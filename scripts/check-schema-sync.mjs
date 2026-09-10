import { readFile, readdir } from "node:fs/promises";

const expectedFiles = [
  "state.schema.json",
  "surface.schema.json",
  "tool-call.schema.json",
  "tool-result.schema.json",
  "ui-command.schema.json",
  "event.schema.json",
  "goal.schema.json",
  "confirmation.schema.json",
  "connection.schema.json",
];
const files = (await readdir(new URL("../resources/schema/", import.meta.url))).sort();

if (JSON.stringify(files) !== JSON.stringify([...expectedFiles].sort())) {
  throw new Error(`Unexpected schema set: ${files.join(", ")}`);
}

const types = await readFile(new URL("../resources/js/types.ts", import.meta.url), "utf8");

for (const file of expectedFiles) {
  const schema = JSON.parse(await readFile(new URL(`../resources/schema/${file}`, import.meta.url), "utf8"));
  const wireName = new URL(schema.$id).pathname.split("/").at(-1);

  if (!wireName || !types.includes(`"${wireName}"`)) {
    throw new Error(`${file} is not represented in WIRE_SCHEMAS.`);
  }
}

console.log(`Verified ${expectedFiles.length} PHP/TypeScript wire schemas.`);
