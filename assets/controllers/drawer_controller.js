import { Controller } from '@hotwired/stimulus';
import { isKeptOnCache } from '../lib/uxor-turbo.js';

/**
 * Opens a `Drawer`, a native `<dialog>` docked to a side of the viewport: modal by default (the page is
 * inert, focus is trapped, Escape or a click on the backdrop closes it) or beside the page.
 * Like the `Modal`, a drawer open when its element is moved in the DOM (Turbo, Live re-renders) is
 * reopened on reconnect, and a closed one stays closed. It closes before Turbo caches the page, unless it is inside a
 * `data-turbo-permanent` element, so the copy shown on Back and Forward has it closed and its triggers collapsed; a
 * copy cached open anyway (the page left before `turbo:before-cache`, or a frame visit promoted to history, which
 * keeps the page on screen with the drawer open) is closed when it connects, unless its `open` value says otherwise.
 *
 * @target trigger             The elements opening the drawer, kept in sync through `aria-expanded`.
 * @target dialog              The `<dialog>` element.
 * @value  open                Whether the drawer is open when the controller connects.
 * @value  modal               Whether the drawer opens as a modal dialog.
 * @action open                Opens the drawer.
 * @action close               Closes the drawer.
 * @action closeOnClickOutside Closes the drawer when its backdrop is clicked.
 * @action closed              Syncs the triggers once the dialog has closed.
 */
export default class extends Controller {
    static targets = ['trigger', 'dialog'];
    static values = { open: Boolean, modal: { type: Boolean, default: true } };

    #wasOpen = null;

    connect() {
        // a cached copy of an open dialog keeps `open` but not its modality or focus: a new controller starts closed
        if (null === this.#wasOpen && this.dialogTarget.open) {
            this.dialogTarget.close();
            this.closed();
        }
        if (this.#wasOpen ?? this.openValue) {
            this.open();
        }
        document.addEventListener('turbo:before-cache', this.#closeBeforeCache);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.#closeBeforeCache);
        // a <dialog> taken out of the DOM loses its modality: close it now, reopen it on reconnect; a Live re-render that
        // replaces the container may have moved the dialog to a new controller already: nothing to close
        this.#wasOpen = this.hasDialogTarget && this.dialogTarget.open;
        if (this.#wasOpen) {
            this.#closeNow();
        }
    }

    open() {
        if (!this.dialogTarget.open) {
            this.modalValue ? this.dialogTarget.showModal() : this.dialogTarget.show();
        }
        this.triggerTargets.forEach((trigger) => trigger.setAttribute('aria-expanded', 'true'));
    }

    close() {
        this.dialogTarget.close();
    }

    closeOnClickOutside({ target }) {
        if (target === this.dialogTarget) {
            this.close();
        }
    }

    closed() {
        // the `close` event comes a task later: the dialog may be open again by then (reopened on reconnect)
        if (!this.dialogTarget.open) {
            this.triggerTargets.forEach((trigger) => trigger.setAttribute('aria-expanded', 'false'));
        }
    }

    // a data-turbo-permanent drawer is not in the copy Turbo shows on Back: Turbo moves the live one in; a frame visit
    // promoted to history keeps the page on screen and took its copy when it started: the drawer stays open
    #closeBeforeCache = () => {
        if (this.dialogTarget.open && !isKeptOnCache(this.element)) {
            this.#closeNow();
        }
    };

    // closes the dialog and collapses the triggers at once: its `close` event comes after Turbo has copied the page
    #closeNow() {
        this.dialogTarget.close();
        this.closed();
    }
}
