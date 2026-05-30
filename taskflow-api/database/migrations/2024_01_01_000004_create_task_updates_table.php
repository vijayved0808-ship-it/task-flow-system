<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_updates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('user_id');
            $table->string('wa_message_id', 255)->nullable();
            $table->enum('command', ['start','update','complete','delay','help','escalate'])->nullable();
            $table->text('message')->nullable();
            $table->json('ai_analysis')->nullable();
            $table->integer('response_time_minutes')->nullable();
            $table->timestamps();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down(): void { Schema::dropIfExists('task_updates'); }
};
