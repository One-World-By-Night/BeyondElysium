# Como Funciona a Aprovação

Como uma mudança que você envia vai de uma edição na sua tela a algo real na ficha: pendente, depois aprovada ou rejeitada, com as regras que decidem quais mudanças precisam de um Narrador, afinal.

## Quem pode usar

Todas as mudanças enviadas de todo jogador passam por isto. Revisar uma - aprovar ou rejeitar - é trabalho de um Narrador (HST e AST); ninguém mais revisa uma mudança. O que de fato precisa de revisão, e o que não precisa, é definido por um Narrador (em Regras de Aprovação ou direto num bloco de esquema) ou por um administrador do site (o padrão geral da própria crônica).

## Como chegar lá

Esta não é uma tela própria. Você envia uma mudança pelo [Editor de Personagem](character-editor.md); um Narrador a revisa na [Fila de Aprovação](approval-queue.md); o que precisa de revisão, para começar, é definido em [Regras de Aprovação](approval-rules.md).

## A tela

**Só dois resultados.** Uma mudança enviada ou é **aprovada automaticamente** - aplicada à ficha no instante em que você envia, sem nada a esperar - ou fica **pendente**, na Fila de Aprovação até um Narrador aprovar ou rejeitar. Não há uma etapa de coordenador à parte: o motivo de uma regra pode dizer para obter antes a aprovação de um coordenador, mas o clique que de fato aprova uma mudança é sempre de um Narrador.

**O que decide qual dos dois você recebe.** Uma regra pode viver em vários níveis de detalhe - uma faixa específica de valores num item ou reserva, uma opção num campo de identidade, um nível de um poder em níveis, um item inteiro ou poder inteiro, ou o padrão do bloco inteiro do catálogo. Vale o que for mais específico para o que você está de fato enviando, recuando um nível por vez até algo ter uma opinião; se nada em lugar nenhum tem, o padrão geral da própria crônica (Pendente por padrão, ou Aprovar automaticamente por padrão) decide. Uma regra específica sempre vence esse padrão, nos dois sentidos - passar uma crônica para a aprovação automática nunca deixa passar em silêncio algo que um Narrador marcou para revisão, nem mesmo uma regra sem texto de motivo.

**Uma entrada personalizada sempre precisa de um Narrador.** Digitar um nome que não está no catálogo - onde a seção sequer permite - sempre vai a um Narrador, não importa como as regras da sua crônica estejam definidas. Não há preço de catálogo contra o qual conferi-la, então nada pode aprová-la automaticamente. Ela também não tem preço próprio: o Narrador que a aprova define um, e até lá a sua prévia diz "Preço definido por um Narrador na aprovação" em vez de mostrar um custo. Não é de graça - só ainda não foi precificada.

**Poderes numerados precificam todo degrau.** Uma compra nova no nível 3 é precificada pelos níveis 1, 2 e 3 juntos, não só pelo nível 3 - vale a mudança acabe aprovada automaticamente ou pendente.

**O XP é cobrado na aprovação, não no envio.** Nada sai da sua experiência não gasta até a mudança de fato se aplicar à ficha - na hora, para algo que se aprova sozinho, ou quando um Narrador clicar em Aprovar. Rejeitar uma mudança não custa nada e deixa a ficha intocada. Os prêmios de XP do próprio Narrador costumam se aplicar na hora, já que carregam a autoridade de um Narrador. Para produção caseira, o valor é o que o Narrador define quando a aprova.

**A fila fica presa ao que um Narrador de fato viu.** Se você reenviar uma mudança que ainda está pendente, ou outra pessoa a revisar primeiro, o clique de Aprovar ou Rejeitar de um Narrador na versão antiga é recusado e a fila dele recarrega com o que realmente está lá - um Narrador nunca pode aprovar algo diferente do que viu.

## Tarefas comuns

### Conferir se a sua mudança ainda está esperando

