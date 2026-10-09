'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.join(__dirname,'..');
const html=fs.readFileSync(path.join(root,'public/live.php'),'utf8');
const live=fs.readFileSync(path.join(root,'public/assets/css/liveview-garage.css'),'utf8');
const dash=fs.readFileSync(path.join(root,'public/assets/css/living-garage.css'),'utf8');
const js=fs.readFileSync(path.join(root,'public/assets/js/liveview.js'),'utf8');
const endLive=live.split('/* V0.1.1.82: image-locked shadows;')[1];
const endDash=dash.split('/* V0.1.1.82: actual front/rear tire pixel contacts')[1];
assert(endLive && endDash,'the image-bound shadow corrections are present in both views');
const section=html.slice(html.indexOf('class="lv-car-sprite"'),html.indexOf('class="lv-car-scene-fallback"'));
assert(section.indexOf('class="lv-car-scene-contact"')<section.indexOf('id="lvCarSceneImage"'),'shadows belong behind same image');
assert(endLive.includes('aspect-ratio:1448/1086'),'shared image coordinate system');
assert(endLive.includes('object-fit:fill'),'avoid independent object-fit padding and detached shadow');
for(const text of ['left:35.5%;top:77.8%','left:82%;top:68.2%']){
 assert(endLive.includes(text)&&endDash.includes(text),'both wheels use identical intrinsic image anchors: '+text);
}
assert(endLive.includes('data-image="ready"'),'no orphaned shadows if the image fails to load');
assert(js.includes("scene.dataset.image='ready'"),'existing image readiness contract preserved');
assert(endDash.includes('.dashboard-wheel-contact')&&endDash.includes('inset:0'),'dashboard shadow shares car coordinates');
assert(endDash.includes('dashboard-hero-stage::after{display:none!important}'),'old stage-wide shadow removed');
assert(!endDash.includes('animation:'),'ground contact is never animated');
for(const name of ['model-y-classic.svg','model-y-white.svg','model-3-classic.svg','model-3-white.svg','model-s-white.svg','model-x-white.svg']){
 const file=path.join(root,'public/assets/vehicles',name);
 assert(fs.statSync(file).size>1000,'bundled original generic SVG art: '+name);
}
// V84: Shadow tuning must never alter the accepted V83 vehicle position.
const shadowV84=dash.split('/* V0.1.1.84 | Refine only image-bound ground shadows. V83 car position is locked. */')[1]?.split('/* V0.1.1.83 |')[0];
assert(shadowV84,'V84 shadow refinement exists before the final position lock');
for(const part of [
 'ellipse 34% 5% at 61% 75.5%',
 'background:rgba(0,0,0,.82);filter:blur(3px)',
 'left:35.5%;top:77.8%;width:13.5%;height:4.4%',
 'left:82%;top:68.2%;width:11.5%;height:3.8%',
 'drop-shadow(0 7px 5px rgba(0,0,0,.28))',
])assert(shadowV84.includes(part),'V84 image-bound shadow geometry: '+part);
assert(!shadowV84.includes('bottom: calc('),'V84 does not move the primary car');
// V85: add ONLY a diffuse center-underbody gradient in the image-bound shadow layer.
const centerShadow='radial-gradient(ellipse 24% 3.5% at 61% 73%,rgba(0,0,0,.19) 0%,rgba(0,0,0,.11) 43%,transparent 100%)';
assert(dash.includes(centerShadow),'V85 soft middle-underbody shadow present');
const centerDecl=dash.slice(dash.indexOf('/* V0.1.1.85: second, soft shadow directly under the chassis between both tires. */'));
assert(centerDecl.includes('radial-gradient(ellipse 34% 5% at 61% 75.5%,rgba(0,0,0,.30),transparent 100%)'),'existing V84 shadow kept intact');
assert(centerDecl.includes('left:35.5%;top:77.8%;width:13.5%;height:4.4%'),'front tire contact unchanged');
assert(centerDecl.includes('left:82%;top:68.2%;width:11.5%;height:3.8%'),'rear tire contact unchanged');
assert(dash.trimEnd().endsWith('}\n}'),'V83 position rule remains the last dashboard CSS declaration');
// V86: separate image-bound diffuse mid-chassis shadow, behind the vehicle artwork.
const dashboardHtml=fs.readFileSync(path.join(root,'public/app.php'),'utf8');
const carMarkup=dashboardHtml.slice(dashboardHtml.indexOf('data-primary-car>'),dashboardHtml.indexOf('class="dashboard-charge-port"'));
assert(carMarkup.indexOf('class="dashboard-wheel-contact"')>=0,'original wheel shadows still present');
assert(carMarkup.indexOf('class="dashboard-chassis-contact"')>carMarkup.indexOf('class="dashboard-wheel-contact"'),'chassis shadow follows tire shadows');
assert(carMarkup.indexOf('class="dashboard-chassis-contact"')<carMarkup.indexOf("dashboard-scene-car',"),'chassis shadow behind image');
const bodyShadow=dash.split('/* V0.1.1.86 | Dedicated sloped chassis-to-floor contact shadow, image-locked.')[1]?.split('/* V0.1.1.83 |')[0];
assert(bodyShadow,'V86 shadow layer exists');
for(const value of ['left:46%','top:68%','width:42%','height:12%','rotate(-9deg)','rgba(0,0,0,.57)','filter:blur(7px)']){
 assert(bodyShadow.includes(value),'V86 chassis contact visible and soft: '+value);
}
assert(!bodyShadow.includes('bottom: calc('),'V86 must not move the car');
// V83: only the whole car wrapper shifts, leaving image coordinates and contacts unchanged.
const v83=dash.slice(dash.lastIndexOf('/* V0.1.1.83 | Dashboard height calibration'));
assert(v83.startsWith('/* V0.1.1.83'),'V83 dashboard override is present and comes last');
assert(v83.includes('@media (min-width: 1181px)'), 'desktop-only override prevents mobile regressions');
assert(v83.includes('bottom: calc(9% - 60px)'), 'car lowered by exactly 60px relative to V82 desktop');
assert(!v83.includes('.dashboard-wheel-contact'),'V82 contacts remain image-aligned');
console.log('Grounded shadows, locked V83 position, V84 wheel contacts and V86 chassis contact: PASS');
