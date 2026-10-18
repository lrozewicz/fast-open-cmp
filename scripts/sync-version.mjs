#!/usr/bin/env node
/**
 * Single source of truth for the plugin version: root package.json "version".
 * Propagates it into plugin/fast-open-cmp.php (the `Version:` header and the
 * FOCMP_VERSION constant), which otherwise drift apart by hand.
 *
 *   node scripts/sync-version.mjs           # write the plugin version
 *   node scripts/sync-version.mjs --check   # verify they match (CI), non-zero on drift
 *
 * npm's "version" lifecycle hook runs the write form automatically, so
 * `npm version <patch|minor|major>` bumps both in one commit.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const version = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8')).version;

const phpPath = join(root, 'plugin', 'fast-open-cmp.php');
let php = readFileSync(phpPath, 'utf8');

const headerRe = /^(\s*\*\s*Version:\s*)(.+)$/m;
const defineRe = /(define\('FOCMP_VERSION',\s*')([^']+)('\);)/;

const headerMatch = php.match(headerRe);
const defineMatch = php.match(defineRe);
if (!headerMatch || !defineMatch) {
  console.error('Could not locate the Version header or FOCMP_VERSION in fast-open-cmp.php');
  process.exit(1);
}

const header = headerMatch[2].trim();
const define = defineMatch[2];

if (process.argv.includes('--check')) {
  if (header !== version || define !== version) {
    console.error(
      `Version drift: package.json=${version}, header=${header}, FOCMP_VERSION=${define}. ` +
        'Run: npm run sync-version',
    );
    process.exit(1);
  }
  console.log(`Version in sync: ${version}`);
  process.exit(0);
}

php = php.replace(headerRe, `$1${version}`).replace(defineRe, `$1${version}$3`);
writeFileSync(phpPath, php);
console.log(`Synced plugin version -> ${version}`);
