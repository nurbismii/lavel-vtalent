<x-layouts.form title="Tunggu sebelum mencoba lagi">
    <section class="panel receipt">
        <span class="badge">Pembatasan sementara</span>
        <h1>Tunggu sebentar</h1>
        <p role="status">{{ $message }}</p>
        <p class="muted">Draf dan dokumen yang sudah disimpan tetap tersedia. Permintaan yang dibatasi ini tidak memproses verifikasi.</p>
        @if($backUrl)<a class="button primary" href="{{ $backUrl }}">Kembali ke formulir</a>@else<p class="muted">Buka kembali tautan email setelah waktu tunggu. Jika tautan kedaluwarsa, minta tautan baru melalui formulir asal.</p>@endif
    </section>
</x-layouts.form>
