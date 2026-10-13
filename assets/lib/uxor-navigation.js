/**
 * What the kit's navigation controllers share: which of their links is the current page's, whether a click on one
 * navigates this tab, and closing a navigation opened over the page once the screen is wide enough to show it beside
 * the page. The one implementation that `nav-menu`, `sidebar`, `side-nav`, `section-nav` and `mobile-nav` import. No
 * dependency.
 */

/**
 * Marks each of `links` (elements with an `href`) `aria-current="page"` when its path is the current URL's path, and
 * removes the attribute from the others. A link to a fragment of the page (`#…`, what the kit's link parts render for a
 * rejected URL) is never the current page. Call it on every connect: the URL changes under a `data-turbo-permanent`
 * element, and under the copy of a page Turbo cached.
 */
export function markCurrentLinks(links) {
    const path = window.location.pathname;
    links.forEach((link) => {
        const isFragment = (link.getAttribute('href') ?? '').trim().startsWith('#');
        const isCurrent = !isFragment && new URL(link.href, window.location.href).pathname === path;
        isCurrent ? link.setAttribute('aria-current', 'page') : link.removeAttribute('aria-current');
    });
}

/**
 * Remembers the `aria-current` of each of `links` as it is now (as the server rendered it, before `markCurrentLinks()`)
 * and returns the function that gives each link that value back: call it on disconnect, so a controller removed from
 * an element that stays leaves the links as they were rendered.
 */
export function rememberCurrent(links) {
    const rendered = new Map(links.map((link) => [link, link.getAttribute('aria-current')]));
    return () => rendered.forEach((value, link) => (null === value ? link.removeAttribute('aria-current') : link.setAttribute('aria-current', value)));
}

/**
 * Whether a click on `link` navigates this tab: not a click with a modifier key or another button (a new tab or
 * window), not a link whose `target` names another browsing context, not a `download`. The caller checks the rest
 * (the link is its own, the click was not cancelled).
 */
export function followsInThisTab(event, link) {
    const newTab = event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0;
    const target = (link.getAttribute('target') ?? '').trim().toLowerCase();
    const otherContext = '' !== target && !['_self', '_top', '_parent'].includes(target);
    return !newTab && !otherContext && !link.hasAttribute('download');
}

/**
 * Calls `callback` each time the media `query` starts to match (a `change` of `window.matchMedia(query)` to matching),
 * not when it stops, and not for how it matches now; returns the function that stops listening, for `disconnect()`.
 * A navigation opened over the page on small screens closes with it when the screen grows to where it sits beside it.
 */
export function whenMediaMatches(query, callback) {
    const media = window.matchMedia(query);
    const listener = () => media.matches && callback();
    media.addEventListener('change', listener);
    return () => media.removeEventListener('change', listener);
}
