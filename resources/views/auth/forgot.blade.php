<x-layouts.auth title="Lupa password">
    <h1>Lupa password?</h1>
    <p class="muted">Masukkan email yang digunakan pada proses rekrutmen.</p>
    <form method="post" action="{{ route('password.email') }}">@csrf<label class="field">Email<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label><button class="button primary full">Kirim instruksi reset</button></form><a href="{{ route('login') }}">Kembali ke login</a>
</x-layouts.auth>