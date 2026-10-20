# Fila de Aprovação

Toda mudança de ficha pendente dos personagens da crônica, em uma só lista, onde um Narrador aprova ou rejeita cada uma antes de ela valer.

## Quem pode usar

Só os Narradores (HST e AST). Um Condutor de Trama ou a Harpia da crônica não vê esta aba, embora usem outras partes do Kit de Ferramentas do Narrador para a sua própria área. Um jogador nunca vê esta tela - as mudanças pendentes dele aparecem, sem revisão, no próprio Painel.

## Como chegar lá

Kit de Ferramentas do Narrador → aba Fila de Aprovação.

## A tela

Uma linha acima dos filtros leva à página Importar sempre que uma transferência ou um arquivo do Grapevine enviado por um jogador espera revisão lá - esta página não mostra nenhum dos dois. Veja [Importar](import.md).

### Filtros

- **Personagem** - "Todos os personagens", ou qualquer um dos personagens desta crônica.
- **Tipo de alteração** - "Todos os tipos de alteração", ou um destes: `add_trait` (um traço, poder ou item novo), `remove_trait` (um tirado da ficha), `modify_trait` (a contagem de um traço existente ou o nível de um poder), `modify_resource` (a classificação permanente ou temporária de uma reserva), `modify_identity` (um campo como Clã ou Natureza), `xp_earn` (experiência concedida), `xp_adjust` (uma correção de experiência feita por um Narrador), `import_note` (uma nota registrada automaticamente por uma importação), `log_knowledge` (a afirmação de um jogador sobre o que o personagem aprendeu, esperando ser ligada a um segredo) ou `pass_secret` (um jogador contando a outro personagem um segredo que sabe).
- **Nível de aprovação** - "Todos os níveis de aprovação", `auto` ou `st`. A lista, as páginas e a contagem total só consideram esse nível.

Mudar qualquer filtro recomeça da primeira página, sem nada marcado.

### Aprovar Selecionados

Um botão que diz quantas mudanças você marcou no momento - **Aprovar Selecionados (N)** - ativado quando pelo menos uma está marcada.

### A lista

Uma tabela (empilhada em cartões numa tela estreita) com uma caixa de seleção por linha, depois:

