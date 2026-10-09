'use strict';

const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const root = join(__dirname, '..');
const js = readFileSync(join(root, 'public/assets/js/liveview.js'), 'utf8');
const css = readFileSync(join(root, 'public/assets/css/liveview.css'), 'utf8');
const html = readFileSync(join(root, 'public/live.php'), 'utf8');

// Execute the real, isolated formatting helpers without needing a DOM/Tesla API.
const start = js.indexOf('  const fmt = ');
const stop = js.indexOf('  const storageGet = ', start);
assert(start >= 0 && stop > start, 'LiveView formatter is present');
const { fmtMetric } = new Function(js.slice(start, stop) + ';return {fmtMetric};')();

assert.equal(fmtMetric(47264.5, 'odometer'), '47.265', 'odometer rounds to full km');
assert.equal(fmtMetric(47264.49, 'odometer'), '47.264', 'no decimal odometer');
assert.equal(fmtMetric(11.8, 'temperature'), '11,8', 'temperature keeps one decimal');
assert.equal(fmtMetric(8.5, 'temperature'), '8,5', 'outside temperature keeps one decimal');
assert.equal(fmtMetric(2.35, 'power'), '2,4', 'power keeps one decimal');
assert.equal(fmtMetric(313.8, 'range'), '314', 'range has no decimals');
assert.equal(fmtMetric(79.49, 'battery'), '79', 'SOC has no decimals');
assert.equal(fmtMetric(-0.38, 'netEnergy'), '-0,38', 'negative regeneration not clamped');
assert.equal(fmtMetric(null, 'odometer'), '–', 'missing odometer is not zero');
assert.equal(fmtMetric(undefined, 'temperature'), '–', 'missing temperature is not zero');
assert.equal(fmtMetric(NaN, 'power'), '–', 'invalid power is not zero');

assert(js.includes("fmtMetric(tesla.odometer_km,'odometer')"), 'odometer uses shared precision');
for (const id of ['inside_temp_c','outside_temp_c','driver_temp_c','passenger_temp_c']) {
  assert(js.includes("fmtMetric(tesla." + id + ",'temperature')"), id + ' uses temperature precision');
}
for (const id of ['lvNerdOdometer','lvNerdInsideTemp','lvNerdOutsideTemp']) {
  assert(html.includes('id="' + id + '"'), id + ' exists');
}
assert(css.includes('.live-root .lv-tesla-temp-pair strong'), 'temperature has scoped type rule');
assert(css.includes('.live-root .lv-tesla-odometer strong'), 'odometer has scoped type rule');
assert(css.includes('font-variant-numeric:tabular-nums lining-nums'), 'aligned digits');
console.log('LiveView metric typography/precision regression: PASS');
