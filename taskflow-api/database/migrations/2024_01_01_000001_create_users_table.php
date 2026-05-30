<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('phone', 20)->unique();
            $table->string('password');
            $table->enum('role', ['super_admin', 'admin', 'manager', 'employee'])->default('employee');
            $table->string('department', 100)->nullable();
            $table->string('designation', 100)->nullable();
            $table->string('employee_code', 50)->nullable();
            $table->boolean('whatsapp_opted_in')->default(false);
            $table->json('wa_session_state')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('users'); }
};
