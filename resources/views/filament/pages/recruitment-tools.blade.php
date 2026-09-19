<div class="recruitment-tools" x-data="{ workspace: 'deadlines' }">
<header class="tools-heading">
    <div><div class="eyebrow">Administrasi rekrutmen</div><h1>Pengelolaan massal & tes teknis</h1><p>Atur tenggat, siapkan soal, dan kirim akses kandidat dari satu tempat.</p><span class="tools-timezone"><x-heroicon-o-clock/> Zona waktu {{ App\Models\AppSetting::valueFor('timezone') }}</span></div>
    <button type="button" class="button" wire:click="navigate('applications')"><x-heroicon-o-arrow-left/> Kembali ke lamaran</button>
</header>
<nav class="tools-navigation" aria-label="Pilih pengelolaan">
    <button type="button" x-on:click="workspace = 'deadlines'" x-bind:class="{ 'is-active': workspace === 'deadlines' }" x-bind:aria-pressed="workspace === 'deadlines'"><span class="tools-nav-icon"><x-heroicon-o-calendar-days/></span><span><strong>Tenggat massal</strong><small>Perpanjang jadwal satu batch</small></span><span class="tools-nav-arrow" aria-hidden="true">→</span></button>
    <button type="button" x-on:click="workspace = 'tasks'" x-bind:class="{ 'is-active': workspace === 'tasks' }" x-bind:aria-pressed="workspace === 'tasks'"><span class="tools-nav-icon"><x-heroicon-o-document-text/></span><span><strong>Soal tes teknis</strong><small>Kelola PDF dan waktu mulai</small></span><span class="tools-nav-arrow" aria-hidden="true">→</span></button>
    <button type="button" x-on:click="workspace = 'email'" x-bind:class="{ 'is-active': workspace === 'email' }" x-bind:aria-pressed="workspace === 'email'"><span class="tools-nav-icon"><x-heroicon-o-envelope/></span><span><strong>Email kandidat</strong><small>Kirim akses dan pesan HR</small></span><span class="tools-nav-arrow" aria-hidden="true">→</span></button>
