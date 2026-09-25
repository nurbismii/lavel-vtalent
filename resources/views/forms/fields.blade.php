@foreach($fields as $field)
    @php($value = old('answers.'.$field['id'], $answers[$field['id']] ?? ($field['type'] === 'checkbox' ? [] : '')))
    @if($field['type'] === 'section')
        <div class="form-section"><span class="eyebrow">Bagian formulir</span><h2>{{ $field['label'] }}</h2><p class="muted">{{ $field['help'] ?? '' }}</p></div>
    @elseif($field['type'] !== 'file')
        <fieldset class="dynamic-field" data-required="{{ $field['required'] ? 'true' : 'false' }}" data-field-label="{{ $field['label'] }}" @disabled($disabled ?? false)>
            <legend>{{ $field['label'] }} @if($field['required'])<span aria-label="wajib" class="field-error">*</span>@endif</legend>
            @if(!empty($field['help']))<p class="muted" id="help-{{ $field['id'] }}">{{ $field['help'] }}</p>@endif
            @if($field['type'] === 'textarea')
                <label class="field"><span class="sr-only">{{ $field['label'] }}</span><textarea name="answers[{{ $field['id'] }}]" maxlength="{{ $field['max_length'] ?: 5000 }}" placeholder="{{ $field['placeholder'] ?? '' }}">{{ $value }}</textarea></label>
            @elseif(in_array($field['type'], ['select','radio','checkbox']))
                @if($field['type'] === 'select')
                    <label class="field"><span class="sr-only">{{ $field['label'] }}</span><select aria-label="{{ $field['label'] }}" name="answers[{{ $field['id'] }}]"><option value="">Pilih jawaban</option>@foreach($field['options'] as $option)<option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>@endforeach</select></label>
                @else
                    <div class="form-choices">@foreach($field['options'] as $option)<label><input type="{{ $field['type'] === 'checkbox' ? 'checkbox' : 'radio' }}" name="answers[{{ $field['id'] }}]{{ $field['type'] === 'checkbox' ? '[]' : '' }}" value="{{ $option }}" @checked(is_array($value) ? in_array($option, $value, true) : $value === $option)> {{ $option }}</label>@endforeach</div>
                @endif
            @elseif($field['type'] === 'consent')
                <label class="form-check"><input type="checkbox" name="answers[{{ $field['id'] }}]" value="1" @checked($value)> Saya menyetujui pernyataan ini.</label>
            @else
                <label class="field"><span class="sr-only">{{ $field['label'] }}</span><input type="{{ match($field['type']) { 'phone' => 'tel', 'number','date','email','url' => $field['type'], default => 'text' } }}" name="answers[{{ $field['id'] }}]" value="{{ $value }}" placeholder="{{ $field['placeholder'] ?? '' }}" @if(in_array($field['type'], ['number','date'])) @if(!empty($field['min'])) min="{{ $field['min'] }}" @endif @if(!empty($field['max'])) max="{{ $field['max'] }}" @endif @if($field['type'] === 'number') step="any" @endif @else maxlength="{{ $field['max_length'] ?: 255 }}" @endif></label>
            @endif
            @error('answers.'.$field['id'])<span class="field-error" role="alert">{{ $message }}</span>@enderror
        </fieldset>
    @else
        <div class="dynamic-field"><strong>{{ $field['label'] }}{{ $field['required'] ? ' *' : '' }}</strong><p class="muted">{{ $field['help'] ?? '' }}</p><small>{{ strtoupper(implode(', ', $field['extensions'])) }} · Maks. {{ $field['max_mb'] }} MB/file · {{ $field['max_files'] }} file</small><p class="muted">Unggah pada bagian Dokumen setelah email diverifikasi.</p></div>
    @endif
@endforeach
