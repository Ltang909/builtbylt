const dialog = document.querySelector('#log-dialog');
const form = document.querySelector('#log-form');
const status = document.querySelector('#form-status');

document.querySelectorAll('[data-open-log]').forEach((button) => button.addEventListener('click', () => {
  if (button.dataset.project) form.elements.project.value = button.dataset.project;
  dialog.showModal();
  setTimeout(() => form.elements.title.focus(), 50);
}));
document.querySelector('[data-close-log]')?.addEventListener('click', () => dialog.close());
dialog?.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  status.textContent = 'Writing…';
  const button = form.querySelector('[type="submit"]');
  button.disabled = true;
  try {
    const response = await fetch('/api/log.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.fromEntries(new FormData(form))) });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Could not save the entry.');
    status.textContent = 'Logged.';
    setTimeout(() => location.reload(), 350);
  } catch (error) {
    status.textContent = error.message;
    button.disabled = false;
  }
});

document.querySelectorAll('.filter').forEach((filter) => filter.addEventListener('click', () => {
  document.querySelectorAll('.filter').forEach((item) => item.classList.remove('active'));
  filter.classList.add('active');
  document.querySelectorAll('.timeline-item').forEach((item) => item.hidden = filter.dataset.filter !== 'all' && item.dataset.type !== filter.dataset.filter);
}));

const tick = () => { const clock = document.querySelector('#clock'); if (clock) clock.textContent = new Intl.DateTimeFormat('en-CA', {hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false}).format(new Date()); };
tick(); setInterval(tick, 1000);

document.querySelector('#task-form')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const response = await fetch('/api/tasks.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({...Object.fromEntries(new FormData(event.currentTarget)), action:'add'})});
  if (response.ok) location.reload();
});
document.querySelectorAll('[data-task-id]').forEach((input) => input.addEventListener('change', async () => {
  const response = await fetch('/api/tasks.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'toggle', id:input.dataset.taskId})});
  if (response.ok) location.reload();
}));

document.querySelector('#idea-form')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const button = event.currentTarget.querySelector('button');
  button.disabled = true;
  const response = await fetch('/api/ideas.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(Object.fromEntries(new FormData(event.currentTarget)))});
  if (response.ok) location.reload(); else button.disabled = false;
});

