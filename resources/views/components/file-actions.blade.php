@props(['attachment'])

@if($attachment->file->scan_status->available())
    @if($attachment->file->scan_status !== \App\Enums\ScanStatus::Clean)
        <small class="muted">Berhasil disimpan</small>
    @endif
    @if(in_array($attachment->file->mime, ['application/pdf', 'image/jpeg', 'image/png'], true))
        <a class="button small" href="{{ route('files.preview', $attachment) }}" target="_blank" rel="noopener noreferrer" aria-label="Lihat {{ $attachment->file->original_name }} (tab baru)">Lihat file ↗</a>
    @endif
    <a class="button small" href="{{ route('files.download', $attachment) }}">Unduh</a>
@else
    <span class="badge {{ $attachment->file->scan_status->value }}">{{ $attachment->file->scan_status->label() }}</span>
@endif
