document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) return;
    const field = document.getElementById(button.dataset.passwordToggle);
    field.type = field.type === 'password' ? 'text' : 'password';
    button.textContent = field.type === 'password' ? 'Tampilkan password' : 'Sembunyikan password';
});

const answerForm = document.querySelector('[data-track-changes]');
if (answerForm) {
    let dirty = false;
    const feedback = document.getElementById('form-client-feedback');
    answerForm.addEventListener('input', () => {
        dirty = true;
        answerForm.querySelector('[data-dirty-status]').textContent = 'Ada perubahan yang belum disimpan';
    });
    answerForm.addEventListener('submit', (event) => {
        if (answerForm.dataset.submitting) { event.preventDefault(); return; }
        answerForm.dataset.submitting = 'true';
        dirty = false;
        answerForm.querySelector('[data-dirty-status]').textContent = 'Menyimpan…';
    });
    document.querySelectorAll('[data-document-action]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (dirty) {
                event.preventDefault();
                feedback.hidden = false;
                feedback.textContent = 'Simpan draf jawaban terlebih dahulu sebelum mengunggah atau melepas dokumen.';
                feedback.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            if (!form.hasAttribute('data-upload-form')) return;
            event.preventDefault();
            const xhr = new XMLHttpRequest();
            const progress = form.querySelector('progress');
            const status = form.querySelector('[data-upload-status]');
            const button = form.querySelector('button');
            button.disabled = true;
            progress.hidden = false;
            status.textContent = 'Mengunggah…';
            xhr.open('POST', form.action);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) progress.value = Math.round(e.loaded * 100 / e.total);
            });
            const fail = (message) => { button.disabled = false; status.textContent = message; };
            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) { window.location.reload(); return; }
                let message = 'Unggahan gagal. Periksa ukuran file atau coba kembali. Jawaban tersimpan tetap tersedia.';
                try { const data = JSON.parse(xhr.responseText); message = Object.values(data.errors || {}).flat().join(' ') || data.message || message; } catch (_) { /* Non-JSON server errors use the fallback. */ }
                fail(message);
            });
            xhr.addEventListener('error', () => fail('Koneksi terputus. Coba unggah kembali.'));
            xhr.send(new FormData(form));
        });
    });
}
