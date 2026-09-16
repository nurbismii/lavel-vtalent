<?php

return [
    'timezone' => env('PORTAL_TIMEZONE', 'Asia/Makassar'),
    'temporary_password_hours' => 72,
    'quota_mb' => 500,
    'temporary_hours' => 24,
    'retention_months' => 12,
    'retention_enabled' => false,
    'privacy_contact' => env('PORTAL_PRIVACY_CONTACT', ''),
    'scan_enabled' => env('UPLOAD_SCAN_ENABLED', false),
    'scanner_binary' => env('CLAMSCAN_BINARY', 'clamscan'),
    'scanner_timeout' => 120,
    'uploads' => [
        'portfolio_main' => ['extensions' => ['pdf'], 'max_mb' => 20, 'max_files' => 1],
        'portfolio_evidence' => ['extensions' => ['pdf', 'docx', 'jpg', 'jpeg', 'png'], 'max_mb' => 10, 'max_files' => 10],
        'technical_result' => ['extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx', 'pptx', 'zip'], 'max_mb' => 25, 'max_files' => 10],
    ],
];
