const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '..');
const markup = fs.readFileSync(path.join(root,'public/trips.php'),'utf8');
const css = fs.readFileSync(path.join(root,'public/assets/css/trip-merge.css'),'utf8');
const script = path.join(root,'public/assets/js/trip-merge-ui.js');
let tested = 0;
function check(condition,msg) { assert.ok(condition,msg); console.log('PASS: '+msg); tested++; }
check(markup.includes('class="trip-card trip-card-compact'), 'compact card is primary layout');
check(markup.includes('data-trip-toggle aria-expanded="false"'), 'detail toggle initially collapsed with accessible aria state');
check(markup.includes('class="trip-expanded"') && markup.includes('" hidden>'), 'details are hidden until user interaction');
check(markup.includes('data-trip-merge-choice') && markup.includes('data-trip-delete-id'), 'merge and delete remain available');
check(markup.includes('tripMergeSave') && markup.includes('tripMergeForm'), 'merge confirmation remains linked to actual POST');
check(css.includes('.trip-expanded[hidden]{display:none!important}'), 'hidden panel remains hidden under CSS');
check(css.includes('@media(max-width:540px)'), 'mobile compact design responsive');
let panel = { hidden:true };
let toggle = { attrs:{'aria-controls':'trip-expanded-13','aria-expanded':'false'}, title:'', listeners:{},
  getAttribute(key){return this.attrs[key]},setAttribute(key,val){this.attrs[key]=val},addEventListener(k,fn){this.listeners[k]=fn},click(){this.listeners.click?.()} };
global.document = { getElementById(id){return id==='trip-expanded-13'?panel:null}, querySelectorAll(selector){return selector==='[data-trip-toggle]'?[toggle]:[]} };
require(script);
toggle.click();check(panel.hidden===false && toggle.attrs['aria-expanded']==='true','click opens selected trip details');
toggle.click();check(panel.hidden===true && toggle.attrs['aria-expanded']==='false','second click closes trip details');
console.log('Compact trip checks passed:',tested);
