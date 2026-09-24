@if($selectedResponse)
    @include('filament.pages.partials.form-response-detail')
@else
    <div class="response-metrics" aria-label="Ringkasan hasil filter">
        <div><span>Respons ditemukan</span><strong>{{ number_format($responseStats->total) }}</strong><small>Sesuai filter yang dipilih</small></div>
        <div><span>Sudah dikirim</span><strong>{{ number_format($responseStats->submitted ?? 0) }}</strong><small>Memiliki jawaban final</small></div>
        <div><span>Akun terhubung</span><strong>{{ number_format($responseStats->linked ?? 0) }}</strong><small>Data tersimpan di akun kandidat</small></div>
    </div>
    <section class="panel response-list-panel">
        <div class="response-list-heading">
            <div><h2>Respons masuk</h2><p class="muted">Periksa jawaban kandidat dan kelola tindak lanjutnya.</p></div>
            <div class="response-toolbar">
                <button class="button" wire:click="exportResponses" wire:loading.attr="disabled"><x-heroicon-o-arrow-down-tray />Ekspor Excel sesuai filter</button>
                @if($bulkAvailable)<button class="button primary" x-on:click="$dispatch('open-form-bulk')"><x-heroicon-o-envelope />Email massal</button>@endif
            </div>
        </div>
        <div class="response-filters">
            <label class="field response-search">Cari kandidat<input wire:model.live.debounce.400ms="search" placeholder="Nama atau alamat email" type="search"></label>
            <label class="field">Status pengisian<select wire:model.live="statusFilter"><option value="">Semua status</option><option value="draft">Draf</option><option value="submitted">Terkirim</option><option value="revision">Revisi</option></select></label>
            <label class="field">Status akun<select wire:model.live="linkedFilter"><option value="">Semua penghubungan</option><option value="linked">Terhubung</option><option value="unlinked">Belum terhubung</option></select></label>
            <label class="field">Posisi<select wire:model.live="positionFilter"><option value="">Semua posisi</option>@foreach($positions as $position)<option value="{{ $position->id }}">{{ $position->name }}</option>@endforeach</select></label>
            <label class="field">ID tautan<input type="number" min="1" wire:model.live="intakeFilter" placeholder="Semua tautan"></label>
        </div>
        <div class="response-results"><span><strong>{{ $responses->total() }}</strong> respons <span class="muted">· {{ $responses->firstItem() ?? 0 }}–{{ $responses->lastItem() ?? 0 }} ditampilkan</span></span><small>Ekspor dan email massal mengikuti semua halaman hasil filter.</small></div>
        <div class="table-wrap response-table-wrap">
            <table class="portal-table response-table"><thead><tr><th>Kandidat</th><th>Formulir & posisi</th><th>Status</th><th>Pengiriman terakhir</th><th><span class="applications-sr-only">Tindakan</span></th></tr></thead><tbody>
                @forelse($responses as $item)
                    @php($candidateName = $item->submitted_at ? ($item->latestRevision?->name ?? $item->name) : 'Draf privat')
                    <tr wire:key="response-row-{{ $item->id }}">
                        <td class="response-person"><div class="response-person-inner"><span class="response-avatar" aria-hidden="true">{{ $item->submitted_at ? mb_strtoupper(mb_substr($candidateName, 0, 1)) : '—' }}</span><div><strong>{{ $candidateName }}</strong><small>{{ $item->email }}</small></div></div></td>
                        <td data-label="Formulir & posisi"><strong class="response-form-title">{{ $item->intake->version->title }}</strong><small>Versi {{ $item->intake->version->number }} · {{ $item->intake->position->name }}</small><small>{{ $item->intake->period->name }}</small></td>
                        <td data-label="Status"><span class="badge {{ $item->status }}">{{ ['draft'=>'Draf','submitted'=>'Terkirim','revision'=>'Revisi diminta'][$item->status] }}</span><small class="response-link-state {{ $item->user_id ? 'is-linked' : '' }}">{{ $item->user_id ? 'Akun terhubung' : 'Belum terhubung' }}</small></td>
                        <td data-label="Pengiriman terakhir">@if($item->submitted_at)<span>{{ $item->submitted_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y') }}</span><small>{{ $item->submitted_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('H:i') }}</small>@else<span class="muted">Belum dikirim</span>@endif</td>
                        <td class="response-row-action">@if($item->submitted_at)<button class="button small" wire:click="inspect({{ $item->id }})">Periksa respons<x-heroicon-o-arrow-right /></button>@else<small>Menunggu kandidat</small>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="response-empty"><x-heroicon-o-inbox /><h3>Belum ada respons yang sesuai</h3><p>Ubah filter atau bagikan tautan formulir untuk menerima jawaban kandidat.</p></td></tr>
                @endforelse
            </tbody></table>
        </div>
        @if($responses->hasPages())<div class="response-pagination">{{ $responses->links() }}</div>@endif
    </section>
    @include('filament.pages.partials.form-response-tools')
@endif
