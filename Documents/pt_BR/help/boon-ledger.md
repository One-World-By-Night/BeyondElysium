# Registro de Favores

Acompanha quem deve um favor a quem na crônica - registre um novo e marque um como pago quando for resolvido.

## Quem pode usar

Os Narradores (HST e AST) e a Harpia da crônica - o papel feito especificamente para conduzir favores - podem registrá-los e quitá-los aqui. A aba **Registro de Favores** só aparece no Kit de Ferramentas do Narrador para quem tem um desses papéis na crônica escolhida no momento. Um Condutor de Trama e um jogador comum nunca veem esta aba. Um construtor de site também pode pôr o widget do Registro de Favores numa página (veja [Widgets do Elementor](elementor-widgets.md)), onde qualquer membro da crônica pode lê-lo - sem os controles de registrar e quitar.

## Como chegar lá

Kit de Ferramentas do Narrador → aba Registro de Favores.

## A tela

- **Registrar um favor** - alterna o formulário de criação abaixo dele.
- O formulário de criação:
  - **Devido por (ID do personagem)** e **Devido a (ID do personagem)** - campos numéricos. Digite direto o ID numérico de cada personagem; não há um seletor de nomes aqui.
  - **Nível** - texto livre, com `trivial`, `minor`, `major` e `life` oferecidos como sugestões enquanto você digita. Qualquer redação que você quiser é aceita.
  - **Termos** (opcional).
  - **Registrar** - cria o favor, datado de hoje.
- A tabela do registro (empilhada em cartões numa tela estreita): **Devido Por**, **Devido A**, **Nível**, **Data**, **Status**, **Termos** e uma coluna **Ações**.
  - Uma linha paga tem um estilo diferente de uma pendente, e mostra uma nota de quitação ao lado de "pago" se uma foi registrada.
  - **Marcar como pago** numa linha pendente abre uma caixa **Como foi resolvido? (opcional)** com **Confirmar** e **Cancelar**.
- "Nenhum." no lugar da tabela quando não há nada a mostrar.

## Tarefas comuns

### Registrar um favor

1. Abra Kit de Ferramentas do Narrador → Registro de Favores.
2. Clique em **Registrar um favor**.
3. Digite os IDs de personagem do devedor e do credor em **Devido por** e **Devido a**.
4. Digite ou escolha um **Nível** e, se quiser, adicione **Termos**.
5. Clique em **Registrar**.

### Marcar um favor como pago

1. Ache o favor pendente na tabela.
2. Clique em **Marcar como pago**.
3. Se quiser, anote como foi de fato resolvido.
4. Clique em **Confirmar**.

### Achar um favor numa lista longa

1. Esta tela não tem busca nem filtro próprios - use a busca de página do próprio navegador (Ctrl/Cmd+F) para ir a um nome.

## O que saber

- **Dois papéis registram e quitam.** Os Narradores e a Harpia desta crônica são as únicas pessoas que mudam favores. Um widget do Registro de Favores colocado numa página deixa os membros lê-los; dado um personagem, ele divide os favores desse personagem em **Favores Que Devo** e **Favores Devidos a Mim**.
- **Sem busca, filtro nem ordenação aqui.** A tabela simplesmente lista todo favor, os pendentes primeiro, cada grupo com o mais novo primeiro.
- **A data de um favor é sempre hoje** quando você o registra - este formulário não tem como informar outra data.
- **Depois de registrado, um favor só pode ser pago, nunca editado nem excluído.** As partes, o nível e os termos dele não têm controle de edição em lugar nenhum da interface, e não há como desfazer um pagamento por esta tela.
- **Os termos podem levar texto só para Narradores**, oculto de quem não pode gerenciar favores - mas como só os Narradores e a Harpia abrem esta tela, você sempre o verá por inteiro aqui.
- **Você precisa do ID numérico de cada personagem para registrar um favor.** Abra antes a Ficha dele pela aba Personagens e leia-o na barra de endereço, ou pergunte a quem gerencia os personagens da crônica.

## Solução de problemas

- **"Falha ao carregar o registro."** Atualize e tente de novo.
- **"Falha ao registrar este favor - verifique se ambos os personagens existem e são diferentes."** Um favor não pode ser devido a si mesmo, e os dois IDs precisam ser de personagens reais desta crônica.
- **"Falha ao marcar este favor como pago."** Tente de novo.
- **Um favor que registrei está faltando.** Confira se você está olhando a crônica certa - o registro é restrito à que estiver escolhida no topo do Kit. Se ele realmente sumiu, a ligação dele com um dos dois personagens provavelmente foi removida em outro lugar (veja [Conexões](connections.md)) - não pode ser reparado daqui; registre-o de novo.
- **Não vejo esta aba.** Você não é Narrador nem a Harpia desta crônica na crônica escolhida no momento - confira a lista suspensa **Crônica**, ou pergunte a um HST/AST.

## Relacionados

- [Kit de Ferramentas do Narrador](storyteller-toolkit.md)
- [Conexões](connections.md)
- [Papéis](roles.md)
- [Conteúdo Só para Narradores](storyteller-only.md)
- [Guia do Narrador](../st-guide.md#3-criando-personagens)