</nav>
<section class="panel tools-form-panel" x-show="workspace === 'deadlines'">
    <div class="eyebrow">Pengaturan jadwal</div><h2>Perpanjang tenggat massal</h2>
    <p class="muted">Berlaku untuk seluruh lamaran aktif pada posisi dan batch terpilih yang belum final. Jika satu tenggat tidak valid, seluruh perubahan dibatalkan.</p>
    <form wire:submit="bulkDeadlines" class="tools-form-grid">
        <label class="field">Posisi<select wire:model.live="bulk.position_id" required><option value="">Pilih posisi</option>@foreach($positions as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
        <label class="field">Batch / periode<select wire:model.live="bulk.recruitment_period_id" required><option value="">Pilih batch</option>@foreach($periods as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
        <label class="field">Jenis pengumpulan<select wire:model.live="bulk.type"><option value="technical_test">Tes teknis</option><option value="portfolio">Portofolio</option></select></label>
        <div class="tools-scope" role="status"><span class="tools-scope-count">{{ $bulkCount }}</span><span><strong>pengumpulan dapat diperpanjang</strong><small>Sesuai posisi, batch, dan jenis pengumpulan yang dipilih.</small></span></div>
        <label class="field tools-full">Tenggat baru<input type="datetime-local" wire:model="bulk.deadline" required></label>
        <small class="tools-form-hint">Pilih waktu di masa depan yang lebih lama daripada seluruh tenggat sebelumnya.</small>
        <label class="field tools-full">Alasan perubahan<textarea placeholder="Contoh: tambahan waktu pengerjaan sesuai keputusan tim HR" rows="3" wire:model="bulk.reason" minlength="5" maxlength="2000" required></textarea></label>
        <div class="tools-form-footer"><small>Perubahan dicatat dalam riwayat aktivitas.</small><button class="button primary" wire:loading.attr="disabled" wire:target="bulkDeadlines"><span wire:loading.remove wire:target="bulkDeadlines">Simpan perpanjangan</span><span wire:loading wire:target="bulkDeadlines">Menyimpan…</span><x-heroicon-o-arrow-right/></button></div>
    </form>
</section>
<section class="panel tools-form-panel" x-show="workspace === 'tasks'" x-ref="taskEditor" x-cloak>
    <div class="eyebrow">Penugasan kandidat</div><h2>Soal & jadwal mulai tes teknis</h2>
    <p class="muted">Satu PDF per posisi dan batch, maksimal 10 MB. Kandidat terdaftar dapat mengunduh sejak waktu mulai. Soal yang sudah dimulai tidak dapat diubah.</p>
    <form class="tools-form-grid" wire:submit="saveTechnicalTask" x-data="{ uploading: false }" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-cancel="uploading = false">
        <label class="field">Posisi<select wire:model="task.position_id" required><option value="">Pilih posisi</option>@foreach($positions as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
        <label class="field">Batch / periode<select wire:model="task.recruitment_period_id" required><option value="">Pilih batch</option>@foreach($periods as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
        <label class="field">Mulai pengerjaan<input type="datetime-local" wire:model="task.starts_at" required></label>
        <label class="field tools-upload">File soal PDF<input type="file" wire:model="taskFile" accept=".pdf,application/pdf"></label>
        <small class="tools-form-hint">Saat mengedit, file boleh dikosongkan untuk tetap menggunakan soal lama.</small>
        <p x-show="uploading" x-cloak role="status">Mengunggah soal…</p>
        <div class="tools-form-footer"><small>PDF hanya tersedia untuk kandidat pada posisi dan batch terkait.</small><button class="button primary" wire:loading.attr="disabled" x-bind:disabled="uploading"><span wire:loading.remove wire:target="saveTechnicalTask">Simpan soal & jadwal</span><span wire:loading wire:target="saveTechnicalTask">Menyimpan…</span><x-heroicon-o-arrow-right/></button></div>
    </form>
</section>
<section class="panel tools-table-panel" x-show="workspace === 'tasks'" x-cloak>
    <h2>Daftar soal teknis</h2>
    <div class="table-wrap"><table class="portal-table"><thead><tr><th>Posisi / batch</th><th>Mulai</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    @forelse($technicalTasks as $item)
        <tr><td><strong>{{ $item->position->name }}</strong><small>{{ $item->period->name }}</small></td><td>{{ $item->starts_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}</td><td><span class="badge {{ $item->starts_at->isFuture() ? 'draft' : 'clean' }}">{{ $item->starts_at->isFuture() ? 'Terjadwal' : 'Sudah dimulai' }}</span></td><td>@if($item->starts_at->isFuture())<button class="button small" wire:click="editTechnicalTask({{ $item->id }})" x-on:click="$refs.taskEditor.scrollIntoView({ block: 'start' })">Edit soal / jadwal</button>@endif</td></tr>
    @empty<tr><td colspan="4" class="tools-empty"><x-heroicon-o-document-plus/><strong>Belum ada soal tes teknis</strong><p>Unggah PDF dan tentukan jadwal mulai melalui formulir di atas.</p></td></tr>@endforelse
    </tbody></table></div>{{ $technicalTasks->links() }}
</section>
<section class="panel tools-email-panel" x-show="workspace === 'email'" x-cloak>
    <div class="eyebrow">Komunikasi kandidat</div><h2>Kirim akses akun & pesan HR</h2>
    @if(config('mail.default') === 'smtp' && config('mail.mailers.smtp.host') === 'sandbox.smtp.mailtrap.io')
        <div class="notice"><strong>Mode pengujian Mailtrap Sandbox.</strong> Email masuk ke inbox pengujian Mailtrap, bukan inbox kandidat. Gunakan SMTP pengiriman untuk mengirim email kepada kandidat.</div>
    @endif
    <div class="notice">Password lama tidak dapat dibaca. Tindakan ini membuat password sementara baru dan mengakhiri sesi login lama. Kandidat wajib menggantinya saat login pertama.</div>
    <form wire:submit="sendCandidateAccess" class="tools-email-grid"><div class="tools-recipients"><h3><span class="number">01</span>Pilih penerima</h3>
        <label class="field">Cari kandidat terdaftar<input type="search" wire:model.live.debounce.350ms="recipientSearch" maxlength="255" placeholder="Nama atau email"></label>
        <div class="candidate-picker-options tools-recipient-list">
        @forelse($recipients as $recipient)
            <label class="candidate-picker-option" wire:key="recipient-{{ $recipient->id }}"><input type="checkbox" wire:model.live="recipientIds" value="{{ $recipient->id }}"><span><strong>{{ $recipient->name }}</strong><small>{{ $recipient->email }}</small></span></label>
        @empty<p>Tidak ada kandidat aktif yang cocok.</p>@endforelse
        </div>
        <p>{{ count($recipientIds) }} kandidat dipilih. Maksimal 100 per pengiriman. Pilihan tetap tersimpan saat mencari kandidat lain.</p>
        <button type="button" class="button small" wire:click="$set('recipientIds', {{ $recipients->pluck('id')->toJson() }})">Pilih semua hasil ({{ $recipients->count() }})</button>
        <button type="button" class="button small" wire:click="$set('recipientIds', [])">Hapus pilihan</button>
        </div><div class="tools-message"><h3><span class="number">02</span>Tulis pesan HR</h3><label class="field">Pesan HR<textarea placeholder="Tuliskan informasi tes dan arahan untuk kandidat…" wire:model="hrMessage" required minlength="5" maxlength="5000" rows="5"></textarea></label>
        <label class="tools-confirm"><input type="checkbox" wire:model="confirmAccess" required><span>Saya memahami password kandidat terpilih akan diganti dan dikirim ke email masing-masing.</span></label>
        <div class="tools-send"><button class="button primary" wire:loading.attr="disabled" wire:target="sendCandidateAccess"><x-heroicon-o-paper-airplane/><span wire:loading.remove wire:target="sendCandidateAccess">Buat password & kirim email</span><span wire:loading wire:target="sendCandidateAccess">Menyiapkan pengiriman…</span></button><small>Email dikirim terpisah kepada setiap penerima.</small></div></div>
    </form>
</section>
<section class="panel tools-table-panel" wire:poll.10s x-show="workspace === 'email'" x-cloak>
    <h2>Status email akses</h2>
    <p class="muted">Terkirim berarti diterima layanan email, bukan konfirmasi masuk inbox. Jika kedaluwarsa atau dibatalkan, buat pengiriman baru.</p>
    <div class="table-wrap"><table class="portal-table"><thead><tr><th>Penerima</th><th>Status</th><th>Percobaan</th><th>Aksi</th></tr></thead><tbody>
    @forelse($accessDeliveries as $delivery)
        <tr><td>{{ $delivery->email }}</td><td><span class="badge {{ match($delivery->status) { 'sent' => 'clean', 'failed' => 'failed', 'pending', 'retrying', 'processing' => 'pending', default => '' } }}">{{ ['pending'=>'Menunggu antrean','processing'=>'Mengirim','retrying'=>'Mencoba ulang','sent'=>'Terkirim','failed'=>'Gagal','cancelled'=>'Dibatalkan'][$delivery->status] ?? $delivery->status }}</span></td><td>{{ $delivery->attempts }}</td><td>@if($delivery->status === 'failed' && $delivery->expires_at->isFuture())<button class="button small" wire:click="retryAccess({{ $delivery->id }})" wire:loading.attr="disabled">Coba kirim ulang</button>@endif</td></tr>
    @empty<tr><td colspan="4" class="tools-empty"><x-heroicon-o-envelope/><strong>Belum ada pengiriman akses</strong><p>Status email akan muncul setelah Anda mengirim akses kandidat.</p></td></tr>@endforelse
    </tbody></table></div>{{ $accessDeliveries->links() }}
</section>

</div>
