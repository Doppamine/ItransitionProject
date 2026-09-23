import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['file', 'preview', 'clear', 'progress', 'status'];
    static values = { signUrl: String, completeUrl: String, token: String, definitionId: Number, errorMessage: String };

    dragOver(event) {
        event.preventDefault();
        this.element.classList.add('border-primary');
    }

    dragLeave() {
        this.element.classList.remove('border-primary');
    }

    drop(event) {
        event.preventDefault();
        this.dragLeave();
        const file = event.dataTransfer?.files?.[0];
        if (file) this.upload(file);
    }

    choose() {
        const file = this.fileTarget.files?.[0];
        if (file) this.upload(file);
    }

    editor() {
        const parent = this.element.closest('[data-controller~="profile-autosave"]');
        return parent ? this.application.getControllerForElementAndIdentifier(parent, 'profile-autosave') : null;
    }

    async readyEditor() {
        const editor = this.editor();
        if (!editor || editor.conflict) throw new Error('Profile changed elsewhere. Reload to continue.');
        await editor.save();
        if (editor.saving || editor.dirty.size > 0 || editor.conflict) throw new Error('Save other changes before uploading.');
        return editor;
    }

    async upload(file) {
        if (this.uploading) return;
        if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type) || file.size > 5 * 1024 * 1024 || file.size === 0) {
            this.statusTarget.textContent = this.errorMessageValue;
            return;
        }
        this.uploading = true;
        this.progressTarget.classList.remove('d-none');
        this.progressTarget.value = 0;
        this.statusTarget.textContent = '';
        try {
            const editor = await this.readyEditor();
            const sign = await fetch(this.signUrlValue, { method: 'POST', headers: { 'X-CSRF-Token': this.tokenValue }, credentials: 'same-origin' });
            if (!sign.ok) throw new Error('Signing failed');
            const upload = await sign.json();
            const form = new FormData();
            for (const [key, value] of Object.entries(upload.fields)) form.append(key, value);
            form.append('file', file);
            const result = await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', upload.url);
                xhr.upload.onprogress = (event) => {
                    if (event.lengthComputable) this.progressTarget.value = Math.round(event.loaded * 100 / event.total);
                };
                xhr.onload = () => {
                    try {
                        const body = JSON.parse(xhr.responseText);
                        xhr.status >= 200 && xhr.status < 300 ? resolve(body) : reject(new Error('Upload failed'));
                    } catch (_error) { reject(new Error('Upload failed')); }
                };
                xhr.onerror = () => reject(new Error('Upload failed'));
                xhr.send(form);
            });
            const complete = await fetch(this.completeUrlValue, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
                body: JSON.stringify({ public_id: result.public_id, version: result.version, signature: result.signature, profileVersion: editor.versionValue }),
            });
            if (!complete.ok) throw new Error('Completion failed');
            const saved = await complete.json();
            editor.versionValue = saved.version;
            this.previewTarget.src = saved.url;
            this.previewTarget.classList.remove('d-none');
            this.clearTarget.classList.remove('d-none');
            this.statusTarget.textContent = '';
        } catch (_error) {
            this.statusTarget.textContent = this.errorMessageValue;
        } finally {
            this.uploading = false;
            this.progressTarget.classList.add('d-none');
            this.fileTarget.value = '';
        }
    }

    async clear() {
        if (this.uploading) return;
        try {
            const editor = await this.readyEditor();
            const response = await fetch(editor.urlValue, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': editor.tokenValue },
                body: JSON.stringify({ version: editor.versionValue, changes: { [this.definitionIdValue]: '' } }),
            });
            if (!response.ok) throw new Error('Clear failed');
            const saved = await response.json();
            editor.versionValue = saved.version;
            this.previewTarget.removeAttribute('src');
            this.previewTarget.classList.add('d-none');
            this.clearTarget.classList.add('d-none');
            this.statusTarget.textContent = '';
        } catch (_error) {
            this.statusTarget.textContent = this.errorMessageValue;
        }
    }
}
