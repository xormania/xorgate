import { Controller } from '@hotwired/stimulus';
import { isPermanent } from '../lib/uxor-turbo.js';

// through the prototypes: a field named `method`, `elements`, `reset`, `closest` or `contains` (a URL parameter kept as
// a hidden field) shadows the form's own
const methodOf = Object.getOwnPropertyDescriptor(HTMLFormElement.prototype, 'method').get;
const elementsOf = Object.getOwnPropertyDescriptor(HTMLFormElement.prototype, 'elements').get;

/**
 * What a form shows after Back and Forward: a GET form reflects the URL, so it shows the values the server rendered;
 * a POST form (any method but GET) holds the user's work, so it keeps what was typed and picked.
 *
 * On `<body>`, which Turbo replaces on every visit, Back and Forward included (they show the copy Turbo cached, with
 * the fields as the user left them): once the page's controllers have connected, each GET form in the body is reset
 * (`form.reset()` restores every field's `value`, `selected` and `checked` attributes). A form is left alone while the
 * focus is inside it, so text typed before the page's scripts ran is kept, and inside a `data-turbo-permanent` element,
 * which Turbo moves from the page on screen. The widgets follow the form: a `calendar` (and its `date-picker`) resets
 * itself on the form's `reset` event, and a field enhanced by Tom Select (`autocomplete`) is synced from its `<select>`.
 * On a page the server just rendered, a reset changes nothing.
 */
export default class extends Controller {
    #pending = false;

    connect() {
        this.#pending = true;
        // the controllers of the body connect in this same batch, after this one: the widgets are listening by then
        queueMicrotask(() => {
            if (this.#pending) {
                this.#pending = false;
                this.#resetGetForms();
            }
        });
    }

    disconnect() {
        this.#pending = false;
    }

    #resetGetForms() {
        for (const form of this.element.querySelectorAll('form')) {
            if (
                'get' !== methodOf.call(form) ||
                isPermanent(form) ||
                Node.prototype.contains.call(form, document.activeElement)
            ) {
                continue;
            }
            HTMLFormElement.prototype.reset.call(form);
            for (const field of elementsOf.call(form)) {
                // Tom Select shows its own copy of the choice, which a reset of the <select> does not reach
                field.tomselect?.sync();
            }
        }
    }
}