| Coluna | Mostra |
| --- | --- |
| Personagem | Em quem é a mudança |
| Alteração | Uma descrição simples, como o valor antigo e o novo de um traço. O [pedido de XP](character-editor.md#solicitar-xp) de um jogador diz "+N XP (Solicitado: onde, data)", com quaisquer detalhes que ele acrescentou mostrados embaixo. Uma mudança encaminhada para cá por uma crônica anfitriã mostra **"De Boston"** (ou a crônica que a enviou) acima da descrição, e a nota de texto livre da própria anfitriã, se houver, embaixo - veja [Uma mudança encaminhada de uma crônica anfitriã](#uma-mudança-encaminhada-de-uma-crônica-anfitriã). Abaixo disso: a nota que o jogador pôs no traço ("Nota: R1 Necromancy ( 4 xp)"), a seção para onde a entrada vai ("Em Rituais") e, depois que um Narrador a edita, "Editada por" e o nome dele |
| XP | O custo, positivo ou negativo. Uma compra caseira mostra **Precisa de um preço** até você definir um |
| Nível | `auto` ou `st` |
| Motivo da Aprovação | Por que parou nesse nível, quando há um. Um motivo vindo de um estatuto termina com uma citação de cláusula clicável, por exemplo `[OWBN Character Bylaws 10.e.v, clause 7838]`, que abre a cláusula de verdade em council.owbn.net; vários motivos numa mudança ficam cada um na própria linha |
| Enviado por | Quem enviou |
| Quando | Quando foi enviada |
| Ações | Aprovar / Rejeitar / Editar |

No celular, Nível, Enviado por e Quando ficam atrás de um **Detalhes** em cada cartão; Personagem, Alteração, XP e Ações continuam visíveis no topo.

### Ações

- **Aprovar** - aplica a mudança na hora.
- **Editar** - abre uma janela onde você altera a entrada, dá um preço, a coloca em outra seção e deixa uma nota para o jogador. Veja [Editar uma mudança](#editar-uma-mudança). Ela nunca aprova nada.
- **Uma caixa de preço**, numa mudança avulsa que precisa de uma - veja [Uma mudança que precisa de um preço](#uma-mudança-que-precisa-de-um-preço).
- **Rejeitar** - abre uma caixa de texto ("Motivo (obrigatório)") com **Confirmar Rejeição** e **Cancelar**. Um motivo é obrigatório; aprovar não precisa de nenhum.

### Editar uma mudança

**Editar** aparece em toda mudança que espera: uma avulsa, cada mudança de um conjunto enviado em conjunto, com preço ou sem, seja qual for o tipo. Abre uma janela com a mudança no topo e estas caixas:

- **Seção** - numa entrada que o livro não tem. Lista as seções que aceitam o mesmo tipo de entrada, então um "Fettered Minion" digitado em Rituais pode ser colocado em Disciplinas Combo, ou em Qualidades, Defeitos, Habilidades e assim por diante. Escolher outra seção move a entrada. Ela é conferida ali como uma sua: um nome que o catálogo da nova seção tem vira essa entrada, pelo preço do livro, e qualquer outro nome continua sendo uma entrada do próprio jogador na nova seção, esperando um preço. Uma mudança de seção é salva sozinha, então a caixa de preço fica cinza até você salvá-la.
- **As caixas da própria mudança** - Nome, Pontos, Especialização e Nota num traço, Nível num poder, a classificação numa reserva, o novo valor numa mudança de identidade, Quantidade e Motivo numa mudança de experiência. Só aparecem as caixas que a mudança realmente tem.
- **Uma caixa de preço**, numa compra - veja [Definir um preço](#definir-um-preço).
- **Nota para o jogador** - texto simples. O jogador a lê no histórico de mudanças dele, sob "Alterada por um Narrador". O texto entre `[ST]` e `[/ST]` é cortado do que ele vê, então serve para um recado ao próximo Narrador.

**Salvar** grava a sua edição e deixa a mudança esperando. Você ainda a decide com **Aprovar**, **Aprovar tudo** ou **Aprovar Selecionados**, e nada é enviado ao jogador até você decidir. Salvar dá à mudança uma linha nova "Editada por" e um novo código de revisão, então um Narrador que abriu a versão antiga é avisado para recarregar.

Um salvamento é recusado se deixaria esta mudança fazendo o mesmo trabalho que outra mudança pendente do personagem, ou se o jogador a reenviou enquanto você digitava.

### Definir um preço

A caixa de preço na janela depende de o livro já ter preço para a entrada.

- **Uma entrada para a qual o livro não tem preço.** A caixa diz **XP por ponto** para um traço contado em pontos, e **XP** para uma compra única (uma Qualidade, Defeito, Ritual ou Disciplina Combo) ou um poder, e mostra o total enquanto você digita. Deixe vazia e a mudança continua esperando um preço. Digite um número, Salve, e ela deixa de esperar: o preço é gravado na entrada, a mudança pode ser marcada para **Aprovar Selecionados**, e passa por **Aprovar tudo** com o resto do conjunto. O número de uma Disciplina Combo é o preço dela, então uma de preço 4 aparece como "(4 XP)" na ficha.
- **Uma entrada para a qual o livro já tem preço.** A caixa diz **XP para esta alteração** e começa vazia, com o preço de costume como dica ("Preço de costume: 6 XP"). Deixe vazia para cobrar esse preço. Digite um número para cobrar esse no lugar, só para esta mudança; nada é gravado na entrada, então uma subida depois é precificada pelo livro de novo.

Nos dois casos o número é inteiro, de 0 a 500, e 0 vale. Numa lista que dá pontos em vez de custá-los, como os Defeitos, o valor é registrado e nada sai do XP do jogador.

### Uma mudança que precisa de um preço

Um traço ou poder que não está no catálogo não tem preço, então a fila não inventa um. Ela mostra **Precisa de um preço** na coluna XP e, numa mudança avulsa, uma caixa ao lado de Aprovar para o que custa. Para um traço contado em pontos, como uma Habilidade ou um Antecedente, a caixa diz **XP por ponto** e mostra o total enquanto você digita, como "× 3 pontos = 6 XP". Para uma compra única, como uma Qualidade, Defeito, Ritual ou Disciplina Combo, e para um poder, diz **XP**: um preço para a compra inteira. Uma mudança de um conjunto não tem caixa ao lado; dê o preço com **Editar**.

- **Aprovar fica cinza até a caixa ter um número inteiro de 0 a 500.** 0 conta: às vezes de graça é a resposta, e digitá-lo diz isso de propósito.
- **A linha não pode ser marcada para um lote.** A caixa de seleção dela está desativada. Aprovar Selecionados deixa essa mudança em paz e diz quantas deixou.
- **O preço vai para a ficha.** A linha é salva com o que você definiu, então a [Auditoria de Pontos](point-audit.md) lê de volta exatamente o que foi cobrado.
- **Um defeito é registrado, não deduzido.** Numa seção que dá pontos em vez de custá-los, o total aparece como registrado, e nada sai do XP do jogador.
- **Subir um traço caseiro depois.** Uma linha que já tem um preço sobe nesse preço, sem pergunta. Uma linha que nunca teve um pergunta de novo, e o preço que você define cobre só os pontos novos.
- **Vínculos e Guanxi nunca perguntam.** Eles nunca são comprados com XP, então adicionar ou subir um não mostra caixa de preço. Ele ainda espera a sua aprovação.

### Uma afirmação de conhecimento registrada

Uma mudança `log_knowledge` é um pedido do próprio jogador, ainda não um segredo - aprová-la exige que você diga qual é. A linha mostra um painel com duas escolhas:

- **Vincular a um segredo existente** - busque pelo título e escolha um dos resultados.
- **Criar um novo segredo** - escolha a que ele se liga (trama, item, local ou NPC) e o id de verdade, depois um título e um texto, preenchidos de antemão com o que o jogador digitou.

**Aprovar fica cinza até uma escolha estar completa.** A linha não pode ser marcada para um lote - aprove ou rejeite-a sozinha, como uma mudança que espera um preço. Aprovar grava uma revelação aprovada para o personagem que registrou, levando o como e o narrador que ele nomeou; rejeitar não grava nada.

### Uma mudança encaminhada de uma crônica anfitriã

Um personagem com uma visita aberta e combinada, mantida atualizada, em outro lugar só é editável em casa - qualquer mudança enviada por um Narrador ou prêmio de XP feito na cópia da própria anfitriã, e qualquer nota de texto livre que os Narradores da anfitriã compartilham, chega aqui como uma mudança pendente comum em vez de se aplicar sozinha. A linha traz o nome da própria crônica anfitriã como rótulo acima da descrição e, para uma nota compartilhada, o texto da nota embaixo; a linha em si é tingida para se distinguir de uma mudança que um jogador enviou direto.

- **`visit_pairing`** - uma anfitriã pedindo para parear um personagem enviado por um jogador com o lar verdadeiro dele, e não uma mudança de ficha. Aprová-lo abre uma visita nova entre as duas crônicas, mantida atualizada desde o início; recusá-lo, ou 60 dias sem resposta, deixa a cópia da própria anfitriã sem par.
- **`visit_note`** - a nota de texto livre da própria anfitriã sobre a visita, sem efeito na ficha de um jeito ou de outro. Aprová-la arquiva a nota como só para Narradores na trama do próprio personagem; recusá-la não registra nada.
- Aprovar ou recusar uma mudança encaminhada funciona exatamente como qualquer outra linha - **Aprovar**/**Rejeitar**, ou como parte de um lote onde nada na linha o impede.

### Enviadas em conjunto

Duas ou mais mudanças que um jogador envia num só salvamento do editor formam um conjunto: chegam juntas e são revisadas juntas. Com a chave de remoção/redução da crônica ligada (Regras de Aprovação), um salvamento que remove algo, baixa uma classificação, ou muda o rótulo ou o nome de uma entrada é pego, e o conjunto inteiro espera um Narrador, seja o que for que qualquer regra dos traços individuais diria de outro modo. A fila mostra um conjunto como uma única linha de aviso acima das mudanças dele, **"Enviado em conjunto (N mudanças) - líquido ±N XP"**, com **Aprovar tudo** e **Recusar tudo** no lugar de uma caixa de seleção e de botões por mudança em cada linha - essas linhas mostram "Parte de um conjunto enviado em conjunto" e um botão **Editar** no lugar.

- **Aprovar tudo** aplica toda mudança do conjunto num só pedido: remoções e classificações menores primeiro, depois o resto, para nada parcial chegar se uma falhar. Fica cinza enquanto alguma mudança do conjunto ainda precisa de um preço, com uma linha dizendo isso: clique em **Editar** em cada uma marcada **Precisa de um preço**, digite o que custa, Salve, e Aprovar tudo acende.
- **Um conjunto passa inteiro ou não passa.** Se uma mudança dele não pode ser aprovada daqui (precisa de um preço, de um segredo escolhido, ou já foi decidida ou editada), nenhuma é, e a fila diz quantas ficaram retidas com o conjunto.
- **Recusar tudo** pede um motivo compartilhado e rejeita toda mudança do conjunto; nada nele chega à ficha.
- **Um grupo não pode ser dividido.** As linhas dele não podem ser marcadas individualmente para Aprovar Selecionados - aprove ou recuse o conjunto inteiro junto.

### Paginação

**Anterior** / **Próximo**, com a página atual e a contagem total de pendentes.

## Tarefas comuns

### Aprovar uma única mudança

1. Abra Kit de Ferramentas do Narrador → Fila de Aprovação.
2. Ache a mudança - filtre por Personagem ou Tipo de alteração se a lista for longa.
3. Clique em **Aprovar**.

### Rejeitar uma mudança

1. Ache a mudança.
2. Clique em **Rejeitar**.
3. Digite um motivo.
4. Clique em **Confirmar Rejeição**.

### Aprovar várias mudanças de uma vez

1. Marque a caixa de cada mudança que você quer aprovar.
2. Clique em **Aprovar Selecionados (N)**.

Uma mudança que precisa de um preço não pode ser marcada. Dê o preço e aprove na própria linha.

### Dar preço a uma compra caseira

1. Ache a linha marcada **Precisa de um preço**.
2. Numa mudança avulsa, digite o que custa na caixa ao lado de Aprovar - por ponto para um traço contado em pontos, o valor inteiro para uma compra única ou um poder. Numa mudança de um conjunto, clique em **Editar** e use a caixa de preço dali.
3. Confira o total ao lado da caixa.
4. Clique em **Aprovar** ou, na janela, em **Salvar** e depois aprove a mudança ou o conjunto.

### Editar uma mudança

1. Clique em **Editar** na mudança.
2. Altere o que precisa - um nome, os pontos, o preço, a nota para o jogador.
3. Clique em **Salvar**. A mudança continua na fila, esperando por você.

### Colocar uma entrada na seção certa

1. Clique em **Editar** na entrada. Se ela pode mudar de lugar, a janela tem uma lista **Seção**.
2. Escolha a seção a que ela pertence, como Disciplinas Combo em vez de Rituais.
3. Clique em **Salvar**. Depois abra **Editar** de novo para dar o preço, já que o preço depende da nova seção.

### Deixar uma nota para o jogador

1. Clique em **Editar** e digite em **Nota para o jogador**.
2. Clique em **Salvar**. O jogador a vê no histórico de mudanças dele, e ela continua lá quando você aprova a mudança.

### Achar as mudanças pendentes de um personagem

1. Escolha esse personagem no filtro **Personagem**.

### Ver por que uma mudança precisa da sua revisão

1. Leia a coluna **Motivo da Aprovação** dessa linha (ou, no celular, abra **Detalhes**).

## O que saber

- **Só dois níveis de aprovação: `auto` e `st`.** O Beyond Elysium não tem uma etapa de coordenador à parte. O motivo de uma regra pode dizer para você obter a aprovação de um coordenador antes de aprovar, mas o clique que aprova aqui é sempre de um Narrador.
- **Aprovar Selecionados é um atalho, não um mecanismo diferente.** Aplica a mesma aprovação, individualmente, a toda mudança que você marcou - não uma sobreposição em lote.
- **A fila fica presa ao que lhe foi mostrado.** Se um jogador reenvia uma mudança pendente, ou outra pessoa a revisa primeiro, o seu clique é recusado e a fila recarrega com o que realmente está lá agora - você nunca aprova algo diferente do que viu.
- **Uma linha marcada continua marcada ao virar a página**, e Aprovar Selecionados age em tudo o que você marcou, não só no que está na tela - cada uma como você a viu quando a marcou. Uma mudança editada depois de você marcá-la é pulada, seja em que página estiver. Mudar um filtro limpa as marcas.
- **Editar não é decidir.** Salvar deixa a mudança esperando. Aprovar depois de uma edição mantém a nota que você salvou, e uma rejeição a substitui pelo motivo que você der.
- **Poderes numerados se somam.** Uma compra nova no nível 3 é precificada pelos níveis 1, 2 e 3 juntos, não só pelo nível 3 - a coluna XP reflete isso.
- **Nem tudo cai aqui.** Alguns prêmios de XP e tudo o que as regras da sua crônica marcam como aprovado automaticamente se aplicam no instante em que são enviados e nunca aparecem nesta fila.

## Solução de problemas

- **Nada aconteceu quando cliquei em Aprovar, e a linha sumiu.** Outra pessoa já a revisou, ou o jogador a mudou depois que a fila carregou. A fila recarrega sozinha - confira o estado atual do personagem antes de revisar de novo.
- **Confirmar Rejeição não clica.** Digite um motivo primeiro - rejeitar exige um.
- **Uma aprovação em lote pulou algumas mudanças.** Elas já tinham sido revisadas, ou editadas, desde que a fila carregou. Recarregue e revise-as individualmente.
- **Um lote disse que algumas mudanças precisam de um preço.** São produções caseiras ainda sem preço. Abra cada uma, digite o que custa e aprove-a sozinha.
- **Aprovar está cinza numa linha.** Ela precisa de um preço. Digite um número inteiro de 0 a 500 na caixa ao lado.
- **Aprovar tudo está cinza.** Uma mudança do conjunto ainda precisa de um preço. Clique em **Editar** em cada mudança marcada **Precisa de um preço**, digite o que custa e Salve.
- **Salvar diz que a mudança foi editada depois que abri.** O jogador a reenviou, ou outro Narrador a editou primeiro. A fila recarrega; abra **Editar** de novo.
- **Salvar diz que a mudança agora tem outro preço.** Trocar o nome ou a seção pode mudar o que o preço significa - por ponto, por escolha, ou um preço que o livro já tem. Abra **Editar** de novo, confira a caixa de preço e Salve.
- **Salvar diz que outra mudança pendente já cobre isto.** O jogador tem uma segunda mudança pendente para o mesmo traço ou campo. Revise ou recuse essa primeiro.
- **A caixa de preço está cinza na janela.** Você escolheu outra seção. Salve a mudança de seção primeiro e abra **Editar** de novo para definir o preço.
- **Não vejo esta aba de jeito nenhum.** Você não tem um papel de Narrador na crônica escolhida no momento - troque de crônica, ou peça a um HST/AST que confira o seu papel.

## Relacionados

- [Importar](import.md)
- [Enviar Ficha](transfer.md)
- [Editor de Personagem](character-editor.md)
- [Ficha de Personagem](character-sheet.md)
- [Regras de Aprovação](approval-rules.md)
- [Como Funciona a Aprovação](approval-flow.md)
- [Segredos](secrets.md)
- [Papéis](roles.md)
- [Guia do Narrador](../st-guide.md#4-conduzindo-a-fila-de-aprovação)