1. Abra [Minha Crônica](my-chronicle.md) → **Painel** → **Minhas Alterações**.
2. Ainda listada ali quer dizer que ainda espera. Não listada, mas também não na ficha? Confira o [Histórico de Alterações](sheet-history.md) desse personagem para ver se foi aprovada ou rejeitada.

### Ver por que uma mudança específica precisa de revisão (Narrador)

1. Abra a [Fila de Aprovação](approval-queue.md).
2. Leia a coluna **Motivo da Aprovação** dessa linha (ou, no celular, abra **Detalhes**).

### Definir o que precisa de revisão na sua crônica (Narrador)

1. Abra [Regras de Aprovação](approval-rules.md), ou edite o item, poder, reserva ou campo direto em [Blocos de Esquema](schema-blocks.md).
2. Escolha o bloco e depois o item, poder, nível, faixa de valores, reserva ou opção de campo específico.
3. Escolha um nível de aprovação e, se quiser, um motivo.
4. Salvar.

### Mudar o que acontece quando nenhuma regra específica se aplica

1. Abra [Regras de Aprovação](approval-rules.md).
2. Em **Política de Aprovação Padrão**, escolha **Pendente por padrão** ou **Aprovar automaticamente por padrão**.

## O que saber

- **Só dois níveis: `auto` e `st`.** Não há um nível de coordenador à parte - um motivo que cita a aprovação de um coordenador continua sendo o clique do próprio Narrador.
- **A regra mais específica sempre vence, nos dois sentidos.** Uma regra estreita definida como auto ainda se aplica mesmo numa crônica definida como Pendente por padrão, e uma regra estreita definida como revisão do Narrador nunca é pulada em silêncio só porque a crônica tem a aprovação automática como padrão.
- **A regra de uma reserva de recurso confere só a classificação permanente.** Gastar ou recuperar pontos durante o jogo nunca dispara revisão - só um aumento permanente, pago com XP, pode.
- **A regra de um campo de identidade confere todo valor que você escolhe.** Para um campo que permite mais de uma escolha, vale a exigência mais rígida entre as suas escolhas.
- **Aprovar vários de uma vez (Aprovar Selecionados) é o mesmo clique, repetido.** Aplica a mesma aprovação a toda mudança que você marcou - não é um mecanismo em lote à parte com regras próprias. A única exceção é a produção caseira que ainda precisa de um preço: ela não pode ser aprovada em lote, porque ninguém disse quanto custa.
- **Nem tudo passa por esta fila.** Algumas coisas que um Narrador faz direto - um prêmio de XP, por exemplo - são registradas como já decididas, já que a própria ação de um Narrador já carrega essa autoridade.

## Solução de problemas

- **A minha mudança não apareceu na ficha.** Provavelmente está pendente da revisão do Narrador - confira [Minha Crônica](my-chronicle.md) → Painel → Minhas Alterações, ou o [Histórico de Alterações](sheet-history.md) do personagem.
- **Enviar Alterações não clica.** Como jogador, enviar deixaria você com XP negativo - reduza o que está comprando, ou peça mais a um Narrador, primeiro.
- **Um Narrador diz que nada aconteceu quando clicou em Aprovar.** Outra pessoa já a revisou, ou você a mudou depois que a fila carregou - a fila recarrega com o que realmente está lá agora.
- **Uma regra parece não estar se aplicando.** Confira se ela trata o valor que de fato está sendo alcançado (nunca uma mudança de antes) e se nada mais específico a sobrepõe.

## Relacionados

- [Fila de Aprovação](approval-queue.md)
- [Regras de Aprovação](approval-rules.md)
- [Editor de Personagem](character-editor.md)
- [Histórico de Alterações](sheet-history.md)
- [Painel](player-dashboard.md)
- [Blocos de Esquema](schema-blocks.md)
- [Papéis](roles.md)
- [Guia do Narrador: Conduzindo a Fila de Aprovação](../st-guide.md#4-conduzindo-a-fila-de-aprovação)
- [Guia do Administrador: Regras de Aprovação](../admin-guide.md#regras-de-aprovação)
