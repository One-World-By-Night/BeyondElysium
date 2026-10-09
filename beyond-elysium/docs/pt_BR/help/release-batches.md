# Lançamentos

Programe rumores e respostas de tempo livre para saírem juntos, vários entre jogos, em vez de no instante em que um Narrador os escreve.

## Quem pode usar

Qualquer pessoa com acesso a Tramas e Rumores (HST, AST, Condutor de Trama). Um jogador nunca vê esta tela - ele só vê as tramas e postagens que ela lança, depois de lançadas.

## Como chegar lá

Kit de Ferramentas do Narrador → aba Lançamentos, ou wp-admin → Beyond Elysium → Tramas → aba Lançamentos.

## A tela

### Lista de lotes (a visão padrão)

- **+ Novo lote** - abre um formulário: um nome (obrigatório) e uma data/hora de lançamento opcional. Deixe a data em branco para salvá-lo como **rascunho**; defina uma e ele vira **agendado**.
- Três listas - **Agendado**, **Rascunho** e **Lançado** - cada uma mostrando o nome de um lote, o horário de lançamento ou um selo "Lançado", e quantos rumores e respostas de tempo livre ele contém. Clique em um para abri-lo.

### Agenda de lançamento

- Um painel recolhível acima da lista de lotes. Clique em **Agenda de lançamento (N)** para abri-lo.
- **+ Adicionar uma regra semanal** - dispara num dia da semana e horário que você escolhe (o padrão é sexta, 18:00).
- **+ Adicionar uma regra mensal** - dispara num dia do mês (1-28) e horário que você escolhe.
- Adicione quantas regras quiser, em qualquer combinação. Remova uma com o seu próprio botão **Remover**.
- A agenda controla *quando*, nunca *o quê*: no dia e horário que uma regra nomeia, todo lote que você deixou em **Rascunho** para esta crônica é lançado como está - o mesmo que clicar em **Lançar agora** em cada um você mesmo, só que automático. Ela nunca cria um lote para você. Se nada está parado em Rascunho quando uma regra dispara, nada acontece - nenhum lote vazio, nenhum e-mail, nenhum erro.
- Se mais de uma regra vence no mesmo dia, os seus lotes em rascunho ainda saem uma só vez cada - as regras não multiplicam nada.

### Um único lote (visão de detalhe)

- **Todos os lotes** - um link de volta às listas.
- **Excluir lote** (só rascunho) - remove o lote; os itens voltam a rascunho, ocultos até serem adicionados a outro lote.
- **Cancelar agendamento (voltar a rascunho)** (só agendado, enquanto ainda não venceu) - limpa o horário de lançamento e devolve o lote a rascunho.
- **Lançar agora** - lança o lote imediatamente, seja qual for o horário agendado. Pede confirmação antes: os jogadores alcançados pelos itens deste lote os verão e receberão um e-mail.
- **Rumores** e **Respostas de tempo livre** - as tramas e entradas retidas do lote. Cada linha pode ser movida para outro lote aberto, ou removida (devolvida a rascunho) com o seu próprio botão.

## Tarefas comuns

### Reter um rumor para um lançamento posterior

1. Abra a trama (rumor) que você quer reter e adicione-a a um lote a partir dali (veja [Tramas e Rumores](plot-manager.md)).
2. Abra Kit de Ferramentas do Narrador → Lançamentos e ache o lote a que você a adicionou.
3. Defina uma data/hora de lançamento, ou deixe como rascunho até estar pronto.

### Lançar tudo no horário

1. Crie um lote e defina a data/hora de lançamento dele - ele passa a **Agendado**.
2. Nada mais a fazer: no instante em que esse horário passa, todos que ele alcança podem vê-lo, e cada jogador recebe um e-mail de resumo nomeando os próprios personagens e quanto é novo. Isso acontece tenha alguém aberto o site nesse meio-tempo ou não.

### Lançar numa agenda recorrente sem uma data específica

1. Abra **Agenda de lançamento** e adicione uma regra semanal ou mensal (ou as duas).
2. Quando estiver pronto durante a semana, prepare um lote como **rascunho** - adicione rumores e respostas de tempo livre a ele, não defina uma data de lançamento.
3. No dia e horário que a regra nomeia, esse rascunho (e qualquer outro lote em rascunho desta crônica) é lançado automaticamente. Comece um novo rascunho para a próxima vez quando quiser.

### Lançar algo agora mesmo

1. Abra o lote (ou adicione antes o item a qualquer lote aberto) e clique em **Lançar agora**.
2. Confirme - os jogadores o verão e receberão um e-mail na hora.

### Mover um item para outro lote

1. Abra o lote que o contém no momento.
2. Use a lista suspensa ao lado do item para escolher outro lote aberto (ainda não lançado).

## O que saber

- **Um rascunho nunca é visível a um jogador**, seja o que for que o público dele diga - retido sem lote (ou num lote ainda não lançado) sempre quer dizer "ainda não".
- **A visibilidade nunca espera uma atualização de página nem uma tarefa agendada.** No instante em que o horário de lançamento de um lote passa, quem já pode ver aquele conteúdo pelo público dele o vê - tenha a varredura em segundo plano do site rodado ainda ou não.
- **Um e-mail por jogador por lote.** Um jogador com vários personagens alcançados pelo mesmo lote recebe um único e-mail nomeando todos, não um por personagem nem por item.
- **O e-mail não nomeia conteúdo.** Diz para quem é, de qual crônica e quanto é novo (rumores, respostas de tempo livre), com um link para Minhas Tramas e Rumores - nunca o rumor ou a resposta em si.
- **Lançado é definitivo.** Um lote lançado não pode ser excluído, ter o agendamento cancelado nem receber itens. Remover um item dele devolve esse item a rascunho (oculto de novo) - não desfaz o e-mail já enviado por ele.
- **Excluir ou cancelar o agendamento de um lote nunca exclui os rumores ou respostas dele** - só o lote em si. Os itens voltam a rascunho e podem ser adicionados a outro lote depois.
- Os segredos ainda não fazem parte desta tela - esse item não foi construído.

## Solução de problemas

- **"Um lote agendado precisa de uma data de lançamento (release_at)."** Escolha uma data/hora antes de passar um lote a Agendado, ou deixe-o como Rascunho.
- **"Este lote já está disponível e não pode mais ter seu agendamento cancelado."** O horário de lançamento dele já passou - os jogadores podem já tê-lo visto. Use **Lançar agora** na mesma agenda para terminar explicitamente de lançá-lo, ou deixe como está.
- **"Um lote lançado é definitivo e não pode mais ser alterado."** / **"...e não pode ser excluído."** Os lotes lançados são permanentes de propósito - remova itens individuais em vez disso, se algo precisa voltar a rascunho.
- **Não vejo esta aba de jeito nenhum.** Você não tem acesso a Tramas e Rumores na crônica escolhida no momento.

## Relacionados

- [Tramas e Rumores](plot-manager.md)
- [Kit de Ferramentas do Narrador](storyteller-toolkit.md)
- [Noites de Jogo](game-nights.md)
- [Papéis](roles.md)
- [Guia do Narrador](../st-guide.md#8-tramas-ações-e-rumores)
