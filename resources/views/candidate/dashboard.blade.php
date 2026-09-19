<x-layouts.portal>
    <div class="heading">
        <div>
            <div class="eyebrow">Portal kandidat</div>
            <h1>Selamat datang, {{ auth()->user()->name }}.</h1>
            <p class="muted">Langkah berikutnya dimulai dari karya Anda.</p>@if($application)<small>{{ $application->position->name }} · {{ $application->period->name }}</small>@endif
        </div>@if($application)<span class="badge clean">● Lamaran aktif</span>@endif
    </div>@if(!$application)<section class="panel">
        <h2>Belum ada lamaran aktif.</h2>
        <p class="muted">Hubungi HR jika Anda sudah menerima penugasan. Riwayat pengumpulan sebelumnya tetap tersedia.</p><a href="{{ route('candidate.history') }}">Lihat riwayat →</a>
    </section>@else<div class="two-col">@foreach($application->submissions as $submission)<section class="panel task-panel {{ $submission->type->value }}">
            <div class="row">
                <div class="icon-box">@if($submission->type===App\Enums\SubmissionType::Portfolio)<x-heroicon-o-folder-open />@else<x-heroicon-o-code-bracket />@endif</div><span class="badge {{ $submission->status->value }}">{{ $submission->status->label() }}</span>
            </div>
            <h2>{{ $submission->type->label() }}</h2>
            @if($submission->type === App\Enums\SubmissionType::TechnicalTest)
                @include('candidate.technical-task', ['technicalTask' => $application->technicalTask()])
            @endif
            <p class="muted">{{ $submission->type===App\Enums\SubmissionType::Portfolio ? 'Perkenalkan pengalaman dan karya terbaik melalui dokumen portofolio.' : 'Unggah hasil pekerjaan sesuai petunjuk tim rekrutmen.' }}</p>
            <hr><small>TENGGAT PENGUMPULAN</small>
            <p><strong>{{ $submission->localDeadline() }}</strong></p>@if($submission->deadline->isPast() && in_array($submission->status,[App\Enums\SubmissionStatus::NotStarted,App\Enums\SubmissionStatus::Draft,App\Enums\SubmissionStatus::Revision]))<p class="field-error">{{ $submission->status===App\Enums\SubmissionStatus::Revision ? 'Revisi belum dikumpulkan' : 'Tenggat terlewati' }}</p>@endif<a class="button {{ $submission->editable()?'primary':'' }} full" href="{{ route('candidate.submission',$submission) }}">{{ match($submission->status){App\Enums\SubmissionStatus::Submitted=>'Lihat tanda terima',App\Enums\SubmissionStatus::Exempt=>'Lihat detail',default=>$submission->editable()?($submission->status===App\Enums\SubmissionStatus::Revision?'Kerjakan revisi':'Lanjutkan pengumpulan'):'Lihat draf'} }} →</a>
        </section>@endforeach</div>
    <div class="notice"><strong>Simpan draf, periksa, lalu kirim final.</strong><br>Dokumen draf belum dianggap dikumpulkan. Tanda terima tersedia setelah pengiriman final berhasil.</div>@endif
</x-layouts.portal>
