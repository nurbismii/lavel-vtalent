<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Portal Kandidat' }} · VDNi</title>@vite(['resources/css/app.css','resources/js/app.js'])@livewireStyles
</head>

<body><a class="sr-only focus:not-sr-only" href="#main">Lewati ke konten</a>
    <header class="portal-header">
        <div class="header-inner"><a class="brand" href="{{ route('candidate.dashboard') }}"><img src="{{ asset('images/vdni-logo.png') }}" alt="VDNi"><span class="brand-label">TALENT PORTAL<br>Ruang untuk langkah berikutnya</span></a>
            <nav class="portal-nav" aria-label="Navigasi utama"><a href="{{ route('candidate.dashboard') }}" @if(request()->routeIs('candidate.dashboard')) aria-current="page" @endif>Beranda</a><a href="{{ route('candidate.history') }}" @if(request()->routeIs('candidate.history')) aria-current="page" @endif>Riwayat</a><a href="{{ route('candidate.profile') }}">Profil</a>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="link-button">Keluar</button></form>
            </nav>
        </div>
    </header>
    <main id="main" class="portal-main">@if(session('status'))<div class="success" role="status">{{ session('status') }}</div>@endif{{ $slot }}
        <footer class="portal-footer"><span>© {{ date('Y') }} VDNi · Portal rekrutmen privat</span><a href="{{ route('privacy') }}">Privasi & bantuan</a></footer>
    </main>@livewireScripts
</body>

</html>