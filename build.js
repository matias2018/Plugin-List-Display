#!/usr/bin/env node
/**
 * build.js — bump the version, then produce a distributable plugin zip for
 * manual testing on a production site before the tag is pushed to WordPress.org.
 *
 * Every build advances the version (default: patch) and writes it to the single
 * sources of truth — the plugin header, the MODULES_INSIGHT_VERSION constant and
 * readme.txt's "Stable tag" — plus the newest "= x.y.z =" heading under both
 * "== Changelog ==" and "== Upgrade Notice ==" when that heading still matches
 * the version being replaced. So the running plugin, the zip filename and the
 * changelog always name the same, latest build.
 *
 * The zip contains a single top-level `modules-insight/` folder (what WordPress
 * expects from "Upload Plugin"). File selection mirrors the SVN deploy: every
 * file except those matched by `.distignore` — the same list the
 * 10up/action-wordpress-plugin-deploy workflow uses for trunk.
 *
 * Usage:
 *   node build.js                 # bump patch (4.0.1 -> 4.0.2), then build
 *   node build.js --minor         # bump minor (4.0.1 -> 4.1.0)
 *   node build.js --major         # bump major (4.0.1 -> 5.0.0)
 *   node build.js --set 4.2.0     # set an exact version
 *   node build.js --no-bump       # rebuild the current version as-is
 *   node build.js --keep          # also leave the unzipped dist/modules-insight/
 *
 * No dependencies — shells out to rsync and zip (both ship with macOS/Linux).
 */

'use strict';

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const SLUG = 'modules-insight';
const ROOT = __dirname;
const DIST = path.join(ROOT, 'dist');
const STAGE = path.join(DIST, SLUG);
const MAIN_FILE = path.join(ROOT, `${SLUG}.php`);
const README = path.join(ROOT, 'readme.txt');

const argv = process.argv.slice(2);

function fail(msg) {
  console.error(`\n✗ ${msg}\n`);
  process.exit(1);
}

function run(cmd, args, opts = {}) {
  return execFileSync(cmd, args, { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'], ...opts });
}

function readVersion(src) {
  return (src.match(/^\s*\*\s*Version:\s*(.+?)\s*$/m) || [])[1];
}

function bump(version, level) {
  const m = /^(\d+)\.(\d+)\.(\d+)$/.exec(version);
  if (!m) fail(`Current version "${version}" is not plain semver (X.Y.Z) — use --set to fix it.`);
  let [major, minor, patch] = m.slice(1).map(Number);
  if (level === 'major') { major += 1; minor = 0; patch = 0; }
  else if (level === 'minor') { minor += 1; patch = 0; }
  else { patch += 1; }
  return `${major}.${minor}.${patch}`;
}

// --- Work out the target version ----------------------------------------

if (!fs.existsSync(MAIN_FILE)) fail(`Cannot find ${SLUG}.php`);

let mainSrc = fs.readFileSync(MAIN_FILE, 'utf8');
const currentVersion = readVersion(mainSrc);
if (!currentVersion) fail('No "Version:" header found in the plugin file.');

const setIdx = argv.indexOf('--set');
let version;
let bumped = true;

if (argv.includes('--no-bump')) {
  version = currentVersion;
  bumped = false;
} else if (setIdx !== -1) {
  version = argv[setIdx + 1] || '';
  if (!/^\d+\.\d+\.\d+$/.test(version)) fail('--set needs a version like 4.2.0');
} else if (argv.includes('--major')) {
  version = bump(currentVersion, 'major');
} else if (argv.includes('--minor')) {
  version = bump(currentVersion, 'minor');
} else {
  version = bump(currentVersion, 'patch');
}

// --- Write it to every source of truth ---------------------------------

if (bumped || version !== currentVersion) {
  mainSrc = mainSrc
    .replace(/^(\s*\*\s*Version:\s*).+$/m, `$1${version}`)
    .replace(/(MODULES_INSIGHT_VERSION',\s*')[^']+(')/, `$1${version}$2`);

  const constVersion = (mainSrc.match(/MODULES_INSIGHT_VERSION',\s*'([^']+)'/) || [])[1];
  if (constVersion !== version) fail('Could not update MODULES_INSIGHT_VERSION — check the define() in the plugin file.');
  fs.writeFileSync(MAIN_FILE, mainSrc);

  if (fs.existsSync(README)) {
    let readmeSrc = fs.readFileSync(README, 'utf8');
    readmeSrc = readmeSrc.replace(/^(Stable tag:\s*).+$/m, `$1${version}`);
    // Advance the newest heading in each section only if it still names the old version.
    for (const section of ['== Changelog ==', '== Upgrade Notice ==']) {
      const re = new RegExp(`(${section}\\s*\\n+=\\s*)${currentVersion.replace(/\./g, '\\.')}(\\s*=)`);
      readmeSrc = readmeSrc.replace(re, `$1${version}$2`);
    }
    fs.writeFileSync(README, readmeSrc);
  }
}

// --- Sanity re-check ---------------------------------------------------

const finalMain = fs.readFileSync(MAIN_FILE, 'utf8');
if (readVersion(finalMain) !== version) fail('Version header did not update as expected.');
if ((finalMain.match(/MODULES_INSIGHT_VERSION',\s*'([^']+)'/) || [])[1] !== version) {
  fail('MODULES_INSIGHT_VERSION does not match the header.');
}
if (fs.existsSync(README)) {
  const stableTag = (fs.readFileSync(README, 'utf8').match(/^Stable tag:\s*(.+?)\s*$/m) || [])[1];
  if (stableTag && stableTag !== version) fail(`readme.txt "Stable tag" is ${stableTag}, expected ${version}.`);
}

const zipPath = path.join(DIST, `${SLUG}-${version}.zip`);

// --- Stage the files -------------------------------------------------

fs.rmSync(DIST, { recursive: true, force: true });
fs.mkdirSync(STAGE, { recursive: true });

const rsyncArgs = ['-a', '--delete', '--exclude=.git/', '--exclude=dist/', '--exclude=build.js'];
if (fs.existsSync(path.join(ROOT, '.distignore'))) {
  rsyncArgs.push('--exclude-from=.distignore');
}
rsyncArgs.push(`${ROOT}/`, `${STAGE}/`);
run('rsync', rsyncArgs);

// --- Zip it --------------------------------------------------------

run('zip', ['-r', '-q', '-X', zipPath, SLUG], { cwd: DIST });

if (!argv.includes('--keep')) {
  fs.rmSync(STAGE, { recursive: true, force: true });
}

// --- Report -------------------------------------------------------

const contents = run('unzip', ['-l', zipPath]);
const sizeKB = (fs.statSync(zipPath).size / 1024).toFixed(1);

console.log(contents.trim());
console.log(
  version !== currentVersion
    ? `\n✓ Bumped ${currentVersion} → ${version} and built ${path.relative(ROOT, zipPath)}  (${sizeKB} KB)`
    : `\n✓ Built ${path.relative(ROOT, zipPath)}  (${sizeKB} KB, v${version})`
);
console.log('  Install on the test site via Plugins → Add New → Upload Plugin.\n');
