# Tempo Livre

Veja a janela de tempo livre de uma data de jogo e toda trama de ação que ainda espera uma resposta.

## Quem pode usar

Qualquer pessoa com acesso a Tramas e Rumores (HST, AST, Condutor de Trama). Um jogador nunca vê esta tela - ele vê a própria janela de tempo livre no próprio formulário de ação.

## Como chegar lá

Kit de Ferramentas do Narrador → aba Tempo Livre.

## A tela

- **Data do jogo** - uma lista suspensa de toda noite de jogo que existe (veja [Noites de Jogo](game-nights.md)). Escolher uma carrega a fila dessa data abaixo. Um selo ao lado mostra o prazo, relativo a agora ("fecha em 3 dia(s)", "fechou há 2 dia(s)").
- **Não respondidas / Todos** - restringe a lista a tramas de ação sem resposta ainda, ou mostra todas da data.
- **Atribuído a** - Todos, Atribuído a mim, ou um membro da equipe nomeado, restringindo a lista às linhas desse responsável.
- A fila em si: uma linha por personagem com uma trama de ação nessa data - o nome, o jogador, quantas ações enviou, se foi respondida, o estado de lançamento da resposta (Rascunho, Agendado, Lançado, ou Publicada para uma enviada na hora), o que a resposta decidiu sobre a ação do personagem (Nenhuma ação cobrada, 1 ação cobrada de um antecedente, ou Cobrança de ação não registrada para uma resposta escrita antes de a escolha ser pedida), o estado da própria janela de tempo livre dele (Aberta, Ainda não aberta, Fechada ou Sem janela) e todo outro personagem, NPC, item e local conectado a essa trama, cada um um link. Clique numa linha para abrir direto o fio dessa trama, onde você pode escrever ou editar a resposta.

## Tarefas comuns

### Achar quem ainda não foi respondido

1. Abra Kit de Ferramentas do Narrador → Tempo Livre.
2. Escolha a data do jogo.
3. Deixe o filtro em **Não respondidas** - é o padrão.

### Responder a uma ação

1. Clique numa linha da fila.
2. Isso abre o fio da trama. Escreva a sua resposta ali - ela é retida por padrão (veja [Lançamentos](release-batches.md)) a menos que você a envie imediatamente de forma explícita.
3. Diga se a resposta custa uma ação ao personagem: escolha **Nenhuma ação cobrada**, ou **Cobrar uma ação**, depois o antecedente de onde ela é cobrada e quantas ações (em geral 1). **Publicar** fica desligado até você escolher. Uma cobrança acrescenta um uso aos [usos de antecedentes](background-uses.md) do personagem na data; apagar a resposta leva o uso junto. O fio mostra a sua escolha sob a resposta.

### Estender o prazo de um personagem

1. Ainda não há um botão para isso nesta tela - peça a um HST ou AST que o defina direto por `POST /sessions/{id}/downtime-extensions`, ou espere uma rodada futura acrescentar um aqui.

## O que saber

- **Uma janela só existe quando a sessão dela tem um horário de abertura, um prazo, ou os dois.** Uma data de jogo sem nenhum dos dois aparece como "Sem janela" e nada é imposto para ela - o mesmo comportamento de antes de este recurso existir.
- **A extensão de um personagem só substitui o prazo dele**, nunca o horário de abertura, e só para esse único personagem.
- **A janela nunca espera uma tarefa em segundo plano.** No instante em que um prazo passa, o formulário de ação desse personagem fecha - tenha alguém atualizado a página ou não.
- **Cada linha tem o seu próprio seletor de responsável** - quem cuida de acompanhar o tempo livre desse personagem. Atribuir a si mesmo é o que faz a linha aparecer em [Minha Fila](my-queue.md), e o filtro **Atribuído a** desta própria tela agora restringe direto a isso.
- Numa tela estreita cada linha se empilha num cartão em vez de rolar para o lado.

## Solução de problemas

- **"Falha ao carregar a fila de tempo livre."** Atualize e tente de novo.
- **"Ainda não existem sessões de jogo - crie uma em Noites de Jogo primeiro."** O tempo livre sempre está ligado à data de uma noite de jogo de verdade - agende uma primeiro.
- **Não vejo esta aba de jeito nenhum.** Você não tem acesso a Tramas e Rumores na crônica escolhida no momento.

## Relacionados

- [Noites de Jogo](game-nights.md)
- [Tramas e Rumores](plot-manager.md)
- [Lançamentos](release-batches.md)
- [Alocar Ações](allocate-actions.md)
- [Kit de Ferramentas do Narrador](storyteller-toolkit.md)
- [Minha Fila](my-queue.md)
- [Guia do Narrador](../st-guide.md#8-tramas-ações-e-rumores)
