import { Controller } from '@hotwired/stimulus';
import { followsInThisTab, markCurrentLinks, rememberCurrent } from '../lib/uxor-navigation.js';

/**
 * A navigation menu, as the WAI-ARIA disclosure navigation pattern describes it: each submenu is a list of links
 * shown by a button (`aria-expanded`, `aria-controls`), at any depth. The button's `aria-expanded` is the only state:
 * the CSS shows a submenu while its button is expanded.
 *
 * Opening a submenu closes the others, its own parents excepted. Escape closes the innermost open submenu holding the
 * focus and gives the focus back to its button; a click outside, the focus leaving the menu, a link followed in the
 * same tab and Turbo caching the page close them all, so Back and Forward never show one open. The link of the
 * current page gets `aria-current="page"` on every connect, Turbo visits included, and the CSS highlights the buttons
 * of the submenus around it.
 *
 * Horizontal, a submenu opens over the page under its button (a nested one beside its parent), positioned by the CSS
 * relative to its item, so it follows the bar on scroll and resize; when it would leave the viewport, it opens
 * towards the other side (`data-flip`, and `data-flip-y` for a nested one near the bottom). Vertical, submenus open
 * in place.
 *
 * @target button      The submenus' buttons.
 * @target link        The links; the one matching the current URL's path gets `aria-current="page"`, never a `#…` link.
 * @value  orientation How the menu lays out: `horizontal` (submenus over the page) or `vertical` (submenus in place).
 * @action toggle      Opens or closes the clicked button's submenu.
 * @action keydown     Escape closes the innermost open submenu holding the focus.
 * @action focusout    Closes every submenu when the focus leaves the menu.
 * @action closeOnLink Closes every submenu when a link inside is followed in the same tab (not one opened in another
 *                     tab or window by a modifier key or a `target`, nor a `download`).
 * @action closeOutside Closes every submenu on a click outside the menu.
 * @action reposition  Opens the shown submenus towards the side where they fit, after a resize or a scroll.
 * @action closeAll    Closes every submenu (before Turbo caches the page).
 */
export default class extends Controller {
    static targets = ['button', 'link'];
    static values = { orientation: { type: String, default: 'horizontal' } };

    connect() {
        // a copy of the page cached with a submenu open, or a data-turbo-permanent menu, comes back closed
        this.closeAll();
        // the links' current state as rendered, given back on disconnect
        this.restoreCurrent = rememberCurrent(this.linkTargets);
        this.markCurrentLink();
    }

    disconnect() {
        this.closeAll();
        this.restoreCurrent();
    }

    toggle({ currentTarget }) {
        this.setOpen(currentTarget, !this.isOpen(currentTarget));
    }

    keydown(event) {
        if ('Escape' !== event.key || event.defaultPrevented) {
            return;
        }
        // the focused button's own submenu, or the innermost open one holding the focus
        const button = this.openButtons()
            .filter((open) => open === event.target || this.panelOf(open)?.contains(event.target))
            .pop();
        if (!button) {
            return; // nothing open here: Escape is left to the page (a drawer around the menu closes)
        }
        event.preventDefault();
        event.stopPropagation();
        this.setOpen(button, false); // gives the focus back to the button
    }

    focusout({ relatedTarget }) {
        // a click on a submenu's padding moves the focus nowhere (relatedTarget null): only a click outside closes
        if (relatedTarget && !this.element.contains(relatedTarget)) {
            this.closeAll();
        }
    }

    closeOnLink(event) {
        const link = event.target.closest('a[href]');
        if (!link || !this.element.contains(link) || event.defaultPrevented) {
            return;
        }
        // a link this tab does not follow (a modifier click, another tab or window, a download) leaves the menu open
        if (followsInThisTab(event, link)) {
            this.closeAll();
        }
    }

    closeOutside({ target }) {
        if (!this.element.contains(target)) {
            this.closeAll();
        }
    }

    reposition() {
        this.openButtons().forEach((button) => this.place(button));
    }

    closeAll() {
        this.buttonTargets.forEach((button) => this.setExpanded(button, false));
    }

    setOpen(button, open) {
        if (!open) {
            // its open submenus close with it; the focus inside goes back to its button
            this.openButtons()
                .filter((other) => other === button || this.panelOf(button)?.contains(other))
                .forEach((other) => this.setExpanded(other, false));
            if (this.panelOf(button)?.contains(document.activeElement)) {
                button.focus();
            }
            return;
        }
        // one open at a time: every other submenu closes, the ones around this button excepted
        this.openButtons()
            .filter((other) => !this.panelOf(other)?.contains(button))
            .forEach((other) => this.setExpanded(other, false));
        this.setExpanded(button, true);
        this.place(button);
    }

    setExpanded(button, open) {
        button.setAttribute('aria-expanded', String(open));
        if (!open) {
            const panel = this.panelOf(button);
            delete panel?.dataset.flip;
            delete panel?.dataset.flipY;
        }
    }

    /** Opens a horizontal submenu towards the side where it fits: the CSS places it, this only picks the side. */
    place(button) {
        const panel = this.panelOf(button);
        if (!panel || 'vertical' === this.orientationValue) {
            return;
        }
        delete panel.dataset.flip;
        delete panel.dataset.flipY;
        const width = document.documentElement.clientWidth;
        const height = document.documentElement.clientHeight;
        const rtl = 'rtl' === getComputedStyle(panel).direction;
        const outside = (rect) => Math.max(0, rtl ? -rect.left : rect.right - width);
        const overflow = outside(panel.getBoundingClientRect());
        if (overflow > 0) {
            panel.dataset.flip = '';
            // the other side is no better (a viewport narrower than the submenu): keep the first one
            const other = panel.getBoundingClientRect();
            if (Math.max(0, rtl ? other.right - width : -other.left) >= overflow) {
                delete panel.dataset.flip;
            }
        }
        const nested = button.closest('ul') !== this.element;
        if (nested && panel.getBoundingClientRect().bottom > height) {
            panel.dataset.flipY = '';
            if (panel.getBoundingClientRect().top < 0) {
                delete panel.dataset.flipY;
            }
        }
    }

    isOpen(button) {
        return 'true' === button.getAttribute('aria-expanded');
    }

    /** The open buttons, outer ones first. */
    openButtons() {
        return this.buttonTargets.filter((button) => this.isOpen(button));
    }

    panelOf(button) {
        const id = button.getAttribute('aria-controls');
        return id ? this.element.querySelector(`#${CSS.escape(id)}`) : null;
    }

    markCurrentLink() {
        if (this.linkTargets.some((link) => link.hasAttribute('data-nav-menu-active-fixed'))) {
            return;
        }
        markCurrentLinks(this.linkTargets);
    }
}
