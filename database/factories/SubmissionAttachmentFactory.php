<?php

namespace Database\Factories;

use App\Models\SubmissionAttachment;
use App\Models\SubmissionVersion;
use App\Models\UploadedFile;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubmissionAttachmentFactory extends Factory
{
    protected $model = SubmissionAttachment::class;

    public function definition(): array
    {
        return ['submission_version_id' => SubmissionVersion::factory(), 'uploaded_file_id' => UploadedFile::factory(), 'purpose' => 'portfolio_main'];
    }
}
