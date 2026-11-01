'use strict';
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  const message = form.dataset.confirm;
  if (message && !window.confirm(message)) {
    event.preventDefault();
    return;
  }
  const submit = event.submitter;
  if (submit instanceof HTMLButtonElement && !form.dataset.submitting) {
    form.dataset.submitting = 'true';
    submit.disabled = true;
    submit.setAttribute('aria-busy', 'true');
    // A disabled submit button no longer submits its own name/value; every
    // POST action is represented by a separate hidden input.
  }
});

document.querySelectorAll('[data-table-search]').forEach((input) => {
  if (!(input instanceof HTMLInputElement)) return;
  const root = document.getElementById(input.dataset.tableSearch || '');
  if (!root) return;
  const rows = Array.from(root.querySelectorAll('tbody tr'));
  const empty = document.getElementById('zone-search-empty');
  input.addEventListener('input', () => {
    const term = input.value.trim().toLocaleLowerCase();
    let shown = 0;
    for (const row of rows) {
      const matches = (row.textContent || '').toLocaleLowerCase().includes(term);
      row.hidden = !matches;
      if (matches) shown++;
    }
    if (empty) empty.classList.toggle('hidden', shown > 0);
  });
});
