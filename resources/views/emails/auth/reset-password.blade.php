@extends('emails.layout', [
    'title' => 'Buat password baru.',
    'eyebrow' => 'KEAMANAN AKUN',
    'preheader' => 'Atur ulang password akun V Talent Anda. Tautan berlaku '.$expiresIn.' menit.',
    'actionUrl' => $resetUrl,
    'actionLabel' => 'Reset password',
])

@section('content')
    <p style="margin:0 0 20px;">Kami menerima permintaan untuk mengatur ulang password akun V Talent Anda. Gunakan tombol di bawah untuk membuat password baru.</p>
    <div style="padding:16px 18px;background-color:#f5f7fb;border:1px solid #e2e8f0;border-radius:8px;">
        <span style="font-size:11px;color:#637187;">MASA BERLAKU TAUTAN</span><br>
        <strong style="font-size:16px;color:#18263b;">{{ $expiresIn }} menit</strong>
    </div>
@endsection

@section('note')
    <p style="margin:0 0 10px;">Jangan bagikan tautan ini kepada siapa pun.</p>
    <p style="margin:0;">Jika Anda tidak meminta reset password, abaikan email ini. Password Anda tidak akan berubah.</p>
@endsection
