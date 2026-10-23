<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('submission_key')->unique();
            $table->string('subject', 200);
            $table->mediumText('body');
            $table->timestamps();
        });

        Schema::create('email_broadcast_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_broadcast_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['email_broadcast_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_broadcast_deliveries');
        Schema::dropIfExists('email_broadcasts');
    }
};
