'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.join(__dirname,'..');
const css=fs.readFileSync(path.join(root,'public/assets/css/liveview-garage.css'),'utf8');
const js=fs.readFileSync(path.join(root,'public/assets/js/liveview.js'),'utf8');
const php=fs.readFileSync(path.join(root,'public/live.php'),'utf8');
const art=fs.readFileSync(path.join(root,'src/TeslaVehicleArt.php'),'utf8');
const cutouts=['model-y-classic.svg','model-y-white.svg','model-3-classic.svg',
  'model-3-white.svg','model-s-white.svg','model-x-white.svg'];
for(const name of cutouts){
  const file=fs.readFileSync(path.join(root,'public/assets/vehicles',name),'utf8');
  assert(file.length>1000,'original generic SVG vehicle illustration missing: '+name);
  assert(file.includes('<svg') && file.includes('viewBox="0 0 1448 1086"'),'illustration must preserve scene coordinate space: '+name);
  assert(file.includes('TrakFog Public: original fictional EV illustration'),'must not contain unlicensed third-party vehicle render: '+name);
  assert(art.includes('assets/vehicles/'+name),'asset used by TeslaVehicleArt: '+name);
}
const session=css.split('/* Idle telemetry lives IN the grid.')[1];
assert(session,'non-overlay idle state exists');
const rule=session.match(/\.live-root \.lv-session-idle\s*\{([^}]+)\}/);
assert(rule,'idle state selector exists');
assert(rule[1].includes('position:static'),'idle helper must not overlap KPIs');
assert(!/position\s*:\s*absolute/.test(rule[1]),'idle state never floats over the metrics');
assert(session.includes('grid-template-rows:auto auto minmax(0,1fr)'),'idle needs dedicated row');
assert(session.includes('.lv-session-card .lv-session-metrics'),'real session KPI grid remains visible');
assert(session.includes('@media(max-width:920px), (max-height:650px)'),'Tesla Standard safe layout');
assert(js.includes("sceneImage.src=asset+'?v='+encodeURIComponent(root.dataset.version || '0')"),
 'new images must bypass prior image cache');
assert(php.includes('data-version="<?= e($version) ?>"'),'server version supplied to LiveView');
console.log('Six original vector placeholders + idle-session no-overlap + cache-busting: PASS');
