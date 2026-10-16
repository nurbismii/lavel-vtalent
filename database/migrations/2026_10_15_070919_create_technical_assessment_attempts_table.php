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
        Schema::create('technical_assessment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_application_id')->constrained()->cascadeOnDelete();
            $table->string('form', 2);
            $table->string('version');
            $table->json('package');
            $table->dateTime('opens_at');
            $table->dateTime('deadline')->index();
            $table->unsignedTinyInteger('section_index')->default(0);
            $table->timestamp('section_started_at')->nullable();
            $table->timestamp('section_expires_at')->nullable()->index();
            $table->timestamp('consented_at')->nullable();
            $table->json('answers')->nullable();
            $table->json('conditions')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->json('scores')->nullable();
            $table->json('review')->nullable();
            $table->timestamps();
            $table->unique(['recruitment_application_id', 'form', 'version'], 'technical_assessment_assignment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technical_assessment_attempts');
    }
};
