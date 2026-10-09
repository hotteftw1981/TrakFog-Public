'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname,'../public/assets/js/charts.js'),'utf8');
function element(tag) {
  return {
    tag, attributes:{}, children:[], dataset:{}, className:'', textContent:'',
    setAttribute(name,value){this.attributes[name]=String(value)},
    append(...items){this.children.push(...items)},
    appendChild(child){this.children.push(child);return child},
    replaceChildren(...items){this.children=items}
  };
}
function render(points,limit=600000) {
  const root=element('div');
  root.dataset.chart=JSON.stringify({unit:'km/h',decimals:0,zeroFloor:true,
    observedRange:true,rawSampleCount:16,maxGapMs:limit,series:[{label:'Tempo',key:'green',points}]});
  const document={
    createElement:element,createElementNS(_ns,tag){return element(tag)},
    querySelectorAll(selector){return selector==='.tf-chart[data-chart]'?[root]:[]}
  };
  const context={document,window:{},Intl,Date,Number,Math};
  vm.runInNewContext(source,context,{filename:'charts.js'});
  return {root,svg:root.children[1].children[0],footer:root.children[2],legend:root.children[0]};
}
const now=Date.UTC(2026,9,8,7,0,0);
const points=[{x:now,y:0},{x:now+60000,y:50},{x:now+8*3600000,y:0},{x:now+8*3600000+60000,y:20}];
const broken=render(points);
assert.equal(broken.root.dataset.gaps,'1','A large telemetry gap must be explicit');
assert.equal(broken.svg.children.filter(x=>x.tag==='path').length,2,'No line across eight-hour gap');
assert.equal(broken.svg.children.filter(x=>x.tag==='circle').length,4,'Sample points stay visible');
assert.match(broken.footer.textContent,/16 Messwerten/);
assert.match(broken.footer.textContent,/1 Datenlücke/);
assert.match(broken.legend.children[0].children[3].textContent,/gemessen:.*max 50 km\/h/);
const continuous=render([{x:now,y:20},{x:now+30000,y:30},{x:now+60000,y:45}]);
assert.equal(continuous.root.dataset.gaps,'0');
assert.equal(continuous.svg.children.filter(x=>x.tag==='path').length,1);
console.log('PASS: measured maximum, sampled points, telemetry gaps, continuous data');
