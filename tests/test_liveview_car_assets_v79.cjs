'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.join(__dirname,'..');
const css=fs.readFileSync(path.join(root,'public/assets/css/liveview-garage.css'),'utf8');
const js=fs.readFileSync(path.join(root,'public/assets/js/liveview.js'),'utf8');
const php=fs.readFileSync(path.join(root,'public/live.php'),'utf8');
const art=fs.readFileSync(path.join(root,'src/TeslaVehicleArt.php'),'utf8');
const cutouts=['model-y-classic.avif','model-y-white.avif','model-3-classic.avif',
  'model-3-white.avif','model-s-white.avif','model-x-white.avif'];
for(const name of cutouts){
  const file=fs.readFileSync(path.join(root,'public/assets/vehicles',name));
  assert(file.length>10000,'generated AVIF studio render missing: '+name);
  assert.equal(file.subarray(4,12).toString('ascii'),'ftypavif','valid AVIF format: '+name);
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
console.log('Six original AVIF studio renders + idle-session no-overlap + cache-busting: PASS');
