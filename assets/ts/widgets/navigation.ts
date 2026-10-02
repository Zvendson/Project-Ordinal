/** Marks the current navigation section without hiding links when JavaScript is unavailable. */
export function initializeNavigation(): void {
    for (const link of document.querySelectorAll<HTMLAnchorElement>(".navigation a")) {
        const path: string = new URL(link.href).pathname;
        if (location.pathname === path || (path !== "/" && location.pathname.startsWith(path + "/"))) {
            link.setAttribute("aria-current", "page");
        }
    }
}
