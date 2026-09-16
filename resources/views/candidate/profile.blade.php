<x-layouts.portal>
    <div class="heading">
        <div>
            <div class="eyebrow">Akun kandidat</div>
            <h1>Profil Anda</h1>
        </div>
    </div><x-errors />
    <div class="two-col">
        <section class="panel">
            <h2>Informasi pribadi</h2><small>Nama</small>
            <p>{{ auth()->user()->name }}</p><small>Email</small>
            <p>{{ auth()->user()->email }}</p><small>Perubahan nama dan email dilakukan oleh HR.</small>
            <form method="post" action="{{ route('candidate.profile.save') }}">@csrf<label class="field">Nomor telepon · Opsional<input name="phone" type="tel" value="{{ old('phone',auth()->user()->profile?->phone) }}" maxlength="30"></label><button class="button primary full">Simpan profil</button></form>
        </section>
        <section class="panel">
            <h2>Keamanan akun</h2>
            <p class="muted">Gunakan password yang berbeda dari akun lainnya.</p><a class="button" href="{{ route('password.initial') }}">Ganti password</a>
        </section>
    </div>
</x-layouts.portal>