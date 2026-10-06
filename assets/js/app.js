// Adiciona mostrar/ocultar a todos os campos de senha, inclusive aos futuros.
(() => {
  const eyeOpen = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c-5.5 0-9.5 5.5-9.5 7s4 7 9.5 7 9.5-5.5 9.5-7S17.5 5 12 5Zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm0-2a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/></svg>';
  const eyeClosed = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3.3 2 18.7 18.7-1.3 1.3-3.1-3.1A11.8 11.8 0 0 1 12 20c-5.5 0-9.5-5.5-9.5-7 0-1 .9-2.6 2.5-4.1L2 3.3 3.3 2Zm3.1 8.3C5.2 11.4 4.6 12.5 4.5 13c.5 1.2 3.4 5 7.5 5 1.4 0 2.8-.5 3.9-1.1l-1.6-1.6A4 4 0 0 1 9.7 10.7L8.2 9.2c-.7.3-1.3.7-1.8 1.1ZM12 6c5.5 0 9.5 5.5 9.5 7 0 .8-.6 2-1.8 3.3l-1.4-1.4c.7-.8 1.1-1.5 1.2-1.9-.5-1.2-3.4-5-7.5-5-.4 0-.8 0-1.2.1L9.2 6.5c.9-.3 1.8-.5 2.8-.5Zm3.9 6.7-4.6-4.6c.2 0 .5-.1.7-.1a4 4 0 0 1 4 4c0 .2 0 .5-.1.7Z"/></svg>';

  document.querySelectorAll('input[type="password"]').forEach(input => {
    if (input.parentElement?.classList.contains('password-field')) return;
    const wrapper = document.createElement('div');
    wrapper.className = 'password-field';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'password-toggle';
    button.setAttribute('aria-label', 'Mostrar senha');
    button.setAttribute('aria-pressed', 'false');
    button.title = 'Mostrar senha';
    button.innerHTML = eyeOpen;
    button.addEventListener('click', () => {
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      button.setAttribute('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha');
      button.setAttribute('aria-pressed', String(!showing));
      button.title = showing ? 'Mostrar senha' : 'Ocultar senha';
      button.innerHTML = showing ? eyeOpen : eyeClosed;
    });
    wrapper.appendChild(button);
  });
})();

document.querySelectorAll('.task-card').forEach(card => {
  card.addEventListener('dragstart', e => {
    e.dataTransfer.setData('text/plain', card.dataset.taskId);
    card.classList.add('dragging');
  });
  card.addEventListener('dragend', () => card.classList.remove('dragging'));
});

document.querySelectorAll('.dropzone').forEach(zone => {
  zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('over'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('over'));
  zone.addEventListener('drop', async e => {
    e.preventDefault(); zone.classList.remove('over');
    const taskId = e.dataTransfer.getData('text/plain');
    const card = document.querySelector(`[data-task-id="${taskId}"]`);
    if (!card) return;
    zone.appendChild(card);
    try {
      const r = await fetch('update_status.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({task_id: taskId, status: zone.dataset.status})
      });
      if (!r.ok) throw new Error();
      location.reload();
    } catch (_) {
      alert('Não foi possível atualizar o status.');
      location.reload();
    }
  });
});

(() => {
  const toast = document.querySelector('.flash-toast');
  if (!toast) return;
  const close = () => {
    if (toast.classList.contains('is-hiding')) return;
    toast.classList.add('is-hiding');
    window.setTimeout(() => toast.remove(), 260);
  };
  toast.querySelector('.flash-close')?.addEventListener('click', close);
  window.setTimeout(close, 5000);
})();

(() => {
  const menu = document.querySelector('[data-notification-menu]');
  const toggle = menu?.querySelector('[data-notification-toggle]');
  const dropdown = menu?.querySelector('[data-notification-dropdown]');
  const badge = menu?.querySelector('[data-notification-badge]');
  if (!menu || !toggle || !dropdown) return;

  const close = () => {
    dropdown.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
  };

  toggle.addEventListener('click', async event => {
    event.stopPropagation();
    const opening = dropdown.hidden;
    dropdown.hidden = !opening;
    toggle.setAttribute('aria-expanded', String(opening));
    if (!opening || !badge || badge.classList.contains('is-empty')) return;

    const data = new FormData();
    data.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
    data.append('action', 'mark_read');
    try {
      const response = await fetch('notifications.php', {method:'POST',headers:{Accept:'application/json'},body:data});
      if (!response.ok) throw new Error();
      badge.textContent = '0';
      badge.classList.add('is-empty');
      menu.querySelectorAll('.notification-preview.is-unread').forEach(item => item.classList.remove('is-unread'));
    } catch (_) {}
  });

  dropdown.addEventListener('click', event => event.stopPropagation());
  document.addEventListener('click', close);
  document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
})();

(() => {
  const column = document.querySelector('[data-kanban-column="done"]');
  const button = column?.querySelector('.collapse-completed');
  if (!column || !button) return;
  const applyState = collapsed => {
    column.classList.toggle('is-collapsed', collapsed);
    button.textContent = collapsed ? 'Mostrar' : 'Recolher';
    button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
  };
  let collapsed = false;
  try { collapsed = localStorage.getItem('tasks-done-collapsed') === '1'; } catch (_) {}
  applyState(collapsed);
  button.addEventListener('click', () => {
    collapsed = !column.classList.contains('is-collapsed');
    applyState(collapsed);
    try { localStorage.setItem('tasks-done-collapsed', collapsed ? '1' : '0'); } catch (_) {}
  });
})();

(() => {
  const modal = document.getElementById('imageModal');
  if (!modal) return;
  const img = document.getElementById('imageModalImg');
  const title = document.getElementById('imageModalTitle');
  const closeBtn = modal.querySelector('.image-modal-close');

  const closeModal = () => {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    img.src = '';
  };

  document.querySelectorAll('.js-image-preview').forEach(button => {
    button.addEventListener('click', () => {
      img.src = button.dataset.src || '';
      title.textContent = button.dataset.title || '';
      modal.classList.add('open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('modal-open');
    });
  });

  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal.classList.contains('open')) closeModal(); });
})();

(() => {
  const openEditor = id => {
    const display = document.querySelector(`[data-comment-display="${id}"]`);
    const editor = document.querySelector(`[data-comment-edit="${id}"]`);
    if (!editor) return;
    if (display) display.hidden = true;
    editor.hidden = false;
    const textarea = editor.querySelector('textarea');
    if (textarea) textarea.focus();
  };

  const closeEditor = id => {
    const display = document.querySelector(`[data-comment-display="${id}"]`);
    const editor = document.querySelector(`[data-comment-edit="${id}"]`);
    if (display) display.hidden = false;
    if (editor) editor.hidden = true;
  };

  document.querySelectorAll('.js-edit-comment').forEach(button => {
    button.addEventListener('click', () => openEditor(button.dataset.commentId));
  });

  document.querySelectorAll('.js-cancel-edit').forEach(button => {
    button.addEventListener('click', () => closeEditor(button.dataset.commentId));
  });
})();

(() => {
  const element = document.querySelector('[data-deadline-alert-modal]');
  if (!element || !window.bootstrap) return;
  const signature = element.dataset.alertSignature || 'default';
  const storageKey = `deadline-alert-seen-${signature}`;
  try {
    if (sessionStorage.getItem(storageKey) === '1') return;
    sessionStorage.setItem(storageKey, '1');
  } catch (_) {}
  window.setTimeout(() => window.bootstrap.Modal.getOrCreateInstance(element).show(), 350);
})();
