@props(['result', 'reportId', 'expanded' => true, 'candidateName' => null])

@php
    $report = app(App\Services\MbtiService::class)->report($result);
@endphp

<div class="mbti-result">
    <div class="mbti-result-identity">
        <div class="mbti-result-summary">
            <div class="row">
                <div><h3>Hasil MBTI</h3><strong class="mbti-type">{{ $result['type'] ?? 'Belum lengkap' }}</strong></div>
                <span class="badge">{{ $result['answered'] }} / {{ $result['total'] }} dijawab</span>
            </div>
            @if(!$result['type'])
                <p class="notice">Jawaban belum lengkap. Tipe kepribadian tidak ditetapkan; persentase berikut hanya mencakup jawaban yang tersimpan.</p>
            @elseif(str_contains($result['type'], 'X'))
                <p class="notice">Huruf X berarti preferensi pada pasangan tersebut seimbang. Tidak ada salah satu sisi yang lebih dominan pada jawaban ini.</p>
            @else
                <p>{{ config('mbti.profiles')[$result['type']] }}</p>
            @endif
            @if($report)<p class="mbti-character-label">{{ $report['name'] }}</p>@endif
        </div>
        @if($report)
            <img class="mbti-character" src="{{ asset('images/mbti/'.(str_contains($result['type'], 'X') ? 'balanced' : strtolower($result['type'])).'.webp') }}" alt="Ilustrasi {{ $report['name'] }}" width="256" height="256" loading="lazy" decoding="async">
        @endif
    </div>

    <div class="mbti-dimensions">
        @foreach($result['dimensions'] as $pair => $dimension)
            @php
                [$left, $right] = str_split($pair);
            @endphp
            <div class="mbti-axis">
                <div class="row"><strong>{{ $left }} {{ $dimension['percentages'][$left] === null ? '—' : $dimension['percentages'][$left].'%' }}</strong><strong>{{ $right }} {{ $dimension['percentages'][$right] === null ? '—' : $dimension['percentages'][$right].'%' }}</strong></div>
                <div class="mbti-bar" aria-hidden="true"><span style="width: {{ $dimension['percentages'][$left] ?? 0 }}%"></span></div>
                <div class="row"><span>{{ config('mbti.dimensions')[$pair][$left] }}</span><span>{{ config('mbti.dimensions')[$pair][$right] }}</span></div>
                <small>{{ $dimension['answered'] }} / {{ $dimension['total'] }} dijawab · {{ $dimension['answered'] === 0 ? 'Belum ada jawaban' : ($dimension['preference'] ? 'Kecenderungan '.$dimension['preference'] : 'Seimbang') }}</small>
            </div>
        @endforeach
    </div>
    <p class="muted">Kuesioner preferensi internal berbasis empat pasangan MBTI, bukan instrumen MBTI® resmi. Hasil menggambarkan jawaban saat ini, bukan ukuran kemampuan dan tidak digunakan untuk menentukan kelulusan atau kecocokan jabatan.</p>
    @if($report)
        <x-mbti-report :report="$report" :type="$result['type']" :report-id="$reportId" :expanded="$expanded" :candidate-name="$candidateName" />
    @endif
</div>
