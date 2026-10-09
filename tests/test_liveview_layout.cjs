'use strict';
// Regression guards for the 4-screen LiveView cockpit layout.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.join(__dirname,'..');
const css=fs.readFileSync(path.join(root,'public/assets/css/liveview.css'),'utf8');
const js=fs.readFileSync(path.join(root,'public/assets/js/liveview.js'),'utf8');
const php=fs.readFileSync(path.join(root,'public/live.php'),'utf8');
const rules={
  vehicle:'.live-root .lv-drive-card .lv-drive-glance-row',
  speed:'.live-root .lv-drive-card .lv-speed-gauge',
  emptyJourney:'.live-root .lv-journey-page-grid.has-no-journey',
  journeyMetrics:'.live-root .lv-now-grid b',
  chargingMetrics:'.live-root .lv-charge-metrics b',
  climate:'.live-root .lv-tesla-climate',
  routeMap:'.live-root .lv-page-map .lv-map-toolbar'
};
for(const [screen,selector] of Object.entries(rules)) {
  assert(css.includes(selector),screen+' missing responsive cockpit selector');
}
assert(js.includes("closest('.lv-journey-page-grid')?.classList.toggle('has-no-journey',!journey)"),
 'idle journey must not affect active journey geometry');
for(const id of ['lvNowTripDistance','lvNowPower','lvChargeEnergy',
                 'lvNerdInsideTemp','lvNerdOutsideTemp','lvSpeedGauge']) {
  assert(php.includes('id="'+id+'"'),'missing LiveView data target '+id);
}
assert(css.includes('@media(min-width:1200px) and (min-height:640px)'),
 'two-column vehicle instrument layout must be landscape scoped');
assert(css.includes('@media(max-width:900px), (max-height:650px)'),
 'compact viewport fallback required');
assert(css.includes('.live-root .lv-tesla-temp-pair strong'),
 'typography precision from previous release retained');
console.log('LiveView 4-page layout regression: PASS');
