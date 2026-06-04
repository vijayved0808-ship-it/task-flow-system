<?php

namespace App\Domain\WhatsApp\Services;

use App\Domain\Task\Models\Task;
use App\Domain\Logs\Models\ActivityLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private string $apiUrl;
    private string $token;
    private string $phoneNumberId;
    private bool $enabled;

    public function __construct()
    {
        $this->token = config('services.whatsapp.token') ?? env('WHATSAPP_TOKEN') ?? '';
        $this->phoneNumberId = config('services.whatsapp.phone_number_id') ?? env('WHATSAPP_PHONE_NUMBER_ID') ?? '';
        $this->apiUrl = "https://graph.facebook.com/v21.0/{$this->phoneNumberId}/messages";

        $this->enabled = !empty($this->token) && !empty($this->phoneNumberId);

        if (!$this->enabled) {
            ActivityLog::record(
                'whatsapp_out', 'init', 'failed',
                'WhatsApp service init failed — token or phone_number_id missing',
                ['token_set' => !empty($this->token), 'phone_id_set' => !empty($this->phoneNumberId)]
            );
        }
    }

    public function sendMessage(string $to, string $message): bool
    {
        $cleanPhone = preg_replace('/[^\d]/', '', $to);

        if (!$this->enabled) {
            ActivityLog::record(
                'whatsapp_out', 'send', 'failed',
                "WhatsApp send skipped — service disabled",
                ['preview' => substr($message, 0, 80)],
                $cleanPhone
            );
            return false;
        }

        ActivityLog::record(
            'whatsapp_out', 'send_attempt', 'info',
            "Sending WhatsApp to {$cleanPhone}",
            ['preview' => substr($message, 0, 80)],
            $cleanPhone
        );

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
            ])->post($this->apiUrl, [
                'messaging_product' => 'whatsapp',
                'to'                => $cleanPhone,
                'type'              => 'text',
                'text'              => [
                    'preview_url' => false,
                    'body'        => $message,
                ],
            ]);

            if ($response->successful()) {
                $messageId = $response->json('messages.0.id', 'unknown');
                ActivityLog::record(
                    'whatsapp_out', 'send', 'success',
                    "✅ WhatsApp delivered to {$cleanPhone}",
                    ['message_id' => $messageId, 'preview' => substr($message, 0, 80)],
                    $cleanPhone
                );
                return true;
            } else {
                $errorBody = $response->body();
                $errorMsg = $response->json('error.message', 'Unknown error');
                $errorCode = $response->json('error.code', 'unknown');

                ActivityLog::record(
                    'whatsapp_out', 'send', 'failed',
                    "❌ Meta API rejected: {$errorMsg}",
                    [
                        'status_code' => $response->status(),
                        'error_code' => $errorCode,
                        'error_message' => $errorMsg,
                        'full_response' => substr($errorBody, 0, 300),
                    ],
                    $cleanPhone
                );
                return false;
            }
        } catch (\Exception $e) {
            ActivityLog::record(
                'whatsapp_out', 'send', 'failed',
                "❌ Exception: " . $e->getMessage(),
                ['exception' => get_class($e)],
                $cleanPhone
            );
            return false;
        }
    }

    public function sendTaskAssignment(Task $task): bool
    {
        if (!$task->assignedTo || !$task->assignedTo->phone) {
            ActivityLog::record(
                'whatsapp_out', 'task_assignment', 'failed',
                "Task assigned but user has no phone",
                ['task_id' => $task->id, 'user_id' => $task->assigned_to]
            );
            return false;
        }

        $dueDate = $task->due_date
            ? $task->due_date->format('d M, h:i A')
            : 'No deadline';

        $assignedBy = $task->assignedBy?->name ?? 'Admin';

        $message = "👋 *New Task Assigned*\n\n"
            . "📋 *Task:* {$task->title}\n"
            . "👤 *From:* {$assignedBy}\n"
            . "🆔 *ID:* T-" . substr($task->id, 0, 6) . "\n"
            . "📅 *Due:* {$dueDate}\n"
            . "⚡ *Priority:* " . ucfirst($task->priority) . "\n"
            . "⭐ *Points:* {$task->reward_points}\n\n"
            . "Reply *START* to begin\n"
            . "Reply *HELP* for all commands";

        return $this->sendMessage($task->assignedTo->phone, $message);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
