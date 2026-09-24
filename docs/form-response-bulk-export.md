# Email massal dan ekspor respons formulir

Di **Formulir kandidat → Respons masuk**, atur filter pencarian, status, posisi, atau ID tautan penerimaan.

## Menghubungkan akun dan mengirim email

1. Isi tenggat portofolio, tenggat tes, label, dan instruksi untuk lamaran baru.
2. Klik **Pratinjau semua penerima sesuai filter**. Semua halaman hasil filter diproses, maksimal 10.000 respons per tindakan. Pratinjau menampilkan jumlah total dan 20 penerima pertama.
3. Periksa tujuan, centang konfirmasi, lalu klik **Hubungkan & kirim email massal**.
4. Pantau **Status email massal**. Gunakan **Coba ulang** untuk proses gagal setelah penyebabnya diperbaiki. Jika lamaran belum terbentuk, isi tenggat yang masih berlaku sebelum mencoba ulang.

Hanya respons berstatus terkirim, email terverifikasi, belum terhubung, dan belum pernah masuk proses massal yang menjadi penerima baru. Mengubah filter membatalkan pratinjau sebelumnya. Respons yang berubah status sebelum worker berjalan akan dilewati.

Akun baru menerima email akun kandidat yang berisi tautan login dan password sementara. Kandidat wajib mengganti password saat masuk pertama kali. Akun lama menerima pemberitahuan penghubungan dengan tautan login; password dan profilnya dipertahankan. Lamaran aktif berbeda posisi/periode akan ditolak tanpa mengubah data lama.

Proses dapat selesai sebagian. Kegagalan satu kandidat tidak membatalkan kandidat lain. Satu respons hanya memiliki satu catatan proses massal. Pengulangan email yang masih valid memakai catatan pengiriman/password yang sama. Status terkirim berarti layanan email menerima pengiriman, bukan jaminan email sudah dibaca atau masuk inbox.

## Ekspor Excel

Klik **Ekspor Excel sesuai filter**. File `.xlsx` berisi satu sheet per versi formulir dengan kolom identitas, posisi/periode, status penghubungan, waktu pengiriman, serta pertanyaan sesuai urutan pada versi tersebut. Bagian/judul tanpa jawaban tidak menjadi kolom. Header memiliki filter dan baris pertama dibekukan.

Ekspor memakai pengiriman final terakhir, termasuk ketika kandidat sedang merevisi. Jawaban revisi yang belum dikirim dan draf privat tidak diekspor. File diwakili nama serta URL unduh yang tetap memeriksa izin pengguna. Path penyimpanan privat dan password tidak diekspor. Telepon dipertahankan sebagai teks, tanggal/angka bertipe Excel, multipilihan dipisahkan baris. Teks kandidat tidak dieksekusi sebagai formula.

## Operasional

Migrasi tambahan hanya membuat tabel pencatatan proses:

```sh
php artisan migrate --force --path=database/migrations/2026_09_24_080000_create_form_account_dispatches_table.php
```

Gunakan mailer SMTP/provider yang sudah dikonfigurasi dan antrean asinkron (`database`, `redis`, `sqs`, atau `beanstalkd`). Pastikan worker aktif, misalnya melalui supervisor layanan atau:

```sh
php artisan queue:work
```

Pembuatan akun dan pengiriman email menggunakan pembatasan laju antrean email portal yang sudah tersedia. Tidak ada email dikirim hanya karena halaman dibuka atau Excel diekspor. Pada deployment dengan worker lama, restart worker secara terkelola agar memuat kode baru.

Jika fitur perlu dihentikan, hentikan penambahan proses baru dan kelola antrean sebelum rollback kode. Jangan menghapus tabel status ketika masih ada job massal yang berjalan. Migrasi balik tidak membatalkan akun yang sudah dibuat atau email yang sudah terkirim.

## Validasi

```sh
php artisan test --compact tests/Feature/CandidateFormsTest.php tests/Feature/RecruitmentToolsTest.php tests/Feature/AccessTest.php tests/Feature/PasswordResetTest.php
```

`tests/Browser/forms-smoke.cjs` mencakup unduhan XLSX, pratinjau, konfirmasi antrean, dan tampilan mobile menggunakan database SQLite/storage khusus pengujian. Jangan jalankan fixture browser terhadap database operasional.
