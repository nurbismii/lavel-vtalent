<x-layouts.portal>
    @php($isQuestionnaire = ($section['kind'] ?? null) === 'questionnaire')
    @php($isReasoning = $attempt->isReasoningSection())
    @php($reasoningLocked = $isReasoning && $attempt->reasoning_answers_locked_at !== null)
    @php($isSimulation = $attempt->isSimulationSection())
    @php($simulationLocked = $isSimulation && $attempt->simulation_answers_locked_at !== null)
    <div class="heading">
        <div>
            <div class="eyebrow">{{ $isQuestionnaire ? 'Catatan kondisi dan konfirmasi' : 'Asesmen kandidat · Form '.$attempt->form }}</div>
            <h1>{{ $isQuestionnaire ? 'Catatan kondisi dan konfirmasi' : $attempt->package['title'] }}</h1><small>{{ array_sum(array_column($attempt->sectionsForDisplay(), 'seconds')) / 60 }} menit - {{ count($attempt->sectionsForDisplay()) }} tahap</small>
        </div><a href="{{ route('candidate.technical-assessments') }}">Daftar tes</a>
    </div>
    <x-errors />
    @if($attempt->completed_at)
    <section class="panel">
        <h2>Jawaban tes telah dikunci</h2>
        @if($attempt->form === 'A')
        <p>Form A telah dikunci. Lanjutkan ke Form B sesuai penugasan HR. Catatan kondisi diisi setelah seluruh soal Form A dan Form B selesai.</p>
        @else
        <p>Seluruh tahap selesai atau tenggat penugasan telah berakhir. HR akan meninjau jawaban dan kondisi pengerjaan Anda.</p>
        @endif
        <p class="muted">Skor hanya dapat dilihat oleh assessor.</p>
    </section>
    @if($attempt->form !== 'A' && !empty($attempt->conditionQuestions()))
    <form class="panel stack assessment-condition-notes" method="post" action="{{ route('candidate.technical-assessments.update', $attempt) }}">@csrf<input type="hidden" name="action" value="conditions">
        <h2>Catatan kondisi dan konfirmasi</h2>
        @foreach(collect($attempt->conditionQuestions())->where('id', '!=', 'accommodations') as $question)<label class="field">{{ $question['prompt'] }}<textarea name="conditions[{{ $question['id'] }}]" maxlength="2000" rows="3">{{ old('conditions.'.$question['id'], $attempt->conditionAnswers()[$question['id']] ?? '') }}</textarea></label>@endforeach
        <label><input type="checkbox" name="confirmation" value="1" required> Saya mengonfirmasi bahwa tes dikerjakan sendiri sesuai aturan dan kondisi yang saya laporkan.</label>
        <div><button class="button primary">Simpan catatan dan konfirmasi</button></div>
    </form>
    @endif
    @elseif($attempt->isWaitingForFormA())
    <section class="panel">
        <h2>Tes terkunci</h2>
        <p>Selesaikan Form A terlebih dahulu sebelum memulai Form B atau Form A2.</p><a class="button primary" href="{{ route('candidate.technical-assessments') }}">Kembali ke daftar tes</a>
    </section>
    @elseif(now()->lt($attempt->opens_at))
    <section class="panel">
        <h2>Jadwal belum dibuka</h2>
        <p>Tes dibuka pada {{ $attempt->opens_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}.</p>
    </section>
    @elseif(!$attempt->consented_at)
    <section class="panel stack">
        <h2>Aturan dan Kebijakan</h2>
        <p class="muted">Sebelum memulai tes, baca dan pahami ketentuan berikut. Anda wajib mematuhinya selama mengikuti tes.</p>
        <ul class="assessment-policy-list">
            <li>Kerjakan tes secara mandiri tanpa menggunakan mesin pencari, AI, atau bantuan orang lain.</li>
            <li>Kalkulator sederhana dan kertas kosong hanya diperbolehkan untuk mengerjakan soal numerik.</li>
            <li>Dilarang menyalin, memotret, atau membagikan soal tes.</li>
            <li>Gunakan satu tab tes selama pengerjaan.</li>
            <li>Jawaban tersimpan otomatis, timer tetap berjalan meskipun koneksi terputus atau halaman ditutup.</li>
            <li>Tahap yang sudah ditutup tidak dapat dibuka atau dikerjakan kembali.</li>
            <li>Perpindahan tab, kehilangan fokus, keluar dari layar penuh, dan waktu penyimpanan jawaban dicatat untuk klarifikasi HR.</li>
            <li>Catatan aktivitas akan ditinjau oleh HR dan tidak otomatis menjadi bukti kecurangan.</li>
            <li>Laporkan gangguan selama tes kepada HR melalui {{ App\Models\AppSetting::valueFor('privacy_contact') ?: 'kontak rekrutmen yang memberikan akun Anda' }}.</li>
        </ul>
        <a href="{{ route('privacy') }}" target="_blank" rel="noopener">Baca privasi dan retensi data</a>
        <form method="post" action="{{ route('candidate.technical-assessments.update', $attempt) }}">@csrf<input type="hidden" name="action" value="consent"><label class="assessment-policy-consent"><input type="checkbox" name="consent" value="1" required><span>Saya telah membaca, memahami, dan menyetujui seluruh aturan dan kebijakan di atas, termasuk pencatatan aktivitas, serta bersedia mematuhinya selama mengikuti tes.</span></label>
            <p><button class="button primary">Konfirmasi kesiapan</button></p>
        </form>
    </section>
    @elseif(!$attempt->section_started_at)
    <section class="panel stack" @if($attempt->form === 'B') data-assessment-wait data-state-url="{{ route('candidate.technical-assessments.show', $attempt) }}" data-section="{{ $attempt->section_index }}" @endif>
        @unless($isQuestionnaire)<span class="badge">Tahap {{ $attempt->section_index + 1 }} / {{ count($attempt->sectionsForDisplay()) }}</span>@endunless
        <h2>{{ $attempt->sectionTitle($section) }}</h2>
        <p>Alokasi waktu {{ $section['seconds'] / 60 }} menit.</p>
        <p class="muted">Waktu tahap dibatasi tenggat penugasan: {{ $attempt->deadline->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}.</p>
        @if($isQuestionnaire)<p>Seluruh soal Form A dan Form B telah dikunci. Isi tiga pertanyaan berikut untuk melaporkan kondisi pengerjaan. Waktu pengisian catatan dimulai saat Anda menekan tombol mulai.</p>
        @elseif($attempt->form === 'B')<p>Mulai tahap ini saat Anda siap.</p>@endif
        <form data-assessment-start method="post" action="{{ route('candidate.technical-assessments.update', $attempt) }}">@csrf<input type="hidden" name="action" value="start"><input type="hidden" name="section" value="{{ $attempt->section_index }}"><input type="hidden" name="revision" value="{{ $attempt->revision }}"><button class="button primary">Mulai {{ $attempt->sectionTitle($section) }}</button></form>
        <noscript>
            <p class="field-error">Aktifkan JavaScript untuk timer dan autosave.</p>
        </noscript>
    </section>
    @else
    <form data-assessment-form data-endpoint="{{ route('candidate.technical-assessments.update', $attempt) }}" data-state-url="{{ route('candidate.technical-assessments.show', $attempt) }}" data-activity-url="{{ route('candidate.technical-assessments.activity', $attempt) }}" data-section="{{ $attempt->section_index }}" data-revision="{{ $attempt->revision }}" data-simulation="{{ $isSimulation ? 'true' : 'false' }}" data-simulation-locked="{{ $simulationLocked ? 'true' : 'false' }}" data-two-minute-warning="{{ $attempt->form === 'A' && in_array('IPIP01', array_column($section['questions'], 'id'), true) ? 'true' : 'false' }}" data-reasoning-locked="{{ $reasoningLocked ? 'true' : 'false' }}" data-remaining="{{ max(0, now()->diffInSeconds($attempt->section_expires_at, false)) }}" class="stack" method="post" action="{{ route('candidate.technical-assessments.update', $attempt) }}">
        @csrf<input type="hidden" name="section" value="{{ $attempt->section_index }}"><input type="hidden" name="revision" value="{{ $attempt->revision }}">
        <div class="panel assessment-toolbar">
            <div>@unless($isQuestionnaire)<span class="badge">Tahap {{ $attempt->section_index + 1 }} / {{ count($attempt->sectionsForDisplay()) }}</span>@endunless
                <h2>{{ $attempt->sectionTitle($section) }}</h2><span data-assessment-progress></span>
            </div>
            <div><strong class="assessment-timer" data-assessment-timer role="timer">Menghitung waktu…</strong>
                <p data-assessment-status role="status" aria-live="polite">Jawaban tersimpan</p><button class="button small" type="button" data-assessment-fullscreen>Layar penuh</button>
            </div>
        </div>
        <p class="notice" data-assessment-activity-status role="status">Aktivitas keluar halaman dicatat. Timer tetap berjalan; hubungi HR bila terjadi gangguan.</p>
        @if($isReasoning)
        @if($reasoningLocked)
        <p class="notice">Jawaban B01–B04 sudah dikunci. Isi penjelasan proses menggunakan sisa waktu tahap penalaran.</p>
        <details class="panel">
            <summary>Lihat jawaban B01–B04 yang sudah dikunci</summary>
            @foreach($section['questions'] as $question)
            @if($question['id'] !== 'process')
            @php($answer = $attempt->answers[$attempt->section_index][$question['id']] ?? '')
            <div class="assessment-material-text"><strong>{{ $question['label'] ?? $question['id'] }}</strong><p>{{ $question['prompt'] }}</p><p>Jawaban: {{ $answer }}. {{ $question['options'][$answer] ?? 'Tidak diisi' }}</p></div>
            @endif
            @endforeach
        </details>
        @else
        <p class="notice">Jawab keempat soal, lalu tekan Next untuk mengunci B01–B04 dan melanjutkan ke penjelasan proses. Timer tetap berjalan pada tahap ini.</p>
        @endif
        @endif
        @if($attempt->form === 'B' && !$isSimulation && in_array('revised_decision', array_column($section['questions'], 'id'), true))
        <details class="panel">
            <summary>Keputusan awal Anda yang sudah dikunci</summary>@foreach($attempt->answers[$attempt->section_index - 1] ?? [] as $answer)<p class="assessment-material-text">{{ $answer }}</p>@endforeach
        </details>
        @endif
        @if($section['material'])<section class="panel assessment-material">@include('candidate.technical-assessment-material', ['material' => $section['material']])</section>@endif
        @if($isSimulation)
        <p class="notice">{{ $simulationLocked ? 'Jawaban prioritas sudah dikunci. Tanggapi fakta baru di bawah menggunakan sisa waktu tahap ini.' : 'Isi empat kolom pada setiap prioritas, lalu tekan Lanjut. Jawaban awal akan dikunci dan fakta baru dibuka dalam tahap yang sama.' }}</p>
        @foreach(collect($section['questions'])->filter(fn (array $question): bool => isset($question['group']))->groupBy('group') as $group => $questions)
        <fieldset class="panel assessment-question assessment-priority">
            <legend>{{ $group }}@if($simulationLocked) <span class="badge">Dikunci</span>@endif</legend>
            @foreach($questions as $question)
            <label class="field assessment-priority-field"><span>{{ $question['prompt'] }}</span><textarea rows="2" maxlength="5000" @if($simulationLocked) readonly @else name="answers[{{ $question['id'] }}]" required @endif>{{ $attempt->answers[$attempt->section_index][$question['id']] ?? '' }}</textarea></label>
            @endforeach
        </fieldset>
        @endforeach
        @if($simulationLocked)
        <section id="simulation-facts" class="panel assessment-material" tabindex="-1"><h2>Fakta baru</h2>@include('candidate.technical-assessment-material', ['material' => $section['followup_material']])</section>
        @endif
        @endif
        @foreach($section['questions'] as $question)
        @continue($isSimulation && (isset($question['group']) || !$simulationLocked))
        @continue($isReasoning && (($question['id'] === 'process') !== $reasoningLocked))
        @if(!empty($question['material']))<section class="panel assessment-material">@include('candidate.technical-assessment-material', ['material' => $question['material']])</section>@endif
        <fieldset class="panel assessment-question">
            <legend>{{ $question['label'] ?? $question['id'] }}</legend>
            <p>{{ $question['prompt'] }}</p>
            @if($question['type'] === 'text')
            <label class="field"><span class="sr-only">Jawaban {{ $question['label'] ?? $question['id'] }}</span><textarea name="answers[{{ $question['id'] }}]" rows="5" maxlength="5000">{{ $attempt->answers[$attempt->section_index][$question['id']] ?? '' }}</textarea></label>
            @else
            @foreach($question['options'] as $value => $label)<label class="assessment-option"><input type="radio" name="answers[{{ $question['id'] }}]" value="{{ $value }}" @required($isReasoning && !$reasoningLocked) @checked((string) ($attempt->answers[$attempt->section_index][$question['id']] ?? '') === (string) $value)><span><strong>{{ $value }}.</strong> {{ $label }}</span></label>@endforeach
            @endif
        </fieldset>
        @endforeach
        <div class="panel row"><button class="button" name="action" value="save" formnovalidate>Simpan sekarang</button>
            @if($isSimulation && !$simulationLocked)<button class="button primary" name="action" value="next">Lanjut</button>
            @elseif($isReasoning && !$reasoningLocked)<button class="button primary" name="action" value="next">Next</button>
            @else<button class="button primary" name="action" value="finish">Selesaikan dan kunci tahap</button>@endif
        </div>
        <noscript>
            <p class="field-error">JavaScript diperlukan untuk timer dan autosave. Waktu server tetap berjalan.</p>
        </noscript>
    </form>
    @endif
</x-layouts.portal>