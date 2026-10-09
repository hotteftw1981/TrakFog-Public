(() => {
  const root = document.documentElement;

  const setTheme = (theme) => {
    const next = theme === 'light' ? 'light' : 'dark';
    root.dataset.theme = next;
    root.style.colorScheme = next;
    localStorage.setItem('trakfog-theme', next);
  };

  const toggleTheme = () => setTheme(root.dataset.theme === 'dark' ? 'light' : 'dark');

  const themeToggle = document.getElementById('themeToggle');
  const authTheme = document.getElementById('authTheme');
  if (themeToggle) themeToggle.addEventListener('click', toggleTheme);
  if (authTheme) authTheme.addEventListener('click', toggleTheme);

  const body = document.body;
  const open = document.getElementById('mobileNavOpen');
  const close = document.getElementById('mobileNavClose');
  const backdrop = document.getElementById('mobileNavBackdrop');

  const setMobileNav = (visible) => {
    if (!open || !backdrop) return;
    body.classList.toggle('mobile-nav-open', visible);
    open.setAttribute('aria-expanded', visible ? 'true' : 'false');
    backdrop.hidden = !visible;
  };

  if (open) open.addEventListener('click', () => setMobileNav(true));
  if (close) close.addEventListener('click', () => setMobileNav(false));
  if (backdrop) backdrop.addEventListener('click', () => setMobileNav(false));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      setMobileNav(false);
      if (infoModal && !infoModal.hidden) setInfoModal(false);
    }
  });
  document.querySelectorAll('.side-nav a').forEach((link) => {
    link.addEventListener('click', () => setMobileNav(false));
  });

  const infoToggle = document.getElementById('infoToggle');
  const infoModal = document.getElementById('infoModal');
  const infoClose = infoModal?.querySelector('[data-info-modal-close]') || null;

  const setInfoModal = (visible) => {
    if (!infoModal) return;
    infoModal.hidden = !visible;
    infoToggle?.setAttribute('aria-expanded', visible ? 'true' : 'false');
    body.classList.toggle('modal-open', visible);
    if (visible) {
      window.setTimeout(() => infoClose?.focus(), 0);
    } else {
      infoToggle?.focus();
    }
  };

  if (infoToggle) {
    infoToggle.setAttribute('aria-expanded', 'false');
    infoToggle.addEventListener('click', () => {
      setMobileNav(false);
      setInfoModal(true);
    });
  }
  if (infoClose) infoClose.addEventListener('click', () => setInfoModal(false));

  const passwordToggle = document.getElementById('passwordToggle');
  const password = document.getElementById('loginPassword');
  if (passwordToggle && password) {
    passwordToggle.addEventListener('click', () => {
      const show = password.type === 'password';
      password.type = show ? 'text' : 'password';
      passwordToggle.textContent = show ? 'Ausblenden' : 'Anzeigen';
      passwordToggle.setAttribute('aria-label', show ? 'Passwort ausblenden' : 'Passwort anzeigen');
    });
  }

  window.TrakFog = {
    toast(message, type = 'ok') {
      const el = document.createElement('div');
      el.className = `tf-toast ${type}`;
      el.textContent = message;
      Object.assign(el.style, {
        position: 'fixed',
        right: '18px',
        bottom: '18px',
        zIndex: 9999,
        padding: '12px 15px',
        borderRadius: '10px',
        border: '1px solid var(--line)',
        boxShadow: '0 18px 50px rgba(0,0,0,.24)'
      });
      document.body.appendChild(el);
      setTimeout(() => el.remove(), 3200);
    },
    async post(url, payload) {
      const res = await fetch(url, {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      });
      const data = await res.json().catch(() => ({ok:false,error:'Ungültige Serverantwort'}));
      if (!res.ok || data.ok === false) throw new Error(data.error || 'Anfrage fehlgeschlagen');
      return data;
    }
  };
})();


