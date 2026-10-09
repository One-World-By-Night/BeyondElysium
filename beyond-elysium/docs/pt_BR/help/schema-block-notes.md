# Descrições e Cronogramas de Aprovação do Catálogo

Quatro pequenos editores que abrem a partir de uma linha da tela Blocos de Esquema: uma nota pública sobre um item, poder, nível, reserva ou campo, dois cronogramas de aprovação mais finos do que só uma configuração fixa de Aprovação consegue expressar, e a lista de pré-requisitos por trás de um item "o custo segue outro bloco".

## Quem pode usar

O mesmo público dos próprios [Blocos de Esquema](schema-blocks.md), já que estes só abrem de dentro dessa tela: um administrador do site no catálogo compartilhado, e os Narradores (HST e AST) na cópia da própria crônica. Um Condutor de Trama, a Harpia de uma crônica e um jogador nunca os veem.

## Como chegar lá

Estes não são uma tela própria. Abra [Blocos de Esquema](schema-blocks.md), edite um bloco e clique num botão **Descrição**, **Aprovação por valor**, **Aprovação por opção** ou **Pré-requisitos** em qualquer linha que ofereça um.

## A tela

### Descrição

Um pequeno modal com três seções independentes de texto rico:

- **Referência** - uma nota geral.
- **Descrição** - a explicação mais completa ou regra da casa.
- **Fonte** - de onde vem, ou uma citação.

Cada seção mantém formatação, listas e tabelas; imagens e todo o resto são retirados ao salvar. Um botão **Assistência de IA** fica ao lado de cada seção. **Cancelar** descarta o modal; **Salvar** o prepara - ele não é gravado de vez até você também clicar em **Salvar** no próprio bloco.

O botão diz **Descrição (definida)** quando qualquer uma das três seções tem algo.

### Aprovação por valor

Usada num item de lista de traços ou numa reserva de recurso. Uma tabela de intervalos: **De**, **Até**, **Aprovação**, **Motivo** e **Remover**, mais **+ Adicionar intervalo**. Resolvida contra o valor que está sendo alcançado, seja qual for o intervalo que o cobre; um valor coberto por nenhum intervalo recua para a Aprovação fixa do próprio item acima. Numa reserva, só a classificação **permanente** dela é conferida.

### Aprovação por opção

Usada num campo de identidade que tem opções de verdade. Uma linha por opção que o campo oferece no momento, cada uma com a sua própria lista suspensa **Aprovação** (**Padrão do bloco** a deixa sem substituição) e um campo **Motivo**, ativado quando essa opção tem uma substituição. Um campo de seleção múltipla confere todo valor que um jogador escolhe; vale a exigência mais rígida.

### Pré-requisitos

Usado num item de lista de traços, ou num poder em níveis, depois que o **Custo por pré-requisito** (lista de traços) ou o **Derivado de um bloco** (poder em níveis) do próprio bloco está ligado - veja [Blocos de Esquema](schema-blocks.md). Uma tabela de exigências: **Bloco**, **Poder**, **Nível mínimo** e **Remover**, mais **+ Adicionar pré-requisito**. Toda linha precisa ser atendida para o item ser precificado contra o bloco que ela nomeia - um rote que precisa de Correspondência 3 e Tempo 2 lista os dois como linhas separadas.

## Tarefas comuns

### Escrever uma nota pública num item do catálogo

1. Abra [Blocos de Esquema](schema-blocks.md) e edite o bloco.
2. Ache o item, poder ou nível e clique em **Descrição** (ou **Descrição (definida)** se já há uma).
3. Preencha **Referência**, **Descrição** ou **Fonte** - o que você precisar.
4. Clique em **Salvar** na nota e depois em **Salvar** no próprio bloco.

### Exigir revisão só acima de um certo valor

1. Abra Blocos de Esquema e edite o bloco.
2. No item ou reserva, clique em **Aprovação por valor**.
3. Clique em **+ Adicionar intervalo** e defina **De**, **Até** e **Aprovação**.
4. Se quiser, digite um **Motivo**.
5. Clique em **Salvar** no modal e depois em **Salvar** no bloco.

### Exigir aprovação para uma opção de um campo

