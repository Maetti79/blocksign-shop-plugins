import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { allows } from "../packages/typescript/dist/gate.js";

for (const example of JSON.parse(readFileSync(new URL("./gate.json", import.meta.url)))) {
  assert.equal(allows(example.stage, example.requirements), example.allowed, example.name);
  console.log("PASS", example.name);
}
