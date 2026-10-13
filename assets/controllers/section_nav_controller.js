import { Controller } from '@hotwired/stimulus';
import { markCurrentLinks, rememberCurrent } from '../lib/uxor-navigation.js';

/**
 * A section navigation: one link per page of a group of pages (settings), the current one marked
 * `aria-current="page"`. Each link is a page visit (Turbo Drive), not a tab: the links are plain links in the Tab
 * order, as in any navigation landmark, so there are no arrow keys to learn.
 *
 * The server marks the current section (`route` or `active`). An item given neither is marked from the current URL's
 * path on every connect, so the navigation also follows the page inside a `data-turbo-permanent` element and over
 * the copy Turbo cached. Below the `lg` breakpoint the list is a strip that scrolls sideways: on connect, the
 * controller scrolls the current section into the strip's view.
 *
 * @target list The list of links, a strip that scrolls sideways on small screens.
 * @target item The section links; an item without `route` or `active` is the current one when its path is the URL's.
 */
export default class extends Controller {
    static targets = ['list', 'item'];

    connect() {
        // the items' current state as rendered, given back on disconnect
        this.restoreCurrent = rememberCurrent(this.itemTargets);
        this.markCurrentItem();
        this.revealCurrentItem();
    }

    disconnect() {
        this.restoreCurrent();
    }

    markCurrentItem() {
        markCurrentLinks(this.itemTargets.filter((item) => item.hasAttribute('data-section-nav-match-url')));
    }

    /** Scrolls the strip, never the page, so the current section is in view when the list overflows sideways. */
    revealCurrentItem() {
        const current = this.itemTargets.find((item) => 'page' === item.getAttribute('aria-current'));
        if (!this.hasListTarget || !current || this.listTarget.scrollWidth <= this.listTarget.clientWidth) {
            return;
        }
        const list = this.listTarget.getBoundingClientRect();
        const item = current.getBoundingClientRect();
        if (item.left >= list.left && item.right <= list.right) {
            return;
        }
        this.listTarget.scrollLeft += item.left + item.width / 2 - (list.left + list.width / 2);
    }
}
