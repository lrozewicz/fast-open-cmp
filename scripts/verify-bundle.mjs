#!/usr/bin/env node
/**
 * Source ↔ bundle consistency check (todo §6).
 *
 * The committed plugin bundle (plugin/assets/js/cmp-sdk.js) is a build artifact of
 * sdk/src. It is easy for the two to drift (someone edits source but forgets to
 * rebuild, or edits the bundle by hand). This script rebuilds the SDK and asserts
 * the freshly built bundle byte-matches the committed one.
 *
 * Exit 0 = in sync. Exit 1 = drift (rebuild + commit the bundle).
 * Run via `npm run verify` from the repo root.
 */
import { spawnSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const built = resolve(root, 'sdk/dist/src/index.js');
const committed = resolve(root, 'plugin/assets/js/cmp-sdk.js');

const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';

console.log('▶ Building SDK (sdk/ → dist)…');
const build = spawnSync(npm, ['run', 'build'], {
  cwd: resolve(root, 'sdk'),
  stdio: 'inherit',
  shell: process.platform === 'win32',
});
if (build.status !== 0) {
  console.error('✖ SDK build failed.');
  process.exit(1);
}

for (const f of [built, committed]) {
  if (!existsSync(f)) {
    console.error(`✖ Missing file: ${f}`);
    process.exit(1);
  }
}

const a = readFileSync(built);
const b = readFileSync(committed);

if (a.equals(b)) {
  console.log('✔ Bundle is in sync with source (sdk/dist/src/index.js === plugin/assets/js/cmp-sdk.js).');
  process.exit(0);
}

console.error(
  [
    '✖ Bundle drift detected!',
    `  built:     ${built} (${a.length} bytes)`,
    `  committed: ${committed} (${b.length} bytes)`,
    '',
    '  The committed plugin bundle does not match a fresh build of sdk/src.',
    '  Fix: run `npm run build` at the repo root and commit plugin/assets/js/cmp-sdk.js.',
  ].join('\n')
);
process.exit(1);
