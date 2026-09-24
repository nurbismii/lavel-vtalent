<x-filament-panels::page>
    <div class="portal-admin" x-data="{ password: '', email: '' }" x-on:access-created.window="password=$event.detail.password; email=$event.detail.email">
        <div x-show="password" x-cloak class="notice" role="status">
            <h2>Akses sementara dibuat</h2>
            <p x-text="email"></p>
            <p>Password ini ditampilkan sekali. Bagikan melalui kanal perusahaan.</p><code x-text="password"></code>
            <div class="row"><button type="button" class="button" x-on:click="navigator.clipboard.writeText(password).then(()=> $el.textContent='Tersalin').catch(()=> $el.textContent='Salin secara manual')">Salin password</button><button type="button" class="button" x-on:click="password=''">Tutup & sembunyikan</button></div>
        </div>

        <x-errors />@if($feedback)<div class="success" role="status">{{ $feedback }}</div>@endif<div wire:loading.delay role="status">Memproses…</div>
        @if($section !== 'tools')<div class="row"><button type="button" class="button" wire:click="navigate('tools')">Tenggat massal, email & soal tes</button></div>@endif
        @if($section === 'tools')
            @include('filament.pages.recruitment-tools')
        @elseif(in_array($section,['dashboard','applications']))
        @if($section==='dashboard')<div class="heading">
            <div>
                <div class="eyebrow">Workspace HR</div>
                <h1>Ringkasan rekrutmen</h1>
                <p class="muted">Pantau pengumpulan. Temukan yang perlu ditindaklanjuti.</p>
            </div>
        </div>
        <div class="stats">
            <div class="panel"><small>LAMARAN AKTIF</small>
                <div class="stat-value">{{ $stats['active'] }}</div>
            </div>
            <div class="panel"><small>PORTOFOLIO TERKUMPUL</small>
                <div class="stat-value">{{ $stats['portfolio'] }} <small>/ {{ $stats['active'] }}</small></div><small>Pengumpulan final; tidak termasuk pengecualian.</small>
            </div>
            <div class="panel"><small>TES TEKNIS TERKUMPUL</small>
                <div class="stat-value">{{ $stats['test'] }} <small>/ {{ $stats['active'] }}</small></div>
            </div>
        </div>@if($stats['overdue'])<div class="notice"><strong>{{ $stats['overdue'] }} pengumpulan melewati tenggat.</strong> Gunakan filter tenggat untuk meninjau tindak lanjut.</div>@endif @endif
        @if($section==='dashboard')<div class="two-col" style="margin:20px 0">@foreach(App\Enums\SubmissionType::cases() as $type)<section class="panel">
                <h3>{{ $type->label() }} · Semua status</h3>@foreach(App\Enums\SubmissionStatus::cases() as $status)<div class="row" style="margin-top:8px"><span class="badge {{ $status->value }}">{{ $status->label() }}</span><strong>{{ $statusCounts->first(fn($row)=>$row->type===$type && $row->status===$status)?->total ?? 0 }}</strong></div>@endforeach
            </section>@endforeach</div>@endif<section class="panel applications-panel">
    <header class="applications-header">
        <div>
            <div class="eyebrow">Administrasi rekrutmen</div>
            <h2>Kandidat & lamaran</h2>
            <p class="muted">Kelola lamaran dan pantau pengumpulan dokumen kandidat.</p>
        </div>
        <div class="applications-actions">
            <button type="button" class="button" wire:click="navigate('import')"><x-heroicon-o-arrow-up-tray/>Import kandidat</button>
            <button type="button" class="button primary" wire:click="navigate('create')"><x-heroicon-o-plus/>Tambah kandidat / lamaran</button>
        </div>
    </header>
    <div class="applications-filters">
        <div class="applications-filter-main">
            <label class="field">Cari kandidat
                <span class="applications-search"><x-heroicon-o-magnifying-glass/><input wire:model.live.debounce.350ms="search" placeholder="Cari nama atau email…" type="search"></span>
            </label>
            <label class="field">Posisi<select wire:model.live="positionFilter"><option value="">Semua posisi</option>@foreach($positions as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
            <label class="field">Periode rekrutmen<select wire:model.live="periodFilter"><option value="">Semua periode</option>@foreach($periods as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
        </div>
        <div class="applications-filter-secondary">
            @foreach(['portfolioFilter'=>'Status portofolio','testFilter'=>'Status tes teknis'] as $field=>$label)
                <label class="field">{{ $label }}<select wire:model.live="{{ $field }}"><option value="">Semua status</option>@foreach(App\Enums\SubmissionStatus::cases() as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></label>
            @endforeach
            <div class="applications-toggles">
                <label><input type="checkbox" wire:model.live="overdue">Tenggat terlewati</label>
                <label><input type="checkbox" wire:model.live="archived">Lamaran diarsipkan</label>
            </div>
        </div>
    </div>
    <div class="applications-results">
        <p><strong>{{ number_format($applications->total(), 0, ',', '.') }}</strong> lamaran {{ $archived ? 'diarsipkan' : 'aktif' }} ditemukan</p>
        @if($search || $positionFilter || $periodFilter || $portfolioFilter || $testFilter || $overdue || $archived)
            <button type="button" class="link-button" wire:click="resetApplicationFilters">Reset filter</button>
        @endif
    </div>
    <div class="table-wrap applications-table-wrap">
        <table class="portal-table applications-table">
            <caption class="applications-sr-only">Daftar kandidat dan status pengumpulan lamaran</caption>
            <thead><tr><th scope="col">Kandidat</th><th scope="col">Posisi & periode</th><th scope="col">Portofolio</th><th scope="col">Tes teknis</th><th scope="col"><span class="applications-sr-only">Aksi</span></th></tr></thead>
            <tbody>
                @forelse($applications as $app)
                    <tr wire:key="application-row-{{ $app->id }}">
                        <td class="applications-person" data-label="Kandidat">
                            <div class="applications-person-content">
                                <span class="applications-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($app->user->name, 0, 1)) }}</span>
                                <div><strong>{{ $app->user->name }}</strong><small>{{ $app->user->email }}</small>@if(!$app->user->active)<span class="badge rejected">Akun nonaktif</span>@endif</div>
                            </div>
                        </td>
                        <td data-label="Posisi & periode"><strong class="applications-position">{{ $app->position->name }}</strong><small>{{ $app->period->name }}</small></td>
                        @foreach(App\Enums\SubmissionType::cases() as $type)
                            @php($s=$app->submissions->firstWhere('type',$type))
                            <td data-label="{{ $type->label() }}">
                                @if($s)
                                    <span class="badge {{ $s->status->value }}">{{ $s->status->label() }}</span>
                                    <small class="applications-deadline">{{ $s->localDeadline() }}</small>
                                    @if($s->deadline->isPast() && in_array($s->status,[App\Enums\SubmissionStatus::NotStarted,App\Enums\SubmissionStatus::Draft,App\Enums\SubmissionStatus::Revision]))
                                        <span class="field-error">{{ $s->status===App\Enums\SubmissionStatus::Revision?'Revisi belum dikumpulkan':'Tenggat terlewati' }}</span>
                                    @endif
                                @else
                                    <small>Belum ada penugasan</small>
                                @endif
                            </td>
                        @endforeach
                        <td class="applications-row-action"><button type="button" class="button small" wire:click="openApplication({{ $app->id }})" aria-label="Lihat detail lamaran {{ $app->user->name }}">Detail <span aria-hidden="true">→</span></button></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="applications-empty"><x-heroicon-o-users/><h3>Belum ada lamaran yang cocok</h3><p class="muted">Coba ubah filter atau tambahkan lamaran baru.</p><small>Kandidat hasil import tersedia pada pilihan Kandidat terdaftar saat membuat lamaran.</small></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($applications->hasPages())<footer class="applications-pagination">{{ $applications->links() }}</footer>@endif
</section>
        @elseif($section==='import')
<section class="panel">
<div class="heading"><div><h1>Import kandidat</h1><p class="muted">Daftarkan akun kandidat dari Excel (.xlsx), lalu gunakan pilihan Kandidat terdaftar untuk membuat lamaran.</p></div><button type="button" class="button" wire:click="navigate('create')">Tambah kandidat / lamaran</button></div>
<button type="button" class="button" wire:click="downloadCandidateTemplate" wire:loading.attr="disabled">Download template Excel</button>
<div class="table-wrap"><table class="portal-table"><thead><tr><th>Kolom</th><th>Aturan pengisian</th></tr></thead><tbody>
<tr><td>nama</td><td>Wajib, maksimal 255 karakter.</td></tr>
<tr><td>email</td><td>Wajib, format email valid dan belum terdaftar. Tidak boleh duplikat dalam file.</td></tr>
<tr><td>password</td><td>Wajib, minimal 12 karakter dan maksimal 72 byte. Gunakan password berbeda untuk setiap kandidat.</td></tr>
<tr><td>telepon</td><td>Opsional, maksimal 30 karakter: angka, +, tanda kurung, spasi, titik, atau tanda hubung. Sel template sudah berformat teks agar angka 0 di depan tetap ada.</td></tr>
</tbody></table></div>
<p class="muted">Isi sheet Kandidat mulai baris kedua, lalu simpan sebagai Excel Workbook (.xlsx). Petunjuk tersedia pada sheet Panduan. Gunakan teks biasa tanpa rumus. Maksimal {{ config('candidate_import.max_rows') }} kandidat dan {{ config('candidate_import.max_kilobytes') / 1024 }} MB per file. Semua kolom header harus tetap ada, termasuk telepon yang opsional diisi.</p>
<div class="notice">Jika ada kesalahan, seluruh import dibatalkan. Password disimpan sebagai hash dan wajib diganti saat login pertama; akses sementara berlaku {{ App\Models\AppSetting::valueFor('temporary_password_hours') }} jam. File diproses dari penyimpanan sementara privat dan dihapus setelah diproses. Simpan file sumber dengan aman karena berisi password.</div>
<form novalidate x-data="{ uploading: false, importing: false, progress: 0 }" x-on:submit.prevent="if (uploading || importing || !$wire.importFile) return; importing = true; try { await $wire.importCandidates() } finally { importing = false }" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-cancel="uploading = false" x-on:livewire-upload-progress="progress = $event.detail.progress">
<label class="field">1. Pilih file Excel<input type="file" wire:model="importFile" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></label>
<div x-show="uploading" x-cloak role="status">Mengunggah… <progress max="100" x-bind:value="progress"></progress></div>
@if($importFile instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
<div class="notice" role="status"><strong>File sudah diunggah, akun belum disimpan.</strong><br>{{ $importFile->getClientOriginalName() }} siap diproses. Klik <strong>2. Import dan simpan kandidat</strong> untuk memvalidasi dan menyimpan akun.</div>
@endif
<p class="muted">Jika import gagal, perbaiki file sesuai nomor baris pada pesan kesalahan, lalu unggah ulang.</p>
<button type="submit" class="button primary" x-bind:disabled="uploading || importing || !$wire.importFile" x-bind:aria-busy="importing"><span x-show="!importing">2. Import dan simpan kandidat</span><span x-show="importing" x-cloak>Memvalidasi dan menyimpan…</span></button>
</form>
</section>
@elseif($section==='create')
        <section class="panel">
            <h1>Tambah kandidat / lamaran</h1>
            <p class="muted">Pilih kandidat terdaftar atau buat kandidat baru. Satu kandidat hanya dapat memiliki satu lamaran aktif.</p>
            <form wire:submit="createCandidate">
                <label class="field">Jenis kandidat<select wire:model.live="candidateMode">
                        <option value="new">Kandidat baru</option>
                        <option value="existing">Kandidat terdaftar</option>
                    </select></label>
                @if($candidateMode === 'existing')
                <div class="candidate-picker" wire:key="existing-candidate-fields" x-data="{ open: false }" x-on:keydown.escape.stop.prevent="open = false; $refs.trigger.focus()" x-on:click.outside="open = false" x-on:focusout="if (!$el.contains($event.relatedTarget)) open = false">
                    <label class="field" id="candidate-picker-label">Kandidat terdaftar</label>
                    <button type="button" class="button candidate-picker-trigger" x-ref="trigger" x-on:click="open = !open; if (open) $nextTick(() => $refs.search.focus())" x-bind:aria-expanded="open" aria-controls="candidate-picker-panel" aria-labelledby="candidate-picker-label candidate-picker-summary">
                        <span id="candidate-picker-summary" x-text="$wire.existingCandidateIds.length ? $wire.existingCandidateIds.length + ' kandidat dipilih' : 'Pilih kandidat'">Pilih kandidat</span><span aria-hidden="true">▾</span>
                    </button>
                    <div id="candidate-picker-panel" class="candidate-picker-panel" x-show="open" x-cloak>
                        <label class="field candidate-picker-search">Cari kandidat<input x-ref="search" wire:model.live.debounce.350ms="candidateSearch" placeholder="Cari nama atau email" maxlength="255" x-on:keydown.enter.prevent></label>
                        <div class="candidate-picker-options" role="group" aria-label="Pilihan kandidat">
                            @forelse($candidateOptions as $option)
                            <label class="candidate-picker-option" wire:key="candidate-option-{{ $option->id }}"><input type="checkbox" wire:model="existingCandidateIds" value="{{ $option->id }}"><span><strong>{{ $option->name }}</strong><small>{{ $option->email }}</small></span></label>
                            @empty
                            <p role="status" class="muted">Tidak ada kandidat yang cocok dan memenuhi syarat.</p>
                            @endforelse
                        </div>
                        <div class="row candidate-picker-footer"><small>Pilihan tetap tersimpan saat mencari nama lain.</small><button type="button" class="link-button" x-on:click="$wire.set('existingCandidateIds', [], false)">Hapus pilihan</button><button type="button" class="button small" x-on:click="open = false; $refs.trigger.focus()">Selesai memilih</button></div>
                    </div>
                    <small>Pilih maksimal 100 kandidat aktif tanpa lamaran aktif. Pencarian menampilkan maksimal 50 hasil. Semua kandidat mendapat posisi, periode, tenggat, dan penugasan yang sama.</small>
                    @error('existingCandidateIds')<span class="field-error">{{ $message }}</span>@enderror
                </div>@else
                <div class="two-col" wire:key="new-candidate-fields"><label class="field">Nama kandidat<input wire:model="candidate.name" required maxlength="255"></label><label class="field">Email<input wire:model="candidate.email" type="email" required></label></div>
                <small>Email terdaftar menggunakan akun lama tanpa mengubah identitasnya.</small>
                @endif
                <div class="two-col"><label class="field">Posisi<select wire:model="candidate.position_id" required>
                            <option value="">Pilih posisi</option>@foreach($positions->where('active',true) as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                        </select></label><label class="field">Periode<select wire:model="candidate.recruitment_period_id" required>
                            <option value="">Pilih periode</option>@foreach($periods->where('active',true) as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                        </select></label><label class="field">Tenggat portofolio ({{ App\Models\AppSetting::valueFor('timezone') }})<input wire:model="candidate.portfolio_deadline" type="datetime-local" required></label><label class="field">Tenggat tes teknis<input wire:model="candidate.test_deadline" type="datetime-local" required></label></div><label class="field">Label tugas teknis<input wire:model="candidate.task_label" required maxlength="255"></label><label class="field">Petunjuk pengumpulan<textarea wire:model="candidate.instructions" maxlength="5000"></textarea></label><button class="button primary full" wire:loading.attr="disabled">Buat lamaran</button>
            </form>
        </section>
        @elseif($section==='detail' && $application)
        <div class="heading">
            <div><small>{{ $application->position->name }} · {{ $application->period->name }}</small>
                <h1>{{ $application->user->name }}</h1>
                <p class="muted">{{ $application->user->email }}</p>
                @if(App\Services\CandidateFormService::available())<a class="button small" href="{{ route('filament.admin.pages.candidate-forms', ['candidate' => $application->user_id]) }}">Lihat formulir kandidat</a>@endif
            </div><span class="badge {{ $application->archived_at?'':'clean' }}">{{ $application->archived_at?'Diarsipkan':'Lamaran aktif' }}</span>
        </div>
        <div class="tabs">@foreach(['Profil','Portofolio','Tes Teknis','Riwayat'] as $t)<button class="{{ $tab===$t?'active':'' }}" wire:click="selectTab('{{ $t }}')">{{ $t }}</button>@endforeach</div>
        @if($tab==='Profil')<div class="two-col">
            <section class="panel">
                <h2>Profil kandidat</h2><label class="field">Nama<input wire:model="edit.name"></label><label class="field">Email<input wire:model="edit.email" type="email"></label><label class="field"><input type="checkbox" wire:model="edit.active"> Akun aktif</label>
                <p><small>Penonaktifan atau perubahan email membatalkan sesi lama.</small></p><button class="button" wire:click="prepareAction('edit')">Simpan perubahan akun</button>
                <hr>
                <div class="row"><button class="button" wire:click="prepareAction('reset')">Reset akses</button>@if(!$application->archived_at)<button class="button danger" wire:click="prepareAction('archive')">Arsipkan lamaran</button>@endif</div>
            </section>
            <section class="panel">
                <h2>Ubah penugasan</h2><small>Tersedia sebelum hasil tes pertama dikirim final.</small><label class="field">Posisi<select wire:model="candidate.position_id">
                        <option value="">Pilih posisi baru</option>@foreach($positions as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select></label><label class="field">Label tugas<input wire:model="candidate.task_label"></label><label class="field">Petunjuk<textarea wire:model="candidate.instructions"></textarea></label><label class="field">Alasan perubahan<textarea wire:model="reason" minlength="5" maxlength="2000"></textarea></label><button class="button" wire:click="updateAssignment" wire:loading.attr="disabled">Perbarui penugasan</button>
            </section>
        </div>
        @elseif(in_array($tab,['Portofolio','Tes Teknis']) && $submission)<div class="form-grid">
            <section class="panel">
                <div class="row">
                    <h2>{{ $submission->type->label() }}</h2><span class="badge {{ $submission->status->value }}">{{ $submission->status->label() }}</span>
                </div>@if($submission->administrative_reason)<div class="notice">{{ $submission->administrative_reason }}</div>@endif @if($version)<label class="field">Versi final<select wire:model.live="versionId">
                        <option value="">Versi terbaru</option>@foreach($versions as $v)<option value="{{ $v->id }}">Versi {{ $v->number }} · {{ $v->submitted_at->format('d M Y') }}</option>@endforeach
                    </select></label>
                <p class="receipt-code" style="margin-top:18px">{{ $version->receipt }}</p><small>{{ $version->submitted_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y, H:i T') }}</small>@foreach($version->attachments->sortBy(fn($a)=>$a->purpose==='portfolio_main'?0:1) as $attachment)<div class="file-row"><span class="file-tag">{{ strtoupper(pathinfo($attachment->file->original_name,PATHINFO_EXTENSION)) }}</span>
                    <div class="file-info"><strong>{{ $attachment->file->original_name }}</strong><br><small>{{ $attachment->purpose==='portfolio_main'?'Dokumen utama':'Lampiran' }} · {{ number_format($attachment->file->size/1048576,2) }} MB</small>@if($attachment->description)<p>{{ $attachment->description }}</p>@endif</div><x-file-actions :attachment="$attachment" />
                </div>@endforeach
                <hr>
                <h3>Tautan & catatan</h3>@foreach($version->links??[] as $link)<p><a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer">{{ $link['label'] }} ↗</a></p>@endforeach<p style="white-space:pre-wrap">{{ $version->notes ?: 'Tidak ada catatan.' }}</p>@else<div class="notice">Belum ada versi final. HR hanya dapat melihat status pengumpulan; isi draf tidak ditampilkan.</div>@endif
            </section>
            <aside class="panel">
                <h3>Pengaturan pengumpulan</h3><small>Tenggat</small>
                <p>{{ $submission->localDeadline() }}</p>@if(!$application->archived_at)@if($submission->status===App\Enums\SubmissionStatus::Submitted)<button type="button" class="button full" wire:click="prepareAction('revision')" wire:loading.attr="disabled" wire:target="prepareAction">Buka revisi</button>@elseif(in_array($submission->status,[App\Enums\SubmissionStatus::NotStarted,App\Enums\SubmissionStatus::Draft,App\Enums\SubmissionStatus::Revision]))<button class="button full" wire:click="prepareAction('deadline')">Perpanjang tenggat</button>@if($submission->type===App\Enums\SubmissionType::Portfolio && !$submission->current_version_id)<button class="button full" wire:click="prepareAction('exempt')">Tidak diwajibkan</button>@endif @endif @endif<small>Versi final sebelumnya tetap tersimpan saat revisi dibuka.</small>
            </aside>
        </div>@endif
        @if($action)<section class="panel" style="margin-top:22px" wire:key="confirmation-{{ $applicationId }}-{{ $action }}" x-data x-init="$nextTick(() => { $el.scrollIntoView({ behavior: 'smooth', block: 'center' }); $el.querySelector('textarea').focus({ preventScroll: true }); })" role="region" aria-label="Konfirmasi tindakan">
            <h2>Konfirmasi {{ match($action){'revision'=>'pembukaan revisi','deadline'=>'perpanjangan tenggat','reset'=>'reset akses','archive'=>'pengarsipan','exempt'=>'pengecualian portofolio',default=>'perubahan akun'} }}</h2>@if($action==='reset')<p>Sesi lama akan dibatalkan dan kandidat wajib mengganti password sementara.</p>@elseif($action==='archive')<p>Pengarsipan menutup pengeditan lamaran tanpa menghapus dokumen.</p>@endif<label class="field">Alasan · Wajib<textarea wire:model="reason" minlength="5" maxlength="2000"></textarea>@error('reason')<span class="field-error" role="alert">{{ $message }}</span>@enderror</label>@if(in_array($action,['revision','deadline']))<label class="field">Tenggat baru ({{ App\Models\AppSetting::valueFor('timezone') }})<input type="datetime-local" wire:model="deadline">@error('deadline')<span class="field-error" role="alert">{{ $message }}</span>@enderror</label>@endif<div class="row" style="margin-top:18px"><button class="button" wire:click="$set('action','')">Batal</button><button class="button primary" wire:click="applyAction" wire:loading.attr="disabled">Konfirmasi & simpan</button></div>
        </section>@endif
        @elseif(in_array($section,['positions','periods']))<div class="two-col">
            <section class="panel">
                <h2>{{ $section==='positions'?'Posisi':'Periode rekrutmen' }}</h2>@foreach($section==='positions'?$positions:$periods as $record)<div class="row" style="border-bottom:1px solid #e2e8f0;padding:12px 0">
                    <div>{{ $record->name }}<br><small>{{ $record->active?'Aktif':'Nonaktif' }}</small></div><button class="button small" wire:click="editCatalog({{ $record->id }})">Ubah</button>
                </div>@endforeach
            </section>
            <form class="panel" wire:submit="saveCatalog">
                <h2>{{ $catalogId?'Ubah data':'Tambah data' }}</h2><label class="field">Nama<input wire:model="catalog.name" required></label>@if($section==='periods')<label class="field">Tanggal mulai<input type="date" wire:model="catalog.starts_at" required></label><label class="field">Tanggal selesai<input type="date" wire:model="catalog.ends_at" required></label>@endif<label class="field"><input type="checkbox" wire:model="catalog.active"> Aktif</label><button class="button primary full" wire:loading.attr="disabled">Simpan</button>
            </form>
        </div>
        @elseif($section==='settings')<form class="panel" wire:submit="saveSettings">
            <h1>Pengaturan portal</h1>
            <div class="two-col">@foreach(['quota_mb'=>'Kuota per lamaran (MB)','temporary_password_hours'=>'Masa berlaku password sementara (jam)','retention_months'=>'Retensi setelah arsip (bulan)'] as $key=>$label)<label class="field">{{ $label }}<input type="number" wire:model="settings.{{ $key }}" required></label>@endforeach<label class="field">Zona waktu<select wire:model="settings.timezone">@foreach(['Asia/Makassar','Asia/Jakarta','Asia/Jayapura'] as $zone)<option>{{ $zone }}</option>@endforeach</select></label></div><label class="field">Kontak privasi / bantuan<input wire:model="settings.privacy_contact" required></label><label class="field"><input type="checkbox" wire:model="settings.retention_enabled"> Aktifkan penghapusan otomatis sesuai kebijakan retensi yang telah disetujui perusahaan.</label>
            <div class="notice">Penghapusan retensi bersifat permanen. Tetapkan kebijakan perusahaan dan siklus backup sebelum mengaktifkan.</div>
            <h2>Batas unggahan</h2>@foreach(['portfolio_main'=>'Dokumen utama','portfolio_evidence'=>'Bukti portofolio','technical_result'=>'Hasil tes'] as $key=>$label)<div class="two-col"><label class="field">{{ $label }} · MB/file<input type="number" min="1" max="25" wire:model="settings.uploads.{{ $key }}.max_mb"></label><label class="field">Jumlah file<input type="number" min="1" max="10" wire:model="settings.uploads.{{ $key }}.max_files" @disabled($key==='portfolio_main' )></label></div>@endforeach<label class="field">Alasan perubahan<textarea wire:model="reason" minlength="5" required></textarea></label><button class="button primary full" wire:loading.attr="disabled">Simpan pengaturan</button>
        </form>
        @elseif($section==='operations')<section class="panel">
            <h2>Status pengiriman email</h2>
            <div class="table-wrap">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Kandidat</th>
                            <th>Peristiwa</th>
                            <th>Status</th>
                            <th>Percobaan</th>
                        </tr>
                    </thead>
                    <tbody>@forelse($deliveries as $delivery)<tr>
                            <td>{{ $delivery->submission->application->user->name }}</td>
                            <td>{{ $delivery->event }}</td>
                            <td>{{ $delivery->status }}</td>
                            <td>{{ $delivery->attempts }}</td>
                        </tr>@empty<tr>
                            <td colspan="4">Belum ada email pengumpulan.</td>
                        </tr>@endforelse</tbody>
                </table>
            </div>{{ $deliveries->links() }}
            <hr>
            <h2>Pemeriksaan file</h2>@if(!config('submissions.scan_enabled'))<p>Pemindaian antivirus dinonaktifkan. Unggahan langsung tersimpan setelah validasi format dan ukuran.</p>@else @forelse($failedScans as $file)<p>{{ $file->submission->application->user->name }} · {{ $file->scan_status->label() }} <small>{{ $file->created_at->diffForHumans() }}</small></p>@empty<p>Tidak ada pemeriksaan tertunda atau gagal.</p>@endforelse @endif<small>File draf tidak dapat diunduh oleh HR. @if(config('submissions.scan_enabled'))Operasikan worker untuk menyelesaikan antrean.@endif</small>
        </section>@endif
        @if($section==='audit' || ($section==='detail' && $tab==='Riwayat'))<section class="panel">
            <h2>Riwayat aktivitas</h2>
            <div class="table-wrap">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Pelaku</th>
                            <th>Aktivitas</th>
                            <th>Alasan / perubahan</th>
                        </tr>
                    </thead>
                    <tbody>@forelse($audits as $audit)<tr>
                            <td>{{ $audit->created_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}</td>
                            <td>{{ $audit->actor?->name ?? 'Sistem' }}</td>
                            <td>{{ $audit->action }}</td>
                            <td>{{ $audit->reason }}@if($audit->metadata)<details>
                                    <summary>Detail metadata</summary>
                                    <pre style="white-space:pre-wrap">{{ json_encode($audit->metadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>@endif</td>
                        </tr>@empty<tr>
                            <td colspan="4">Belum ada aktivitas.</td>
                        </tr>@endforelse</tbody>
                </table>
            </div>{{ $audits->links() }}
        </section>@endif
    </div>
</x-filament-panels::page>
