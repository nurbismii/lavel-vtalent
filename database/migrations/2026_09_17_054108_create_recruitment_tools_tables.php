<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->restrictOnDelete();
            $table->foreignId('recruitment_period_id')->constrained()->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->string('path');
            $table->unique(['position_id', 'recruitment_period_id']);
            $table->timestamps();
        });
        Schema::create('access_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->text('password')->nullable();
            $table->text('message');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_deliveries');
        Schema::dropIfExists('technical_tasks');
    }
};
