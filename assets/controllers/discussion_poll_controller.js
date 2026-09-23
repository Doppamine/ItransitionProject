import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['posts'];
    static values = { url: String };

    connect() {
        this.lastId = this.latestId();
        this.inFlight = false;
        this.timer = window.setInterval(() => this.poll(), 3000);
    }

    disconnect() {
        window.clearInterval(this.timer);
    }

    async poll() {
        if (this.inFlight || document.hidden) return;
        this.inFlight = true;
        try {
            const response = await fetch(`${this.urlValue}?after=${this.lastId}`, {
                headers: { Accept: 'text/html' },
            });
            if (!response.ok) return;
            const template = document.createElement('template');
            template.innerHTML = await response.text();
            this.postsTarget.append(template.content);
            this.lastId = this.latestId();
        } catch (_error) {
            // The next poll retries after a temporary network failure.
        } finally {
            this.inFlight = false;
        }
    }

    latestId() {
        return Math.max(0, ...Array.from(this.postsTarget.querySelectorAll('[data-post-id]'),
            post => Number(post.dataset.postId) || 0));
    }
}
