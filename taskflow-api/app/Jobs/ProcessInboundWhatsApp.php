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
            $msgType   = $message['type'];
            $text      = $message['text']['body'] ?? '';
            $messageId = $message['id'];

            Log::info('WA message received', ['phone' => $phone, 'text' => $text]);

            $user = User::where('phone', $phone)->orWhere('phone', '+' . $phone)->first();

            if (!$user) {
                $wa->sendMessage($phone, "Hi! You're not registered in TaskFlow.\nPlease contact your manager.");
                return;
            }

            // Parse command — first word is the command
            $parts   = explode("\n", trim($text), 2);
            $command = strtoupper(trim($parts[0]));

            $reply = $handler->handle($user, $command, $text, $messageId);
            $wa->sendMessage($phone, $reply);

        } catch (\Exception $e) {
            Log::error('ProcessInboundWhatsApp failed', ['error' => $e->getMessage(), 'payload' => $this->payload]);
        }
    }
}
