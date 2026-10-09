'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const css = fs.readFileSync(path.join(__dirname, '..', 'public/assets/css/liveview.css'), 'utf8');
const php = fs.readFileSync(path.join(__dirname, '..', 'public/live.php'), 'utf8');
const js = fs.readFileSync(path.join(__dirname, '..', 'public/assets/js/liveview.js'), 'utf8');
const prefix = '/* V0.1.1.73 | Unified telemetry typography for all LiveView screens.';
assert(css.includes(prefix), 'all-screen telemetry typography block exists');
const typography = css.slice(css.indexOf(prefix));

for(const selector of [
  '.lv-hero-value strong',              // main driving / parking
  '.lv-dashboard-ring strong',          // battery and charging rings
  '.lv-drive-glance-meta b',            // range, power & regeneration
  '.lv-session-metrics b',              // trip metrics
  '.lv-journey-big-metrics strong',      // journey KPIs
  '.lv-now-grid b',                     // now
  '.lv-charge-metrics b',               // charging session
  '.lv-tesla-speed strong',             // NerdView speed
  '.lv-tesla-hero-metrics b',           // power and gear
  '.lv-tesla-metric-grid b',            // NerdView charge/range
  '.lv-tesla-odometer strong',          // reference typography
  '.lv-tesla-temp-pair strong',         // reference typography
  '.lv-tesla-climate-set b',            // climate setpoint
  '.lv-map-hud-ring strong',            // map battery
  '.lv-map-hud-data>div:not(.lv-map-hud-location) strong'
]) {
  assert(typography.includes(selector), 'numeric telemetry selector missing: '+selector);
}

assert(typography.includes('font-variant-numeric:tabular-nums lining-nums'), 'stable numeric widths');
for(const token of ['--lv-num-display-weight:560','--lv-num-primary-weight:600','--lv-num-secondary-weight:620']) {
  assert(typography.includes(token), 'missing typographic tier: '+token);
}
assert(typography.includes('.lv-tesla-speed small'), 'speed unit hierarchy retained');
assert(typography.includes('.lv-tesla-metric-grid small'), 'KPI units aligned');
assert(typography.includes('.lv-tesla-temp-pair small'), 'temperature units aligned');
assert(typography.includes('.lv-map-hud-data>div:not(.lv-map-hud-location) strong'),
  'position names must NOT be treated as numeric telemetry');

const standard = '@media (min-width:701px) and (max-width:920px) and (orientation:landscape)';
assert(typography.includes(standard), 'Tesla 773x601 standard receives its own font sizes');
assert(typography.includes('@media(max-width:700px), (max-height:620px)'),
  'small / short Tesla browser font fallback');
assert(typography.includes('.live-root .lv-drive-card .lv-drive-glance-meta b'),
  'previous Tesla metric no-ellipsis adjustment retained');

for(const id of ['lvHeroValue','lvBattery','lvMapSpeed','lvMapRange','lvJourneyCost',
                 'lvNowPower','lvChargeEnergy','lvNerdOdometer','lvNerdInsideTemp']) {
  assert(php.includes('id="'+id+'"'), 'LiveView source field preserved: '+id);
}
assert(js.includes("fmtMetric(tesla.odometer_km,'odometer')"), 'odometer is still rounded');
assert(js.includes("fmtMetric(tesla.inside_temp_c,'temperature')"), 'temperatures still have decimals');

console.log('All four LiveView numeric typography and Tesla breakpoints: PASS');
