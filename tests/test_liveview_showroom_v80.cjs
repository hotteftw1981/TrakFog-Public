'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.join(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const live=read('public/live.php');
const app=read('public/app.php');
const liveCss=read('public/assets/css/liveview-garage.css');
const dashCss=read('public/assets/css/living-garage.css');
const liveJs=read('public/assets/js/liveview.js');
const appJs=read('public/assets/js/app.js');
const showroom=liveCss.split('/* V0.1.1.80 – approved showroom reference:')[1];
const dashboard=dashCss.split('/* V0.1.1.80 | Approved showroom')[1];
assert(showroom && dashboard,'both live and dashboard designs present');
for(const id of ['lvCarScene','lvCarSceneImage','lvCarSceneName','lvCarSceneModel',
                  'lvHeroValue','lvBattery','lvRange','lvPowerValue','lvPowerDirection',
                  'lvTripDistance','lvTripDuration','lvTripConsumption','lvTripMax'])
  assert(live.includes('id="'+id+'"'),'existing real telemetry binding lost: '+id);
for(const klass of ['lv-car-scene-floor','lv-car-scene-contact'])
  assert(live.includes('class="'+klass+'"'),'showroom scene layer missing: '+klass);
for(const id of ['lvTripDistanceLabel','lvTripDurationLabel','lvTripConsumptionLabel','lvTripMaxLabel'])
  assert(live.includes('id="'+id+'"'),'showroom label missing: '+id);
for(const symbol of ['moon','bolt','road','clock','leaf','gauge','car']){
  assert(live.includes('id="lv-icon-'+symbol+'"'), 'LiveView icon symbol missing: '+symbol);
  assert(live.includes('href="#lv-icon-'+symbol+'"'),'LiveView icon not used: '+symbol);
}
for(const symbol of ['battery','road','moon','clock','thermometer','calendar','bolt','lock','gauge'])
  assert(app.includes('id="ds-icon-'+symbol+'"'),'Dashboard icon missing: '+symbol);
assert(app.includes('class="dashboard-wheel-contact"'),'dashboard wheel contact belongs to vehicle markup');
assert(showroom.includes('.lv-car-scene-contact') && showroom.includes('bottom:11%'),
       'live contact shadow anchored where vehicle wheels are visible');
assert(showroom.includes('.lv-car-scene-road{display:none}'),'old separated shadow oval must be removed');
assert(showroom.includes('.lv-car-scene-floor'),'subtle floor grid is part of the stage');
assert(showroom.includes('left:8%;width:84%;top:15%;height:90%'),
       'car scale and position match the approved stage without floating');
assert(showroom.includes('.lv-session-stat-copy'),'session has consistent icon/value/unit layout');
assert(showroom.includes('.lv-session-card.is-idle .lv-session-stat-copy .lv-session-unit'),
       'unit hint only on empty sessions – avoid duplicate units in live values');
assert(showroom.includes('@media(max-width:1099px)') &&
       showroom.includes('@media(max-width:700px), (max-height:620px)'),
       'Tesla Standard + smaller browser breakpoints preserved');
assert(dashboard.includes('.dashboard-primary-car .dashboard-wheel-contact'),
       'dashboard shadow tracks vehicle across states and widths');
assert(dashboard.includes('.dashboard-hero-stage::after{display:none!important}'),
       'former detached black stage shadow removed');
assert(dashboard.includes('.dashboard-hero-metrics .dashboard-metric-icon svg'),
       'dashboard unified line-icon system applied');
assert(liveJs.includes("powerRing.classList.toggle('has-live-power',hasLivePower)"),
       'parked and sleeping show icon, not fake live kW');
assert(liveJs.includes("['Geladen','Dauer','Ladeleistung','Max. Leistung']"),
       'charging session metric labels stay semantically correct');
assert(liveJs.includes("['Strecke','Dauer','Verbrauch','Max.']"),
       'driving session labels stay semantically correct');
assert(appJs.includes("selectHeroIcon('clock')") &&
       appJs.includes("selectHeroIcon('bolt')"),'changing selected car retains matching SVG icon');
assert(liveCss.includes('prefers-reduced-motion:reduce') &&
       dashCss.includes('prefers-reduced-motion:reduce'),'animation accessibility preserved');
console.log('Showroom V0.1.1.80: consistent icons, grounded car and dynamic stats: PASS');
