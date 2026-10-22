<x-layouts.portal>
    <div class="heading">
        <div>
            <div class="eyebrow">Asesmen kandidat</div>
            <h1>Tes Teknis</h1>
            <p class="muted">Ikuti tes sesuai penugasan dan jadwal yang ditetapkan HR.</p>
        </div>
    </div>
    <nav class="assessment-categories" aria-label="Kategori tes teknis">
        <a class="assessment-category is-active" href="#compatibility-tests" aria-current="location">
            <x-heroicon-o-clipboard-document-check aria-hidden="true" />
            <span><strong>Tes kompatibilitas</strong><small>Form A dan Form B</small></span>
        </a>
        <button class="assessment-category" type="button" disabled>
            <x-heroicon-o-lock-closed aria-hidden="true" />
            <span><strong>Penguasaan Bidang</strong><small>Belum tersedia</small></span>
        </button>
    </nav>
    <section id="compatibility-tests" aria-labelledby="compatibility-heading">
        <div class="assessment-category-heading">
            <h2 id="compatibility-heading">Tes kompatibilitas</h2>
            <p class="muted">Form A dan Form B tersedia sesuai penugasan HR.</p>
        </div>
        <div class="stack">
            @forelse($attempts->sortBy('form') as $attempt)
                <article class="panel assessment-attempt-card">
                    <div class="row">
                        <div>
                            <span class="assessment-form-label">Form {{ $attempt->form }}</span>
                            <h3>{{ $attempt->package['title'] }}</h3>
                        </div>
                        <span class="badge {{ $attempt->completed_at ? 'clean' : 'pending' }}">{{ $attempt->completed_at ? 'Selesai' : (now()->gte($attempt->deadline) ? 'Tenggat berakhir' : 'Menunggu / berlangsung') }}</span>
                    </div>
                    <p>{{ array_sum(array_column($attempt->sectionsForDisplay(), 'seconds')) / 60 }} menit - {{ count($attempt->sectionsForDisplay()) }} tahap</p>
                    <p class="muted">Timer berlaku per tahap dan tetap berjalan saat halaman ditutup.</p>
                    <p><small>Jadwal {{ $attempt->opens_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i') }} - {{ $attempt->deadline->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}</small></p>
                    @if($attempt->isWaitingForFormA())
                        <p class="notice">Terkunci - Selesaikan Form A terlebih dahulu sebelum memulai Form B</p>
                    @else
                        <a class="button primary" href="{{ route('candidate.technical-assessments.show', $attempt) }}">{{ $attempt->completed_at ? ($attempt->form === 'A' || empty($attempt->conditionQuestions()) ? 'Lihat status tes' : 'Konfirmasi dan catatan kondisi') : 'Buka tes' }}</a>
                    @endif
                </article>
            @empty
                <div class="panel">
                    <h3>Belum ada penugasan tes online</h3>
                    <p class="muted">Form A dan Form B akan muncul di sini sesuai penugasan HR.</p>
                </div>
            @endforelse
            {{ $attempts->links() }}
        </div>
    </section>
</x-layouts.portal>
