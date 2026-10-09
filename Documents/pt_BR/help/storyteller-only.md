# Conteúdo Só para Narradores

Duas formas de uma crônica manter algo longe dos jogadores por completo: texto marcado dentro de um campo comum, e uma seção inteira do catálogo construída para ser só do Narrador desde o início.

## Quem pode usar

Todos esbarram nisto sem necessariamente notar - a ficha, as impressões e as exportações de um jogador simplesmente nunca carregam nada disso. Os Narradores (HST e AST) sempre o veem por inteiro, em todo personagem da própria crônica; a Harpia da crônica o vê dentro dos termos de um favor. Marcar texto assim, ou sinalizar uma seção inteira, é feito por um Narrador ou administrador do site na tela que já guarda esse conteúdo.

## Como chegar lá

Esta não é uma tela única - é uma regra que vale em todo lugar onde pode existir conteúdo só para Narradores: a Biografia, as Notas e as seções só de NPC de um personagem; a descrição e outros textos de um item, local ou ritual; e os Termos de um favor.

## A tela

**Marcadores `[ST]...[/ST]`.** Envolva qualquer parte de um campo de texto comum em `[ST]` e `[/ST]` e tudo entre eles é removido antes de chegar a um jogador - na tela, numa impressão e numa exportação. Isso funciona dentro de:

- A Biografia e as Notas de um personagem.
- A descrição ou outras propriedades de texto livre de um item, local ou ritual.
- Os Termos de um favor.

Um Narrador vê o texto marcado por inteiro, onde quer que normalmente visse o campo - a mesma visão que um jogador recebe, só que sem nada retirado - e destacado no lugar, para a parte que um jogador nunca recebe ser fácil de notar. Isso vale no Antecedente e nas Notas de um personagem, em descrições e entradas de trama, no texto de um segredo e num cartão de item ou local. A busca no catálogo e as condições de relatório rodam todas no mesmo texto retirado que um jogador vê, então o texto marcado também não pode ser achado por elas, por ninguém que não seja Narrador.

**Blocos só para Narradores.** Uma seção inteira da ficha - a seção de Notas de Interpretação de NPC na ficha de um NPC, por exemplo - pode ser sinalizada como só para Narradores em [Blocos de Esquema](schema-blocks.md). Sinalizar um bloco assim o remove por completo para todos, menos um Narrador: a própria seção nunca aparece no layout da ficha, e nada que um personagem tenha nela é enviado ao navegador, à impressão ou à exportação de um jogador.

## Tarefas comuns

### Esconder parte de um campo dos jogadores

1. Abra o campo como Narrador - a Biografia ou as Notas de um personagem, ou o texto de um item, local, ritual ou favor.
2. Envolva a parte que você quer escondida em `[ST]` e `[/ST]`.
3. Salve como de costume.

### Esconder uma seção inteira dos jogadores

1. Abra [Blocos de Esquema](schema-blocks.md) para o bloco.
2. Marque **Somente Narrador**.
3. Salvar.

### Conferir se uma seção já é só para Narradores

1. Abra [Blocos de Esquema](schema-blocks.md).
2. Leia a configuração **Somente Narrador** do próprio bloco.

## O que saber

- **O personagem de um jogador pode carregar texto só para Narradores sobre ele, e ele nunca o vê** - nem na própria ficha, nem na própria exportação, nem na própria impressão. Isso é de propósito: ali pode caber a sua nota privada sobre o personagem de um jogador.
- **O texto marcado não pode ser buscado nem filtrado ao redor.** A Ferramenta de Consulta, a busca no catálogo e as condições de relatório avaliam todas o mesmo texto retirado que um não-Narrador vê, então o texto escondido não pode ser achado nem confirmar uma cláusula.
- **Um bloco só para Narradores é sinalizado por crônica.** Se a cópia própria de um bloco da sua crônica é sinalizada como só para Narradores, isso vale só para a sua crônica - a cópia de outra crônica do mesmo bloco compartilhado mantém a configuração dela.
- **O marcador é `[ST]` e `[/ST]` para toda crônica hoje** - não há tela para mudá-lo.
- **Um `[ST]` sem o `[/ST]` de fechamento esconde tudo depois dele até o fim do campo** - feche todo marcador que abrir.
- **Isto é separado da visibilidade do próprio NPC.** A ficha de um NPC nunca aparece para um jogador, com marcadores `[ST]` ou sem - veja [Ficha de Personagem](character-sheet.md).

## Solução de problemas

- **Digitei texto `[ST]` e um jogador ainda o vê.** Confira se os dois marcadores estão presentes e escritos exatamente `[ST]` e `[/ST]`, e se você está olhando a visão própria de um jogador, não a de um Narrador.
- **Uma seção simplesmente sumiu de uma ficha.** Alguém sinalizou o bloco de esquema dela como só para Narradores - confira [Blocos de Esquema](schema-blocks.md); um Narrador que olha a mesma ficha ainda a vê.
- **A busca não acha um texto que sei que está lá.** Se está marcado com `[ST]`, isso é esperado para quem não é Narrador - a busca roda no mesmo texto que um jogador veria.

## Relacionados

- [Ficha de Personagem](character-sheet.md)
- [Editor de Personagem](character-editor.md)
- [Blocos de Esquema](schema-blocks.md)
- [Itens e Locais](world-objects.md)
- [Registro de Favores](boon-ledger.md)
- [Ferramenta de Consulta](query-tool.md)
- [Relatórios](reports.md)
- [Papéis](roles.md)
- [Guia do Narrador: Criando ou Marcando um NPC](../st-guide.md#criando-ou-marcando-um-npc)
