import { Controller } from '@hotwired/stimulus';

/**
 * The `Navbar` menu button: asks the `Sidebar` with the given id to open or close over the page,
 * through a `sidebar:toggle` window event (the sidebar keeps the button's `aria-expanded` in sync).
 *
 * @action toggleSidebar Toggles the sidebar whose id is the `sidebar` action parameter.
 */
export default class extends Controller {
    toggleSidebar({ params }) {
        window.dispatchEvent(new CustomEvent('sidebar:toggle', { detail: { id: params.sidebar } }));
    }
}
