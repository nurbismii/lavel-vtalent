<p>Halo,</p>
<p>Gunakan tautan berikut untuk memverifikasi email, melanjutkan pengisian, atau melihat respons formulir kandidat Anda. Anda tidak perlu membuat akun.</p>
<p><a href="{{ $url }}">Buka formulir & lihat respons</a></p>
<p>Tautan dapat dibuka berkali-kali sampai {{ $expires->copy()->timezone(App\Models\AppSetting::valueFor('timezone'))->format('d M Y H:i') }} ({{ App\Models\AppSetting::valueFor('timezone') }}). Setelah respons dikirim, jawaban dan dokumen hanya dapat dilihat dan tidak dapat diubah, kecuali HR membuka revisi.</p>
<p>Jika tautan kedaluwarsa, minta tautan baru melalui formulir dengan email yang sama. Jika respons sudah terhubung ke akun kandidat, masuk melalui portal untuk melihatnya.</p>
<p>Jangan bagikan tautan ini karena pemegang tautan dapat mengakses isian Anda. Jika Anda tidak meminta akses, abaikan email ini.</p>
<p>Tim Rekrutmen VDNi</p>
