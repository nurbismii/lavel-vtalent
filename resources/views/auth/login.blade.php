<x-layouts.auth>
    <div class="eyebrow">Portal kandidat</div>
    <h1>Selamat datang kembali.</h1>
    <p class="muted">Masuk untuk melanjutkan pengumpulan Anda.</p>
    <form action="{{ route('login') }}" method="post">@csrf<label class="field">Email<input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label><label class="field">Password<input id="login-password" name="password" type="password" autocomplete="current-password" required></label><button type="button" class="link-button" data-password-toggle="login-password">Tampilkan password</button>
        <div class="row"><a href="{{ route('password.request') }}">Lupa password?</a></div><button class="button primary full">Masuk ke portal →</button>
    </form>
    <p style="margin-top:20px"><small>Belum menerima akses? Hubungi tim rekrutmen perusahaan.</small></p><a class="link-button" href="/admin/login">Masuk sebagai HR</a>
</x-layouts.auth>