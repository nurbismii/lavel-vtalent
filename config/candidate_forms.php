<?php

return [
    'enabled' => env('CANDIDATE_FORMS_ENABLED', true),
    'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'docx'],
    'max_mb' => 10, 'max_files' => 5, 'quota_mb' => 100,
    'global_quota_mb' => env('FORM_GLOBAL_QUOTA_MB', 10240),
    'access_minutes' => 30, 'session_hours' => 12,
    'email_resend_seconds' => 60,
    'email_max_per_hour' => 10,
    'draft_retention_days' => 30, 'unlinked_retention_days' => 365, 'linked_retention_days' => 730,
    'delete_final_responses' => false,
];
