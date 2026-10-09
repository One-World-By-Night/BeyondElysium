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
| Alteração | Uma descrição simples, como o valor antigo e o novo de um traço. O [pedido de XP](character-editor.md#solicitar-xp) de um jogador diz "+N XP (Solicitado: onde, data)", com quaisquer detalhes que ele acrescentou mostrados embaixo. Uma mudança encaminhada para cá por uma crônica anfitriã mostra **"De Boston"** (ou a crônica que a enviou) acima da descrição, e a nota de texto livre da própria anfitriã, se houver, embaixo - veja [Uma mudança encaminhada de uma crônica anfitriã](#uma-mudança-encaminhada-de-uma-crônica-anfitriã) |
| XP | O custo, positivo ou negativo. Uma compra caseira mostra **Precisa de um preço** até você definir um |
| Nível | `auto` ou `st` |
| Motivo da Aprovação | Por que parou nesse nível, quando há um. Um motivo vindo de um estatuto termina com uma citação de cláusula clicável, por exemplo `[OWBN Character Bylaws 10.e.v, clause 7838]`, que abre a cláusula de verdade em council.owbn.net; vários motivos numa mudança ficam cada um na própria linha |
| Enviado por | Quem enviou |
| Quando | Quando foi enviada |
| Ações | Aprovar / Rejeitar |

No celular, Nível, Enviado por e Quando ficam atrás de um **Detalhes** em cada cartão; Personagem, Alteração, XP e Ações continuam visíveis no topo.

### Ações

- **Aprovar** - aplica a mudança na hora.
- **Uma caixa de preço**, numa mudança que precisa de uma - veja [Uma mudança que precisa de um preço](#uma-mudança-que-precisa-de-um-preço).
- **Rejeitar** - abre uma caixa de texto ("Motivo (obrigatório)") com **Confirmar Rejeição** e **Cancelar**. Um motivo é obrigatório; aprovar não precisa de nenhum.

### Uma mudança que precisa de um preço

Um traço ou poder que não está no catálogo não tem preço, então a fila não inventa um. Ela mostra **Precisa de um preço** na coluna XP e uma caixa ao lado de Aprovar para o que custa. Para um traço contado em pontos, como uma Habilidade ou um Antecedente, a caixa diz **XP por ponto** e mostra o total enquanto você digita, como "× 3 pontos = 6 XP". Para uma compra única, como uma Qualidade, Defeito ou Ritual, e para um poder, diz **XP**: um preço para a compra inteira.

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

Com a chave de remoção/redução da crônica ligada (Regras de Aprovação), o conjunto do editor de um jogador que foi pego - uma remoção, uma classificação menor, um novo rótulo ou uma troca de nome - é enviado como um grupo e espera junto, seja o que for que qualquer regra dos traços individuais diria de outro modo. A fila o mostra como uma única linha de aviso acima das mudanças dele, **"Enviado em conjunto (N mudanças) - líquido ±N XP"**, com **Aprovar tudo** e **Recusar tudo** no lugar de uma caixa de seleção e de botões por mudança em cada linha - essas linhas mostram "Parte de um conjunto enviado em conjunto" no lugar.

- **Aprovar tudo** aplica toda mudança do conjunto num só pedido: remoções e classificações menores primeiro, depois o resto, para nada parcial chegar se uma falhar.
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
2. Digite o que custa na caixa - por ponto para um traço contado em pontos, o valor inteiro para uma compra única ou um poder.
3. Confira o total ao lado da caixa.
4. Clique em **Aprovar**.

### Achar as mudanças pendentes de um personagem

1. Escolha esse personagem no filtro **Personagem**.

### Ver por que uma mudança precisa da sua revisão

1. Leia a coluna **Motivo da Aprovação** dessa linha (ou, no celular, abra **Detalhes**).

## O que saber

- **Só dois níveis de aprovação: `auto` e `st`.** O Beyond Elysium não tem uma etapa de coordenador à parte. O motivo de uma regra pode dizer para você obter a aprovação de um coordenador antes de aprovar, mas o clique que aprova aqui é sempre de um Narrador.
- **Aprovar Selecionados é um atalho, não um mecanismo diferente.** Aplica a mesma aprovação, individualmente, a toda mudança que você marcou - não uma sobreposição em lote.
- **A fila fica presa ao que lhe foi mostrado.** Se um jogador reenvia uma mudança pendente, ou outra pessoa a revisa primeiro, o seu clique é recusado e a fila recarrega com o que realmente está lá agora - você nunca aprova algo diferente do que viu.
- **Uma linha marcada continua marcada ao virar a página**, e Aprovar Selecionados age em tudo o que você marcou, não só no que está na tela - cada uma como você a viu quando a marcou. Uma mudança editada depois de você marcá-la é pulada, seja em que página estiver. Mudar um filtro limpa as marcas.
- **Poderes numerados se somam.** Uma compra nova no nível 3 é precificada pelos níveis 1, 2 e 3 juntos, não só pelo nível 3 - a coluna XP reflete isso.
- **Nem tudo cai aqui.** Alguns prêmios de XP e tudo o que as regras da sua crônica marcam como aprovado automaticamente se aplicam no instante em que são enviados e nunca aparecem nesta fila.

## Solução de problemas

- **Nada aconteceu quando cliquei em Aprovar, e a linha sumiu.** Outra pessoa já a revisou, ou o jogador a mudou depois que a fila carregou. A fila recarrega sozinha - confira o estado atual do personagem antes de revisar de novo.
- **Confirmar Rejeição não clica.** Digite um motivo primeiro - rejeitar exige um.
- **Uma aprovação em lote pulou algumas mudanças.** Elas já tinham sido revisadas, ou editadas, desde que a fila carregou. Recarregue e revise-as individualmente.
- **Um lote disse que algumas mudanças precisam de um preço.** São produções caseiras ainda sem preço. Abra cada uma, digite o que custa e aprove-a sozinha.
- **Aprovar está cinza numa linha.** Ela precisa de um preço. Digite um número inteiro de 0 a 500 na caixa ao lado.
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
