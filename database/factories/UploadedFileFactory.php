<?php

namespace Database\Factories;

use App\Models\Submission;
use App\Models\UploadedFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UploadedFileFactory extends Factory
{
    protected $model = UploadedFile::class;

    public function definition(): array
    {
        $submission = Submission::factory()->create();

        return ['submission_id' => $submission->id, 'recruitment_application_id' => $submission->recruitment_application_id, 'uploader_id' => $submission->application->user_id, 'purpose' => 'portfolio_main', 'disk' => 'private', 'path' => 'quarantine/'.Str::uuid().'.pdf', 'original_name' => 'portfolio.pdf', 'mime' => 'application/pdf', 'size' => 1024, 'checksum' => str_repeat('a', 64), 'scan_status' => 'clean'];
    }
}
