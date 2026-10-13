/**
 * What the kit's controllers ask about Turbo's copies of a page: the one implementation that the recipes resetting
 * their state before Turbo caches a page, or cleaning up a cached copy as it connects, import. Turbo copies the page
 * on `turbo:before-cache` (Back and Forward show that copy), moves a `data-turbo-permanent` element into the next
 * page instead of copying it, and, for a frame visit promoted to history, copies the page as the visit starts and
 * keeps it on screen. No dependency: without Turbo on the page, every answer is the one for a page Turbo never copied.
 */

/**
 * Whether the current `turbo:before-cache` comes from a frame visit promoted to history (`data-turbo-action="advance"`,
 * a data table's pages): Turbo keeps the page on screen and caches the copy it took when the frame visit started, so a
 * reset now only changes what the user sees. Turbo 8 runs that visit with `willRender: false`, a full visit or a
 * restoration with `true`; without Turbo, false.
 */
export function isPromotedFrameCache() {
    return false === window.Turbo?.session?.navigator?.currentVisit?.willRender;
}

/**
 * Whether `element` is inside a `data-turbo-permanent` element (or is one): Turbo moves it into the next page as it is.
 * Through the prototype: a form's field named `closest` shadows the form's own method.
 */
export function isPermanent(element) {
    return null !== Element.prototype.closest.call(element, '[data-turbo-permanent]');
}

/**
 * Whether the current `turbo:before-cache` leaves `element` as the user sees it, so a controller resetting its state
 * for the cached copy skips the reset: the page stays on screen (`isPromotedFrameCache()`), or Turbo moves the element
 * into the next page (`isPermanent(element)`).
 */
export function isKeptOnCache(element) {
    return isPromotedFrameCache() || isPermanent(element);
}

const connectedBefore = new WeakSet();

/**
 * Whether `controller`'s element is part of a copy of the page Turbo cached: on the first connect of this controller,
 * the element already carries `mark`, which a controller sets while the state the copy must not show is on screen.
 * Turbo's copy holds clones, which keep attributes and get new controllers; the same element connecting again (moved
 * in the DOM, a morph, a permanent element kept by a visit) keeps its controller, so a later call is never a copy.
 * Call it once in each `connect()`, before setting `mark`.
 */
export function isCachedCopy(controller, mark) {
    const first = !connectedBefore.has(controller);
    connectedBefore.add(controller);
    return first && controller.element.hasAttribute(mark);
}
