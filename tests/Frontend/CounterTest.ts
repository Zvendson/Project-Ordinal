import { formatPreview } from "../../assets/ts/widgets/counter.js";

/** Rejects malformed/foreign responses rather than showing a misleading build number. */
function assertEqual(expected: string, actual: string): void {
    if (expected !== actual) { throw new Error(`Expected ${expected}, received ${actual}`); }
}

assertEqual("Next build number: 0. This does not reserve it.", formatPreview({ projectId: 3, nextBuildNumber: 0, isExhausted: false }, 3));
assertEqual("Counter exhausted. A confirmed reset is required.", formatPreview({ projectId: 3, nextBuildNumber: 4294967295, isExhausted: true }, 3));
for (const value of [null, {}, { projectId: 4, nextBuildNumber: 1, isExhausted: false }, { projectId: 3, nextBuildNumber: -1, isExhausted: false }, { projectId: 3, nextBuildNumber: 1.5, isExhausted: false }, { projectId: 3, nextBuildNumber: "1", isExhausted: false }, { projectId: 3, nextBuildNumber: 4294967296, isExhausted: false }, { projectId: 3, nextBuildNumber: 1, isExhausted: "false" }]) {
    let isRejected: boolean = false;
    try { formatPreview(value, 3); } catch { isRejected = true; }
    if (!isRejected) { throw new Error("Invalid preview accepted"); }
}
console.log("Counter response checks passed (10 cases).");
