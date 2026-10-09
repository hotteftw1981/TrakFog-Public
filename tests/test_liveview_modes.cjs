'use strict';
// Regression: tracked Tesla state must drive BOTH gauges and current/old charge readouts.
const assert=require('node:assert/strict');
const {readFileSync}=require('node:fs');
const {join}=require('node:path');
const root=join(__dirname,'..');
const js=readFileSync(join(root,'public/assets/js/liveview.js'),'utf8');
const css=readFileSync(join(root,'public/assets/css/liveview.css'),'utf8');
const html=readFileSync(join(root,'public/live.php'),'utf8');
const start=js.indexOf('  const fmt = ');
const end=js.indexOf('  const storageGet = ',start);
assert(start>=0 && end>start,'pure shared state helpers exist');
const {cockpitState,nerdChargeDisplay}=new Function(js.slice(start,end)+';return {cockpitState,nerdChargeDisplay};')();

let sleep=cockpitState({mode:'sleeping',speed_kmh:0,power_kw:2,stream_fresh:false});
assert.equal(sleep.instrument.value,'Zz','sleep is exactly one symbol, never crescent plus P');
assert.equal(sleep.instrument.unit,'','sleep is not a speed readout');
assert.equal(sleep.power,null,'cached power is not live while sleeping');
assert.equal(sleep.powerStatus,'Schlafmodus');
let park=cockpitState({mode:'parked',speed_kmh:0,power_kw:2,stream_fresh:false});
assert.equal(park.instrument.value,'P','parking instrument uses only a single P');
assert.equal(park.power,null,'stale parked power must not be shown as current');
assert.equal(park.powerStatus,'Parkmodus');
assert.equal(cockpitState({mode:'parked',power_kw:0,stream_fresh:true}).powerStatus,'Standby');
assert.equal(cockpitState({mode:'parked',power_kw:2,stream_fresh:true}).powerStatus,'Verbrauch');
let drive=cockpitState({mode:'driving',speed_kmh:74.5,power_kw:-8,stream_fresh:true});
assert.equal(drive.instrument.unit,'km/h');
assert.equal(drive.powerStatus,'Rekuperation','negative stream power must remain supported');
assert.equal(drive.power,-8);
let charge=cockpitState({mode:'charging',power_kw:0,stream_fresh:false},{current_power_kw:6.2});
assert.equal(charge.power,6.2,'active session wins over stale snapshot');
assert.equal(charge.powerStatus,'Ladevorgang');
assert.equal(charge.instrument.unit,'','no km/h when plugged in');
let stale=cockpitState({mode:'stale',power_kw:9,stream_fresh:false});
assert.equal(stale.power,null);
assert.equal(stale.instrument.value,'…');
const old=nerdChargeDisplay('sleeping',null,{
  charger_power_kw:17,charger_voltage_v:235,charger_current_a:16,
  charge_energy_added_kwh:1.9,time_to_full_charge_h:0
});
assert.equal(old.active,false);
assert.equal(old.status,'Keine aktive Ladung');
assert.equal(old.power,null,'old charger reading must not be current');
assert.deepEqual(old.electricity,[null,null],'old voltage/current must not look live');
assert.equal(old.energyLabel,'Zuletzt gemeldet: 1,9 kWh');
assert.equal(old.timeLabel,'– bis voll','old zero time-to-full must not be shown as now');
const now=nerdChargeDisplay('charging',{current_power_kw:11,energy_kwh:3},{
  charger_power_kw:0,charger_voltage_v:232,charger_current_a:15,
  charge_energy_added_kwh:3,time_to_full_charge_h:1.5
});
assert.equal(now.active,true);
assert.equal(now.power,11);
assert.equal(now.energyLabel,'3,0 kWh geladen');
assert.equal(now.timeLabel,'1,5 h bis voll');

assert(html.includes('id="lvNerdSpeedUnit"'),'Nerd speed unit can disappear outside driving');
assert(html.includes('id="lvPowerDirection"'),'live power status preserved');
assert(css.includes('.live-root .lv-speed-gauge[data-mode="sleeping"] .lv-drive-gear'),'no doubled Park gear');
assert(css.includes('.live-root .lv-tesla-hero[data-mode="sleeping"]'),'Nerd ring drives layout mode');
assert(js.includes("nerdHero.dataset.mode=state.mode"),'Nerd layout responds to telemetry mode');
assert(js.includes("setText('#lvNerdChargeAdded',chargeDisplay.energyLabel)"),'charge history clearly labeled');
console.log('LiveView sleeping, parking, stale and charging-state regression: PASS');
