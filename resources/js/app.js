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
const resendForm = document.querySelector('[data-email-resend]');
if (resendForm) {
    const button = resendForm.querySelector('button');
    const status = resendForm.querySelector('[data-resend-countdown]');
    const readyAt = Date.now() + Number(resendForm.dataset.retrySeconds) * 1000;
    const updateCountdown = () => {
        const seconds = Math.max(0, Math.ceil((readyAt - Date.now()) / 1000));
        button.disabled = seconds > 0;
        status.textContent = seconds > 0 ? `Tunggu ${seconds} detik sebelum mengirim ulang.` : '';
        return seconds;
    };
    const interval = setInterval(() => { if (!updateCountdown()) clearInterval(interval); }, 1000);
    updateCountdown();
    resendForm.addEventListener('submit', (event) => {
        if (resendForm.dataset.submitting) { event.preventDefault(); return; }
        resendForm.dataset.submitting = 'true';
        clearInterval(interval);
        button.disabled = true;
        status.textContent = 'Menjadwalkan pengiriman…';
    });
}
