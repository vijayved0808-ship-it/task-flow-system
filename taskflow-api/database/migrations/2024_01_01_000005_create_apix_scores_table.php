<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apix_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->date('score_date');
            $table->decimal('completion_rate', 5, 2)->default(0);
            $table->decimal('timeliness_score', 5, 2)->default(0);
            $table->decimal('quality_score', 5, 2)->default(70);
            $table->decimal('consistency_score', 5, 2)->default(0);
            $table->decimal('manager_rating', 5, 2)->default(75);
            $table->decimal('apix_score', 5, 2)->default(0);
            $table->integer('tasks_assigned')->default(0);
            $table->integer('tasks_completed')->default(0);
            $table->integer('tasks_on_time')->default(0);
            $table->integer('tasks_late')->default(0);
            $table->integer('total_updates')->default(0);
            $table->decimal('avg_response_minutes', 10, 2)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'score_date']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'score_date']);
        });
    }

    public function down(): void { Schema::dropIfExists('apix_scores'); }
};
