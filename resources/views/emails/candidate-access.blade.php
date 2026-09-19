@extends('emails.layout', [
    'title' => 'Akses akun Anda siap.',
    'eyebrow' => 'AKUN KANDIDAT',
    'preheader' => 'Informasi akses portal dan pesan dari tim HR. Ganti password saat login pertama.',
    'actionUrl' => $loginUrl,
    'actionLabel' => 'Masuk ke portal',
])

@section('content')
    <p style="margin:0 0 20px;">Gunakan akses berikut untuk masuk ke V Talent dan mengikuti proses rekrutmen Anda.</p>
    <div style="margin-bottom:22px;padding:18px;background-color:#f5f7fb;border-left:3px solid #415e9e;border-radius:6px;">
        <p style="margin:0 0 8px;font-size:11px;font-weight:bold;letter-spacing:1px;color:#415e9e;">PESAN DARI HR</p>
        <p style="margin:0;color:#18263b;">@foreach(preg_split('/\r\n|\r|\n/', $hrMessage) as $line){{ $line }}@unless($loop->last)<br>@endunless @endforeach</p>
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;">
        <tr><td style="padding:16px 18px;border-bottom:1px solid #e2e8f0;"><span style="font-size:11px;color:#637187;">EMAIL AKUN</span><br><strong style="font-size:14px;color:#18263b;word-break:break-all;">{{ $email }}</strong></td></tr>
        <tr><td style="padding:16px 18px;border-bottom:1px solid #e2e8f0;"><span style="font-size:11px;color:#637187;">PASSWORD SEMENTARA</span><br><code style="font-size:16px;font-family:Consolas,monospace;color:#18263b;word-break:break-all;">{{ $temporaryPassword }}</code></td></tr>
        <tr><td style="padding:16px 18px;"><span style="font-size:11px;color:#637187;">BERLAKU HINGGA</span><br><strong style="font-size:14px;color:#18263b;">{{ $expiresAt }}</strong></td></tr>
    </table>
@endsection

@section('note')
    <p style="margin:0;">Password wajib diganti saat login pertama. Simpan informasi akses ini secara pribadi dan jangan membagikannya kepada siapa pun.</p>
@endsection
