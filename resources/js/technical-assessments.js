import Swal from 'sweetalert2';

const start = document.querySelector('[data-assessment-start]');
if (start) {
    start.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = start.querySelector('button');
        if (button.disabled) return;
        button.disabled = true;
        const result = await Swal.fire({ title: 'Mulai tahap ini?', text: 'Timer tetap berjalan saat halaman ditutup. Tahap yang selesai akan dikunci.', icon: 'question', showCancelButton: true, confirmButtonText: 'Mulai', cancelButtonText: 'Belum siap' });
        if (result.isConfirmed) {
            button.textContent = 'Memulai…';
            start.submit();
        } else {
            button.disabled = false;
        }
    });
}

const waiting = document.querySelector('[data-assessment-wait]');
if (waiting) {
    setInterval(async () => {
        try {
            const response = await fetch(waiting.dataset.stateUrl, { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(10000) });
            if (!response.ok) return;
            const state = await response.json();
            if (state.active || state.completed || state.section !== Number(waiting.dataset.section)) window.location.reload();
        } catch { /* Retry while waiting for the assessor. */ }
    }, 5000);
}

const form = document.querySelector('[data-assessment-form]');
if (form) {
    const status = form.querySelector('[data-assessment-status]');
    const timer = form.querySelector('[data-assessment-timer]');
    const activityStatus = form.querySelector('[data-assessment-activity-status]');
    const token = form.querySelector('[name="_token"]').value;
    const section = Number(form.dataset.section);
    let revision = Number(form.dataset.revision);
    let endsAt = performance.now() + Number(form.dataset.remaining) * 1000;
    let dirty = false;
    let pending = false;
    let stopped = false;
    let finishing = false;
    let lastTimeoutCheck = 0;
    const warningKey = `technical-assessment-two-minute:${form.dataset.endpoint}:${section}`;
    let warnedAtTwoMinutes = false;
    try { warnedAtTwoMinutes = window.sessionStorage.getItem(warningKey) === '1'; } catch { /* Continue when browser storage is unavailable. */ }
    let debounce;
    const fields = [...form.querySelectorAll('[name^="answers["]')];
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token };
    const collect = () => Object.fromEntries([...new FormData(form)].filter(([name]) => name.startsWith('answers[')).map(([name, value]) => [name.slice(8, -1), value]));
    const progress = () => {
        const answered = Object.values(collect()).filter(value => value.trim() !== '').length;
        form.querySelector('[data-assessment-progress]').textContent = `${answered} / ${new Set(fields.map(field => field.name)).size} jawaban terisi`;
    };
    const lock = () => { fields.forEach(field => field.disabled = true); form.querySelectorAll('[name="action"]').forEach(button => button.disabled = true); };
    const applyState = (state, allowRevision = false) => {
        if (state.completed || state.section !== section || !state.active || Boolean(state.reasoning_locked) !== (form.dataset.reasoningLocked === 'true') || Boolean(state.simulation_locked) !== (form.dataset.simulationLocked === 'true')) {
            stopped = true;
            if (state.simulation_locked && form.dataset.simulationLocked !== 'true') window.location.hash = 'simulation-facts';
            window.location.reload();
            return false;
        }
        if (!allowRevision && state.revision !== revision) {
            stopped = true;
            lock();
            status.textContent = 'Jawaban berubah di tab lain. Salin catatan yang belum tersimpan, lalu muat ulang halaman.';
            return false;
        }
        revision = state.revision;
        form.querySelector('[name="revision"]').value = revision;
        endsAt = performance.now() + Math.max(0, state.remaining) * 1000;
        return true;
    };
    async function save(action = 'save') {
        if (pending || stopped || (finishing && action === 'save')) return false;
        pending = true;
        dirty = false;
        status.textContent = 'Menyimpan jawaban…';
        const answers = collect();
        if (action === 'finish' || action === 'next') { finishing = true; fields.forEach(field => field.disabled = true); }
        try {
            const response = await fetch(form.dataset.endpoint, { method: 'POST', headers, credentials: 'same-origin', signal: AbortSignal.timeout(15000), body: JSON.stringify({ action, section, revision, answers }) });
            const data = await response.json();
            if (!response.ok) {
                if (response.status === 409) { stopped = true; lock(); }
                throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Penyimpanan gagal. Coba kembali.');
            }
            if (!applyState(data, true)) return false;
            status.textContent = dirty ? 'Ada perubahan yang belum tersimpan' : 'Jawaban tersimpan';
            return true;
        } catch (error) {
            dirty = true;
            status.textContent = `${error.message} Jawaban belum tersimpan; periksa koneksi. Timer tetap berjalan.`;
            return false;
        } finally {
            pending = false;
            if (!stopped && finishing) { fields.forEach(field => field.disabled = false); finishing = false; }
        }
    }
    form.addEventListener('input', () => {
        dirty = true;
        progress();
        status.textContent = 'Ada perubahan yang belum tersimpan';
        clearTimeout(debounce);
        debounce = setTimeout(() => save(), 600);
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (pending || stopped || finishing) return;
        const action = event.submitter?.value || 'save';
        if (action === 'finish' || action === 'next') {
            finishing = true;
            const simulation = form.dataset.simulation === 'true';
            const result = await Swal.fire({ title: action === 'next' ? (simulation ? 'Kunci jawaban prioritas?' : 'Kunci jawaban B01–B04?') : 'Kunci tahap ini?', text: action === 'next' ? (simulation ? 'Jawaban awal tidak dapat diubah lagi. Fakta baru dibuka di bawah dengan sisa waktu dari total 8 menit.' : 'Keempat jawaban tidak dapat diubah lagi. Penjelasan proses ditampilkan dengan timer tahap yang sama.') : 'Jawaban kosong tetap dianggap kosong. Anda tidak dapat kembali ke tahap ini.', icon: 'question', showCancelButton: true, confirmButtonText: action === 'next' ? (simulation ? 'Lanjut' : 'Next') : 'Selesaikan tahap', cancelButtonText: 'Periksa lagi' });
            finishing = false;
            if (!result.isConfirmed) return;
            if (pending) { status.textContent = 'Tunggu penyimpanan selesai, lalu tekan tombol kembali.'; return; }
        }
        clearTimeout(debounce);
        await save(action);
    });
    const eventTimes = new Map();
    async function recordActivity(event) {
        if (stopped) return;
        const last = eventTimes.get(event) || 0;
        if (Date.now() - last < 3000) return;
        eventTimes.set(event, Date.now());
        try {
            const response = await fetch(form.dataset.activityUrl, { method: 'POST', headers, keepalive: true, credentials: 'same-origin', body: JSON.stringify({ event, section }) });
            if (!response.ok) throw new Error();
            if (event !== 'page_loaded') activityStatus.textContent = 'Aktivitas dicatat untuk klarifikasi HR. Lanjutkan tes secara mandiri.';
        } catch { activityStatus.textContent = 'Catatan aktivitas belum terkirim. Periksa koneksi; timer tetap berjalan.'; }
    }
    document.addEventListener('visibilitychange', () => { if (document.hidden) { recordActivity('tab_hidden'); if (dirty) save(); } });
    window.addEventListener('blur', () => recordActivity('window_blur'));
    window.addEventListener('offline', () => { status.textContent = 'Koneksi terputus. Jangan tutup halaman; jawaban akan dicoba simpan kembali saat terhubung. Timer tetap berjalan.'; });
    window.addEventListener('online', () => { recordActivity('connection_restored'); if (dirty) save(); });
    let wasFullscreen = Boolean(document.fullscreenElement);
    document.addEventListener('fullscreenchange', () => {
        if (wasFullscreen && !document.fullscreenElement) recordActivity('fullscreen_exit');
        wasFullscreen = Boolean(document.fullscreenElement);
    });
    form.querySelector('[data-assessment-fullscreen]').addEventListener('click', async () => {
        try {
            if (!document.documentElement.requestFullscreen) throw new Error('Mode layar penuh tidak didukung perangkat ini. Anda tetap dapat mengerjakan tes.');
            await document.documentElement.requestFullscreen();
        } catch (error) { await Swal.fire({ icon: 'info', title: 'Layar penuh', text: error.message }); }
    });
    setInterval(async () => {
        if (stopped) return;
        const remaining = Math.max(0, Math.ceil((endsAt - performance.now()) / 1000));
        timer.textContent = `${String(Math.floor(remaining / 60)).padStart(2, '0')}:${String(remaining % 60).padStart(2, '0')}`;
        timer.classList.toggle('assessment-time-low', remaining <= 60);
        if (form.dataset.twoMinuteWarning === 'true' && remaining > 0 && remaining <= 120 && !warnedAtTwoMinutes) {
            warnedAtTwoMinutes = true;
            try { window.sessionStorage.setItem(warningKey, '1'); } catch { /* Keep the reminder once per page when storage is unavailable. */ }
            Swal.fire({ icon: 'warning', title: 'Sisa waktu 2 menit', text: 'Segera selesaikan bagian Kecenderungan Perilaku. Timer tetap berjalan.', confirmButtonText: 'Lanjutkan mengerjakan' });
        }
        if (!remaining && !pending && !finishing && performance.now() - lastTimeoutCheck >= 5000) {
            lastTimeoutCheck = performance.now();
            status.textContent = 'Waktu berakhir. Memeriksa penguncian tahap di server…';
            await save();
        }
    }, 250);
    setInterval(() => { if (dirty && !finishing) save(); }, 5000);
    setInterval(async () => {
        if (stopped || pending || finishing) return;
        pending = true;
        try {
            const response = await fetch(form.dataset.stateUrl, { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(10000) });
            if (response.ok) applyState(await response.json());
        } catch { /* Autosave reports connection failures without clearing answers. */ }
        finally { pending = false; }
    }, 10000);
    window.addEventListener('beforeunload', (event) => { if (dirty && !stopped) { event.preventDefault(); event.returnValue = ''; } });
    progress();
    recordActivity('page_loaded');
}
