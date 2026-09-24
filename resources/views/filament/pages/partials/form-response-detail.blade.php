@php
    $latest = $selectedResponse->revisions->sortByDesc('number')->first();
    $displayName = $latest?->name ?? $selectedResponse->name;
    $timezone = App\Models\AppSetting::valueFor('timezone');
@endphp
<div class="response-detail-nav"><button class="link-button" wire:click="navigate('responses')"><x-heroicon-o-arrow-left />Kembali ke daftar</button><span class="badge {{ $selectedResponse->status }}">{{ $selectedResponse->status === 'submitted' ? 'Terkirim' : 'Revisi diminta' }}</span></div>
<section class="panel response-profile">
    <div class="response-profile-identity"><span class="response-avatar large" aria-hidden="true">{{ mb_strtoupper(mb_substr($displayName, 0, 1)) }}</span><div><span class="eyebrow">Respons kandidat</span><h2>{{ $displayName }}</h2><p>{{ $selectedResponse->email }}</p><span class="response-verified"><x-heroicon-o-check-badge />Email terverifikasi</span></div></div>
    <dl class="response-profile-meta"><div><dt>Posisi yang dilamar</dt><dd>{{ $selectedResponse->intake->position->name }}</dd></div><div><dt>Periode rekrutmen</dt><dd>{{ $selectedResponse->intake->period->name }}</dd></div><div><dt>Formulir</dt><dd>{{ $selectedResponse->intake->version->title }} <small>· v{{ $selectedResponse->intake->version->number }}</small></dd></div><div><dt>Pengiriman terakhir</dt><dd>{{ $latest?->submitted_at->timezone($timezone)->format('d M Y, H:i') ?? '—' }} <small>{{ $timezone }}</small></dd></div></dl>
</section>
<div class="response-detail-grid">
    <div class="response-detail-main">
        @if($selectedResponse->status === 'revision')<div class="notice response-revision-notice"><strong>Menunggu perbaikan kandidat</strong><p>{{ $selectedResponse->revision_note }}</p><small>Jawaban di bawah adalah pengiriman final terakhir.</small></div>@endif
        @if($latest)@include('forms.answers', ['fields'=>$selectedResponse->intake->version->fields,'answers'=>$latest->answers,'documents'=>$selectedResponse->documents->whereIn('id',$latest->document_ids),'name'=>$latest->name,'email'=>$selectedResponse->email])@endif
        <section class="panel response-history"><div class="row"><h2>Riwayat pengiriman</h2><span class="badge">{{ $selectedResponse->revisions->count() }} pengiriman</span></div>
            @foreach($selectedResponse->revisions->sortByDesc('number') as $revision)
                <details class="response-history-item"><summary><span><strong>Pengiriman {{ $revision->number }}</strong><small>{{ $revision->submitted_at->timezone($timezone)->format('d M Y, H:i') }}</small></span><span class="response-history-tag">{{ $loop->first ? 'Terbaru' : 'Lihat jawaban' }}</span></summary>
                    @include('forms.answers', ['fields'=>$selectedResponse->intake->version->fields,'answers'=>$revision->answers,'documents'=>$selectedResponse->documents->whereIn('id',$revision->document_ids),'name'=>$revision->name,'email'=>$selectedResponse->email])
                </details>
            @endforeach
        </section>
    </div>
    <aside class="response-detail-aside" aria-label="Tindak lanjut kandidat">
        <section class="panel response-account-panel"><div class="response-panel-title"><x-heroicon-o-user-circle /><h2>Akun kandidat</h2></div><span class="badge {{ $selectedResponse->user_id ? 'submitted' : 'pending' }}">{{ $selectedResponse->user_id ? 'Akun terhubung' : 'Belum terhubung' }}</span>
            @if($selectedResponse->user_id)<p>Data dan dokumen terhubung ke akun kandidat #{{ $selectedResponse->user_id }}.</p>@else<p>Hubungkan respons ini untuk melanjutkan proses rekrutmen melalui portal.</p>@endif
            @if($selectedResponse->activation_pending)<div class="response-aside-note">Email akun berisi tautan masuk dan password sementara. Kandidat wajib mengganti password saat login pertama.</div><button class="button response-full-button" wire:click="sendAccountEmail" wire:loading.attr="disabled"><x-heroicon-o-envelope />Kirim ulang email akun kandidat</button>@endif
            <div class="response-reference"><span>Nomor referensi</span><code>{{ $selectedResponse->reference }}</code></div>
        </section>
        @if(!$selectedResponse->user_id && $selectedResponse->status === 'submitted')
            @php($existingUser = App\Models\User::whereRaw('LOWER(email) = ?', [$selectedResponse->email])->first())
            <form wire:submit="link" class="panel response-link-panel"><h2>{{ $existingUser ? 'Hubungkan akun' : 'Buat akun & hubungkan data' }}</h2><p class="muted">{{ $existingUser ? 'Akun tersedia. Profil dan password lama tetap digunakan.' : 'Akun baru dan email akses akan dibuat untuk kandidat ini.' }}</p><div class="response-aside-note">Tenggat berlaku untuk lamaran baru. Jika lamaran pada posisi dan periode ini sudah aktif, tenggat sebelumnya dipertahankan.</div>
                <label class="field">Tenggat portofolio<input type="datetime-local" wire:model="application.portfolio_deadline"></label>
                <label class="field">Tenggat tes teknis<input type="datetime-local" wire:model="application.test_deadline"></label>
                <label class="field">Label tes<input wire:model="application.task_label"></label>
                <label class="field">Instruksi tes<textarea wire:model="application.instructions" rows="3"></textarea></label>
                <small>Zona waktu: {{ $timezone }}</small>
                <label class="form-check"><input type="checkbox" wire:model="confirmLink"> Saya sudah memeriksa email, posisi, dan periode tujuan penghubungan.</label>
                <button class="button primary response-full-button" wire:loading.attr="disabled">{{ $existingUser ? 'Hubungkan respons' : 'Buat akun & hubungkan' }}</button>
            </form>
        @endif
        @if($selectedResponse->status === 'submitted')
            <details class="panel response-disclosure response-revision-panel"><summary><span class="response-disclosure-title"><x-heroicon-o-pencil-square /><span><strong>Minta perbaikan</strong><small>Buka kembali pengisian kandidat</small></span></span><x-heroicon-o-chevron-down class="response-chevron" /></summary>
                <form wire:submit="requestRevision" class="response-disclosure-body"><label class="field">Catatan untuk kandidat<textarea wire:model="revisionNote" required minlength="5" placeholder="Jelaskan data yang perlu diperbaiki"></textarea></label><label class="field">Tenggat revisi baru<input type="datetime-local" wire:model="revisionDeadline" required></label><button class="button response-full-button" wire:loading.attr="disabled">Buka revisi</button></form>
            </details>
        @endif
    </aside>
</div>
