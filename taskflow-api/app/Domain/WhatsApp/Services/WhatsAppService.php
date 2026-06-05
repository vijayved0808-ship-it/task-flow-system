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

    // ════════════════════════════════════════════════════════════════════
    // MEDIA HANDLING — download from Meta, re-upload to forward
    // ════════════════════════════════════════════════════════════════════

    /**
     * Download a media file from Meta by its media_id.
     * Returns the local file path on success, null on failure.
     * Storage location: storage_path('app/wa_media/{uuid}.ext')
     */
    public function downloadMedia(string $mediaId, ?string $expectedMimeType = null): ?string
    {
        if (!$this->enabled) return null;

        try {
            // Step 1: Get media URL from Meta
            $meta = Http::withToken($this->token)
                ->get("https://graph.facebook.com/v21.0/{$mediaId}");

            if (!$meta->successful()) {
                ActivityLog::record(
                    'whatsapp_in', 'media_meta', 'failed',
                    "❌ Could not fetch media metadata for {$mediaId}",
                    ['status' => $meta->status(), 'body' => substr($meta->body(), 0, 200)]
                );
                return null;
            }

            $url = $meta->json('url');
            $mime = $meta->json('mime_type', $expectedMimeType ?? 'application/octet-stream');
            if (!$url) return null;

            // Step 2: Download the file binary
            $file = Http::withToken($this->token)->get($url);
            if (!$file->successful()) {
                ActivityLog::record(
                    'whatsapp_in', 'media_download', 'failed',
                    "❌ Could not download media from {$url}",
                    ['status' => $file->status()]
                );
                return null;
            }

            // Step 3: Save locally
            $ext = $this->mimeToExtension($mime);
            $dir = storage_path('app/wa_media');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $filename = uniqid('wa_', true) . '.' . $ext;
            $path = $dir . DIRECTORY_SEPARATOR . $filename;
            file_put_contents($path, $file->body());

            ActivityLog::record(
                'whatsapp_in', 'media_download', 'success',
                "📎 Media downloaded: {$mediaId} ({$mime}, " . round(strlen($file->body()) / 1024) . " KB)",
                ['media_id' => $mediaId, 'mime' => $mime, 'path' => $path]
            );

            return $path;
        } catch (\Exception $e) {
            ActivityLog::record(
                'whatsapp_in', 'media_download', 'failed',
                "❌ Exception downloading media: " . $e->getMessage(),
                ['media_id' => $mediaId]
            );
            return null;
        }
    }

    /**
     * Upload a local file to Meta to get a fresh media_id we can send.
     * Returns the new media_id on success, null on failure.
     */
    public function uploadMedia(string $localPath, string $mimeType): ?string
    {
        if (!$this->enabled || !is_readable($localPath)) return null;

        try {
            $url = "https://graph.facebook.com/v21.0/{$this->phoneNumberId}/media";

            $resp = Http::withToken($this->token)
                ->attach('file', file_get_contents($localPath), basename($localPath), ['Content-Type' => $mimeType])
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'type'              => $mimeType,
                ]);

            if (!$resp->successful()) {
                ActivityLog::record(
                    'whatsapp_out', 'media_upload', 'failed',
                    "❌ Media upload to Meta rejected",
                    ['status' => $resp->status(), 'body' => substr($resp->body(), 0, 300)]
                );
                return null;
            }

            return $resp->json('id');
        } catch (\Exception $e) {
            ActivityLog::record(
                'whatsapp_out', 'media_upload', 'failed',
                "❌ Exception uploading media: " . $e->getMessage(),
                []
            );
            return null;
        }
    }

    /**
     * Send a media message (image/document/video/audio) to a recipient.
     * Internally re-uploads the file to Meta to get a fresh media_id.
     */
    public function sendMedia(string $to, string $localPath, string $mimeType, ?string $caption = null, ?string $filename = null): bool
    {
        if (!$this->enabled) return false;

        $cleanPhone = preg_replace('/[^\d]/', '', $to);
        $waType = $this->mimeToWaType($mimeType);

        $newMediaId = $this->uploadMedia($localPath, $mimeType);
        if (!$newMediaId) return false;

        try {
            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $cleanPhone,
                'type'              => $waType,
                $waType             => ['id' => $newMediaId],
            ];

            // image / video / document support caption
            if ($caption && in_array($waType, ['image', 'video', 'document'])) {
                $payload[$waType]['caption'] = $caption;
            }
            // document supports filename
            if ($waType === 'document' && $filename) {
                $payload['document']['filename'] = $filename;
            }

            $resp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
            ])->post($this->apiUrl, $payload);

            if ($resp->successful()) {
                ActivityLog::record(
                    'whatsapp_out', 'send_media', 'success',
                    "📎 Media sent to {$cleanPhone} ({$waType})",
                    ['type' => $waType, 'caption' => substr($caption ?? '', 0, 60)],
                    $cleanPhone
                );
                return true;
            }

            ActivityLog::record(
                'whatsapp_out', 'send_media', 'failed',
                "❌ Media send rejected: " . $resp->json('error.message', 'Unknown'),
                ['status' => $resp->status(), 'body' => substr($resp->body(), 0, 300)],
                $cleanPhone
            );
            return false;
        } catch (\Exception $e) {
            ActivityLog::record(
                'whatsapp_out', 'send_media', 'failed',
                "❌ Exception sending media: " . $e->getMessage(),
                [],
                $cleanPhone
            );
            return false;
        }
    }

    /**
     * Map a MIME type to a WhatsApp message type.
     */
    public function mimeToWaType(string $mime): string
    {
        if (str_starts_with($mime, 'image/'))  return 'image';
        if (str_starts_with($mime, 'video/'))  return 'video';
        if (str_starts_with($mime, 'audio/'))  return 'audio';
        return 'document'; // PDF, Word, Excel, PPT, etc.
    }

    /**
     * Map MIME type to a file extension. Used for local storage filename.
     */
    private function mimeToExtension(string $mime): string
    {
        $map = [
            'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
            'image/gif' => 'gif',  'image/webp' => 'webp',
            'video/mp4' => 'mp4',  'video/3gpp' => '3gp',
            'audio/mpeg' => 'mp3', 'audio/aac' => 'aac',  'audio/ogg' => 'ogg',
            'audio/mp4' => 'm4a',  'audio/amr' => 'amr',
            'application/pdf' => 'pdf',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'text/csv'   => 'csv',
        ];
        return $map[$mime] ?? 'bin';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
