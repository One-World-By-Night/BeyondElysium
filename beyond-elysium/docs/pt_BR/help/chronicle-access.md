# Acesso à Crônica

Quem pertence a uma crônica e que papel tem, a integração com o accessSchema e duas configurações de todo o site para fazer backup ou excluir os dados do próprio Beyond Elysium.

## Quem pode usar

Só os administradores do site. Toda rota que esta tela chama exige uma conta real de administrador do WordPress, e a própria aba fica escondida de todos os outros - um HST ou AST vê a linha Narradores da lista de conferência da [Configuração da Crônica](chronicle-setup.md), mas a lista de membros de verdade e a atribuição de papéis vivem aqui, fora do alcance deles. Um Condutor de Trama, a Harpia de uma crônica e um jogador nunca veem esta aba.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Configuração da Crônica → aba Acesso à Crônica. A própria aba só aparece para um administrador do site.

## A tela

- **accessSchema** - uma caixa de seleção, "Usar caminhos de papel do accessSchema (recorre à associação de crônica abaixo sempre que negar, estiver inacessível ou desativado)", mais uma linha que informa se um cliente real do accessSchema foi detectado nesta instalação, com um aviso se a chave está ligada mas nada está instalado para sustentá-la.
- **Gerenciamento de Dados** - de todo o site, não por crônica:
  - Uma caixa de seleção, "Excluir todos os dados do Beyond Elysium quando o plugin for desinstalado (desativado por padrão - desativar ou desinstalar de outra forma mantém toda crônica intacta)."
  - **Exportar todos os dados** - baixa toda crônica, personagem e catálogo como um único arquivo JSON.
