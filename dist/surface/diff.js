export function semanticDiff(before, after) {
    if (!before)
        return [{ path: "/", operation: "add", value: after }];
    return walk(before, after, "");
}
function walk(before, after, path) {
    if (Object.is(before, after))
        return [];
    if (!isObject(before) || !isObject(after)) {
        return [{ path: path || "/", operation: "replace", value: after }];
    }
    const patches = [];
    const keys = new Set([...Object.keys(before), ...Object.keys(after)]);
    for (const key of keys) {
        const childPath = `${path}/${key.replace(/~/g, "~0").replace(/\//g, "~1")}`;
        if (!(key in after)) {
            patches.push({ path: childPath, operation: "remove" });
        }
        else if (!(key in before)) {
            patches.push({ path: childPath, operation: "add", value: after[key] });
        }
        else {
            patches.push(...walk(before[key], after[key], childPath));
        }
    }
    return patches;
}
function isObject(value) {
    return typeof value === "object" && value !== null && !Array.isArray(value);
}
