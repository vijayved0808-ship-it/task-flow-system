<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('manager_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->foreign('manager_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->uuid('team_id');
            $table->uuid('user_id');
            $table->timestamps();
            $table->primary(['team_id', 'user_id']);
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
