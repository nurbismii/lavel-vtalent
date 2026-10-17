<x-layouts.portal>
    <div class="heading"><div><div class="eyebrow">Asesmen kandidat · Form {{ $attempt->form }}</div><h1>{{ $attempt->package['title'] }}</h1><small>Versi {{ $attempt->version }}</small></div><a href="{{ route('candidate.technical-assessments') }}">Daftar tes</a></div>
    <x-errors />
    @if($attempt->completed_at)
        <section class="panel"><h2>Jawaban tes telah dikunci</h2><p>Seluruh tahap selesai atau tenggat penugasan telah berakhir. HR akan meninjau jawaban dan kondisi pengerjaan Anda.</p><p class="muted">Skor dan kunci hanya tersedia bagi assessor berwenang.</p></section>
        <form class="panel stack" method="post" action="{{ route('candidate.technical-assessments.update', $attempt) }}">@csrf<input type="hidden" name="action" value="conditions">
            <h2>Catatan kondisi dan konfirmasi</h2>
            @foreach($attempt->package['condition_questions'] as $question)<label class="field">{{ $question['prompt'] }}<textarea name="conditions[{{ $question['id'] }}]" maxlength="2000" rows="3">{{ old('conditions.'.$question['id'], $attempt->conditions[$question['id']] ?? '') }}</textarea></label>@endforeach
            <label><input type="checkbox" name="confirmation" value="1" required> Saya mengonfirmasi bahwa tes dikerjakan sendiri sesuai aturan dan kondisi yang saya laporkan.</label>
            <button class="button primary">Simpan catatan dan konfirmasi</button>
        </form>
    @elseif($attempt->isWaitingForFormA())
        <section class="panel"><h2>Tes terkunci</h2><p>Selesaikan Form A terlebih dahulu sebelum memulai Form B atau Form A2.</p><a class="button primary" href="{{ route('candidate.technical-assessments') }}">Kembali ke daftar tes</a></section>
    @elseif(now()->lt($attempt->opens_at))
        <section class="panel"><h2>Jadwal belum dibuka</h2><p>Tes dibuka pada {{ $attempt->opens_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}.</p></section>
    @elseif(!$attempt->consented_at)
        <section class="panel stack"><h2>Sebelum memulai</h2><p>Tes ini menilai sampel penalaran, ketelitian dokumen, dan judgment kerja. Hasil menjadi salah satu bahan seleksi dan akan ditinjau HR.</p>
            <p>Kerjakan sendiri tanpa internet, AI, bank soal, atau bantuan orang lain. Kalkulator sederhana dan kertas kosong diperbolehkan pada soal numerik. Jangan menyalin, memotret, atau membagikan soal.</p>
            <p>Jawaban tersimpan otomatis. Timer mengikuti waktu server dan tetap berjalan saat koneksi terputus atau halaman ditutup. Tahap yang sudah ditutup tidak bisa dikerjakan kembali. Gunakan satu tab tes.</p>
            <p>Perpindahan tab, kehilangan fokus, keluar layar penuh, dan waktu simpan dicatat untuk klarifikasi HR. Catatan aktivitas tidak otomatis membuktikan kecurangan. Kebutuhan akomodasi dan gangguan dapat dilaporkan kepada HR melalui {{ App\Models\AppSetting::valueFor('privacy_contact') ?: 'kontak rekrutmen yang memberikan akun Anda' }}.</p>
            <a href="{{ route('privacy') }}" target="_blank" rel="noopener">Baca privasi dan retensi data</a>
            <form method="post" action="{{ route('candidate.technical-assessments.update', $attempt) }}">@csrf<input type="hidden" name="action" value="consent"><label><input type="checkbox" name="consent" value="1" required> Saya memahami aturan, pencatatan aktivitas, dan menyatakan siap mengikuti tes sesuai kondisi yang disepakati.</label><p><button class="button primary">Konfirmasi kesiapan</button></p></form>
        </section>
    @elseif(!$attempt->section_started_at)
        <section class="panel stack" @if($attempt->form === 'B') data-assessment-wait data-state-url="{{ route('candidate.technical-assessments.show', $attempt) }}" data-section="{{ $attempt->section_index }}" @endif>
            <span class="badge">Tahap {{ $attempt->section_index + 1 }} / {{ count($attempt->package['sections']) }}</span><h2>{{ $section['title'] }}</h2><p>Alokasi waktu {{ $section['seconds'] / 60 }} menit.</p>
            <p class="muted">Waktu tahap dibatasi tenggat penugasan: {{ $attempt->deadline->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}.</p>
            @if($attempt->form === 'B')<p>Mulai tahap ini saat Anda siap. Setiap tahap dapat dimulai sendiri tanpa menunggu assessor membuka sesi.</p>@endif
            <form data-assessment-start method="post" action="{{ route('candidate.technical-assessments.update', $attempt) }}">@csrf<input type="hidden" name="action" value="start"><input type="hidden" name="section" value="{{ $attempt->section_index }}"><input type="hidden" name="revision" value="{{ $attempt->revision }}"><button class="button primary">Mulai {{ $section['title'] }}</button></form>
            <noscript><p class="field-error">Aktifkan JavaScript untuk timer dan autosave.</p></noscript>
        </section>
    @else
        <form data-assessment-form data-endpoint="{{ route('candidate.technical-assessments.update', $attempt) }}" data-state-url="{{ route('candidate.technical-assessments.show', $attempt) }}" data-activity-url="{{ route('candidate.technical-assessments.activity', $attempt) }}" data-section="{{ $attempt->section_index }}" data-revision="{{ $attempt->revision }}" data-remaining="{{ max(0, now()->diffInSeconds($attempt->section_expires_at, false)) }}" class="stack" method="post" action="{{ route('candidate.technical-assessments.update', $attempt) }}">
            @csrf<input type="hidden" name="section" value="{{ $attempt->section_index }}"><input type="hidden" name="revision" value="{{ $attempt->revision }}">
            <div class="panel assessment-toolbar"><div><span class="badge">Tahap {{ $attempt->section_index + 1 }} / {{ count($attempt->package['sections']) }}</span><h2>{{ $section['title'] }}</h2><span data-assessment-progress></span></div><div><strong class="assessment-timer" data-assessment-timer role="timer">Menghitung waktu…</strong><p data-assessment-status role="status" aria-live="polite">Jawaban tersimpan</p><button class="button small" type="button" data-assessment-fullscreen>Layar penuh</button></div></div>
            <p class="notice" data-assessment-activity-status role="status">Aktivitas keluar halaman dicatat. Timer tetap berjalan; hubungi HR bila terjadi gangguan.</p>
            @if($attempt->form === 'B' && $attempt->section_index === 3)
                <details class="panel"><summary>Keputusan awal Anda yang sudah dikunci</summary>@foreach($attempt->answers[2] ?? [] as $answer)<p class="assessment-material-text">{{ $answer }}</p>@endforeach</details>
            @endif
            @if($section['material'])<section class="panel assessment-material">@include('candidate.technical-assessment-material', ['material' => $section['material']])</section>@endif
            @foreach($section['questions'] as $question)
                <fieldset class="panel assessment-question"><legend>{{ $question['label'] ?? $question['id'] }}</legend><p>{{ $question['prompt'] }}</p>
                    @if($question['type'] === 'text')
                        <label class="field"><span class="sr-only">Jawaban {{ $question['label'] ?? $question['id'] }}</span><textarea name="answers[{{ $question['id'] }}]" rows="5" maxlength="5000">{{ $attempt->answers[$attempt->section_index][$question['id']] ?? '' }}</textarea></label>
                    @else
                        @foreach($question['options'] as $value => $label)<label class="assessment-option"><input type="radio" name="answers[{{ $question['id'] }}]" value="{{ $value }}" @checked((string) ($attempt->answers[$attempt->section_index][$question['id']] ?? '') === (string) $value)><span><strong>{{ $value }}.</strong> {{ $label }}</span></label>@endforeach
                    @endif
                </fieldset>
            @endforeach
            <div class="panel row"><button class="button" name="action" value="save">Simpan sekarang</button><button class="button primary" name="action" value="finish">Selesaikan dan kunci tahap</button></div>
            <noscript><p class="field-error">JavaScript diperlukan untuk timer dan autosave. Waktu server tetap berjalan.</p></noscript>
        </form>
    @endif
</x-layouts.portal>
