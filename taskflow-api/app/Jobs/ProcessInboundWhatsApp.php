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
            $text      = $message['text']['body'] ?? '';
            $messageId = $message['id'];

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
                $singleWordCommands = ['ASSIGN', 'LIST', 'STATUS', 'VERIFY', 'REJECT', 'REPORT', 'START', 'UPDATE', 'COMPLETE', 'DELAY', 'ESCALATE', 'SCORE', 'HELP'];
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

            $reply = $handler->handle($user, $command, $text, $messageId);

            $wa->sendMessage($phone, $reply);

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
