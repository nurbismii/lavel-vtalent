<x-layouts.form title="Periksa email Anda">
    <section class="panel">
        <span class="eyebrow">Verifikasi email</span>
        <h1>Periksa inbox Anda</h1>
        <p>Permintaan akses untuk <strong>{{ $email }}</strong> sudah diterima. Jika memenuhi syarat, tautan verifikasi akan dikirim ke alamat tersebut.</p>
        <div class="notice">Periksa folder spam atau promosi. Tautan berlaku {{ config('candidate_forms.access_minutes') }} menit. Refresh halaman ini tidak mengirim email baru.</div>
        <p class="muted">Jika akun Anda sudah terhubung, masuk melalui portal. Jangan meminta email berulang kali saat pengiriman sedang tertunda.</p>
        <form method="post" action="{{ route('forms.resend', $intake->slug) }}" data-email-resend data-retry-seconds="{{ $retryAfter }}">
            @csrf
            <button class="button primary" @disabled($retryAfter > 0)>Kirim ulang email verifikasi</button>
            <span class="muted" data-resend-countdown role="status">@if($retryAfter > 0)Tunggu {{ $retryAfter }} detik sebelum mengirim ulang.@endif</span>
        </form>
        <div class="form-actions"><a class="button" href="{{ route('forms.show', $intake->slug) }}">Kembali / perbaiki email</a><a class="button" href="{{ route('login') }}">Masuk ke portal</a></div>
    </section>
</x-layouts.form>
