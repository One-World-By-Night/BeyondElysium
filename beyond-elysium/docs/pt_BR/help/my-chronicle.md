# Minha Crônica

A página única que você usa para tudo em uma crônica a que pertence: escolha a crônica e depois passe entre o Início, os Personagens, o Personagem, o Compêndio, as Tramas e Rumores e os Relatórios dela.

## Quem pode usar

Qualquer pessoa autenticada. Pertencer a pelo menos uma crônica - como jogador, ou como Narrador olhando aqui a sua visão de jogador em vez do Kit de Ferramentas do Narrador - mostra a página inteira abaixo do seletor de crônica; não pertencer a nenhuma ainda mostra **Crônicas que você pode pedir para entrar**.

## Como chegar lá

Procure um link "Minha Crônica" na navegação do seu site - este plugin cria a página automaticamente. Se não encontrar, pergunte a um Narrador ou ao administrador do seu site onde ela está ligada. No layout próprio do Beyond Elysium o Kit de Ferramentas do Narrador tem um link para ela no topo.

## A tela

- **Crônica** - uma lista suspensa com cada crônica a que você pertence, cada uma marcada com o seu papel ali. Escolher outra troca tudo abaixo dela para aquela crônica. Qualquer crônica diferente da escolhida no momento também mostra quantas das suas próprias mudanças ainda estão pendentes ali, por exemplo "- 2 pendentes" - um jeito rápido de notar que há algo esperando em uma crônica que você não está vendo. Se você não pertence a nenhuma crônica, isso é dito em vez de mostrar uma lista, com um link para [enviar um arquivo do Grapevine para uma](send-grapevine-file.md).
- **Crônicas que você pode pedir para entrar** - recolhida quando você já pertence a uma crônica, aberta por padrão quando não pertence. Toda crônica do site que aceita pedidos de entrada e da qual você ainda não é membro, cada uma com um botão **Pedir para entrar** que abre o painel de entrada. Veja [Entrar em uma Crônica](joining.md).
- **Abas**, depois que uma crônica é escolhida:
  - **Início** - os seus personagens com o que cada um tem para gastar, o que precisa de você, a próxima noite de jogo e as suas últimas tramas. Veja [Início](player-dashboard.md).
  - **Personagens** - os seus personagens aqui (todos os personagens, para um Narrador desta crônica), com **+ Novo Personagem** para começar outro. Veja [Personagens](character-list.md).
  - **Personagem** - o personagem escolhido, ou um pedido para escolher um em Personagens se nenhum está escolhido ainda, com uma fileira de visualizações: **Jogar** (reservas, saúde e o que foi perdido hoje à noite), **Ficha**, **História** (Antecedentes, Notas, o perfil do Quem é Quem e, para um Narrador, as anotações de RP), **Histórico** e **Editar**. Abre na visualização que você usou por último neste aparelho, ou na Ficha na primeira vez. Veja [Ficha de Personagem](character-sheet.md) e [Editor de Personagem](character-editor.md).
  - **Compêndio** - o catálogo da crônica, um tipo de criatura e uma seção de cada vez, com o que cada entrada custa a um personagem que você escolher. Veja [Compêndio](compendium.md).

Cada aba mostra o que você pode fazer na crônica escolhida - um Narrador aqui vê todo personagem em **Personagens**, e guarda os controles do Narrador no [Kit de Ferramentas do Narrador](storyteller-toolkit.md).
  - **Minhas Tramas e Rumores** - os fios de trama a que você está ligado e qualquer rumor que chegou até você.
  - **Quem é Quem** - o diretório de perfis públicos da crônica, NPCs e personagens de jogadores, mostrando só o que o perfil de cada um compartilha. Veja [Quem é Quem](whos-who.md).
  - **O Que Eu Sei** - segredos que foram revelados a qualquer um dos seus personagens. Veja [O Que Eu Sei](what-i-know.md).
  - **Relatório Pós-Jogo** - o que o seu personagem fez, o que você quer em seguida e qualquer coisa para a equipe, um por personagem por sessão. Veja [Relatório Pós-Jogo](after-game-report.md).
  - **Relatórios** - um pequeno seletor entre **Calendário do Jogo**, **Cartões de Local**, **Cartões de Ritual** e **Vínculos**, aberto a todo membro, Narrador ou não. Vínculos lista os vínculos e o Guanxi dos seus próprios personagens, com um link **Imprimir**. O Calendário do Jogo lista as noites de jogo desta crônica, ou diz que não há nenhuma. Cartões de Local e Cartões de Ritual mostram um cartão por entrada, com todos os campos - inclusive o texto formatado de Descrição ou Aparência do próprio Narrador - exibidos como foram escritos; uma passagem marcada com `[ST]...[/ST]` continua sendo retirada para você, igual a todo lugar. Um seletor **Restringir a um personagem** acima das abas deixa um mago escolher para qual dos seus personagens ver Cartões de Ritual - a aba só aparece depois que se escolhe um personagem cuja pilha realmente tem rituais. Veja [Relatórios](reports.md).

A página guarda a crônica, a aba e o personagem que você escolheu no endereço web dela, então atualizar ou marcar como favorita leva você de volta à mesma visão.

## O layout próprio do Beyond Elysium

