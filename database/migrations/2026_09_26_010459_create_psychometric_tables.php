<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('psychometric_tests')) {
            Schema::create('psychometric_tests', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('corrected_page_path')->nullable();
                $table->json('sections');
                $table->json('answer_key')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
            });
        } elseif (! Schema::hasColumns('psychometric_tests', ['id', 'title', 'corrected_page_path', 'sections', 'answer_key', 'published_at', 'created_at', 'updated_at'])) {
            throw new RuntimeException('Existing psychometric_tests schema is incomplete; inspect it before resuming migration.');
        }
        Schema::create('psychometric_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('psychometric_test_id')->constrained()->restrictOnDelete();
            $table->foreignId('recruitment_application_id')->constrained()->cascadeOnDelete();
            $table->dateTime('opens_at');
            $table->dateTime('deadline')->index();
            $table->unsignedTinyInteger('section_index')->default(0);
            $table->timestamp('section_started_at')->nullable();
            $table->timestamp('section_expires_at')->nullable();
            $table->json('answers')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('raw_score')->nullable();
            $table->json('section_scores')->nullable();
            $table->timestamps();
            $table->unique(['psychometric_test_id', 'recruitment_application_id'], 'psychometric_assignment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psychometric_attempts');
        Schema::dropIfExists('psychometric_tests');
    }
};
