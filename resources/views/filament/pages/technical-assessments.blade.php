<x-filament-panels::page>
    @php
        $package = $packages[$form];
        $zone = App\Models\AppSetting::valueFor('timezone');
    @endphp
    <div class="portal-admin technical-admin" x-data="{ workspace: 'assignment' }">
        <header class="hr-psych-heading">
            <div>
                <div class="eyebrow">Asesmen rekrutmen</div>
                <h1>Tes teknis online</h1>
                <p>Siapkan penugasan, pantau sesi, dan tinjau kemampuan kandidat.</p>
            </div>
            <span class="technical-heading-note"><x-heroicon-o-shield-check /> Hasil ditinjau oleh assessor</span>
        </header>

        <nav class="hr-psych-nav" aria-label="Pengelolaan asesmen teknis">
            <button type="button" x-on:click="workspace = 'assignment'" x-bind:class="{ 'is-active': workspace === 'assignment' }" x-bind:aria-pressed="workspace === 'assignment'" aria-controls="technical-assignment"><x-heroicon-o-user-plus /> Pengaturan & penugasan</button>
            <button type="button" x-on:click="workspace = 'results'" x-bind:class="{ 'is-active': workspace === 'results' }" x-bind:aria-pressed="workspace === 'results'" aria-controls="technical-results"><x-heroicon-o-chart-bar /> Hasil & sesi kandidat</button>
        </nav>

        @if($feedback)<div class="success" role="status">{{ $feedback }}</div>@endif
        @if($errors->any())<div class="errors" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

        <section id="technical-assignment" x-show="workspace === 'assignment'">
            <form wire:submit="assign" class="panel technical-assignment">
                <header class="technical-section-heading">
                    <div><h2>Penugasan kandidat</h2><p>Pilih paket, periksa durasi, lalu tentukan kandidat dan jadwal.</p></div>
                    <span class="badge">Maks. 100 kandidat</span>
                </header>

                <div class="technical-assignment-grid">
                    <section class="technical-form-section" aria-labelledby="technical-package-title">
                        <div class="technical-step-heading"><span class="technical-step">1</span><div><h3 id="technical-package-title">Paket & tahapan tes</h3><p>Gunakan paket sesuai tahap seleksi kandidat.</p></div></div>
                        <label class="field">Paket asesmen<select wire:model.live="form">@foreach($packages as $code => $definition)<option value="{{ $code }}">{{ $definition['title'] }}</option>@endforeach</select></label>
                        <div class="technical-package-summary" wire:key="summary-{{ $form }}">
                            <div class="technical-package-icon"><x-heroicon-o-clipboard-document-check /></div>
                            <div>
                                <strong>{{ match($form) { 'A' => 'Screening mandiri', 'B' => 'Verifikasi langsung', default => 'Kecenderungan perilaku' } }}</strong>
                                <p>{{ match($form) { 'A' => 'Kandidat mengerjakan penalaran, pemeriksaan dokumen, dan judgment kerja.', 'B' => 'Assessor membuka satu tahap pada satu waktu saat sesi langsung.', default => 'Modul opsional untuk konteks wawancara; bukan penentu kelulusan.' } }}</p>
                                <div class="technical-package-meta"><span><x-heroicon-o-clock /> {{ array_sum(array_column($package['sections'], 'seconds')) / 60 }} menit standar</span><span>{{ count($package['sections']) }} tahap</span></div>
                            </div>
                        </div>
                        <div class="technical-duration-heading"><h3>Durasi per tahap</h3><small>Ubah bila ada akomodasi yang disepakati.</small></div>
                        <div class="technical-duration-list">
                            @foreach($package['sections'] as $index => $stage)
                                <div class="technical-duration-row" wire:key="duration-{{ $form }}-{{ $index }}">
                                    <span class="technical-stage-number">{{ $index + 1 }}</span>
                                    <div><strong>{{ $stage['title'] }}</strong><small>Standar {{ $stage['seconds'] / 60 }} menit</small></div>
                                    <label class="field technical-duration-input"><span class="sr-only">Durasi {{ $stage['title'] }} dalam detik</span><input type="number" min="60" max="7200" wire:model="durations.{{ $index }}" required><span aria-hidden="true">detik</span></label>
                                </div>
                            @endforeach
                        </div>
                        <p class="technical-help"><x-heroicon-o-information-circle /> Timer berlaku per tahap. Tahap berikutnya menunggu tombol mulai; tenggat penugasan tetap berlaku.</p>
                    </section>

                    <section class="technical-form-section" aria-labelledby="technical-recipients-title">
                        <div class="technical-step-heading"><span class="technical-step">2</span><div><h3 id="technical-recipients-title">Kandidat & jadwal</h3><p>Satu jadwal dapat digunakan untuk beberapa kandidat.</p></div></div>
                        <div class="candidate-picker" x-data="{ open: false }" x-on:keydown.escape.stop.prevent="open = false; $refs.trigger.focus()" x-on:click.outside="open = false" x-on:focusout="if (!$el.contains($event.relatedTarget)) open = false">
                            <label class="field" id="technical-picker-label">Lamaran kandidat</label>
                            <button type="button" class="button candidate-picker-trigger" x-ref="trigger" x-on:click="open = !open; if (open) $nextTick(() => $refs.search.focus())" x-bind:aria-expanded="open" aria-controls="technical-picker-panel" aria-labelledby="technical-picker-label technical-picker-summary">
                                <span id="technical-picker-summary" x-text="$wire.applicationIds.length ? $wire.applicationIds.length + ' lamaran dipilih' : 'Pilih kandidat'">Pilih kandidat</span><span aria-hidden="true">▾</span>
                            </button>
                            <div id="technical-picker-panel" class="candidate-picker-panel" x-show="open" x-cloak>
                                <label class="field candidate-picker-search">Cari kandidat / paste email<input type="search" x-ref="search" wire:model.live.debounce.400ms="search" placeholder="Nama atau paste beberapa email" maxlength="255" x-on:keydown.enter.prevent x-on:paste="if ($event.clipboardData.getData('text').includes('@')) { $event.preventDefault(); $wire.selectApplicationsByEmail($event.clipboardData.getData('text')); }"></label>
                                <small>Paste email dari Excel; pisahkan dengan baris baru, spasi, koma, atau titik koma. Maksimal 100 kandidat.</small>
                                @error('search')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                                <small wire:loading wire:target="selectApplicationsByEmail" role="status">Mencocokkan email kandidat…</small>
                                <div class="candidate-picker-options" role="group" aria-label="Pilihan lamaran kandidat">
                                    @forelse($applications as $application)
                                        <label class="candidate-picker-option" wire:key="technical-application-{{ $application->id }}"><input type="checkbox" wire:model="applicationIds" value="{{ $application->id }}"><span><strong>{{ $application->user->name }}</strong><small>{{ $application->user->email }} · {{ $application->position->name }} · {{ $application->period->name }}</small></span></label>
                                    @empty
                                        <p>Tidak ada lamaran kandidat aktif yang cocok.</p>
                                    @endforelse
                                </div>
                                <div class="row candidate-picker-footer"><small>Maksimum 30 hasil pencarian. Pilihan tetap tersimpan saat mencari nama lain.</small><button type="button" class="link-button" x-on:click="$wire.set('applicationIds', [], false)">Hapus pilihan</button><button type="button" class="button small" x-on:click="open = false; $refs.trigger.focus()">Selesai memilih</button></div>
                            </div>
                        </div>
                        @error('applicationIds')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                        @error('applicationIds.*')<span class="field-error" role="alert">{{ $message }}</span>@enderror

                        <div class="technical-schedule">
                            <label class="field">Dibuka pada<input type="datetime-local" wire:model="opensAt" required><small>Kandidat dapat mulai sejak waktu ini.</small></label>
                            <label class="field">Tenggat penugasan<input type="datetime-local" wire:model="deadline" required><small>Seluruh jawaban dikunci setelah tenggat.</small></label>
                        </div>
                        <p class="technical-help"><x-heroicon-o-globe-alt /> Jadwal menggunakan zona waktu {{ $zone }}.</p>
                        <div class="technical-inline-note"><strong>Penugasan yang sudah ada aman.</strong><p>Kandidat yang sudah menerima versi paket ini akan dilewati tanpa mengubah jadwal atau jawabannya.</p></div>
                    </section>
                </div>

                <details class="technical-disclosure technical-preview">
                    <summary><x-heroicon-o-document-magnifying-glass /><span>Tinjau soal, kunci, dan rubrik<small>Periksa materi sebelum memberikan penugasan.</small></span></summary>
                    <div class="technical-preview-content">
                        @foreach($package['sections'] as $stage)
                            <details class="technical-disclosure">
                                <summary>{{ $stage['title'] }} <span class="badge">{{ count($stage['questions']) }} soal / isian</span></summary>
                                <div class="technical-disclosure-body">
                                    @include('candidate.technical-assessment-material', ['material' => $stage['material']])
                                    @foreach($stage['questions'] as $question)
                                        <article class="technical-answer"><strong>{{ $question['label'] ?? $question['id'] }}</strong><p>{{ $question['prompt'] }}</p>@foreach($question['options'] ?? [] as $value => $label)<p>{{ $value }}. {{ $label }}</p>@endforeach</article>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                        <details class="technical-disclosure"><summary>Kunci dan panduan assessor · rahasia</summary><div class="technical-disclosure-body">@include('candidate.technical-assessment-material', ['material' => $package['assessor_material']])</div></details>
                    </div>
                </details>

                <footer class="technical-assignment-footer">
                    <label class="technical-confirm"><input type="checkbox" wire:model="reviewed" required><span><strong>Saya sudah meninjau materi dan pengaturan.</strong><small>Soal, kunci, durasi, dan prosedur telah diperiksa. Form B dilakukan langsung; A2 bukan penentu kelulusan.</small></span></label>
                    <button class="button primary" wire:loading.attr="disabled" wire:target="assign"><x-heroicon-o-user-plus /><span wire:loading.remove wire:target="assign">Buat penugasan</span><span wire:loading wire:target="assign">Menyimpan penugasan…</span></button>
                </footer>
            </form>
        </section>

        <div id="technical-results" class="technical-results" x-show="workspace === 'results'" x-cloak>
            <section class="panel technical-results-panel">
                <header class="technical-section-heading"><div><h2>Penugasan dan hasil</h2><p>Cari kandidat untuk membuka sesi, memeriksa jawaban, atau menyelesaikan penilaian.</p></div><span class="badge">{{ $attempts->total() }} penugasan</span></header>
                <div class="technical-results-toolbar">
                    <label class="field">Cari hasil tes<input type="search" wire:model.live.debounce.400ms="resultSearch" maxlength="255" placeholder="Nama atau email kandidat"></label>
                    @if($selected)<a class="button small" href="#technical-session">Ke sesi terpilih ↓</a>@endif
                </div>
                <p class="technical-table-hint"><x-heroicon-o-arrows-right-left /> Geser tabel untuk melihat jadwal dan tombol aksi.</p>
                <div class="assessment-table-scroll" role="region" aria-label="Penugasan dan hasil kandidat" tabindex="0">
                    <table class="assessment-table technical-results-table">
                        <thead><tr><th scope="col">Kandidat</th><th scope="col">Paket</th><th scope="col">Pengerjaan</th><th scope="col">Jadwal</th><th scope="col">Penilaian assessor</th><th scope="col">Aksi</th></tr></thead>
                        <tbody>
                            @forelse($attempts as $attempt)
                                <tr wire:key="result-{{ $attempt->id }}" @class(['is-selected' => $attemptId === $attempt->id])>
                                    <td><strong>{{ $attempt->application->user->name }}</strong><small>{{ $attempt->application->user->email }}</small><small>{{ $attempt->application->position->name }}</small></td>
                                    <td><span class="badge">Form {{ $attempt->form }}</span><small>Versi {{ $attempt->version }}</small></td>
                                    <td><span class="badge {{ $attempt->completed_at ? 'clean' : 'pending' }}">{{ $attempt->completed_at ? 'Selesai' : (now()->gte($attempt->deadline) ? 'Tenggat berakhir' : ($attempt->section_started_at ? 'Berlangsung' : 'Menunggu mulai')) }}</span>@unless($attempt->completed_at)<small>Tahap {{ $attempt->section_index + 1 }} / {{ count($attempt->package['sections']) }}</small>@endunless</td>
                                    <td><small>Dibuka {{ $attempt->opens_at->timezone($zone)->format('d M Y H:i') }}</small><small>Batas {{ $attempt->deadline->timezone($zone)->format('d M Y H:i') }}</small></td>
                                    <td><span class="technical-review-status">{{ $attempt->review['status'] ?? 'Belum dinilai' }}</span></td>
                                    <td><button class="button small" wire:click="selectAttempt({{ $attempt->id }})" wire:loading.attr="disabled" wire:target="selectAttempt">Buka sesi / hasil <span aria-hidden="true">→</span></button></td>
                                </tr>
                            @empty
                                <tr><td colspan="6"><div class="technical-empty"><x-heroicon-o-clipboard-document-list /><h3>{{ $resultSearch !== '' ? 'Kandidat tidak ditemukan' : 'Belum ada penugasan' }}</h3><p>{{ $resultSearch !== '' ? 'Coba cari dengan nama atau email yang lain.' : 'Buat penugasan melalui tab Pengaturan & penugasan untuk memulai.' }}</p></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $attempts->links() }}
            </section>

            @if($selected)
                <section id="technical-session" class="panel technical-session" wire:key="attempt-{{ $selected->id }}" tabindex="-1" aria-label="Sesi kandidat terpilih" x-init="$nextTick(() => { $el.focus({ preventScroll: true }); $el.scrollIntoView({ block: 'start' }); })">
                    <header class="technical-section-heading"><div><div class="eyebrow">Sesi #{{ $selected->id }}</div><h2>{{ $selected->application->user->name }}</h2><p>{{ $selected->package['title'] }}</p></div><button class="button small" wire:click="selectAttempt({{ $selected->id }})" wire:loading.attr="disabled"><x-heroicon-o-arrow-path /> Perbarui status sesi</button></header>
                    <dl class="technical-session-meta">
                        <div><dt>Kesiapan kandidat</dt><dd>{{ $selected->consented_at ? 'Sudah dikonfirmasi' : 'Menunggu konfirmasi' }}</dd></div>
                        <div><dt>Pengerjaan</dt><dd>{{ $selected->completed_at ? 'Jawaban telah dikunci' : 'Tahap '.($selected->section_index + 1).' dari '.count($selected->package['sections']) }}</dd></div>
                        <div><dt>Tenggat penugasan</dt><dd>{{ $selected->deadline->timezone($zone)->format('d M Y H:i T') }}</dd></div>
                    </dl>

                    @if(!$selected->completed_at)
                        <section class="technical-session-control">
                            <div><span class="badge {{ $selected->section_started_at ? 'clean' : 'pending' }}">{{ $selected->section_started_at ? 'Tahap aktif' : 'Menunggu mulai' }}</span><h3>{{ $selected->package['sections'][$selected->section_index]['title'] }}</h3><p>{{ $selected->section_expires_at ? 'Waktu tahap berakhir '.$selected->section_expires_at->timezone($zone)->format('H:i:s T') : 'Timer dimulai saat tahap dibuka.' }}</p></div>
                            @if($selected->form === 'B' && !$selected->application->archived_at)
                                <p class="technical-help">Buka satu tahap pada satu waktu. Fakta baru hanya dibuka setelah keputusan awal dikunci. Pastikan jawaban kandidat sudah tersimpan.</p>
                                <form wire:submit="control({{ $selected->section_index }}, {{ $selected->revision }}, '{{ $selected->section_started_at ? 'finish' : 'start' }}')" class="technical-control-form">
                                    <label class="technical-confirm"><input type="checkbox" wire:model="confirmStageAction" required><span>{{ $selected->section_started_at ? 'Saya mengonfirmasi tahap akan dikunci menggunakan jawaban yang sudah tersimpan.' : 'Kandidat dan assessor sudah siap memulai timer tahap ini.' }}</span></label>
                                    <button class="button primary" wire:loading.attr="disabled" wire:target="control">{{ $selected->section_started_at ? 'Kunci tahap aktif' : 'Mulai tahap untuk kandidat' }}</button>
                                </form>
                            @endif
                        </section>
                        @if($selected->section_started_at)
                            <details class="technical-disclosure"><summary><x-heroicon-o-clock /><span>Pemulihan gangguan pada tahap aktif<small>Tambahkan waktu dengan alasan yang tercatat.</small></span></summary>
                                <form wire:submit="extend({{ $selected->section_index }}, {{ $selected->revision }})" class="technical-disclosure-body technical-extension-form">
                                    <label class="field">Tambahan menit<input type="number" wire:model="extraMinutes" min="1" max="120" required></label>
                                    <label class="field">Alasan dan bagian terdampak<textarea wire:model="extensionReason" minlength="10" maxlength="2000" rows="3" required placeholder="Jelaskan gangguan, durasi, dan bagian yang terdampak."></textarea></label>
                                    <p class="technical-help">Tahap yang sudah ditutup tidak dibuka ulang. Bila hasil tidak dapat ditafsirkan, gunakan status Administrasi tidak valid.</p>
                                    <button class="button" wire:loading.attr="disabled" wire:target="extend">Tambahkan waktu dan catat alasan</button>
                                </form>
                            </details>
                        @endif
                    @else
                        <section class="technical-score-section"><h3>Ringkasan hasil</h3><p class="technical-help">Skor membantu review dan verifikasi. IPP adalah indeks prioritas pilot, bukan keputusan kelulusan otomatis.</p>
                            <dl class="assessment-scores">@foreach($selected->scores ?? [] as $label => $value)@if($label !== 'items')<div><dt>{{ $label }}</dt><dd>{{ is_array($value) ? (implode(', ', $value) ?: 'Tidak ada') : ($value ?? 'Item belum lengkap') }}</dd></div>@endif @endforeach</dl>
                        </section>
                        <form wire:submit="saveReview" class="technical-review-form"><div><h3>Penilaian assessor</h3><p class="technical-help">Nilai berdasarkan jawaban, bukti proses, serta gangguan atau akomodasi yang dicatat.</p></div>
                            @if($selected->form === 'B')<div class="technical-rating-grid">@foreach(App\Services\TechnicalAssessmentService::RATING_DIMENSIONS as $dimension)<label class="field">{{ $dimension }}<input type="number" wire:model="ratings.{{ $dimension }}" min="0" max="4" required><small>Skor 0–4 sesuai rubrik.</small></label>@endforeach</div>@endif
                            <label class="field">Status verifikasi<select wire:model="reviewStatus" required><option value="">Pilih status penilaian</option>@foreach(App\Services\TechnicalAssessmentService::REVIEW_STATUSES as $status)<option>{{ $status }}</option>@endforeach</select></label>
                            <label class="field">Alasan dan bukti, termasuk gangguan / akomodasi<textarea wire:model="reviewNotes" minlength="10" maxlength="10000" rows="5" required placeholder="Tuliskan bukti yang mendukung penilaian dan kondisi yang perlu dipertimbangkan."></textarea></label>
                            <button class="button primary" wire:loading.attr="disabled" wire:target="saveReview">Simpan penilaian assessor</button>
                        </form>
                    @endif

                    <div class="technical-session-evidence">
                        <details class="technical-disclosure"><summary><x-heroicon-o-document-text /><span>Jawaban tersimpan dan catatan kandidat<small>Periksa respons per tahap dan kondisi pengerjaan.</small></span></summary>
                            <div class="technical-disclosure-body">
                                @foreach($selected->package['sections'] as $index => $stage)
                                    <h3>{{ $stage['title'] }}</h3>
                                    @forelse($stage['questions'] as $question)
                                        @php($answer = $selected->answers[$index][$question['id']] ?? '')
                                        <article class="technical-answer"><strong>{{ $question['label'] ?? $question['id'] }}</strong><p>{{ $question['prompt'] }}</p><div class="technical-answer-value assessment-material-text">{{ $answer !== null && $answer !== '' ? $answer : 'Belum dijawab' }}@if($question['type'] === 'choice' && isset($question['options'][$answer])) · {{ $question['options'][$answer] }}@endif</div></article>
                                    @empty
                                        <p class="muted">Tahap orientasi tidak memerlukan jawaban.</p>
                                    @endforelse
                                @endforeach
                                <h3>Catatan kondisi</h3>@foreach($selected->package['condition_questions'] as $question)<article class="technical-answer"><p>{{ $question['prompt'] }}</p><div class="technical-answer-value assessment-material-text">{{ $selected->conditions[$question['id']] ?? 'Belum diisi' }}</div></article>@endforeach
                            </div>
                        </details>
                        <details class="technical-disclosure"><summary><x-heroicon-o-lock-closed /><span>Kunci dan rubrik versi penugasan<small>Materi terbatas untuk assessor.</small></span></summary><div class="technical-disclosure-body">@include('candidate.technical-assessment-material', ['material' => $selected->package['assessor_material']])</div></details>
                        <details class="technical-disclosure"><summary><x-heroicon-o-clock /><span>Riwayat aktivitas sesi (100 terbaru)<small>Waktu mulai, penyimpanan, dan aktivitas keluar halaman.</small></span></summary>
                            <div class="technical-disclosure-body">
                                <p class="technical-help">Perpindahan tab, kehilangan fokus, dan keluar layar penuh adalah catatan untuk klarifikasi, bukan bukti kecurangan.</p>
                                <div class="assessment-table-scroll" role="region" aria-label="Aktivitas tes kandidat" tabindex="0"><table class="assessment-table technical-log-table"><thead><tr><th scope="col">Waktu</th><th scope="col">Aktivitas</th><th scope="col">Tahap</th><th scope="col">Keterangan</th></tr></thead><tbody>
                                    @foreach($logs as $log)
                                        <tr><td>{{ $log->created_at->timezone($zone)->format('d M H:i:s') }}</td><td>{{ match(str_replace('technical_assessment.', '', $log->action)) { 'assigned' => 'Penugasan dibuat', 'consented' => 'Kesiapan dikonfirmasi', 'section_started' => 'Tahap dimulai', 'section_closed' => 'Tahap dikunci', 'answers_saved' => 'Jawaban disimpan', 'completed' => 'Tes selesai', 'conditions_saved' => 'Catatan kondisi disimpan', 'time_extended' => 'Waktu ditambahkan', 'reviewed' => 'Penilaian disimpan', 'activity.page_loaded' => 'Halaman tes dibuka', 'activity.tab_hidden' => 'Beralih tab', 'activity.window_blur' => 'Halaman kehilangan fokus', 'activity.fullscreen_exit' => 'Keluar layar penuh', 'activity.connection_restored' => 'Koneksi tersambung kembali', default => 'Aktivitas tes' } }}</td><td>{{ isset($log->metadata['section']) ? $log->metadata['section'] + 1 : '—' }}</td><td>{{ match($log->reason) { 'timeout' => 'Waktu tahap berakhir', 'deadline' => 'Tenggat penugasan berakhir', 'submitted' => 'Dikirim kandidat', 'assessor_closed' => 'Ditutup assessor', default => $log->reason } }}@if($log->actor)<small>{{ $log->actor->name }}</small>@endif</td></tr>
                                    @endforeach
                                </tbody></table></div>
                            </div>
                        </details>
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