A menos que um administrador do site o tenha desligado em [Identidade Visual](branding.md), esta página mostra o layout próprio do Beyond Elysium no lugar do layout do tema:

- **O cabeçalho** nomeia o site primeiro, com o logotipo dele, como um link para a página inicial do site, depois **Minha Crônica**, o seletor de crônica e um seletor dos seus próprios personagens nessa crônica. Escolher um personagem abre a Ficha dele, ou mantém você na aba Editar quando ela está aberta. Um Narrador também vê um link **Kit de Ferramentas do Narrador**. **Ajuda** e o seu nome com **Sair** (ou **Entrar**) vêm por último.
- **O sino de avisos** fica no cabeçalho antes de **Ajuda**, com a contagem do que você não leu. Ele abre uma lista: para um jogador, o que um Narrador fez com as suas mudanças ("Alice: 2 aprovadas, 1 recusada", com cada linha e qualquer nota), uma mudança que um Narrador editou, uma mudança tomada de volta com Desfazer e XP concedido; para um Narrador, "Alice enviou 3 alterações; 2 aguardam você", com link para a [Fila de Aprovação](approval-queue.md). Cada aviso leva ao lugar a que pertence, **Marcar todos como lidos** zera a contagem, e os avisos são guardados por 90 dias. Um Narrador também encontra ali **Alterações aguardando você**, com as escolhas Cada envio, Uma vez por dia e Nunca, e o padrão sendo o que o e-mail da equipe da crônica decidir.
- **Em um computador** toda aba aparece numa linha abaixo do cabeçalho, passando para uma segunda linha quando não cabem.
- **Em um celular** uma barra na parte de baixo da tela tem **Início**, **Personagem** (o personagem escolhido, ou Personagens quando nenhum está escolhido) e **Tramas** (Minhas Tramas e Rumores). **Mais** lista toda outra aba, o link do Kit de Ferramentas do Narrador, a Ajuda e a saída.
- **O rodapé** traz o Desenvolvido por Beyond Elysium, o logotipo da Dark Pack e o aviso da Paradox.

Para manter a página na tela inicial do celular, use **Adicionar à tela inicial** no seu navegador. Ela abre no navegador, com o nome do site.

## Tarefas comuns

### Trocar para outra crônica

1. Abra a lista suspensa **Crônica** no topo da página.
2. Escolha a que você quer. As abas abaixo recarregam para aquela crônica.

### Ir direto a uma aba

1. Clique em **Início**, **Personagens**, **Personagem**, **Compêndio**, **Minhas Tramas e Rumores** ou **Relatórios**.

### Consultar a descrição de um local ou ritual

1. Abra a aba **Relatórios**.
2. Escolha **Cartões de Local** ou **Cartões de Ritual**.

### Pedir para entrar em uma crônica

1. Abra **Crônicas que você pode pedir para entrar**, ou siga um link de entrada que um Narrador compartilhou com você.
2. Clique em **Pedir para entrar** ao lado da que você quer.
3. Escreva uma mensagem curta e clique em **Enviar solicitação** - ou, em vez de enviar só a mensagem, clique em **Começar um personagem** ou **Enviar um arquivo do Grapevine**, cada um com o seu jeito próprio de pedir. Veja [Entrar em uma Crônica](joining.md).

## O que saber

- A lista suspensa **Crônica** só mostra as crônicas a que você já pertence - não é um diretório de todas as crônicas do site. **Crônicas que você pode pedir para entrar** é esse diretório, reduzido às que ainda aceitam pedidos.
- Trocar a lista suspensa **Crônica** enquanto a aba Personagem tem um personagem aberto não limpa essa escolha - o personagem escolhido antes pertencia à crônica que você acabou de deixar. Veja Solução de problemas.

## Solução de problemas

- **"Entre para ver as suas crônicas."** Você não está autenticado. Clique em **Entrar**, e você volta para esta página.
- **"Você ainda não pertence a nenhuma crônica."** Peça para entrar em uma pela lista abaixo, ou [envie um arquivo do Grapevine](send-grapevine-file.md) se você já tem um - veja [Entrar em uma Crônica](joining.md).
- **"Não foi possível carregar as suas crônicas."** O pedido falhou - clique em **Tentar novamente**.
- **Uma crônica em que acabei de entrar não está na lista suspensa.** O seu pedido de entrada, ou o personagem que você começou para ela, provavelmente ainda espera um Narrador - ela aparece quando ele aprovar.
- **Troquei de crônica e a minha aba Personagem parou de mostrar o personagem que eu tinha aberto.** Esse personagem pertence à crônica em que você estava. Vá a **Personagens** e escolha um da nova crônica.
- **Nada carrega.** Confira se você está autenticado e atualize a página.

## Relacionados

- [Entrar em uma Crônica](joining.md)
- [Início](player-dashboard.md)
- [Compêndio](compendium.md)
- [Personagens](character-list.md)
- [Enviar um Arquivo do Grapevine](send-grapevine-file.md)
- [Ficha de Personagem](character-sheet.md)
- [Editor de Personagem](character-editor.md)
- [Quem é Quem](whos-who.md)
- [O Que Eu Sei](what-i-know.md)
- [Relatório Pós-Jogo](after-game-report.md)
- [Relatórios](reports.md)
- [Papéis](roles.md)
- [Crônicas](chronicles.md)
- [Guia do Jogador](../player-guide.md)
