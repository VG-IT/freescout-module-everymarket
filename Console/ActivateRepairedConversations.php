<?php

namespace Modules\Everymarket\Console;

use App\Conversation;
use App\Thread;
use App\User;
use Illuminate\Console\Command;

/**
 * Set closed conversations back to Active for threads repaired by
 * everymarket:repair-thread-bodies (their conversations may have been
 * closed by agents who never saw the customer's actual reply).
 *
 * Repaired thread IDs are taken from the backup files the repair command
 * writes to storage/app/em-thread-repair/.
 */
class ActivateRepairedConversations extends Command
{
    protected $signature = 'everymarket:activate-repaired-conversations
        {--conversation= : Conversation ID(s) to activate, comma separated (overrides backup detection)}
        {--user= : User ID to attribute the status change to (defaults to the first admin)}
        {--dry-run : Show what would change without saving}';

    protected $description = 'Mark conversations repaired by everymarket:repair-thread-bodies as Active if they are closed';

    public function handle()
    {
        $user = $this->option('user')
            ? User::find((int) $this->option('user'))
            : User::nonDeleted()->where('role', User::ROLE_ADMIN)->orderBy('id')->first();
        if (!$user) {
            $this->error('User not found. Pass --user=<id>.');
            return 1;
        }

        if ($this->option('conversation')) {
            $conversation_ids = array_filter(array_map('intval', explode(',', $this->option('conversation'))));
        } else {
            $conversation_ids = $this->conversationIdsFromBackups();
            $this->line(count($conversation_ids).' repaired conversation(s) found from backups in storage/app/em-thread-repair/.');
        }

        if (!count($conversation_ids)) {
            $this->error('Nothing to process. Run everymarket:repair-thread-bodies first or pass --conversation=<ids>.');
            return 1;
        }

        $activated = 0;
        $skipped = 0;

        foreach ($conversation_ids as $conversation_id) {
            $conversation = Conversation::find($conversation_id);
            if (!$conversation) {
                $this->error('Conversation #'.$conversation_id.': not found - skipping.');
                $skipped++;
                continue;
            }

            if ($conversation->status != Conversation::STATUS_CLOSED) {
                $this->line('Conversation #'.$conversation->id.': status is "'.$conversation->getStatusName().'" (not closed) - skipping.');
                $skipped++;
                continue;
            }

            $this->info('Conversation #'.$conversation->id.' ("'.mb_substr($conversation->subject ?? '', 0, 60).'"): closed -> active');

            if ($this->option('dry-run')) {
                $activated++;
                continue;
            }

            // Sets the status, moves the conversation to the proper folder,
            // adds a "Status changed" line item to the history and fires events.
            $conversation->changeStatus(Conversation::STATUS_ACTIVE, $user);

            if ($conversation->mailbox) {
                $conversation->mailbox->updateFoldersCounters();
            }

            $activated++;
        }

        $this->info('Activated: '.$activated.', skipped: '.$skipped
            .($this->option('dry-run') ? ' (dry run - nothing saved)' : ''));

        return 0;
    }

    /**
     * Extract repaired thread IDs from backup file names
     * (thread_{id}_{timestamp}.html) and map them to conversations.
     */
    protected function conversationIdsFromBackups(): array
    {
        $backup_dir = storage_path('app/em-thread-repair');
        if (!is_dir($backup_dir)) {
            return [];
        }

        $thread_ids = [];
        foreach (scandir($backup_dir) as $file) {
            if (preg_match('/^thread_(\d+)_\d+\.html$/', $file, $m)) {
                $thread_ids[] = (int) $m[1];
            }
        }
        if (!count($thread_ids)) {
            return [];
        }

        return Thread::whereIn('id', array_unique($thread_ids))
            ->pluck('conversation_id')
            ->unique()
            ->values()
            ->all();
    }
}
