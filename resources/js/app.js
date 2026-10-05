import Swal from 'sweetalert2';

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
        if (event.submitter?.value === 'review') {
            document.querySelectorAll('[data-required-error]').forEach((error) => error.remove());
            answerForm.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
            const missing = [];
            const markMissing = (target, message) => {
                const error = document.createElement('p');
                error.className = 'field-error';
                error.dataset.requiredError = '';
                error.setAttribute('role', 'alert');
                error.textContent = message;
                target.append(error);
                missing.push({ target, message });
            };
            const name = answerForm.elements.namedItem('name');
            if (!name.value.trim()) markMissing(name.closest('label'), 'Nama lengkap wajib diisi.');
            answerForm.querySelectorAll('[data-required="true"]').forEach((group) => {
                const inputs = [...group.querySelectorAll('input, select, textarea')];
                const filled = inputs.some((input) => ['checkbox', 'radio'].includes(input.type) ? input.checked : input.value.trim());
                if (!filled) {
                    inputs.forEach((input) => input.setAttribute('aria-invalid', 'true'));
                    markMissing(group, `${group.dataset.fieldLabel} wajib diisi.`);
                }
            });
            document.querySelectorAll('[data-required-document="true"][data-document-ready="false"]').forEach((group) => {
                markMissing(group, `Unggah ${group.dataset.fieldLabel} dan tunggu pemeriksaan file selesai.`);
            });
            const consent = answerForm.elements.namedItem('consent');
            if (!consent.checked) markMissing(consent.closest('label'), 'Persetujuan pemrosesan data wajib dicentang.');
            if (missing.length) {
                event.preventDefault();
                feedback.hidden = false;
                feedback.setAttribute('role', 'alert');
                feedback.textContent = `Belum dapat melanjutkan. ${missing.map((item) => item.message).join(' ')}`;
                const first = missing[0].target;
                first.querySelector('input, select, textarea')?.focus({ preventScroll: true });
                first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            feedback.hidden = true;
        }
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


const psychometricFullscreenRequired = !/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);

async function enterPsychometricFullscreen() {
    if (!psychometricFullscreenRequired) return;
    if (document.fullscreenElement === document.documentElement) return;
    if (!document.fullscreenEnabled || !document.documentElement.requestFullscreen) {
        throw new Error('Browser ini tidak mendukung layar penuh. Gunakan browser desktop yang mendukung mode layar penuh untuk mengerjakan tes.');
    }
    try {
        await document.documentElement.requestFullscreen();
    } catch {
        throw new Error('Mode layar penuh belum diizinkan. Izinkan layar penuh lalu coba kembali.');
    }
    if (document.fullscreenElement !== document.documentElement) {
        throw new Error('Masuk mode layar penuh terlebih dahulu untuk melanjutkan.');
    }
}

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-psych-start]');
    if (!form) return;
    event.preventDefault();
    if (form.dataset.pending) return;
    form.dataset.pending = 'true';
    const button = form.querySelector('button');
    button.disabled = true;
    try {
        const confirmation = await Swal.fire({
            title: 'Sudah siap melakukan tes ?',
            text: psychometricFullscreenRequired
                ? 'Perpindahan tab, kehilangan fokus dan keluar layar penuh dicatat untuk HR. Gunakan satu tab tes. Tes wajib dalam layar penuh timer dimulai setelah konfirmasi dan layar penuh aktif.'
                : 'Perpindahan tab dan kehilangan fokus dicatat untuk HR. Gunakan satu tab tes. Timer dimulai setelah konfirmasi tes di HP dapat dikerjakan tanpa mode layar penuh.',
            icon: 'question', showCancelButton: true,
            confirmButtonText: 'Ya, mulai tes', cancelButtonText: 'Belum siap',
            allowOutsideClick: false,
            preConfirm: async () => {
                try {
                    await enterPsychometricFullscreen();
                    return true;
                } catch (error) {
                    Swal.showValidationMessage(error.message);
                    return false;
                }
            },
        });
        if (!confirmation.isConfirmed) return;
        if (psychometricFullscreenRequired && document.fullscreenElement !== document.documentElement) {
            throw new Error('Layar penuh telah ditutup. Coba mulai kembali dalam mode layar penuh.');
        }
        button.textContent = 'Memulai tes…';
        const response = await fetch(form.getAttribute('action'), {
            method: 'POST', credentials: 'same-origin', body: new FormData(form),
            headers: { Accept: 'text/html' }, signal: AbortSignal.timeout(20000),
        });
        if (!response.ok) throw new Error('Tes belum dapat ditampilkan. Muat ulang halaman untuk memeriksa status tes sebelum mencoba lagi.');
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const content = page.querySelector('#main');
        if (!content || !page.querySelector('[data-psychometric-form]')) {
            window.location.reload();
            return;
        }
        document.querySelector('#main').replaceChildren(...content.childNodes);
        initializePsychometricForm();
        window.scrollTo(0, 0);
    } catch (error) {
        await Swal.fire({ icon: 'error', title: 'Tidak dapat membuka tes', text: `${error.message} Jika permintaan sudah diterima server, timer tetap berjalan.` });
    } finally {
        delete form.dataset.pending;
        button.disabled = false;
        button.textContent = form.dataset.startLabel;
    }
});

