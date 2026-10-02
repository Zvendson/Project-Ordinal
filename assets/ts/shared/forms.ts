/** Avoids accidental repeated POSTs without persisting inputs or replacing server validation. */
export function initializeForms(): void {
    const forms: NodeListOf<HTMLFormElement> = document.querySelectorAll('form[method="post"]');
    for (const form of forms) {
        const status: HTMLParagraphElement = document.createElement("p");
        status.setAttribute("role", "status");
        form.append(status);
        form.addEventListener("submit", (event: SubmitEvent): void => {
            if (form.getAttribute("aria-busy") === "true") { event.preventDefault(); return; }
            form.setAttribute("aria-busy", "true");
            status.textContent = "Submitting…";
        });
    }
    window.addEventListener("pageshow", (): void => {
        for (const form of forms) {
            form.removeAttribute("aria-busy");
            const status: HTMLElement | null = form.querySelector('[role="status"]');
            if (status !== null) { status.textContent = ""; }
        }
    });
}
