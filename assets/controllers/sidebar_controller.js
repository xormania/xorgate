import { Controller } from '@hotwired/stimulus';
import { markCurrentLinks, whenMediaMatches } from '../lib/uxor-navigation.js';

/**
 * Collapses the `Sidebar` to its icons (saved in `localStorage`), opens it over the page on small
 * screens (`sidebar:toggle` window event, sent by the `Navbar` toggle) and marks the current item.
 *
 * Meant to be `data-turbo-permanent`: Turbo then keeps the same element across visits, Back and Forward, so the
 * controller re-marks the current item on every reconnect and restores the scroll position, which the
 * browser resets when the element is re-inserted. With `localStorage` unavailable the collapse lasts until the next
 * page load. Opened over the page, it closes on a visit, Back and Forward (the menu buttons of the page shown are
 * collapsed on reconnect, a cached copy's included) and when the screen grows to where it sits beside the page.
 *
 * @target toggle      The collapse button, kept in sync through `aria-expanded` and its label.
 * @target scroll      The scrolling navigation, whose position survives Turbo visits.
 * @target item        The item links; the one matching the current URL's path gets `aria-current="page"`, never a `#…` link.
 * @value  storageKey  The `localStorage` key of the collapsed state.
 * @value  media       The media query where the sidebar sits beside the page (Tailwind's `md`); opened over the page, it closes there.
 * @action toggle      Collapses or expands the sidebar and saves the state.
 * @action rememberScroll Remembers the navigation's scroll position, restored on reconnect.
 * @action toggleMobile Opens or closes the sidebar over the page on small screens.
 * @action closeMobile Closes the sidebar opened over the page, giving focus back to the menu button.
 */
export default class extends Controller {
    static targets = ['toggle', 'scroll', 'item'];
    static values = {
        storageKey: { type: String, default: 'sidebar-collapsed' },
        media: { type: String, default: '(min-width: 48rem)' },
    };

    connect() {
        // storage unavailable: a permanent sidebar reconnecting after a visit keeps its state, a new page starts expanded
        // a collapse that could not be saved (storage blocked or full) lasts until the next page load: the element's own
        // state wins over a stale stored value
        let collapsed = this.element.hasAttribute('data-collapsed');
        if (!this.unsaved) {
            try {
                collapsed = 'true' === localStorage.getItem(this.storageKeyValue);
            } catch {
                // storage unavailable: keep the element's state
            }
        }
        this.setCollapsed(collapsed);
        this.markCurrentItem();
        if (undefined !== this.savedScroll && this.hasScrollTarget) {
            this.scrollTarget.scrollTop = this.savedScroll;
        }
        // the page shown may be a copy Turbo took with the sidebar open over it: its menu buttons follow the sidebar
        this.syncMobileTriggers();
        this.stopClosingWhenWide = whenMediaMatches(this.mediaValue, () => this.setMobileOpen(false));
    }

    disconnect() {
        this.stopClosingWhenWide();
        this.setMobileOpen(false);
    }

    rememberScroll() {
        // saved on scroll: by the time disconnect() runs, the element is re-inserted and its scroll reset
        this.savedScroll = this.scrollTarget.scrollTop;
    }

    toggle() {
        const collapsed = !this.element.hasAttribute('data-collapsed');
        this.setCollapsed(collapsed);
        try {
            localStorage.setItem(this.storageKeyValue, String(collapsed));
            this.unsaved = false;
        } catch {
            // storage unavailable or full: the state lasts until the next page load
            this.unsaved = true;
        }
    }

    setCollapsed(collapsed) {
        this.element.toggleAttribute('data-collapsed', collapsed);
        this.toggleTargets.forEach((button) => {
            button.setAttribute('aria-expanded', String(!collapsed));
            button.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        });
    }

    toggleMobile(event) {
        if (event?.detail?.id && event.detail.id !== this.element.id) {
            return;
        }
        this.element.hasAttribute('data-mobile-open') ? this.closeMobile() : this.setMobileOpen(true);
    }

    closeMobile() {
        if (!this.element.hasAttribute('data-mobile-open')) {
            return;
        }
        const hadFocus = this.element.contains(document.activeElement);
        this.setMobileOpen(false);
        if (hadFocus) {
            this.mobileTriggers()[0]?.focus();
        }
    }

    setMobileOpen(open) {
        if (open === this.element.hasAttribute('data-mobile-open')) {
            return;
        }
        this.element.toggleAttribute('data-mobile-open', open);
        this.syncMobileTriggers();
        if (open && this.hasScrollTarget) {
            this.scrollTarget.querySelector('a')?.focus();
        }
    }

    syncMobileTriggers() {
        const open = String(this.element.hasAttribute('data-mobile-open'));
        this.mobileTriggers().forEach((trigger) => trigger.setAttribute('aria-expanded', open));
    }

    mobileTriggers() {
        return [...document.querySelectorAll(`[data-sidebar-trigger="${CSS.escape(this.element.id)}"]`)];
    }

    markCurrentItem() {
        if (!this.itemTargets.some((item) => item.hasAttribute('data-sidebar-active-fixed'))) {
            markCurrentLinks(this.itemTargets);
        }
    }
}
