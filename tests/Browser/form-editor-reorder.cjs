const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync, spawn } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

function otp(secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = [...secret].map(char => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const digest = crypto.createHmac('sha1', Buffer.from(bytes)).update(counter).digest();
    return ((digest.readUInt32BE(digest[19] & 15) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}

(async () => {
    const output = path.resolve('storage/framework/testing', `forms-browser-reorder-${crypto.randomUUID()}`);
    for (const directory of ['logs', 'framework/sessions', 'framework/views', 'framework/cache/data']) {
        fs.mkdirSync(path.join(output, directory), { recursive: true });
    }
    const database = path.join(output, 'candidate-forms-browser.sqlite');
    fs.writeFileSync(database, '');
    const port = String(8100 + crypto.randomInt(1000));
    const base = `http://127.0.0.1:${port}`;
    const env = { ...process.env, APP_ENV: 'testing', APP_URL: base, LARAVEL_STORAGE_PATH: output,
        APP_CONFIG_CACHE: path.join(output, 'config.php'), APP_ROUTES_CACHE: path.join(output, 'routes.php'),
        DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '', SESSION_DRIVER: 'file', SESSION_SECURE_COOKIE: 'false',
        CACHE_STORE: 'array', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', CANDIDATE_FORMS_ENABLED: 'true' };
    execFileSync('php', ['tests/Browser/prepare-forms.php'], { env, stdio: 'pipe', windowsHide: true });
    const fixture = JSON.parse(fs.readFileSync(path.join(output, 'browser-fixtures.json'), 'utf8'));
    const server = spawn('php', ['-S', `127.0.0.1:${port}`, path.resolve('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {
        cwd: path.resolve('public'), env, stdio: 'ignore', windowsHide: true,
    });
    let browser;
    try {
        const expires = Date.now() + 20000;
        while (true) {
            try { if ((await fetch(`${base}/up`)).ok) break; } catch {}
            if (Date.now() > expires) throw new Error('Isolated form server did not start.');
            await new Promise(resolve => setTimeout(resolve, 150));
        }
        browser = await chromium.launch({ headless: true, channel: 'msedge' });
        const page = await browser.newPage();
        page.setDefaultTimeout(10000);
        await page.route('**/build/assets/theme-*.css', async route => {
            const response = await route.fetch();
            await route.fulfill({ response, body: (await response.text()).replaceAll('.whitespace-pre-line{white-space:pre-line}', '') });
        });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(`${base}/admin/login`);
        await page.locator('input[type=email]').fill(fixture.admin);
        await page.locator('input[type=password]').fill(fixture.password);
        await page.locator('button[type=submit]').click();
        await page.locator('input[autocomplete=one-time-code]').fill(otp(fixture.secret));
        await page.locator('button[type=submit]').click();
        await page.waitForURL(/\/admin\/recruitment/);
        await page.goto(`${base}/admin/candidate-forms`);
        await page.getByRole('button', { name: 'Edit draf', exact: true }).click();
        const panels = page.locator('.form-editor-field');
        const label = index => panels.nth(index).getByRole('textbox', { name: 'Label pertanyaan', exact: true });
        const labels = () => page.getByRole('textbox', { name: 'Label pertanyaan', exact: true }).evaluateAll(inputs => inputs.map(input => input.value));
        const action = async button => {
            const response = page.waitForResponse(response => response.url().includes('/update') && response.request().method() === 'POST');
            await button.click();
            await (await response).finished();
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        };
        await panels.nth(3).getByRole('spinbutton', { name: 'Maksimum MB/file', exact: true }).waitFor();
        const helpText = 'Bantuan domisili\nBaris kedua\n\n<strong>Contoh</strong>';
        await label(0).fill('Domisili direvisi');
        await panels.nth(0).getByRole('textbox', { name: 'Teks bantuan', exact: true }).fill(helpText);
        await action(panels.nth(0).getByRole('button', { name: 'Pindah ke bawah' }));
        assert.deepEqual(await labels(), ['Tanggal lahir', 'Domisili direvisi', 'Pendidikan terakhir', 'Curriculum Vitae']);
        assert.equal(await panels.nth(1).getByRole('textbox', { name: 'Teks bantuan', exact: true }).inputValue(), helpText);
        await label(1).fill('Domisili setelah pindah');
        await action(page.getByRole('button', { name: 'Simpan draf', exact: true }));
        assert.equal(await panels.first().getByRole('button', { name: 'Pindah ke atas' }).isDisabled(), true);
        assert.equal(await panels.last().getByRole('button', { name: 'Pindah ke bawah' }).isDisabled(), true);
        await page.reload();
        await page.getByRole('button', { name: 'Edit draf', exact: true }).click();
        await label(0).waitFor();
        assert.deepEqual(await labels(), ['Tanggal lahir', 'Domisili setelah pindah', 'Pendidikan terakhir', 'Curriculum Vitae']);
        assert.equal(await panels.nth(1).getByRole('textbox', { name: 'Teks bantuan', exact: true }).inputValue(), helpText);
        await action(panels.nth(1).getByRole('button', { name: 'Pindah ke atas' }));
        assert.deepEqual(await labels(), ['Domisili setelah pindah', 'Tanggal lahir', 'Pendidikan terakhir', 'Curriculum Vitae']);
        await action(panels.nth(0).getByRole('button', { name: 'Duplikasi', exact: true }));
        assert.deepEqual(await labels(), ['Domisili setelah pindah', 'Domisili setelah pindah (Salinan)', 'Tanggal lahir', 'Pendidikan terakhir', 'Curriculum Vitae']);
        await action(panels.nth(0).getByRole('button', { name: 'Hapus', exact: true }));
        await label(1).fill('Tanggal lahir setelah hapus');
        await action(page.getByRole('button', { name: 'Simpan draf', exact: true }));
        await page.reload();
        await page.getByRole('button', { name: 'Edit draf', exact: true }).click();
        await label(0).waitFor();
        assert.deepEqual(await labels(), ['Domisili setelah pindah (Salinan)', 'Tanggal lahir setelah hapus', 'Pendidikan terakhir', 'Curriculum Vitae']);
        const description = 'Petunjuk pertama\nPetunjuk kedua\n\n<strong>Contoh</strong>';
        await page.getByRole('textbox', { name: 'Deskripsi & petunjuk', exact: true }).fill(description);
        await action(page.getByRole('button', { name: 'Pratinjau', exact: true }));
        const preview = page.locator('.panel').filter({ has: page.getByText('Pratinjau · Tidak menyimpan jawaban', { exact: true }) });
        const previewDescription = preview.locator('h1 + p');
        const previewHelp = preview.locator('p[id^="help-"]').first();
        assert.equal(await previewDescription.innerText(), description);
        assert.equal(await previewHelp.innerText(), helpText);
        assert.equal(await previewDescription.locator('strong').count(), 0);
        assert.equal(await previewHelp.locator('strong').count(), 0);
        await action(page.getByRole('button', { name: 'Simpan & terbitkan versi', exact: true }));
        await page.getByRole('combobox', { name: 'Posisi', exact: true }).selectOption({ index: 1 });
        await page.getByRole('combobox', { name: 'Periode', exact: true }).selectOption({ index: 1 });
        await action(page.getByRole('button', { name: 'Buat tautan publik', exact: true }));
        const publicUrl = await page.getByRole('textbox', { name: 'Tautan publik', exact: true }).first().inputValue();
        await page.goto(publicUrl);
        const publicDescription = page.locator('.heading h1 + p');
        const publicHelp = page.locator('candidate-form-fields p[id^="help-"]').first();
        assert.equal(await publicDescription.innerText(), description);
        assert.equal(await publicHelp.innerText(), helpText);
        assert.equal(await publicDescription.locator('strong').count(), 0);
        assert.equal(await publicHelp.locator('strong').count(), 0);
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await publicDescription.innerText(), description);
        assert.equal(await publicHelp.innerText(), helpText);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
        assert.deepEqual(errors, []);
        console.log('Passed: reorder and saved values; multiline preview/public form matches even with older admin CSS and keeps HTML escaped.');
    } finally {
        if (browser) await browser.close();
        server.kill();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
