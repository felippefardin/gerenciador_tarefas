# Gerenciador de Tarefas v7

Projeto local em **PHP + MySQL**, preparado para XAMPP.

## Principais funções

- Login por e-mail ou nome de login.
- Criação de usuário diretamente na tela de login.
- Todos os usuários visualizam os mesmos projetos, tarefas e lembretes compartilhados.
- Página **Meu perfil** individual para cada conta.
- Alteração do nome, nome de login e senha pelo próprio usuário.
- Recuperação de senha por código de 6 dígitos enviado por e-mail.
- Redefinição por e-mail também disponível dentro de **Meu perfil**.
- Troca de e-mail no **Meu perfil** com dois códigos: um enviado ao endereço atual e outro ao novo.
- Tema claro/escuro disponível em todas as telas, com preferência salva no navegador.
- Projetos e tarefas em Kanban.
- Aviso em modal para tarefas que vencem hoje ou amanhã.
- Arquivamento de tarefas concluídas, com restauração para qualquer etapa ou exclusão manual definitiva.
- Vários responsáveis podem ser vinculados à mesma tarefa.
- Apenas dois perfis: Usuário e Administrador. `felippe.andreata` permanece administrador e pode nomear outros.
- Tarefas e lembretes públicos são gerenciados pelo criador e pelos responsáveis; os privados ficam visíveis somente ao criador.
- Subtarefas, comentários, imagens, anexos e exclusões.
- Lembretes compartilhados sem vínculo com projetos.

## Abrir no XAMPP

Extraia a pasta como:

`C:\xampp\htdocs\gerenciador_tarefas`

Inicie **Apache** e **MySQL** no XAMPP e acesse:

`http://localhost/gerenciador_tarefas/`

Na rede local, com o computador servidor ligado, acesse de outra máquina por:

`http://03-D000363/gerenciador_tarefas/`

O projeto também inclui o atalho `Acessar Gerenciador de Tarefas - Chrome.lnk`, que abre o sistema diretamente no Google Chrome e pode ser copiado para a área de trabalho das outras máquinas. Para liberar o primeiro acesso, consulte a seção **Acesso pela rede local** em `tutorial.php`.

Em instalação nova, abra primeiro:

`http://localhost/gerenciador_tarefas/setup.php`

Em instalação já existente, as atualizações de banco necessárias são aplicadas automaticamente. O administrador também pode abrir `upgrade.php`.

## E-mail / SMTP

O envio de código utiliza Gmail SMTP em `smtp.gmail.com`, porta `587`, com STARTTLS.

A configuração fica em `config/mail.php`.

**Importante:** esse arquivo contém credencial SMTP. Ele está listado no `.gitignore`. Não publique esse arquivo nem a senha de aplicativo em GitHub, repositório público ou hospedagem que outras pessoas possam baixar. Se a credencial for exposta, revogue-a na Conta Google e gere outra senha de aplicativo.

Para o envio funcionar no XAMPP, a extensão **OpenSSL** do PHP precisa estar habilitada e o computador precisa conseguir acessar `smtp.gmail.com:587`.
