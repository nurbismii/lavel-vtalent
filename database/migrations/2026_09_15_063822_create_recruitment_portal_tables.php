<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('role')->default('candidate')->index();
                $t->boolean('active')->default(true);
                $t->boolean('must_change_password')->default(false);
                $t->timestamp('temporary_password_expires_at')->nullable();
                $t->unsignedInteger('session_version')->default(1);
                $t->text('app_authentication_secret')->nullable();
                $t->text('app_authentication_recovery_codes')->nullable();
            });
        }
        $this->create('candidate_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('phone', 30)->nullable();
            $t->timestamps();
        });
        $this->create('positions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        $this->create('recruitment_periods', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->date('starts_at');
            $t->date('ends_at');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        $this->create('recruitment_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('position_id')->constrained()->restrictOnDelete();
            $t->foreignId('recruitment_period_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('active_user_id')->nullable()->unique();
            $t->timestamp('archived_at')->nullable()->index();
            $t->timestamp('purged_at')->nullable();
            $t->timestamps();
        });
        $this->create('submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recruitment_application_id')->constrained()->restrictOnDelete();
            $t->string('type');
            $t->string('status')->default('not_started')->index();
            $t->timestamp('deadline')->index();
            $t->string('task_label')->nullable();
            $t->text('instructions')->nullable();
            $t->text('administrative_reason')->nullable();
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->timestamps();
            $t->unique(['recruitment_application_id', 'type']);
        });
        $this->create('submission_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->string('status')->default('draft');
            $t->text('notes')->nullable();
            $t->json('links')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->string('receipt')->nullable()->unique();
            $t->timestamps();
            $t->unique(['submission_id', 'number']);
        });
        if (! collect(Schema::getForeignKeys('submissions'))->contains(fn ($key) => in_array('current_version_id', $key['columns'], true))) {
            Schema::table('submissions', fn (Blueprint $t) => $t->foreign('current_version_id')->references('id')->on('submission_versions')->restrictOnDelete());
        }
        $this->create('uploaded_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recruitment_application_id')->constrained()->restrictOnDelete();
            $t->foreignId('submission_id')->constrained()->restrictOnDelete();
            $t->foreignId('uploader_id')->constrained('users')->restrictOnDelete();
            $t->string('purpose');
            $t->string('disk')->default('private');
            $t->string('path')->unique();
            $t->string('original_name');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('checksum', 64);
            $t->string('scan_status')->default('pending')->index();
            $t->string('scan_message')->nullable();
            $t->timestamps();
        });
        $this->create('submission_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('uploaded_file_id')->constrained()->restrictOnDelete();
            $t->string('purpose');
            $t->string('description', 500)->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['submission_version_id', 'uploaded_file_id'], 'attachment_version_file_unique');
        });
        $this->create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action')->index();
            $t->string('target_type');
            $t->unsignedBigInteger('target_id');
            $t->text('reason')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index(['target_type', 'target_id']);
        });
        $this->create('app_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('value');
            $t->timestamps();
        });
        $this->create('email_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->constrained()->restrictOnDelete();
            $t->foreignId('submission_version_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('event');
            $t->string('status')->default('pending')->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });
    }

    private function create(string $name, Closure $callback): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $callback);
        }
        if ($name === 'submission_attachments' && ! Schema::hasIndex($name, 'attachment_version_file_unique')) {
            Schema::table($name, fn (Blueprint $t) => $t->unique(['submission_version_id', 'uploaded_file_id'], 'attachment_version_file_unique'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Rollback portal dinonaktifkan untuk melindungi histori. Gunakan prosedur restore backup yang telah diuji.');
    }
};
