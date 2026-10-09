# Listas de Traços

O editor de uma seção de lista de traços da ficha de um personagem - catálogos como Habilidades, Antecedentes, Qualidades e Defeitos, em que cada entrada mantida é um nome e uma contagem ou nível.

## Quem pode usar

Qualquer pessoa que edita um personagem vê isto dentro da aba Editar - um jogador editando o próprio personagem, ou um Narrador editando qualquer personagem da crônica. Se você só pode visualizar o personagem, cada traço aqui aparece como uma lista simples somente leitura, sem controle de Adicionar nem de edição. Veja o [Editor de Personagem](character-editor.md) para saber quem pode abrir a aba Editar.

## Como chegar lá

Minha Crônica → aba Editar, dentro de qualquer seção montada como lista de traços (Habilidades, Antecedentes, Qualidades, Defeitos e afins, conforme o modelo da própria crônica).

## A tela

- Entradas mantidas, uma linha cada: o nome, uma exibição de pontos ou contagem para qualquer coisa acima de zero e, quando definidos, uma especialização entre parênteses, uma nota e o custo escolhido para ela. Algumas seções agrupam as entradas sob linhas de título e subtítulo em vez de uma lista simples, conforme como a seção é montada.
- **✎** em cada linha - abre, para editar, o mesmo modal usado para adicionar.
- **+ Adicionar** - abre um modal em branco para adicionar uma nova entrada.
- **Reordenar** - em Rituais, e em qualquer outra lista de traços que a sua crônica configurou para deixar os jogadores escolherem a própria ordem. Coloca o que você tem na ordem que quiser em vez da lista alfabética de costume - veja [Sua Própria Ordem](player-order.md).

### O modal de Adicionar/Editar

- **Nome** - uma lista suspensa com busca do catálogo desta seção. Digitar filtra a lista; onde a seção permite uma entrada personalizada, digitar um nome que não está na lista o adiciona como uma. Mostrado só ao adicionar - o nome de uma entrada mantida não pode ser mudado, só removido e adicionado de novo.
- **Contagem / Nível** - um número, mínimo 1.
- **Custo** - mostrado só para uma entrada que o catálogo precifica com uma escolha de custos, como uma Qualidade listada "1 ou 3" ou "3-5". Começa no mais baixo, que é o que você paga se o deixar.
- **Especialização**, ou **Quem ou o quê?** - mostrado numa seção que aceita especialização (por exemplo, uma Habilidade como Ciências Acadêmicas pode ter uma como "História Bizantina"), e também para uma entrada que você pode ter mais de uma vez mesmo numa seção que de outro modo não usa este campo - veja [O que saber](#o-que-saber) abaixo. Seja qual for a pergunta feita, deixá-la em branco (ou repetir uma resposta que você já usou) soma à linha existente correspondente em vez de começar uma nova.
- **Nota** - um campo de texto livre, sempre disponível.
- **Remover** / **Desfazer remoção** - marca a entrada para remoção, ou a traz de volta, sem sair do modal.
- **Salvar** - desativado até um nome ser escolhido; grava a entrada na cópia desta tela da seção - ela não chega à ficha até você enviar as alterações.

## Tarefas comuns

### Adicionar uma entrada nova

1. Abra a seção e clique em **+ Adicionar**.
2. Escolha um nome na lista (ou digite um, onde permitido).
3. Defina a **Contagem / Nível** e, se quiser, uma especialização ou nota. Para uma entrada com escolha de custos, escolha o seu **Custo**.
4. Clique em **Salvar**.

### Mudar a contagem ou o nível de uma entrada

1. Clique em **✎** na entrada.
2. Atualize **Contagem / Nível**.
3. Clique em **Salvar**.

### Remover uma entrada

1. Clique em **✎** na entrada.
2. Clique em **Remover**.
3. Para trazê-la de volta antes de enviar, abra-a de novo e clique em **Desfazer remoção**.

## O que saber

- **Adicionar o mesmo nome de novo soma a ele, não cria uma linha duplicada** - na maioria das seções, escolher um nome que você já tem aumenta a contagem da entrada existente em vez de criar uma segunda, seja qual for a especialização que você digitar junto. Uma especialização rotula uma posse, então `Briga 5 (Luta Livre)` é um único Briga em 5: escolher outro foco não compra um segundo Briga, apenas reorganiza ou soma ao que você tem.
- **Algumas entradas realmente podem ser mantidas mais de uma vez**, e são aquelas em que o rótulo faz parte do que você tem - dois Serviçais diferentes, ou dois campos de estudo sob uma Habilidade. Para elas, o modal pergunta **Quem ou o quê?** em vez de Especialização, mesmo numa seção (como Antecedentes) que de outro modo não mostra este campo. O catálogo da sua própria crônica diz quais entradas funcionam assim; para elas, uma resposta nova é uma linha nova com os seus próprios pontos e o seu próprio custo, e repetir uma resposta que você já usou aumenta essa linha. Algumas seções - Qualidades e Defeitos, por exemplo - sempre mantêm cada escolha como a sua própria linha, não importa como seja nomeada.
- **Renomear para um rótulo que você já tem junta as duas** - onde um nome pode ser mantido mais de uma vez, editar o rótulo de uma linha para igualar o de outra as combina na que você já tinha, em vez de deixar duas linhas que nada distingue.
- **Um nome personalizado sempre precisa de revisão.** Digitar um nome que não está no catálogo só salva onde esta seção aceita entradas personalizadas, e mesmo assim sempre vai a um Narrador para aprovação, não importa como a sua crônica configurou a aprovação automática.
- **Remover marca, não apaga** - nada sai de fato da ficha até você enviar as alterações pela aba Editar, então você pode desfazer uma remoção até esse momento.
- **Todos os pontos têm o mesmo tamanho** - a exibição da contagem aqui combina exatamente com a ficha e com um PDF assinado.
- **Nada aqui é precificado nem salvo por si só.** Toda adição, mudança ou remoção vai para a fila de Alterações Pendentes da aba Editar até você enviá-la.

## Solução de problemas

- **Salvar não clica.** Escolha um nome primeiro - ele é obrigatório.
- **Um nome que digitei diz que não está no catálogo.** Confira a grafia. Se é mesmo novo, esta seção pode não aceitar entradas personalizadas - pergunte a um Narrador.
- **Não vejo + Adicionar nem o botão ✎.** Você está olhando um personagem que não pode editar - veja o [Editor de Personagem](character-editor.md).
- **A minha remoção sumiu.** Provavelmente você reabriu a entrada e clicou em Desfazer remoção, ou descartou as alterações pendentes antes de enviar.

## Relacionados

- [Editor de Personagem](character-editor.md)
- [Poderes](power-editor.md)
- [Sua Própria Ordem](player-order.md)
- [Reservas de Recurso e Campos de Identidade](pools-identity-editor.md)
- [Ficha de Personagem](character-sheet.md)
- [Como Funciona a Aprovação](approval-flow.md)
- [Guia do Jogador](../player-guide.md#2-editando-sua-ficha)
- [Guia do Narrador](../st-guide.md#3-criando-personagens)
