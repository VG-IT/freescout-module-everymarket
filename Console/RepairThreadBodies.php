<?php

namespace Modules\Everymarket\Console;

use App\Mailbox;
use App\Thread;
use Illuminate\Console\Command;

/**
 * Repair customer threads whose body was mangled on import (the customer's
 * new text was lost and only the quoted history was saved - see the
 * fetch_emails.separate_reply.preprocess_body hook in the module provider).
 *
 * Re-fetches the original email from the IMAP server by Message-ID and
 * rebuilds the thread body through the fixed separation logic.
 */
class RepairThreadBodies extends Command
{
    protected $signature = 'everymarket:repair-thread-bodies
        {--mailbox= : Mailbox ID}
        {--thread= : Thread ID(s) to repair, comma separated}
        {--detect : Detect mangled customer threads (body contains the fsReplyAbove marker)}
        {--folders= : Comma separated IMAP folders to search (defaults to mailbox fetch folders)}
        {--dry-run : Show what would change without saving}';

    protected $description = 'Rebuild mangled thread bodies from the original emails on the IMAP server';

    public function handle()
    {
        $mailbox = Mailbox::find((int) $this->option('mailbox'));
        if (!$mailbox) {
            $this->error('Mailbox not found. Pass --mailbox=<id>.');
            return 1;
        }

        $thread_ids = [];
        if ($this->option('thread')) {
            $thread_ids = array_filter(array_map('intval', explode(',', $this->option('thread'))));
        } elseif ($this->option('detect')) {
            $thread_ids = Thread::where('threads.type', Thread::TYPE_CUSTOMER)
                ->where('threads.body', 'like', '%fsReplyAbove%')
                ->join('conversations', 'conversations.id', '=', 'threads.conversation_id')
                ->where('conversations.mailbox_id', $mailbox->id)
                ->pluck('threads.id')
                ->all();
            $this->line(count($thread_ids).' mangled customer thread(s) detected.');
        }

        if (!count($thread_ids)) {
            $this->error('Nothing to repair. Pass --thread=<id> or --detect.');
            return 1;
        }

        $client = \MailHelper::getMailboxClient($mailbox);
        $client->connect();

        if ($this->option('folders')) {
            $folder_names = array_filter(array_map('trim', explode(',', $this->option('folders'))));
        } else {
            $folder_names = $mailbox->getInImapFolders();
        }

        $folders = [];
        foreach ($folder_names as $folder_name) {
            try {
                $folder = \MailHelper::getImapFolder($client, $folder_name);
                if ($folder) {
                    $folders[$folder_name] = $folder;
                }
            } catch (\Exception $e) {
                $this->error('IMAP folder not found: '.$folder_name);
            }
        }
        if (!count($folders)) {
            $this->error('No IMAP folders available.');
            return 1;
        }

        // Reuse the standard fetch command for separateReply(), so the
        // Eventy preprocess fix and mailbox reply separators apply.
        $fetcher = new \App\Console\Commands\FetchEmails();
        $fetcher->mailbox = $mailbox;

        $repaired = 0;
        $skipped = 0;

        foreach ($thread_ids as $thread_id) {
            $thread = Thread::find($thread_id);
            if (!$thread || !$thread->message_id) {
                $this->error('Thread #'.$thread_id.': not found or has no Message-ID - skipping.');
                $skipped++;
                continue;
            }
            if (!$thread->conversation || $thread->conversation->mailbox_id != $mailbox->id) {
                $this->error('Thread #'.$thread_id.': does not belong to mailbox '.$mailbox->id.' - skipping.');
                $skipped++;
                continue;
            }

            // Find the original email on the server.
            $message = null;
            $found_in = '';
            foreach ($folders as $folder_name => $folder) {
                try {
                    $messages = $folder->query()
                        ->whereMessageId($thread->message_id)
                        ->leaveUnread()
                        ->limit(1)
                        ->get();
                    if (count($messages)) {
                        $message = $messages->first();
                        $found_in = $folder_name;
                        break;
                    }
                } catch (\Exception $e) {
                    $this->error('Thread #'.$thread_id.': IMAP search failed in '.$folder_name.': '.$e->getMessage());
                }
            }
            if (!$message) {
                $this->error('Thread #'.$thread_id.': original email not found on the server ('.$thread->message_id.'). Try --folders="[Gmail]/All Mail".');
                $skipped++;
                continue;
            }

            // Rebuild the body the same way FetchEmails does.
            $html_body = $message->getHTMLBody(false);
            $is_html = true;
            if ($html_body) {
                $body = $html_body;
            } else {
                $is_html = false;
                $body = htmlspecialchars($message->getTextBody() ?? '');
            }
            $body = \Helper::utf8Encode($body);
            $new_body = $fetcher->separateReply($body, $is_html, true, false, '');

            $new_text = trim(\Helper::htmlToText($new_body));
            if ($new_text === '') {
                $this->error('Thread #'.$thread_id.': rebuilt body is empty - skipping.');
                $skipped++;
                continue;
            }
            if (trim($new_body) === trim($thread->body)) {
                $this->line('Thread #'.$thread_id.': body unchanged - skipping.');
                $skipped++;
                continue;
            }

            $this->info('Thread #'.$thread_id.' (conversation #'.$thread->conversation_id.', found in '.$found_in.'):');
            $this->line('  OLD: '.mb_substr(trim(\Helper::htmlToText($thread->body)), 0, 120));
            $this->line('  NEW: '.mb_substr($new_text, 0, 120));

            if ($this->option('dry-run')) {
                continue;
            }

            // Keep a backup of the old body before overwriting.
            $backup_dir = storage_path('app/em-thread-repair');
            if (!is_dir($backup_dir)) {
                mkdir($backup_dir, 0755, true);
            }
            file_put_contents($backup_dir.'/thread_'.$thread->id.'_'.date('YmdHis').'.html', $thread->body);

            $thread->body = $new_body;
            $thread->save();

            // Update the conversation preview if this is the latest thread.
            $conversation = $thread->conversation;
            $last_thread = $conversation->threads()
                ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
                ->orderByDesc('created_at')
                ->first();
            if ($last_thread && $last_thread->id == $thread->id) {
                $conversation->setPreview($new_body);
                $conversation->save();
            }

            $repaired++;
        }

        $this->info('Repaired: '.$repaired.', skipped: '.$skipped
            .($this->option('dry-run') ? ' (dry run - nothing saved)' : '. Old bodies backed up in storage/app/em-thread-repair/'));

        return 0;
    }
}
