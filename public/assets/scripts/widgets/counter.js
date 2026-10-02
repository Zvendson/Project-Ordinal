/** Matches the server's unsigned 32-bit counter range. */
const MAX_BUILD_NUMBER = 4294967295;
/** Bounds the optional preview request independently of build allocation retries. */
const PREVIEW_TIMEOUT_MS = 10000;
/** Validates preview responses so foreign or malformed data cannot be presented as a number. */
export function formatPreview(value, projectId) {
    if (typeof value !== "object" || value === null) {
        throw new Error("Invalid preview");
    }
    const preview = value;
    const number = preview["nextBuildNumber"];
    if (preview["projectId"] !== projectId || typeof number !== "number" || !Number.isInteger(number)
        || number < 0 || number > MAX_BUILD_NUMBER || typeof preview["isExhausted"] !== "boolean") {
        throw new Error("Invalid preview");
    }
    return preview["isExhausted"] ? "Counter exhausted. A confirmed reset is required."
        : `Next build number: ${number}. This does not reserve it.`;
}
/** Enhances only the read-only GET preview; edits and resets remain ordinary protected forms. */
export function initializeCounter() {
    const form = document.querySelector("[data-counter-preview]");
    const status = document.querySelector("[data-preview-status]");
    if (form === null || status === null) {
        return;
    }
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (form.getAttribute("aria-busy") === "true") {
            return;
        }
        form.setAttribute("aria-busy", "true");
        status.textContent = "Checking the next number…";
        try {
            const response = await fetch(form.action, { method: "GET", credentials: "same-origin", cache: "no-store", signal: AbortSignal.timeout(PREVIEW_TIMEOUT_MS), headers: { Accept: "application/json" } });
            if (!response.ok) {
                status.textContent = response.status === 401 ? "Your session expired. Sign in again."
                    : response.status === 403 ? "Administrator access is required."
                        : response.status === 404 ? "This project is unavailable."
                            : "The preview is unavailable. Try again later; no number was allocated.";
                return;
            }
            status.textContent = formatPreview(await response.json(), Number(form.dataset["projectId"]));
        }
        catch {
            status.textContent = "The preview could not be verified. Try again later; no number was allocated.";
        }
        finally {
            form.removeAttribute("aria-busy");
        }
    });
}
