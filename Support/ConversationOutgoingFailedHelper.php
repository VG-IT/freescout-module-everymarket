<?php

namespace Modules\Everymarket\Support;

use App\SendLog;
use App\Thread;

/**
 * Highlights conversation list rows when any outgoing message (thread) has a send error.
 */
class ConversationOutgoingFailedHelper
{
    /**
     * Conversation IDs with at least one failed outgoing thread, as id => true (set after preload).
     *
     * @var array<int, bool>|null
     */
    private static $failedOutgoingConversationIds = null;

    /**
     * Called from conversations_table.preload_table_data with the current page of conversations.
     *
     * @param  \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection  $conversations
     * @return mixed
     */
    public static function preloadFailedOutgoingConversationIds($conversations)
    {
        self::$failedOutgoingConversationIds = [];

        if (!$conversations || $conversations->isEmpty()) {
            return $conversations;
        }

        $ids = $conversations->pluck('id')->filter()->values()->all();
        if (empty($ids)) {
            return $conversations;
        }

        $failedIds = Thread::query()
            ->whereIn('conversation_id', $ids)
            ->where('type', Thread::TYPE_MESSAGE)
            ->where('state', Thread::STATE_PUBLISHED)
            ->whereIn('send_status', SendLog::$status_errors)
            ->distinct()
            ->pluck('conversation_id')
            ->all();

        foreach ($failedIds as $cid) {
            self::$failedOutgoingConversationIds[(int) $cid] = true;
        }

        return $conversations;
    }

    public static function conversationHasFailedOutgoing($conversationId)
    {
        $conversationId = (int) $conversationId;

        if (self::$failedOutgoingConversationIds !== null) {
            return !empty(self::$failedOutgoingConversationIds[$conversationId]);
        }

        return Thread::query()
            ->where('conversation_id', $conversationId)
            ->where('type', Thread::TYPE_MESSAGE)
            ->where('state', Thread::STATE_PUBLISHED)
            ->whereIn('send_status', SendLog::$status_errors)
            ->exists();
    }
}
