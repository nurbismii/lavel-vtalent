@foreach($material as $block)
    @if(isset($block['text']))
        <p class="assessment-material-text">{{ $block['text'] }}</p>
    @else
        <div class="assessment-table-scroll" role="region" aria-label="Tabel materi asesmen" tabindex="0">
            <table class="assessment-table"><thead><tr>@foreach($block['rows'][0] as $cell)<th scope="col">{{ $cell }}</th>@endforeach</tr></thead><tbody>
                @foreach(array_slice($block['rows'], 1) as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach
            </tbody></table>
        </div>
    @endif
@endforeach
