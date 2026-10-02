<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
$loggedIn = !empty($_SESSION['user_id']);
$networkUrl = 'http://' . NETWORK_COMPUTER_NAME . NETWORK_APP_PATH;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Tutorial completo - <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <script src="assets/js/theme.js"></script>
</head>
<body>
<main class="tutorial-page">
    <header class="tutorial-top">
        <div><span class="muted">Central de ajuda</span><h1>📘 Como usar o Gerenciador de Tarefas</h1><p class="muted">Guia completo, da entrada no sistema até a conclusão de uma tarefa.</p></div>
        <a class="btn" href="<?= $loggedIn ? 'dashboard.php' : 'login.php' ?>">← <?= $loggedIn ? 'Voltar ao sistema' : 'Voltar ao login' ?></a>
    </header>

    <nav class="tutorial-nav" aria-label="Índice do tutorial">
        <a href="#primeiro-acesso">Primeiro acesso</a><a href="#permissoes">Permissões</a><a href="#visao-geral">Visão geral</a><a href="#tarefas">Tarefas</a><a href="#kanban">Quadro Kanban</a><a href="#lembretes">Lembretes</a><a href="#perfil">Perfil e senha</a><a href="#rede">Acesso em rede</a><a href="#duvidas">Dúvidas comuns</a>
    </nav>

    <section class="panel tutorial-section" id="primeiro-acesso">
        <h2>1. Primeiro acesso e criação de usuário</h2>
        <ol>
            <li>Na tela de login, localize o quadro <strong>Criar usuário</strong>.</li>
            <li>Informe seu nome, um nome de login fácil de lembrar, seu e-mail e uma senha com no mínimo 8 caracteres.</li>
            <li>Repita a senha e clique em <strong>Criar usuário</strong>. Você entrará automaticamente.</li>
            <li>Nos próximos acessos, use o e-mail ou o nome de login e sua senha no quadro <strong>Entrar</strong>.</li>
        </ol>
        <div class="tutorial-note">Cada pessoa deve usar sua própria conta. Novas contas entram como Usuário. O administrador <strong>felippe.andreata</strong> pode nomear outras pessoas como administradoras pela página Usuários.</div>
    </section>

    <section class="panel tutorial-section" id="permissoes">
        <h2>2. Perfis, privacidade e permissões</h2>
        <p>O sistema possui somente dois perfis: <strong>Usuário</strong> e <strong>Administrador</strong>. Ambos seguem exatamente as mesmas regras para tarefas e lembretes. A diferença é que o Administrador também acessa a página Usuários para cadastrar, editar, remover contas e nomear outros administradores.</p>
        <table class="tutorial-table"><thead><tr><th>Tipo de item</th><th>Quem visualiza</th><th>Quem altera, movimenta ou remove</th></tr></thead><tbody>
            <tr><td>Público</td><td>Todos os usuários</td><td>O criador e as pessoas marcadas como responsáveis.</td></tr>
            <tr><td>Privado</td><td>Somente o criador</td><td>Somente o criador.</td></tr>
        </tbody></table>
        <p>Em tarefas públicas, usuários que não são criadores nem responsáveis podem acompanhar o conteúdo e registrar comentários sobre o andamento. Cada pessoa pode editar ou excluir somente os próprios comentários.</p>
        <div class="tutorial-note tutorial-warning"><strong>Importante:</strong> ser Administrador não libera tarefas ou lembretes privados de outras pessoas e não permite alterar conteúdo alheio fora das regras acima.</div>
    </section>

    <section class="panel tutorial-section" id="visao-geral">
        <h2>3. Conhecendo a tela principal</h2>
        <p>Depois do login, o <strong>Dashboard</strong> mostra um resumo do trabalho: tarefas a fazer, em andamento, em revisão, concluídas, atrasadas e lembretes pendentes.</p>
        <table class="tutorial-table"><thead><tr><th>Área</th><th>Para que serve</th></tr></thead><tbody>
            <tr><td>Dashboard</td><td>Resumo geral, progresso, tarefas recentes e próximos vencimentos.</td></tr>
            <tr><td>Lembretes</td><td>Anotações rápidas com prazo, sem precisar criar uma tarefa completa.</td></tr>
            <tr><td>Tarefas</td><td>Criação, pesquisa, filtros e organização do trabalho no quadro Kanban.</td></tr>
            <tr><td>Ajuda</td><td>Abre este tutorial a qualquer momento.</td></tr>
            <tr><td>Usuários</td><td>Área exclusiva de administradores para cadastrar, editar, remover contas e nomear outros administradores.</td></tr>
            <tr><td>Seu nome/avatar</td><td>Abre o perfil para alterar dados, e-mail e senha.</td></tr>
        </tbody></table>
    </section>

    <section class="panel tutorial-section" id="tarefas">
        <h2>4. Criando e detalhando tarefas</h2>
        <ol>
            <li>Clique em <strong>Tarefas</strong> no menu ou em <strong>+ Nova tarefa</strong> no Dashboard.</li>
            <li>Preencha o título com uma ação clara, por exemplo: “Conferir relatório mensal”.</li>
            <li>Quando aplicável, role a lista de usuários e marque um ou vários responsáveis. Depois escolha a prioridade e a data de vencimento. Cada responsável possui uma caixa de seleção própria.</li>
            <li>Use a descrição para registrar contexto, resultado esperado e informações necessárias.</li>
            <li>Marque como <strong>privada</strong> somente quando o conteúdo deva aparecer exclusivamente para você. Responsáveis não visualizam um item privado.</li>
            <li>Salve. A tarefa aparecerá na coluna <strong>A fazer</strong>.</li>
        </ol>
        <h3>Ao abrir uma tarefa</h3>
        <p>O criador e os responsáveis podem editar, movimentar, anexar arquivos, administrar subtarefas e remover uma tarefa pública. Os demais usuários podem visualizá-la e comentar o andamento. Use subtarefas para dividir um trabalho grande em pequenas entregas.</p>
        <div class="tutorial-note tutorial-warning"><strong>Atenção:</strong> antes de excluir uma tarefa, confirme se o histórico e os anexos não serão mais necessários. A exclusão não deve ser usada apenas para indicar conclusão.</div>
    </section>

    <section class="panel tutorial-section" id="kanban">
        <h2>5. Atualizando o andamento no quadro Kanban</h2>
        <p>O quadro organiza cada tarefa em uma etapa. No computador, arraste o cartão para a coluna correta:</p>
        <ul><li><strong>A fazer:</strong> ainda não iniciada.</li><li><strong>Em andamento:</strong> alguém está trabalhando nela.</li><li><strong>Em revisão:</strong> pronta para conferência ou aprovação.</li><li><strong>Concluída:</strong> trabalho finalizado.</li></ul>
        <p>Somente o criador ou um responsável pode arrastar o cartão. Os demais podem abrir a tarefa e comentar. Use os filtros rápidos para localizar tarefas por situação e a busca para procurar pelo título.</p>
    </section>

    <section class="panel tutorial-section" id="lembretes">
        <h2>6. Usando lembretes</h2>
        <ol><li>Abra <strong>Lembretes</strong>.</li><li>Digite uma anotação curta e, se quiser, escolha uma data.</li><li>Em um lembrete público, marque uma ou mais pessoas como responsáveis.</li><li>Se marcar como privado, somente você poderá visualizá-lo e os responsáveis serão desconsiderados.</li><li>Clique para adicionar.</li><li>O criador ou um responsável pode editar, concluir, reabrir ou excluir o lembrete público.</li></ol>
        <p>Use lembretes para recados rápidos. Se o item exigir responsável, prioridade, arquivos ou acompanhamento por etapas, crie uma tarefa.</p>
    </section>

    <section class="panel tutorial-section" id="perfil">
        <h2>7. Perfil, e-mail e recuperação de senha</h2>
        <p>Clique no seu nome no canto superior direito para abrir <strong>Meu perfil</strong>. Nessa tela você pode alterar nome, nome de login, senha e e-mail. A troca de e-mail exige códigos de confirmação enviados ao endereço atual e ao novo endereço.</p>
        <p>Se esquecer a senha, volte ao login e clique em <strong>Esqueci minha senha</strong>. Informe o e-mail da conta, receba o código e crie uma nova senha. Se a mensagem não chegar, verifique Spam/Lixo eletrônico e peça ao administrador para conferir a configuração de e-mail.</p>
    </section>

    <section class="panel tutorial-section" id="rede">
        <h2>8. Acessando de outro computador da rede</h2>
        <p>O computador principal identificado como <strong><?= e(NETWORK_COMPUTER_NAME) ?></strong> será o servidor. Nos outros computadores conectados à mesma rede, abra o navegador e digite:</p>
        <p><a class="network-url" href="<?= e($networkUrl) ?>"><?= e($networkUrl) ?></a></p>
        <h3>Preparação necessária no computador principal</h3>
        <ol>
            <li>Abra o painel do XAMPP e inicie <strong>Apache</strong> e <strong>MySQL</strong>.</li>
            <li>Mantenha o computador ligado e conectado à mesma rede dos demais.</li>
            <li>Quando o Windows perguntar, permita o Apache em <strong>Redes privadas</strong>.</li>
            <li>Se a pergunta não aparecer, abra “Firewall do Windows Defender com Segurança Avançada” e permita conexões de entrada TCP na porta 80 apenas no perfil privado.</li>
            <li>Em outra máquina, teste o endereço acima. Você também pode copiar o atalho <strong>Acessar Gerenciador de Tarefas - Chrome.lnk</strong> para a área de trabalho dela. O atalho abre diretamente no Google Chrome.</li>
        </ol>
        <div class="tutorial-note tutorial-warning"><strong>Segurança:</strong> use este endereço somente na rede local confiável. Ele não publica o sistema na internet. Não faça redirecionamento da porta 80 no roteador sem configurar HTTPS, autenticação e proteção adequadas.</div>
    </section>

    <section class="panel tutorial-section" id="duvidas">
        <h2>9. Dúvidas e soluções rápidas</h2>
        <h3>O endereço de rede não abre</h3><p>Confirme se as duas máquinas estão na mesma rede, se Apache e MySQL estão verdes no XAMPP e se o Firewall permite o Apache em rede privada. Tente também o IP do servidor: <strong>http://172.17.16.45/gerenciador_tarefas/</strong> ou <strong>http://192.168.56.1/gerenciador_tarefas/</strong>. O IP correto é o que pertence à rede usada pelas outras máquinas.</p>
        <h3>O banco ainda não foi configurado</h3><p>No computador principal, acesse <strong>setup.php</strong> uma única vez e siga a configuração inicial. Não execute uma nova instalação se o sistema já possui dados.</p>
        <h3>Uma tarefa ou lembrete não aparece para mim</h3><p>Verifique os filtros e a busca. Se o item foi criado como privado por outra pessoa, somente o criador poderá vê-lo — inclusive administradores não têm acesso.</p>
        <h3>O sistema parece desatualizado</h3><p>Atualize a página com Ctrl+F5. Se continuar, confirme se você está usando o endereço correto e se a pasta atualizada foi copiada para o XAMPP.</p>
    </section>

    <div class="tutorial-footer-actions"><a class="btn" href="<?= $loggedIn ? 'dashboard.php' : 'login.php' ?>">Voltar</a><a class="btn secondary" href="#">↑ Voltar ao início</a></div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
