/* TrakFog Living Garage V0.1.1.59 — browser-only effects; no Tesla API requests. */
(() => {
  'use strict';
  const storageKey = 'trakfog-living-garage-motion';
  const choices = ['off','subtle','full'];
  const metricSelector = '[data-hero-metric-a],[data-hero-metric-b],[data-hero-metric-c],[data-hero-metric-d],[data-hero-metric-e],[data-hero-metric-f]';
  let lastSnapshot = null;
  let pulseTimer = 0;
  const getMotion = () => {
    try {
      const value = localStorage.getItem(storageKey);
      return choices.includes(value) ? value : 'subtle';
    } catch (_) { return 'subtle'; }
  };
  const setMotion = (value) => {
    if (!choices.includes(value)) return;
    try { localStorage.setItem(storageKey, value); } catch (_) {}
    const hero = document.querySelector('[data-dashboard-hero]');
    if (!hero) return;
    hero.dataset.lgMotion = value;
    hero.querySelectorAll('[data-lg-motion]').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.lgMotion === value));
    });
  };
  const readSnapshot = () => {
    const card = document.querySelector('[data-dashboard-vehicle-select].is-selected');
    if (!card) return null;
    const data = card.dataset;
    return {
      id: data.vehicleId || '', mode: data.mode || 'offline',
      soc: data.soc || '', range: data.range || '',
      power: data.power || '', temp: data.temperature || '',
      updated: data.updated || '', status: data.status || ''
    };
  };
  const changedKeys = (before, after) => {
    if (!before || !after || before.id !== after.id) return [];
    return ['mode','soc','range','power','temp','updated','status'].filter((key) => before[key] !== after[key]);
  };
  const animateChanged = (keys) => {
    const hero = document.querySelector('[data-dashboard-hero]');
    if (!hero || !keys.length || hero.dataset.lgMotion === 'off') return;
    window.clearTimeout(pulseTimer);
    hero.classList.remove('lg-data-received');
    void hero.offsetWidth;
    hero.classList.add('lg-data-received');
    const fields = {
      soc:['a'], range:['b'], power:['d'], temp:['e'],
      mode:['c','d'], status:['c'], updated:['f']
    };
    const names = new Set(keys.flatMap((key) => fields[key] || []));
    hero.querySelectorAll(metricSelector).forEach((node) => {
      const letter = node.getAttributeNames().find((name) => name.startsWith('data-hero-metric-'))?.slice(-1);
      if (names.has(letter)) {
        node.classList.remove('lg-metric-changed');
        void node.offsetWidth;
        node.classList.add('lg-metric-changed');
      }
    });
    pulseTimer = window.setTimeout(() => {
      hero.classList.remove('lg-data-received');
      hero.querySelectorAll('.lg-metric-changed').forEach((node) => node.classList.remove('lg-metric-changed'));
    }, 1200);
  };
  const refresh = () => {
    const hero = document.querySelector('[data-dashboard-hero]');
    if (!hero) return;
    hero.dataset.lgMotion = getMotion();
    hero.querySelectorAll('[data-lg-motion]').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.lgMotion === hero.dataset.lgMotion));
    });
    const current = readSnapshot();
    if (current) {
      const changes = changedKeys(lastSnapshot, current);
      if (changes.length) animateChanged(changes);
      lastSnapshot = current;
    }
  };
  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-lg-motion]');
    if (button) { event.preventDefault(); setMotion(button.dataset.lgMotion); return; }
    if (event.target.closest('[data-dashboard-vehicle-select]')) {
      window.requestAnimationFrame(() => { lastSnapshot = readSnapshot(); refresh(); });
    }
  });
  document.addEventListener('keydown', (event) => {
    if ((event.key === 'Enter' || event.key === ' ') && event.target.closest('[data-dashboard-vehicle-select]')) {
      window.requestAnimationFrame(() => { lastSnapshot = readSnapshot(); refresh(); });
    }
  });
  document.addEventListener('trakfog:live-updated', refresh);
  refresh();
})();