(() => {
  const pollRoot = document.querySelector('[data-live-poll]');
  if (!pollRoot) return;

  const configured = Number.parseInt(pollRoot.dataset.livePoll || '5000', 10);
  const interval = Number.isFinite(configured) ? Math.max(2500, configured) : 5000;
  let timer = null;
  let busy = false;

  const schedule = (delay = interval) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(refreshLiveRegions, delay);
  };

  const refreshLiveRegions = async () => {
    if (busy || document.hidden) {
      schedule();
      return;
    }

    busy = true;
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('_live', Date.now().toString());

      const response = await fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'text/html',
          'X-TrakFog-Live': '1'
        }
      });

      if (response.redirected && response.url.includes('login.php')) {
        window.location.assign(response.url);
        return;
      }
      if (!response.ok) throw new Error('Live refresh failed');

      const html = await response.text();
      const freshDocument = new DOMParser().parseFromString(html, 'text/html');

      document.querySelectorAll('[data-live-region]').forEach((current) => {
        const key = current.getAttribute('data-live-region');
        if (!key) return;

        const fresh = Array.from(freshDocument.querySelectorAll('[data-live-region]'))
          .find((node) => node.getAttribute('data-live-region') === key);
        if (!fresh) return;

        const active = document.activeElement;
        if (active && active !== document.body && current.contains(active)) return;

        if (current.className !== fresh.className) current.className = fresh.className;
        if (current.innerHTML !== fresh.innerHTML) current.innerHTML = fresh.innerHTML;
      });

      document.dispatchEvent(new CustomEvent('trakfog:live-updated'));
      document.documentElement.dataset.liveUi = 'ok';
    } catch (_) {
      document.documentElement.dataset.liveUi = 'retry';
    } finally {
      busy = false;
      schedule();
    }
  };

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) schedule(250);
  });

  schedule(1200);
})();

