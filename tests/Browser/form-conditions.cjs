const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { readFileSync } = require('node:fs');
const assert = require('node:assert/strict');
const path = require('node:path');

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'msedge' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.setContent(`<main>
            <div id="form-client-feedback" hidden></div>
            <form data-track-changes>
                <input name="name" value="Kandidat">
                <input type="checkbox" name="consent" checked>
                <span data-dirty-status></span>
                <candidate-form-fields>
                    <div data-form-field data-field-id="experience" data-field-type="radio" data-condition="null">
                        <input type="radio" name="answers[experience]" value="Ya" aria-label="Ya">
                        <input type="radio" name="answers[experience]" value="Tidak" aria-label="Tidak">
                    </div>
                    <div data-form-field data-field-id="section" data-field-type="section" data-condition='{"field_id":"experience","value":"Ya"}'>Pengalaman kerja</div>
                    <div data-form-field data-field-id="company" data-field-type="text" data-condition="null">
                        <fieldset data-required="true" data-field-label="Nama perusahaan"><input name="answers[company]" aria-label="Nama perusahaan"></fieldset>
                    </div>
                    <div data-form-field data-field-id="skills" data-field-type="checkbox" data-condition="null">
                        <input type="checkbox" name="answers[skills][]" value="PHP" aria-label="PHP">
                    </div>
                    <div data-form-field data-field-id="detail" data-field-type="text" data-condition='{"field_id":"skills","value":"PHP"}'>
                        <input name="answers[detail]" aria-label="Detail PHP">
                    </div>
                    <div data-form-field data-field-id="file" data-field-type="file" data-condition="null">Surat pengalaman</div>
                    <div data-form-field data-field-id="next" data-field-type="section" data-condition="null">Lainnya</div>
                    <div data-form-field data-field-id="city" data-field-type="text" data-condition="null">
                        <input name="answers[city]" aria-label="Domisili">
                    </div>
                </candidate-form-fields>
                <button name="action" value="review">Tinjau</button>
            </form>
            <div data-upload-field-id="file" data-required-document="true" data-document-ready="false" data-field-label="Surat pengalaman">
                <form data-document-action><input type="file" aria-label="Surat pengalaman"><button>Unggah</button></form>
            </div>
        </main>`);
        await page.addScriptTag({ content: readFileSync(path.join(__dirname, '../../resources/js/form-conditions.js'), 'utf8') });
        await page.addScriptTag({ content: readFileSync(path.join(__dirname, '../../resources/js/app.js'), 'utf8').replace(/^import .+;\r?\n/gm, '') });
        const company = page.getByRole('textbox', { name: 'Nama perusahaan', includeHidden: true });
        const fileZone = page.locator('[data-upload-field-id]');
        assert.equal(await company.isVisible(), false);
        assert.equal(await company.isDisabled(), true);
        assert.equal(await fileZone.isVisible(), false);
        assert.equal(await page.getByRole('textbox', { name: 'Domisili' }).isVisible(), true);

        await page.getByRole('radio', { name: 'Ya', exact: true }).check();
        assert.equal(await company.isVisible(), true);
        assert.equal(await company.isEnabled(), true);
        assert.equal(await fileZone.isVisible(), true);
        await page.getByRole('button', { name: 'Tinjau' }).click();
        assert.match(await page.locator('#form-client-feedback').textContent(), /Nama perusahaan wajib diisi/);
        assert.match(await page.locator('#form-client-feedback').textContent(), /Unggah Surat pengalaman/);
        await company.fill('PT Contoh');
        await page.getByRole('checkbox', { name: 'PHP', exact: true }).check();
        await page.getByRole('textbox', { name: 'Detail PHP', exact: true }).fill('Laravel');
        await page.getByRole('radio', { name: 'Tidak', exact: true }).check();
        assert.equal(await fileZone.isVisible(), false);
        assert.equal(await company.isVisible(), false);
        assert.equal(await page.getByRole('textbox', { name: 'Detail PHP', includeHidden: true }).isVisible(), false);
        assert.deepEqual(await page.locator('[data-track-changes]').evaluate((form) => [...new FormData(form).entries()]), [
            ['name', 'Kandidat'], ['consent', 'on'], ['answers[experience]', 'Tidak'], ['answers[city]', ''],
        ]);
        assert.equal(await page.locator('[data-required-error]').count(), 0);
        await page.getByRole('radio', { name: 'Ya', exact: true }).check();
        assert.equal(await company.inputValue(), 'PT Contoh');
        assert.equal(await page.getByRole('textbox', { name: 'Detail PHP' }).inputValue(), 'Laravel');

        await page.locator('[data-field-id="experience"]').evaluate((group) => {
            group.innerHTML = '<select name="answers[experience]" aria-label="Pengalaman"><option>Ya</option><option>Tidak</option></select>';
        });
        await page.getByRole('combobox', { name: 'Pengalaman' }).selectOption('Tidak');
        assert.equal(await company.isVisible(), false);
        await page.getByRole('combobox', { name: 'Pengalaman' }).selectOption('Ya');
        assert.equal(await company.isVisible(), true);

        await page.evaluate(() => {
            const preview = document.createElement('candidate-form-fields');
            preview.innerHTML = '<div data-form-field data-field-id="preview" data-field-type="text" data-condition="null"><input disabled aria-label="Tetap nonaktif"></div>';
            document.querySelector('main').append(preview);
        });
        assert.equal(await page.getByRole('textbox', { name: 'Tetap nonaktif' }).isDisabled(), true);
        assert.deepEqual(errors, []);
        console.log('Passed: conditional sections, nested checkbox rules, hidden validation, uploads, draft values, and dynamic preview.');
    } finally {
        await browser.close();
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
