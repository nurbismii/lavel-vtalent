<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f7fb;color:#18263b;font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">{{ $preheader }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f7fb;">
        <tr><td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
                <tr><td style="padding:0 8px 24px;">
                    <img src="{{ isset($message) ? $message->embed(public_path('images/vdni-logo.png')) : asset('images/vdni-logo.png') }}" alt="VDNI" width="110" style="display:block;width:110px;height:auto;border:0;">
                    <p style="margin:12px 0 0;color:#637187;font-size:11px;letter-spacing:1.8px;">V TALENT · PORTAL REKRUTMEN</p>
                </td></tr>
                <tr><td style="padding:30px 24px;background-color:#ffffff;border:1px solid #e2e8f0;border-top:4px solid #415e9e;border-radius:12px;">
                    <p style="margin:0 0 12px;font-size:11px;font-weight:bold;letter-spacing:1.4px;color:#415e9e;">{{ $eyebrow }}</p>
                    <h1 style="margin:0 0 24px;font-size:27px;line-height:1.3;color:#18263b;">{{ $title }}</h1>
                    <p style="margin:0 0 18px;font-size:15px;line-height:1.7;">Halo {{ $name }},</p>
                    <div style="font-size:14px;line-height:1.8;color:#526078;">@yield('content')</div>
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:24px;"><tr><td bgcolor="#415e9e" style="border-radius:8px;text-align:center;mso-padding-alt:14px 24px;">
                        <a href="{{ $actionUrl }}" style="display:inline-block;padding:14px 24px;border:1px solid #415e9e;border-radius:8px;color:#ffffff;font-size:14px;font-weight:bold;text-decoration:none;">{{ $actionLabel }}</a>
                    </td></tr></table>
                    <div style="margin-top:22px;font-size:12px;line-height:1.8;color:#637187;">@yield('note')</div>
                    <p style="margin:24px 0 0;font-size:14px;line-height:1.7;color:#18263b;">Salam,<br><strong>Tim HR · VDNI</strong></p>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px;"><tr><td style="border-top:1px solid #e2e8f0;padding-top:18px;">
                        <p style="margin:0 0 6px;font-size:11px;line-height:1.7;color:#637187;">Tombol tidak berfungsi? Salin tautan berikut ke browser:</p>
                        <a href="{{ $actionUrl }}" style="font-size:11px;line-height:1.7;color:#415e9e;word-break:break-all;overflow-wrap:anywhere;">{{ $actionUrl }}</a>
                    </td></tr></table>
                </td></tr>
                <tr><td style="padding:22px 16px;text-align:center;font-size:11px;line-height:1.8;color:#637187;">V Talent · Portal Rekrutmen VDNI<br>Email otomatis terkait akun dan proses rekrutmen Anda.<br>Untuk bantuan, hubungi HR melalui kanal komunikasi rekrutmen yang telah diberikan.</td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
