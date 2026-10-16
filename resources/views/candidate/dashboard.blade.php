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
    </section>@else<div class="grid grid-cols-1 gap-[22px] lg:grid-cols-3">@foreach($application->submissions->where('type', App\Enums\SubmissionType::Portfolio) as $submission)<section class="panel task-panel {{ $submission->type->value }}">
            <div class="row">
                <div class="icon-box"><x-heroicon-o-folder-open /></div><span class="badge {{ $submission->status->value }}">{{ $submission->status->label() }}</span>
            </div>
            <h2>{{ $submission->type->label() }}</h2>
            <p class="muted">Perkenalkan pengalaman dan karya terbaik melalui dokumen portofolio.</p>
            <hr><small>TENGGAT PENGUMPULAN</small>
            <p><strong>{{ $submission->localDeadline() }}</strong></p>@if($submission->deadline->isPast() && in_array($submission->status,[App\Enums\SubmissionStatus::NotStarted,App\Enums\SubmissionStatus::Draft,App\Enums\SubmissionStatus::Revision]))<p class="field-error">{{ $submission->status===App\Enums\SubmissionStatus::Revision ? 'Revisi belum dikumpulkan' : 'Tenggat terlewati' }}</p>@endif<a class="button {{ $submission->editable()?'primary':'' }} full" href="{{ route('candidate.submission',$submission) }}">{{ match($submission->status){App\Enums\SubmissionStatus::Submitted=>'Lihat tanda terima',App\Enums\SubmissionStatus::Exempt=>'Lihat detail',default=>$submission->editable()?($submission->status===App\Enums\SubmissionStatus::Revision?'Kerjakan revisi':'Lanjutkan pengumpulan'):'Lihat draf'} }} →</a>
        </section>@endforeach
        <section class="panel task-panel technical_test">
            <div class="icon-box"><x-heroicon-o-code-bracket /></div>
            <h2>Tes Teknis</h2>
            <p class="muted">Kerjakan tes teknis online dan ikuti verifikasi langsung sesuai penugasan tim rekrutmen.</p>
            <hr><small>TENGGAT TES</small>
            @forelse($technicalAttempts as $attempt)
                <p><small>Form {{ $attempt->form }}</small><br><strong>{{ $attempt->deadline->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}</strong></p>
            @empty
                <p class="muted">Belum ditetapkan.</p>
            @endforelse
            @if(App\Services\TechnicalAssessmentService::available())
                <a class="button primary full" href="{{ route('candidate.technical-assessments') }}">Buka tes teknis online →</a>
            @else
                <p class="muted">Tes teknis online belum tersedia. Hubungi HR untuk informasi lebih lanjut.</p>
            @endif
        </section>
        <section class="panel task-panel psychometric">
            <div class="icon-box"><x-heroicon-o-clipboard-document-check /></div>
            <h2>Tes Psikotes</h2>
            <p class="muted">Kerjakan psikotes sesuai jadwal dan penugasan tim rekrutmen. Siapkan koneksi stabil dan waktu tanpa gangguan.</p>
            <hr><small>TENGGAT TES</small>
            @forelse($psychometricAttempts as $attempt)
                <p><small>{{ $attempt->test->title }}</small><br><strong>{{ $attempt->deadline->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i T') }}</strong></p>
            @empty
                <p class="muted">Belum ditetapkan.</p>
            @endforelse
            @if(App\Services\PsychometricService::available())
                <a class="button primary full" href="{{ route('candidate.psychometrics') }}">Buka tes psikotes →</a>
            @else
                <p class="muted">Psikotes belum tersedia. Hubungi HR untuk informasi lebih lanjut.</p>
            @endif
        </section>
    </div>
    <div class="notice"><strong>Pengumpulan portofolio: simpan draf, periksa, lalu kirim final.</strong><br>Dokumen draf belum dianggap dikumpulkan. Tanda terima tersedia setelah pengiriman final berhasil.</div>@endif
    @if($candidateForms->isNotEmpty())<section class="panel"><div class="row"><h2>Formulir kandidat</h2><a href="{{ route('candidate.forms') }}">Lihat semua</a></div>@foreach($candidateForms as $formResponse)<div class="form-intake-row"><div class="row"><div><h3>{{ $formResponse->intake->version->title }}</h3><span class="badge {{ $formResponse->status }}">{{ ['draft'=>'Draf','revision'=>'Revisi diminta','submitted'=>'Terkirim'][$formResponse->status] }}</span>@if($formResponse->status === 'revision')<p>{{ $formResponse->revision_note }}</p><small>Tenggat revisi: {{ $formResponse->revision_deadline->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i') }}</small>@endif</div><a class="button" href="{{ route('forms.response', $formResponse->reference) }}">{{ $formResponse->editable() ? 'Perbaiki jawaban' : 'Lihat jawaban' }}</a></div></div>@endforeach</section>@endif
</x-layouts.portal>
