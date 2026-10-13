import { Controller } from '@hotwired/stimulus';
import { followsInThisTab, whenMediaMatches } from '../lib/uxor-navigation.js';

/**
 * The `MobileNav`: the app's navigation in a `Drawer` on small screens, opened by a menu button. The drawer controller
 * on the same element makes it a modal `<dialog>` (focus trapped, Escape and the backdrop close it, `aria-expanded` on
 * the button); this one moves the focus to the current page's link when it opens, and closes it when a link inside is
 * followed, before Turbo caches the page (so Back and Forward never show it open), and when the screen grows to the
 * width where the button is hidden.
 *
 * @target trigger       The menu button.
 * @target nav           The navigation inside the drawer.
 * @value  media         The media query where the menu button is hidden (Tailwind's `md`); an open drawer closes there.
 * @action focusTrigger  Focuses the menu button before the drawer opens, so closing it gives the focus back to it.
 * @action focusCurrent  Moves the focus to the current page's link in the drawer, or to the navigation's first one.
 * @action closeOnLink   Closes the drawer when a link inside it is followed in the same tab.
 * @action close         Closes the drawer.
 */
export default class extends Controller {
    static targets = ['trigger', 'nav'];
    static values = { media: { type: String, default: '(min-width: 48rem)' } };

    connect() {
        this.stopClosingWhenWide = whenMediaMatches(this.mediaValue, () => this.close());
    }

    disconnect() {
        this.stopClosingWhenWide();
    }

    focusTrigger({ currentTarget }) {
        // Safari does not focus a clicked button: the dialog would give the focus back to the page instead
        currentTarget.focus();
    }

    focusCurrent() {
        if (!this.dialog?.open || !this.hasNavTarget) {
            return;
        }
        const shown = (element) => element.getClientRects().length > 0;
        const current = [...this.navTarget.querySelectorAll('[aria-current="page"]')].find(shown);
        // a SideNav's Tab stop, or the first link
        (current ?? [...this.navTarget.querySelectorAll('[tabindex="0"], a[href]')].find(shown))?.focus();
    }

    closeOnLink(event) {
        const link = event.target.closest('a[href]');
        if (!link || !this.dialog?.contains(link) || event.defaultPrevented) {
            return;
        }
        // a modifier click or another button opens a new tab; a target other than this tab, or a download, does not
        // navigate this one either: the drawer stays open
        if (followsInThisTab(event, link)) {
            this.close();
        }
    }

    close() {
        if (this.dialog?.open) {
            this.dialog.close();
            // the dialog's `close` event, which updates the button, comes later: Turbo would cache the button expanded
            this.triggerTarget.setAttribute('aria-expanded', 'false');
        }
    }

    get dialog() {
        return this.hasTriggerTarget ? document.getElementById(this.triggerTarget.getAttribute('aria-controls')) : null;
    }
}
