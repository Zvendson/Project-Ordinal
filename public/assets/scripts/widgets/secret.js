/** Copies an issued secret only after an explicit click, retaining manual selection as fallback. */
export function initializeSecret() {
    const value = document.querySelector("#issued-secret");
    const button = document.querySelector("[data-copy-secret]");
    const status = document.querySelector("[data-copy-status]");
    if (value === null || button === null || status === null || !navigator.clipboard) {
        return;
    }
    button.hidden = false;
    button.addEventListener("click", async () => {
        try {
            await navigator.clipboard.writeText(value.value);
            status.textContent = "Secret copied. Store it securely before leaving this page.";
        }
        catch {
            value.focus();
            value.select();
            status.textContent = "Clipboard access is unavailable. Copy the selected secret manually.";
        }
    });
}
