<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->uuid('assigned_by');
            $table->uuid('assigned_to')->nullable();
            $table->uuid('team_id')->nullable();
            $table->enum('status', ['assigned','accepted','in_progress','waiting','completed','verified','rejected','escalated'])->default('assigned');
            $table->enum('priority', ['low','medium','high','critical'])->default('medium');
            $table->timestamp('due_date')->nullable();
            $table->integer('reward_points')->default(0);
            $table->boolean('is_recurring')->default(false);
            $table->json('recurrence_rule')->nullable();
            $table->decimal('ai_score', 5, 2)->nullable();
            $table->text('ai_summary')->nullable();
            $table->uuid('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign('assigned_by')->references('id')->on('users');
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->index(['status', 'assigned_to']);
            $table->index(['due_date', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('tasks'); }
};
