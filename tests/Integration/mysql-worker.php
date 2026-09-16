<?php

use App\Models\Submission;
use App\Services\SubmissionService;
use App\Services\UploadService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = getenv('PORTAL_TEST_DATABASE');
if (! preg_match('/^vtalent_ci_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Isolated MySQL test database required.');
}
config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database, 'filesystems.disks.private.root' => storage_path('framework/testing/'.$database)]);
DB::purge('mysql');
Queue::fake();
[$script,$action,$submissionId,$versionId,$fileId,$release] = $argv;
$submission = Submission::findOrFail($submissionId);
$user = $submission->application->user;
while (microtime(true) < (float) $release) {
    usleep(10000);
}
try {
    if ($action === 'final') {
        $version = app(SubmissionService::class)->save($submission, $user, (int) $versionId, ['attachments' => [['id' => (int) $fileId]]], true);
        echo json_encode(['receipt' => $version->receipt]);
    } elseif ($action === 'upload') {
        $file = app(UploadService::class)->store($submission, $user, UploadedFile::fake()->create('result.pdf', 600, 'application/pdf'), 'technical_result');
        echo json_encode(['status' => 'accepted']);
    } else {
        throw new RuntimeException('Unknown test action.');
    }
} catch (ValidationException) {
    echo json_encode(['status' => 'rejected']);
}
