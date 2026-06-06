<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->default('default');
            $table->string('title', 500);
            $table->foreignUuid('assigned_by')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('assigned_to')->constrained('users')->onDelete('cascade');
            $table->string('schedule_type', 20);   // 'daily' or 'weekly'
            $table->json('days_of_week')->nullable(); // ["mon","wed","fri"] for weekly
            $table->string('priority', 20)->default('medium');
            $table->integer('reward_points')->default(50);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'schedule_type']);
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_schedules');
    }
};
