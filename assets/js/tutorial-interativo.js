(() => {
  'use strict';
  if (!document.querySelector('.userbox .profile-link')) return;
  const page = location.pathname.split('/').pop();
  const common = [
    ['.topbar nav', 'Navegue pelo sistema', 'Use Dashboard para a visão geral, Tarefas para o quadro de trabalho, Lembretes para recados e Arquivados para consultar tarefas guardadas.'],
    ['[data-notification-toggle]', 'Acompanhe as notificações', 'O sino reúne avisos do sistema. Abra as notificações para consultar os detalhes.'],
    ['.profile-link', 'Seu perfil', 'Clique no seu nome para atualizar seus dados, e-mail e senha.'],
    ['a[href="tutorial.php"]', 'Consulte a ajuda', 'A página Ajuda contém as orientações completas. Este tutorial pode ser repetido pelo botão Tutorial interativo.']
  ];
  const guides = {
    'dashboard.php': [
      ['.page-head', 'Bem-vindo ao gerenciador', 'Este painel apresenta a visão geral das tarefas e lembretes. O guia apenas explica os controles: nenhum registro será criado ou alterado.'],
      ['.dashboard-reminders-panel', 'Lembretes do dia', 'Consulte os lembretes pendentes e seus prazos. Use Ver todos os lembretes para abrir a lista completa.'],
      ['.page-actions a[href="task_form.php"]', 'Crie uma tarefa', 'Use Nova tarefa para registrar uma atividade, definir responsáveis, prioridade e prazo.']
    ],
    'task_form.php': [
      ['#task-title', 'Dê um título à tarefa', 'Descreva a ação de forma clara, por exemplo: Conferir relatório mensal.'],
      ['.task-assignees-field', 'Escolha os responsáveis', 'Marque as pessoas responsáveis pela atividade. Você pode selecionar mais de uma.'],
      ['#task-due', 'Defina o prazo', 'Informe a data de vencimento para acompanhar entregas e atrasos.'],
      ['#task-priority', 'Prioridade e andamento', 'Escolha a prioridade e use Status inicial para indicar a etapa atual da atividade.'],
      ['.task-form-privacy', 'Confira a privacidade', 'Ao marcar Tarefa privada, somente você poderá visualizar o item.'],
      ['#task-description', 'Explique o trabalho', 'Inclua contexto e instruções. Depois de encerrar o guia, confira os campos e use o botão de salvar do formulário.'],
      ['.task-control-panel', 'Encontre uma tarefa', 'Use os filtros, a pesquisa e a ordenação para localizar atividades.'],
      ['.task-board', 'Acompanhe pelo quadro', 'Abra um cartão para ver detalhes. Quando você tiver permissão, poderá movimentá-lo entre A fazer, Em andamento, Em revisão e Concluídas.']
    ],
    'reminders.php': [
      ['#reminder-title', 'Registre um lembrete', 'Digite um recado ou compromisso curto.'],
      ['#reminder-date', 'Escolha uma data', 'Preencha a data quando houver prazo para o lembrete.'],
      ['.reminder-assignees-field', 'Indique os responsáveis', 'Selecione as pessoas que devem receber a notificação.'],
      ['.reminder-privacy', 'Pessoal ou compartilhado', 'Marque Compartilhado para permitir que todos visualizem o lembrete. Confira a escolha antes de criar.'],
      ['.reminder-primary-action', 'Crie após conferir', 'Quando terminar o tutorial, clique em Criar lembrete para salvar os dados preenchidos.'],
      ['.reminder-pending-card', 'Acompanhe os pendentes', 'Os controles de concluir, editar e excluir aparecem conforme sua permissão.'],
      ['#completedRemindersPanel', 'Consulte os concluídos', 'Veja o histórico de lembretes finalizados. Confira com atenção antes de usar Limpar concluídos.']
    ],
    'task.php': [
      ['main h1', 'Detalhes da tarefa', 'Consulte os dados da atividade. Os controles disponíveis dependem das suas permissões.'],
      ['input[name="subtask_id"], input[placeholder="Adicionar subtarefa..."]', 'Divida em etapas', 'Use as subtarefas para acompanhar pequenas entregas dentro desta atividade.'],
      ['textarea[name="body"]', 'Registre o andamento', 'Escreva um comentário para compartilhar informações e atualizações.'],
      ['input[name="file"]', 'Anexe arquivos', 'Quando disponível, selecione um arquivo e use o botão do formulário para enviar.']
    ]
  };
  const steps = [...(guides[page] || []), ...common].filter(([s]) => {
    const el = document.querySelector(s); return el && el.getClientRects().length;
  });
  const launch = document.createElement('button');
  launch.type = 'button'; launch.className = 'tour-launch'; launch.textContent = 'Tutorial interativo';
  const userbox = document.querySelector('.topbar .userbox');
  userbox.insertBefore(launch, userbox.querySelector('.profile-link'));
  let dialog, ring, index = 0, previous, frame;
  function place() {
    if (!dialog) return;
    const el = document.querySelector(steps[index][0]);
    if (!el) { ring.hidden = true; return; }
    const r = el.getBoundingClientRect();
    Object.assign(ring.style, {left: `${Math.max(2, r.left - 5)}px`, top: `${Math.max(2, r.top - 5)}px`, width: `${Math.min(innerWidth - 4, r.width + 10)}px`, height: `${Math.min(innerHeight - 4, r.height + 10)}px`});
    const height = dialog.getBoundingClientRect().height;
    const top = r.bottom + height + 20 < innerHeight ? r.bottom + 12 : Math.max(12, r.top - height - 12);
    Object.assign(dialog.style, {left: `${Math.max(12, Math.min(r.left, innerWidth - dialog.offsetWidth - 12))}px`, top: `${Math.min(top, Math.max(12, innerHeight - height - 12))}px`});
  }
  function close() {
    if (!dialog) return;
    dialog.remove(); ring.remove(); dialog = null;
    cancelAnimationFrame(frame);
    window.removeEventListener('resize', place); window.removeEventListener('scroll', place, true);
    document.removeEventListener('keydown', keys, true);
    previous?.focus();
  }
  function show() {
    const [, title, body] = steps[index];
    dialog.querySelector('h2').textContent = title;
    dialog.querySelector('p').textContent = body;
    dialog.querySelector('.tour-count').textContent = `Passo ${index + 1} de ${steps.length}`;
    dialog.querySelector('[data-back]').disabled = index === 0;
    dialog.querySelector('[data-next]').textContent = index === steps.length - 1 ? 'Concluir' : 'Próximo';
    document.querySelector(steps[index][0])?.scrollIntoView({block: 'center', behavior: 'instant'});
    frame = requestAnimationFrame(place);
    dialog.focus();
  }
  function keys(e) {
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    if (e.key === 'Tab') {
      const buttons = [...dialog.querySelectorAll('button:not(:disabled)')];
      const first = buttons[0], last = buttons[buttons.length - 1];
      if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && (document.activeElement === last || document.activeElement === dialog)) { e.preventDefault(); first.focus(); }
    }
  }
  launch.addEventListener('click', () => {
    if (dialog || !steps.length || document.querySelector('.modal.show')) return;
    previous = document.activeElement; index = 0;
    ring = document.createElement('div'); ring.className = 'tour-ring'; ring.setAttribute('aria-hidden', 'true');
    dialog = document.createElement('dialog'); dialog.className = 'tour-dialog'; dialog.tabIndex = -1;
    dialog.setAttribute('aria-labelledby', 'tour-title'); dialog.setAttribute('aria-describedby', 'tour-description');
    dialog.innerHTML = '<span class="tour-count" aria-live="polite"></span><h2 id="tour-title"></h2><p id="tour-description"></p><div class="tour-actions"><button type="button" data-close>Encerrar</button><button type="button" data-back>Voltar</button><button type="button" data-next>Próximo</button></div>';
    document.body.append(ring, dialog); dialog.showModal();
    dialog.querySelector('[data-close]').onclick = close;
    dialog.querySelector('[data-back]').onclick = () => { if (index > 0) { index--; show(); } };
    dialog.querySelector('[data-next]').onclick = () => { if (index < steps.length - 1) { index++; show(); } else close(); };
    dialog.addEventListener('cancel', e => { e.preventDefault(); close(); });
    window.addEventListener('resize', place); window.addEventListener('scroll', place, true);
    document.addEventListener('keydown', keys, true); show();
  });
})();
