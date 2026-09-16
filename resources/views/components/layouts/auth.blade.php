<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Masuk' }} · VDNi</title>@vite(['resources/css/app.css','resources/js/app.js'])
</head>

<body>
    <main class="auth-wrap">
        <section class="auth-intro"><img src="{{ asset('images/vdni-logo.png') }}" alt="VDNi">
            <div class="eyebrow">Talent portal</div>
            <h1>Langkah berikutnya<br>dimulai dari<br>karya Anda.</h1>
            <p class="muted">Satu tempat untuk portofolio dan hasil tes teknis Anda.</p><small>Portal privat · Akses diberikan oleh tim HR</small>
        </section>
        <section class="panel auth-form">
            <div class="brand"><img src="{{ asset('images/vdni-logo.png') }}" alt="VDNi"></div>@if(session('status'))<div class="success" role="status">{{ session('status') }}</div>@endif<x-errors />{{ $slot }}
            <hr><small><a href="{{ route('privacy') }}">Privasi & bantuan</a></small>
        </section>
    </main>
</body>

</html>