@props(['report', 'type', 'reportId', 'expanded' => true])

@php
    $sectionTitles = config('mbti_reports.sections');
    unset($sectionTitles['scores']);
@endphp

<details class="mbti-report" @if($expanded) open @endif>
    <summary><span><strong>Laporan terperinci {{ $type }}</strong><small>{{ $report['name'] }} · {{ count($sectionTitles) }} bagian</small></span></summary>
    <div class="mbti-report-content">
        
        <div class="mbti-report-layout">
            <nav class="mbti-report-toc" aria-label="Daftar isi laporan {{ $type }}">
                <strong>Daftar isi</strong>
                <ol>@foreach($sectionTitles as $key => $title)<li><a href="#{{ $reportId }}-{{ $key }}">{{ $title }}</a></li>@endforeach</ol>
            </nav>
            <div class="mbti-report-sections">
                @foreach($sectionTitles as $key => $title)
                <section class="mbti-report-section" aria-labelledby="{{ $reportId }}-{{ $key }}">
                    <h4 id="{{ $reportId }}-{{ $key }}" tabindex="-1">{{ $title }}</h4>
                    @if($key === 'overview')
                        @foreach($report[$key] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
                    @else
                        <ul>@foreach($report[$key] as $item)<li>{{ $item }}</li>@endforeach</ul>
                    @endif
                </section>
                @endforeach
            </div>
        </div>
        <p class="mbti-report-source">
            @if(str_contains($type, 'X'))
                Laporan ini disusun dari skor setiap pasangan preferensi pada kuesioner internal. X adalah penanda skor seimbang, bukan nama tipe resmi MBTI.
            @else
                Rujukan gambaran tipe: <a href="https://www.mbtionline.com/mbti-types/{{ strtolower($type) }}" target="_blank" rel="noopener noreferrer">MBTIonline · {{ $type }}</a>.
            @endif
            Saran pengembangan dan latihan merupakan bahan refleksi yang disusun untuk aplikasi ini. Baca juga <a href="https://www.myersbriggs.org/unique-features-of-myers-briggs/ethical-use-of-the-mbti/" target="_blank" rel="noopener noreferrer">panduan penggunaan MBTI</a>.
            Penjelasan umum tentang batas penafsiran tersedia pada <a href="https://www.myersbriggs.org/my-mbti-personality-type/my-mbti-results/" target="_blank" rel="noopener noreferrer">panduan memahami hasil</a>; persentase di aplikasi ini memakai perhitungan internal.
        </p>
    </div>
</details>
