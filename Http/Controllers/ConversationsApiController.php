<?php

namespace Modules\Everymarket\Http\Controllers;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Events\UserAddedNote;
use App\Events\UserReplied;
use App\Mailbox;
use App\Thread;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConversationsApiController extends Controller
{
    use ApiTokenAuth;

    const DEFAULT_PAGE_SIZE = 50;
    const MAX_PAGE_SIZE = 100;
    const MAX_REPLY_LENGTH = 10000;

    // Formatting kept in an HTML seller reply (body_format=html); everything
    // else is removed.
    const REPLY_ALLOWED_HTML = 'p,br,strong,b,em,i,u,s,a[href],ul,ol,li,blockquote';

    // Seller reply attachments. Match the limits in EM Ops'
    // FreescoutConversationsController. Base64 adds a third, so the request
    // can be ~20 MB: PHP post_max_size and nginx client_max_body_size must
    // allow that.
    const MAX_REPLY_ATTACHMENTS = 5;
    const MAX_REPLY_ATTACHMENT_BYTES = 10 * 1024 * 1024;
    const MAX_REPLY_ATTACHMENTS_TOTAL_BYTES = 15 * 1024 * 1024;
    const REPLY_ATTACHMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx'];

    /**
     * GET /everymarket/api/conversations
     *
     * Query: status=active|pending|closed|spam (optional, repeatable as status[]=)
     *        mailbox_id=... (optional)
     *        folder_id=... (optional)
     *        modified_since=... (optional, ISO 8601; alias: modifiedSince) —
     *            only conversations updated at/after this time, ordered oldest
     *            modified first for incremental polling
     *        page=1 (optional, default 1)
     *        page_size=50 (optional, default 50, max 100)
     *
     * Auth: header X-Everymarket-Api-Token, Authorization: Bearer <token>, or ?api_token=
     */
    public function index(Request $request)
    {
        if (!$this->isAuthorized($request)) {
            return $this->unauthorizedResponse();
        }

        $statuses = $this->parseStatuses($request);
        if ($statuses === false) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('Invalid status. Allowed: :statuses', ['statuses' => implode(', ', Conversation::$statuses)]),
            ], 400);
        }

        $modifiedSince = null;
        $modifiedSinceRaw = $request->query('modified_since', $request->query('modifiedSince'));
        if ($modifiedSinceRaw) {
            try {
                // updated_at is stored in the app timezone, and Laravel binds a
                // date as its wall-clock time without converting it, so convert
                // first. Otherwise a UTC cursor skips changes made in the hours
                // after it (5-6 h for America/Chicago).
                $modifiedSince = Carbon::parse($modifiedSinceRaw)->setTimezone(config('app.timezone'));
            } catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'msg'    => __('Invalid modified_since, use ISO 8601 format, e.g. 2026-07-01T00:00:00Z'),
                ], 400);
            }
        }

        $page = max(1, (int) $request->query('page', 1));
        $pageSize = (int) $request->query('page_size', self::DEFAULT_PAGE_SIZE);
        if ($pageSize < 1) {
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }
        $pageSize = min($pageSize, self::MAX_PAGE_SIZE);

        $query = Conversation::query()
            ->where('state', Conversation::STATE_PUBLISHED);

        if ($modifiedSince) {
            // Oldest-modified-first with a stable (updated_at, id) order, so a
            // client polling page by page won't skip conversations that get
            // modified again while it is paging through the result set.
            $query->where('updated_at', '>=', $modifiedSince)
                ->orderBy('updated_at')
                ->orderBy('id');
        } else {
            $query->orderByDesc('created_at')
                ->orderByDesc('id');
        }

        if (!empty($statuses)) {
            $query->whereIn('status', $statuses);
        }
        if ($request->filled('mailbox_id')) {
            $query->where('mailbox_id', (int) $request->query('mailbox_id'));
        }
        if ($request->filled('folder_id')) {
            $query->where('folder_id', (int) $request->query('folder_id'));
        }

        $paginator = $query->with(['customer', 'user'])
            ->paginate($pageSize, ['*'], 'page', $page);

        $conversations = collect($paginator->items())->map(function (Conversation $conversation) {
            return $this->transform($conversation);
        })->values();

        return response()->json([
            '_embedded' => [
                'conversations' => $conversations,
            ],
            'page' => [
                'size'          => $pageSize,
                'totalElements' => $paginator->total(),
                'totalPages'    => $paginator->lastPage(),
                'number'        => $paginator->currentPage(),
            ],
        ]);
    }

    /**
     * GET /everymarket/api/conversations/{id}
     *
     * Query: threads=0 to omit the embedded thread list (included by default)
     *
     * Auth: header X-Everymarket-Api-Token, Authorization: Bearer <token>, or ?api_token=
     */
    public function show(Request $request, $id)
    {
        if (!$this->isAuthorized($request)) {
            return $this->unauthorizedResponse();
        }

        $conversation = Conversation::where('state', Conversation::STATE_PUBLISHED)
            ->with(['customer', 'user'])
            ->find((int) $id);

        if (!$conversation) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('Conversation not found'),
            ], 404);
        }

        $data = $this->transform($conversation);
        $data['customFields'] = $this->getCustomFields($conversation);

        $include_threads = $request->query('threads', '1') !== '0';
        if ($include_threads) {
            $data['_embedded'] = [
                'threads' => $this->getThreads($conversation),
            ];
        }

        return response()->json($data);
    }

    /**
     * POST /everymarket/api/conversations
     *
     * Starts a conversation from an order and emails the customer. Same write
     * token, body, seller note and attachments as reply().
     *
     * Body (JSON or form): email, subject, body, body_format, seller_name,
     *                      sent_by, order_number,
     *                      attachments[{file_name, mime_type, content}]
     *
     * 201 {id, number, status}
     *
     * Auth: EM_REPLY_API_TOKEN in header X-Everymarket-Api-Token or
     *       Authorization: Bearer <token> (the query string is not accepted).
     */
    public function store(Request $request)
    {
        if (!$this->isAuthorizedForWrite($request)) {
            return $this->unauthorizedResponse();
        }

        $user = $this->sellerReplyUser();
        if (!$user) {
            return $this->sellerRepliesNotConfigured();
        }

        $message = $this->validatedSellerMessage($request);
        if ($message instanceof \Illuminate\Http\JsonResponse) {
            return $message;
        }

        $email = \App\Email::sanitizeEmail($request->input('email'));
        if (!$email) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('A valid customer email is required'),
            ], 422);
        }

        $subject = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $request->input('subject', ''))));
        if ($subject === '') {
            return response()->json([
                'status' => 'error',
                'msg'    => __('Add a subject before sending.'),
            ], 422);
        }
        if (mb_strlen($subject) > 255) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('The subject is too long'),
            ], 422);
        }

        $mailbox = $this->sellerMailbox();
        if (!$mailbox) {
            \Log::warning('[Everymarket] Cannot start a conversation: no mailbox has Everymarket settings');
            return $this->sellerRepliesNotConfigured();
        }

        $customer = Customer::create($email);
        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('A valid customer email is required'),
            ], 422);
        }

        $attachment_ids = $this->storeSellerAttachments($message['attachments'], $user, 'a new conversation');
        if ($attachment_ids instanceof \Illuminate\Http\JsonResponse) {
            return $attachment_ids;
        }

        $now = date('Y-m-d H:i:s');
        $conversation = new Conversation();
        $conversation->type = Conversation::TYPE_EMAIL;
        $conversation->subject = $subject;
        $conversation->setPreview($message['html']);
        $conversation->mailbox_id = $mailbox->id;
        $conversation->created_by_user_id = $user->id;
        $conversation->source_via = Conversation::PERSON_USER;
        $conversation->source_type = Conversation::SOURCE_TYPE_WEB;
        $conversation->customer_id = $customer->id;
        $conversation->customer_email = $email;
        $conversation->status = Conversation::STATUS_ACTIVE;
        $conversation->state = Conversation::STATE_PUBLISHED;
        $conversation->last_reply_at = $now;
        $conversation->last_reply_from = Conversation::PERSON_USER;
        $conversation->user_updated_at = $now;
        $conversation->setRelation('mailbox', $mailbox);
        $conversation->setRelation('customer', $customer);
        $conversation->updateFolder($mailbox);
        $conversation->save();

        $this->assignOrderNumber($conversation, $mailbox, $request->input('order_number'));

        $this->createUserThread($conversation, $user, $message['html'], Thread::TYPE_MESSAGE, $attachment_ids);
        $this->createUserThread($conversation, $user, $this->sellerAuditNote($message['seller_name'], $message['sent_by']), Thread::TYPE_NOTE);

        $conversation->refresh();

        return response()->json([
            'id'     => (int) $conversation->id,
            'number' => (int) $conversation->number,
            'status' => Conversation::$statuses[$conversation->status] ?? 'active',
        ], 201);
    }

    /**
     * POST /everymarket/api/conversations/{id}/reply
     *
     * Sends a reply to the customer on behalf of a seller in EM Ops.
     *
     * Body (JSON or form): body        - required, max 10000 chars
     *                      body_format - optional, "text" (default) or "html";
     *                                    HTML is cleaned to REPLY_ALLOWED_HTML
     *                      seller_name - required, store name for the audit note
     *                      sent_by     - optional, EM Ops user email for the audit note
     *                      attachments - optional, up to 5 of
     *                                    {file_name, mime_type, content (base64)}
     *
     * The reply is sent as the FreeScout user EM_SELLER_REPLY_USER_ID through the
     * same path as a reply from the web UI, so the customer gets the normal email
     * (after the usual undo delay). An internal note records which seller sent it.
     * A closed conversation cannot be replied to. Re-open it first.
     * Any other status is left as it is.
     *
     * Auth: EM_REPLY_API_TOKEN in header X-Everymarket-Api-Token or
     *       Authorization: Bearer <token> (the query string is not accepted).
     */
    public function reply(Request $request, $id)
    {
        if (!$this->isAuthorizedForWrite($request)) {
            return $this->unauthorizedResponse();
        }

        $user = $this->sellerReplyUser();
        if (!$user) {
            return $this->sellerRepliesNotConfigured();
        }

        $message = $this->validatedSellerMessage($request);
        if ($message instanceof \Illuminate\Http\JsonResponse) {
            return $message;
        }

        $conversation = Conversation::where('state', Conversation::STATE_PUBLISHED)
            ->with(['customer', 'mailbox'])
            ->find((int) $id);

        if (!$conversation) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('Conversation not found'),
            ], 404);
        }
        if ($conversation->status == Conversation::STATUS_CLOSED) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('This conversation is closed'),
            ], 422);
        }
        if ($conversation->status == Conversation::STATUS_SPAM || !$conversation->customer_email) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('This conversation can not be replied to'),
            ], 422);
        }

        // Store the files before touching the conversation, so a storage
        // failure leaves nothing half-sent.
        $attachment_ids = $this->storeSellerAttachments($message['attachments'], $user, 'conversation '.$conversation->id);
        if ($attachment_ids instanceof \Illuminate\Http\JsonResponse) {
            return $attachment_ids;
        }

        // Same conversation fields the web UI's send_reply sets for a reply.
        $now = date('Y-m-d H:i:s');
        $conversation->last_reply_at = $now;
        $conversation->last_reply_from = Conversation::PERSON_USER;
        $conversation->user_updated_at = $now;
        $conversation->updateFolder();
        $conversation->save();

        $thread = $this->createUserThread($conversation, $user, $message['html'], Thread::TYPE_MESSAGE, $attachment_ids);
        // The customer message is already stored. A failure while recording
        // the internal note must not make EM Ops tell the seller it was not sent.
        try {
            $this->createUserThread($conversation, $user, $this->sellerAuditNote($message['seller_name'], $message['sent_by']), Thread::TYPE_NOTE);
        } catch (\Throwable $e) {
            \Log::error('[Everymarket] Seller reply '.$thread->id.' was saved on conversation '.$conversation->id.' but the audit note failed: '.$e->getMessage());
        }

        try {
            $payload = $this->threadToArray($thread->fresh(['created_by_user', 'attachments']));
        } catch (\Throwable $e) {
            \Log::error('[Everymarket] Seller reply '.$thread->id.' was saved on conversation '.$conversation->id.' but the response could not be built: '.$e->getMessage());
            $payload = ['id' => (int) $thread->id, 'type' => 'message'];
        }

        return response()->json($payload, 201);
    }

    /**
     * POST /everymarket/api/conversations/{id}/close
     *
     * Closes a published conversation. Closing one that is already closed is
     * success, so EM Ops can retry without creating another status line.
     *
     * Auth: EM_REPLY_API_TOKEN, same as reply().
     */
    public function close(Request $request, $id)
    {
        if (!$this->isAuthorizedForWrite($request)) {
            return $this->unauthorizedResponse();
        }

        $user = $this->sellerReplyUser();
        if (!$user) {
            return $this->sellerRepliesNotConfigured();
        }

        $conversation = $this->publishedConversation($id);
        if (!$conversation) {
            return $this->conversationNotFound();
        }

        $this->changeStatusOrKeep($conversation, (int) Conversation::STATUS_CLOSED, $user);

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * POST /everymarket/api/conversations/{id}/reopen
     *
     * Opens a closed conversation again so a reply can be sent. Re-opening
     * one that is already open is success.
     *
     * Auth: EM_REPLY_API_TOKEN, same as reply().
     */
    public function reopen(Request $request, $id)
    {
        if (!$this->isAuthorizedForWrite($request)) {
            return $this->unauthorizedResponse();
        }

        $user = $this->sellerReplyUser();
        if (!$user) {
            return $this->sellerRepliesNotConfigured();
        }

        $conversation = $this->publishedConversation($id);
        if (!$conversation) {
            return $this->conversationNotFound();
        }

        if ((int) $conversation->status === (int) Conversation::STATUS_CLOSED) {
            $this->changeStatusOrKeep($conversation, (int) Conversation::STATUS_ACTIVE, $user);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * POST /everymarket/api/conversations/{id}/forward
     *
     * Body: to (required email), close (optional bool), body (optional).
     * Forwards the conversation to `to`. When close is true, the original
     * conversation is closed in this same request.
     *
     * Auth: EM_REPLY_API_TOKEN, same as reply().
     */
    public function forward(Request $request, $id)
    {
        if (!$this->isAuthorizedForWrite($request)) {
            return $this->unauthorizedResponse();
        }

        $user = $this->sellerReplyUser();
        if (!$user) {
            return $this->sellerRepliesNotConfigured();
        }

        $to = \App\Email::sanitizeEmail($request->input('to'));
        if (!$to) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('A valid email address is required'),
            ], 422);
        }

        $conversation = $this->publishedConversation($id);
        if (!$conversation) {
            return $this->conversationNotFound();
        }

        $body = trim((string) $request->input('body', ''));
        if ($body === '') {
            $body = __('Forwarded from EM Ops');
        }

        $conversation->forward($user, htmlspecialchars($body, ENT_QUOTES, 'UTF-8'), $to);

        if (filter_var($request->input('close'), FILTER_VALIDATE_BOOLEAN)) {
            $conversation->refresh();
            $this->changeStatusOrKeep($conversation, (int) Conversation::STATUS_CLOSED, $user);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    protected function publishedConversation($id)
    {
        return Conversation::where('state', Conversation::STATE_PUBLISHED)
            ->with(['customer', 'mailbox'])
            ->find((int) $id);
    }

    protected function conversationNotFound()
    {
        return response()->json([
            'status' => 'error',
            'msg'    => __('Conversation not found'),
        ], 404);
    }

    /**
     * Validates and decodes seller reply attachments. Returns the decoded
     * list, or an error message for the seller.
     */
    protected function decodeReplyAttachments($input)
    {
        if (empty($input)) {
            return [];
        }
        if (!is_array($input)) {
            return __('attachments must be a list');
        }
        if (count($input) > self::MAX_REPLY_ATTACHMENTS) {
            return __('You can attach up to :max files', ['max' => self::MAX_REPLY_ATTACHMENTS]);
        }

        $decoded = [];
        $total = 0;
        foreach ($input as $item) {
            $file_name = trim((string) ($item['file_name'] ?? ''));
            $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            if ($file_name === '' || !in_array($extension, self::REPLY_ATTACHMENT_EXTENSIONS, true)) {
                return __(':file is not an allowed file type', ['file' => $file_name ?: __('A file')]);
            }

            $content = base64_decode((string) ($item['content'] ?? ''), true);
            if ($content === false || $content === '') {
                return __(':file is empty or could not be read', ['file' => $file_name]);
            }

            $size = strlen($content);
            if ($size > self::MAX_REPLY_ATTACHMENT_BYTES) {
                return __(':file is larger than 10 MB', ['file' => $file_name]);
            }
            $total += $size;
            if ($total > self::MAX_REPLY_ATTACHMENTS_TOTAL_BYTES) {
                return __('Attachments are larger than 15 MB in total');
            }

            $decoded[] = [
                'file_name' => $file_name,
                'mime_type' => trim((string) ($item['mime_type'] ?? '')) ?: 'application/octet-stream',
                'content'   => $content,
            ];
        }

        return $decoded;
    }

    /**
     * @return array{html: string, seller_name: string, sent_by: string, attachments: array}|\Illuminate\Http\JsonResponse
     */
    protected function validatedSellerMessage(Request $request)
    {
        $body = trim((string) $request->input('body', ''));
        $seller_name = trim((string) $request->input('seller_name', ''));
        $sent_by = trim((string) $request->input('sent_by', ''));
        $body_format = (string) $request->input('body_format', 'text');

        if (!in_array($body_format, ['text', 'html'], true)) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('body_format must be text or html'),
            ], 422);
        }

        if ($body === '' || $seller_name === '') {
            return response()->json([
                'status' => 'error',
                'msg'    => __('body and seller_name are required'),
            ], 422);
        }
        if (mb_strlen($body) > self::MAX_REPLY_LENGTH) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('body is too long (max :max characters)', ['max' => self::MAX_REPLY_LENGTH]),
            ], 422);
        }

        if ($body_format === 'html') {
            $html = \Purifier::clean($body, ['HTML.Allowed' => self::REPLY_ALLOWED_HTML]);
            if (trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) === '') {
                return response()->json([
                    'status' => 'error',
                    'msg'    => __('body and seller_name are required'),
                ], 422);
            }
        } else {
            $html = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        }

        $attachments = $this->decodeReplyAttachments($request->input('attachments', []));
        if (is_string($attachments)) {
            return response()->json([
                'status' => 'error',
                'msg'    => $attachments,
            ], 422);
        }

        return [
            'html'        => $html,
            'seller_name' => $seller_name,
            'sent_by'     => $sent_by,
            'attachments' => $attachments,
        ];
    }

    /**
     * @param array<int, array{file_name: string, mime_type: string, content: string}> $attachments
     * @return array<int, int>|\Illuminate\Http\JsonResponse
     */
    protected function storeSellerAttachments(array $attachments, User $user, string $logContext)
    {
        $attachment_ids = [];
        foreach ($attachments as $attachment) {
            $saved = Attachment::create($attachment['file_name'], $attachment['mime_type'], null, $attachment['content'], '', false, null, $user->id);
            if (!$saved) {
                Attachment::deleteByIds($attachment_ids);
                \Log::error('[Everymarket] Could not store seller reply attachment '.$attachment['file_name'].' for '.$logContext);
                return response()->json([
                    'status' => 'error',
                    'msg'    => __('Could not store the attachments'),
                ], 500);
            }
            $attachment_ids[] = $saved->id;
        }

        return $attachment_ids;
    }

    protected function sellerAuditNote(string $seller_name, string $sent_by): string
    {
        $note = __('Reply sent by seller :seller from EM Ops', ['seller' => $seller_name]);
        if ($sent_by !== '') {
            $note .= ' ('.$sent_by.')';
        }

        return htmlspecialchars($note, ENT_QUOTES, 'UTF-8');
    }

    protected function sellerReplyUser()
    {
        return User::where('id', (int) config('everymarket.seller_reply_user_id'))
            ->where('status', User::STATUS_ACTIVE)
            ->first();
    }

    protected function sellerRepliesNotConfigured()
    {
        return response()->json([
            'status' => 'error',
            'msg'    => __('Seller replies are not configured'),
        ], 503);
    }

    protected function sellerMailbox()
    {
        $mailboxes = Mailbox::orderBy('id')->get();
        foreach ($mailboxes as $mailbox) {
            if (\Everymarket::isMailboxApiEnabled($mailbox)) {
                return $mailbox;
            }
        }

        return null;
    }

    protected function assignOrderNumber(Conversation $conversation, Mailbox $mailbox, $orderNumber): void
    {
        $orderNumber = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $orderNumber)));
        if ($orderNumber === '') {
            return;
        }
        $orderNumber = mb_substr($orderNumber, 0, 64);

        if (!Schema::hasTable('custom_fields')) {
            return;
        }

        $customField = DB::table('custom_fields')
            ->where('mailbox_id', $mailbox->id)
            ->where('name', 'Order Number')
            ->first();
        if (!$customField) {
            \Log::warning('[Everymarket] Order Number custom field is missing', ['mailbox_id' => $mailbox->id]);
            return;
        }

        $ccfTable = Schema::hasTable('conversation_custom_field')
            ? 'conversation_custom_field'
            : (Schema::hasTable('conversation_custom_fields') ? 'conversation_custom_fields' : null);
        if (!$ccfTable) {
            return;
        }

        try {
            DB::table($ccfTable)->insert([
                'conversation_id' => $conversation->id,
                'custom_field_id' => $customField->id,
                'value'           => $orderNumber,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('[Everymarket] Could not store Order Number: '.$e->getMessage());
            return;
        }

        \Eventy::filter('conversation.set_custom_field', false, $conversation, 'Order Number', $orderNumber);
        $conversation->setMeta('custom_fields.order_number', $orderNumber, true);
    }

    /**
     * Mirrors Conversation::createUserThread(), which doesn't return the
     * thread it creates. Firing UserReplied queues the email to the customer.
     */
    protected function createUserThread(Conversation $conversation, User $user, string $body, int $type, array $attachment_ids = []): Thread
    {
        $thread = Thread::create($conversation, $type, $body, [], false);
        if ($type == Thread::TYPE_MESSAGE && !(int) $conversation->threads_count) {
            $thread->first = true;
        }
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->user_id = $conversation->user_id;
        $thread->status = $conversation->status;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_user_id = $user->id;
        $thread->body = $body;
        $thread->setTo($conversation->customer_email);
        $this->saveThreadOrKeep($thread, $conversation);

        // Link the files before UserReplied queues the email, as the web UI
        // does, so SendReplyToCustomer attaches them.
        if ($attachment_ids) {
            Attachment::whereIn('id', $attachment_ids)->update(['thread_id' => $thread->id]);
            $thread->has_attachments = true;
            $this->saveThreadOrKeep($thread, $conversation);
            $conversation->has_attachments = true;
            try {
                $conversation->save();
            } catch (\Throwable $e) {
                \Log::error('[Everymarket] Thread '.$thread->id.' was saved on conversation '.$conversation->id.' but the attachment flag could not be saved: '.$e->getMessage());
            }
        }

        // Counters, the customer email, and realtime updates happen after the
        // thread row exists. If one of them throws, the reply is still sent
        // from EM Ops' point of view and must not be reported as a failure.
        if ($conversation->mailbox) {
            try {
                $conversation->mailbox->updateFoldersCounters();
            } catch (\Throwable $e) {
                \Log::error('[Everymarket] Could not refresh folder counters after thread '.$thread->id.' on conversation '.$conversation->id.': '.$e->getMessage());
            }
        }

        try {
            if ($type == Thread::TYPE_NOTE) {
                event(new UserAddedNote($conversation, $thread));
                \Eventy::action('conversation.note_added', $conversation, $thread);
            } else {
                event(new UserReplied($conversation, $thread));
                \Eventy::action('conversation.user_replied', $conversation, $thread);
            }
        } catch (\Throwable $e) {
            \Log::error('[Everymarket] Thread '.$thread->id.' was saved on conversation '.$conversation->id.' but a follow-up action failed: '.$e->getMessage());
        }

        return $thread;
    }

    /**
     * changeStatus() saves the new status, then writes a line item and
     * updates folder counters. Those follow-ups throw often enough that the
     * first request returns 500 after the status is already stored, and the
     * retry succeeds because the status matches. Once the status is stored,
     * the request succeeded.
     */
    protected function changeStatusOrKeep(Conversation $conversation, int $status, User $user): void
    {
        if ((int) $conversation->status === $status) {
            return;
        }

        try {
            $conversation->changeStatus($status, $user);
        } catch (\Throwable $e) {
            $conversation->refresh();
            if ((int) $conversation->status === $status) {
                \Log::error('[Everymarket] Conversation '.$conversation->id.' status was saved but a follow-up failed: '.$e->getMessage());
                return;
            }
            throw $e;
        }
    }

    /**
     * Thread::save() runs observers after the row is inserted. If one of them
     * throws, the reply is already stored and the customer email still has to
     * be queued. A failure before the row exists is a real failure.
     */
    protected function saveThreadOrKeep(Thread $thread, Conversation $conversation): void
    {
        try {
            $thread->save();
        } catch (\Throwable $e) {
            if (!$thread->exists) {
                throw $e;
            }
            \Log::error('[Everymarket] Thread '.$thread->id.' was saved on conversation '.$conversation->id.' but a follow-up during save failed: '.$e->getMessage());
        }
    }

    /**
     * @return array|false Array of status codes, empty array for "no filter", false if invalid.
     */
    protected function parseStatuses(Request $request)
    {
        $raw = $request->query('status');
        if ($raw === null || $raw === '') {
            return [];
        }

        $names = is_array($raw) ? $raw : explode(',', (string) $raw);
        $codes = [];
        $name_to_code = array_flip(Conversation::$statuses);

        foreach ($names as $name) {
            $name = mb_strtolower(trim($name));
            if ($name === '') {
                continue;
            }
            if (!isset($name_to_code[$name])) {
                return false;
            }
            $codes[] = $name_to_code[$name];
        }

        return array_unique($codes);
    }

    protected function transform(Conversation $conversation): array
    {
        return [
            'id'            => (int) $conversation->id,
            'number'        => (int) $conversation->number,
            'threadsCount'  => (int) $conversation->threads_count,
            'type'          => Conversation::$types[$conversation->type] ?? (string) $conversation->type,
            'folderId'      => (int) $conversation->folder_id,
            'status'        => Conversation::$statuses[$conversation->status] ?? (string) $conversation->status,
            'state'         => Conversation::$states[$conversation->state] ?? (string) $conversation->state,
            'subject'       => (string) ($conversation->subject ?? ''),
            'preview'       => (string) ($conversation->preview ?? ''),
            'mailboxId'     => (int) $conversation->mailbox_id,
            'customerId'    => $conversation->customer_id ? (int) $conversation->customer_id : null,
            'createdAt'     => $conversation->created_at ? $conversation->created_at->toIso8601String() : null,
            'closedAt'      => $conversation->closed_at ? $conversation->closed_at->toIso8601String() : null,
            'updatedAt'     => $conversation->updated_at ? $conversation->updated_at->toIso8601String() : null,
            'userUpdatedAt' => $conversation->user_updated_at ? $conversation->user_updated_at->toIso8601String() : null,
            'customer'      => $this->customerToArray($conversation->customer, $conversation->customer_email),
            'assignee'      => $this->userToArray($conversation->user),
        ];
    }

    /**
     * Full thread list for the conversation detail endpoint (body not truncated).
     */
    protected function getThreads(Conversation $conversation): array
    {
        $threads = $conversation->threads()
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE, Thread::TYPE_NOTE])
            ->where('state', Thread::STATE_PUBLISHED)
            ->with(['customer', 'created_by_customer', 'created_by_user', 'attachments'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $threads->map(function (Thread $thread) {
            return $this->threadToArray($thread);
        })->values()->all();
    }

    protected function threadToArray(Thread $thread): array
    {
        $row = [
            'id'        => (int) $thread->id,
            'type'      => Thread::$types[$thread->type] ?? (string) $thread->type,
            'state'     => Conversation::$states[$thread->state] ?? (string) $thread->state,
            'createdAt' => $thread->created_at ? $thread->created_at->toIso8601String() : null,
            'body'      => (string) ($thread->body ?? ''),
        ];

        if ($thread->isCustomerMessage()) {
            $customer = $thread->created_by_customer ?: $thread->customer;
            $row['customer'] = $this->customerToArray($customer, $thread->from);
        } else {
            $row['createdBy'] = $this->userToArray($thread->created_by_user);
        }

        $row['attachments'] = $this->attachmentsToArray($thread);

        return $row;
    }

    /**
     * Files attached to a thread, including images embedded in the body.
     * `url` is the tokenised download link (APP_URL/storage/attachment/...),
     * so it opens without a FreeScout login.
     */
    protected function attachmentsToArray(Thread $thread): array
    {
        if (!$thread->has_attachments) {
            return [];
        }

        return $thread->attachments->map(function ($attachment) {
            return [
                'id'       => (int) $attachment->id,
                'fileName' => (string) $attachment->file_name,
                'mimeType' => (string) ($attachment->mime_type ?? ''),
                'size'     => (int) $attachment->size,
                'embedded' => (bool) $attachment->embedded,
                'url'      => $attachment->url(),
            ];
        })->values()->all();
    }

    /**
     * Custom field values set on the conversation (requires the CustomFields module).
     */
    protected function getCustomFields(Conversation $conversation): array
    {
        $ccfTable = Schema::hasTable('conversation_custom_field')
            ? 'conversation_custom_field'
            : (Schema::hasTable('conversation_custom_fields') ? 'conversation_custom_fields' : null);

        if (!$ccfTable || !Schema::hasTable('custom_fields')) {
            return [];
        }

        $rows = DB::table($ccfTable)
            ->join('custom_fields', 'custom_fields.id', '=', $ccfTable.'.custom_field_id')
            ->where($ccfTable.'.conversation_id', $conversation->id)
            ->where('custom_fields.mailbox_id', $conversation->mailbox_id)
            ->get(['custom_fields.name', $ccfTable.'.value']);

        $fields = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row->value ?? ''));
            if ($value === '') {
                continue;
            }
            $fields[] = ['name' => $row->name, 'value' => $value];
        }

        return $fields;
    }

    protected function customerToArray(?Customer $customer, ?string $fallback_email = null): ?array
    {
        if (!$customer) {
            return null;
        }

        return [
            'id'        => (int) $customer->id,
            'firstName' => (string) ($customer->first_name ?? ''),
            'lastName'  => (string) ($customer->last_name ?? ''),
            'email'     => (string) ($fallback_email ?: $customer->getMainEmail()),
        ];
    }

    protected function userToArray(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id'        => (int) $user->id,
            'firstName' => (string) ($user->first_name ?? ''),
            'lastName'  => (string) ($user->last_name ?? ''),
        ];
    }
}
