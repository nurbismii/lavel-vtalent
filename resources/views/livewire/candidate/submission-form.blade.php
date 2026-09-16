<div x-data="{ dirty: $wire.entangle('dirty'), uploading: false, progress: 0 }" x-on:beforeunload.window="if(dirty || uploading){ $event.preventDefault(); $event.returnValue=''; }" wire:poll.8s>
    <div class="heading">
        <div><a href="{{ route('candidate.dashboard') }}">Beranda /</a>
            <div class="eyebrow" style="margin-top:18px">Pengumpulan dokumen</div>
            <h1>{{ $submission->type->label() }} Anda</h1>
            <p class="muted">{{ $submission->application->position->name }} · {{ $submission->application->period->name }}</p>
        </div><span class="badge {{ $submission->status->value }}">{{ $submission->status->label() }}</span>
    </div>
    <x-errors />@if($feedback)<div class="success" role="status">{{ $feedback }}</div>@endif
    @if($submission->status===App\Enums\SubmissionStatus::Exempt)<section class="panel">
        <h2>Portofolio tidak diwajibkan.</h2>
        <p>{{ $submission->administrative_reason }}</p><small>Status ini ditetapkan oleh HR dan bukan pengiriman final oleh kandidat.</small>
    </section>
    @elseif($version?->status==='final')
    <section class="panel receipt">
        <div class="icon-box" style="margin:0 auto 20px"><x-heroicon-o-check-badge /></div>
        <div class="eyebrow">Tanda terima pengumpulan</div>
        <h1>{{ $submission->type->label() }} berhasil dikumpulkan.</h1>
        <p class="muted">Versi final tersimpan dan terkunci.</p>
        <p class="receipt-code">{{ $version->receipt }}</p>
        <p>{{ $version->submitted_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y, H:i T') }} · Versi {{ $version->number }}</p>@foreach($version->attachments as $attachment)<div class="file-row">
            <div class="file-info"><a href="{{ route('files.download',$attachment) }}">{{ $attachment->file->original_name }}</a>@if($attachment->description)<p>{{ $attachment->description }}</p>@endif</div><x-file-actions :attachment="$attachment" />
        </div>@endforeach @foreach($version->links??[] as $link)<p><a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer">{{ $link['label'] }} ↗</a></p>@endforeach<p style="white-space:pre-wrap;margin-top:20px">{{ $version->notes }}</p>
        <hr><a class="button" href="{{ route('candidate.history') }}">Lihat seluruh riwayat</a>
    </section>
    @else
    @if($submission->status===App\Enums\SubmissionStatus::Revision)<div class="notice"><strong>{{ $submission->editable()?'HR membuka revisi':'Revisi belum dikumpulkan' }}</strong>
        <p>{{ $submission->administrative_reason }}</p><a href="{{ route('candidate.history') }}">Lihat versi final sebelumnya →</a>
    </div>@endif
    @if(!$submission->editable())<div class="errors">Pengumpulan terkunci karena tenggat terlewati atau lamaran diarsipkan. Data tersimpan tetap dapat dilihat. Hubungi HR untuk tindak lanjut.</div>@endif
    <div class="form-grid">
        <div class="stack">
            <section class="panel">
                <h2><span class="number">01</span>{{ $submission->type===App\Enums\SubmissionType::Portfolio?'Dokumen utama & bukti':'File hasil pekerjaan' }}</h2>@if($submission->task_label)<h3>{{ $submission->task_label }}</h3>
                <p style="white-space:pre-wrap">{{ $submission->instructions }}</p>@endif<p class="muted">{{ $submission->type===App\Enums\SubmissionType::Portfolio?'Dokumen portofolio utama wajib berupa satu file PDF agar tata letak tetap konsisten. Ekspor dokumen Word ke PDF sebelum mengunggah. Bukti tambahan opsional, tidak perlu diunggah ulang jika sudah ada dalam dokumen utama.':'Minimal satu file hasil pekerjaan wajib saat final. Pengerjaan tes dilakukan di luar portal.' }}</p>
                @foreach($attachments as $index=>$item)@php($file=$selected->get($item['id']))@if($file)<div wire:key="selected-{{ $file->id }}">
                    <div class="file-row"><span class="file-tag">{{ strtoupper(pathinfo($file->original_name,PATHINFO_EXTENSION)) }}</span>
                        <div class="file-info"><strong>{{ $file->original_name }}</strong><br><small>{{ number_format($file->size/1048576,2) }} MB · {{ $file->purpose==='portfolio_main'?'Dokumen utama':($file->purpose==='portfolio_evidence'?'Bukti tambahan':'Hasil tes') }}</small></div><span class="badge {{ $file->scan_status->value }}">{{ $file->scan_status->label() }}</span>@if($submission->editable())<button class="link-button" wire:click="removeFile({{ $file->id }})">Lepaskan</button>@endif
                    </div>@if($file->purpose==='portfolio_evidence')<label class="field">Keterangan bukti · Wajib saat final<input wire:model="attachments.{{ $index }}.description" maxlength="500" placeholder="Contoh: Screenshot proyek pada halaman 5" @disabled(!$submission->editable())></label>@error('attachments.'.$index.'.description')<span class="field-error">{{ $message }}</span>@enderror @endif
                </div>@endif @endforeach
                @if(!$attachments)<div class="notice">Belum ada file yang dipilih untuk draf ini.</div>@endif
                @if($submission->editable())<div class="upload-zone" x-on:livewire-upload-start="uploading=true;progress=0" x-on:livewire-upload-finish="uploading=false" x-on:livewire-upload-error="uploading=false" x-on:livewire-upload-cancel="uploading=false" x-on:livewire-upload-progress="progress=$event.detail.progress"><label class="field">Jenis dokumen<select wire:model.live="purpose">@if($submission->type===App\Enums\SubmissionType::Portfolio)<option value="portfolio_main">Dokumen portofolio utama</option>
                            <option value="portfolio_evidence">Bukti tambahan (opsional)</option>@else<option value="technical_result">Hasil tes teknis</option>@endif
                        </select></label>@php($limits=App\Models\AppSetting::valueFor('uploads')[$purpose]??null)@if($limits)<small>{{ strtoupper(implode(', ',$limits['extensions'])) }} · Maks. {{ $limits['max_mb'] }} MB/file · {{ $limits['max_files'] }} file</small>@endif<label class="field">Pilih file<input type="file" wire:model="upload" @if($limits) accept="{{ implode(',',array_map(fn($e)=>'.'.$e,$limits['extensions'])) }}" @endif></label>@error('upload')<span class="field-error" role="alert">{{ $message }}</span>@enderror<div x-show="uploading" x-cloak><progress max="100" x-bind:value="progress" aria-label="Progress unggah"></progress><span x-text="progress+'%'"></span><button class="link-button" type="button" x-on:click="$wire.cancelUpload('upload')">Batalkan</button></div><small wire:loading wire:target="upload">Menyimpan unggahan…</small></div>@endif
                @foreach($staged as $file)<div class="file-row" wire:key="staged-{{ $file->id }}">
                    <div class="file-info"><strong>{{ $file->original_name }}</strong><br><span class="badge {{ $file->scan_status->value }}">{{ $file->scan_status->label() }}</span>@if(config('submissions.scan_enabled') && $file->scan_status===App\Enums\ScanStatus::Pending)<p class="muted" role="status">File sudah tersimpan dan menunggu pemindaian antivirus. Setelah lolos, pilih Gunakan file lalu Periksa &amp; kirim final. Jika status tidak berubah, hubungi HR.</p>@endif@if($file->scan_message && ! $file->scan_status->available())<p class="field-error">{{ $file->scan_message }}</p>@endif</div>@if($submission->editable())@if($file->scan_status->available())<button class="button small" wire:click="useFile({{ $file->id }})">Gunakan file</button>@elseif($file->scan_status===App\Enums\ScanStatus::Failed)<button class="button small" wire:click="retry({{ $file->id }})">Coba lagi</button>@endif<button class="link-button" wire:click="discard({{ $file->id }})">Hapus unggahan</button>@endif
                </div>@endforeach
            </section>
            <section class="panel">
                <div class="row">
                    <h2><span class="number">02</span>Tautan & catatan</h2><span class="badge">Opsional</span>
                </div>@foreach($links as $index=>$link)<div wire:key="link-{{ $index }}"><label class="field">Label tautan<input wire:model="links.{{ $index }}.label" maxlength="120" @disabled(!$submission->editable())></label>@error('links.'.$index.'.label')<span class="field-error">{{ $message }}</span>@enderror<label class="field">URL HTTP/HTTPS<input type="url" wire:model="links.{{ $index }}.url" @disabled(!$submission->editable())></label>@error('links.'.$index.'.url')<span class="field-error">{{ $message }}</span>@enderror @if($submission->editable())<button class="link-button" wire:click="removeLink({{ $index }})">Hapus tautan</button>@endif</div>@endforeach @if($submission->editable() && count($links)<5)<button class="button" wire:click="addLink">+ Tambah tautan</button>@endif<label class="field">Catatan<textarea wire:model="notes" maxlength="5000" @disabled(!$submission->editable())></textarea></label><small>Maksimal 5.000 karakter</small>
            </section>
            @if($submission->editable())<div class="row"><small>{{ $dirty?'Perubahan belum disimpan':'Terakhir disimpan: '.($version?->updated_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M, H:i T')??'—') }}</small>
                <div class="row"><button class="button" wire:click="save" wire:loading.attr="disabled" x-bind:disabled="uploading">Simpan draf</button><button class="button primary" wire:click="review" wire:loading.attr="disabled" x-bind:disabled="uploading">Periksa & kirim final</button></div>
            </div><span wire:loading wire:target="save,review,finalize" role="status">Memproses pengumpulan…</span>@endif
            @if($confirming)<section class="panel" role="region" aria-label="Konfirmasi pengiriman final">
                <h2>Siap mengirim final?</h2>
                <p>{{ count($attachments) }} file dipilih. @if(config('submissions.scan_enabled'))Semua file harus lolos pemeriksaan keamanan.@else Periksa kembali dokumen sebelum mengirim final. Pemindaian antivirus tidak diaktifkan.@endif</p>
                <p>Setelah dikirim, dokumen terkunci. Perubahan hanya tersedia jika HR membuka revisi.</p>
                <div class="row"><button class="button" wire:click="$set('confirming',false)">Kembali periksa</button><button class="button primary" wire:click="finalize" wire:loading.attr="disabled">Kirim final</button></div>
            </section>@endif
        </div>
        <aside class="panel">
            <div class="eyebrow">Ringkasan pengumpulan</div>
            <h3 style="margin-top:16px">{{ $submission->application->position->name }}</h3><small>{{ $submission->application->period->name }}</small>
            <hr><small>Tenggat</small>
            <p><strong>{{ $submission->localDeadline() }}</strong></p><small>Kuota termasuk histori</small>
            <p>{{ number_format($submission->application->files()->sum('size')/1048576,1) }} / {{ App\Models\AppSetting::valueFor('quota_mb') }} MB</p>
            <hr><small>Samarkan informasi rahasia perusahaan atau klien. Memulai upload sebelum tenggat tidak menjamin finalisasi diterima setelah tenggat.</small>
        </aside>
    </div>@endif
</div>
