<?php

namespace Modules\Everymarket\Services;

use App\Conversation;
use App\Email;
use Illuminate\Support\Facades\DB;

/**
 * Fast path for the conversation search: when the search query is an email
 * address or an EM order number, find conversations via indexed exact-match
 * lookups instead of scanning all threads with LIKE '%...%'.
 */
class ConversationSearchOptimizer
{
    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|string
     *         Empty string to fall back to the standard search.
     */
    public static function perform($q, array $filters, $user)
    {
        $q = trim((string) $q);
        if ($q === '' || !$user) {
            return '';
        }

        $email = '';
        $conversation_ids = null;

        if (strpos($q, '@') !== false && !preg_match('/\s/', $q)) {
            $email = Email::sanitizeEmail($q);
            if (!$email) {
                return '';
            }
        } elseif (preg_match('/^#?EM[A-Za-z0-9\-]*\d[A-Za-z0-9\-]*$/i', $q)) {
            $conversation_ids = (new ConversationSummaryService())->matchOrderNumberConversationIds($q);
            if ($conversation_ids->isEmpty()) {
                return '';
            }
        } else {
            return '';
        }

        // Same as in ConversationsController::searchQuery().
        if ($user->canSeeOnlyAssignedConversations()) {
            $filters['assigned'] = $user->id;
        }

        $query = Conversation::select('conversations.*');

        if ($email !== '') {
            $customer_ids = DB::table('emails')
                ->where('email', $email)
                ->pluck('customer_id')
                ->unique()
                ->filter()
                ->values();

            $query->where(function ($query) use ($email, $customer_ids) {
                $query->where('conversations.customer_email', $email);
                if ($customer_ids->isNotEmpty()) {
                    $query->orWhereIn('conversations.customer_id', $customer_ids->all());
                }
            });
        } else {
            $query->whereIn('conversations.id', $conversation_ids->all());
        }

        // Passing an empty search string skips the LIKE conditions, while
        // mailbox access, filters, joins and sorting are applied as in the
        // standard search.
        $query = Conversation::search('', $filters, $user, $query);

        $result = $query->paginate(Conversation::DEFAULT_LIST_SIZE);

        // No exact matches — fall back to the standard search, which can
        // also find the text in thread bodies, CC, etc.
        if (!$result->total()) {
            return '';
        }

        return $result;
    }
}
