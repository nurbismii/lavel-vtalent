<x-layouts.portal>
    <div class="heading">
        <div>
            <div class="eyebrow">Dokumen Anda</div>
            <h1>Riwayat pengumpulan</h1>
            <p class="muted">Versi final dan tanda terima dari seluruh lamaran Anda.</p>
        </div>
    </div>
    <div class="stack">@forelse($versions as $version)<section class="panel">
            <div class="row">
                <h2>{{ $version->submission->type->label() }} · Versi {{ $version->number }}</h2><span class="badge submitted">Sudah dikumpulkan</span>
            </div>
            <p>{{ $version->submission->application->position->name }}</p><small>{{ $version->submitted_at->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y, H:i T') }}</small>
            <p class="receipt-code">{{ $version->receipt }}</p>@foreach($version->attachments as $attachment)<div class="file-row">
                <div class="file-info"><a href="{{ route('files.download',$attachment) }}">{{ $attachment->file->original_name }}</a>@if($attachment->description)<p class="muted">{{ $attachment->description }}</p>@endif</div><x-file-actions :attachment="$attachment" />
            </div>@endforeach
        </section>@empty<section class="panel">
            <h2>Belum ada pengumpulan final.</h2>
            <p class="muted">Tanda terima akan muncul setelah Anda mengirim dokumen final.</p><a href="{{ route('candidate.dashboard') }}">Kembali ke beranda →</a>
        </section>@endforelse</div>{{ $versions->links() }}
</x-layouts.portal>