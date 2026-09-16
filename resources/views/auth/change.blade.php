<x-layouts.auth title="Ganti password">
    <h1>Amankan akun Anda.</h1>
    <p class="muted">Ganti password sementara sebelum mengakses dokumen.</p>
    <form method="post" action="{{ route('password.initial.store') }}">@csrf<label class="field">Password saat ini<input type="password" name="current_password" autocomplete="current-password" required></label><label class="field">Password baru<input type="password" name="password" minlength="12" autocomplete="new-password" required></label><small>Minimal 12 karakter dan berbeda dari password sebelumnya.</small><label class="field">Konfirmasi password baru<input type="password" name="password_confirmation" autocomplete="new-password" required></label><button class="button primary full">Simpan & lanjutkan</button></form>
</x-layouts.auth>