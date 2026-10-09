# Tradução de Termos do Catálogo

Gerencia a tradução de todo termo do catálogo - nomes de traços, nomes de poderes, rótulos e opções de campos de identidade - para quantos idiomas uma crônica precisar. Separada da tradução da própria interface do plugin (botões, rótulos, mensagens), que se instala por um pacote de idioma comum do WordPress: um termo do catálogo como "Fortitude" nunca aparece como uma sequência literal em nenhum arquivo-fonte, só como uma linha do banco de dados, então um arquivo `.po` nunca pode levá-lo.

## Quem pode usar

Quem tem `be_manage_translations` - uma capacidade própria, independente de `be_manage_schemas`, para um voluntário nativo poder ter a confiança de traduzir sem também ter a de editar a mecânica do próprio catálogo. Não é restrita a uma crônica: uma instalação, um conjunto de idiomas, compartilhado por toda crônica nela.

## Como chegar lá

- Barra lateral do wp-admin → Beyond Elysium → Configuração do Sistema → aba Traduções.

## A tela

- **Idioma** - uma lista suspensa de locales que já têm traduções, mais todo locale que o próprio WordPress tem instalado. **+ Adicionar idioma** começa um novo digitando o código do locale (por exemplo, `es_ES`) - não é preciso instalar nenhum pacote de idioma do WordPress, já que isto são dados do catálogo, não interface.
- **Reescanear catálogo** - percorre de novo todo bloco de esquema, do sistema e a bifurcação própria de cada crônica, e atualiza o índice de termos contra o que o catálogo real e atual de fato contém.
- Uma barra de progresso e uma contagem de termos por status (rascunho / precisa de revisão / aprovado / conflito) para o idioma escolhido.
- **Catálogo**, **Status** (inclusive **Somente não traduzidos**) e **Buscar** filtram a tabela abaixo.
- **Exportar CSV** / **Importar CSV** - a ida e volta offline, abaixo.
- A tabela: uma caixa de seleção, o **Termo em inglês**, **Aparece em** (quais blocos do catálogo o usam), o texto traduzido (um campo editável - digite, clique fora ou aperte Tab para pular direto para a próxima linha sem tradução) e **Status** (a sua própria lista suspensa).
- **Marcar selecionados como aprovados** aplica esse status a toda linha marcada de uma vez.

## Tarefas comuns

### Corrigir um termo

1. **Busque** por ele.
2. Edite o texto na linha dele.
3. Clique fora, ou aperte Tab para a próxima linha - ele salva sozinho.

### Traduzir um catálogo inteiro offline

1. Defina **Catálogo** como o bloco que você está traduzindo e **Status** como **Somente não traduzidos**.
2. Clique em **Exportar CSV**.
3. Preencha a coluna `translation` numa planilha.
4. Clique em **Importar CSV** e escolha o arquivo.
5. Revise o resumo do ensaio (adicionados / atualizados / inalterados / sem correspondência / conflitos) e as linhas de amostra.
6. Clique em **Confirmar importação**.

### Começar um idioma novo

1. Clique em **+ Adicionar idioma** e digite o código do locale.
2. Trabalhe nele do mesmo jeito - o CSV offline para o grosso, a edição na própria linha para o resto.

## O que saber

- **A tabela são os dados de verdade, não o CSV.** Exportar e reimportar um arquivo é uma conveniência para uma passada em lote; nada nisso é obrigatório, e o arquivo nunca é a fonte da verdade.
- **Um conflito durante a importação quer dizer que o arquivo discorda de si mesmo** - duas linhas para o mesmo termo com duas traduções diferentes - não que o arquivo discorda do que já está salvo, o que é uma atualização comum e nunca é sinalizada como conflito.
- **Um termo sem tradução recua para o inglês em todo lugar** - numa ficha impressa, num PDF assinado, nunca em branco.
- **Reescanear nunca perde uma tradução.** Um termo temporariamente ausente do catálogo (uma crônica restringiu os tipos de criatura ativados, digamos) só para de atualizar o próprio carimbo de "visto por último"; a tradução continua lá no instante em que o termo reaparece.

## Solução de problemas

- **Não vejo esta aba.** Você precisa de `be_manage_translations` - peça a um administrador do WordPress que a conceda.
- **A minha importação relatou linhas "sem correspondência".** O `source_text` dessas linhas não casa com nenhum termo real do catálogo - confira a grafia, ou clique primeiro em **Reescanear catálogo** se você acabou de adicionar conteúdo novo ao catálogo.
- **Um termo que corrigi ainda mostra o texto antigo em algum lugar.** Confirme que você editou o idioma certo - a correção só vale para o idioma que você tinha escolhido quando a fez.

## Relacionados

- [Guia do Administrador: Tradução de Termos do Catálogo](../admin-guide.md#tradução-de-termos-do-catálogo)
- [Guia do Narrador: Corrigindo um Termo do Catálogo que Você Vê em Jogo](../st-guide.md#20-corrigindo-um-termo-do-catálogo-que-você-vê-em-jogo)
- [Blocos de Esquema](schema-blocks.md)