(() => {
  const selectedKey = 'trakfog-dashboard-selected-vehicle';
  const favoriteKey = 'trakfog-dashboard-favorite-vehicle';
  const toneClasses = ['vehicle-tone-black','vehicle-tone-white','vehicle-tone-red','vehicle-tone-blue','vehicle-tone-silver'];
  const modeClasses = ['mode-charging','mode-driving','mode-sleeping','mode-online','mode-offline'];

  const readStorage = (key) => {
    try { return localStorage.getItem(key) || ''; } catch (_) { return ''; }
  };
  const writeStorage = (key, value) => {
    try { localStorage.setItem(key, value); } catch (_) {}
  };
  const num = (value) => {
    const parsed = Number.parseFloat(value || '');
    return Number.isFinite(parsed) ? parsed : null;
  };
  const format = (value, suffix = '', digits = 0) => {
    if (value === null) return '–';
    return value.toLocaleString('de-DE', {minimumFractionDigits:digits, maximumFractionDigits:digits}) + suffix;
  };

  const setText = (root, selector, value) => {
    const node = root.querySelector(selector);
    if (node) node.textContent = value;
  };

  const applyTone = (node, tone) => {
    if (!node) return;
    node.classList.remove(...toneClasses);
    node.classList.add('vehicle-tone-' + (tone || 'black'));
  };

  const copyForMode = (mode) => {
    switch (mode) {
      case 'charging':
        return {
          verb:'lädt gerade.',
          lead:'Das Fahrzeug lädt. TrakFog bündelt SoC, Reichweite, Ladelimit und die wichtigsten Live-Daten an einer Stelle.',
          flow:'Aktiv im Hintergrund',
          note:'Ladevorgang aktiv.'
        };
      case 'driving':
        return {
          verb:'ist unterwegs.',
          lead:'Das Fahrzeug ist unterwegs. Die Bühne wird dynamischer und zeigt den aktuell aktiven Fahrzustand.',
          flow:'Live-Daten aktiv',
          note:'Fahrt läuft.'
        };
      case 'sleeping':
        return {
          verb:'schläft aktuell.',
          lead:'Das Fahrzeug ruht. Die Szene bleibt bewusst dunkel und ruhig, bis neue Fahrzeugdaten eintreffen.',
          flow:'Ruhemodus',
          note:'Alles in Ruhe.'
        };
      case 'online':
        return {
          verb:'ist wach.',
          lead:'Das Fahrzeug ist wach und bereit. Aktuelle Werte stehen für LiveView und Auswertungen bereit.',
          flow:'Aktiv im Hintergrund',
          note:'Fahrzeug ist bereit.'
        };
      default:
        return {
          verb:'ist offline.',
          lead:'Aktuell kommen keine frischen Fahrzeugdaten. Der letzte bekannte Stand bleibt sichtbar.',
          flow:'wartet auf Daten',
          note:'Letzter Stand.'
        };
    }
  };

  const selectVehicle = (root, id, persist = true) => {
    const cards = Array.from(root.querySelectorAll('[data-dashboard-vehicle-select]'));
    if (!cards.length) return;

    const card = cards.find((item) => item.dataset.vehicleId === String(id)) || cards[0];
    const hero = root.querySelector('[data-dashboard-hero]');
    if (!hero) return;

    cards.forEach((item) => {
      const selected = item === card;
      item.classList.toggle('is-selected', selected);
      item.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });

    if (persist) writeStorage(selectedKey, card.dataset.vehicleId || '');

    const mode = card.dataset.mode || 'offline';
    const tone = card.dataset.tone || 'black';
    const soc = num(card.dataset.soc);
    const range = num(card.dataset.range);
    const target = num(card.dataset.target);
    const power = num(card.dataset.power);
    const tripDistance = num(card.dataset.tripDistance);
    const temperature = num(card.dataset.temperature);
    const locked = card.dataset.locked;
    const copy = copyForMode(mode);

    hero.classList.remove(...modeClasses);
    hero.classList.add('mode-' + mode);

    const primaryCar = hero.querySelector('[data-primary-car]');
    applyTone(primaryCar, tone);
    const primaryImage = primaryCar?.querySelector('.dashboard-car-image');
    if (primaryImage && card.dataset.carAsset && primaryImage.getAttribute('src') !== card.dataset.carAsset) {
      primaryImage.setAttribute('src', card.dataset.carAsset);
    }

    setText(hero, '[data-hero-name]', card.dataset.name || 'Tesla');
    setText(hero, '[data-hero-verb]', copy.verb);
    setText(hero, '[data-hero-lead]', copy.lead);
    setText(hero, '[data-hero-flow]', copy.flow);
    setText(hero, '[data-hero-kicker]', card.classList.contains('is-favorite') ? 'Favoriten-Fahrzeug' : 'Ausgewähltes Fahrzeug');

    // Update one trusted SVG symbol, not an emoji or arbitrary user HTML.
    const iconUse=hero.querySelector('[data-hero-icon-d] use');
    const selectHeroIcon=(id)=>{
      if(iconUse) iconUse.setAttribute('href','#ds-icon-'+id);
    };
    setText(hero, '[data-hero-metric-a]', format(soc, ' %', 0));
    setText(hero, '[data-hero-label-a]', 'Batteriestand');
    setText(hero, '[data-hero-metric-b]', format(range, ' km', 0));
    setText(hero, '[data-hero-label-b]', 'Geschätzte Reichweite');

    if (mode === 'charging') {
      selectHeroIcon('bolt');
      setText(hero, '[data-hero-metric-c]', target !== null ? format(target, ' %', 0) : '–');
      setText(hero, '[data-hero-label-c]', 'Ladelimit');
      setText(hero, '[data-hero-metric-d]', power !== null ? format(power, ' kW', 1) : (card.dataset.statusDetail || 'aktiv'));
      setText(hero, '[data-hero-label-d]', power !== null ? 'Aktuelle Ladeleistung' : 'Ladestatus');
    } else if (mode === 'driving') {
      selectHeroIcon('gauge');
      setText(hero, '[data-hero-metric-c]', tripDistance !== null ? format(tripDistance, ' km', 1) : 'Live');
      setText(hero, '[data-hero-label-c]', 'Aktuelle Fahrt');
      setText(hero, '[data-hero-metric-d]', card.dataset.statusDetail || 'unterwegs');
      setText(hero, '[data-hero-label-d]', 'Fahrstatus');
    } else if (mode === 'sleeping') {
      selectHeroIcon('clock');
      setText(hero, '[data-hero-metric-c]', 'Schläft');
      setText(hero, '[data-hero-label-c]', 'Fahrzeugstatus');
      setText(hero, '[data-hero-metric-d]', card.dataset.sleepAge || card.dataset.statusDetail || '–');
      setText(hero, '[data-hero-label-d]', 'Ruhezeit');
    } else {
      selectHeroIcon(mode === 'online' ? 'lock' : 'moon');
      setText(hero, '[data-hero-metric-c]', card.dataset.status || '–');
      setText(hero, '[data-hero-label-c]', 'Fahrzeugstatus');
      setText(hero, '[data-hero-metric-d]', locked === '1' ? 'Verschlossen' : (locked === '0' ? 'Entriegelt' : (card.dataset.statusDetail || '–')));
      setText(hero, '[data-hero-label-d]', locked ? 'Fahrzeugsicherung' : 'Statusdetail');
    }

    setText(hero, '[data-hero-metric-e]', temperature !== null ? format(temperature, ' °C', 1) : '–');
    setText(hero, '[data-hero-label-e]', 'Außentemperatur');
    setText(hero, '[data-hero-metric-f]', card.dataset.updated || '–');
    setText(hero, '[data-hero-label-f]', 'Letzte Daten');

    const details = hero.querySelector('[data-hero-details]');
    if (details) details.href = 'vehicle.php?id=' + encodeURIComponent(card.dataset.vehicleId || '');

    const flowStatuses = hero.querySelectorAll('[data-flow-status]');
    flowStatuses.forEach((node) => {
      node.textContent = mode === 'offline' ? '–' : (mode === 'sleeping' ? 'Ruhe' : 'OK');
    });

    // Keep the favorite in front. Show additional cars when several vehicles
    // charge; otherwise allow nearby fleet cars to share the stage quietly.
    const secondaryCandidates = cards
      .filter((item) => item !== card)
      .sort((a, b) => Number(b.dataset.mode === 'charging') - Number(a.dataset.mode === 'charging'))
      .slice(0, 6);
    const secondarySlots = Array.from(hero.querySelectorAll('[data-secondary-car]'));
    secondarySlots.forEach((slot, index) => {
      const other = secondaryCandidates[index];
      slot.hidden = !other;
      if (other) {
        applyTone(slot, other.dataset.tone || 'white');
        const secondaryImage = slot.querySelector('.dashboard-car-image');
        if (secondaryImage && other.dataset.carAsset && secondaryImage.getAttribute('src') !== other.dataset.carAsset) {
          secondaryImage.setAttribute('src', other.dataset.carAsset);
        }
        slot.dataset.mode = other.dataset.mode || 'offline';
        slot.title = other.dataset.name || '';
        slot.style.opacity = other.dataset.mode === 'charging' ? '.88' : '.42';
      }
    });

    const noteTitle = hero.querySelector('[data-hero-note-title]');
    const noteCopy = hero.querySelector('[data-hero-note-copy]');
    if (mode === 'charging' && secondaryCandidates.some(item => item.dataset.mode === 'charging')) {
      const total = secondaryCandidates.filter(item => item.dataset.mode === 'charging').length + 1;
      if (noteTitle) noteTitle.textContent = total === 2 ? 'Zwei Fahrzeuge laden.' : total + ' Fahrzeuge laden.';
      if (noteCopy) noteCopy.textContent = 'Das Lieblings-/Auswahlfahrzeug bleibt vorne, weitere aktive Fahrzeuge stehen dezent dahinter.';
    } else {
      if (noteTitle) noteTitle.textContent = copy.note;
      if (noteCopy) {
        noteCopy.textContent = mode === 'sleeping'
          ? 'Wenig Bewegung, gedimmter Glow und der letzte bekannte Stand.'
          : mode === 'driving'
            ? 'Datenlinien und Bewegung reagieren auf den aktiven Fahrzustand.'
            : mode === 'charging'
              ? 'Energiefluss und Ladeimpuls reagieren auf den laufenden Ladevorgang.'
              : 'TrakFog zeigt hier immer das ausgewählte Fahrzeug.';
      }
    }

    if (persist) card.scrollIntoView({block:'nearest', inline:'nearest', behavior:'smooth'});
  };

  const initDashboard = () => {
    const root = document.querySelector('[data-dashboard-root]');
    if (!root) return;

    const cards = Array.from(root.querySelectorAll('[data-dashboard-vehicle-select]'));
    if (!cards.length) return;

    let favorite = readStorage(favoriteKey);
    if (!cards.some((card) => card.dataset.vehicleId === favorite)) {
      favorite = cards[0].dataset.vehicleId || '';
      writeStorage(favoriteKey, favorite);
    }

    cards.forEach((card) => {
      const isFavorite = card.dataset.vehicleId === favorite;
      card.classList.toggle('is-favorite', isFavorite);
      const star = card.querySelector('[data-dashboard-favorite]');
      if (star) {
        star.setAttribute('aria-pressed', isFavorite ? 'true' : 'false');
        star.setAttribute('aria-label', isFavorite ? 'Lieblingsfahrzeug' : 'Als Lieblingsfahrzeug markieren');
      }
    });

    let selected = readStorage(selectedKey);
    if (!cards.some((card) => card.dataset.vehicleId === selected)) selected = favorite;
    selectVehicle(root, selected, false);
  };

  document.addEventListener('click', (event) => {
    const favoriteButton = event.target.closest('[data-dashboard-favorite]');
    if (favoriteButton) {
      event.preventDefault();
      event.stopPropagation();
      const card = favoriteButton.closest('[data-dashboard-vehicle-select]');
      const root = favoriteButton.closest('[data-dashboard-root]');
      if (!card || !root) return;
      const id = card.dataset.vehicleId || '';
      writeStorage(favoriteKey, id);
      root.querySelectorAll('[data-dashboard-vehicle-select]').forEach((item) => {
        item.classList.toggle('is-favorite', item.dataset.vehicleId === id);
      });
      selectVehicle(root, id, true);
      initDashboard();
      return;
    }

    const card = event.target.closest('[data-dashboard-vehicle-select]');
    if (card) {
      if (event.target.closest('a,button')) return;
      const root = card.closest('[data-dashboard-root]');
      if (root) selectVehicle(root, card.dataset.vehicleId || '', true);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (!['Enter',' '].includes(event.key)) return;
    const card = event.target.closest?.('[data-dashboard-vehicle-select]');
    if (!card || event.target.closest('[data-dashboard-favorite]')) return;
    event.preventDefault();
    const root = card.closest('[data-dashboard-root]');
    if (root) selectVehicle(root, card.dataset.vehicleId || '', true);
  });

  document.addEventListener('trakfog:live-updated', initDashboard);
  initDashboard();
})();

