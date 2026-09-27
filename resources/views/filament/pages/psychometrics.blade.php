<x-filament-panels::page>
    <div class="portal-admin hr-psych" x-data="{ workspace: 'packages' }">
        <header class="hr-psych-heading">
            <div><div class="eyebrow">Asesmen rekrutmen</div><h1>CFIT 3B</h1><p>Siapkan tes, atur penugasan, dan tinjau hasil kandidat.</p></div>
            <button type="button" class="button primary" wire:click="createDraft" x-on:click="workspace = 'packages'" wire:loading.attr="disabled"><x-heroicon-o-plus/> Buat paket tes</button>
        </header>
        <div class="hr-psych-overview" aria-label="Ringkasan psikotes">
            <div><span class="hr-psych-stat-icon"><x-heroicon-o-square-3-stack-3d/></span><span><strong>{{ $tests->count() }}</strong><small>Paket tes</small></span></div>
            <div><span class="hr-psych-stat-icon"><x-heroicon-o-pencil-square/></span><span><strong>{{ $tests->whereNull('published_at')->count() }}</strong><small>Dalam draf</small></span></div>
            <div><span class="hr-psych-stat-icon"><x-heroicon-o-check-badge/></span><span><strong>{{ $tests->whereNotNull('published_at')->count() }}</strong><small>Paket terbit</small></span></div>
            <div><span class="hr-psych-stat-icon"><x-heroicon-o-users/></span><span><strong>{{ $attempts->total() }}</strong><small>Penugasan</small></span></div>
        </div>
        @if($feedback)<div class="success" role="status">{{ $feedback }}</div>@endif
        <x-errors />
        <div class="hr-psych-loading" wire:loading.delay role="status">Memproses perubahan…</div>
        <nav class="hr-psych-nav" aria-label="Pengelolaan psikotes">
            <button type="button" x-on:click="workspace = 'packages'" x-bind:class="{ 'is-active': workspace === 'packages' }" x-bind:aria-pressed="workspace === 'packages'"><x-heroicon-o-adjustments-horizontal/> Paket & pengaturan</button>
            <button type="button" x-on:click="workspace = 'results'" x-bind:class="{ 'is-active': workspace === 'results' }" x-bind:aria-pressed="workspace === 'results'"><x-heroicon-o-chart-bar/> Penugasan & hasil <span>{{ $attempts->total() }}</span></button>
        </nav>
        <div class="hr-psych-workspace" x-show="workspace === 'packages'">
            <aside class="panel hr-psych-library" aria-label="Daftar paket tes">
                <div class="hr-psych-library-heading"><h2>Paket tes</h2><span>{{ $tests->count() }}</span></div>
                <p class="muted">Pilih paket untuk mengatur tes.</p>
                <div class="hr-psych-package-list">
                    @forelse($tests as $test)
                        <button type="button" wire:key="package-{{ $test->id }}" class="hr-psych-package {{ $testId === $test->id ? 'is-selected' : '' }}" wire:click="edit({{ $test->id }})" wire:loading.attr="disabled" aria-pressed="{{ $testId === $test->id ? 'true' : 'false' }}">
                            <span class="hr-psych-package-top"><span class="hr-psych-tag {{ $test->published_at ? 'is-published' : '' }}">{{ $test->published_at ? 'Terbit' : 'Draf' }}</span><small>#{{ $test->id }}</small></span>
                            <strong>{{ $test->title }}</strong><span class="hr-psych-package-meta">{{ count($test->sections) }} bagian <span aria-hidden="true">·</span> {{ array_sum(array_column($test->sections, 'count')) }} soal <x-heroicon-o-arrow-right/></span>
                        </button>
                    @empty
                        <div class="hr-psych-empty"><x-heroicon-o-document-plus/><h3>Belum ada paket</h3><p>Buat paket tes pertama untuk mulai mengatur soal.</p></div>
                    @endforelse
                </div>
            </aside>
            <div class="hr-psych-main">
                @if($selected)
                    <section class="panel hr-psych-editor" wire:key="editor-{{ $selected->id }}">
                        <header class="hr-psych-editor-heading"><div><div class="eyebrow">Pengaturan paket #{{ $selected->id }}</div><h2>{{ $selected->title }}</h2><p>{{ count($selected->sections) }} bagian · {{ array_sum(array_column($selected->sections, 'count')) }} soal</p></div><span class="hr-psych-tag {{ $selected->published_at ? 'is-published' : '' }}">{{ $selected->published_at ? 'Terbit · terkunci' : 'Draf' }}</span></header>
                        <div class="hr-psych-sections">
                            @foreach($selected->sections as $i => $section)
                                <section class="hr-psych-section" wire:key="section-{{ $selected->id }}-{{ $i }}">
                                    <div class="hr-psych-section-heading"><span class="hr-psych-number">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span><div><h3>{{ $section['title'] }}</h3><small>{{ $section['count'] }} soal · {{ $section['choices'] === 1 ? 'Satu pilihan jawaban' : 'Dua pilihan jawaban' }}</small></div></div>
                                    <div class="hr-psych-section-fields">
                                        <label class="field">Durasi <span class="hr-psych-unit-input"><input type="number" min="1" max="7200" placeholder="0" wire:model="durations.{{ $i }}" @disabled($selected->published_at)><span>detik</span></span></label>
                                        <label class="field">Kunci berurutan<textarea rows="2" placeholder="{{ $section['choices'] === 2 ? 'A,B C,E …' : 'A C F …' }}" wire:model="keyText.{{ $i }}" @disabled($selected->published_at)></textarea><small>{{ $section['choices'] === 2 ? 'Pisahkan pasangan dengan spasi. Contoh: A,B C,E.' : 'Pisahkan setiap jawaban dengan spasi. Contoh: A C F. Tanda - berarti kunci belum diisi dan wajib dilengkapi sebelum terbit.' }}</small></label>
                                    </div>
                                    <details class="hr-psych-disclosure"><summary><x-heroicon-o-eye/> Pratinjau contoh dan soal</summary><div class="hr-psych-preview">@foreach([$section['example'], ...$section['pages']] as $page)<img class="psych-page" loading="lazy" src="{{ route('psychometrics.preview', [$selected, $page]) }}" alt="{{ $section['title'] }} halaman {{ $page }}">@endforeach</div></details>
                                </section>
                            @endforeach
                        </div>
                        <details class="hr-psych-disclosure hr-psych-upload" wire:ignore.self>
                            <summary><x-heroicon-o-photo/> Gambar per soal dan pilihan jawaban</summary>
                            <div class="hr-psych-upload-content">
                                <p class="muted">Pilih nomor sesuai urutan pengerjaan. Gambar soal berisi pertanyaan saja; unggah gambar pilihan A–F secara terpisah agar tetap mudah dibaca di HP.</p>
                                <div class="two-col">
                                    <label class="field">Bagian tes<select wire:model.live="imageSection">@foreach($selected->sections as $index => $definition)<option value="{{ $index }}">{{ $definition['title'] }}</option>@endforeach</select></label>
                                    <label class="field">Nomor soal<select wire:model.live="imageQuestion">@for($q = 1; $q <= ($selected->sections[$imageSection]['count'] ?? 0); $q++)<option value="{{ $q }}">Soal {{ $q }}</option>@endfor</select></label>
                                    <label class="field">Gambar yang diganti<select wire:model.live="imagePart"><option value="stem">Gambar soal</option>@foreach(array_slice(range('A', 'F'), 0, $selected->sections[$imageSection]['options'] ?? 0) as $letter)<option value="{{ $letter }}">Pilihan {{ $letter }}</option>@endforeach</select></label>
                                </div>
                                @php
                                    $imageDefinition = $selected->sections[$imageSection] ?? [];
                                    $imageOriginalNumber = $imageDefinition['source_numbers'][$imageQuestion - 1] ?? $imageQuestion;
                                    $imageMap = config('psychometric_questions.sections')[$imageSection][$imageOriginalNumber] ?? null;
                                    $imageRegion = $imagePart === 'stem' ? ($imageMap['stem'] ?? null) : ($imageMap['options'][array_search($imagePart, range('A', 'F'), true)] ?? null);
                                    $replacement = $imageDefinition['images'][$imageQuestion][$imagePart] ?? null;
                                @endphp
                                <div class="hr-psych-image-preview" wire:key="image-{{ $selected->id }}-{{ $imageSection }}-{{ $imageQuestion }}-{{ $imagePart }}-{{ md5($replacement ?? '') }}">
                                    @if($replacement)
                                        <img src="{{ route('psychometrics.question-preview', [$selected, $imageSection, $imageQuestion, $imagePart]) }}?v={{ md5($replacement) }}" alt="Gambar pengganti soal {{ $imageQuestion }} {{ $imagePart }}">
                                    @elseif($imageMap && $imageMap['page'] === 11 && $selected->corrected_page_path)
                                        <img src="{{ route('psychometrics.preview', [$selected, 11]) }}" alt="Halaman pengganti Tes 3">
                                    @elseif($imageRegion)
                                        <x-psychometric-image :image-width="$imageMap['image_width'] ?? 1191" :image-height="$imageMap['image_height'] ?? 1684" :source="route('psychometrics.preview', [$selected, $imageMap['page']])" :region="$imageRegion" label="Pratinjau gambar asli" />
                                    @else
                                        <p class="muted">Soal ini menggunakan gambar pada setiap pilihan jawaban.</p>
                                    @endif
                                </div>
                                @unless($selected->published_at)
                                    <label class="field">Unggah gambar pengganti<input type="file" accept="image/jpeg,image/png" wire:model="questionImage"><small>JPG atau PNG, maksimal 10 MB. Periksa juga kunci setelah mengganti gambar.</small></label>
                                    <button type="button" class="button" wire:click="uploadQuestionImage" wire:loading.attr="disabled"><x-heroicon-o-photo/> Simpan gambar soal</button>
                                @else
                                    <p class="muted">Gambar paket terbit terkunci.</p>
                                @endunless
                            </div>
                        </details>
                        <div class="hr-psych-delete">
                            @if($selected->attempts_count)
                                <p class="muted">Paket tidak dapat dihapus: sudah ada {{ $selected->attempts_count }} penugasan.</p>
                            @elseif($confirmDelete)
                                <p>Hapus paket <strong>{{ $selected->title }}</strong> beserta gambar unggahannya? Tindakan ini tidak dapat dibatalkan.</p>
                                <div class="hr-psych-actions"><button type="button" class="button danger" wire:click="deletePackage" wire:loading.attr="disabled">Ya, hapus paket</button><button type="button" class="button" wire:click="$set('confirmDelete', false)" wire:loading.attr="disabled">Batal</button></div>
                            @else
                                <button type="button" class="button danger" wire:click="$set('confirmDelete', true)" wire:loading.attr="disabled"><x-heroicon-o-trash/> Hapus paket</button>
                                <p class="muted">Hanya paket tanpa penugasan yang dapat dihapus.</p>
                            @endif
                        </div>
                        @unless($selected->published_at)
                            <details class="hr-psych-disclosure hr-psych-upload"><summary><x-heroicon-o-photo/> Ganti gambar halaman 11 <span class="hr-psych-optional">Opsional</span></summary><div class="hr-psych-upload-content"><label class="field">Gambar pengganti<input type="file" accept="image/jpeg,image/png" wire:model="correctedPage"><small>JPG atau PNG, maksimal 10 MB.</small></label>@if($selected->corrected_page_path)<p class="muted">Halaman pengganti tersedia.</p>@endif<button type="button" class="button" wire:click="uploadCorrection" wire:loading.attr="disabled">Simpan gambar</button></div></details>
                            <footer class="hr-psych-editor-footer"><label class="hr-psych-review"><input type="checkbox" wire:model="reviewed"><span>Saya telah memeriksa soal, kunci jawaban, dan durasi tes.</span></label><div class="hr-psych-actions"><button type="button" class="button" wire:click="save" wire:loading.attr="disabled"><x-heroicon-o-bookmark/> Simpan draf</button><button type="button" class="button primary" wire:click="save(true)" wire:loading.attr="disabled"><x-heroicon-o-check-circle/> Terbitkan & kunci</button></div></footer>
                        @endunless
                    </section>
        @if($selected->published_at)
            <form class="panel hr-psych-assignment" wire:submit="assign"><div class="eyebrow">Langkah berikutnya</div><h2>Tugaskan ke kandidat</h2><p class="muted">Satu kesempatan per paket dan lamaran. Jadwal menggunakan {{ App\Models\AppSetting::valueFor('timezone') }}.</p><label class="field">Cari kandidat<input wire:model.live.debounce.400ms="search" placeholder="Nama atau email"></label><label class="field">Lamaran (maksimum 30 hasil pencarian)<select wire:model="applicationId" required><option value="">Pilih kandidat</option>@foreach($applications as $application)<option value="{{ $application->id }}">{{ $application->user->name }} · {{ $application->user->email }} · {{ $application->position->name }} · {{ $application->period->name }}</option>@endforeach</select></label><div class="two-col"><label class="field">Jadwal dibuka<input type="datetime-local" wire:model="opensAt" required></label><label class="field">Tenggat akhir<input type="datetime-local" wire:model="deadline" required></label></div><div class="hr-psych-actions"><button class="button primary" wire:loading.attr="disabled" wire:target="assign"><x-heroicon-o-user-plus/> Simpan penugasan</button></div></form>
        @endif
                @else
                    <section class="panel hr-psych-empty hr-psych-welcome"><span class="hr-psych-welcome-icon"><x-heroicon-o-adjustments-horizontal/></span><div class="eyebrow">Ruang pengelolaan tes</div><h2>Mulai dari sebuah paket</h2><p>Pilih paket di daftar untuk mengatur durasi dan kunci jawaban. Setelah diterbitkan, paket siap ditugaskan kepada kandidat.</p><div class="hr-psych-steps"><span><b>1</b> Atur tes</span><span><b>2</b> Terbitkan</span><span><b>3</b> Tugaskan</span></div></section>
                @endif
            </div>
        </div>
    <section class="panel hr-psych-results" x-show="workspace === 'results'" x-cloak><div class="row"><h2>Penugasan dan hasil</h2><button class="button" wire:click="finalizeExpired" wire:loading.attr="disabled">Perbarui status</button></div><p class="muted">Nilai hanya tersedia setelah tes selesai. Jawaban kosong atau kombinasi yang tidak lengkap bernilai 0.</p>
        @forelse($attempts as $attempt)<article class="hr-psych-attempt" wire:key="attempt-{{ $attempt->id }}"><div class="row"><div><strong>{{ $attempt->application->user->name }}</strong><p>{{ $attempt->test->title }}</p></div><span class="badge">{{ $attempt->completed_at ? 'Selesai' : (now()->gte($attempt->deadline) ? 'Tenggat berakhir · menunggu finalisasi' : 'Bagian '.($attempt->section_index + 1)) }}</span></div><p>Tenggat: {{ $attempt->deadline->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}</p>
        @if($attempt->completed_at)
            <p><strong>Skor mentah: {{ $attempt->raw_score }} / {{ array_sum(array_column($attempt->test->sections, 'count')) }}</strong></p>
            @if($attempt->iqScore() !== null)
                <p><strong>IQ: {{ $attempt->iqScore() }}</strong> · Kategori: {{ $attempt->iqCategory() }}</p>
            @else
                <p class="muted">Konversi IQ tidak tersedia untuk skor ini.</p>
            @endif
            <details class="hr-psych-disclosure">
                <summary>Rincian jawaban dan kunci (khusus HR)</summary>
                @foreach($attempt->test->sections as $i => $section)
                    <h3>{{ $section['title'] }}: {{ $attempt->section_scores[$i] ?? 0 }} / {{ $section['count'] }}</h3>
                    <div class="table-wrap"><table class="portal-table">
                        <caption class="sr-only">Perbandingan jawaban kandidat dan kunci {{ $section['title'] }}</caption>
                        <thead><tr><th scope="col">Nomor</th><th scope="col">Jawaban kandidat</th><th scope="col">Kunci jawaban</th><th scope="col">Hasil</th></tr></thead>
                        <tbody>
                            @for($n = 1; $n <= $section['count']; $n++)
                                @php
                                    $candidateAnswer = $attempt->answers[$i][$n] ?? [];
                                    $answerKey = $attempt->test->answer_key[$i][$n - 1] ?? [];
                                    sort($candidateAnswer);
                                    sort($answerKey);
                                    $answerStatus = $answerKey === [] ? 'Kunci belum tersedia' : ($candidateAnswer === [] ? 'Tidak dijawab' : ($candidateAnswer === $answerKey ? 'Benar' : 'Salah'));
                                @endphp
                                <tr><th scope="row">{{ $n }}@if(isset($section['source_numbers']) && $section['source_numbers'][$n - 1] !== $n) <small>(nomor {{ $section['source_numbers'][$n - 1] }} pada gambar)</small>@endif</th><td>{{ implode(', ', $candidateAnswer) ?: '—' }}</td><td>{{ implode(', ', $answerKey) ?: 'Belum diisi' }}</td><td>{{ $answerStatus }}</td></tr>
                            @endfor
                        </tbody>
                    </table></div>
                @endforeach
            </details>
        @endif
        <div class="hr-psych-delete">
            @if($deletingAttemptId === $attempt->id)
                <div role="alert" tabindex="-1" x-data x-init="$el.focus()">
                    <p>Hapus <strong>{{ $attempt->application->user->name }}</strong> dari tes <strong>{{ $attempt->test->title }}</strong>?</p>
                    <p class="muted">Penugasan, jawaban, dan hasil tes ini akan dihapus permanen. Akun, lamaran, dan hasil tes lain tetap tersimpan. Peserta dapat ditugaskan ulang setelah dihapus.</p>
                    <div class="hr-psych-actions">
                        <button type="button" class="button" wire:click="cancelAttemptDeletion" wire:loading.attr="disabled">Batal</button>
                        <button type="button" class="button danger" wire:click="deleteAttempt" wire:loading.attr="disabled">Ya, hapus peserta dari tes</button>
                    </div>
                </div>
            @else
                <button type="button" class="button danger" wire:click="confirmAttemptDeletion({{ $attempt->id }})" wire:loading.attr="disabled"><x-heroicon-o-trash/> Hapus peserta dari tes</button>
            @endif
        </div>
        </article>@empty<div class="hr-psych-empty"><x-heroicon-o-users/><h3>Belum ada penugasan</h3><p>Terbitkan paket tes, lalu pilih kandidat dan jadwal pengerjaan.</p><button type="button" class="button" x-on:click="workspace = 'packages'">Kelola paket tes</button></div>@endforelse{{ $attempts->links() }}</section>
    </div>
</x-filament-panels::page>
