@php
    $controlRulesIndex = collect($material)->search(fn (array $block): bool => ($block['text'] ?? null) === 'Aturan Kontrol');
    if ($controlRulesIndex !== false) {
        $controlRules = collect($material)->slice($controlRulesIndex + 1)->takeWhile(fn (array $block): bool => isset($block['text']));
        array_splice($material, $controlRulesIndex, $controlRules->count() + 1, [[
            'heading' => 'Aturan Kontrol',
            'items' => $controlRules->pluck('text')->all(),
        ]]);
    }
@endphp
@foreach($material as $block)
    @if(isset($block['columns']))
        <div class="assessment-documents-scroll" role="region" aria-label="Perbandingan dokumen" tabindex="0">
            <div class="assessment-documents">
                @foreach($block['columns'] as $document)
                    <section class="assessment-document"><h3>{{ $document['title'] }}</h3>@include('candidate.technical-assessment-material', ['material' => $document['material']])</section>
                @endforeach
            </div>
        </div>
    @elseif(isset($block['items']))
        <h3>{{ $block['heading'] }}</h3>
        <ul class="assessment-control-rules">
            @foreach($block['items'] as $item)<li>{{ $item }}</li>@endforeach
        </ul>
    @elseif(isset($block['text']))
        <p class="assessment-material-text">{{ $block['text'] }}</p>
    @else
        <div class="assessment-table-scroll" role="region" aria-label="Tabel materi asesmen" tabindex="0">
            <table class="assessment-table"><thead><tr>@foreach($block['rows'][0] as $cell)<th scope="col">{{ $cell }}</th>@endforeach</tr></thead><tbody>
                @foreach(array_slice($block['rows'], 1) as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach
            </tbody></table>
        </div>
    @endif
@endforeach
