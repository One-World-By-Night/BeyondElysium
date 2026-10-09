# Modelos

Como as seções de uma pilha de criatura são dispostas na ficha renderizada - qual bloco vai em qual coluna, em que ordem e sob qual título.

## Quem pode usar

Todo modelo compartilhado - o livro - é somente leitura aqui, para todos, administrador do site inclusive: é sempre só **Ver**, haja uma crônica escolhida ou não. Uma crônica recebe o próprio layout do mesmo jeito que muda qualquer outra coisa no próprio catálogo: restrito a essa crônica, pela Configuração da Crônica.

Os Narradores (HST e AST) chegam a esta tela restrita à própria crônica, normalmente seguindo o link Modelos de ficha da [Configuração da Crônica](chronicle-setup.md), e podem dar à crônica o próprio layout ali - um novinho, ou uma cópia de um modelo compartilhado que eles então ajustam (**Personalizar para esta crônica**).

Um Condutor de Trama, a Harpia de uma crônica e um jogador nunca veem esta tela; o que ela produz é o que eles veem automaticamente toda vez que abrem uma ficha.

## Como chegar lá

- Administrador do site, vendo os modelos do próprio livro: barra lateral do wp-admin → Beyond Elysium → Configuração do Sistema → aba Modelos.
- Narrador, editando o layout da própria crônica: barra lateral do wp-admin → Beyond Elysium → Configuração da Crônica, escolha a sua crônica, ache **Modelos de ficha** e clique em **Ir**.

## A tela

Com uma crônica escolhida, **Os modelos desta crônica** aparece primeiro: **Nome**, **Tipo**, **Pilha**, **Ações** (**Editar**, **Excluir**) e **+ Novo Modelo para esta crônica**.

**Modelos compartilhados** vem sempre depois: **Nome**, **Tipo**, **Pilha**, **Sistema?** (Sim/Não), **Ações**:

- **Personalizar para esta crônica** (só com uma crônica escolhida) - abre um formulário de criação para a sua crônica, preenchido a partir desse modelo compartilhado.
- **Ver** - sempre oferecido, a todos, em toda linha. Abre o modelo somente leitura, com todo campo desativado, e **Fechar** como única ação.

Não há como criar, editar nem excluir um modelo compartilhado diretamente, de nenhuma conta - a cópia própria de uma crônica é o único jeito de mudar como uma ficha é disposta.

### O formulário de criação/edição

- **Nome**.
- **Tipo de Modelo** - texto livre (`sheet_full`, `sheet_compact`, `sheet_mobile` e afins).
- **Slug da Pilha** - opcional; em branco vale para todo tipo de criatura, nomear um restringe o modelo só a esse tipo.
- **Colunas** - em quantas colunas as seções da ficha fluem (1 a 4).
- **Seções** - tabela com uma linha por seção da ficha. **+ Adicionar seção** acrescenta uma nova linha; **Remover** exclui uma.
  - **Bloco** - qual bloco de esquema esta seção renderiza. Cada bloco só pode aparecer uma vez num modelo.
  - **Título** - o título da própria seção, com linhas **+ Referência de título** opcionais embaixo dele - escolha outro Bloco e o Nome do campo dele para anexar o valor resolvido desse campo a este título, por exemplo nomeando a qual Disciplina um poder pertence.
  - **Largura** - um terço, metade ou inteira: quanto de uma linha a caixa desta seção ocupa.
  - **Coluna** - em qual coluna numerada (de 1 até Colunas acima) esta seção fica.
  - **Ordem** - a posição dentro dessa coluna; números menores ficam mais acima.
  - **Substituição de exibição** - deixe como o padrão do próprio bloco, ou force um dos estilos de exibição embutidos da ficha (por exemplo, pontos em vez de uma contagem simples) só para esta seção.
  - **Recolhido** - uma caixa de seleção salva com a seção. Nada na ficha renderizada muda hoje com base nela.
- **Salvar** / **Cancelar**.

## Tarefas comuns

### Dar a uma crônica o próprio layout de ficha

