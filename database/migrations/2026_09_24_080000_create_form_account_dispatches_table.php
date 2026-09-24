<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_account_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_response_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('access_delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->json('application_data');
            $table->string('status')->default('pending')->index();
            $table->text('error')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_account_dispatches');
    }
};
