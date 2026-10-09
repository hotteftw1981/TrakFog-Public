(() => {
  'use strict';

  const qs = (selector, root=document) => root.querySelector(selector);
  const qsa = (selector, root=document) => [...root.querySelectorAll(selector)];
  const fmt = (value, digits=0) => {
    const n = Number(value);
    return Number.isFinite(n)
      ? n.toLocaleString('de-DE',{minimumFractionDigits:digits,maximumFractionDigits:digits})
      : '–';
  };
  // Shared display precision for all four LiveView panels.
  // Real negative values (e.g. regenerative net energy) keep their sign.
  const metricPrecision=Object.freeze({
    odometer:0, range:0, speed:0, battery:0, percent:0, whPerKm:0,
    temperature:1, power:1, chargeEnergy:1, netEnergy:2, money:2
  });
  const fmtMetric=(value,type) => {
    if(value===null || value===undefined || value==='') return '–';
    const n=Number(value);
    if(!Number.isFinite(n)) return '–';
    return fmt(n,metricPrecision[type] ?? 0);
  };

  // Shared mode contract for both instrument panels; never present cached
  // consumption or charge-snapshot values as currently measured power.
  const cockpitState=(vehicle,activeCharge=null) => {
    const mode=vehicle?.mode || 'parked';
    const sourcePower=mode==='charging'
      ? (activeCharge?.current_power_kw ?? vehicle?.power_kw)
      : vehicle?.power_kw;
    const canShowPower=mode==='charging'
      ? activeCharge!=null
      : Boolean(vehicle?.stream_fresh && (mode==='driving'||mode==='parked'));
    const power=canShowPower && sourcePower!=null && sourcePower!==''
      && Number.isFinite(Number(sourcePower)) ? Number(sourcePower) : null;
    const powerStatus=mode==='sleeping'?'Schlafmodus'
      :mode==='stale'?'Daten veraltet'
      :mode==='charging'?'Ladevorgang'
      :power===null?(mode==='parked'?'Parkmodus':'Keine Live-Daten')
      :power < -0.2?'Rekuperation'
      :power > 0.2?'Verbrauch'
      :'Standby';
    const instrument=mode==='driving'
      ? {value:fmtMetric(vehicle?.speed_kmh,'speed'),unit:'km/h'}
      :mode==='charging'
        ? {value:'⚡',unit:''}
        :mode==='sleeping'
          ? {value:'Zz',unit:''}
          :mode==='stale'
            ? {value:'…',unit:''}
            :{value:'P',unit:''};
    return {mode,power,powerStatus,instrument};
  };
  const nerdChargeDisplay=(mode,activeCharge,tesla) => {
    const active=mode==='charging' && activeCharge!=null;
    const last=tesla?.charge_energy_added_kwh;
    const lastKnown=last!=null && Number.isFinite(Number(last)) && Number(last)>0;
    return {
      active,
      status:active ? null : 'Keine aktive Ladung',
      power:active ? activeCharge.current_power_kw ?? tesla?.charger_power_kw : null,
      electricity:active ? [tesla?.charger_voltage_v,tesla?.charger_current_a] : [null,null],
      energyLabel:active
        ? ((tesla?.charge_energy_added_kwh ?? activeCharge.energy_kwh)==null
          ? '– kWh geladen'
          : fmtMetric(tesla?.charge_energy_added_kwh ?? activeCharge.energy_kwh,'chargeEnergy')+' kWh geladen')
        :lastKnown ? 'Zuletzt gemeldet: '+fmtMetric(last,'chargeEnergy')+' kWh'
          :'Keine aktive Ladung',
      timeLabel:active && tesla?.time_to_full_charge_h!=null
        ? fmtMetric(tesla.time_to_full_charge_h,'temperature')+' h bis voll' : '– bis voll'
    };
  };

  const storageGet = (key, fallback=null) => {
    try {
      const value=localStorage.getItem(key);
      return value === null ? fallback : value;
    } catch (_) {
      return fallback;
    }
  };
  const storageSet = (key, value) => {
    try { localStorage.setItem(key,String(value)); } catch (_) {}
  };

  /* PIN gate */
  const pinForm=qs('#livePinForm');
  if(pinForm){
    const pinInput=qs('#livePinInput');
    const dots=qsa('#livePinDots span');
    const submit=qs('#livePinSubmit');
    const hint=qs('#liveDeviceHint');

    const deviceHint=() => {
      const ua=(navigator.userAgent || '').toLowerCase();
      if(ua.includes('tesla')) return 'Tesla Browser';
      if(ua.includes('ipad')) return 'iPad';
      if(ua.includes('iphone')) return 'iPhone';
      if(ua.includes('android') && ua.includes('mobile')) return 'Android Smartphone';
      if(ua.includes('android')) return 'Android Tablet';
      if(ua.includes('smart-tv') || ua.includes('smarttv') || ua.includes('hbbtv')) return 'Smart TV';
      const touch=(navigator.maxTouchPoints || 0)>0;
      const orientation=innerWidth>=innerHeight ? 'Landscape' : 'Portrait';
      return (touch ? 'Touch Browser' : 'Browser')+' · '+orientation;
    };
    if(hint) hint.value=deviceHint();

    const render=() => {
      const value=String(pinInput?.value || '').replace(/\D/g,'').slice(0,6);
      if(pinInput) pinInput.value=value;
      dots.forEach((dot,index)=>dot.classList.toggle('filled',index<value.length));
      if(submit) submit.disabled=value.length!==6;
    };
    const add=(digit) => {
      if(!pinInput || pinInput.value.length>=6) return;
      pinInput.value+=digit;
      render();
    };
    const backspace=() => {
      if(!pinInput) return;
      pinInput.value=pinInput.value.slice(0,-1);
      render();
    };
    const clear=() => {
      if(pinInput) pinInput.value='';
      render();
    };

    qsa('[data-pin-digit]').forEach(button=>button.addEventListener('click',()=>add(button.dataset.pinDigit || '')));
    qs('[data-pin-backspace]')?.addEventListener('click',backspace);
    qs('[data-pin-clear]')?.addEventListener('click',clear);
    window.addEventListener('keydown',event=>{
      if(/^\d$/.test(event.key)){
        event.preventDefault();
        add(event.key);
      } else if(event.key==='Backspace'){
        event.preventDefault();
        backspace();
      } else if(event.key==='Escape'){
        clear();
      } else if(event.key==='Enter' && pinInput?.value.length===6){
        event.preventDefault();
        pinForm.requestSubmit();
      }
    });
    render();
  }

  const root=qs('#liveRoot');
  if(!root) return;

  const stage=qs('#lvStage');
  const track=qs('#lvTrack');
  const swipeZone=qs('#lvSwipeZone');
  const pageName=qs('#lvPageName');
  const vehicleSelect=qs('#lvVehicleSelect');
  const toast=qs('#lvToast');
  const api=root.dataset.api || 'live-data.php';
  const pageNames=['Fahrzeug','Karte','Fahrt & Reise','NerdView'];
  const maxPage=pageNames.length-1;
  let page=Math.max(0,Math.min(maxPage,Number(storageGet('trakfog-live-page','0')) || 0));
  let currentData=null;
  let fetching=false;
  let toastTimer=null;
  let pollTimer=null;
  let selectedVehicleId=Number(storageGet('trakfog-live-vehicle','0')) || 0;

  const showToast=(message,timeout=3200) => {
    if(!toast) return;
    toast.textContent=message;
    toast.hidden=false;
    clearTimeout(toastTimer);
    toastTimer=setTimeout(()=>{ toast.hidden=true; },timeout);
  };

  const layoutName=() => {
    const w=window.innerWidth;
    const h=window.innerHeight;
    if(w<620 || (w<760 && h>w)) return 'Compact';
    if(w<1100 || h<650) return 'Standard';
    if(w<1700) return 'Wide';
    return 'XL';
  };

  const updateDiagnostics=() => {
    const viewport=window.visualViewport;
    const vw=viewport ? Math.round(viewport.width) : window.innerWidth;
    const vh=viewport ? Math.round(viewport.height) : window.innerHeight;
    qs('#lvDiagViewport').textContent=vw+' × '+vh;
    qs('#lvDiagScreen').textContent=screen.width+' × '+screen.height;
    qs('#lvDiagDpr').textContent=String(Math.round((window.devicePixelRatio || 1)*100)/100);
    qs('#lvDiagLayout').textContent=layoutName();
    document.documentElement.dataset.liveLayout=layoutName().toLowerCase();
  };

  const pageWidth=() => Math.max(1,stage?.clientWidth || window.innerWidth);
  const renderPagePosition=(delta=0,animate=true) => {
    if(!track) return;
    track.classList.toggle('dragging',!animate);
    const x=(-page*pageWidth())+delta;
    track.style.transform='translate3d('+x+'px,0,0)';
  };

  let map=null;
  let vehicleMarker=null;
  let routeLine=null;
  const otherVehicleMarkers=new Map();
  let stopMarkers=[];
  let activeMergeSuggestion=null;
  const continuationStorage='trakfog-live-continuation';
  let mapCentered=false;
  let mapFollow=true;
  let mapZoom=Math.max(3,Math.min(19,Number(storageGet('trakfog-live-map-zoom','15')) || 15));
  const ownTeslaVisible=true; // Instance fleet markers and selected Tesla always visible.
  let routeVisible=storageGet('trakfog-live-map-route','1')!=='0';
  const mapThemes=['light','dark','night'];
  let mapTheme=storageGet('trakfog-live-map-theme','dark');
  if(!mapThemes.includes(mapTheme)) mapTheme='dark';

  const setPage=(next,animate=true) => {
    page=Math.max(0,Math.min(maxPage,next));
    storageSet('trakfog-live-page',page);
    renderPagePosition(0,animate);
    qsa('[data-page-dot]').forEach(dot=>dot.classList.toggle('active',Number(dot.dataset.pageDot)===page));
    if(pageName) pageName.textContent=pageNames[page] || '';
    if(page===1){
      ensureMap();
      requestAnimationFrame(()=>{
        map?.invalidateSize();
        updateMap(currentData,true);
      });
    }
  };

  qs('[data-page-prev]')?.addEventListener('click',event=>{
    event.stopPropagation();
    setPage(page-1);
  });
  qs('[data-page-next]')?.addEventListener('click',event=>{
    event.stopPropagation();
    setPage(page+1);
  });
  qsa('[data-page-dot]').forEach(dot=>dot.addEventListener('click',event=>{
    event.stopPropagation();
    setPage(Number(dot.dataset.pageDot));
  }));

  let swipeActive=false;
  let swipeStartX=0;
  let swipeDelta=0;
  let swipePointerId=null;

  swipeZone?.addEventListener('pointerdown',event=>{
    if(event.target.closest('button')) return;
    swipeActive=true;
    swipeStartX=event.clientX;
    swipeDelta=0;
    swipePointerId=event.pointerId;
    swipeZone.classList.add('dragging');
    try { swipeZone.setPointerCapture(event.pointerId); } catch (_) {}
    renderPagePosition(0,false);
  });
  swipeZone?.addEventListener('pointermove',event=>{
    if(!swipeActive || event.pointerId!==swipePointerId) return;
    swipeDelta=event.clientX-swipeStartX;
    const width=pageWidth();
    if((page===0 && swipeDelta>0) || (page===maxPage && swipeDelta<0)) swipeDelta*=.28;
    renderPagePosition(swipeDelta,false);
  });
  const endSwipe=(event) => {
    if(!swipeActive || (event && event.pointerId!==swipePointerId)) return;
    const threshold=Math.max(44,pageWidth()*.075);
    const delta=swipeDelta;
    swipeActive=false;
    swipePointerId=null;
    swipeZone?.classList.remove('dragging');
    if(delta<=-threshold) setPage(page+1,true);
    else if(delta>=threshold) setPage(page-1,true);
    else setPage(page,true);
  };
  swipeZone?.addEventListener('pointerup',endSwipe);
  swipeZone?.addEventListener('pointercancel',endSwipe);

  swipeZone?.addEventListener('wheel',event=>{
    if(Math.abs(event.deltaX)<=Math.abs(event.deltaY) || Math.abs(event.deltaX)<18) return;
    event.preventDefault();
    setPage(page+(event.deltaX>0?1:-1));
  },{passive:false});

  window.addEventListener('keydown',event=>{
    const tag=(event.target?.tagName || '').toLowerCase();
    if(['input','select','textarea'].includes(tag)) return;
    if(event.key==='ArrowRight') setPage(page+1);
    if(event.key==='ArrowLeft') setPage(page-1);
  });

  const duration=(seconds) => {
    const total=Math.max(0,Number(seconds || 0));
    const hours=Math.floor(total/3600);
    const minutes=Math.floor((total%3600)/60);
    if(hours>0) return hours+' h '+String(minutes).padStart(2,'0')+' min';
    return Math.max(1,minutes)+' min';
  };
  const dataAge=(value) => {
    if(!value) return 'keine Daten';
    const normalized=String(value).includes('T') ? String(value) : String(value).replace(' ','T')+'Z';
    const ts=new Date(normalized).getTime();
    if(!Number.isFinite(ts)) return '–';
    const seconds=Math.max(0,Math.floor((Date.now()-ts)/1000));
    if(seconds<15) return 'gerade eben';
    if(seconds<60) return 'vor '+seconds+' s';
    if(seconds<3600) return 'vor '+Math.floor(seconds/60)+' min';
    return 'vor '+Math.floor(seconds/3600)+' h';
  };
  const modeLabel=(mode) => ({
    driving:'Fährt',
    charging:'Lädt',
    sleeping:'Schläft',
    stale:'Daten alt',
    parked:'Geparkt'
  }[mode] || 'Fahrzeug');

  const updateVehicles=(data) => {
    const vehicles=Array.isArray(data.vehicles)?data.vehicles:[];
    if(!vehicleSelect) return;

    const signature=vehicles.map(v=>v.id+':'+v.name).join('|');
    if(vehicleSelect.dataset.signature!==signature){
      vehicleSelect.dataset.signature=signature;
      vehicleSelect.innerHTML='';
      vehicles.forEach(vehicle=>{
        const option=document.createElement('option');
        option.value=String(vehicle.id);
        option.textContent=vehicle.name;
        vehicleSelect.appendChild(option);
      });
    }

    if(data.vehicle?.id){
      vehicleSelect.value=String(data.vehicle.id);
      selectedVehicleId=Number(data.vehicle.id);
      storageSet('trakfog-live-vehicle',selectedVehicleId);
    }
    vehicleSelect.hidden=vehicles.length<=1;
  };

  vehicleSelect?.addEventListener('change',()=>{
    selectedVehicleId=Number(vehicleSelect.value || 0);
    storageSet('trakfog-live-vehicle',selectedVehicleId);
    mapCentered=false;
    mapFollow=true;
    updateFollowButton();
    fetchData(true);
  });

  const setText=(selector,value) => {
    const node=qs(selector);
    if(node) node.textContent=value;
  };

  const setRing=(selector,value,max=100) => {
    const node=qs(selector);
    if(!node) return;
    const n=Number(value);
    const pct=Number.isFinite(n) && Number(max)>0
      ? Math.max(0,Math.min(100,(n/Number(max))*100))
      : 0;
    node.style.setProperty('--ring-value',pct.toFixed(2));
  };

  const setSpeedGauge=(value) => {
    const node=qs('#lvSpeedGauge');
    if(!node) return;
    const n=Number(value);
    const pct=Number.isFinite(n)?Math.max(0,Math.min(100,(n/220)*100)):0;
    node.style.setProperty('--speed-value',pct.toFixed(2));
  };

  const updateMainPage=(data) => {
    const vehicle=data.vehicle;
    if(!vehicle) {
      setText('#lvTopState','kein Tesla verbunden');
      setText('#lvHeroLabel','TrakFog Live');
      setText('#lvHeroValue','–');
      setText('#lvHeroUnit','');
      setText('#lvHeroSub','Noch kein Fahrzeug vorhanden.');
      return;
    }

    const state=cockpitState(vehicle,data.charge);
    const {mode}=state;
    const heroGauge=qs('#lvSpeedGauge');
    if(heroGauge) heroGauge.dataset.mode=mode;
    setText('#lvTopState',vehicle.name+' · '+modeLabel(mode));
    setText('#lvMapVehicle',vehicle.name);
    setText('#lvVehicleState',modeLabel(mode)+' · '+(vehicle.state || 'unknown'));
    setText('#lvDataAge',dataAge(vehicle.last_data_at));
    setText('#lvLocation',vehicle.location || 'Standort unbekannt');
    setText('#lvBattery',vehicle.battery==null?'–':fmtMetric(vehicle.battery,'battery'));
    setText('#lvRange',vehicle.range_km==null?'– km':fmtMetric(vehicle.range_km,'range')+' km');
    setText('#lvDriveGear',vehicle.shift_state || (mode==='parked'||mode==='sleeping'||mode==='charging'?'P':'–'));

    const livePower=state.power;
    const hasLivePower=livePower!==null;
    setText('#lvPowerValue',hasLivePower?fmtMetric(livePower,'power'):'–');
    setText('#lvPowerDirection',state.powerStatus);
    setRing('#lvPowerRing',hasLivePower?Math.abs(livePower):0,250);
    const powerRing=qs('#lvPowerRing');
    if(powerRing) {
      powerRing.classList.toggle('regen',hasLivePower&&livePower<0);
      powerRing.classList.toggle('has-live-power',hasLivePower);
    }

    setRing('#lvBatteryRing',vehicle.battery,100);
    setRing('#lvMapBatteryRing',vehicle.battery,100);
    setText('#lvMapBattery',vehicle.battery==null?'–':fmtMetric(vehicle.battery,'battery'));
    setText('#lvMapSpeed',fmtMetric(vehicle.speed_kmh,'speed')+' km/h');
    setText('#lvMapRange',vehicle.range_km==null?'– km':fmtMetric(vehicle.range_km,'range')+' km');
    // Preserve signed stream energy: downhill drives may have a real negative net balance.
    const mapTripEnergy=data.trip?.energy_kwh==null?null:Number(data.trip.energy_kwh);
    const mapTripEnergyLabel=mapTripEnergy!==null&&Number.isFinite(mapTripEnergy)
      ? ' · '+(mapTripEnergy<0?'♻️ ':'')+fmtMetric(mapTripEnergy,'netEnergy')+' kWh' : '';
    setText('#lvMapTrip',data.trip?fmt(data.trip.distance_km,1)+' km'+mapTripEnergyLabel:'keine Fahrt');
    setText('#lvMapLocation',vehicle.location || 'Standort unbekannt');

    const liveDot=qs('#lvLiveDot');
    liveDot?.classList.remove('live','drive','charge');
    if(vehicle.stream_fresh) liveDot?.classList.add(mode==='driving'?'drive':(mode==='charging'?'charge':'live'));

    if(mode==='driving'){
      setText('#lvHeroLabel','Geschwindigkeit');
      setText('#lvHeroValue',fmtMetric(vehicle.speed_kmh,'speed'));
      setText('#lvHeroUnit','km/h');
      setText('#lvHeroSub',(vehicle.shift_state?'Gang '+vehicle.shift_state+' · ':'')+(vehicle.location || 'unterwegs'));
      setSpeedGauge(vehicle.speed_kmh);
    } else if(mode==='charging'){
      const power=data.charge?.current_power_kw ?? vehicle.power_kw;
      setText('#lvHeroLabel','Ladeleistung');
      setText('#lvHeroValue',power==null?'–':fmt(power,1));
      setText('#lvHeroUnit','kW');
      setText('#lvHeroSub',data.charge?.location || vehicle.location || 'Ladevorgang aktiv');
      setSpeedGauge(0);
    } else if(mode==='sleeping'){
      setText('#lvHeroLabel','Schlafmodus');
      setText('#lvHeroValue',state.instrument.value);
      setText('#lvHeroUnit','');
      setText('#lvHeroSub',(vehicle.location || 'Standort unbekannt')+' · '+(vehicle.battery==null?'Akku –':fmtMetric(vehicle.battery,'battery')+' %')+' · '+(vehicle.range_km==null?'Reichweite –':fmtMetric(vehicle.range_km,'range')+' km'));
      setSpeedGauge(0);
    } else if(mode==='stale'){
      setText('#lvHeroLabel','Daten veraltet');
      setText('#lvHeroValue','…');
      setText('#lvHeroUnit','');
      setText('#lvHeroSub',(vehicle.location || 'Letzter Standort unbekannt')+' · '+dataAge(vehicle.last_data_at));
      setSpeedGauge(0);
    } else {
      setText('#lvHeroLabel','Geparkt');
      setText('#lvHeroValue',state.instrument.value);
      setText('#lvHeroUnit','');
      setText('#lvHeroSub',(vehicle.location || 'Standort unbekannt')+' · '+(vehicle.battery==null?'Akku –':fmtMetric(vehicle.battery,'battery')+' %')+' · '+(vehicle.range_km==null?'Reichweite –':fmtMetric(vehicle.range_km,'range')+' km'));
      setSpeedGauge(0);
    }

    const sessionEmpty=qs('#lvSessionEmpty');
    const idleSession=!data.charge && !data.trip;
    const labels=data.charge
      ? ['Geladen','Dauer','Ladeleistung','Max. Leistung']
      : ['Strecke','Dauer','Verbrauch','Max.'];
    ['#lvTripDistanceLabel','#lvTripDurationLabel','#lvTripConsumptionLabel','#lvTripMaxLabel']
      .forEach((selector,index)=>setText(selector,labels[index]));
    if(sessionEmpty)sessionEmpty.hidden=!idleSession;
    qs('.lv-session-card')?.classList.toggle('is-idle',idleSession);
    if(data.charge){
      setText('#lvSessionLabel','Aktive Ladung');
      qs('#lvSessionStatus')?.classList.remove('lv-regen-champion');
      setText('#lvSessionStatus','⚡ lädt');
      setText('#lvTripDistance',(data.charge.energy_kwh==null?'–':fmt(data.charge.energy_kwh,1)+' kWh'));
      setText('#lvTripDuration',duration(data.charge.duration_seconds));
      setText('#lvTripConsumption',(data.charge.current_power_kw==null?'–':fmt(data.charge.current_power_kw,1)+' kW'));
      setText('#lvTripMax',(data.charge.max_power_kw==null?'–':fmt(data.charge.max_power_kw,1)+' kW'));
    } else if(data.trip){
      setText('#lvSessionLabel','Aktuelle Fahrt');
      // A negative, measured stream balance is valid on downhill trips. Never derive this badge from SoC.
      const tripNetEnergy=data.trip.energy_kwh==null ? null : Number(data.trip.energy_kwh);
      const isRegenChampion=tripNetEnergy!==null && Number.isFinite(tripNetEnergy)
        && tripNetEnergy < -0.0001 && Number(data.trip.distance_km)>0;
      const sessionStatus=qs('#lvSessionStatus');
      setText('#lvSessionStatus',isRegenChampion?'♻️ Rekuperations-Champion':'● live');
      sessionStatus?.classList.toggle('lv-regen-champion',isRegenChampion);
      if(sessionStatus) sessionStatus.title=isRegenChampion
        ? 'Negative Netto-Stream-Energie: mehr Energie zurückgewonnen als verbraucht.'
        : '';
      setText('#lvTripDistance',fmt(data.trip.distance_km,1)+' km');
      setText('#lvTripDuration',duration(data.trip.duration_seconds));
      setText('#lvTripConsumption',data.trip.avg_wh_km==null?'–':fmtMetric(data.trip.avg_wh_km,'whPerKm')+' Wh/km');
      setText('#lvTripMax',data.trip.max_speed_kmh==null?'–':fmt(data.trip.max_speed_kmh,0)+' km/h');
    } else {
      setText('#lvSessionLabel','Aktuelle Session');
      qs('#lvSessionStatus')?.classList.remove('lv-regen-champion');
      setText('#lvSessionStatus','keine');
      setText('#lvTripDistance','–');
      setText('#lvTripDuration','–');
      setText('#lvTripConsumption','–');
      setText('#lvTripMax','–');
    }

    if(data.journey){
      setText('#lvJourneyName',data.journey.title);
      setText('#lvJourneyDestination',data.journey.destination || 'unterwegs');
      setText('#lvJourneyDistance',fmt(data.journey.distance_km,1)+' km');
    } else {
      setText('#lvJourneyName','Keine aktive Reise');
      setText('#lvJourneyDestination','–');
      setText('#lvJourneyDistance','–');
    }
  };

  const updateJourneyPage=(data) => {
    const journey=data.journey;
    const vehicle=data.vehicle;
    const trip=data.trip;
    const charge=data.charge;
    const journeyHero=qs('.lv-journey-hero');
    journeyHero?.classList.toggle('is-empty',!journey);
    journeyHero?.closest('.lv-journey-page-grid')?.classList.toggle('has-no-journey',!journey);

    setRing('#lvJourneyRing',vehicle?.battery,100);
    setText('#lvJourneyRingValue',vehicle?.battery==null?'–':fmtMetric(vehicle.battery,'battery'));
    setText(
      '#lvJourneyReadyMeta',
      vehicle
        ? [
            vehicle.battery==null?'Akku –':fmtMetric(vehicle.battery,'battery')+' % Akku',
            vehicle.range_km==null?'Reichweite –':fmtMetric(vehicle.range_km,'range')+' km Reichweite',
            vehicle.location || null
          ].filter(Boolean).join(' · ')
        : 'Tesla wartet auf die nächste Reise.'
    );

    if(journey){
      setText('#lvJourneyDuration',fmt(journey.trip_count,0)+' Fahrten · '+fmt(journey.charge_count,0)+' Stopps');
      setText('#lvJourneyPageTitle',journey.title);
      setText('#lvJourneyPageDestination',journey.destination || 'Reise läuft');
      setText('#lvJourneyTotalTime',duration(journey.duration_seconds));
      setText('#lvJourneyPageDistance',fmt(journey.distance_km,1)+' km');
      setText('#lvJourneyChargedEnergy',fmt(journey.charged_kwh || 0,1)+' kWh');
      setText('#lvJourneyCost',journey.confirmed_cost_count>0?fmt(journey.charging_cost,2)+' €':'–');

      const budgetWrap=qs('#lvJourneyBudgetWrap');
      if(journey.budget_amount!=null && Number(journey.budget_amount)>0){
        budgetWrap.hidden=false;
        const pct=Math.max(0,Math.min(100,(Number(journey.charging_cost || 0)/Number(journey.budget_amount))*100));
        setText('#lvJourneyBudgetText',fmt(journey.charging_cost,2)+' / '+fmt(journey.budget_amount,2)+' €');
        const bar=qs('#lvJourneyBudgetBar');
        if(bar) bar.style.width=pct+'%';
      } else {
        budgetWrap.hidden=true;
      }
    } else {
      setText('#lvJourneyDuration','–');
      setText('#lvJourneyPageTitle','Keine aktive Reise');
      setText('#lvJourneyPageDestination','Starte oder plane eine Reise im TrakFog-Backend.');
      setText('#lvJourneyTotalTime','–');
      setText('#lvJourneyPageDistance','–');
      setText('#lvJourneyChargedEnergy','–');
      setText('#lvJourneyCost','–');
      qs('#lvJourneyBudgetWrap').hidden=true;
    }

    setText('#lvNowMode',vehicle?modeLabel(vehicle.mode):'–');
    setText('#lvNowTripDistance',trip?fmt(trip.distance_km,1)+' km':'–');
    setText('#lvNowTripDuration',trip?duration(trip.duration_seconds):'keine Fahrt');
    setText('#lvNowPower',vehicle?.power_kw==null?'–':fmtMetric(vehicle.power_kw,'power')+' kW');
    setText('#lvNowSpeed',vehicle?fmtMetric(vehicle.speed_kmh,'speed')+' km/h':'–');
    setText('#lvNowBattery',vehicle?.battery==null?'–':fmtMetric(vehicle.battery,'battery')+' %');
    setText('#lvNowRange',vehicle?.range_km==null?'–':fmtMetric(vehicle.range_km,'range')+' km');
    setText('#lvNowCharge',charge?.energy_kwh==null?'–':fmt(charge.energy_kwh,1)+' kWh');
    setText('#lvNowChargeTime',charge?duration(charge.duration_seconds):'keine Ladung');

    const driveSeconds=Math.max(0,Number(journey?.drive_seconds || trip?.duration_seconds || 0));
    const chargeSeconds=Math.max(0,Number(journey?.charge_seconds || charge?.duration_seconds || 0));
    const totalActivity=Math.max(1,driveSeconds+chargeSeconds);
    const drivePct=(driveSeconds/totalActivity)*100;
    const chargePct=(chargeSeconds/totalActivity)*100;
    const driveBar=qs('#lvJourneyDriveBar');
    const chargeBar=qs('#lvJourneyChargeBar');
    if(driveBar) driveBar.style.width=drivePct+'%';
    if(chargeBar) chargeBar.style.width=chargePct+'%';
    setText('#lvJourneyDriveTime',driveSeconds>0?duration(driveSeconds):'–');
    setText('#lvJourneyChargeTime',chargeSeconds>0?duration(chargeSeconds):'–');
    setText(
      '#lvJourneyActivityText',
      driveSeconds+chargeSeconds>0
        ? Math.round(drivePct)+' % Fahrt · '+Math.round(chargePct)+' % Laden'
        : 'noch keine Aktivität'
    );

    if(charge){
      setText('#lvChargeState','● aktiv');
      setText('#lvChargeLocation',charge.location || 'Ladevorgang');
      setText('#lvChargePower',charge.current_power_kw==null?'–':fmt(charge.current_power_kw,1)+' kW');
      setText('#lvChargeEnergy',charge.energy_kwh==null?'–':fmt(charge.energy_kwh,1)+' kWh');
      setText('#lvChargeDuration',duration(charge.duration_seconds));
      setText('#lvChargeCost',charge.cost_amount==null?'–':fmt(charge.cost_amount,2)+' '+(charge.currency || 'EUR'));
    } else {
      setText('#lvChargeState','keine');
      setText('#lvChargeLocation','Keine aktive Ladung');
      setText('#lvChargePower','–');
      setText('#lvChargeEnergy','–');
      setText('#lvChargeDuration','–');
      setText('#lvChargeCost','–');
    }
  };

  const updateNerdPage=(data) => {
    const vehicle=data.vehicle || null;
    const tesla=data.tesla_nerd || null;
    const yesNo=(value) => value==null ? '–' : (value ? 'AN' : 'AUS');
    const modeName=(mode) => ({
      driving:'FÄHRT',
      charging:'LÄDT',
      sleeping:'SCHLÄFT',
      stale:'DATEN ALT',
      parked:'GEPARKT'
    }[mode] || String(mode || 'TESLA').toUpperCase());
    const chargeName=(state) => {
      const raw=String(state || '').toLowerCase();
      if(raw==='charging') return 'Lädt';
      if(raw==='complete') return 'Voll / beendet';
      if(raw==='disconnected') return 'Nicht verbunden';
      if(raw==='stopped') return 'Gestoppt';
      if(raw==='starting') return 'Startet';
      return state || 'Nicht aktiv';
    };
    const pressure=(value) => value==null ? '–' : fmt(value,2)+' bar';

    const direction=(degrees) => {
      const n=Number(degrees);
      if(!Number.isFinite(n)) return '–';
      const labels=['N','NNO','NO','ONO','O','OSO','SO','SSO','S','SSW','SW','WSW','W','WNW','NW','NNW'];
      const normalized=((n%360)+360)%360;
      return labels[Math.round(normalized/22.5)%16]+' · '+fmt(normalized,0)+'°';
    };

    if(!vehicle || !tesla){
      setText('#lvNerdMode','KEINE DATEN');
      setText('#lvNerdSpeed','–');
      setText('#lvNerdDataAge','–');
      return;
    }

    setText('#lvNerdDataAge','Daten '+dataAge(tesla.data_at));
    const state=cockpitState(vehicle,data.charge);
    const nerdHero=qs('.lv-tesla-hero');
    if(nerdHero) nerdHero.dataset.mode=state.mode;
    setText('#lvNerdMode',modeName(state.mode));
    setText('#lvNerdSpeed',state.instrument.value);
    setText('#lvNerdSpeedUnit',state.instrument.unit);
    const nerdSpeedGauge=qs('#lvNerdSpeedGauge');
    if(nerdSpeedGauge){
      const speed=Math.max(0,Math.min(220,Number(vehicle.speed_kmh || 0)));
      nerdSpeedGauge.style.setProperty('--nerd-speed-value',((speed/220)*100).toFixed(2));
      nerdSpeedGauge.dataset.mode=vehicle.mode || 'parked';
    }
    setText('#lvNerdPower',state.power===null?'–':fmtMetric(state.power,'power'));
    setText('#lvNerdGear',vehicle.shift_state || ((state.mode==='sleeping'||state.mode==='parked')?'P':'–'));

    setText('#lvNerdSoc',vehicle.battery==null?'–':fmtMetric(vehicle.battery,'battery'));
    setText('#lvNerdUsableBattery',tesla.usable_battery==null?'nutzbar –':('nutzbar '+fmt(tesla.usable_battery,0)+' %'));
    const chargeDisplay=nerdChargeDisplay(state.mode,data.charge,tesla);
    setText('#lvNerdChargingState',chargeDisplay.active
      ? (Number(data.charge?.current_power_kw)>0.05 ? 'Lädt' : 'Ladesession aktiv')
      : chargeDisplay.status);
    setText('#lvNerdRatedRange',tesla.rated_range_km==null?'–':fmtMetric(tesla.rated_range_km,'range'));
    setText('#lvNerdEstimatedRange',tesla.estimated_range_km==null?'–':fmtMetric(tesla.estimated_range_km,'range'));
    setText('#lvNerdChargerPower',chargeDisplay.power==null?'–':fmtMetric(chargeDisplay.power,'power'));
    setText(
      '#lvNerdChargeElectrical',
      chargeDisplay.electricity.every(value=>value==null)
        ? '–'
        : chargeDisplay.electricity.map(value=>value==null?'–':fmt(value,0)).join(' / ')
    );
    setText('#lvNerdChargeAdded',chargeDisplay.energyLabel);
    setText('#lvNerdChargeTime',chargeDisplay.timeLabel);
    setRing('#lvNerdBatteryRing',vehicle.battery,100);

    setText('#lvNerdVehicleModel',tesla.model_name || vehicle.name || 'Tesla');
    setText('#lvNerdSoftware',tesla.software_version || '–');
    setText('#lvNerdOdometer',tesla.odometer_km==null?'–':fmtMetric(tesla.odometer_km,'odometer'));
    const softwareUpdate=tesla.software_update || {};
    const softwareUpdateBox=qs('#lvNerdUpdate');
    if(softwareUpdateBox){
      softwareUpdateBox.hidden=!softwareUpdate.available;
      if(softwareUpdate.available){
        const progress=softwareUpdate.install_percent ?? softwareUpdate.download_percent;
        const label=(softwareUpdate.version || 'Neue Version')+
          (softwareUpdate.status ? ' · '+String(softwareUpdate.status).toUpperCase() : '')+
          (progress==null ? '' : ' · '+fmt(progress,0)+' %');
        setText('#lvNerdUpdateText',label);
      }
    }
    let port='–';
    if(tesla.charge_port_open!=null){
      port=tesla.charge_port_open?'OFFEN':'ZU';
      if(tesla.charge_port_latch) port+=' · '+String(tesla.charge_port_latch).toUpperCase();
    }
    setText('#lvNerdChargePort',port);

    const panels=Array.isArray(tesla.open_panels)?tesla.open_panels:[];
    setText('#lvNerdPanels',panels.length?panels.join(', '):'ALLES GESCHLOSSEN');
    const panelStatus=qs('#lvNerdPanels')?.closest('.lv-tesla-status-wide');
    panelStatus?.classList.toggle('warn',panels.length>0);
    panelStatus?.classList.toggle('ok',panels.length===0);
    setText('#lvNerdVin',tesla.vin_suffix?'VIN ••••••'+tesla.vin_suffix:'VIN ••••••');

    setText('#lvNerdClimateState',tesla.climate_on==null?'–':(tesla.climate_on?'AN':'AUS'));
    setText('#lvNerdFan',tesla.fan_status==null?'Lüfter –':'Lüfter '+fmt(tesla.fan_status,0));
    setText('#lvNerdInsideTemp',tesla.inside_temp_c==null?'–':fmtMetric(tesla.inside_temp_c,'temperature'));
    setText('#lvNerdOutsideTemp',tesla.outside_temp_c==null?'–':fmtMetric(tesla.outside_temp_c,'temperature'));
    setText('#lvNerdDriverTemp',tesla.driver_temp_c==null?'–':fmtMetric(tesla.driver_temp_c,'temperature')+' °C');
    setText('#lvNerdPassengerTemp',tesla.passenger_temp_c==null?'–':fmtMetric(tesla.passenger_temp_c,'temperature')+' °C');

    setText('#lvNerdPlace',vehicle.location || 'Standort unbekannt');
    setText('#lvNerdPositionFresh',tesla.address?'Geocodiert':'GPS / Ort');
    setText('#lvNerdAddress',tesla.address || vehicle.location || 'Adresse wird ermittelt …');
    setText(
      '#lvNerdCity',
      [tesla.postcode,tesla.city].filter(Boolean).join(' ') || '–'
    );
    setText('#lvNerdStreet',tesla.street_name || '–');

    let roadType=tesla.road_type || '–';
    if(tesla.road_ref) roadType+=(roadType==='–'?'':' · ')+tesla.road_ref;
    setText('#lvNerdRoadType',roadType);

    setText('#lvNerdDirection',direction(tesla.heading_deg));
    setText('#lvNerdElevation',tesla.elevation_m==null?'– m':fmt(tesla.elevation_m,0)+' m');
    const compassNeedle=qs('#lvNerdCompassNeedle');
    const heading=Number(tesla.heading_deg);
    if(compassNeedle) compassNeedle.style.transform='translate(-50%,-100%) rotate('+(Number.isFinite(heading)?heading:0)+'deg)';
    setText('#lvNerdCompassDegrees',Number.isFinite(heading)?fmt(heading,0)+'°':'–°');
  };

  const makeCarIcon=(heading=0,other=false,stale=false) => {
    const angle=Number.isFinite(Number(heading))?Number(heading):0;
    return L.divIcon({
      className:'lv-map-car-wrap'+(other?' lv-map-car-other':'')+(stale?' lv-map-car-stale':''),
      html:'<span class="lv-map-car"><i style="display:block;transform:rotate('+angle+'deg);font-style:normal">▲</i></span>',
      iconSize:other?[27,27]:[34,34],
      iconAnchor:other?[13,13]:[17,17],
      popupAnchor:[0,-16]
    });
  };

  const ensureMap=() => {
    if(map || typeof L==='undefined' || !qs('#lvMap')) return;
    map=L.map('lvMap',{
      preferCanvas:true,
      zoomControl:true,
      attributionControl:true,
      dragging:true,
      scrollWheelZoom:'center',
      doubleClickZoom:'center',
      touchZoom:'center'
    }).setView([51.16,10.45],6);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
      maxZoom:19,
      attribution:'&copy; OpenStreetMap'
    }).addTo(map);

    map.on('zoomend',()=>{
      mapZoom=map.getZoom();
      storageSet('trakfog-live-map-zoom',mapZoom);
    });
    // Manual pan pauses follow mode until the driver chooses to re-enable it.
    map.on('dragstart',()=>{
      mapFollow=false;
      updateFollowButton();
    });
    requestAnimationFrame(()=>map.invalidateSize());
  };

  const updateFollowButton=() => {
    const button=qs('#lvMapFollow');
    button?.classList.toggle('active',mapFollow);
    if(button){
      button.textContent=mapFollow?'◎ Tesla folgen':'⌖ Tesla folgen';
      button.setAttribute('aria-pressed',String(mapFollow));
      button.title=mapFollow?'Automatische Zentrierung aktiv. Karte ziehen zum Erkunden.':'Zum Tesla zurückkehren und wieder automatisch folgen';
    }
  };

  const updateMap=(data,forceCenter=false) => {
    if(page!==1 && !map) return;
    ensureMap();
    if(!map || !data?.vehicle) return;

    const vehicle=data.vehicle;
    const lat=Number(vehicle.latitude);
    const lon=Number(vehicle.longitude);
    const hasPosition=vehicle.latitude!=null&&vehicle.longitude!=null&&Number.isFinite(lat)&&Number.isFinite(lon)&&Math.abs(lat)<=90&&Math.abs(lon)<=180&&!(lat===0&&lon===0);

    if(hasPosition){
      if(!vehicleMarker){
        vehicleMarker=L.marker([lat,lon],{icon:makeCarIcon(vehicle.heading),zIndexOffset:700})
          .addTo(map);
      } else {
        vehicleMarker.setLatLng([lat,lon]);
        vehicleMarker.setIcon(makeCarIcon(vehicle.heading));
      }
      vehicleMarker.bindPopup(
        '<div class="lv-map-popup"><strong>'+String(vehicle.name || 'Tesla').replace(/[<>&]/g,'')+'</strong>'+
        '<span>'+fmtMetric(vehicle.battery,'battery')+' % · '+fmtMetric(vehicle.speed_kmh,'speed')+' km/h</span></div>'
      );
      if(!ownTeslaVisible && map.hasLayer(vehicleMarker)) map.removeLayer(vehicleMarker);
      if(ownTeslaVisible && !map.hasLayer(vehicleMarker)) vehicleMarker.addTo(map);
    }

    // Coordinates are from our OWN TrakFog instance, not community sharing.
    // Never touch the Tesla API: these positions were stored by the existing worker.
    const ids=new Set();
    (Array.isArray(data.vehicles)?data.vehicles:[]).forEach(item=>{
      if(Number(item.id)===Number(vehicle.id)) return;
      const otherLat=item.latitude==null?NaN:Number(item.latitude);
      const otherLon=item.longitude==null?NaN:Number(item.longitude);
      if(!Number.isFinite(otherLat)||!Number.isFinite(otherLon)||Math.abs(otherLat)>90||Math.abs(otherLon)>180||(otherLat===0&&otherLon===0))return;
      const id=Number(item.id);ids.add(id);
      const age=item.last_seen_at?Date.now()-Date.parse(String(item.last_seen_at).replace(' ','T')+'Z'):Infinity;
      const stale=!Number.isFinite(age)||age>5*60*1000;
      let marker=otherVehicleMarkers.get(id);
      if(!marker){
        marker=L.marker([otherLat,otherLon],{icon:makeCarIcon(item.heading,true,stale),zIndexOffset:200}).addTo(map);
        marker.on('click',()=>{
          selectedVehicleId=id;
          storageSet('trakfog-live-vehicle',id);
          if(vehicleSelect)vehicleSelect.value=String(id);
          mapCentered=false;
          mapFollow=true;
          updateFollowButton();
          fetchData(true);
        });
        otherVehicleMarkers.set(id,marker);
      } else { marker.setLatLng([otherLat,otherLon]); marker.setIcon(makeCarIcon(item.heading,true,stale)); }
      marker.bindPopup('<div class="lv-map-popup"><strong>'+String(item.name||'Tesla').replace(/[<>&"]/g,'')+'</strong><span>'+ (stale?'Letzter bekannter Standort · ':'')+fmt(item.battery,0)+' %</span></div>');
    });
    for(const [id,marker] of otherVehicleMarkers){
      if(!ids.has(id)){ map.removeLayer(marker);otherVehicleMarkers.delete(id); }
    }
    let routeSegments=Array.isArray(data.route?.segments)&&data.route.segments.length
      ? data.route.segments
      : [data.route?.points||[]];
    let shownStops=data.route?.stops||[];
    const suggestion=data.merge_suggestion;
    const preview=data.merge_preview;
    if(suggestion && preview?.previous_points?.length && !data.can_merge){
      const accepted=String(data.vehicle.id)+':'+String(suggestion.current_trip_id)+':'+String(suggestion.previous_trip_id);
      if(storageGet(continuationStorage,'')===accepted){
        routeSegments=[preview.previous_points,...routeSegments];
        if(preview.stop)shownStops=[preview.stop,...shownStops];
      }
    }
    const lines=routeSegments.map(segment=>(segment||[])
      .map(point=>[Number(point.lat),Number(point.lon)])
      .filter(point=>Number.isFinite(point[0])&&Number.isFinite(point[1]))
    ).filter(segment=>segment.length>=2);
    // Keep separate polylines between parking stops. Never invent a road through a supermarket.
    if(routeLine){
      routeLine.setLatLngs(lines);
    } else if(lines.length){
      routeLine=L.polyline(lines,{
        weight:4,
        opacity:.84,
        color:'#e82127',
        lineCap:'round',
        lineJoin:'round'
      }).addTo(map);
    }
    if(routeLine){
      if(!routeVisible && map.hasLayer(routeLine)) map.removeLayer(routeLine);
      if(routeVisible && !map.hasLayer(routeLine)) routeLine.addTo(map);
    }
    stopMarkers.forEach(marker=>map.removeLayer(marker));stopMarkers=[];
    if(routeVisible){
      shownStops.forEach((stop,index)=>{
        const slat=Number(stop.lat),slon=Number(stop.lon);
        if(!Number.isFinite(slat)||!Number.isFinite(slon)||(slat===0&&slon===0))return;
        stopMarkers.push(L.circleMarker([slat,slon],{radius:6,color:'#76c7ff',weight:2,fillColor:'#0d263f',fillOpacity:.86})
          .addTo(map).bindPopup('Zwischenstopp '+(index+1)+' · '+Number(stop.gap_minutes||0)+' min'));
      });
    }

    if(hasPosition){
      if(mapFollow && (forceCenter || !mapCentered)){
        map.setView([lat,lon],mapZoom,{animate:false});
        mapCentered=true;
      } else if(mapFollow){
        // Maintain the driver-selected zoom while following the selected car.
        map.panTo([lat,lon],{animate:true,duration:.35});
      }
    }
  };

  const routeToggle=qs('#lvRouteToggle');
  const mapThemeToggle=qs('#lvMapThemeToggle');
  const applyMapTheme=() => {
    root.dataset.mapTheme=mapTheme;
    const labels={
      light:'☀️ Hell',
      dark:'🌙 Dunkel',
      night:'🌑 Nacht'
    };
    if(mapThemeToggle) mapThemeToggle.textContent=labels[mapTheme] || labels.dark;
    storageSet('trakfog-live-map-theme',mapTheme);
  };
  const renderMapToggles=() => {
    routeToggle?.classList.toggle('active',routeVisible);
  };
  routeToggle?.addEventListener('click',()=>{
    routeVisible=!routeVisible;
    storageSet('trakfog-live-map-route',routeVisible?'1':'0');
    renderMapToggles();
    updateMap(currentData,false);
  });
  mapThemeToggle?.addEventListener('click',()=>{
    const index=mapThemes.indexOf(mapTheme);
    mapTheme=mapThemes[(index+1)%mapThemes.length];
    applyMapTheme();
    requestAnimationFrame(()=>map?.invalidateSize());
  });
  qs('#lvMapFollow')?.addEventListener('click',()=>{
    mapFollow=true;
    updateFollowButton();
    updateMap(currentData,true);
  });
  qsa('[data-future-feature]').forEach(button=>button.addEventListener('click',()=>{
    const feature=button.dataset.futureFeature;
    const messages={
      location:'⌖ Der große Standort-Schalter ist für die spätere Ein/Aus-Freigabe vorbereitet. Grün = an, Rot = aus.',
      teslas:'👥 Hier erscheinen später freigegebene Teslas anderer Teilnehmer – nur mit ausdrücklicher Standortfreigabe.',
      places:'⭐ Hier kommen später geprüfte Community-Orte wie gute Ladeplätze, Restaurants und Empfehlungen hinein.'
    };
    showToast(messages[feature] || 'Diese LiveView-Funktion ist vorbereitet.');
  }));
  renderMapToggles();
  applyMapTheme();
  updateFollowButton();

  const mergeToggle=qs('#lvTripMergeToggle');
  const mergeDialog=qs('#lvTripMergeDialog');
  const mergeDetail=qs('#lvTripMergeDetail');
  const mergeError=qs('#lvTripMergeError');
  const renderMergeSuggestion=(data)=>{
    activeMergeSuggestion=data?.merge_suggestion||null;
    if(mergeToggle){
      const possible=!!activeMergeSuggestion;
      mergeToggle.hidden=!possible;
      if(possible){mergeToggle.textContent='🔗 Letzte Fahrt fortsetzen?';}
    }
  };
  mergeToggle?.addEventListener('click',()=>{
    if(!activeMergeSuggestion||!mergeDialog)return;
    if(mergeError)mergeError.hidden=true;
    if(mergeDetail)mergeDetail.textContent='Die letzte Fahrt endete vor '+activeMergeSuggestion.gap_minutes+' Minuten, etwa '+activeMergeSuggestion.distance_meters+' m vom jetzigen Start entfernt. Die rote Linie wird in dieser LiveView fortgesetzt. Nach Fahrtende kannst du im Backend aus beiden Fahrten eine machen.';
    mergeDialog.showModal();
  });
  qs('#lvTripMergeCancel')?.addEventListener('click',()=>mergeDialog?.close());
  qs('#lvTripMergeConfirm')?.addEventListener('click',async()=>{
    if(!activeMergeSuggestion)return;
    const button=qs('#lvTripMergeConfirm');
    if(button)button.disabled=true;
    if(mergeError)mergeError.hidden=true;
    const storedKey=String(currentData?.vehicle?.id||0)+':'+String(activeMergeSuggestion.current_trip_id)+':'+String(activeMergeSuggestion.previous_trip_id);
    storageSet(continuationStorage,storedKey);
    mergeDialog?.close();
    updateMap(currentData,false);
    showToast('Rote Route fortgesetzt. Beide Fahrten kannst du spaeter im Backend zusammenfuehren.',3500);
    if(button)button.disabled=false;
  });

  // Four verified bundled Tesla cutouts; model selection is telemetry-derived,
  // not guessed from a custom nickname such as "Hotte Y".
  const normalizeTeslaModel=(input) => {
    const raw=String(input||'').trim().toLowerCase().replace(/[\-_]/g,' ').replace(/\s+/g,' ');
    // Require a model identifier at the START, not any nickname ending in Y.
    const match=raw.match(/^(?:tesla\s+)?(?:model\s*)?(3|y|s|x)(?:\s|$)/);
    if(match) return match[1];
    const short=raw.match(/^m([3ysx])$/);
    if(short) return short[1];
    return null;
  };
  const carAssets=Object.freeze({
    '3':'assets/vehicles/model-3-white.avif',
    y:'assets/vehicles/model-y-white.avif',
    s:'assets/vehicles/model-s-white.avif',
    x:'assets/vehicles/model-x-white.avif'
  });
  const carModeLabels=Object.freeze({
    driving:'Unterwegs', charging:'Lädt', parked:'Geparkt',
    sleeping:'Schläft', stale:'Letzte Daten'
  });
  const carMotionKey='trakfog-live-car-motion';
  let carMotion=storageGet(carMotionKey,'subtle')==='off'?'off':'subtle';
  const scene=qs('#lvCarScene');
  const sceneImage=qs('#lvCarSceneImage');
  const sceneFallback=qs('#lvCarSceneFallback');
  const motionToggle=qs('#lvCarMotionToggle');
  const setCarMotion=(value)=>{
    carMotion=value==='off'?'off':'subtle';
    storageSet(carMotionKey,carMotion);
    if(scene) scene.dataset.motion=carMotion;
    if(motionToggle){
      motionToggle.textContent=carMotion==='off'?'Effekte · AUS':'Dezent · AN';
      motionToggle.setAttribute('aria-pressed',String(carMotion!=='off'));
    }
  };
  motionToggle?.addEventListener('click',()=>setCarMotion(carMotion==='off'?'subtle':'off'));
  if(sceneImage){
    sceneImage.addEventListener('error',()=>{
      sceneImage.hidden=true;
      if(sceneFallback)sceneFallback.hidden=false;
      if(scene)scene.dataset.image='missing';
    });
    sceneImage.addEventListener('load',()=>{
      sceneImage.hidden=false;
      if(sceneFallback)sceneFallback.hidden=true;
      if(scene)scene.dataset.image='ready';
    });
  }
  setCarMotion(carMotion);

  const updateCarScene=(data)=>{
    if(!scene)return;
    const vehicle=data?.vehicle||null;
    const model=normalizeTeslaModel(vehicle?.model) || normalizeTeslaModel(data?.tesla_nerd?.model_name);
    const visual=vehicle?.visual || null;
    const mode=vehicle?.mode || 'stale';
    // Server resolves both VIN model-year uncertainty and persisted manual choice.
    // Only fixed, bundled filenames are accepted, never arbitrary remote URLs.
    const allowedAssets=new Set([...Object.values(carAssets),
      'assets/vehicles/model-y-classic.avif','assets/vehicles/model-3-classic.avif']);
    const asset=visual && allowedAssets.has(visual.asset) ? visual.asset
      : visual ? null : (model?carAssets[model]:null);
    scene.dataset.mode=mode;
    scene.dataset.model=model||'unknown';
    scene.dataset.vehicleId=String(vehicle?.id||'');
    setText('#lvCarSceneName',vehicle?.name || 'Tesla');
    setText('#lvCarSceneModel',visual?.label
      || (visual?.needs_selection?'Generation auswählen':(model?'MODEL '+model.toUpperCase():'TESLA')));
    setText('#lvCarSceneState',carModeLabels[mode] || 'Status unbekannt');
    // No false Model Y illustration for unrecognized or unconfigured cars.
    if(!asset){
      if(sceneImage)sceneImage.hidden=true;
      if(sceneFallback)sceneFallback.hidden=false;
      scene.dataset.image='missing';
      return;
    }
    if(sceneImage && sceneImage.dataset.asset!==asset){
      scene.dataset.image='loading';
      sceneImage.hidden=true;
      if(sceneFallback)sceneFallback.hidden=true;
      sceneImage.dataset.asset=asset;
      sceneImage.src=asset+'?v='+encodeURIComponent(root.dataset.version || '0');
      if(sceneImage.complete && sceneImage.naturalWidth>0){
        sceneImage.hidden=false;
        scene.dataset.image='ready';
      }
    } else if(sceneImage && sceneImage.complete && sceneImage.naturalWidth>0){
      sceneImage.hidden=false;
      if(sceneFallback)sceneFallback.hidden=true;
      scene.dataset.image='ready';
    }
    if(sceneImage) sceneImage.alt=visual?.label || 'Tesla Model '+String(model||'').toUpperCase();
  };

  const updateData=(data) => {
    currentData=data;
    renderMergeSuggestion(data);
    updateVehicles(data);
    updateMainPage(data);
    updateCarScene(data);
    updateJourneyPage(data);
    updateNerdPage(data);
    if(page===1 || map) updateMap(data,false);
  };

  const fetchData=async(force=false) => {
    if(fetching && !force) return;
    fetching=true;
    try {
      const query=selectedVehicleId>0?'?vehicle_id='+encodeURIComponent(selectedVehicleId):'';
      const response=await fetch(api+query,{
        credentials:'same-origin',
        cache:'no-store',
        headers:{'Accept':'application/json'}
      });
      if(response.status===401){
        location.reload();
        return;
      }
      if(!response.ok) throw new Error('HTTP '+response.status);
      const data=await response.json();
      if(!data.ok) throw new Error(data.error || 'Live data failed');
      updateData(data);
    } catch (error) {
      setText('#lvTopState','Verbindung prüfen');
      showToast('Live-Daten konnten gerade nicht aktualisiert werden.',1800);
    } finally {
      fetching=false;
    }
  };

  const updateClock=() => {
    setText('#lvClock',new Date().toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'}));
  };
  updateClock();
  setInterval(updateClock,1000);

  const infoDrawer=qs('#lvInfoDrawer');
  qs('#lvInfoToggle')?.addEventListener('click',()=>{
    if(!infoDrawer) return;
    infoDrawer.hidden=!infoDrawer.hidden;
    updateDiagnostics();
  });
  qs('#lvInfoClose')?.addEventListener('click',()=>{ if(infoDrawer) infoDrawer.hidden=true; });

  window.addEventListener('resize',()=>{
    updateDiagnostics();
    renderPagePosition(0,false);
    requestAnimationFrame(()=>map?.invalidateSize());
  });
  window.visualViewport?.addEventListener('resize',updateDiagnostics);

  updateDiagnostics();
  setPage(page,false);
  fetchData(true);
  pollTimer=setInterval(()=>fetchData(false),3000);

  document.addEventListener('visibilitychange',()=>{
    if(document.visibilityState==='visible') fetchData(true);
  });
})();
