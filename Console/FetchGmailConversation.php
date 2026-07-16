<?php

namespace Modules\Everymarket\Console;

use App\Conversation;
use App\Mailbox;
use App\Thread;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Fetch a conversation's email chain from the IMAP server (Gmail) instead of
 * the DB: lists every message of the thread as the mail server has it and
 * marks which ones have no thread record in FreeScout.
 *
 * Strategy: resolve Gmail's native thread id (X-GM-THRID) from one of the
 * conversation's known Message-IDs and fetch the whole Gmail thread. If the
 * server does not support it, fall back to searching by Message-ID /
 * In-Reply-To / References chains.
 */
class FetchGmailConversation extends Command
{
    protected $signature = 'everymarket:fetch-gmail-conversation
        {--mailbox= : Mailbox ID}
        {--conversation= : Conversation ID}
        {--folders= : Comma separated IMAP folders (default: "[Gmail]/All Mail", falling back to mailbox fetch folders)}
        {--save-eml= : Directory to save raw .eml files of the found messages}';

    protected $description = 'Fetch a conversation\'s email chain from the IMAP server and compare it with DB threads';

    public function handle()
    {
        $mailbox = Mailbox::find((int) $this->option('mailbox'));
        if (!$mailbox) {
            $this->error('Mailbox not found. Pass --mailbox=<id>.');
            return 1;
        }
        $conversation = Conversation::find((int) $this->option('conversation'));
        if (!$conversation || $conversation->mailbox_id != $mailbox->id) {
            $this->error('Conversation not found in this mailbox. Pass --conversation=<id>.');
            return 1;
        }

        // Known Message-IDs from the DB: stored ones for incoming threads,
        // computed FS_reply-... ones for outgoing threads.
        $db_message_ids = [];
        $seeds = [];
        $threads = $conversation->threads()
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->orderBy('created_at')
            ->get();
        foreach ($threads as $thread) {
            foreach (array_unique(array_filter([$thread->message_id, $thread->getMessageId($mailbox)])) as $mid) {
                $db_message_ids[mb_strtolower($mid)] = $thread->id;
                $seeds[] = $mid;
            }
        }
        if (!count($seeds)) {
            $this->error('Conversation #'.$conversation->id.' has no threads with Message-IDs to search by.');
            return 1;
        }

        $client = \MailHelper::getMailboxClient($mailbox);
        $client->connect();

        $folders = $this->resolveFolders($client, $mailbox);
        if (!count($folders)) {
            $this->error('No IMAP folders available.');
            return 1;
        }
        $this->line('Searching folders: '.implode(', ', array_keys($folders)));

        $fetch_body = (bool) $this->option('save-eml');

        // Found messages keyed by lowercased Message-ID.
        $found = [];

        // Strategy A: Gmail native thread id.
        $thrid = $this->resolveGmailThreadId($folders, $seeds);
        if ($thrid) {
            $this->line('Gmail thread id (X-GM-THRID): '.$thrid);
            foreach ($folders as $folder_name => $folder) {
                try {
                    $messages = $folder->query()
                        ->where('CUSTOM X-GM-THRID', $thrid)
                        ->leaveUnread()
                        ->setFetchBody($fetch_body)
                        ->get();
                    $this->collect($messages, $folder_name, $found);
                } catch (\Exception $e) {
                    $this->error('X-GM-THRID search failed in '.$folder_name.': '.$e->getMessage());
                }
            }
        }

        // Strategy B (also a safety net if A found nothing): follow the
        // Message-ID / In-Reply-To / References chain.
        if (!count($found)) {
            if ($thrid) {
                $this->line('Falling back to Message-ID chain search.');
            }
            $this->searchByReferenceChain($folders, $seeds, $found, $fetch_body);
        }

        if (!count($found)) {
            $this->error('No messages of this conversation found on the server.');
            return 1;
        }

        // Report.
        $rows = [];
        $missing = 0;
        foreach ($found as $key => $info) {
            $in_db = isset($db_message_ids[$key]);
            if (!$in_db) {
                $missing++;
            }
            $rows[] = [
                'date'       => $info['date'],
                'from'       => $info['from'],
                'subject'    => mb_substr($info['subject'], 0, 50),
                'message_id' => $info['message_id'],
                'folder'     => $info['folder'],
                'in_db'      => $in_db ? 'thread #'.$db_message_ids[$key] : 'NO - MISSING',
            ];
        }
        usort($rows, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        $this->info(count($found).' message(s) on the server for conversation #'.$conversation->id
            .' ('.count($threads).' email thread(s) in DB, '.$missing.' server message(s) without a DB thread):');
        $this->table(['Date', 'From', 'Subject', 'Message-ID', 'Folder', 'In DB'], $rows);

        if ($this->option('save-eml')) {
            $this->saveEmls($found);
        }

        return $missing ? 2 : 0;
    }

    protected function resolveFolders($client, $mailbox): array
    {
        if ($this->option('folders')) {
            $folder_names = array_filter(array_map('trim', explode(',', $this->option('folders'))));
        } else {
            // All Mail contains both received and sent messages.
            $folder_names = ['[Gmail]/All Mail'];
        }

        $folders = [];
        foreach ($folder_names as $folder_name) {
            try {
                $folder = \MailHelper::getImapFolder($client, $folder_name);
                if ($folder) {
                    $folders[$folder_name] = $folder;
                }
            } catch (\Exception $e) {
                // Handled below.
            }
        }

        // Not a Gmail server - use the mailbox fetch folders.
        if (!count($folders) && !$this->option('folders')) {
            foreach ($mailbox->getInImapFolders() as $folder_name) {
                try {
                    $folder = \MailHelper::getImapFolder($client, $folder_name);
                    if ($folder) {
                        $folders[$folder_name] = $folder;
                    }
                } catch (\Exception $e) {
                    $this->error('IMAP folder not found: '.$folder_name);
                }
            }
        }

        return $folders;
    }

    /**
     * Get Gmail's thread id from any of the conversation's Message-IDs.
     */
    protected function resolveGmailThreadId(array $folders, array $seeds)
    {
        foreach ($folders as $folder) {
            foreach ($seeds as $seed) {
                try {
                    $messages = $folder->query()
                        ->whereMessageId($seed)
                        ->leaveUnread()
                        ->setFetchBody(false)
                        ->setExtensions(['X-GM-THRID'])
                        ->limit(1)
                        ->get();
                    if (count($messages)) {
                        $thrid = trim((string) $messages->first()->getHeader()->get('X-GM-THRID'));
                        if ($thrid !== '' && is_numeric($thrid)) {
                            return $thrid;
                        }
                    }
                } catch (\Exception $e) {
                    // Extension or search not supported - fall back.
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Find chain messages by Message-ID, In-Reply-To and References headers,
     * iterating over newly discovered Message-IDs.
     */
    protected function searchByReferenceChain(array $folders, array $seeds, array &$found, bool $fetch_body)
    {
        $queue = array_unique($seeds);
        $searched = [];
        $rounds = 0;

        while (count($queue) && $rounds < 5 && count($found) < 200) {
            $rounds++;
            $next = [];

            foreach ($queue as $mid) {
                $key = mb_strtolower($mid);
                if (isset($searched[$key])) {
                    continue;
                }
                $searched[$key] = true;

                foreach ($folders as $folder_name => $folder) {
                    foreach (['Message-ID', 'In-Reply-To', 'References'] as $header) {
                        try {
                            $messages = $folder->query()
                                ->where('CUSTOM HEADER '.$header, $mid)
                                ->leaveUnread()
                                ->setFetchBody($fetch_body)
                                ->get();
                        } catch (\Exception $e) {
                            continue;
                        }
                        foreach ($this->collect($messages, $folder_name, $found) as $new_info) {
                            $next[] = $new_info['message_id'];
                            foreach ($new_info['prev_ids'] as $prev_id) {
                                $next[] = $prev_id;
                            }
                        }
                    }
                }
            }

            $queue = array_unique($next);
        }
    }

    /**
     * Add messages to the found list; returns infos of newly added ones.
     */
    protected function collect($messages, string $folder_name, array &$found): array
    {
        $new = [];
        foreach ($messages as $message) {
            $message_id = trim((string) $message->getMessageId());
            if ($message_id === '' || isset($found[mb_strtolower($message_id)])) {
                continue;
            }

            $date = $message->getDate();
            if (is_object($date) && get_class($date) == 'Webklex\PHPIMAP\Attribute') {
                $date = $date->toDate();
            }

            $from = '';
            $from_list = $message->getFrom();
            if (is_object($from_list) && get_class($from_list) == 'Webklex\PHPIMAP\Attribute') {
                $from_list = $from_list->toArray();
            }
            if ($from_list) {
                foreach ($from_list as $item) {
                    if (!empty($item->mail)) {
                        $from = $item->mail;
                        break;
                    }
                }
            }

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

            $info = [
                'message'    => $message,
                'message_id' => $message_id,
                'date'       => $date instanceof Carbon ? $date->format('Y-m-d H:i') : '',
                'from'       => $from,
                'subject'    => trim((string) $message->getSubject()),
                'folder'     => $folder_name,
                'prev_ids'   => array_unique($prev_ids),
            ];

            $found[mb_strtolower($message_id)] = $info;
            $new[] = $info;
        }

        return $new;
    }

    protected function saveEmls(array $found)
    {
        $dir = rtrim($this->option('save-eml'), '/');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $saved = 0;
        foreach ($found as $info) {
            try {
                $header = $info['message']->getHeader();
                $raw = (is_string($header) ? $header : $header->raw)."\r\n\r\n".$info['message']->getRawBody();
                $filename = preg_replace('/[^A-Za-z0-9._@-]/', '_', $info['message_id']).'.eml';
                file_put_contents($dir.'/'.$filename, $raw);
                $saved++;
            } catch (\Exception $e) {
                $this->error('Could not save .eml for '.$info['message_id'].': '.$e->getMessage());
            }
        }
        $this->line($saved.' .eml file(s) saved to '.$dir);
    }
}
