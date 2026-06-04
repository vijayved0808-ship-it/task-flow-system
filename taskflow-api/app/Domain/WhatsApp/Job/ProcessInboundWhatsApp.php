<?php

namespace App\Jobs;

use App\Domain\User\Models\User;
use App\Domain\WhatsApp\Handlers\CommandHandler;
use App\Domain\WhatsApp\Services\WhatsAppService;
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

            if (!$value || empty($value['messages'])) return;

            $message   = $value['messages'][0];
            $phone     = $message['from'];
            $msgType   = $message['type'] ?? 'text';
            $text      = $message['text']['body'] ?? '';
            $messageId = $message['id'];

            // Add + if missing
            $phoneWithPlus = '+' . $phone;

            Log::info('WA message received', ['phone' => $phoneWithPlus, 'text' => $text, 'type' => $msgType]);

            // Find user by phone (try both with and without +)
            $user = User::where('phone', $phoneWithPlus)
                ->orWhere('phone', $phone)
                ->orWhere('phone', 'LIKE', '%' . substr($phone, -10))
                ->first();

            if (!$user) {
                $wa->sendMessage($phone,
                    "👋 Hi! You're not registered in TaskFlow.\n\n"
                    . "Please ask your manager to add you using:\n"
                    . "*ADD EMPLOYEE <your name> {$phoneWithPlus} <your role>*"
                );
                return;
            }

            if (!$user->is_active) {
                $wa->sendMessage($phone, "Your account is inactive. Please contact your manager.");
                return;
            }

            // Parse first word as command, rest as full message
            $text = trim($text);
            $firstWord = strtoupper(explode(' ', $text)[0] ?? '');
            
            // For multi-word commands like "ADD EMPLOYEE", check first 2 words
            $firstTwoWords = strtoupper(implode(' ', array_slice(explode(' ', $text), 0, 2)));
            $command = in_array($firstTwoWords, ['ADD EMPLOYEE', 'LIST EMPLOYEES', 'REPORT TODAY', 'REPORT WEEK'])
                ? $firstTwoWords
                : $firstWord;

            $reply = $handler->handle($user, $command, $text, $messageId);
            $wa->sendMessage($phone, $reply);

        } catch (\Exception $e) {
            Log::error('ProcessInboundWhatsApp failed', [
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'payload' => $this->payload
            ]);
        }
    }
}
