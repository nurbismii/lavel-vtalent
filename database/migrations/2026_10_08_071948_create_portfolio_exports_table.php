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
        Schema::create('portfolio_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('application_ids');
            $table->string('status')->default('pending');
            $table->string('path')->unique();
            $table->text('error')->nullable();
            $table->unsignedInteger('candidate_count')->default(0);
            $table->timestamp('expires_at')->index();
            $table->index(['actor_id', 'status']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_exports');
    }
};
