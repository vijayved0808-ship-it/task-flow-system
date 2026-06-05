<?php

namespace App\Console\Commands;

use App\Domain\Logs\Models\ActivityLog;
use App\Domain\WhatsApp\Models\WaMedia;
use Illuminate\Console\Command;

/**
 * Delete WhatsApp media files older than their expires_at (default 2 hours).
 * Removes both the local file AND the wa_media DB row.
 *
 * Usage:
 *   php artisan media:cleanup
 *   php artisan media:cleanup --dry-run
 */
class CleanupOldMedia extends Command
{
    protected $signature = 'media:cleanup {--dry-run : Show what would be deleted without removing}';
    protected $description = 'Delete expired WhatsApp media files (2-hour TTL)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $cutoff = now();

        $expired = WaMedia::where('expires_at', '<=', $cutoff)->get();

        $this->info("Found {$expired->count()} expired media file(s).");

        $filesDeleted   = 0;
        $rowsDeleted    = 0;
        $missingOnDisk  = 0;
        $deleteFailed   = 0;

        foreach ($expired as $media) {
            $sizeKb = (is_readable($media->file_path) ? round(filesize($media->file_path) / 1024) : 0);
            $this->line("• {$media->id} — {$media->type}/{$media->mime_type} ({$sizeKb} KB) — created {$media->created_at}");

            if ($dryRun) continue;

            // Delete physical file
            if (is_file($media->file_path)) {
                if (@unlink($media->file_path)) {
                    $filesDeleted++;
                } else {
                    $deleteFailed++;
                    $this->warn("  ⚠️ Could not delete file: {$media->file_path}");
                }
            } else {
                $missingOnDisk++;
            }

            // Delete DB row
            $media->delete();
            $rowsDeleted++;
        }

        $this->newLine();
        if ($dryRun) {
            $this->info("Dry-run — no changes made. Would have deleted {$expired->count()} record(s).");
        } else {
            $this->info("Done. Rows deleted: {$rowsDeleted} · Files removed: {$filesDeleted} · Already missing: {$missingOnDisk} · Failed: {$deleteFailed}");

            if ($rowsDeleted > 0) {
                ActivityLog::record(
                    'media', 'cleanup', 'success',
                    "🧹 Cleaned up {$rowsDeleted} expired media files",
                    ['rows_deleted' => $rowsDeleted, 'files_deleted' => $filesDeleted, 'delete_failed' => $deleteFailed]
                );
            }
        }

        return Command::SUCCESS;
    }
}
