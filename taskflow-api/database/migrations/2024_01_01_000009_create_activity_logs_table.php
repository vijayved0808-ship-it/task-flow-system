<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 50)->index();       // whatsapp_out, whatsapp_in, task_create, user_create, error
            $table->string('action', 100);             // send, receive, assign, etc.
            $table->string('status', 20);              // success, failed, info
            $table->text('message');                   // human-readable message
            $table->jsonb('meta')->nullable();         // structured details
            $table->string('phone', 20)->nullable()->index();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
