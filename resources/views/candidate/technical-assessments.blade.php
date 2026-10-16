<x-layouts.portal>
    <div class="heading"><div><div class="eyebrow">Asesmen kandidat</div><h1>Tes teknis online</h1><p class="muted">Kerjakan tes mandiri dan ikuti verifikasi langsung sesuai penugasan HR.</p></div></div>
    <div class="stack">
        @forelse($attempts as $attempt)
            <section class="panel">
                <div class="row"><h2>{{ $attempt->package['title'] }}</h2><span class="badge {{ $attempt->completed_at ? 'clean' : 'pending' }}">{{ $attempt->completed_at ? 'Selesai' : (now()->gte($attempt->deadline) ? 'Tenggat berakhir' : 'Menunggu / berlangsung') }}</span></div>
                <p>{{ array_sum(array_column($attempt->package['sections'], 'seconds')) / 60 }} menit · {{ count($attempt->package['sections']) }} tahap · Versi {{ $attempt->version }}</p>
                <p class="muted">{{ $attempt->form === 'B' ? 'Sesi langsung: setiap tahap dibuka oleh assessor.' : 'Timer berlaku per tahap dan tetap berjalan saat halaman ditutup.' }}</p>
                <p><small>Jadwal {{ $attempt->opens_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i') }} — {{ $attempt->deadline->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}</small></p>
                <a class="button primary" href="{{ route('candidate.technical-assessments.show', $attempt) }}">{{ $attempt->completed_at ? 'Konfirmasi dan catatan kondisi' : 'Buka tes' }} →</a>
            </section>
        @empty
            <section class="panel"><h2>Belum ada penugasan tes online</h2><p class="muted">Tes akan muncul di sini setelah HR menetapkan soal dan jadwal.</p></section>
        @endforelse
        {{ $attempts->links() }}
    </div>
</x-layouts.portal>
