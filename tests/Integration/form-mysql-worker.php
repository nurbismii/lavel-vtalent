<?php

use App\Models\FormResponse;
use App\Models\User;
use App\Services\CandidateFormService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = getenv('PORTAL_TEST_DATABASE');
if (! preg_match('/^vtalent_ci_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Isolated MySQL database required.');
}
config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database]);
DB::purge('mysql');
[$script, $action, $responseId, $adminId, $release] = $argv;
$response = FormResponse::findOrFail($responseId);
while (microtime(true) < (float) $release) {
    usleep(10000);
}
$service = app(CandidateFormService::class);
if ($action === 'final') {
    $result = $service->save($response, 0, ['name' => 'Kandidat', 'answers' => [], 'consent' => true], true);
    echo json_encode(['reference' => $result->reference]);
} elseif ($action === 'link') {
    $result = $service->link(User::findOrFail($adminId), $response, ['portfolio_deadline' => now()->addDays(3)->toDateTimeString(), 'test_deadline' => now()->addDays(4)->toDateTimeString(), 'task_label' => 'Tes']);
    echo json_encode(['user_id' => $result->user_id, 'application_id' => $result->recruitment_application_id]);
} else {
    throw new RuntimeException('Unknown action.');
}
