const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '..');
const script = path.join(root, 'public/assets/js/trip-merge-ui.js');
let passed = 0;
function test(name, fn) { fn(); console.log('PASS: ' + name); passed++; }
function choice(car, time, start, end, checked=true) {
  return { checked, dataset:{ car, time, from:start, to:end, carLabel:'Hotte Y' },
    listeners:{}, addEventListener(name, handler){this.listeners[name]=handler;}, change(){this.listeners.change?.();} };
}
const {summarize}=require(script);
const oldTrip=choice('8','2026-10-08 08:00:00','Arbeit','Supermarkt');
const newTrip=choice('8','2026-10-08 08:21:00','Supermarkt','Zuhause');
test('two trips of one car are ready',()=>assert.equal(summarize([newTrip,oldTrip]).ready,true));
test('order is chronological, even when listing newest first',()=>{
  const info=summarize([newTrip,oldTrip]);assert.equal(info.start,'Arbeit');assert.equal(info.end,'Zuhause');
});
test('one trip cannot be consolidated',()=>assert.equal(summarize([oldTrip]).ready,false));
test('no trips cannot be saved',()=>assert.equal(summarize([]).ready,false));
test('different cars cannot be combined',()=>assert.equal(summarize([oldTrip,choice('9','2026-10-08 10:00:00','A','B')]).ready,false));
test('unchecked trips are excluded',()=>assert.equal(summarize([oldTrip,newTrip,choice('8','2026-10-08 09:00:00','C','D',false)]).count,2));
test('non-consecutive selection is blocked before posting',()=>{const middle=choice('8','2026-10-08 08:12:00','B','C',false);assert.equal(summarize([oldTrip,middle,newTrip]).ready,false);});
class Element {
  constructor(){this.listeners={};this.textContent='';this.hidden=true;this.disabled=true;this.dataset={};this.events=[];}
  addEventListener(type,fn){this.listeners[type]=fn;}
  click(){this.listeners.click?.({preventDefault(){}});}
  dispatch(type,e={preventDefault(){this.prevented=true;}}){this.listeners[type]?.(e);return e;}
  showModal(){this.open=true;this.events.push('showModal');}
  close(){this.open=false;this.events.push('close');}
}
const els={};for(const id of ['tripMergeForm','tripMergeDialog','tripMergeSelectedBar','tripMergeSelectedCount','tripMergeSelectedHint','tripMergeOpen','tripMergeReset','tripMergeSave','tripMergeCancel','tripMergeClose','mergeDialogSummary','tripMergePreviewStart','tripMergePreviewEnd','tripMergePreviewInfo'])els[id]=new Element();
const simulated=[choice('8','2026-10-08 08:21:00','Supermarkt','Zuhause',false),choice('8','2026-10-08 08:00:00','Arbeit','Supermarkt',false)];
els.tripMergeForm.querySelectorAll=()=>simulated;
global.document={getElementById(id){return els[id];},querySelectorAll(){return [];}};
delete require.cache[require.resolve(script)];require(script);
test('actionbar starts hidden',()=>assert.equal(els.tripMergeSelectedBar.hidden,true));
simulated[0].checked=true;simulated[0].change();
test('one selection shows toolbar but not saving',()=>{assert.equal(els.tripMergeSelectedBar.hidden,false);assert.equal(els.tripMergeOpen.disabled,true);});
simulated[1].checked=true;simulated[1].change();
test('two selections activate CTA',()=>{assert.equal(els.tripMergeOpen.disabled,false);assert.match(els.tripMergeSelectedCount.textContent,/2 Fahrten/);});
els.tripMergeOpen.click();
test('CTA opens modal with route endpoints and final save button',()=>{
  assert.deepEqual(els.tripMergeDialog.events,['showModal']);assert.equal(els.tripMergePreviewStart.textContent,'Arbeit');assert.equal(els.tripMergePreviewEnd.textContent,'Zuhause');assert.match(els.tripMergePreviewInfo.textContent,/1 Zwischenstopp/);
});
test('confirming posts the selected form and prevents duplicate save',()=>{
  const event=els.tripMergeForm.dispatch('submit');assert.equal(event.prevented,undefined);assert.equal(els.tripMergeSave.disabled,true);
});
els.tripMergeCancel.click();test('cancel closes modal without changing selection',()=>{assert.equal(els.tripMergeDialog.open,false);assert.equal(summarize(simulated).count,2);});
els.tripMergeReset.click();test('reset clears all choices and hides actionbar',()=>{assert.equal(summarize(simulated).count,0);assert.equal(els.tripMergeSelectedBar.hidden,true);});
const markup=fs.readFileSync(path.join(root,'public/trips.php'),'utf8');
const endpoint=fs.readFileSync(path.join(root,'public/trip-merge.php'),'utf8');
const trip=fs.readFileSync(path.join(root,'public/trip.php'),'utf8');
const styles=fs.readFileSync(path.join(root,'public/assets/css/trip-merge.css'),'utf8');
test('real submit button is associated with persistent POST form',()=>{assert.match(markup,/id="tripMergeForm" method="post" action="trip-merge.php"/);assert.match(markup,/id="tripMergeSave" type="submit" form="tripMergeForm"/);});
test('POST saves only one real trip and redirects to its details',()=>{assert.match(endpoint,/TripConsolidation::combine\(/);assert.match(endpoint,/trip\.php\?id=/);assert.match(trip,/Fahrten erfolgreich zu einer Fahrt/);});
test('no virtual tour list or extra duplicate entries',()=>{assert.doesNotMatch(markup,/Verbundene Touren/);assert.doesNotMatch(markup,/\$joinedRows/);assert.match(markup,/Fahrten zusammenf/);});
test('destructive merge requires admin permission',()=>{assert.match(endpoint,/Auth::requireAdmin\(\)/);});
test('route labels are 14px or greater and wrap instead of truncating',()=>{assert.match(styles,/trip-place-name\{font-size:clamp\(14px/);assert.match(styles,/white-space:normal/);});
console.log('Trip merge UI tests passed:',passed);
