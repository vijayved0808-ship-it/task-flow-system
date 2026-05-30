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

    public function __construct()
    {
        $this->token         = config('services.whatsapp.token');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
        $this->apiUrl        = 'https://graph.facebook.com/' . config('services.whatsapp.api_version', 'v19.0');
    }

    public function sendMessage(string $phone, string $message): bool
    {
        try {
            $response = Http::withToken($this->token)
                ->post("{$this->apiUrl}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $this->normalizePhone($phone),
                    'type'              => 'text',
                    'text'              => ['body' => $message],
                ]);

            if (!$response->successful()) {
                Log::error('WhatsApp send failed', ['response' => $response->json(), 'phone' => $phone]);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::error('WhatsApp exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function sendTaskAssignment(Task $task): void
    {
        $employee = $task->assignedTo;
        $priority = strtoupper($task->priority);
        $due      = $task->due_date ? $task->due_date->format('D, d M Y h:i A') : 'No deadline';

        $msg = "Hello {$employee->name} 👋\n\n"
             . "📋 *New Task Assigned*\n"
             . "Task: {$task->title}\n"
             . "Priority: {$priority}\n"
             . "Due: {$due}\n"
             . "Points: ⭐ {$task->reward_points}\n\n";

        if ($task->description) {
            $msg .= "{$task->description}\n\n";
        }

        $msg .= "Reply with:\n"
              . "*START* – Begin working\n"
              . "*HELP* – View all commands";

        $this->sendMessage($employee->phone, $msg);
    }

    public function sendTaskCompletedNotification(Task $task): void
    {
        $manager = $task->assignedBy;
        if (!$manager || !$manager->phone) return;

        $msg = "✅ *Task Completed*\n\n"
             . "Employee: {$task->assignedTo->name}\n"
             . "Task: {$task->title}\n"
             . "Completed: " . now()->format('d M, h:i A') . "\n\n"
             . "Please verify and rate the work.";

        $this->sendMessage($manager->phone, $msg);
    }

    public function sendToEmployee(string $phone, string $message): void
    {
        $this->sendMessage($phone, $message);
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }
}