function initializePsychometricForm() {
    document.querySelectorAll('[data-psych-start] button').forEach(button => button.disabled = false);
    const psychForm = document.querySelector('[data-psychometric-form]');
    if (psychForm) {
        const gate = document.querySelector('[data-psych-fullscreen-gate]');
        const synchronizeFullscreen = () => {
            const active = !psychometricFullscreenRequired || document.fullscreenElement === document.documentElement;
            psychForm.hidden = !active;
            psychForm.inert = !active;
            gate.hidden = active;
        };
        document.addEventListener('fullscreenchange', synchronizeFullscreen);
        gate.querySelector('button').addEventListener('click', async () => {
            try {
                await enterPsychometricFullscreen();
                synchronizeFullscreen();
            } catch (error) {
                await Swal.fire({ icon: 'warning', title: 'Layar penuh diperlukan', text: error.message });
            }
        });
        synchronizeFullscreen();
        const status = psychForm.querySelector('[data-psych-status]');
        const timer = psychForm.querySelector('[data-psych-timer]');
        const section = Number(psychForm.dataset.section);
        const started = performance.now();
        const duration = Number(psychForm.dataset.remaining) * 1000;
        let revision = Number(psychForm.dataset.revision);
        let pending = false;
        let dirty = false;
        let stopped = false;
        let debounce;
        const remaining = () => Math.max(0, Math.ceil((duration - (performance.now() - started)) / 1000));
        const activityStatus = psychForm.querySelector('[data-psych-activity-status]');
        const recordActivity = async (event) => {
            if (stopped || remaining() === 0) return;
            activityStatus.textContent = 'Aktivitas keluar halaman terdeteksi. Kembali ke tes dan kerjakan mandiri; timer tetap berjalan.';
            try {
                const response = await fetch(psychForm.dataset.activityEndpoint, {
                    method: 'POST', credentials: 'same-origin', keepalive: true,
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': psychForm.querySelector('[name="_token"]').value },
                    body: JSON.stringify({ event, section }),
                });
                if (!response.ok) throw new Error('activity failed');
                activityStatus.textContent = 'Aktivitas keluar halaman dicatat untuk HR. Lanjutkan tes secara mandiri; timer tetap berjalan.';
            } catch {
                activityStatus.textContent = 'Catatan aktivitas belum terkirim. Periksa koneksi dan lanjutkan tes secara mandiri.';
            }
        };
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) recordActivity('tab_hidden');
        });
        window.addEventListener('blur', () => recordActivity('window_blur'));
        let wasFullscreen = document.fullscreenElement === document.documentElement;
        document.addEventListener('fullscreenchange', () => {
            const isFullscreen = document.fullscreenElement === document.documentElement;
            if (wasFullscreen && !isFullscreen) recordActivity('fullscreen_exit');
            wasFullscreen = isFullscreen;
        });
        const collect = () => {
            const answers = {};
            psychForm.querySelectorAll('input[name^="answers["]:checked').forEach(input => {
                const number = input.name.match(/answers\[(\d+)\]/)[1];
                (answers[number] ??= []).push(input.value);
            });
            return answers;
        };
        async function save(action = 'save') {
            if (pending || stopped) return;
            pending = true;
            dirty = false;
            status.textContent = 'Menyimpan jawaban…';
            psychForm.querySelectorAll('button').forEach(button => button.disabled = true);
            if (action === 'finish') psychForm.querySelectorAll('input[name^="answers["]').forEach(input => input.disabled = true);
            try {
                const response = await fetch(psychForm.dataset.endpoint, {
                    method: 'POST', credentials: 'same-origin', signal: AbortSignal.timeout(15000),
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': psychForm.querySelector('[name="_token"]').value },
                    body: JSON.stringify({ action, section, revision, answers: collect() }),
                });
                if (!response.ok) {
                    if ([401, 403, 409, 419].includes(response.status)) {
                        stopped = true;
                        psychForm.querySelectorAll('input[name^="answers["]').forEach(input => input.disabled = true);
                        status.textContent = 'Sesi atau bagian berubah. Muat ulang halaman untuk melanjutkan.';
                        return;
                    }
                    throw new Error('save failed');
                }
                const result = await response.json();
                revision = result.revision;
                psychForm.querySelector('[name="revision"]').value = revision;
                if (action === 'finish' || result.completed || result.section !== section || !result.active) {
                    stopped = true;
                    window.location.reload();
                    return;
                }
                status.textContent = dirty ? 'Ada perubahan, menyimpan lagi…' : 'Semua jawaban tersimpan';
            } catch {
                dirty = true;
                status.textContent = 'Gagal menyimpan. Periksa koneksi; jawaban akan dicoba lagi selama waktu tersedia.';
            } finally {
                pending = false;
                if (!stopped) {
                    psychForm.querySelectorAll('button, input[name^="answers["]').forEach(input => input.disabled = false);
                    if (dirty && remaining() > 0) debounce = setTimeout(() => save(), 1500);
                }
            }
        }
        psychForm.addEventListener('change', event => {
            if (!event.target.name.startsWith('answers[')) return;
            const fieldset = event.target.closest('fieldset');
            if (fieldset.querySelectorAll('input:checked').length > Number(psychForm.dataset.choices)) {
                event.target.checked = false;
                status.textContent = `Pilih maksimal ${psychForm.dataset.choices} jawaban untuk satu soal.`;
                return;
            }
            dirty = true;
            status.textContent = 'Ada perubahan yang belum tersimpan';
            clearTimeout(debounce);
            debounce = setTimeout(() => save(), 350);
        });
        psychForm.addEventListener('submit', event => {
            event.preventDefault();
            clearTimeout(debounce);
            save(event.submitter?.value === 'finish' ? 'finish' : 'save');
        });
        psychForm.querySelector('[data-psych-review]').addEventListener('click', () => psychForm.querySelector('[data-psych-confirm]').hidden = false);
        psychForm.querySelector('[data-psych-cancel]').addEventListener('click', () => psychForm.querySelector('[data-psych-confirm]').hidden = true);
        window.addEventListener('beforeunload', event => {
            if ((dirty || pending) && !stopped) { event.preventDefault(); event.returnValue = ''; }
        });
        const tick = setInterval(() => {
            const seconds = remaining();
            timer.textContent = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
            if (!seconds && !pending) {
                clearInterval(tick);
                stopped = true;
                window.location.reload();
            }
        }, 250);
    }
}

initializePsychometricForm();
