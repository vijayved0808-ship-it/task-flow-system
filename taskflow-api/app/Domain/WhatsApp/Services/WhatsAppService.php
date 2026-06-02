<?php

namespace App\Domain\WhatsApp\Services;

use App\Domain\Task\Models\Task;
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
        
        // Disable WhatsApp if token/phone_id not configured
        $this->enabled = !empty($this->token) && !empty($this->phoneNumberId);
        
        if (!$this->enabled) {
            Log::warning('WhatsApp service disabled - missing token or phone_number_id');
        }
    }

    public function sendMessage(string $to, string $message): bool
    {
        if (!$this->enabled) {
            Log::info('WhatsApp message skipped (service disabled)', ['to' => $to, 'message' => substr($message, 0, 50)]);
            return false;
        }

        try {
            // Clean phone number (remove + and spaces)
            $cleanPhone = preg_replace('/[^\d]/', '', $to);
            
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
                Log::info('WhatsApp sent', ['to' => $cleanPhone]);
                return true;
            } else {
                Log::warning('WhatsApp send failed', [
                    'to' => $cleanPhone,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('WhatsApp exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function sendTaskAssignment(Task $task): bool
    {
        if (!$task->assignedTo || !$task->assignedTo->phone) {
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