- **Crônica** - uma lista suspensa com toda crônica da instalação. O padrão é a primeira crônica de verdade e não a demonstração semeada, a menos que um link tenha nomeado uma direto.
- **"caminho do accessSchema de [Crônica]"** - o caminho guardado em estilo de código, ou "(não definido)", com um botão **Editar** que revela uma caixa de texto (texto de exemplo "Chronicle/KONY") e **Salvar**/**Cancelar**.
- **"notificações de [Crônica]"** - uma caixa de seleção, "Enviar e-mail a um jogador quando sua alteração enviada for aprovada ou rejeitada." Todo e-mail que esta crônica deixa de enviar porque a caixa está desligada é listado no [Registro de E-mails](email-log.md) dela como "Os e-mails estão desativados nesta crônica".
- **Membros** - uma tabela de Nome, E-mail, Papel e Ações desta crônica:
  - **Papel** é uma lista suspensa por membro que oferece **HST**, **AST**, **Condutor de Trama**, **Harpia (favores)** ou **Jogador** - o que cada um pode fazer está em O que saber abaixo. Mudá-lo salva na hora. Um membro cuja conta do WordPress não pode usar o papel dele mostra "Esta conta precisa da função Editor neste site para usar esta função, a menos que o accessSchema a conceda."
  - **Remover** tira o acesso restrito à crônica desse membro, depois de uma confirmação.
- **+ Adicionar Membro** - abre um formulário: uma lista suspensa de **Papel** (os mesmos cinco valores), uma caixa de busca ("Buscar por nome ou e-mail…"), uma lista ao vivo de contas do WordPress correspondentes, cada uma com um botão **Adicionar como [papel]**, e **Cancelar**.

## Tarefas comuns

### Ligar ou desligar o accessSchema

1. Marque ou desmarque a caixa **accessSchema** no topo da página.

### Fazer backup de todos os dados do plugin

1. Em **Gerenciamento de Dados**, clique em **Exportar todos os dados**.
2. Um arquivo JSON é baixado com a data de hoje no nome.

### Definir o caminho do accessSchema de uma crônica

1. Escolha a crônica.
2. Clique em **Editar** ao lado do caminho do accessSchema dela.
3. Digite o caminho (por exemplo, `Chronicle/KONY`).
4. Clique em **Salvar**.

### Adicionar um membro

1. Escolha a crônica.
2. Clique em **+ Adicionar Membro**.
3. Escolha um **Papel**.
4. Busque por nome ou e-mail e clique em **Adicionar como [papel]** ao lado da pessoa certa.

### Mudar o papel de um membro

1. Ache-o na tabela **Membros**.
2. Escolha o novo papel na lista suspensa **Papel** dele.

### Remover um membro

1. Ache-o na tabela **Membros**.
2. Clique em **Remover** e confirme.

## O que saber

- **O accessSchema é uma cadeia de recuo, não uma substituição.** Sempre que ele nega o acesso, está inacessível ou está desligado, as conferências de permissão recuam sozinhas para a tabela de filiação da própria crônica - uma crônica roda bem sem nenhum outro conjunto de plugins do OWBN instalado.
- **Cinco papéis, não quatro: HST, AST, Condutor de Trama, Harpia (favores) e Jogador.** A Harpia conduz só o registro de favores, sem poderes de Narrador sobre personagens ou tramas.
- **Um papel só faz o que o papel do WordPress da conta permite.** Sem o accessSchema, um HST, AST ou Condutor de Trama precisa do papel Editor neste site; os papéis de Harpia e Jogador funcionam em qualquer conta. O Acesso à Crônica sinaliza um membro cuja conta fica aquém.
- **Remover um membro só remove o acesso restrito à crônica.** A conta do WordPress dele, e quaisquer personagens que ele tenha, ficam intocados.
- **Você raramente precisa adicionar um jogador à mão.** Um jogador ganha uma linha aqui automaticamente depois que um Narrador aprova o pedido de entrada pendente dele, ou você atribui a ele um personagem existente - adicione alguém aqui direto para torná-lo Narrador, Condutor de Trama ou a sua Harpia, ou para adicionar um jogador com antecedência.
- **O seletor de crônica pula a crônica de demonstração por padrão**, preferindo a primeira de verdade, para você não editar dados de exemplo por engano.
- **A opção de sair das notificações de um jogador é separada desta tela.** Vive na própria página de Perfil do WordPress dele, e só importa depois que a chave de notificações da própria crônica está ligada.
- **O Gerenciamento de Dados vale para todo o site.** A exportação e a escolha de excluir na desinstalação cobrem toda crônica da instalação de uma vez, não só a escolhida acima.

## Solução de problemas

- **"accessSchema está habilitado, mas nenhum cliente está instalado - toda requisição com escopo de crônica está recorrendo à associação abaixo."** A chave está ligada mas nada a sustenta - desligue-a, ou instale e ative o cliente do accessSchema.
- **Uma busca em Adicionar Membro não acha ninguém.** Confira a grafia - ela casa nome, e-mail ou nome de usuário.
- **Não vejo esta aba.** Você não está autenticado como administrador do site - a linha Narradores da lista de conferência da [Configuração da Crônica](chronicle-setup.md) ainda diz, em linhas gerais, se há um atribuído.
- **Removi um membro por engano.** Adicione-o de volta com **+ Adicionar Membro** e escolha o papel dele de novo - nada mais na conta dele mudou.
- **"Esta conta precisa da função Editor neste site para usar esta função..."** Dê à conta do WordPress dessa pessoa o papel Editor (tela Usuários), ou conceda o papel pelo accessSchema.

## Relacionados

- [Configuração da Crônica](chronicle-setup.md)
- [Configurações de Ação e Rumor](apr-settings.md)
- [Jogos](games.md)
- [Personagens do Administrador](admin-characters.md)
- [Papéis](roles.md)
- [Crônicas](chronicles.md)
- [Guia do Administrador](../admin-guide.md#acesso-restrito-à-crônica)
- [Guia do Narrador](../st-guide.md#accessschema-e-papéis-da-crônica)
