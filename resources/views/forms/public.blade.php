<x-layouts.form :title="$intake->version->title">
    <div class="heading"><div><div class="eyebrow">{{ $intake->position->name }} · {{ $intake->period->name }}</div><h1>{{ $intake->version->title }}</h1><p class="muted">{{ $intake->version->description }}</p></div><span class="badge">Versi {{ $intake->version->number }}</span></div>
    @php($deadline = $response?->status === 'revision' ? $response->revision_deadline : $intake->deadline)
    @if($deadline)<p class="muted">Batas pengisian: {{ $deadline->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i') }} ({{ App\Models\AppSetting::valueFor('timezone') }})</p>@endif
    @if($response && $response->status === 'submitted')
        <section class="panel receipt"><span class="badge submitted">Terkirim</span><h2>Terima kasih, {{ $response->name }}</h2><p>Jawaban Anda telah diterima. Pembuatan akun akan diproses oleh HR.</p><p class="receipt-code">{{ $response->reference }}</p><small>{{ $response->submitted_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i') }}</small></section>
        @include('forms.answers', ['fields' => $intake->version->fields, 'answers' => $response->answers, 'documents' => $response->documents->where('selected', true), 'name' => $response->name, 'email' => $response->email])
    @elseif($response && ! $response->editable())
        <div class="notice">Pengisian terkunci atau tenggat telah berakhir. Hubungi HR jika perlu perbaikan.</div>
        @include('forms.answers', ['fields' => $intake->version->fields, 'answers' => $response->answers, 'documents' => $response->documents->where('selected', true), 'name' => $response->name, 'email' => $response->email])
    @else
        @if(!$response)<div class="notice">Isi tanpa membuat akun. Verifikasi email untuk menyimpan draf, melanjutkan di perangkat lain, dan mengunggah dokumen. Jika sudah pernah mengisi, cukup masukkan email yang sama untuk meminta akses kembali.</div>@endif
        @if(!$intake->open() && !$response)<div class="notice">Penerimaan baru sedang ditutup. Anda masih dapat meminta akses ke pengisian sebelumnya.</div>@endif
        @if($response?->status === 'revision')<div class="notice"><strong>HR meminta perbaikan</strong><p>{{ $response->revision_note }}</p></div>@endif
        <form id="candidate-answer-form" data-track-changes method="post" action="{{ $response ? route('forms.save', $response->reference) : route('forms.access', $intake->slug) }}" class="panel stack">
            @csrf
            @if($response)<input type="hidden" name="lock_version" value="{{ $response->lock_version }}">@endif
            <section><span class="eyebrow">Identitas kandidat</span><h2>Mulai dari diri Anda</h2><label class="field">Nama lengkap *<input name="name" value="{{ old('name', $response?->name ?? '') }}" maxlength="255" autocomplete="name"></label><label class="field">Email utama *<input name="email" type="email" value="{{ old('email', $response?->email ?? '') }}" required maxlength="255" autocomplete="email" @readonly($response)></label>@if($response)<small>Email terverifikasi. Untuk menggunakan email lain sebelum pengiriman, buka tautan formulir publik dan verifikasi email tersebut.</small>@endif</section>
            @if(!$response)<div class="form-honeypot" aria-hidden="true"><label>Situs pribadi<input name="website" tabindex="-1" autocomplete="off"></label></div>@endif
            @include('forms.fields', ['fields' => $intake->version->fields, 'answers' => $response?->answers ?? [], 'disabled' => !$response && !$intake->open()])
            <label class="form-check"><input type="checkbox" name="consent" value="1" @checked(old('consent'))> <span>Saya menyatakan data benar dan menyetujui pemrosesan data untuk rekrutmen sesuai <a href="{{ route('privacy') }}" target="_blank" rel="noopener">informasi privasi</a>.</span></label>
            <div class="form-actions">@if($response)<button class="button" name="action" value="save">Simpan draf</button><button class="button primary" name="action" value="review">Tinjau & kirim</button>@else<button class="button primary">Kirim tautan verifikasi email</button>@endif<span data-dirty-status class="muted" role="status"></span></div>
        </form>
        @if($response && collect($intake->version->fields)->contains('type','file'))
            <section class="panel form-documents"><div class="row"><div><span class="eyebrow">Dokumen pendukung</span><h2>Unggah dokumen Anda</h2></div><a class="button small" href="{{ route('forms.response', $response->reference) }}">Perbarui status</a></div><p class="muted">Simpan perubahan jawaban sebelum mengelola file. Dokumen tersimpan privat. Melepas file tidak menghapus histori pengiriman sebelumnya.</p>
            @foreach($intake->version->fields as $field)
                @if($field['type'] !== 'file') @continue @endif
                <div class="upload-zone"><h3>{{ $field['label'] }}{{ $field['required'] ? ' *' : '' }}</h3><small>{{ strtoupper(implode(', ', $field['extensions'])) }} · Maks. {{ $field['max_mb'] }} MB/file · {{ $field['max_files'] }} file</small>
                    @foreach($response->documents->where('field_id', $field['id'])->where('selected', true) as $document)
                        <div class="file-row"><div class="file-info"><strong>{{ $document->original_name }}</strong><div><span class="badge {{ $document->scan_status }}">{{ ['pending'=>'Menunggu pemeriksaan','clean'=>'Aman','skipped'=>'Tersimpan','failed'=>'Pemeriksaan gagal','rejected'=>'Ditolak'][$document->scan_status] }}</span></div><small>{{ $document->scan_message }}</small></div>@if($document->available())<a class="button small" href="{{ route('forms.document.download', $document) }}">Unduh</a>@endif<form method="post" data-document-action action="{{ route('forms.document.action', $document) }}">@csrf @if($document->scan_status === 'failed')<button class="button small" name="action" value="retry">Periksa ulang</button>@endif<button class="button small danger" name="action" value="remove">Lepaskan</button></form></div>
                    @endforeach
                    <form method="post" enctype="multipart/form-data" data-document-action data-upload-form action="{{ route('forms.upload', $response->reference) }}">@csrf<input type="hidden" name="field_id" value="{{ $field['id'] }}"><label class="field">Pilih {{ $field['label'] }}<input type="file" name="upload" accept="{{ implode(',', array_map(fn($ext)=>'.'.$ext, $field['extensions'])) }}" required></label><button class="button small">Unggah file</button><progress hidden max="100" value="0" aria-label="Progres unggahan"></progress><span data-upload-status role="status"></span></form>
                </div>
            @endforeach
            </section>
        @endif
    @endif
</x-layouts.form>
