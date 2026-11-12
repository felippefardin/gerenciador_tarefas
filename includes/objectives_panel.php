<link rel="stylesheet" href="assets/objectives.css?v=5">
<div class="panel task-section-card task-subtasks-card" id="subtasks-list">
    <div class="panel-head task-section-heading"><div><h2>Subtarefas</h2><p><?= $doneCount ?> de <?= count($subtasks) ?> concluídas</p></div><strong><?= $progress ?>%</strong></div>
    <div class="progress" role="img" aria-label="Progresso geral <?= $progress ?> por cento"><span style="width:<?= $progress ?>%"></span></div>
    <ul class="subtask-list-v2">
    <?php if (!$subtasks): ?><li class="muted">Nenhuma subtarefa adicionada.</li><?php endif; ?>
    <?php foreach ($subtasks as $st): $percent=(int)round(objective_ratio($st)*100); ?>
    <li class="subtask-list-item" id="subtask-<?= (int)$st['id'] ?>">
        <div class="subtask-list-head">
            <?php if (!(int)$st['objective_total']): ?>
            <form method="post" class="subtask-manual"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_subtask"><input type="hidden" name="subtask_id" value="<?= (int)$st['id'] ?>">
                <button class="check" type="submit" <?= !$canManageTaskFiles ? 'disabled' : '' ?> aria-label="<?= $st['completed'] ? 'Reabrir' : 'Concluir' ?> subtarefa <?= e($st['title']) ?>"><i class="bi <?= $st['completed'] ? 'bi-check-circle-fill' : 'bi-circle' ?>" aria-hidden="true"></i></button>
            </form>
            <?php endif; ?>
            <strong class="subtask-name"><?= e($st['title']) ?></strong><strong><?= $percent ?>%</strong>
            <a class="list-action" href="subtask_dashboard.php?id=<?= (int)$st['id'] ?>">Dashboard / PDF</a>
            <?php if ($canManageTaskFiles): ?>
            <button class="list-action" type="button" onclick="document.getElementById('edit-subtask-<?= (int)$st['id'] ?>').hidden = !document.getElementById('edit-subtask-<?= (int)$st['id'] ?>').hidden">Editar</button>
            <form method="post" onsubmit="return confirm('Excluir esta subtarefa e todos os seus objetivos?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_subtask"><input type="hidden" name="subtask_id" value="<?= (int)$st['id'] ?>"><button class="list-action danger-text">Excluir</button></form>
            <?php endif; ?>
        </div>
        <?php if ($canManageTaskFiles): ?>
        <form method="post" class="list-edit" id="edit-subtask-<?= (int)$st['id'] ?>" hidden><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="edit_subtask"><input type="hidden" name="subtask_id" value="<?= (int)$st['id'] ?>">
            <label for="subtask-name-<?= (int)$st['id'] ?>">Nome curto (até 60 caracteres)</label><input id="subtask-name-<?= (int)$st['id'] ?>" name="title" value="<?= e($st['title']) ?>" maxlength="60" required><label for="subtask-description-<?= (int)$st['id'] ?>">Descrição curta (opcional, até 300 caracteres)</label><textarea id="subtask-description-<?= (int)$st['id'] ?>" name="subtask_description" rows="2" maxlength="300" placeholder="Descrição curta (até 300 caracteres)"><?= e($st['description'] ?? '') ?></textarea><button class="btn small">Salvar</button><button type="button" class="btn secondary small" onclick="this.form.hidden=true">Cancelar</button>
        </form>
        <?php endif; ?>
        <div class="subtask-list-content">
            <?php if (trim((string)($st['description'] ?? '')) !== ''): ?><p class="subtask-description"><?= nl2br(e($st['description'])) ?></p><?php endif; ?>
            <h3 class="objectives-label">Objetivos</h3>
            <div class="progress" role="img" aria-label="Progresso da subtarefa <?= $percent ?> por cento"><span style="width:<?= $percent ?>%"></span></div>
            <?php if ((int)$st['objective_total']): ?><small class="muted"><?= (int)$st['objective_done'] ?> de <?= (int)$st['objective_total'] ?> objetivos concluídos</small><?php else: ?><small class="muted">Sem objetivos. Conclusão manual.</small><?php endif; ?>
            <ul class="objective-list-v2">
            <?php foreach ($objectivesBySubtask[$st['id']] ?? [] as $objective): ?>
            <li>
                <div class="objective-list-row">
                    <form method="post" class="objective-toggle"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_objective"><input type="hidden" name="subtask_id" value="<?= (int)$st['id'] ?>"><input type="hidden" name="objective_id" value="<?= (int)$objective['id'] ?>">
                        <label><input type="checkbox" name="completed" value="1" <?= $objective['completed'] ? 'checked' : '' ?> <?= !$canManageTaskFiles ? 'disabled' : '' ?> onchange="this.form.submit()"><span class="<?= $objective['completed'] ? 'objective-completed' : '' ?>"><?= e($objective['title']) ?></span></label>
                        <?php if ($canManageTaskFiles): ?><noscript><button class="btn small">Salvar</button></noscript><?php endif; ?>
                    </form>
                    <?php if ($canManageTaskFiles): ?>
                    <button class="list-action" type="button" onclick="document.getElementById('edit-objective-<?= (int)$objective['id'] ?>').hidden = !document.getElementById('edit-objective-<?= (int)$objective['id'] ?>').hidden">Editar</button>
                    <form method="post" onsubmit="return confirm('Excluir este objetivo?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_objective"><input type="hidden" name="subtask_id" value="<?= (int)$st['id'] ?>"><input type="hidden" name="objective_id" value="<?= (int)$objective['id'] ?>"><button class="list-action danger-text">Excluir</button></form>
                    <?php endif; ?>
                </div>
                <?php if ($canManageTaskFiles): ?><form method="post" class="list-edit" id="edit-objective-<?= (int)$objective['id'] ?>" hidden><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="edit_objective"><input type="hidden" name="subtask_id" value="<?= (int)$st['id'] ?>"><input type="hidden" name="objective_id" value="<?= (int)$objective['id'] ?>">
                    <label for="objective-name-<?= (int)$objective['id'] ?>">Texto do objetivo</label><input id="objective-name-<?= (int)$objective['id'] ?>" name="objective_title" value="<?= e($objective['title']) ?>" maxlength="220" required><button class="btn small">Salvar</button><button type="button" class="btn secondary small" onclick="this.form.hidden=true">Cancelar</button>
                </form><?php endif; ?>
            </li>
            <?php endforeach; ?>
            </ul>
            <?php if ($canManageTaskFiles): ?><form method="post" class="objective-add subtask-create" onsubmit="var b=this.querySelector('button[type=submit],button:not([type])'); if(b){b.disabled=true;b.textContent='Criando...';}"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_objective"><input type="hidden" name="subtask_id" value="<?= (int)$st['id'] ?>">
                <label class="list-sr" for="new-objective-<?= (int)$st['id'] ?>">Novo objetivo em <?= e($st['title']) ?></label><input id="new-objective-<?= (int)$st['id'] ?>" name="objective_title" placeholder="Adicionar objetivo..." maxlength="220" required><button class="btn small">Adicionar objetivo</button>
            </form><?php endif; ?>
        </div>
    </li>
    <?php endforeach; ?>
    </ul>
    <?php if ($canManageTaskFiles): ?><form method="post" class="objective-add"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="subtask"><h3>Nova subtarefa</h3><label for="new-subtask">Nome curto (até 60 caracteres)</label><input id="new-subtask" name="title" placeholder="Nome curto (até 60 caracteres)" maxlength="60" required><label for="new-subtask-description">Descrição curta (opcional, até 300 caracteres)</label><textarea id="new-subtask-description" name="subtask_description" rows="2" maxlength="300" placeholder="Descrição curta (até 300 caracteres)"></textarea><button class="btn small">Criar subtarefa</button></form><?php endif; ?>
</div>