1. Abra a [Configuração da Crônica](chronicle-setup.md) dessa crônica, ache **Modelos de ficha** e clique em **Ir**.
2. Em **Modelos compartilhados** abaixo, ache o que você quer mudar e clique em **Personalizar para esta crônica**.
3. Ajuste as seções, colunas ou títulos dele.
4. Clique em **Salvar**. Só esta crônica usa o novo layout - o modelo compartilhado fica intocado.

### Adicionar uma seção a um modelo

1. Edite o modelo.
2. Clique em **+ Adicionar seção**.
3. Escolha o **Bloco**, digite um **Título** e defina **Coluna**, **Ordem** e **Largura**.
4. Clique em **Salvar**.

### Mostrar o traço que governa um poder no título dele

1. Edite a seção que precisa disso.
2. Clique em **+ Referência de título**.
3. Escolha o **Bloco** que guarda o valor e digite o **Nome do campo** dele.
4. Clique em **Salvar**.

## O que saber

- **Dois catálogos, uma tela - mas só um deles é editável aqui.** O modelo próprio de uma crônica - feito por Personalizar para esta crônica - sempre vence o compartilhado, para o mesmo Tipo de Modelo e Slug da Pilha; toda outra crônica continua usando a versão compartilhada, que ninguém, administrador do site inclusive, pode editar ou excluir diretamente.
- **Um Slug da Pilha em branco vale para todo tipo de criatura**; nomear um restringe o modelo só a esse tipo.
- **O recuo é em camadas.** Exclua o modelo próprio de uma crônica e as fichas dela voltam ao compartilhado por baixo, gerado automaticamente a partir das seções da própria pilha de criatura se o catálogo compartilhado também não tem um modelo próprio para esse tipo.
- **Um modelo do sistema nunca pode ser excluído**, em nenhum dos catálogos - só a cópia própria de uma crônica pode.
- **Largura só aceita um terço, metade ou inteira** - não há nada no meio, e qualquer outra coisa é recusada ao salvar.
- **Cada bloco só pode aparecer uma vez num modelo.** Adicione o mesmo Bloco a uma segunda seção e salvar é recusado.
- **Um modelo só controla o layout.** Ele nunca decide o que fica escondido de um jogador - uma seção sinalizada como somente Narrador em [Blocos de Esquema](schema-blocks.md) continua escondida, seja qual for o modelo que está sendo mostrado ou a coluna em que ela está.
- **Ver a aba não é o mesmo que poder usá-la aqui.** Qualquer conta com capacidade de Narrador pode abrir esta tela, mas mudar os modelos próprios de uma crônica específica ainda exige um papel de Narrador de verdade nessa crônica.
- **Uma atualização do livro num modelo compartilhado que a sua crônica também personalizou aparece como uma [correção do livro](chronicle-setup.md#a-tela)** na linha Personalização do catálogo da Configuração da Crônica, nunca uma sobrescrita silenciosa do seu próprio layout.

## Solução de problemas

- **Não vejo Editar nem Excluir num modelo compartilhado, só Ver.** Isso é de propósito - ninguém edita um modelo compartilhado diretamente, administrador do site inclusive. Use **Personalizar para esta crônica**, com a sua crônica escolhida.
- **Toda crônica que tento mostra um erro de permissão.** Você pode ver esta aba sem ser Narrador em lugar nenhum - cada crônica ainda confere se você de fato tem um papel de Narrador nela.
- **Não vejo esta aba de jeito nenhum.** A Configuração do Sistema exige uma conta de administrador do WordPress, ou um papel de Narrador em alguma crônica.
- **Salvar diz que o layout é inválido.** Confira se toda seção tem um Bloco escolhido, se duas seções não dividem o mesmo Bloco e se cada número de Coluna está dentro da contagem de Colunas do próprio modelo.
- **As fichas da minha crônica parecem diferentes depois que excluí um modelo.** Isso é esperado - excluir o modelo próprio da sua crônica devolve as fichas dela ao layout compartilhado por baixo, que pode estar arrumado de outro jeito.

## Relacionados

- [Blocos de Esquema](schema-blocks.md)
- [Pilhas de Criatura](creature-stacks.md)
- [Ficha de Personagem](character-sheet.md)
- [Configuração da Crônica](chronicle-setup.md)
- [Conteúdo Só para Narradores](storyteller-only.md)
- [Guia do Administrador: Modelos](../admin-guide.md#modelos)
