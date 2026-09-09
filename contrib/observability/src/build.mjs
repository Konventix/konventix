#!/usr/bin/env node
// Writes the committed dashboard JSON from the sources next to this file.
// `--check` exits 1 when a committed file differs, which CI runs.
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, '..', 'dashboards');
const check = process.argv.includes('--check');
const sources = ['sale', 'platform', 'logs'];

mkdirSync(out, { recursive: true });
let dirty = 0;

for (const name of sources) {
    const { default: model } = await import(`./${name}.mjs`);
    const file = join(out, `${model.uid}.json`);
    const json = JSON.stringify(model, null, 2) + '\n';
    let current = null;
    try {
        current = readFileSync(file, 'utf8');
    } catch {
        // first build
    }
    if (check) {
        if (current !== json) {
            console.error(`${file} is out of date; run npm run observability:build`);
            dirty++;
        }
    } else if (current !== json) {
        writeFileSync(file, json);
        console.log(`wrote ${file}`);
    }
}

process.exit(dirty > 0 ? 1 : 0);
