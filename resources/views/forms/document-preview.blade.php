<x-layouts.form title="Lihat dokumen">
    <section class="panel stack">
        <h1>{{ $document->original_name }}</h1>
        <p>Pratinjau tersedia untuk PDF, JPG, dan PNG. Format dokumen ini belum dapat ditampilkan di browser.</p>
        <p>Unduh dokumen untuk membukanya dengan aplikasi yang sesuai.</p>
        <a class="button primary" href="{{ route('forms.document.download', $document) }}">Unduh dokumen</a>
    </section>
</x-layouts.form>
