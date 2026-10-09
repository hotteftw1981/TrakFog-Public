'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const base=path.join(__dirname,'..');
const js=fs.readFileSync(path.join(base,'public/assets/js/liveview.js'),'utf8');
const css=fs.readFileSync(path.join(base,'public/assets/css/liveview-garage.css'),'utf8');
const html=fs.readFileSync(path.join(base,'public/live.php'),'utf8');
const api=fs.readFileSync(path.join(base,'public/live-data.php'),'utf8');
const first=js.indexOf('  const normalizeTeslaModel=');
const last=js.indexOf('  const carMotionKey=',first);
assert(first>=0&&last>first,'isolated local four-model resolver exists');
const {normalizeTeslaModel,carAssets,carModeLabels}=
  new Function(js.slice(first,last)+';return {normalizeTeslaModel,carAssets,carModeLabels};')();
for(const [input,expected] of [
  ['Model 3','3'],['Tesla Model 3 Performance','3'],['model3','3'],['M3','3'],
  ['Model Y','y'],['Tesla Model Y Long Range','y'],['ModelY','y'],['MY','y'],
  ['Model S','s'],['Tesla Model S Plaid','s'],['model_s','s'],['MS','s'],
  ['Model X','x'],['Tesla Model X','x'],['model-x','x'],['MX','x']
]){
  assert.equal(normalizeTeslaModel(input),expected,'model identification: '+input);
}
for(const input of ['Hotte Y','Hotte','Cybertruck','Model 2','',null,undefined]){
  assert.equal(normalizeTeslaModel(input),null,'do not guess image from nickname: '+input);
}
for(const [model,filename] of Object.entries({
  '3':'model-3-white.svg',y:'model-y-white.svg',s:'model-s-white.svg',x:'model-x-white.svg'
})){
  assert.equal(carAssets[model],'assets/vehicles/'+filename);
  assert(fs.statSync(path.join(base,'public/assets/vehicles',filename)).size>1000,
    filename+' packaged as a non-empty asset');
}
for(const mode of ['driving','charging','parked','sleeping','stale'])
  assert(typeof carModeLabels[mode]==='string','missing rendering status for '+mode);
for(const id of ['lvCarScene','lvCarSceneImage','lvCarSceneFallback','lvCarSceneName',
                 'lvCarSceneModel','lvCarSceneState','lvCarMotionToggle'])
  assert(html.includes('id="'+id+'"'),'missing stage control '+id);
assert(html.includes('href="assets/css/liveview-garage.css?v=<?= e($version) ?>"'),
  'garage CSS must be version-cache-busted');
assert(html.includes('id="lvCarSceneImage"') && html.includes('decoding="async" hidden'),
  'unrecognized Tesla must not briefly show Model Y');
assert(api.includes("'model' => $liveCarType"),
  'selected Tesla model supplied from stored car_type');
assert(api.includes("$detailRaw['vehicle_config']") && api.includes("$configRaw['car_type']"),
  'live model matches the cached car configuration used by the dashboard');
assert(js.includes('normalizeTeslaModel(vehicle?.model) || normalizeTeslaModel(data?.tesla_nerd?.model_name)'),
  'canonical car_type preferred over the optional model display name');
assert(js.includes('updateCarScene(data);'),'vehicle scene updated on every existing LiveView poll');
assert(js.includes('if(!asset){'),'unknown model fallback is explicit');
assert(js.includes('scene.dataset.mode=mode;'),'state drives styling');
assert(js.includes("storageSet(carMotionKey,carMotion)"),'visual effects are optional per browser');
assert(js.includes("sceneImage.addEventListener('error'"),'broken asset fallback');
assert(css.includes('[data-mode="charging"]')&&css.includes('[data-mode="driving"]')&&css.includes('[data-mode="sleeping"]'),
  'mode-specific palette');
assert(css.includes('@media(max-width:1099px)')&&css.includes('@media(max-width:700px)'),
  'Tesla Standard and compact screens have specific controls');
assert(css.includes('@media(prefers-reduced-motion:reduce)'),
  'reduced motion is respected');
assert(!js.includes('fetch(\'assets/vehicles'),'no new remote telemetry requests to resolve images');
console.log('LiveView Tesla models, safe fallback, states and responsiveness: PASS');
