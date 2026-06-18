<?php

namespace Modules\Everymarket\Services;

use App\Conversation;
use App\Email;
use App\Thread;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConversationSummaryService
{
    const ORDER_NUMBER_FIELD = 'Order Number';

    /**
     * Find conversations by Order Number custom field value.
     */
    public function findByOrderNumber(string $orderNumber, bool $all = false): Collection
    {
        $convIds = $this->conversationIdsForOrderNumber($orderNumber);
        if ($convIds->isEmpty()) {
            return collect();
        }

        return $this->loadConversations($convIds, $all);
    }

    /**
     * Find conversations by customer email (conversation field or linked customer emails).
     */
    public function findByCustomerEmail(string $email, bool $all = false): Collection
    {
        $email = Email::sanitizeEmail(trim($email));
        if ($email === '') {
            return collect();
        }

        $customerIds = DB::table('emails')
            ->where('email', $email)
            ->pluck('customer_id')
            ->unique()
            ->filter()
            ->values();

        $query = Conversation::query()
            ->where('state', Conversation::STATE_PUBLISHED)
            ->where(function ($q) use ($email, $customerIds) {
                $q->where('customer_email', $email);
                if ($customerIds->isNotEmpty()) {
                    $q->orWhereIn('customer_id', $customerIds);
                }
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if (!$all) {
            $conversation = $query->first();
            return $conversation ? collect([$conversation]) : collect();
        }

        return $query->get();
    }

    /**
     * Build API summary payload for one conversation.
     */
    public function summarize(Conversation $conversation): array
    {
        $conversation->loadMissing(['user', 'customer']);

        $replyMetrics = $this->getReplyMetrics($conversation);
        $orderNumber = $this->getOrderNumberForConversation($conversation);

        $assignedTo = '';
        if ($conversation->user) {
            $assignedTo = $conversation->user->getFullName();
        }

        $typeCode = $conversation->type;
        $typeName = Conversation::$types[$typeCode] ?? (string) $typeCode;

        return [
            'conversation_id'       => (int) $conversation->id,
            'customer_email'        => (string) ($conversation->customer_email ?: optional($conversation->customer)->getMainEmail()),
            'subject'               => (string) ($conversation->subject ?? ''),
            'conversation_started'  => $conversation->created_at ? $conversation->created_at->toIso8601String() : null,
            'assigned_to'           => $assignedTo,
            'conversation_type'     => $typeName,
            'threads_count'         => (int) $conversation->threads_count,
            'started_at'            => $replyMetrics['started_at'],
            'first_reply_at'        => $replyMetrics['first_reply_at'],
            'first_reply_seconds'   => $replyMetrics['first_reply_seconds'],
            'latest_reply_at'       => $replyMetrics['latest_reply_at'],
            'order_number'          => $orderNumber,
            'status'                => $conversation->getStatusName(),
        ];
    }

    /**
     * @param Collection|array $conversationIds
     */
    protected function loadConversations($conversationIds, bool $all): Collection
    {
        $ids = collect($conversationIds)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $query = Conversation::query()
            ->whereIn('id', $ids)
            ->where('state', Conversation::STATE_PUBLISHED)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if (!$all) {
            $firstId = $ids->first();
            if (!$firstId) {
                return collect();
            }
            $conversation = Conversation::find($firstId);

            return $conversation ? collect([$conversation]) : collect();
        }

        return $query->get();
    }

    protected function conversationIdsForOrderNumber(string $orderNumber): Collection
    {
        $ccfTable = $this->conversationCustomFieldTable();
        if (!$ccfTable || !Schema::hasTable('custom_fields')) {
            return collect();
        }

        $fieldIds = DB::table('custom_fields')
            ->where('name', self::ORDER_NUMBER_FIELD)
            ->pluck('id');

        if ($fieldIds->isEmpty()) {
            return collect();
        }

        $variants = $this->orderNumberVariants($orderNumber);
        if (empty($variants)) {
            return collect();
        }

        $target = $variants[0];

        $rows = DB::table($ccfTable)
            ->whereIn('custom_field_id', $fieldIds)
            ->get(['conversation_id', 'value']);

        $matched = [];
        foreach ($rows as $row) {
            $stored = $this->normalizeOrderNumber($row->value ?? '');
            if ($stored !== '' && $stored === $target) {
                $matched[] = (int) $row->conversation_id;
            }
        }

        if (empty($matched)) {
            return collect();
        }

        return Conversation::query()
            ->whereIn('id', array_unique($matched))
            ->where('state', Conversation::STATE_PUBLISHED)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->pluck('id');
    }

    protected function getOrderNumberForConversation(Conversation $conversation): string
    {
        $ccfTable = $this->conversationCustomFieldTable();
        if ($ccfTable && Schema::hasTable('custom_fields') && $conversation->mailbox_id) {
            $cf = DB::table('custom_fields')
                ->where('mailbox_id', $conversation->mailbox_id)
                ->where('name', self::ORDER_NUMBER_FIELD)
                ->first();
            if ($cf) {
                $ccf = DB::table($ccfTable)
                    ->where('conversation_id', $conversation->id)
                    ->where('custom_field_id', $cf->id)
                    ->first();
                if ($ccf && trim((string) ($ccf->value ?? '')) !== '') {
                    return trim((string) $ccf->value);
                }
            }
        }

        return trim((string) $conversation->getMeta('custom_fields.order_number', ''));
    }

    protected function getReplyMetrics(Conversation $conversation): array
    {
        $threads = $conversation->threads()
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'type', 'created_at']);

        $startedAt = $conversation->created_at;
        foreach ($threads as $thread) {
            if ($thread->type == Thread::TYPE_CUSTOMER) {
                $startedAt = $thread->created_at;
                break;
            }
        }

        $firstReplyAt = null;
        $firstReplySeconds = null;
        foreach ($threads as $thread) {
            if ($thread->type == Thread::TYPE_MESSAGE && $thread->created_at >= $startedAt) {
                $firstReplyAt = $thread->created_at;
                $firstReplySeconds = $startedAt ? $startedAt->diffInSeconds($thread->created_at) : null;
                break;
            }
        }

        $latestReplyAt = null;
        if ($conversation->last_reply_at) {
            $latestReplyAt = $conversation->last_reply_at;
        } elseif ($threads->isNotEmpty()) {
            $latestReplyAt = $threads->last()->created_at;
        }

        return [
            'started_at'          => $startedAt ? $startedAt->toIso8601String() : null,
            'first_reply_at'      => $firstReplyAt ? $firstReplyAt->toIso8601String() : null,
            'first_reply_seconds' => $firstReplySeconds,
            'latest_reply_at'     => $latestReplyAt ? \Carbon\Carbon::parse($latestReplyAt)->toIso8601String() : null,
        ];
    }

    protected function conversationCustomFieldTable(): ?string
    {
        if (Schema::hasTable('conversation_custom_field')) {
            return 'conversation_custom_field';
        }
        if (Schema::hasTable('conversation_custom_fields')) {
            return 'conversation_custom_fields';
        }

        return null;
    }

    protected function normalizeOrderNumber(?string $value): string
    {
        $value = trim((string) $value);
        $value = ltrim($value, '#');
        return strtoupper($value);
    }

    /**
     * @return string[]
     */
    protected function orderNumberVariants(string $orderNumber): array
    {
        $normalized = $this->normalizeOrderNumber($orderNumber);
        if ($normalized === '') {
            return [];
        }

        return [$normalized];
    }
}