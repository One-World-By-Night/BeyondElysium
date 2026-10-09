# Personagens do Administrador

A lista completa de personagens, pelo wp-admin, da crônica que você escolher - personagens de jogadores e NPCs numa só página, com uma chave entre eles e um botão para começar um novo.

## Quem pode usar

Os Narradores (HST e AST) de uma crônica, e qualquer administrador do WordPress. Beyond Elysium → Personagens, em si, precisa só da capacidade de todo o site que toda conta de HST, AST ou administrador já carrega, então a lista suspensa **Jogo** lista toda crônica da instalação - mas a lista só carrega, e Atribuir jogador/Excluir só funcionam, numa crônica em que você de fato é HST ou AST (um administrador pode gerenciar qualquer crônica, seja ou não membro). Escolha uma crônica em que você não tem papel e a lista não carrega. Um Condutor de Trama, a Harpia da crônica e um jogador nunca veem este item de menu.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Personagens.

## A tela

- **Jogo** - uma lista suspensa com toda crônica da instalação. Escolher uma recarrega a lista abaixo para ela.
- **Mostrar NPCs em vez de personagens de jogadores** - uma caixa de seleção. Desmarcada mostra personagens de jogadores; marcada troca a tabela inteira para NPCs.
- **+ Novo Personagem** - abre o mesmo formulário de criação de personagem que um jogador usa, no front-end, já ajustado para a crônica que você escolheu aqui. Veja o [Editor de Personagem](character-editor.md).
- Abaixo, a mesma tabela de elenco da aba Personagens do front-end - veja [Personagens](character-list.md) para as colunas, a ordenação e a paginação dela - exceto que esta página sempre mostra as ações Atribuir jogador/Trocar jogador e Excluir, e sempre mostra todo personagem da crônica e não só os seus.
- "Ainda não existe nenhum jogo - crie um em Beyond Elysium → Configuração do Sistema → Jogos primeiro." aparece no lugar de tudo acima quando não existe nenhuma crônica na instalação.

## Tarefas comuns

### Navegar pelos personagens de uma crônica

1. Abra Beyond Elysium → Personagens.
2. Escolha a crônica em **Jogo**.

### Alternar entre personagens de jogadores e NPCs

1. Marque ou desmarque **Mostrar NPCs em vez de personagens de jogadores**.

### Criar um personagem ou NPC

1. Escolha a crônica em **Jogo**.
2. Clique em **+ Novo Personagem**.
3. Preencha o formulário - marque **Este é um NPC** se é isso que você está montando. Veja o [Editor de Personagem](character-editor.md).

### Marcar um personagem existente como NPC, ou desmarcar um

1. Clique no nome dele para abrir a Ficha e depois em **Editar este personagem**.
2. Alterne **Este é um NPC** ali. Veja o [Editor de Personagem](character-editor.md).

### Atribuir, trocar ou excluir um personagem

1. Procure na tabela.
2. Use **Atribuir jogador** / **Trocar jogador**, ou **Excluir** - veja [Personagens](character-list.md) para os dois.

## O que saber

- **Este é o único lugar para ver ou gerenciar NPCs.** A aba Personagens do front-end nunca os mostra, a ninguém.
- **A lista suspensa Jogo não se limita às crônicas que você de fato gerencia** - lista toda crônica da instalação. Carregar a lista, e usar Atribuir jogador ou Excluir, ainda exige um papel de Narrador de verdade na que você escolheu (ou uma conta de administrador do site) - veja o [Guia do Administrador](../admin-guide.md#o-que-um-hst-pode-e-não-pode-fazer).
- **+ Novo Personagem e clicar num nome saem os dois do wp-admin** - abrem a página Minha Crônica do front-end em vez de uma tela daqui.
- **Excluir um personagem aqui não pode ser desfeito** - leva junto o histórico de alterações, os instantâneos, o estilo da ficha e as conexões do personagem.
- Toda página de administração do Beyond Elysium, esta inclusive, traz uma pequena linha de homenagem no fim: "In Memory of Arielle 'XP Day' M."

## Solução de problemas

- **"Ainda não existe nenhum jogo - crie um em Beyond Elysium → Configuração do Sistema → Jogos primeiro."** Só um administrador do site pode criar um.
- **"Falha ao carregar personagens. Tente atualizar a página."** Se isso continua acontecendo numa crônica específica, você provavelmente não é HST nem AST nela - tente uma em que você seja, ou peça a um administrador do site.
- **Não vejo + Novo Personagem.** Escolha antes uma crônica em **Jogo** - ele só aparece depois que uma está escolhida.
- **Não vejo este item de menu de jeito nenhum.** Ele exige um papel de Narrador em pelo menos uma crônica, ou uma conta de administrador.

## Relacionados

- [Editor de Personagem](character-editor.md)
- [Personagens](character-list.md)
- [Painel do Administrador](admin-dashboard.md)
- [Jogos](games.md)
- [Papéis](roles.md)
- [Guia do Administrador](../admin-guide.md#o-que-um-hst-pode-e-não-pode-fazer)
- [Guia do Narrador](../st-guide.md#3-criando-personagens)
