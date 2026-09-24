<!DOCTYPE html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow"><title>{{ $title ?? 'Formulir Kandidat' }} · VDNi</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body><a class="sr-only focus:not-sr-only" href="#main">Lewati ke konten</a>
<header class="portal-header"><div class="header-inner"><a class="brand" href="{{ route('login') }}"><img src="{{ asset('images/vdni-logo.png') }}" alt="VDNi"><span class="brand-label">TALENT PORTAL<br>Langkah awal perjalanan Anda</span></a><nav class="portal-nav">@auth<a href="{{ route('candidate.forms') }}">Formulir saya</a>@else<a href="{{ route('login') }}">Sudah punya akun?</a>@endauth</nav></div></header>
<main id="main" class="portal-main candidate-form-shell">@if(session('status'))<div class="success" role="status">{{ session('status') }}</div>@endif<x-errors /><div id="form-client-feedback" class="notice" role="status" hidden></div>{{ $slot }}<footer class="portal-footer"><span>VDNi · Rekrutmen</span><a href="{{ route('privacy') }}">Privasi & bantuan</a></footer></main>
</body></html>
