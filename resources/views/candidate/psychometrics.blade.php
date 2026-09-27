<x-layouts.portal>
    <div class="heading"><div><div class="eyebrow">Asesmen kandidat</div><h1>Psikotes saya</h1><p class="muted">Siapkan koneksi stabil dan waktu tanpa gangguan sebelum memulai.</p></div></div>
    @forelse($attempts as $attempt)
        <section class="panel">
            <div class="row"><h2>{{ $attempt->test->title }}</h2><span class="badge">{{ $attempt->completed_at ? 'Selesai' : (now()->gte($attempt->deadline) ? 'Waktu berakhir' : ($attempt->section_started_at || $attempt->section_index > 0 ? 'Sedang dikerjakan' : 'Belum dimulai')) }}</span></div>
            <p>Jadwal: {{ $attempt->opens_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i') }} – {{ $attempt->deadline->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}</p>
            <a class="button primary" href="{{ route('candidate.psychometrics.show', $attempt) }}">{{ $attempt->completed_at ? 'Lihat status' : 'Buka tes' }} →</a>
        </section>
    @empty
        <section class="panel"><h2>Belum ada penugasan psikotes</h2><p class="muted">Tes akan muncul di sini setelah ditugaskan oleh HR.</p></section>
    @endforelse
    {{ $attempts->links() }}
</x-layouts.portal>
