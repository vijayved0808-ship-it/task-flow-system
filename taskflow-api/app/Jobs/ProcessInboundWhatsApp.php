<?php

namespace App\Jobs;

use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Handlers\CommandHandler;
use App\Domain\WhatsApp\Services\WhatsAppService;
use App\Domain\Logs\Models\ActivityLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessInboundWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(private array $payload) {}

    public function handle(CommandHandler $handler, WhatsAppService $wa): void
    {
        try {
            $entry   = $this->payload['entry'][0] ?? null;
            $changes = $entry['changes'][0] ?? null;
            $value   = $changes['value'] ?? null;

            if (!$value || empty($value['messages'])) {
                return; // Status update — not a message
            }

            $message   = $value['messages'][0];
            $phone     = $message['from'];
            $msgType   = $message['type'] ?? 'text';
            $messageId = $message['id'];

            // ── WEBHOOK DEDUPLICATION ──
            // Meta retries webhooks on slow ACK (common on Render free tier cold starts).
            // Use the message-id as a 24-hour dedup key. First arrival wins; retries skip.
            if ($messageId) {
                $dedupKey = "wa_msg_dedup:{$messageId}";
                if (Cache::has($dedupKey)) {
                    Log::info("Duplicate webhook skipped", ['message_id' => $messageId]);
                    ActivityLog::record(
                        'whatsapp_in', 'duplicate_skipped', 'info',
                        "⏭ Duplicate webhook skipped (message {$messageId} already processed)",
                        ['message_id' => $messageId]
                    );
                    return;
                }
                Cache::put($dedupKey, true, now()->addHours(24));
            }

            // ── Extract text + media payload from any message type ──
            $text          = '';
            $mediaId       = null;
            $mediaType     = null; // 'image', 'document', 'video', 'audio'
            $mediaMime     = null;
            $mediaFilename = null;

            switch ($msgType) {
                case 'text':
                    $text = $message['text']['body'] ?? '';
                    break;
                case 'image':
                    $text       = $message['image']['caption'] ?? '';
                    $mediaId    = $message['image']['id'] ?? null;
                    $mediaType  = 'image';
                    $mediaMime  = $message['image']['mime_type'] ?? 'image/jpeg';
                    break;
                case 'document':
                    $text          = $message['document']['caption'] ?? '';
                    $mediaId       = $message['document']['id'] ?? null;
                    $mediaType     = 'document';
                    $mediaMime     = $message['document']['mime_type'] ?? 'application/octet-stream';
                    $mediaFilename = $message['document']['filename'] ?? null;
                    break;
                case 'video':
                    $text      = $message['video']['caption'] ?? '';
                    $mediaId   = $message['video']['id'] ?? null;
                    $mediaType = 'video';
                    $mediaMime = $message['video']['mime_type'] ?? 'video/mp4';
                    break;
                case 'audio':
                    $mediaId   = $message['audio']['id'] ?? null;
                    $mediaType = 'audio';
                    $mediaMime = $message['audio']['mime_type'] ?? 'audio/ogg';
                    break;
                case 'voice':
                    $mediaId   = $message['voice']['id'] ?? null;
                    $mediaType = 'audio';
                    $mediaMime = $message['voice']['mime_type'] ?? 'audio/ogg';
                    break;
                default:
                    // Unknown types (sticker, location, contacts) — log and skip parsing
                    break;
            }

            $phoneWithPlus = '+' . $phone;

            ActivityLog::record(
                'whatsapp_in', 'receive', 'success',
                "📩 Received from {$phoneWithPlus}: \"" . substr($text, 0, 100) . "\"",
                ['type' => $msgType, 'message_id' => $messageId],
                $phoneWithPlus
            );

            // Find user by phone
            $user = User::where('phone', $phoneWithPlus)
                ->orWhere('phone', $phone)
                ->orWhere('phone', 'LIKE', '%' . substr($phone, -10))
                ->first();

            if (!$user) {
                ActivityLog::record(
                    'whatsapp_in', 'user_lookup', 'failed',
                    "User not found for phone {$phoneWithPlus}",
                    [],
                    $phoneWithPlus
                );
                $wa->sendMessage($phone,
                    "👋 Hi! You're not registered in TaskFlow.\n\n"
                    . "Please ask your manager to add you."
                );
                return;
            }

            if (!$user->is_active) {
                $wa->sendMessage($phone, "Your account is inactive. Please contact your manager.");
                return;
            }

            // ── If a media file came in, download it and create a WaMedia record ──
            $waMedia = null;
            if ($mediaId && $mediaType) {
                $localPath = $wa->downloadMedia($mediaId, $mediaMime);
                if ($localPath) {
                    $waMedia = \App\Domain\WhatsApp\Models\WaMedia::create([
                        'meta_media_id' => $mediaId,
                        'user_id'       => $user->id,
                        'type'          => $mediaType,
                        'mime_type'     => $mediaMime,
                        'filename'      => $mediaFilename,
                        'file_path'     => $localPath,
                        'caption'       => $text,
                        'task_id'       => null,
                        'expires_at'    => now()->addHours(2),
                    ]);
                }
            }

            // Parse command - handle multi-word commands FIRST
            $text = trim($text);
            $textUpper = strtoupper($text);

            // Detect command (multi-word commands take priority)
            $command = '';
            $multiWordCommands = ['ADD EMPLOYEE', 'LIST EMPLOYEES', 'REPORT TODAY', 'REPORT WEEK', 'MY TASKS'];
            foreach ($multiWordCommands as $mwc) {
                if (str_starts_with($textUpper, $mwc)) {
                    $command = $mwc;
                    break;
                }
            }

            // Single-word commands
            if (empty($command)) {
                $singleWordCommands = [
                    // Existing commands
                    'ASSIGN', 'LIST', 'STATUS', 'VERIFY', 'REJECT', 'REPORT',
                    'START', 'UPDATE', 'COMPLETE', 'DELAY', 'ESCALATE', 'SCORE', 'HELP',
                    // Phase 1 — query commands
                    'URGENT', 'HIGH', 'TODAY', 'OVERDUE', 'PENDING',
                    // Phase 1 — admin actions
                    'CANCEL', 'REASSIGN', 'FORWARD', 'REOPEN',
                    // Phase 2 — inter-user chat
                    'CHAT', 'DM', 'REPLY',
                    // Phase 3 — chat session close
                    'CLOSE', 'END', 'BYE', 'EXIT',
                    // Phase 4 — team overview + batched assign markers
                    'ALL', 'DONE', 'FINISH', 'SEND', 'ABORT',
                ];
                $firstWord = strtoupper(explode(' ', $text)[0] ?? '');
                if (in_array($firstWord, $singleWordCommands)) {
                    $command = $firstWord;
                }
            }

            ActivityLog::record(
                'whatsapp_in', 'command_parse', 'info',
                "🔍 User: {$user->name} ({$user->role}) | Command: " . ($command ?: 'UNKNOWN'),
                [
                    'user_id' => $user->id,
                    'is_manager' => $user->isManager(),
                    'raw_text' => $text,
                    'parsed_command' => $command
                ],
                $phoneWithPlus
            );

            $reply = $handler->handle($user, $command, $text, $messageId, $waMedia);

            // Empty/null reply means "no echo back to sender" (e.g. chat-mode silent forward)
            if ($reply !== '' && $reply !== null) {
                $wa->sendMessage($phone, $reply);
            }

        } catch (\Exception $e) {
            ActivityLog::record(
                'whatsapp_in', 'process', 'failed',
                "❌ Exception: " . $e->getMessage(),
                ['exception' => get_class($e), 'line' => $e->getLine()]
            );
            Log::error('ProcessInboundWhatsApp failed', [
                'error'   => $e->getMessage(),
                'payload' => $this->payload
            ]);
        }
    }
}
