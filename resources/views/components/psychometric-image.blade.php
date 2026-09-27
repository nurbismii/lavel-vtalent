@props(['source', 'region', 'label', 'imageWidth' => 1191, 'imageHeight' => 1684])
<svg {{ $attributes }} viewBox="{{ implode(' ', $region) }}" role="img" aria-label="{{ $label }}" xmlns="http://www.w3.org/2000/svg">
    <image href="{{ $source }}" x="0" y="0" width="{{ $imageWidth }}" height="{{ $imageHeight }}" preserveAspectRatio="none" />
</svg>
