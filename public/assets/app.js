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
