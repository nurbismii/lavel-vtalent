<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Laporan MBTI - {{ $attempt->application->user->name }} - {{ $attempt->result['type'] }}</title>
    @vite('resources/css/app.css')
    <style>
        @page { size: A4; margin: 16mm; }
        body { background: #f3f5f9; }
        .mbti-export-toolbar { max-width: 210mm; margin: 24px auto; padding: 0 20px; display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
        .mbti-export-toolbar p { flex: 1; min-width: 220px; margin: 0; font-size: 13px; }
        .mbti-export-page { max-width: 210mm; margin: 0 auto 32px; padding: 16mm; border-radius: 0; }
        .mbti-export-header { border-bottom: 2px solid #415e9e; padding-bottom: 24px; margin-bottom: 24px; }
        .mbti-export-header h1 { font-size: 26px; margin: 6px 0 20px; }
        .mbti-export-identity { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 24px; }
        .mbti-export-identity dt { color: #59697c; font-size: 12px; }
        .mbti-export-identity dd { margin: 3px 0 0; font-size: 14px; overflow-wrap: anywhere; }
        .mbti-export-page .mbti-report-layout { grid-template-columns: 1fr; }
        .mbti-export-page .mbti-report-toc { position: static; }
        .mbti-export-page .mbti-character { width: 140px; height: 140px; }
        @media (max-width: 640px) {
            .mbti-export-page { padding: 24px 20px; }
            .mbti-export-identity { grid-template-columns: 1fr; }
        }
        @media print {
            body { background: white; font-size: 10pt; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .mbti-export-toolbar, .mbti-report-toc { display: none; }
            .mbti-export-page { max-width: none; padding: 0; margin: 0; border: 0; box-shadow: none; }
            .mbti-export-header h1 { font-size: 20pt; }
            .mbti-export-identity { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .mbti-export-identity dt { font-size: 9pt; }
            .mbti-export-identity dd { font-size: 10pt; }
            .mbti-result-identity { flex-direction: row; align-items: center; break-inside: avoid; }
            .mbti-dimensions { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            .mbti-axis { break-inside: avoid; padding: 12px; }
            .mbti-axis .row { font-size: 9pt; }
            .mbti-report { border: 0; border-radius: 0; }
            .mbti-report > summary { display: block; padding: 16px 0; break-after: avoid; }
            .mbti-report > summary::marker { content: ''; }
            .mbti-report-content { padding: 0; overflow: visible; max-height: none; }
            .mbti-report-layout { display: block; margin-top: 0; }
            .mbti-report-section h4 { font-size: 12pt; break-after: avoid; }
            .mbti-report-section p, .mbti-report-section li { font-size: 10pt; line-height: 1.65; orphans: 3; widows: 3; }
            .mbti-report-source { font-size: 9pt; }
        }
    </style>
</head>
<body>
    <nav class="mbti-export-toolbar" aria-label="Aksi laporan">
        <a class="button" href="{{ route('filament.admin.pages.psychometrics') }}">Psikotes admin</a>
        <p>Pilih <strong>Simpan sebagai PDF</strong> pada dialog cetak untuk mengunduh laporan.</p>
        <button class="button primary" type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    </nav>
    <main class="panel mbti-export-page">
        <header class="mbti-export-header">
            <div class="eyebrow">VDNI - Talent Portal</div>
            <h1>Laporan hasil terperinci MBTI</h1>
            <dl class="mbti-export-identity">
                <div><dt>Nama kandidat</dt><dd>{{ $attempt->application->user->name }}</dd></div>
                <div><dt>Email</dt><dd>{{ $attempt->application->user->email }}</dd></div>
                <div><dt>Posisi</dt><dd>{{ $attempt->application->position->name }}</dd></div>
                <div><dt>Periode rekrutmen</dt><dd>{{ $attempt->application->period->name }}</dd></div>
                <div><dt>Paket tes</dt><dd>{{ $attempt->test->title }}</dd></div>
                <div><dt>Selesai ({{ $timezone }})</dt><dd>{{ $attempt->completed_at->copy()->timezone($timezone)->format('d/m/Y H:i') }}</dd></div>
                <div><dt>ID sesi</dt><dd>{{ $attempt->id }}</dd></div>
                <div><dt>ID lamaran</dt><dd>{{ $attempt->recruitment_application_id }}</dd></div>
            </dl>
        </header>
        <x-mbti-result :result="$attempt->result" :report-id="'mbti-report-'.$attempt->id" :candidate-name="$attempt->application->user->name" />
    </main>
</body>
</html>