1. Edite o bloco do campo de identidade.
2. No campo, clique em **Aprovação por opção**.
3. Escolha uma **Aprovação** para a opção que precisa de revisão.
4. Clique em **Salvar** no modal e depois em **Salvar** no bloco.

### Precificar um item pelo nível de outro bloco

1. Edite o bloco e ligue **Custo por pré-requisito** (lista de traços) ou **Derivado de um bloco** (poder em níveis) - veja [Blocos de Esquema](schema-blocks.md).
2. No item, clique em **Pré-requisitos**.
3. Clique em **+ Adicionar pré-requisito** e escolha o **Bloco**, o **Poder** e o **Nível mínimo** de que ele precisa.
4. Repita para cada exigência que o item tem.
5. Clique em **Salvar** no modal e depois em **Salvar** no bloco.

## O que saber

- **Isto grava exatamente os mesmos dados que a tela de [Regras de Aprovação](approval-rules.md) gerencia.** Mude um e veja refletido no outro - as Regras de Aprovação só listam toda regra achatada, em todo bloco, um alvo por vez, enquanto estes modais gerenciam um cronograma inteiro (todo intervalo, ou toda opção) de um item ou campo de uma vez.
- **Uma Descrição é pública.** Todo membro de uma crônica que roda este bloco pode lê-la - na página de [Regras da Casa](house-rules.md) própria dela - não só os Narradores. Ela também não é tocada pela importação ou exportação do Grapevine, já que vive na definição do catálogo, nunca na ficha de um personagem.
- **Aprovação por valor confere o valor que está sendo alcançado, nunca o que um jogador tinha antes.** Ir direto de 2 para 5 é julgado igual a ir de 4 para 5.
- **O nível de um poder em níveis não tem botão Aprovação por valor** - cada nível já é a sua própria linha na tabela de Poderes, com uma lista suspensa de Aprovação simples ali mesmo.
- **Todo pré-requisito listado precisa ser atendido - não há opção "qualquer um de".** Um item que precisa de uma de duas coisas precisa de dois itens separados, cada um com o seu único pré-requisito.
- **Nada aqui grava até o próprio bloco ser salvo.** Fechar um modal com Salvar só prepara aquela nota ou cronograma; você ainda precisa clicar em Salvar no bloco abaixo dele.
- **Só formatação, listas e tabelas sobrevivem a uma Descrição** - uma imagem ou qualquer outra coisa que você cole é retirada no instante em que salva.
- **Editar a nota ou o cronograma de um bloco compartilhado do sistema, como administrador do site, muda para toda crônica que não fez a própria cópia** - e, uma vez lá, sobrevive às futuras atualizações do próprio Beyond Elysium, ao contrário dos dados de catálogo que vêm com o bloco (nomes, custos), que uma atualização ainda renova.

## Solução de problemas

- **A minha nota sumiu depois que salvei.** Provavelmente você clicou em Cancelar na nota, ou não clicou em Salvar no bloco depois - qualquer um dos dois fecha o modal sem manter nada.
- **Aprovação por valor não tem efeito.** Confira se o intervalo de fato cobre o valor que está sendo enviado, e se nenhuma regra anterior e mais estreita já o responde primeiro.
- **Aprovação por opção está desativada.** O campo ainda não tem Opções - adicione algumas na própria caixa Opções do campo em Blocos de Esquema primeiro.
- **Cliquei em Descrição e nada parece ter sido salvo.** Lembre-se do passo duplo: Salvar dentro do modal, depois Salvar no próprio bloco. Cancelar, em qualquer um dos dois, descarta.

## Relacionados

- [Blocos de Esquema](schema-blocks.md)
- [Regras de Aprovação](approval-rules.md)
- [Como Funciona a Aprovação](approval-flow.md)
- [Regras da Casa](house-rules.md)
- [Assistência de Escrita por IA](writing-assist.md)
- [Guia do Administrador: Descrições e Cronogramas de Aprovação](../admin-guide.md#descrições-e-cronogramas-de-aprovação-em-itens-do-catálogo)
- [Guia do Administrador: Aprovação por Valor e Aprovação por Opção](../admin-guide.md#aprovação-por-valor-e-aprovação-por-opção)
