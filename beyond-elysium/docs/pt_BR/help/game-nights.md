# Noites de Jogo

Agende as noites de jogo de uma crônica, registre quem apareceu e conceda o XP de presença depois que o elenco está resolvido.

## Quem pode usar

Os Narradores (HST e AST) e os Condutores de Trama - um Condutor de Trama muitas vezes cuida da porta num jogo, então esta aba está aberta a ele também, ao contrário da maior parte do Kit de Ferramentas do Narrador. Um jogador nunca vê esta tela.

## Como chegar lá

Kit de Ferramentas do Narrador → aba Noites de Jogo.

## A tela

### Lista de sessões (a visão padrão)

- **Destaque** - mostrado só a um HST ou AST. Alterna uma lista de todo personagem ativo com a última data de presença, a contagem de tramas ativas, a última postagem da equipe e se está **Sinalizado** por passar tempo demais sem uma. Veja **Destaque** abaixo.
- **Configurações da sessão** - XP de Presença, XP de Relatório e Dias de destaque, mostrados só a um HST ou AST. **Salvar configurações** grava; definem o valor *padrão* que **Conceder XP de presença**/**Conceder XP de relatório** oferecem abaixo, não um limite, e quantos dias sem uma postagem da equipe antes de um personagem ser sinalizado no Destaque.
- **+ Nova sessão** - abre um formulário: uma data (obrigatória, única nesta crônica), uma hora de início e um lugar. **Criar sessão** salva e abre direto a visão de detalhe dela; **Cancelar** fecha o formulário sem salvar.
- Uma lista de sessões, a agendada mais cedo primeiro, cada uma mostrando data, hora, lugar e um selo "XP concedido" e/ou "XP de relatório concedido" depois que um dos dois foi dado. Clique em uma para abri-la.

### Destaque

O perfil de atenção de cada personagem ativo que não é NPC, na crônica toda - não ligado a nenhuma sessão específica. Um personagem é **Sinalizado** quando nenhum Narrador ou Condutor de Trama postou nada além de uma nota privada em nenhuma das tramas dele (as rodadas de ação dele inclusive) dentro da configuração de Dias de destaque da própria crônica (42 por padrão) - ou nunca postou. Os personagens sinalizados vêm primeiro, depois todos os outros por quanto tempo faz desde a última postagem da equipe. Veja [Relatório Pós-Jogo](after-game-report.md) para a metade voltada ao jogador de manter um personagem atendido.

### Uma única sessão (visão de detalhe)

- **Todas as sessões** - um link de volta à lista.
- **Registro de Presença** - todo personagem ativo da crônica que não é NPC, com o nome do jogador e uma caixa de seleção; marque uma para registrar a presença desse personagem, desmarque para tirá-lo. Uma contagem corrida de quem está registrado no momento. Abaixo do elenco, adicione um visitante pelo nome (e, opcionalmente, a crônica de origem dele) que não seja um dos seus personagens - cada um aparece com um botão **Remover** próprio.
- **Conceder XP de presença** - concede o valor configurado a todos os registrados no momento, com um motivo que registra a data da sessão. Desativado depois de usado nesta sessão; **Conceder novamente mesmo assim** aparece depois se você realmente precisa rodar uma segunda vez.
- **Excluir sessão** - só permitido enquanto a sessão não tem presença, escalação de NPC nem relatório pós-jogo registrados; depois que qualquer um existe, mude a data dela em vez de excluí-la.
- **Relatórios**, mostrado só a um Narrador com acesso ao gerenciamento de Personagens - todo relatório pós-jogo enviado para esta sessão, cada um com um botão **Marcar como lido** (ou um selo **Lido** depois que você marcou) e **Conceder XP de relatório**, que funciona exatamente como o XP de Presença, só que ligado a quem enviou um relatório e não a quem se registrou. Você nunca edita as palavras de um relatório - veja [Relatório Pós-Jogo](after-game-report.md).
- **Escalar NPCs**, mostrado só a um Narrador com acesso ao gerenciamento de Personagens - escale qualquer membro da crônica (não só a equipe) para interpretar qualquer NPC nesta sessão, com uma nota opcional só para este jogo. Cada escalação lista quem interpreta o quê, um link **Imprimir escalação** e um botão **Remover**. Veja [Ficha de Escalação de NPC](npc-casting.md) para o que o membro escalado lê.
- **Resumo** - só para Narradores; os jogadores nunca o veem. Eventos principais, decisões dos jogadores, uma lista repetível de NPCs envolvidos (cada um com um status vivo/ferido/morto/desconhecido), um suspense final e o preparo para a próxima vez, com o seu próprio **Salvar resumo**. Um botão **Rascunhar resumo**, desativado quando todos os campos já têm algo, pede a uma IA que preencha o que ainda está vazio a partir da presença e dos relatórios pós-jogo desta própria sessão - revise antes de salvar, igual a toda outra ferramenta de rascunho.

## Tarefas comuns

### Agendar uma noite de jogo

1. Abra Kit de Ferramentas do Narrador → Noites de Jogo.
2. Clique em **+ Nova sessão**.
3. Escolha uma data e, se quiser, uma hora e um lugar.
4. Clique em **Criar sessão**.

### Registrar quem apareceu

1. Abra a sessão de hoje à noite (crie uma primeiro se ainda não existe).
2. Marque cada personagem presente em **Registro de Presença**.
3. Adicione pelo nome quem visita de outra crônica.

### Conceder o XP de presença

1. Abra a sessão, com todos que compareceram já registrados.
2. Clique em **Conceder XP de presença**.

### Ler os relatórios pós-jogo e conceder o XP de relatório

1. Abra a sessão.
2. Em **Relatórios**, clique em **Marcar como lido** em cada um enquanto lê.
3. Quando todos que vão enviar um já enviaram, clique em **Conceder XP de relatório**.

### Conferir quem precisa de atenção

1. Abra Kit de Ferramentas do Narrador → Noites de Jogo, sem nenhuma sessão escolhida.
2. Clique em **Destaque**.
3. Comece pelos personagens **Sinalizados** no topo.

### Escrever o resumo de uma sessão

1. Abra a sessão.
2. Preencha você mesmo os campos de **Resumo**, ou clique antes em **Rascunhar resumo** e revise o que voltar antes de salvar.
3. Clique em **Salvar resumo**.

### Mudar a data de uma noite de jogo

1. Exclua a sessão errada, se ninguém foi registrado ainda, e crie uma nova com a data certa - ainda não há um formulário de "editar" à parte nesta tela.

## O que saber

- **Uma sessão por data.** Uma crônica não pode ter duas sessões na mesma data do calendário; criar uma segunda numa data existente é recusado.
- **Uma sessão com qualquer presença, escalação de NPC ou relatório pós-jogo não pode ser excluída.** Isso protege o registro depois que existem dados reais contra ela - mude a data dela em vez disso.
- **O XP de Presença e o XP de Relatório são, cada um, uma ação única por sessão**, não um prêmio recorrente - os dois são protegidos contra serem dados duas vezes por engano, com uma sobreposição explícita se você realmente quer.
- **Uma postagem da equipe na trama de rodada de ação de um personagem ainda conta para o Destaque** - o desenho de propósito não as exclui do jeito que **Tramas Ativas** faz em outro lugar; um Narrador que responde a uma ação de tempo livre é atenção de verdade.
- **O Destaque e a contagem de "Personagens Precisando de Atenção" do painel são sempre o mesmo número** - os dois são calculados do jeito idêntico, então nenhum pode se afastar em silêncio do outro.
- **Um visitante não é um personagem.** Registrar um só acompanha que ele compareceu - não cria personagem, conexão nem prêmio de XP.
- **As configurações da sessão valem para a crônica toda**, não por sessão - mudar o XP de Presença depois que uma sessão já existe só afeta os prêmios feitos daí em diante.
- Numa tela estreita a lista de sessões se empilha em cartões de coluna única em vez de rolar para o lado.

## Solução de problemas

- **"Falha ao carregar as sessões de jogo."** Atualize e tente de novo.
- **"Já existe uma sessão nesta data."** Abra essa sessão em vez de criar uma nova.
- **"Esta sessão já tem presença, uma escalação de NPC ou um relatório registrado - altere a data em vez de excluí-la."** Editar a data em si ainda não está disponível; se a data está mesmo errada, peça a um administrador que a corrija direto.
- **"O XP de presença já foi concedido para esta sessão." / "O XP de relatório já foi concedido para esta sessão."** Use **Conceder novamente mesmo assim** se você quer deliberadamente rodar uma segunda vez.
- **"Falha ao carregar a verificação de destaque."** Atualize e tente de novo.
- **"Falha ao salvar o resumo."** Tente **Salvar resumo** de novo.
- **Não vejo esta aba de jeito nenhum.** Você não tem um papel de Narrador ou de Condutor de Trama na crônica escolhida no momento - troque de crônica, ou peça a um HST/AST que confira o seu papel.

## Relacionados

- [Kit de Ferramentas do Narrador](storyteller-toolkit.md)
- [Tramas e Rumores](plot-manager.md)
- [Lançamentos](release-batches.md)
- [Tempo Livre](downtime-queue.md)
- [Configurações de Ação e Rumor](apr-settings.md)
- [Ficha de Escalação de NPC](npc-casting.md)
- [Relatório Pós-Jogo](after-game-report.md)
- [Assistência de Escrita por IA](writing-assist.md)
- [Papéis](roles.md)
- [Guia do Narrador](../st-guide.md#8-tramas-ações-e-rumores)
