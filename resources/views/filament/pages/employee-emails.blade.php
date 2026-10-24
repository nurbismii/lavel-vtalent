<x-filament-panels::page>
    <div class="portal-admin recruitment-tools employee-emails" x-data="{ uploadingAttachment: false }">
        <header class="tools-heading">
            <div>
                <div class="eyebrow">Komunikasi kandidat</div>
                <h1>Blast Email Kandidat</h1>
                <p>Sampaikan informasi perusahaan melalui email dengan format resmi Tim HR.</p>
            </div>
            <span class="tools-timezone"><x-heroicon-o-envelope /> Maksimal 100 penerima per pengiriman</span>
        </header>

        @if($feedback)
            <div class="notice" role="status">{{ $feedback }}</div>
        @endif
        <x-errors />
        @if(config('mail.default') === 'smtp' && config('mail.mailers.smtp.host') === 'sandbox.smtp.mailtrap.io')
            <div class="notice"><strong>Mode pengujian Mailtrap Sandbox.</strong> Email masuk ke inbox pengujian Mailtrap. Gunakan SMTP pengiriman untuk mengirim ke inbox kandidat.</div>
        @endif

        <section class="panel">
            <div class="eyebrow">Email baru</div>
            <h2>Siapkan informasi kandidat</h2>
            <p class="muted">Paste email penerima, isi subjek, lalu susun pesan. Periksa pratinjau sebelum mengirim.</p>
            <form wire:submit="previewEmail" class="broadcast-compose" x-on:form-processing-started="uploadingAttachment = true" x-on:form-processing-finished="uploadingAttachment = false">
                <div class="broadcast-fields">{{ $this->form }}</div>
                <aside class="broadcast-guide">
                    <div class="broadcast-guide-icon"><x-heroicon-o-document-duplicate /></div>
                    <h3>Dari Excel ke email</h3>
                    <ol>
                        <li>Blok dan copy kolom email tanpa judul kolom.</li>
                        <li>Paste ke kolom Email penerima.</li>
                        <li>Tulis pesan dan periksa pratinjaunya.</li>
                    </ol>
                    <p>Baris baru, tab, spasi, koma, dan titik koma dapat digunakan sebagai pemisah. Email duplikat dihitung satu kali.</p>
                    <div class="broadcast-privacy"><x-heroicon-o-lock-closed /><span>Email dikirim satu per satu. Penerima lain tidak terlihat.</span></div>
                    <button type="submit" class="button primary" x-bind:disabled="uploadingAttachment" wire:loading.attr="disabled" wire:target="previewEmail,send">
                        <x-heroicon-o-eye /><span wire:loading.remove wire:target="previewEmail">Periksa pratinjau</span><span wire:loading wire:target="previewEmail">Menyiapkan…</span>
                    </button>
                </aside>
            </form>
        </section>

        @if($preview)
            <section class="panel broadcast-preview" wire:key="email-preview">
                <div class="tools-status-heading">
                    <div><div class="eyebrow">Periksa sebelum kirim</div><h2>Pratinjau email</h2><p class="muted">{{ count($preview['emails']) }} alamat email unik siap menerima informasi ini.</p></div>
                    <button type="button" class="button" wire:click="editMessage">Kembali mengedit</button>
                </div>
                <details class="broadcast-recipients">
                    <summary>Lihat daftar penerima ({{ count($preview['emails']) }})</summary>
                    <div>@foreach($preview['emails'] as $email)<span>{{ $email }}</span>@endforeach</div>
                </details>
                @foreach($preview['attachment'] as $attachment)
                    <p class="muted"><strong>Lampiran:</strong> {{ $attachment->getClientOriginalName() }}</p>
                @endforeach
                <iframe title="Pratinjau email informasi kandidat" sandbox="" srcdoc="{{ view('emails.employee-information', ['subject' => $preview['subject'], 'body' => $preview['body']])->render() }}" class="broadcast-preview-frame"></iframe>
                <div class="tools-form-footer">
                    <small>Subjek, isi pesan, lampiran, dan seluruh penerima sudah saya periksa.</small>
                    <button type="button" class="button primary" wire:click="send" x-bind:disabled="uploadingAttachment" wire:loading.attr="disabled" wire:target="send,previewEmail">
                        <x-heroicon-o-paper-airplane /><span wire:loading.remove wire:target="send">Kirim ke {{ count($preview['emails']) }} penerima</span><span wire:loading wire:target="send">Menyiapkan pengiriman…</span>
                    </button>
                </div>
            </section>
        @endif

        <section class="panel tools-table-panel" wire:poll.10s>
            <div class="eyebrow">Riwayat komunikasi</div>
            <h2>Riwayat blast email</h2>
            <p class="muted">Pilih pengiriman untuk melihat status masing-masing penerima.</p>
            <div class="table-wrap">
                <table class="portal-table">
                    <thead><tr><th>Subjek</th><th>Waktu dibuat</th><th>Progres</th><th>Aksi</th></tr></thead>
                    <tbody>
                        @forelse($broadcasts as $broadcast)
                            <tr wire:key="broadcast-{{ $broadcast->id }}" @class(['broadcast-selected' => $broadcastId === $broadcast->id])>
                                <td class="broadcast-subject"><strong>{{ $broadcast->subject }}</strong><small>{{ $broadcast->deliveries_count }} penerima</small></td>
                                <td>{{ $broadcast->created_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i') }}</td>
                                <td>{{ $broadcast->sent_count }} terkirim
                                    @if($broadcast->failed_count)<small>{{ $broadcast->failed_count }} gagal</small>@endif</td>
                                <td><button type="button" class="button small" wire:click="selectBroadcast({{ $broadcast->id }})" wire:loading.attr="disabled">{{ $broadcastId === $broadcast->id ? 'Sedang dilihat' : 'Lihat status' }}</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="tools-empty"><x-heroicon-o-envelope /><strong>Belum ada blast email</strong><p>Pengiriman pertama Anda akan tercatat di sini.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $broadcasts->links() }}
        </section>

        @if($selectedBroadcast)
            <section class="panel tools-table-panel" wire:poll.10s>
                <div class="tools-status-heading">
                    <div><div class="eyebrow">Detail pengiriman</div><h2>Status email penerima</h2><p class="muted">{{ $selectedBroadcast->subject }}</p></div>
                    <label class="field">Status pengiriman<select wire:model.live="statusFilter"><option value="">Semua status</option><option value="pending">Menunggu antrean</option><option value="processing">Mengirim</option><option value="retrying">Mencoba ulang</option><option value="sent">Terkirim</option><option value="failed">Gagal</option></select></label>
                </div>
                <p class="muted">Terkirim berarti pesan diterima layanan email. Status ini belum memastikan pesan masuk inbox atau dibaca.</p>
                @foreach($selectedBroadcast->attachmentFiles() as $attachment)
                    <p class="muted"><strong>Lampiran:</strong> {{ $attachment['name'] }}</p>
                @endforeach
                <div class="table-wrap">
                    <table class="portal-table">
                        <thead><tr><th>Email penerima</th><th>Status / keterangan</th><th>Percobaan</th><th>Aksi</th></tr></thead>
                        <tbody>
                            @forelse($deliveries as $delivery)
                                <tr wire:key="broadcast-delivery-{{ $delivery->id }}">
                                    <td>{{ $delivery->email }}@if($delivery->sent_at)<small>{{ $delivery->sent_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i') }}</small>@endif</td>
                                    <td class="broadcast-delivery-note"><span class="badge {{ match($delivery->status) { 'sent' => 'clean', 'failed' => 'failed', default => 'pending' } }}">{{ ['pending' => 'Menunggu antrean', 'processing' => 'Mengirim', 'retrying' => 'Mencoba ulang', 'sent' => 'Terkirim', 'failed' => 'Gagal'][$delivery->status] ?? $delivery->status }}</span>@if($delivery->last_error)<small>{{ $delivery->last_error }}</small>@endif</td>
                                    <td>{{ $delivery->attempts }}</td>
                                    <td>@if($delivery->status === 'failed')<button type="button" class="button small" wire:click="retryDelivery({{ $delivery->id }})" wire:loading.attr="disabled">Coba ulang</button>@else<span class="muted">—</span>@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="tools-empty"><strong>Tidak ada email dengan status ini</strong><p>Pilih status lain atau tampilkan semua status.</p></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $deliveries->links() }}
            </section>
        @endif
    </div>
</x-filament-panels::page>

