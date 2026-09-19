@if($technicalTask)
    <div class="notice">
        <strong>Soal tes teknis</strong>
        <p>Mulai pengerjaan: {{ $technicalTask->starts_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}</p>
        @if($technicalTask->starts_at->lte(now()))
            <a class="button" href="{{ route('candidate.task.download', $technicalTask) }}">Download soal PDF</a>
        @else
            <p>Soal tersedia setelah waktu mulai. Muat ulang halaman saat jadwal dimulai.</p>
        @endif
    </div>
@else
    <p class="muted">File soal belum diunggah oleh HR.</p>
@endif
