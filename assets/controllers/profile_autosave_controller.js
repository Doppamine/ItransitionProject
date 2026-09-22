import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['status'];
    static values = { url: String, version: Number, token: String };

    connect() {
        this.dirty = new Map();
        this.saving = false;
        this.conflict = false;
        this.timer = window.setInterval(() => this.save(), 7000);
    }

    disconnect() {
        window.clearInterval(this.timer);
    }

    changed(event) {
        if (this.conflict) return;
        const editor = event.target.closest('[data-definition-id]');
        if (!editor || !event.target.matches('[data-profile-part]')) return;
        this.dirty.set(editor.dataset.definitionId, this.readValue(editor));
        editor.querySelector('[data-profile-error]').textContent = '';
        this.show('dirty', 'Unsaved changes');
    }

    guardNavigation(event) {
        if (this.conflict || this.saving || this.dirty.size > 0) {
            event.preventDefault();
            this.show(this.conflict ? 'conflict' : 'dirty', this.conflict ? 'Profile changed elsewhere. Reload to continue.' : 'Unsaved changes — wait for save');
        }
    }

    readValue(editor) {
        const start = editor.querySelector('[data-profile-part="start"]');
        if (start) return { start: start.value, end: editor.querySelector('[data-profile-part="end"]').value };
        return editor.querySelector('[data-profile-part="value"]').value;
    }

    async save() {
        if (this.saving || this.conflict || this.dirty.size === 0) return;
        const sent = new Map(this.dirty);
        this.saving = true;
        this.show('saving', 'Saving…');

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
                credentials: 'same-origin',
                body: JSON.stringify({ version: this.versionValue, changes: Object.fromEntries(sent) }),
            });
            const result = await response.json();
            if (response.status === 409) {
                this.conflict = true;
                this.show('conflict', 'Profile changed elsewhere. Reload to continue.');
                return;
            }
            if (response.status === 422) {
                if (result.field) {
                    const editor = [...this.element.querySelectorAll('[data-definition-id]')].find((item) => item.dataset.definitionId === result.field);
                    if (editor) editor.querySelector('[data-profile-error]').textContent = 'Check this value and try again.';
                }
                this.show('dirty', 'Unsaved changes');
                return;
            }
            if (!response.ok) throw new Error('Save failed');

            this.versionValue = result.version;
            for (const [id, value] of sent) {
                if (JSON.stringify(this.dirty.get(id)) === JSON.stringify(value)) this.dirty.delete(id);
            }
            this.show(this.dirty.size ? 'dirty' : 'saved', this.dirty.size ? 'Unsaved changes' : 'Saved');
        } catch (_error) {
            this.show('dirty', 'Unsaved changes');
        } finally {
            this.saving = false;
        }
    }

    show(state, message) {
        this.statusTarget.dataset.state = state;
        this.statusTarget.textContent = message;
    }
}
