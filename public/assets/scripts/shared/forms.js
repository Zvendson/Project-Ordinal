/** Avoids accidental repeated POSTs without persisting inputs or replacing server validation. */
export function initializeForms() {
    const forms = document.querySelectorAll('form[method="post"]');
    for (const form of forms) {
        const status = document.createElement("p");
        status.setAttribute("role", "status");
        form.append(status);
        form.addEventListener("submit", (event) => {
            if (form.getAttribute("aria-busy") === "true") {
                event.preventDefault();
                return;
            }
            form.setAttribute("aria-busy", "true");
            status.textContent = "Submitting…";
        });
    }
    window.addEventListener("pageshow", () => {
        for (const form of forms) {
            form.removeAttribute("aria-busy");
            const status = form.querySelector('[role="status"]');
            if (status !== null) {
                status.textContent = "";
            }
        }
    });
}
