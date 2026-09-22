import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'selected', 'suggestions'];
    static values = { url: String };

    connect() {
        this.requestNumber = 0;
        this.renderSelected();
    }

    async suggest() {
        this.renderSelected();
        const prefix = this.inputTarget.value.split(',').at(-1).trim();
        const requestNumber = ++this.requestNumber;
        this.suggestionsTarget.replaceChildren();
        if (!prefix) return;

        try {
            const response = await fetch(`${this.urlValue}?q=${encodeURIComponent(prefix)}`, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok || requestNumber !== this.requestNumber) return;
            const names = await response.json();
            if (requestNumber !== this.requestNumber || !Array.isArray(names)) return;
            for (const name of names) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'list-group-item list-group-item-action';
                button.textContent = name;
                button.addEventListener('click', () => this.choose(name));
                this.suggestionsTarget.append(button);
            }
        } catch (_error) {
            if (requestNumber === this.requestNumber) this.suggestionsTarget.replaceChildren();
        }
    }

    keyDown(event) {
        if (event.key !== 'Enter') return;
        const first = this.suggestionsTarget.querySelector('button');
        if (!first) return;
        event.preventDefault();
        this.choose(first.textContent);
    }

    choose(name) {
        const parts = this.inputTarget.value.split(',');
        parts.pop();
        parts.push(name);
        this.inputTarget.value = `${parts.map(part => part.trim()).filter(Boolean).join(', ')}, `;
        this.suggestionsTarget.replaceChildren();
        this.renderSelected();
        this.inputTarget.focus();
    }

    renderSelected() {
        this.selectedTarget.replaceChildren();
        const parts = this.inputTarget.value.split(',');
        if (!this.inputTarget.value.trimEnd().endsWith(',')) parts.pop();
        for (const part of parts.map(part => part.trim()).filter(Boolean)) {
            const badge = document.createElement('span');
            badge.className = 'badge text-bg-secondary me-1';
            badge.textContent = part;
            this.selectedTarget.append(badge);
        }
    }
}
