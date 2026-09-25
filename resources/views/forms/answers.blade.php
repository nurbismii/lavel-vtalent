<section class="panel form-answer-summary">
    <div class="answer-heading"><div><span class="eyebrow">Data formulir</span><h2>Ringkasan jawaban</h2></div><span class="badge">{{ collect($fields)->where('type', '!=', 'section')->count() }} pertanyaan</span></div>
    <dl class="answer-grid">
        <div class="answer-item"><dt>Nama lengkap</dt><dd>{{ $name }}</dd></div>
        <div class="answer-item"><dt>Email terverifikasi</dt><dd>{{ $email }}</dd></div>
        @foreach($fields as $field)
            @if($field['type'] === 'section')
                <div class="answer-section"><dt>{{ $field['label'] }}</dt><dd>{{ $field['help'] ?? '' }}</dd></div>
                @continue
            @endif
            <div class="answer-item {{ in_array($field['type'], ['file','textarea','checkbox']) ? 'answer-wide' : '' }}">
                <dt>{{ $field['label'] }}</dt>
                <dd>
                    @if($field['type'] === 'file')
                        <div class="answer-documents">
                        @forelse($documents->where('field_id', $field['id']) as $document)
                            <div class="answer-document"><span class="answer-file-type">{{ strtoupper(pathinfo($document->original_name, PATHINFO_EXTENSION)) ?: 'FILE' }}</span><div class="answer-file-info"><strong>{{ $document->original_name }}</strong><small>{{ ($document->size < 1024 ? '< 1' : number_format($document->size / 1024, 0)) }} KB · {{ $document->available() ? 'Siap dilihat / diunduh' : (['pending'=>'Menunggu pemeriksaan','scanning'=>'Sedang diperiksa','infected'=>'File ditolak','failed'=>'Pemeriksaan gagal','error'=>'Pemeriksaan gagal'][$document->scan_status] ?? 'Belum tersedia') }}</small></div>@if($document->available())<a class="button small" href="{{ route('forms.document.preview', $document) }}" target="_blank" rel="noopener" aria-label="Lihat {{ $document->original_name }}">Lihat dokumen</a><a class="button small" href="{{ route('forms.document.download', $document) }}" aria-label="Unduh {{ $document->original_name }}"><x-heroicon-o-arrow-down-tray /><span>Unduh</span></a>@endif</div>
                        @empty<span class="answer-empty">Tidak ada file diunggah</span>@endforelse
                        </div>
                    @else
                        @php($answer = $answers[$field['id']] ?? null)
                        @if($field['type'] === 'consent')<span class="badge {{ $answer ? 'submitted' : '' }}">{{ $answer ? 'Disetujui' : 'Tidak diisi' }}</span>
                        @elseif(is_array($answer) && count($answer))<ul class="answer-options">@foreach($answer as $value)<li>{{ $value }}</li>@endforeach</ul>
                        @elseif($answer === null || $answer === '' || $answer === [])<span class="answer-empty">Belum diisi</span>
                        @elseif($field['type'] === 'date'){{ \Carbon\Carbon::parse($answer)->translatedFormat('d F Y') }}
                        @else<span class="answer-text">{{ $answer }}</span>@endif
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</section>
