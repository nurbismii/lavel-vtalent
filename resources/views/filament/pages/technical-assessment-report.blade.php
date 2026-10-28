<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Hasil tes teknis - {{ $application->user->name }} - Lamaran {{ $application->id }}</title>
    <style>
        @page {
            size: A4; margin: 16mm;
            @bottom-left { content: "Laporan hasil tes teknis"; font: 8pt Arial, sans-serif; color: #59697c; }
            @bottom-right { content: "Halaman " counter(page) " / " counter(pages); font: 8pt Arial, sans-serif; color: #59697c; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: #edf0f5; color: #182536; font: 10.5pt/1.55 Arial, sans-serif; }
        .toolbar { max-width: 210mm; margin: 24px auto; display: flex; align-items: center; flex-wrap: wrap; gap: 12px; padding: 0 20px; }
        .toolbar p { flex: 1; min-width: 220px; margin: 0; font-size: 10pt; }
        .toolbar button, .toolbar a { border: 1px solid #415e9e; border-radius: 6px; padding: 10px 16px; color: #415e9e; background: white; font: inherit; cursor: pointer; text-decoration: none; }
        .toolbar button { background: #415e9e; color: white; }
        main { max-width: 210mm; padding: 16mm; margin: 0 auto 32px; background: white; }
        h1, h2, h3, h4 { line-height: 1.3; break-after: avoid; }
        h1 { font-size: 24pt; margin: 5px 0 18px; }
        h2 { font-size: 17pt; color: #415e9e; margin: 0 0 8px; }
        h3 { font-size: 13pt; margin: 24px 0 12px; }
        h4 { font-size: 11pt; margin: 0 0 6px; break-inside: avoid; }
        p { margin: 6px 0; orphans: 3; widows: 3; }
        .eyebrow, .muted { color: #59697c; }
        .eyebrow { font-size: 9pt; letter-spacing: 1px; text-transform: uppercase; }
        .candidate { border-bottom: 2px solid #415e9e; padding-bottom: 20px; margin-bottom: 24px; }
        dl { margin: 0; }
        .identity { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 24px; }
        dt { font-size: 9pt; color: #59697c; }
        dd { margin: 2px 0 0; overflow-wrap: anywhere; }
        .attempt + .attempt { border-top: 2px solid #415e9e; margin-top: 36px; padding-top: 24px; break-before: page; }
        .question { margin: 0 0 16px; }
        .question-label { font-size: 9pt; color: #59697c; margin-bottom: 4px; break-after: avoid; }
        .answer { border-left: 3px solid #415e9e; background: #f4f6fa; margin-top: 8px; padding: 10px 12px; break-inside: avoid; }
        .answer-label { font-size: 9pt; font-weight: bold; color: #415e9e; break-after: avoid; }
        .text, .assessment-material-text { white-space: pre-wrap; overflow-wrap: anywhere; }
        .points { font-size: 9pt; color: #59697c; margin-top: 6px; break-before: avoid; }
        .material { margin: 12px 0 18px; }
        .assessment-documents { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .assessment-document { min-width: 0; }
        .assessment-document h3 { margin: 0 0 8px; font-size: 10pt; }
        .assessment-control-rules { padding-left: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 9pt; }
        th, td { padding: 7px 9px; border: 1px solid #dce2ea; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { background: #f0f3f8; }
        tr { break-inside: avoid; }
        thead { display: table-header-group; }
        .summary { margin-top: 24px; break-inside: avoid; }
        .summary th:first-child { width: 58%; }
        .review { margin-top: 16px; }
        @media (max-width: 640px) {
            main { padding: 24px 20px; }
            .identity, .assessment-documents { grid-template-columns: 1fr; }
        }
        @media print {
            body { background: white; font-size: 10pt; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .toolbar { display: none; }
            main { max-width: none; padding: 0; margin: 0; }
            .identity, .assessment-documents { grid-template-columns: 1fr 1fr; }
            .attempt + .attempt { border-top: 0; margin-top: 0; padding-top: 0; }
            .answer { background: #f4f6fa; }
        }
    </style>
</head>
<body>
    <nav class="toolbar" aria-label="Aksi laporan">
        <a href="{{ route('filament.admin.pages.technical-assessments', ['candidate' => $application->user->email]) }}">Kembali ke hasil tes</a>
        <p>Pilih <strong>Simpan sebagai PDF</strong> pada dialog cetak untuk mengunduh laporan.</p>
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    </nav>
    <main>
        <header class="candidate">
            <div class="eyebrow">VDNI - Talent Portal</div>
            <h1>Laporan hasil tes teknis</h1>
            <dl class="identity">
                <div><dt>Nama kandidat</dt><dd>{{ $application->user->name }}</dd></div>
                <div><dt>Email</dt><dd>{{ $application->user->email }}</dd></div>
                <div><dt>Posisi</dt><dd>{{ $application->position->name }}</dd></div>
                <div><dt>Periode rekrutmen</dt><dd>{{ $application->period->name }}</dd></div>
                <div><dt>ID lamaran</dt><dd>{{ $application->id }}</dd></div>
                <div><dt>Laporan dibuka ({{ $timezone }})</dt><dd>{{ now()->timezone($timezone)->format('d/m/Y H:i') }}</dd></div>
            </dl>
        </header>

        @foreach($attempts as $attempt)
            <article class="attempt" aria-label="Hasil Form {{ $attempt->form }} versi {{ $attempt->version }}">
                <h2>Form {{ $attempt->form }}</h2>
                <p><strong>{{ $attempt->package['title'] }}</strong></p>
                <p class="muted">Sesi #{{ $attempt->id }} - Versi {{ $attempt->version }} - Selesai {{ $attempt->completed_at->timezone($timezone)->format('d/m/Y H:i') }} ({{ $timezone }})</p>

                @foreach($attempt->sectionsForDisplay() as $index => $section)
                    @if(count($section['questions']))
                        <section>
                            <h3>{{ $section['title'] }}</h3>
                            @if(!empty($section['material']))<div class="material">@include('candidate.technical-assessment-material', ['material' => $section['material']])</div>@endif
                            @foreach($section['questions'] as $question)
                                @php
                                    $answer = $attempt->answers[$index][$question['id']] ?? null;
                                    $hasAnswer = $answer !== null && $answer !== '';
                                    $weights = $attempt->package['scoring'][$question['id']] ?? [];
                                @endphp
                                @if(!empty($question['material']))<div class="material">@include('candidate.technical-assessment-material', ['material' => $question['material']])</div>@endif
                                @if($question['id'] === 'revised_decision' && !empty($section['followup_material']))<h4>Fakta baru</h4><div class="material">@include('candidate.technical-assessment-material', ['material' => $section['followup_material']])</div>@endif
                                <article class="question">
                                    <div class="question-label">{{ $question['id'] }}@if(($question['label'] ?? $question['id']) !== $question['id']) - {{ $question['label'] }}@endif</div>
                                    <h4 class="text">{{ $question['prompt'] }}</h4>
                                    <div class="answer">
                                        <div class="answer-label">Jawaban kandidat</div>
                                        <p class="text">{{ $hasAnswer ? $answer : 'Belum dijawab' }}@if($hasAnswer && isset($question['options'][$answer])) - {{ $question['options'][$answer] }}@endif</p>
                                    </div>
                                    @if($weights)<p class="points">Poin: {{ $attempt->scores['items'][$question['id']] ?? 'Belum tersedia' }} / {{ max($weights) }}</p>@endif
                                </article>
                            @endforeach
                        </section>
                    @endif
                @endforeach

                <section class="summary">
                    <h3>Ringkasan poin Form {{ $attempt->form }}</h3>
                    <table>
                        <thead><tr><th scope="col">Komponen</th><th scope="col">Hasil</th></tr></thead>
                        <tbody>
                            @forelse(collect($attempt->scores ?? [])->except('items') as $label => $value)
                                <tr><th scope="row">{{ $label }}</th><td>{{ is_array($value) ? (implode(', ', $value) ?: 'Tidak ada') : ($value ?? 'Item belum lengkap') }}</td></tr>
                            @empty
                                <tr><td colspan="2">Ringkasan poin belum tersedia.</td></tr>
                            @endforelse
                            @foreach($attempt->review['ratings'] ?? [] as $label => $rating)<tr><th scope="row">{{ $label }} (assessor)</th><td>{{ $rating }} / 4</td></tr>@endforeach
                        </tbody>
                    </table>
                    <p class="muted">Skor mendukung review dan verifikasi. IPP pilot adalah indeks prioritas, bukan keputusan kelulusan otomatis. Kecenderungan perilaku digunakan sebagai konteks wawancara.</p>
                    <div class="review">
                        <h4>Penilaian assessor</h4>
                        <p><strong>{{ $attempt->review['status'] ?? 'Belum dinilai' }}</strong></p>
                        <p class="text">{{ $attempt->review['notes'] ?? 'Catatan assessor belum tersedia.' }}</p>
                    </div>
                </section>

                @if(count($attempt->conditionQuestions()))
                    <section>
                        <h3>Catatan kondisi kandidat</h3>
                        @foreach($attempt->conditionQuestions() as $question)
                            @php($answer = $attempt->conditionAnswers()[$question['id']] ?? null)
                            <article class="question">
                                <h4>{{ $question['prompt'] }}</h4>
                                <p class="text">{{ $answer !== null && $answer !== '' ? $answer : 'Belum diisi' }}</p>
                            </article>
                        @endforeach
                    </section>
                @endif
            </article>
        @endforeach
    </main>
</body>
</html>
