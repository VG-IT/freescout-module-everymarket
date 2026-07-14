<?php

namespace Modules\Everymarket\Console;

use App\Mailbox;
use App\Thread;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Compare emails present on the IMAP server with thread records in the DB
 * and report emails which were never imported (missing threads).
 *
 * Fetches headers only and never marks messages as seen.
 */
class AuditFetchedEmails extends Command
{
    protected $signature = 'everymarket:audit-fetched-emails
        {--mailbox= : Mailbox ID to audit}
        {--after= : Start date (YYYY-MM-DD, inclusive)}
        {--before= : End date (YYYY-MM-DD, inclusive), defaults to today}
        {--days=3 : Audit the last N days (used when --after is not set)}
        {--folders= : Comma separated IMAP folders (defaults to mailbox fetch folders)}
        {--csv= : Write the report to a CSV file}
        {--import : Import missing emails via freescout:fetch-emails and re-verify}';

    protected $description = 'Find emails on the IMAP server which have no thread record in the DB';

    public function handle()
    {
        $mailbox = Mailbox::find((int) $this->option('mailbox'));
        if (!$mailbox) {
            $this->error('Mailbox not found. Pass --mailbox=<id>. Available mailboxes:');
            foreach (Mailbox::all() as $mb) {
                $this->line('  '.$mb->id.' - '.$mb->name.' ('.$mb->email.')');
            }
            return 1;
        }

        try {
            if ($this->option('after')) {
                $after = Carbon::createFromFormat('Y-m-d', $this->option('after'))->startOfDay();
            } else {
                $after = now()->subDays((int) $this->option('days'))->startOfDay();
            }
            $before = $this->option('before')
                ? Carbon::createFromFormat('Y-m-d', $this->option('before'))->endOfDay()
                : now()->endOfDay();
        } catch (\Exception $e) {
            $this->error('Invalid date, use YYYY-MM-DD format.');
            return 1;
        }
        if ($after->gt($before)) {
            $this->error('--after must be before --before.');
            return 1;
        }

        $this->line('Auditing mailbox "'.$mailbox->name.'" from '.$after->toDateString().' to '.$before->toDateString());

        // Connect using the same client as freescout:fetch-emails.
        $client = \MailHelper::getMailboxClient($mailbox);
        $client->connect();

        if ($this->option('folders')) {
            $folder_names = array_filter(array_map('trim', explode(',', $this->option('folders'))));
        } else {
            $folder_names = $mailbox->getInImapFolders();
        }

        $own_emails = $mailbox->getEmails();

        // Candidates: lowercased Message-ID => info.
        $candidates = [];
        $skipped_own = 0;
        $skipped_no_id = 0;

        foreach ($folder_names as $folder_name) {
            try {
                $folder = \MailHelper::getImapFolder($client, $folder_name);
            } catch (\Exception $e) {
                $this->error('IMAP folder not found on the mail server: '.$folder_name.' ('.$e->getMessage().')');
                continue;
            }
            if (!$folder) {
                $this->error('IMAP folder not found on the mail server: '.$folder_name);
                continue;
            }

            $this->line('Scanning folder: '.$folder_name);

            $page_size = (int) config('app.fetching_bunch_size') ?: 100;
            $page = 0;
            do {
                // IMAP SINCE/BEFORE have day granularity in the server's
                // timezone, so the range is padded and re-filtered precisely
                // below by the Date header.
                $query = $folder->query()
                    ->since($after->copy()->subDay())
                    ->before($before->copy()->addDays(2))
                    ->leaveUnread()
                    ->setFetchBody(false);
                $query->limit($page_size, $page);

                $messages = $query->get();

                foreach ($messages as $message_id => $message) {
                    $message_id = trim((string) $message_id);
                    if ($message_id === '') {
                        // Threads for emails without a Message-ID get an
                        // artificial one and can not be compared.
                        $skipped_no_id++;
                        continue;
                    }

                    $date = $this->attrToDate($message->getDate());
                    if ($date && ($date->lt($after) || $date->gt($before))) {
                        continue;
                    }

                    $from = $this->firstEmail($message->getFrom());
                    if ($from && in_array($from, $own_emails)) {
                        // Outgoing copy sent by the helpdesk itself.
                        $skipped_own++;
                        continue;
                    }

                    $candidates[mb_strtolower($message_id)] = [
                        'message_id' => $message_id,
                        'date'       => $date ? $date->format('Y-m-d H:i') : '',
                        'from'       => $from ?: '',
                        'subject'    => mb_substr(trim((string) $message->getSubject()), 0, 60),
                        'folder'     => $folder_name,
                        'prev_ids'   => $this->getPrevMessageIds($message),
                    ];
                }

                $page++;
            } while (count($messages) == $page_size);
        }

        $this->line('Emails on server in range: '.count($candidates)
            .($skipped_own ? ' (skipped '.$skipped_own.' sent by the mailbox itself)' : '')
            .($skipped_no_id ? ' (skipped '.$skipped_no_id.' without Message-ID)' : ''));

        $missing = $this->findMissing($candidates);

        if (!count($missing)) {
            $this->info('All emails have thread records in the DB. Nothing is missing.');
            return 0;
        }

        // Try to find the conversation each missing email belongs to.
        $rows = [];
        foreach ($missing as $info) {
            $conversation_id = $this->resolveConversationId($info['prev_ids']);
            $rows[] = [
                'date'         => $info['date'],
                'from'         => $info['from'],
                'subject'      => $info['subject'],
                'message_id'   => $info['message_id'],
                'folder'       => $info['folder'],
                'conversation' => $conversation_id ? '#'.$conversation_id : '(new conversation lost)',
            ];
        }
        usort($rows, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        $this->error(count($missing).' email(s) have no thread record in the DB:');
        $this->table(['Date', 'From', 'Subject', 'Message-ID', 'Folder', 'Conversation'], $rows);

        if ($this->option('csv')) {
            $this->writeCsv($this->option('csv'), $rows);
        }

        if ($this->option('import')) {
            $this->importMissing($mailbox, $after, $missing);
        }

        // Non-zero exit code so the audit can be used for monitoring/alerting.
        return 2;
    }

    /**
     * Diff candidate Message-IDs against threads.message_id.
     */
    protected function findMissing(array $candidates): array
    {
        $existing = [];
        foreach (array_chunk(array_column($candidates, 'message_id'), 500) as $chunk) {
            foreach (Thread::whereIn('message_id', $chunk)->pluck('message_id') as $mid) {
                $existing[mb_strtolower($mid)] = true;
            }
        }

        $missing = [];
        foreach ($candidates as $key => $info) {
            if (!isset($existing[$key])) {
                $missing[$key] = $info;
            }
        }

        return $missing;
    }

    /**
     * Collect In-Reply-To and References Message-IDs (as in FetchEmails).
     */
    protected function getPrevMessageIds($message): array
    {
        $prev_ids = [];

        $in_reply_to = trim((string) $message->getInReplyTo(), ' <>');
        if ($in_reply_to) {
            $prev_ids[] = $in_reply_to;
        }

        $references = $message->getReferences();
        if ($references && !is_array($references)) {
            $references = array_filter(preg_split('/[, <>]/', (string) $references));
        }
        if (is_array($references)) {
            foreach ($references as $reference) {
                $reference = trim((string) $reference, ' <>');
                if ($reference) {
                    $prev_ids[] = $reference;
                }
            }
        }

        return array_unique($prev_ids);
    }

    /**
     * Find the conversation a missing email belongs to: FreeScout's outgoing
     * Message-IDs encode the thread ID (FS_reply-{thread_id}-...), other IDs
     * are looked up in threads.message_id.
     */
    protected function resolveConversationId(array $prev_message_ids)
    {
        $prefixes = [
            \MailHelper::MESSAGE_ID_PREFIX_REPLY_TO_CUSTOMER,
            \MailHelper::MESSAGE_ID_PREFIX_AUTO_REPLY,
            \MailHelper::MESSAGE_ID_PREFIX_NOTIFICATION,
        ];

        foreach ($prev_message_ids as $prev_message_id) {
            foreach ($prefixes as $prefix) {
                if (preg_match('/^'.preg_quote($prefix, '/').'\-(\d+)\-/', $prev_message_id, $m)) {
                    $thread = Thread::find($m[1]);
                    if ($thread) {
                        return $thread->conversation_id;
                    }
                }
            }

            $thread = Thread::where('message_id', $prev_message_id)->first();
            if ($thread) {
                return $thread->conversation_id;
            }
        }

        return null;
    }

    /**
     * Recover missing emails by re-fetching the period through the standard
     * pipeline (deduplicated by Message-ID), then re-verify.
     */
    protected function importMissing($mailbox, Carbon $after, array $missing)
    {
        $days = (int) ceil($after->diffInHours(now()) / 24) + 1;

        $this->line('Importing via freescout:fetch-emails --days='.$days.' --unseen=0 --mailboxes='.$mailbox->id);

        $this->call('freescout:fetch-emails', [
            '--days'      => $days,
            '--unseen'    => 0,
            '--mailboxes' => (string) $mailbox->id,
        ]);

        $still_missing = $this->findMissing($missing);

        $recovered = count($missing) - count($still_missing);
        $this->info('Recovered: '.$recovered.' of '.count($missing));

        if (count($still_missing)) {
            $this->error('Still missing (check fetch errors in Manage > Logs):');
            foreach ($still_missing as $info) {
                $this->line('  '.$info['date'].'  '.$info['from'].'  '.$info['message_id']);
            }
        }
    }

    protected function firstEmail($obj_list)
    {
        if (!$obj_list) {
            return null;
        }
        if (is_object($obj_list) && get_class($obj_list) == 'Webklex\PHPIMAP\Attribute') {
            $obj_list = $obj_list->toArray();
        }
        foreach ($obj_list as $item) {
            $email = \App\Email::sanitizeEmail($item->mail ?? '');
            if ($email) {
                return $email;
            }
        }

        return null;
    }

    protected function attrToDate($attr)
    {
        if (!$attr) {
            return null;
        }
        if (is_object($attr) && get_class($attr) == 'Webklex\PHPIMAP\Attribute') {
            $attr = $attr->toDate();
        }

        return $attr instanceof Carbon ? $attr : null;
    }

    protected function writeCsv($path, array $rows)
    {
        $f = fopen($path, 'w');
        fputcsv($f, ['Date', 'From', 'Subject', 'Message-ID', 'Folder', 'Conversation']);
        foreach ($rows as $row) {
            fputcsv($f, array_values($row));
        }
        fclose($f);
        $this->line('Report written to '.$path);
    }
}
