/* TrakFog V0.1.1.63: understandable, persistent trip merge flow. */
(() => {
  'use strict';
  const summarize = (choices) => {
    const selected = Array.from(choices).filter((choice) => choice.checked);
    const cars = new Set(selected.map((choice) => choice.dataset.car));
    const ordered = selected.slice().sort((a,b) => String(a.dataset.time).localeCompare(String(b.dataset.time)));
    const sameCar = cars.size <= 1;
    const finished = selected.every((choice) => choice.dataset.ended !== '0');
    const timeline = Array.from(choices).filter((choice) => choice.dataset.car === selected[0]?.dataset.car)
      .sort((a,b) => String(a.dataset.time).localeCompare(String(b.dataset.time)));
    const indexes = selected.map((choice) => timeline.indexOf(choice)).sort((a,b) => a-b);
    const consecutive = indexes.length < 2 || (indexes[0] >= 0 &&
      indexes[indexes.length - 1] - indexes[0] + 1 === indexes.length);
    return { count: selected.length, sameCar, consecutive, finished,
      ready: selected.length >= 2 && sameCar && consecutive && finished,
      start: ordered[0]?.dataset.from || '',
      end: ordered[ordered.length - 1]?.dataset.to || '',
      vehicle: ordered[0]?.dataset.carLabel || '' };
  };
  if (typeof module !== 'undefined' && module.exports) module.exports = { summarize };
  if (typeof document === 'undefined') return;
  const form = document.getElementById('tripMergeForm');
  const dialog = document.getElementById('tripMergeDialog');
  if (!form || !dialog) return;
  const checks = [...form.querySelectorAll('[data-trip-merge-choice]')];
  const bar = document.getElementById('tripMergeSelectedBar');
  const count = document.getElementById('tripMergeSelectedCount');
  const hint = document.getElementById('tripMergeSelectedHint');
  const open = document.getElementById('tripMergeOpen');
  const reset = document.getElementById('tripMergeReset');
  const save = document.getElementById('tripMergeSave');
  const update = () => {
    const info = summarize(checks);
    bar.hidden = info.count === 0;
    count.textContent = info.count + ' ' + (info.count === 1 ? 'Fahrt ausgewählt' : 'Fahrten ausgewählt');
    hint.textContent = !info.sameCar ? 'Bitte nur Fahrten desselben Teslas markieren.' :
      info.count < 2 ? 'Mindestens eine weitere Fahrt ausw\u00e4hlen.' :
      !info.consecutive ? 'Bitte auch alle dazwischenliegenden Fahrten markieren.' :
      !info.finished ? 'Nur abgeschlossene Fahrten zusammenf\u00fchren.' :
      'Jetzt aus den markierten Fahrten eine Fahrt machen.';
    open.disabled = !info.ready;
    return info;
  };
  checks.forEach((box) => box.addEventListener('change', update));
  document.querySelectorAll('[data-quick-trip-merge]').forEach((button) => button.addEventListener('click', () => {
    const ids = button.dataset.quickTripMerge.split(',');
    checks.forEach((box) => { box.checked = ids.includes(box.value); });
    if (update().ready) open.click();
  }));
  reset?.addEventListener('click', () => {
    checks.forEach((box) => { box.checked = false; });
    update();
  });
  open?.addEventListener('click', () => {
    const info = update();
    if (!info.ready) return;
    document.getElementById('mergeDialogSummary').textContent = info.count +
      ' Fahrten von ' + info.vehicle + ' werden zu genau einer Fahrt zusammengef\u00fchrt.';
    document.getElementById('tripMergePreviewStart').textContent = info.start || 'Unbekannter Start';
    document.getElementById('tripMergePreviewEnd').textContent = info.end || 'Unbekanntes Ziel';
    document.getElementById('tripMergePreviewInfo').textContent = info.count +
      ' Fahrten · ' + (info.count - 1) + ' ' +
      (info.count === 2 ? 'Zwischenstopp' : 'Zwischenstopps');
    dialog.showModal();
  });
  const close = () => dialog.close();
  document.getElementById('tripMergeCancel')?.addEventListener('click', close);
  document.getElementById('tripMergeClose')?.addEventListener('click', close);
  form.addEventListener('submit', (event) => {
    if (!summarize(checks).ready) { event.preventDefault(); update(); return; }
    if (save) { save.disabled = true; save.textContent = 'Fahrten werden verbunden...'; }
  });
  update();
})();


/* V0.1.1.65: accessible expansion independent of merge/admin rights. */
(() => {
  'use strict';
  if (typeof document === 'undefined') return;
  document.querySelectorAll('[data-trip-toggle]').forEach((toggle) => {
    const panel = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!panel) return;
    toggle.addEventListener('click', () => {
      const expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!expanded));
      toggle.title = expanded ? 'Fahrtdetails anzeigen' : 'Fahrtdetails verbergen';
      panel.hidden = expanded;
    });
  });
})();
