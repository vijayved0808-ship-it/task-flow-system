<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('meta_media_id', 100)->index(); // inbound media id from Meta
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->string('type', 20);            // image, document, video, audio
            $table->string('mime_type', 100)->nullable();
            $table->string('filename', 255)->nullable();
            $table->string('file_path', 500);      // local storage path
            $table->text('caption')->nullable();
            $table->foreignUuid('task_id')->nullable()->constrained('tasks')->onDelete('set null');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_media');
    }
};
