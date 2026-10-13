import { Controller } from '@hotwired/stimulus';
import { isCachedCopy, isPermanent } from '../lib/uxor-turbo.js';

/**
 * Dismisses a `Toast` after its timeout, pausing while it is hovered or focused, or when its close
 * button is pressed; it fades out, then leaves the DOM. A toast added by a Turbo Stream connects like
 * any other, so the timer starts as soon as it appears.
 *
 * In a `ToastRegion` (`data-turbo-permanent`), a toast stays across Turbo visits until it times out or is closed. A
 * toast outside a permanent region belongs to its page: once shown, it is marked `data-toast-shown`, and a copy of the
 * page Turbo cached (Back, Forward, a preview) removes it as it connects, so it is never shown again. It is not
 * `data-turbo-temporary`: Turbo removes those on `turbo:before-cache`, which a frame visit promoted to history
 * dispatches with the page still on screen, after copying the page.
 *
 * @value  timeout Milliseconds before the toast dismisses itself, `0` to keep it until closed.
 * @action pause   Stops the countdown while the toast is hovered or focused, keeping the time left.
 * @action resume  Restarts the countdown with the time left once it is neither hovered nor focused.
 * @action close   Fades the toast out and removes it; under `prefers-reduced-motion`, removes it at once.
 */
export default class extends Controller {
    static values = { timeout: { type: Number, default: 5000 } };

    connect() {
        this.hovered = false;
        this.focused = false;
        // shown before, but not by this controller: a copy of the page Turbo cached, where a toast of the page is gone.
        // A reconnect of the same element (the permanent region kept by a visit, a toast moved) keeps the controller
        if (isCachedCopy(this, 'data-toast-shown') && !isPermanent(this.element)) {
            this.element.remove();
            return;
        }
        this.element.setAttribute('data-toast-shown', '');
        if (this.closing) {
            // moved in the DOM while fading out: finish the removal
            this.element.remove();
            return;
        }
        // a reconnect (Turbo keeping the permanent region) continues with the time left
        this.remaining ??= this.timeoutValue;
        this.start();
    }

    disconnect() {
        this.stop();
        clearTimeout(this.removeTimer);
    }

    pause({ type }) {
        if ('mouseenter' === type) {
            this.hovered = true;
        } else {
            this.focused = true;
        }
        this.stop();
    }

    resume({ type, relatedTarget }) {
        if ('mouseleave' === type) {
            this.hovered = false;
        } else if (!this.element.contains(relatedTarget)) {
            this.focused = false;
        }
        this.start();
    }

    close() {
        if (this.closing) {
            return;
        }
        this.closing = true;
        this.stop();
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this.element.remove();
            return;
        }
        this.element.classList.add('opacity-0');
        this.removeTimer = setTimeout(() => this.element.remove(), 300);
    }

    start() {
        if (this.timer || this.timeoutValue <= 0 || this.closing || this.hovered || this.focused) {
            return;
        }
        this.startedAt = Date.now();
        this.timer = setTimeout(() => this.close(), Math.max(0, this.remaining));
    }

    stop() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
            this.remaining -= Date.now() - this.startedAt;
        }
    }
}
