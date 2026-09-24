<details class="panel response-disclosure response-bulk-panel" x-data="{ open: @js((bool) $bulkResponseIds) }" x-bind:open="open" x-on:toggle="open = $el.open" x-on:open-form-bulk.window="open = true; $nextTick(() => $el.scrollIntoView({ block: 'start', behavior: 'smooth' }))">
    <summary><span class="response-disclosure-title"><x-heroicon-o-envelope /><span><strong>Pengiriman email massal</strong><small>Hubungkan akun dan kirim akses ke kandidat sesuai filter</small></span></span><x-heroicon-o-chevron-down class="response-chevron" /></summary>
    <div class="response-disclosure-body">
    @if($bulkAvailable)
        <div class="response-aside-note">Hanya respons final terverifikasi yang belum terhubung atau masuk antrean. Akun baru menerima akses login; akun lama tetap menggunakan password sebelumnya.</div>
        <div class="response-section-label"><h3>Pengaturan lamaran baru</h3><small>Zona waktu: {{ App\Models\AppSetting::valueFor('timezone') }}</small></div>
        <p class="muted">Lamaran aktif pada posisi dan periode yang sama tetap memakai tenggat sebelumnya.</p>
        <div class="two-col">
            <label class="field">Tenggat portofolio<input type="datetime-local" wire:model="application.portfolio_deadline"></label>
            <label class="field">Tenggat tes teknis<input type="datetime-local" wire:model="application.test_deadline"></label>
            <label class="field">Label tes<input wire:model="application.task_label" maxlength="255"></label>
            <label class="field">Instruksi tes<textarea wire:model="application.instructions" maxlength="5000"></textarea></label>
        </div>
        <div class="form-actions"><button class="button primary" wire:click="previewBulkAccounts" wire:loading.attr="disabled">Pratinjau semua penerima sesuai filter<x-heroicon-o-arrow-right /></button></div>
        @if($bulkResponseIds)
            <div class="notice response-bulk-confirmation">
                <h3>Konfirmasi {{ count($bulkResponseIds) }} respons</h3>
                <dl class="response-confirmation-dates"><div><dt>Tenggat portofolio</dt><dd>{{ \Carbon\Carbon::parse($bulkApplication['portfolio_deadline'])->format('d M Y, H:i') }}</dd></div><div><dt>Tenggat tes</dt><dd>{{ \Carbon\Carbon::parse($bulkApplication['test_deadline'])->format('d M Y, H:i') }}</dd></div><div><dt>Label tes</dt><dd>{{ $bulkApplication['task_label'] }}</dd></div></dl>
                <p>{{ $bulkApplication['instructions'] ?? '' }}</p>
                <p>Daftar penerima dan tenggat dikunci saat pratinjau. Buat pratinjau ulang jika Anda mengubah pengaturan. {{ count($bulkResponseIds) > 20 ? 'Berikut 20 penerima pertama.' : '' }}</p>
                <div class="table-wrap response-table-wrap"><table class="portal-table response-table"><thead><tr><th>Kandidat</th><th>Tujuan</th></tr></thead><tbody>
                    @foreach($bulkPreview as $recipient)<tr><td data-label="Kandidat"><strong>{{ $recipient->name }}</strong><small>{{ $recipient->email }}</small></td><td data-label="Tujuan">{{ $recipient->intake->position->name }}<small>{{ $recipient->intake->period->name }}</small></td></tr>@endforeach
                </tbody></table></div>
                <label class="form-check"><input type="checkbox" wire:model="confirmBulk"> Saya menyetujui pembuatan/penghubungan akun dan pengiriman email untuk {{ count($bulkResponseIds) }} respons ini.</label>
                <div class="form-actions"><button class="button primary" wire:click="sendBulkAccounts" wire:loading.attr="disabled">Hubungkan & kirim email massal</button><button class="button" wire:click="cancelBulkAccounts">Batal</button></div>
            </div>
        @endif
    @else
        <p class="notice">Pengiriman massal tersedia setelah migrasi fitur diterapkan oleh pengelola sistem.</p>
    @endif
    </div>
</details>
@if($bulkAvailable)
    <details class="panel response-disclosure response-delivery-panel" wire:poll.10s x-data="{ open: false }" x-bind:open="open" x-on:toggle="open = $el.open">
        <summary><span class="response-disclosure-title"><x-heroicon-o-paper-airplane /><span><strong>Status email massal</strong><small>{{ $dispatches->total() }} proses · Diperbarui otomatis</small></span></span><x-heroicon-o-chevron-down class="response-chevron" /></summary>
        <div class="response-disclosure-body"><p class="muted">Riwayat seluruh pengiriman, termasuk dari filter lain. Jika pembuatan lamaran gagal, perbarui tenggat di pengaturan email massal sebelum mencoba ulang.</p>
        @php($labels = ['pending'=>'Menunggu antrean', 'linked'=>'Akun terhubung, menunggu email', 'queued'=>'Email dalam antrean', 'processing'=>'Mengirim email', 'retrying'=>'Pengiriman dicoba ulang', 'sent'=>'Email terkirim', 'failed'=>'Gagal', 'cancelled'=>'Email dibatalkan/kedaluwarsa', 'skipped'=>'Dilewati'])
        <div class="table-wrap response-table-wrap"><table class="portal-table response-table"><thead><tr><th>Kandidat</th><th>Status</th><th>Tindakan</th></tr></thead><tbody>
            @forelse($dispatches as $dispatch)
                @php($deliveryStatus = $dispatch->deliveryStatus())
                <tr wire:key="dispatch-{{ $dispatch->id }}"><td data-label="Kandidat"><strong>{{ $dispatch->response->name }}</strong><small>{{ $dispatch->response->email }}</small></td><td data-label="Status"><span class="badge {{ $deliveryStatus === 'sent' ? 'submitted' : (in_array($deliveryStatus, ['failed','cancelled']) ? 'failed' : 'pending') }}">{{ $labels[$deliveryStatus] ?? $deliveryStatus }}</span>@if($dispatch->error)<small>{{ $dispatch->error }}</small>@endif</td><td><div class="response-toolbar"><button class="button small" wire:click="inspect({{ $dispatch->form_response_id }})">Lihat respons</button>@if(in_array($deliveryStatus, ['failed','cancelled']))<button class="button small" wire:click="retryBulkAccount({{ $dispatch->id }})" wire:loading.attr="disabled">Coba ulang</button>@endif</div></td></tr>
            @empty<tr><td colspan="3" class="response-empty"><x-heroicon-o-envelope /><h3>Belum ada pengiriman massal</h3><p>Status akan muncul setelah Anda mengirim email melalui panel di atas.</p></td></tr>@endforelse
        </tbody></table></div>@if($dispatches->hasPages())<div class="response-pagination">{{ $dispatches->links() }}</div>@endif
        </div>
    </details>
@endif
