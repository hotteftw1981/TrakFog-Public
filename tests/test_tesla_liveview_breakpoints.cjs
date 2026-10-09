'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.join(__dirname,'..');
const css=fs.readFileSync(path.join(root,'public/assets/css/liveview.css'),'utf8');
const php=fs.readFileSync(path.join(root,'public/live.php'),'utf8');
const js=fs.readFileSync(path.join(root,'public/assets/js/liveview.js'),'utf8');

// Screenshots measured in the actual Tesla browser: Standard 773x601,
// Wide 1256x707. Desktop 1920px remains the unmodified XL layout.
const standard='@media (min-width:701px) and (max-width:920px) and (orientation:landscape)';
const halfWidth='@media (min-width:701px) and (max-width:1099px) and (orientation:landscape)';
assert(css.includes(standard), 'Tesla Standard breakpoint exists');
assert(css.includes(halfWidth), 'Tesla journey balance breakpoint exists');
// Always inspect the ORIGINAL layout block: later typographic media queries may share the same breakpoint.
const layoutAnchor='/* V0.1.1.72 | Tesla browser sizes measured on the actual centre display.';
assert(css.includes(layoutAnchor), 'original Tesla layout definitions retained');
const layoutCss=css.slice(css.indexOf(layoutAnchor));
const standardRules=layoutCss.split(standard)[1].split('/* The short Tesla browser')[0];
assert(standardRules.includes('grid-template-areas:"nerdhero nerdbattery"'), 'NerdView declares complete layout');
for(const selector of [
  '.lv-tesla-hero{grid-area:nerdhero',
  '.lv-tesla-battery{grid-area:nerdbattery',
  '.lv-tesla-vehicle{grid-area:nerdvehicle',
  '.lv-tesla-climate{grid-area:nerdclimate',
  '.lv-tesla-position{grid-area:nerdposition'
]) assert(standardRules.includes(selector), 'No implicit grid columns: '+selector);
assert(standardRules.includes('grid-template-columns:repeat(2,minmax(0,1fr))'),
  'Vehicle lower metrics must keep their two tiles');
assert(standardRules.includes('white-space:normal;overflow-wrap:anywhere'),
  'Vehicle status must not clip as 314... / Ver...');
const journeyRules=css.split(halfWidth).at(-1);
assert(journeyRules.includes('.lv-journey-page-grid.has-no-journey'), 'Empty journey balanced at Tesla Standard');
assert(journeyRules.includes('.lv-swipe-label,.live-root .lv-page-name'),
  'Tesla page indication legible');
assert(js.includes("classList.toggle('has-no-journey',!journey)"),
  'Empty journey state comes from live data');
for(const target of ['lvNerdInsideTemp','lvNerdOdometer','lvPowerDirection','lvJourneyPageTitle']){
 assert(php.includes('id="'+target+'"'), 'Existing telemetry preserved: '+target);
}
console.log('Tesla 773x601 / 1256x707 LiveView breakpoint contracts: PASS');
