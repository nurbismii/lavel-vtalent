<x-layouts.portal>
    <div class="heading"><div><div class="eyebrow">Psikotes kandidat</div><h1>{{ $attempt->test->title }}</h1></div><a href="{{ route('candidate.psychometrics') }}">Daftar tes</a></div>
    <x-errors />
    @if($attempt->completed_at)
        <section class="panel"><h2>Jawaban telah dikunci</h2><p>Tes telah selesai atau tenggat pengerjaan telah berakhir. Jawaban yang tersimpan akan ditinjau oleh HR.</p><p class="muted">Hasil tidak ditampilkan kepada peserta.</p></section>
    @elseif(now()->lt($attempt->opens_at))
        <section class="panel"><h2>Tes belum dibuka</h2><p>Anda dapat mulai pada {{ $attempt->opens_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}.</p></section>
    @elseif(!$attempt->section_started_at)
        <section class="panel"><span class="badge">Bagian {{ $attempt->section_index + 1 }} / {{ count($attempt->test->sections) }}</span><h2>{{ $section['title'] }}</h2>
            <p>{{ $section['count'] }} soal · {{ $section['seconds'] }} detik · Pilih {{ $section['choices'] }} jawaban per soal.</p>
            <div class="notice">Pelajari contoh di bawah. Timer dimulai saat Anda menekan tombol mulai dan tetap berjalan jika halaman ditutup. Bagian yang sudah selesai tidak dapat dibuka kembali. Tenggat penugasan tetap berlaku.</div>
            <img class="psych-page" src="{{ route('candidate.psychometrics.image', [$attempt, $section['example']]) }}" alt="Contoh {{ $section['title'] }}">
            <p class="notice">Pada browser HP, tes dapat dikerjakan tanpa layar penuh. Pada desktop, layar penuh wajib diaktifkan; jika keluar, masuk kembali untuk melanjutkan. Timer tetap berjalan.</p>
            <noscript><p class="notice">Aktifkan JavaScript untuk memulai tes.</p></noscript>
            <form data-psych-start data-start-label="Mulai bagian {{ $attempt->section_index + 1 }}" method="post" action="{{ route('candidate.psychometrics.update', $attempt) }}">@csrf<input type="hidden" name="action" value="start"><input type="hidden" name="section" value="{{ $attempt->section_index }}"><button class="button primary" disabled>Mulai bagian {{ $attempt->section_index + 1 }}</button></form>
        </section>
    @else
        <section class="panel" data-psych-fullscreen-gate>
            <h2>Masuk mode layar penuh untuk melanjutkan</h2>
            <p>Timer tes tetap berjalan. Aktifkan layar penuh untuk melihat soal dan melanjutkan pengerjaan.</p>
            <button class="button primary" type="button">Masuk layar penuh</button>
            <noscript><p class="notice">Aktifkan JavaScript untuk melanjutkan tes.</p></noscript>
        </section>
        <form hidden inert data-psychometric-form data-endpoint="{{ route('candidate.psychometrics.update', $attempt) }}" data-remaining="{{ max(0, now()->diffInSeconds($attempt->section_expires_at, false)) }}" data-section="{{ $attempt->section_index }}" data-revision="{{ $attempt->revision }}" data-choices="{{ $section['choices'] }}" method="post" action="{{ route('candidate.psychometrics.update', $attempt) }}">
            @csrf<input type="hidden" name="section" value="{{ $attempt->section_index }}"><input type="hidden" name="revision" value="{{ $attempt->revision }}">
            <div class="panel psych-toolbar"><div><strong>{{ $section['title'] }}</strong><p>Pilih {{ $section['choices'] }} jawaban per soal.</p></div><div><strong data-psych-timer role="timer">Menghitung waktu…</strong><p data-psych-status role="status" aria-live="polite">Jawaban tersimpan</p></div></div>
            <noscript><div class="notice">JavaScript diperlukan untuk timer dan simpan otomatis. Aktifkan JavaScript sebelum melanjutkan; waktu server tetap berjalan.</div></noscript>
            <div class="psych-question-list">
                <nav class="psych-question-navigation" aria-label="Nomor soal">@for($number = 1; $number <= $section['count']; $number++)<a href="#question-{{ $number }}" aria-label="Ke soal {{ $number }}">{{ $number }}</a>@endfor</nav>
                @for($number = 1; $number <= $section['count']; $number++)
                    @php
                        $sourceNumber = $section['source_numbers'][$number - 1] ?? $number;
                        $question = config('psychometric_questions.sections')[$attempt->section_index][$sourceNumber] ?? null;
                        $customPage = $question && $question['page'] === 11 && $attempt->test->corrected_page_path;
                        $imageSource = $question ? route('candidate.psychometrics.image', [$attempt, $question['page']]) : null;
                    @endphp
                    <fieldset class="panel psych-question-card" id="question-{{ $number }}">
                        <legend>Soal {{ $number }} <span>/ {{ $section['count'] }}</span></legend>
                        <p class="psych-question-hint">{{ $section['choices'] === 1 ? 'Pilih satu jawaban.' : 'Pilih dua jawaban.' }}@if($sourceNumber !== $number) <small>(nomor {{ $sourceNumber }} pada gambar)</small>@endif</p>
                        @if(isset($section['images'][$number]['stem']))
                            <img class="psych-custom-stem" src="{{ route('candidate.psychometrics.question-image', [$attempt, $attempt->section_index, $number, 'stem']) }}" alt="Gambar soal {{ $number }}">
                        @elseif($question && !$customPage)
                            @if($question['stem'])
                                <div class="psych-question-stem" style="max-width: {{ $question['stem_width'] }}px"><x-psychometric-image :image-width="$question['image_width'] ?? 1191" :image-height="$question['image_height'] ?? 1684" :source="$imageSource" :region="$question['stem']" :label="'Gambar soal '.$number" /></div>
                            @endif
                        @elseif($imageSource)
                            <img class="psych-page" src="{{ $imageSource }}" alt="Halaman pengganti, lihat soal nomor {{ $sourceNumber }}">
                        @else
                            <p class="notice">Gambar soal ini belum tersedia. Hubungi HR.</p>
                        @endif
                        <div class="psych-picture-options">
                            @foreach(array_slice(range('A', 'F'), 0, $section['options']) as $option)
                                <label class="psych-picture-option">
                                    @if(isset($section['images'][$number][$option]))
                                        <img src="{{ route('candidate.psychometrics.question-image', [$attempt, $attempt->section_index, $number, $option]) }}" alt="Gambar pilihan {{ $option }} untuk soal {{ $number }}">
                                    @elseif($question && !$customPage)
                                        <x-psychometric-image :image-width="$question['image_width'] ?? 1191" :image-height="$question['image_height'] ?? 1684" :source="$imageSource" :region="$question['options'][$loop->index]" :label="'Gambar pilihan '.$option.' untuk soal '.$number" />
                                    @endif
                                    <span><input type="{{ $section['choices'] === 1 ? 'radio' : 'checkbox' }}" name="answers[{{ $number }}][]" value="{{ $option }}" aria-label="Soal {{ $number }}, pilihan {{ $option }}" @checked(in_array($option, $attempt->answers[$attempt->section_index][$number] ?? [], true))> {{ $option }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endfor
            </div>
            <section class="panel psych-question-footer">
                <div class="form-actions"><button class="button" type="submit" name="action" value="save">Simpan sekarang</button><button class="button primary" type="button" data-psych-review>Selesaikan bagian</button></div>
                <div data-psych-confirm hidden class="notice"><p>Bagian ini akan dikunci. Pastikan jawaban sudah diperiksa.</p><button class="button primary" type="submit" name="action" value="finish">Ya, kunci dan lanjutkan</button><button class="button" type="button" data-psych-cancel>Kembali periksa</button></div>
            </section>
        </form>
    @endif
</x-layouts.portal>
