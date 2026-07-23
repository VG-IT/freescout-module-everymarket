<?php

namespace Modules\Everymarket\Http\Controllers;

use App\Conversation;
use App\Customer;
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
                $modifiedSince = Carbon::parse($modifiedSinceRaw);
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
            ->with(['customer', 'created_by_customer', 'created_by_user'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $threads->map(function (Thread $thread) {
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

            return $row;
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
