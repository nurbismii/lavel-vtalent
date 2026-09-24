<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_forms', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->json('fields');
            $t->unsignedInteger('lock_version')->default(0);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });
        Schema::create('candidate_form_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('candidate_form_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->string('title');
            $t->text('description')->nullable();
            $t->json('fields');
            $t->timestamps();
            $t->unique(['candidate_form_id', 'number']);
        });
        Schema::create('form_intakes', function (Blueprint $t) {
            $t->id();
            $t->uuid('slug')->unique();
            $t->foreignId('candidate_form_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('position_id')->constrained()->restrictOnDelete();
            $t->foreignId('recruitment_period_id')->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('deadline')->nullable();
            $t->unsignedInteger('max_responses')->nullable();
            $t->timestamps();
        });
        Schema::create('form_responses', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('form_intake_id')->constrained()->restrictOnDelete();
            $t->string('email');
            $t->string('name')->default('');
            $t->timestamp('email_verified_at');
            $t->json('answers');
            $t->string('status')->default('draft')->index();
            $t->unsignedInteger('lock_version')->default(0);
            $t->timestamp('submitted_at')->nullable();
            $t->text('revision_note')->nullable();
            $t->timestamp('revision_deadline')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('recruitment_application_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamp('linked_at')->nullable();
            $t->boolean('activation_pending')->default(false);
            $t->unsignedInteger('access_generation')->default(1);
            $t->timestamps();
            $t->unique(['form_intake_id', 'email']);
        });
        Schema::create('form_response_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_response_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->string('name');
            $t->json('answers');
            $t->json('document_ids');
            $t->timestamp('submitted_at');
            $t->timestamps();
            $t->unique(['form_response_id', 'number']);
        });
        Schema::create('form_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_response_id')->constrained()->restrictOnDelete();
            $t->uuid('field_id');
            $t->string('path')->unique();
            $t->string('original_name');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('scan_status')->default('pending');
            $t->string('scan_message')->nullable();
            $t->boolean('selected')->default(true);
            $t->timestamps();
        });
        Schema::create('form_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('form_intake_id')->constrained()->restrictOnDelete();
            $t->string('email');
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at')->index();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback formulir dinonaktifkan untuk melindungi respons dan dokumen. Gunakan restore backup yang telah diuji.');
    }
};
