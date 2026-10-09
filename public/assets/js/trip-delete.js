/* Admin-only trip deletion modal. Never deletes without POST, CSRF and checkbox. */
(() => {
  const dialog = document.getElementById('tripDeleteDialog');
  if (!dialog) return;
  const form = document.getElementById('tripDeleteForm');
  const input = document.getElementById('tripDeleteId');
  const target = document.getElementById('tripDeleteTarget');
  const acknowledge = document.getElementById('tripDeleteAcknowledge');
  const confirm = document.getElementById('tripDeleteConfirm');
  const cancel = document.getElementById('tripDeleteCancel');
  if (!form || !input || !acknowledge || !confirm || !cancel || !target) return;

  document.querySelectorAll('[data-trip-delete-id]').forEach(button => {
    button.addEventListener('click', () => {
      const id = Number(button.dataset.tripDeleteId);
      if (!Number.isSafeInteger(id) || id <= 0) return;
      form.reset();
      input.value = String(id);
      confirm.disabled = true;
      target.textContent = button.dataset.tripDeleteLabel || `Fahrt #${id}`;
      dialog.showModal();
      acknowledge.focus();
    });
  });
  acknowledge.addEventListener('change', () => {
    confirm.disabled = !acknowledge.checked;
  });
  cancel.addEventListener('click', () => dialog.close());
  form.addEventListener('submit', event => {
    if (!acknowledge.checked || !Number(input.value)) event.preventDefault();
  });
})();
