class CandidateFormFields extends HTMLElement {
    connectedCallback() {
        this.initialDisabled = new WeakMap();
        this.addEventListener('change', () => this.updateVisibility());
        queueMicrotask(() => this.updateVisibility());
    }

    updateVisibility() {
        const visible = new Map();
        let sectionVisible = true;
        this.querySelectorAll('[data-form-field]').forEach((group) => {
            const condition = JSON.parse(group.dataset.condition);
            const source = condition?.field_id ? visible.get(condition.field_id) : null;
            const matches = !condition?.field_id || Boolean(source && [...source.querySelectorAll('input, select')].some((input) => {
                return !input.disabled && input.value === condition.value && (!['radio', 'checkbox'].includes(input.type) || input.checked);
            }));
            if (group.dataset.fieldType === 'section') sectionVisible = matches;
            const shown = sectionVisible && matches;
            this.setVisibility(group, shown);
            if (shown) visible.set(group.dataset.fieldId, group);
            this.closest('main')?.querySelectorAll('[data-upload-field-id]').forEach((zone) => {
                if (zone.dataset.uploadFieldId === group.dataset.fieldId) this.setVisibility(zone, shown);
            });
        });
    }

    setVisibility(group, shown) {
        group.hidden = !shown;
        group.querySelectorAll('input, select, textarea, button').forEach((input) => {
            if (!this.initialDisabled.has(input)) this.initialDisabled.set(input, input.disabled);
            input.disabled = !shown || this.initialDisabled.get(input);
        });
        if (!shown) {
            group.querySelectorAll('[data-required-error]').forEach((error) => error.remove());
            group.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
        }
    }
}

customElements.define('candidate-form-fields', CandidateFormFields);
