# Guia do Administrador

Este guia cobre as partes do Beyond Elysium que moldam o próprio plugin, e não o jogo do dia a dia de uma crônica: blocos de esquema, pilhas de criatura, adicionar um novo tipo de criatura sem escrever código e modelos. Se você procura o gerenciamento de crônica do dia a dia, veja o [Guia do Narrador](st-guide.md).

## O Menu do wp-admin

Tudo abaixo vive num único menu de nível superior **Beyond Elysium** no wp-admin. Clicar no próprio rótulo de nível superior cai num painel de verdade - uma referência sobre o plugin e o que está onde, o inventário de widgets e shortcodes do Elementor e uma chamada para "Crie sua primeira crônica" que só é destacada enquanto não existe nenhuma crônica de verdade. Dezesseis submenus planos foram consolidados em 8 grupos com abas; uma aba se esconde individualmente quando quem vê não tem a capacidade própria dela, então uma página continua alcançável até para quem não pode ver todas as abas dela:

| Submenu | Abas (cada uma com a sua capacidade) | Coberto neste guia |
|---|---|---|
| Personagens | - (página única; a chave NPC/jogador substitui a antiga página separada de Lista de NPCs) | - a lista de personagens voltada à equipe em todas as crônicas, mais "+ Novo Personagem" e a marcação de NPC, abaixo |
| Tramas | - (página única) | - as mesmas ferramentas de trama/ação/rumor da página Kit de Ferramentas do Narrador, pelo wp-admin |
| Itens e Locais | - (página única) | - o catálogo de objetos do mundo a que um personagem pode ser conectado |
| Ferramenta de Consulta | Ferramenta de Consulta (`be_run_queries`), Relatórios (`be_view_reports`) | - o mesmo construtor de consultas da Ferramenta de Consulta do front-end, mais a camada de 20 relatórios/cartões/saída em lote |
| Importar | - (página única) | Importar, abaixo |
| Configuração da Crônica | Configuração da Crônica (`be_manage_chronicle_setup`), Acesso à Crônica (`be_manage_games`), Configurações de Ação e Rumor e Assistência de IA (`be_manage_apr`) - só a equipe: a página exige `be_manage_chronicle_setup`, então um jogador nunca a vê | Acesso Restrito à Crônica, abaixo; a Configuração da Crônica em si é uma lista de conferência ao vivo para a configuração de uma crônica, veja o [Guia do Narrador](st-guide.md) |
| Configuração do Sistema | Jogos (`be_manage_games`), Blocos de Esquema (`be_manage_schemas` - somente leitura para o livro, restrito à crônica para editar), Pilhas de Criatura (`be_manage_games` para ver o livro; `be_manage_schemas`, restrito a uma crônica, para construir ou editar uma), Modelos (`be_manage_templates` - somente leitura para o livro, restrito à crônica para editar), Regras de Aprovação (`be_manage_approval_rules`), Traduções (`be_manage_translations`) | Blocos de Esquema e Pilhas de Criatura, Modelos, Descrições e Cronogramas de Aprovação, Regras de Aprovação e Tradução de Termos do Catálogo, todos abaixo |
| Documentação | - (página única, `be_view_characters`) | - este guia e os três irmãos dele, renderizados dentro do plugin |

Duas páginas relacionadas vivem no front-end e não no wp-admin de jeito nenhum: o **Painel do Jogo** (estatísticas do elenco, saúde do elenco, tramas futuras) é a aba Painel tanto na página Kit de Ferramentas do Narrador (equipe) quanto em Minha Crônica (jogadores, só as próprias estatísticas), e as **Notificações** são uma chave liga/desliga por crônica em Configuração da Crônica → Acesso à Crônica, com cada jogador podendo optar por sair individualmente na própria página de Perfil do WordPress. A referência "O que está onde" do painel de entrada liga as duas.

### Criando ou Marcando um NPC

O botão "+ Novo Personagem" da página Personagens abre o mesmo formulário de criação de personagem que um jogador usa (front-end, aba Editar de Minha Crônica) para a crônica escolhida no momento. Quem tem `be_manage_characters` vê além disso ali uma caixa "Este é um NPC" - nunca mostrada a um jogador - que resolve na hora o modelo de ficha de NPC mais rico (voz, trejeitos, ganchos de trama). A mesma caixa aparece no modo de edição de um personagem já existente, então marcar ou desmarcar o status de NPC depois não precisa de nenhuma ação à parte.

## Blocos de Esquema e Pilhas de Criatura

O Beyond Elysium nunca tem código específico de criatura. Toda ficha de personagem é montada na hora de renderizar a partir de dois tipos de entradas de catálogo:

- **Blocos de esquema** - um bloco de construção reutilizável: uma lista de traços (Qualidades, Antecedentes), um poder em níveis (Disciplinas, Dons, Esferas), uma reserva de recurso (Sangue, Força de Vontade, Gnosis) ou um campo de identidade (Natureza, Comportamento, Tribo).
- **Pilhas de criatura** - uma montagem ordenada de blocos que forma a ficha completa de um tipo de criatura.

Em **Beyond Elysium → Configuração do Sistema → Blocos de Esquema**, cada bloco mostra o tipo de seção, se é um bloco do sistema (parte do catálogo que vem com o plugin) ou uma bifurcação própria de uma crônica, e a definição completa. Um bloco do sistema pode ser bifurcado por crônica pelo lado do Narrador (veja o Guia do Narrador) sem nunca tocar a versão compartilhada que toda outra crônica usa. A cópia mantém o que a crônica mudou - valores, regras de aprovação, entradas que adicionou ou removeu - e pega todo o resto do bloco compartilhado, tanto quando o plugin atualiza quanto quando você salva o bloco compartilhado aqui.

Em Blocos de Esquema, Pilhas de Criatura, Jogos, Modelos e Regras de Aprovação, clicar em **Editar** ou **+ Novo** rola a página até o editor e põe o cursor no primeiro campo dele, então o formulário que você abriu é sempre o que está à sua frente.

Quando uma atualização do livro muda algo que a bifurcação própria de uma crônica também mudou, nada é sobrescrito em silêncio e nada é perdido em silêncio - a linha **Personalização do catálogo** da Configuração da Crônica a sinaliza como uma **correção do livro** a revisar, nomeando exatamente o que o livro diz agora contra o que a cópia própria da crônica ainda diz, com um clique para manter o valor da crônica ou adotar o novo do livro. Veja a [Configuração da Crônica](help/chronicle-setup.md#a-tela) para o painel de revisão em si.

Em **Beyond Elysium → Configuração do Sistema → Pilhas de Criatura**, cada pilha lista quais blocos usa e em que seção/coluna eles renderizam. Todo tipo de criatura que vem com o plugin é somente leitura ali, para todos - uma crônica muda como um funciona para si com uma camada própria, pela [Configuração da Crônica](help/chronicle-setup.md). Restrita a uma crônica, a mesma tela também deixa um Narrador construir um tipo de criatura totalmente novo que pertence só a essa crônica; veja [Adicionando um Tipo de Criatura sem Código](#adicionando-um-tipo-de-criatura-sem-código) abaixo.

Alguns blocos vêm com **variantes** - edições alternativas do mesmo catálogo, como as Disciplinas de Dark Ages ou o pacote de Arcanoi do próprio OWBN - que uma crônica pode trocar pela linha **Variantes do livro** da Configuração da Crônica sem ninguém nunca tocar o bloco base. Uma variante **substituta** ocupa o lugar do base por inteiro (uma por vez); uma variante **aditiva** soma conteúdo adicional ao lado dele (qualquer número ao mesmo tempo). Escolher ou largar uma mostra antes uma prévia de quais entradas mantidas por personagens deixariam de casar com o catálogo, então uma crônica pode decidir com informação completa antes de qualquer coisa ser gravada; um personagem mantém o que já tem de qualquer modo. Veja a [Configuração da Crônica](help/chronicle-setup.md#a-tela) para o seletor em si.

O custo de um bloco de poder em níveis nem sempre é um número fixo por nível. As **Configurações globais** dele trazem uma tabela **Níveis e custos** - uma linha por nível nomeado (Básico, Intermediário, Avançado, Ancião e assim por diante) com o custo desse nível e os modificadores **dentro do tipo** e **fora do tipo** dele (`+N`, `-N` ou `×N`), então a Rapidez dentro do clã de um Brujah e a Ofuscação fora do clã são precificadas diferente a partir da mesma linha de catálogo. Um bloco pode em vez disso ser uma **trilha sem níveis**, precificada a uma taxa fixa por nível ou **derivada do nível de outro bloco** (um rote precificado pela Esfera de que precisa) - a mesma ideia de "custo por pré-requisito" que um item de lista de traços também pode levar, cada um nomeando as entradas específicas que exige. Uma reserva de recurso traz de modo parecido o **custo por ponto**, os **pontos gratuitos** e uma escolha entre **Progressivo** (cada ponto custa a taxa do degrau dele) e **Redução paga** (precificada por baixar a reserva do valor inicial dela, nunca os dois ao mesmo tempo). Veja [Blocos de Esquema](help/schema-blocks.md) para todo campo.

## Regras de Criação

Cada pilha de criatura pode levar um documento de **regras de criação**: uma lista reordenável de passos, editada pela tela da própria pilha, que governa como um personagem novinho desse tipo é montado - não só precificado. O Beyond Elysium traz uma de verdade para todo tipo de criatura; o tipo de criatura personalizado de uma crônica também pode escrever uma, ou deixá-la vazia, o que precifica tudo ao custo de compra comum, sem reservas livres nem orçamentos, exatamente como todo tipo de criatura se comportava antes de este motor existir.

Um passo é de um entre sete tipos: `prioritized` (uma divisão fixa entre seções, a maior primeiro - Atributos 7/5/3), `budget` (uma quantidade livre de entradas de uma seção, opcionalmente filtrada só para dentro do tipo ou um teto de nível, com cotas que pode acompanhar), `free` (uma reserva de pontos gasta no que um passo de orçamento não cobriu), `earned` (uma reserva preenchida com o que o personagem já tem, como o valor de um Defeito), `limit` (sinaliza uma seção ou entrada acima de um total de pontos ou de uma classificação, só para a atenção do Narrador - nunca bloqueia um salvamento), `start` (o valor inicial de uma entrada: fixo, consultado por outro campo ou uma fórmula) e `grant` (uma entrada fixa com que o personagem começa, de graça). Todo passo pode trazer uma condição **Somente quando**. Veja as [Pilhas de Criatura](help/creature-stacks.md#o-editor-de-regras-de-criação) para o editor completo.

O **teste dentro do tipo** que uma seção de pilha de criatura carrega é aquilo contra o que o modificador dentro do tipo/fora do tipo de um nível acima de fato confere: uma seção sem teste declarado trata tudo como dentro do tipo. O `kind` de um teste - `names`, `facet` (um `group`/`subgroup`), `chosen` (algo que o jogador escolheu na criação) ou `all` (todo teste aninhado precisa passar) - lê os valores de um campo do personagem, de um mapa de consulta guardado num campo de identidade ou de uma lista fixa, com uma condição opcional para o caso em que um campo *não estar definido* é em si o estado que importa (um ghoul é um Mortal sem Família Revenant). Veja as [Pilhas de Criatura](help/creature-stacks.md#o-teste-dentro-do-tipo) para o editor.

O progresso ao vivo de um personagem contra as regras de criação do seu tipo - o que cada passo ainda precisa, o saldo de cada reserva e o que resta a comprar ao preço comum - renderiza como um painel de **Cálculo da Construção** no formulário de criação de personagem, e de novo para um Narrador que revisa a construção própria de um personagem pendente.

## Descrições e Cronogramas de Aprovação em Itens do Catálogo

Além do nome/custo/aprovação básicos, qualquer entrada do catálogo - um item de lista de traços, um nível de poder em níveis, uma família de poder em níveis, uma reserva de recurso ou um campo de identidade - pode levar uma **Descrição** e um **cronograma de aprovação** mais fino, os dois editados pela mesma tela **Beyond Elysium → Configuração do Sistema → Blocos de Esquema** em que o item já vive.

### Descrição

Um botão **Descrição** ao lado de um item, nível de poder ou família de poder abre um pequeno editor com três seções separadas de texto rico:

- **Referência** - uma citação de página ou documento.
- **Descrição** - uma nota geral ou regra da casa.
- **Fonte** - de onde esta decisão veio (uma ideia separada da citação de livro-fonte impressa do próprio item, que é um campo simples em outro lugar da mesma linha).

Cada seção mantém formatação, listas e tabelas; imagens e todo o resto são retirados ao salvar. Este é um campo **de todo o site**, não por crônica - uma crônica ainda pode bifurcar o bloco para escrever a própria nota, mas uma edição feita aqui (sem crônica escolhida) é visível a toda crônica na hora. Ele também sobrevive a toda atualização futura do plugin: os dados de catálogo de um bloco do sistema (custo, requisitos de esfera e assim por diante) se renovam a partir da fonte que vem com o plugin a cada mudança de versão, mas uma descrição que um administrador escreveu passa adiante intocada.

O mesmo vale para toda outra configuração que só um administrador faz num bloco compartilhado do sistema: níveis e motivos de aprovação, cronogramas de aprovação por valor e por opção, a substituição de aprovação de uma família de poder, as regras de aprovação do próprio bloco e qualquer item, poder, nível, reserva ou campo que um administrador adicionou. Uma seção adicionada a uma pilha de criatura do sistema também é mantida. O que uma atualização renova é o que vem com o plugin - nomes, custos, notas, traduções, o nome e as seções de uma pilha - então mudar um desses num bloco do sistema dura só até a próxima atualização; bifurque o bloco para a sua crônica para mudá-lo de vez.

### Aprovação por Valor e Aprovação por Opção

A configuração fixa de aprovação de um item ("este item inteiro precisa da aprovação de um Narrador") pode ser afinada para depender do que um jogador de fato está elevando:

- **Itens de lista de traços e reservas de recurso** - um botão **Aprovação por valor** abre uma pequena tabela de intervalos (`De` / `Até` / `Aprovação` / `Motivo`), por exemplo Ocultismo 1-3 aprovado automaticamente, 4-5 precisando de revisão do Narrador. Isto é resolvido contra o valor que um jogador está enviando, nunca uma comparação com o que ele tinha antes - chegar ao nível 4 precisa de revisão não importa como o personagem chegou lá. O cronograma de uma reserva de recurso confere só a classificação **permanente** dela; gastar ou recuperar pontos em jogo nunca o dispara.
- **Níveis de poder em níveis** (Disciplinas, Dons, Esferas, …) - cada nível já é a sua própria linha, então ganha uma lista suspensa de **Aprovação** simples direto, sem intervalo a configurar.
- **Campos de identidade** (Natureza, Clã, Geração, …) - um botão **Aprovação por opção** lista toda opção que o campo oferece com a sua própria lista suspensa de aprovação, por exemplo exigir a aprovação de um Narrador para escolher "Antediluviano" enquanto toda outra opção é automática. Um campo de seleção múltipla confere todo valor que um jogador escolhe e vale a exigência mais rígida.

Um valor ou opção sem entrada no cronograma recua para a configuração fixa `approval` do próprio item, que por sua vez recua para o padrão geral do bloco, que por sua vez recua para a **política de aprovação padrão** da própria crônica - veja [Regras de Aprovação](#regras-de-aprovação) abaixo - e veja a [seção de aprovação do Guia do Narrador](st-guide.md#4-conduzindo-a-fila-de-aprovação) para como um nível de aprovação resolvido chega à fila.

Um botão **Pré-requisitos** aparece no lugar, num item cujo custo é derivado do nível de outro bloco (veja [Regras de Criação](#regras-de-criação) acima) - uma tabela das entradas específicas e níveis mínimos de que ele precisa, todos os quais precisam ser atendidos antes de ele ser precificado, afinal.

## Regras de Aprovação

Em **Beyond Elysium → Configuração do Sistema → Regras de Aprovação**, uma página lista toda exceção de aprovação definida no momento em qualquer lugar do catálogo de uma crônica - exatamente os mesmos dados de fundo que os editores de Descrição/Aprovação da própria tela Blocos de Esquema gravam, só reunidos numa lista plana e endereçável em vez de espalhados pelo bloco em que cada regra por acaso vive. Tudo definido aqui aparece lá também, e vice-versa; edite o que for mais conveniente no momento - no contexto enquanto já edita os outros campos de um bloco, ou aqui para uma olhada rápida em tudo para o que uma crônica exige revisão no momento.

Escolher um bloco oferece um **Escopo**: um item, poder, reserva ou campo específico (abaixo), **o bloco inteiro** (o `approval_rules.default` fixo dele) ou - só num bloco de poder em níveis com um teste dentro do tipo de verdade declarado em algum tipo de criatura desta crônica - **dentro do tipo / fora do tipo** (`approval_rules.in_type`/`out_of_type`, dois níveis separados; um bloco sem esse teste recusa este escopo com `400 no_in_type_test`).

Escolher "um item, poder, reserva ou campo específico" oferece o seletor de alvo correspondente ao tipo de seção dele:

- **Lista de traços** (Qualidades, Antecedentes, …) - um item, depois uma escolha entre "o item inteiro" (a aprovação fixa dele) ou "um intervalo de valor específico" (uma entrada de `Aprovação por valor`, endereçada pelos limites exatos `De`/`Até`).
- **Poder em níveis** (Disciplinas, Dons, Esferas, …) - um poder, depois uma escolha entre "o poder inteiro" (o `approval_override` dele) ou "apenas um nível" (o próprio `reason` desse nível - veja a nota acima sobre por que a aprovação fixa de um nível é definida na própria linha de catálogo dele e não duplicada aqui).
- **Reserva de recurso** (Sangue, Força de Vontade, Gnosis, …) - uma reserva, sempre endereçada por um intervalo exato `De`/`Até` no valor **permanente** dela, a mesma regra da versão deste controle no editor de Blocos de Esquema.
- **Campo de identidade** (Clã, Seita, …) - um campo (só os que de fato oferecem opções de verdade), depois uma das opções dele.

### Política de Aprovação Padrão

A mesma página também traz a linha de base da própria crônica: **Pendente por padrão** (o comportamento de sempre - tudo precisa da revisão de um Narrador a menos que uma regra abaixo diga `auto`) ou **Aprovar automaticamente por padrão** (o inverso - tudo é liberado a menos que uma regra abaixo diga `st`). Uma regra granular pode pedir qualquer um dos níveis, para qualquer lado que o padrão da própria crônica esteja definido. (Não há nível de coordenador: uma regra cujo motivo nomeia a aprovação de um coordenador é uma regra de Narrador, e o Narrador obtém essa aprovação.)

Uma regra granular **sempre** vence este padrão, nos dois sentidos - o padrão só se aplica quando nada mais específico (um item, um poder, um nível, um intervalo de valores, uma opção de campo ou o próprio `approval_rules.default` do bloco dono) teve uma opinião, afinal. Isso importa concretamente: passar uma crônica de Pendente para Aprovar automaticamente nunca aprova em silêncio algo que um Narrador tinha sinalizado explicitamente como precisando de revisão, nem uma sinalização sem texto de motivo anexado.

### Remoções, reduções de classificação, reclassificações e renomeações

Uma caixa de seleção à parte ao lado da Política de Aprovação Padrão, desligada por padrão: `settings.approval_on_removal`. Ligada, uma remoção, uma classificação menor, um novo rótulo ou uma troca de nome sempre espera um Narrador, vence toda regra e o padrão igualmente, e é decidida a partir da própria ficha mantida do personagem, nunca do que o pedido do cliente afirma - então uma "adição" forjada que na verdade reduz uma contagem ou nível mantido é pega do mesmo jeito. O editor de um jogador envia todo o seu conjunto de mudanças na fila junto, sob um id de envio compartilhado; depois que qualquer uma mudança do conjunto é pega, o conjunto inteiro espera junto, com o motivo "Parte de uma mudança que remove, reduz ou renomeia algo." A Fila de Aprovação mostra duas ou mais dessas mudanças como um só cartão "Enviadas em conjunto", com o seu próprio Aprovar tudo / Recusar tudo.

### Estatutos de Personagem da OWBN

Uma terceira caixa de seleção ao lado das outras duas, `settings.owbn_bylaws`, desligada por padrão. Nada é gravado no catálogo: os estatutos são um arquivo que vem com o plugin (`data/bylaws/owbn-character-bylaws.json`, montado a partir de um mapeamento conferido à mão guardado no repositório) lido na hora da aprovação, indexado por família de entrada e nome (um slug de bloco sem o prefixo da criatura, então `merits` alcança a cópia própria da lista de cada criatura). `Change_Engine::resolve_rule_level()` o lê como um passo próprio, depois das regras do próprio bloco e antes do padrão da crônica: cada ligação que casa com a compra soma o seu próprio motivo, o de PC ou o de NPC conforme o personagem seja um NPC, sem recuo entre os dois - uma cláusula que diz "NPC: Unregulated" não soma nada para um NPC mesmo que o nível de PC dela exija Aprovação do Coordenador. O motivo próprio da crônica, se uma regra acima já definiu um, vem primeiro; o motivo de cada estatuto vem depois, um por linha, cada um terminando numa citação que leva à cláusula de verdade no council.owbn.net.

Abaixo da caixa de seleção, um botão **Atualizar do council.owbn.net** puxa de novo, ao vivo, toda cláusula do Estatuto de Personagem e grava o resultado como uma opção do site que vence o arquivo que vem com o plugin daí em diante - uma cláusula que ainda está ativa mantém a ligação existente, uma que o council removeu perde o motivo e uma cláusula novinha aparece sem ligação para uma passada curada futura. **Enviar um arquivo** aceita um arquivo já montado no lugar (da mesma forma que `tools/bylaws/build.php` grava), para um site que não alcança o council.owbn.net direto - uma ação própria de um administrador do WordPress (`be_manage_games`), não de um Narrador. Os dois recusam de cara uma fonte malformada e não mudam nada. A mesma seção lista toda regra - cláusula, assunto, nível de PC/NPC, coordenador(es) e a que está ligada, se a algo - filtrada por busca, nível e status de ligação, então o conjunto inteiro continua legível mesmo onde a maior parte dele ainda não está ligada a nada.

## Tradução de Termos do Catálogo

Em **Beyond Elysium → Configuração do Sistema → Traduções**, uma tela gerencia a tradução de todo termo do catálogo - nomes de traços, nomes de poderes, rótulos e opções de campos de identidade - para os idiomas de que uma crônica de fato precisa. Isto é separado da tradução da própria interface do plugin (os rótulos, botões e mensagens de que a interface é feita, que se instalam por um pacote de idioma comum do WordPress): um termo do catálogo como "Fortitude" ou "Alertness" nunca aparece como uma sequência literal em nenhum arquivo-fonte, só como uma linha do banco de dados, então o `wp i18n make-pot` nunca pode vê-lo e um arquivo `.po` nunca pode levá-lo.

**Concedendo acesso.** `be_manage_translations` é uma capacidade própria, independente de `be_manage_schemas` - um voluntário nativo pode ter a confiança de traduzir termos sem também ter a de editar a mecânica do próprio catálogo. Conceda-a a um papel do WordPress, ou adicione-a direto ao papel `administrator`/`editor`, do mesmo jeito que se concede qualquer outra capacidade do Beyond Elysium.

**A tela.** Um seletor de idioma (**+ Adicionar idioma** começa um novo digitando o código do locale, por exemplo `es_ES` - não é preciso instalar nenhum pacote de idioma do WordPress, já que isto são dados do catálogo, não interface) fica ao lado de um botão **Reescanear catálogo**, que percorre de novo todo bloco de esquema - do sistema e a bifurcação própria de cada crônica - e atualiza o índice de termos contra o que o catálogo real e atual de fato contém. Abaixo, uma barra de progresso e uma contagem por status (rascunho / precisa de revisão / aprovado / conflito) para o idioma escolhido. Filtros restringem a tabela abaixo: **Catálogo** (um bloco de esquema ou todos), **Status** (inclusive **Somente não traduzidos**) e uma **Buscar** de texto livre. A tradução de cada linha é um campo de texto simples - digite, clique fora (ou aperte Tab para pular direto para a próxima linha sem tradução) e ela salva. O **Status** de uma linha é a sua própria lista suspensa, editável do mesmo jeito. Caixas de seleção mais **Marcar selecionados como aprovados** aplicam esse status a muitas linhas num clique.

**Ida e volta por CSV.** **Exportar CSV** baixa a visão filtrada atual como `source_text`, `translation`, `status`. **Importar CSV** lê de volta as mesmas três colunas, casadas com o catálogo real pelo nome - um arquivo reordenado ou parcialmente preenchido ainda importa corretamente, e qualquer linha que não casa com um termo real do catálogo é relatada, não descartada em silêncio. Toda importação mostra uma prévia primeiro: um resumo do ensaio (adicionados / atualizados / inalterados / sem correspondência / conflitos) com uma amostra do que mudou, e nada é gravado até **Confirmar importação**.

**Por que um CSV, afinal, se a tabela é a autoridade:** o arquivo é uma conveniência para a passada em lote inicial - um voluntário exporta 500 nomes de Dons de Lobisomem sem tradução, trabalha neles numa planilha durante uma semana, importa, confere as contagens do ensaio, confirma. É uma **exportação de uma tabela ao vivo**, nunca a fonte da verdade - importá-lo não precisa de repositório, de compilação, de implantação nem de desenvolvedor. A edição na própria linha é para a outra metade do trabalho: um Narrador nota um termo errado no meio de uma sessão, busca, corrige um campo, e fica certo em toda ficha, toda bifurcação de crônica e todo PDF impresso no próximo carregamento de página - sem chamado, sem arquivo, sem implantação.

## Movendo um Site Antigo para as Listas por Tipo de Criatura

Os sites montados antes da 1.3.4 compartilham uma lista de Habilidades, uma de Qualidades, uma de Defeitos e uma de Ritos entre todos os tipos de criatura. Cada tipo de criatura agora tem as próprias listas, com preços e agrupamentos próprios, e uma instalação nova começa nelas. **Um site antigo é movido pela própria atualização.** Não há nada a executar e nada a ligar.

Para cada personagem que tem linhas numa lista compartilhada, a atualização:

- **Move as linhas** para a seção própria do tipo de criatura dele, na mesma ordem, com os mesmos pontos.
- **Transforma uma entrada personalizada que claramente é um item do catálogo nesse item.** "Lore: Sabbat", "Briga (Boxe)" e "Meditiation" pegam o preço e as regras do item de verdade. Só uma correspondência segura conta: o mesmo nome a menos de maiúsculas ou pontuação, um nome anterior registrado, uma marca de nota de rodapé no fim, um ritual escrito de outra forma comum ou uma entrada "Base: Rótulo", em que o rótulo vira a especialização ou uma nota. Um palpite nunca conta.
- **Dá a um nome de catálogo a grafia do catálogo.** Se a lista antiga dizia "Fortune-telling" e a nova diz "Fortune-Telling", a linha pega a nova grafia e nada mais nela muda.
- **Deixa tudo o mais como estava.** A produção caseira para a qual o catálogo não tem resposta continua personalizada, mostrada e editável como antes.
- **Nunca toca o XP.** Nem o ganho, nem o não gasto. Nada é reprecificado e ninguém é reembolsado.

Cada personagem que muda ganha uma linha de **Atualização do catálogo** no seu [histórico de alterações](help/sheet-history.md), com as correspondências listadas embaixo, e um instantâneo da ficha como estava. As mudanças que esperam na fila de aprovação são apontadas para as novas seções, e os modelos e tipos de criatura são trocados no fim. Em crônicas de verdade cerca de quatro em cada dez entradas personalizadas casam; o resto continua personalizado e continua funcionando.

A mudança leva alguns segundos. Enquanto roda, as pessoas podem ler mas não salvar: um salvamento recebe "O site está sendo movido para o novo catálogo e não pode salvar nada por um momento. Tente de novo em um minuto."

Quando a atualização não consegue mover todo mundo, ela para, e um aviso no topo de todo o wp-admin diz por quê. As duas causas usuais não gravam nada: um personagem tem uma entrada que a lista própria do tipo de criatura dele não tem (adicione a entrada a essa lista em Blocos de Esquema, ou tire-a do personagem), ou um personagem não pertence a nenhuma crônica (atribua-o a uma, ou exclua-o). A atualização tenta de novo sozinha depois que a causa é corrigida.

Quando todo personagem está movido, os blocos compartilhados de Habilidades, Qualidades e Defeitos e dois blocos que nenhum tipo de criatura lista mais (Lores de Demônio e Numina de Mortal) são excluídos de Blocos de Esquema. Um bloco que algo ainda usa fica, e o log de erros do PHP nomeia o que o usa. Faça um backup do banco de dados antes da atualização, como faria para qualquer uma.

## Combos e Vínculos Movidos pela Atualização 1.3.8

Duas coisas que importações anteriores deixaram no lugar errado são consertadas pela própria atualização, um personagem por vez, cada uma com uma linha no histórico do personagem e um instantâneo da ficha antes dela:

- **Combos arquivados em Disciplinas.** Uma importação do Grapevine costumava manter um combo que não conseguia casar ("Combo: Spy Master", "Combi: Draw Fire" e as outras grafias) como uma escolha personalizada em Disciplinas, impressa com "(ancião)". A atualização move cada um para Disciplinas Combo como um combo personalizado, mantendo o valor que tinha como preço em XP. Um combo que o personagem já tem ali não é dobrado, e uma linha divisória como "Combo Powers-----" fica onde está. Onde a importação perdeu o preço por completo, o combo chega sem preço mostrado; a hospedagem do site pode restaurar esses preços a partir dos arquivos do Grapevine salvos dos personagens.
- **Vínculos guardados só no registro de importação.** A lista de Vínculos de um Vampiro costumava ser guardada no histórico de importação do personagem porque a ficha não tinha onde mostrá-la. A atualização preenche a nova seção Vínculos de cada personagem a partir do registro de importação mais novo dele, quando essa seção ainda está vazia.

O XP nunca é tocado. Um personagem a quem nada se aplica é deixado em paz, e rodar a atualização de novo não muda nada.

## Adicionando um Tipo de Criatura sem Código

Este é o ponto do desenho guiado por esquema: um tipo de criatura novo é configuração, sempre, não uma mudança de código. Todo tipo de criatura que vem com o plugin é somente leitura, em todo lugar, para todos - administrador do site inclusive - então um totalmente novo é montado do mesmo jeito que uma crônica muda qualquer outra coisa no próprio catálogo: restrito a essa crônica, pela Configuração da Crônica.

1. **Construa ou reaproveite blocos de esquema.** Se o novo tipo precisa de traços que nada mais usa ainda (a própria lista de poderes, a própria reserva de recurso), crie antes esses blocos pela [Configuração da Crônica](help/chronicle-setup.md) da própria crônica → Personalização do catálogo - o novo bloco pertence só a essa crônica. O catálogo compartilhado em si é somente leitura para todos, administrador do site inclusive; não há como adicionar a ele diretamente.
2. **Construa a pilha de criatura.** Pela [Configuração da Crônica](help/chronicle-setup.md), ache **Tipos de criatura** e siga o link dele para Pilhas de Criatura - isso abre a tela já restrita a essa crônica. Clique em **+ Nova Pilha de Criatura**, dê a ela um slug e um nome, e monte-a a partir de blocos existentes e novos com **+ Adicionar seção**, definindo o rótulo e a ordem de exibição de cada um. O tipo pertence só a essa crônica; nenhuma outra crônica pode vê-lo nem usá-lo.
3. **Ative-o.** Construir a pilha não a torna disponível sozinha - de volta à linha **Tipos de criatura** da [Configuração da Crônica](help/chronicle-setup.md), ative o novo tipo para que um personagem possa de fato ser criado nele.
4. **As regras de criação**, guardadas na pilha ao lado da lista de seções, são um motor de regras de verdade e imposto - alocação priorizada de atributos, compras orçadas com preço dentro do tipo/fora do tipo, valores iniciais de reservas de recurso e o resto da gramática que o `CATALOG-JSON-FORMAT.md` documenta. Um tipo novo sem nenhuma declarada simplesmente precifica a ficha inicial inteira como XP de construção comum, exatamente como sempre foi.
5. **Um Modelo é opcional, não obrigatório.** Um tipo de criatura sem [Modelo](help/templates.md) autoral ainda renderiza uma ficha, gerada a partir das próprias seções num layout padrão sensato; construa um quando uma crônica quer a ficha arrumada de outro jeito.
6. O novo tipo fica então disponível em todo lugar onde uma pilha de criatura pode ser escolhida para essa crônica - criação de personagem, o filtro da lista, a montagem de consultas - sem mais nenhuma ligação.

O tipo de criatura próprio de uma crônica só pode ser excluído quando nenhum personagem em lugar nenhum é desse tipo - um personagem não pode mudar de tipo, e um cujo tipo sumiu não teria ficha para mostrar, imprimir nem auditar.

Uma coisa que um tipo novo não pode fazer: sair do site. A exportação para o Grapevine e as transferências de crônica para crônica viajam como arquivos de intercâmbio do Grapevine, e o Grapevine não tem raça para um tipo que você inventou, então exportar ou transferir um personagem dele é recusado com uma mensagem que diz isso. Todos os tipos que vêm com o plugin viajam; Bête sai como a Fera com quem divide todos os blocos e volta como Bête no Beyond Elysium.

Todo widget do front-end do plugin (ficha de personagem, editor, lista, fila de aprovação, painel e o resto - quinze no total, o registro de widgets de `src/index.tsx`) lê as mesmas definições de bloco de esquema/pilha de criatura na hora de renderizar. Não há nenhum outro lugar do plugin onde um tipo de criatura precise ser registrado.

## Crônicas de Demonstração

**Beyond Elysium → Configuração do Sistema → Jogos → Editar → Crônica de demonstração.** Um administrador do site pode marcar qualquer crônica para ela se redefinir sozinha a um ponto de partida declarado numa agenda (1, 3, 6, 12 ou 24 horas, com padrão de 6), como a conta de Narrador, com todo e-mail suprimido. Uma redefinição mantém o id, o slug e as configurações da própria crônica - só o conteúdo dela é substituído - e remove qualquer membro que não seja uma das duas contas escolhidas para ela. Marcá-la também cria uma segunda crônica companheira em `{slug}-companion`, com a conta de Narrador como único membro, para ver como é entrar numa crônica vendo de fora; a companheira se redefine e trava junto com a principal.

Uma crônica de demonstração se recusa a ser excluída ou renomeada, a enviar um personagem a outro site ou a aceitar uma oferta de transferência recebida, enquanto a marcação está ligada; o rascunho por IA fica desligado nela, e todo botão de IA ainda aparece e explica isso quando clicado. Todo visitante vê um aviso que nomeia quando ela se redefine em seguida, e o seletor de crônica a rotula. Veja a [Crônica de Demonstração](help/demo-chronicle.md) para o passo a passo completo da tela. Isto não tem relação com a crônica `be-demo` que o plugin semeia uma vez numa instalação nova - aquela é um conjunto fixo e único de personagens de exemplo, sem nenhum mecanismo de redefinição próprio.

## Modelos

Um **modelo** controla como os blocos de uma pilha de criatura são dispostos na ficha renderizada - quais blocos vão em qual coluna, em que ordem e sob qual título de seção. Toda pilha de criatura recebe um modelo padrão sensato automaticamente; os modelos só precisam de edição quando uma crônica quer um arranjo visual diferente do padrão.

Em **Beyond Elysium → Configuração do Sistema → Modelos**, um modelo nomeia uma pilha de criatura, um conjunto de agrupamentos de seção e substituições de coluna/largura/título por bloco. Um modelo também pode referenciar o campo de outro bloco para exibição entre blocos (`title_refs`) ou resolver um nome de exibição por um bloco de consulta (`name_lookup`) - os dois usados para casos como mostrar na mesma linha o nome da Esfera ou Disciplina que governa um poder em vez de só o slug cru dele.

Os modelos vêm com a mesma distinção sistema-contra-bifurcação que os blocos de esquema: o padrão é compartilhado, e uma crônica que quer o próprio layout o bifurca sem afetar o de ninguém mais. O modelo compartilhado em si é somente leitura, para todos, administrador do site inclusive - aqui é sempre **Ver**; **Personalizar para esta crônica**, restrito a uma crônica, é o único jeito de mudar um layout.

## Acesso Restrito à Crônica

Em **Beyond Elysium → Configuração da Crônica → Acesso à Crônica**, um administrador controla:

- A chave do accessSchema de todo o site (ligado/desligado), e se um cliente real do accessSchema foi de fato detectado nesta instalação.
- O `asc_role_path` de cada crônica - o prefixo do caminho do accessSchema dela.
- A filiação à crônica e o papel de cada usuário - **cinco** papéis, não quatro: **HST**, **AST**, **Condutor de Trama**, **Favores** (uma Harpia - conduz só o registro de favores, sem poderes de Narrador sobre personagens ou tramas) e **Jogador**.
- A chave de notificação por crônica. O que ela suprime, e todo outro e-mail que a crônica envia ou deixa de enviar, fica registrado no [Registro de E-mails](help/email-log.md) dessa crônica, que só o HST e o AST dela podem ler.
- **Gerenciamento de Dados** (de todo o site, não por crônica): se desinstalar o plugin também exclui os dados dele, e uma exportação JSON completa de um clique de toda tabela do plugin para um backup ou uma migração.

Se o accessSchema está desligado, não instalado ou inacessível para um dado pedido, toda conferência de permissão recua sozinha para esta tabela de filiação - uma crônica pode rodar inteiramente com as capacidades comuns do WordPress sem nenhum conjunto de plugins do OWBN presente.

Em toda crônica, o HST e o AST adicionam e removem **jogadores** eles mesmos pela aba **Jogadores** do Kit de Ferramentas do Narrador, inclusive revisando pedidos de entrada pela própria seção **Pedidos para entrar** dela; os papéis da equipe ficam aqui. Numa crônica ligada ao accessSchema (accessSchema ligado para o site e um `asc_role_path` na crônica), adicionar um jogador ali também concede `{asc_role_path}/player` pelo cliente accessSchema do owbn-core, e removê-lo o revoga; a aba Jogadores de uma crônica não ligada funciona do mesmo jeito sem nenhuma chamada ao accessSchema tentada. O servidor do accessSchema só aceita uma concessão enviada com a chave de API de leitura e escrita, então a chave que o owbn-core guarda neste site precisa ser essa; com a chave somente leitura, o jogador ainda é adicionado aqui e o Narrador é avisado de que o OWbN recusou a concessão.

## O Que um HST Pode e Não Pode Fazer

Um HST é um `editor` do WordPress, não um `administrator`, e três páginas continuam só para administradores seja qual for o papel na crônica: **Jogos** (criar uma crônica, renomear ou excluir uma), **Acesso à Crônica** (atribuir HST/AST/Condutor de Trama/Favores/Jogador) e qualquer configuração restrita a `be_manage_games`. Isto é de propósito - o `game-roles.php` exclui `be_manage_games` de todo papel de crônica pelo nome, então nenhum HST pode nomear o próprio AST nem para a própria crônica.

Um HST pode alcançar **Blocos de Esquema** e **Modelos** da **Configuração do Sistema** para a personalização da própria crônica - bifurcando um bloco ou um modelo para uma crônica em que tem filiação `hst`. Isso exige que duas coisas sejam verdade: a capacidade de todo o site (`be_manage_schemas`/`be_manage_templates`, concedida a `editor`) e uma linha de filiação de verdade nessa crônica específica. Ter só a capacidade, sem linha de filiação, ainda recebe um `403` - não é uma concessão simples de todo o site, a mesma conferência de duas camadas que toda rota restrita à crônica deste plugin usa. Tanto **Blocos de Esquema** quanto **Modelos** são só de um HST; um AST não tem nenhum dos dois para a própria crônica.

**O que um AST não tem.** Um AST não tem `be_manage_approval_rules`, `be_manage_schemas`, `be_manage_templates` nem `be_delete_characters` para a própria crônica - Regras de Aprovação, personalização de catálogo e modelos (bifurcar um Bloco de Esquema ou um Modelo) e excluir um personagem de vez são só de um HST. Um AST mantém todo o resto que os dois papéis dividem: importação, transferências, edição de personagens e as operações em lote de XP/status/redefinição. Um HST tem acesso de escrita de verdade a três configurações da Configuração da Crônica que de outro modo são só de `be_manage_games` (um administrador do site, sem exceções): **Tipos de criatura**, **Restrições de Subfacção** e **Aprovação de novo personagem** - a capacidade `be_manage_chronicle_setup`, restrita à crônica da mesma forma de duas camadas que todo o resto. Um Condutor de Trama (`be_manage_plots`, não `be_manage_characters`) pode ver e alocar ações para qualquer personagem da crônica dele, não só um que possua como jogador - a lista de personagens de que o Alocador de Ações lê não é restrita aos personagens dele, embora editar, excluir ou criar um personagem ainda exija `be_manage_characters`/`be_delete_characters`, que um Condutor de Trama nunca tem.

## Páginas do Front-End

Quatro páginas do WordPress, criadas automaticamente na primeira vez que o plugin roda (ou atualiza), levam todo widget do front-end: **Minha Crônica** (`be-player`), **Kit de Ferramentas do Narrador** (`be-storyteller`), **Ficha de Personagem (Impressão)** (`character-sheet-print`) e **Verificar Personagem** (`be-verify`). Fixas em quatro seja quantas forem as crônicas que este site hospeda - Minha Crônica e o Kit de Ferramentas do Narrador trazem cada um um seletor de crônica em vez de ficarem presos a uma crônica na criação; veja o [Guia do Narrador](st-guide.md#7-o-painel-do-jogo) para o que vive em cada uma.

Se uma destas páginas for excluída por engano, ela **não** é recriada sozinha automaticamente - uma página, depois de criada num dado slug, nunca é sobrescrita nem substituída. Recupere-a em **Beyond Elysium → Configuração da Crônica**: a linha **Páginas do front-end** da lista de conferência fica vermelha quando qualquer uma das quatro falta, com um link **Corrigir** que refaz o mesmo passo de provisionamento (`?provision_pages=1`, protegido por `be_manage_games`) que o plugin já rodou uma vez automaticamente.

**Um botão flutuante na página.** Um seletor de idioma, um balão de chat ou um alternador de modo escuro fixo na parte de baixo da tela pode cobrir os botões Enviar e Descartar do editor de personagem, e a barra de ações do Gerenciador de Tramas, em um celular. Defina `--be-bottom-clearance` com a altura do botão flutuante, por exemplo `:root { --be-bottom-clearance: 64px; }` no CSS adicional do tema, e as duas barras ficam essa distância acima da base da tela.

## Importar

Em **Beyond Elysium → Importar**, um administrador (não só um Narrador) pode importar um arquivo de jogo completo do Grapevine (`.gv3`) para criar uma crônica novinha, ou um arquivo de intercâmbio de personagem/jogo (`.gex`) do mesmo jeito que um Narrador faria pelo lado da crônica. Veja a [seção de importação do Guia do Narrador](st-guide.md#5-importando-do-grapevine) para o comportamento de detecção de duplicatas e mesclagem, que é idêntico por qualquer das duas superfícies.

## Assistência de Escrita por IA

Um pequeno botão **Assistência de IA** fica ao lado de todo campo de texto livre longo do plugin - Biografia/Notas de personagem, o bloco de Notas de Interpretação de NPC, descrições/suspenses finais/entradas da linha do tempo de tramas, descrições de rumores, Descrição/Limitações/propriedades de texto de Objetos do Mundo, Referência/Descrição/Fonte do catálogo de Blocos de Esquema, o Motivo de uma Regra de Aprovação, a Descrição própria de uma crônica e o texto dos Créditos. Clicar nele abre um pequeno popover: um campo vazio pergunta sobre o que escrever, um campo com texto existente oferece aprimorá-lo. Nada é salvo automaticamente - uma sugestão só chega ao campo depois de um **Aceitar** explícito, e o botão Salvar normal do próprio campo ainda é o que de fato o persiste.

**Só para Narradores, por desenho.** O botão é protegido pela mesma capacidade de nível de gerenciamento que já governa a área própria desse campo (`be_manage_characters`, `be_manage_plots`, `be_manage_world_objects`, `be_manage_schemas`, `be_manage_approval_rules` ou `be_manage_games`) - nunca pela capacidade simples de nível de edição que a rota de salvar do próprio campo aceita. Um jogador que edita a Biografia do próprio personagem, por exemplo, nunca vê este botão, mesmo podendo de outro modo salvar esse campo por conta própria.

**Dois provedores, escolhidos de propósito**: OpenAI primeiro, Claude em segundo - o Gemini foi considerado e descartado. Cada pedido envia só o texto atual de um campo (ou um prompt curto de uma linha para um campo vazio); nada mais sobre o personagem, a crônica ou outros jogadores sai do site.

### Três ferramentas de rascunho, um mecanismo diferente do botão acima

Mais três ferramentas assistidas por IA rascunham um resultado *estruturado* em vez de aprimorar o texto simples de um campo, e cada uma grava em mais de um lugar ao mesmo tempo: um botão **Rascunhar notas de interpretação** na seção de notas só para Narradores de um NPC preenche num só pedido todo campo vazio no momento ali (veja o [Editor de Personagem](help/character-editor.md)); **Rascunhar a partir de uma premissa** em Tramas e Rumores transforma uma premissa de uma linha direto numa trama criada, seus momentos só para Narradores e alguns rumores retidos, sem etapa de prévia (veja [Rascunhar uma Trama a partir de uma Premissa](help/draft-plot.md)); e **Rascunhar resumo** numa noite de jogo preenche o que ainda está vazio do resumo da própria sessão a partir da presença e dos relatórios pós-jogo (veja [Noites de Jogo](help/game-nights.md)). As três compartilham o mesmo provedor, chave e adesão da crônica que o botão acima - não há nada extra a configurar para elas - e o mesmo limite de frequência e o mesmo bloqueio de crônica de demonstração.

### Configurando

**Isto exige uma chave de API de verdade da OpenAI ou da Anthropic - não um login do ChatGPT Plus ou do Claude Pro.** Não há como conectar este recurso a nenhum dos provedores usando em vez disso um login de assinatura de consumidor comum; nenhum dos dois oferece isso como opção, para este plugin ou para qualquer outra pessoa. Os dois são produtos diferentes com cobranças diferentes:

| | Assinatura de consumidor (ChatGPT Plus / Claude Pro) | Chave de API (o que este recurso de fato precisa) |
|---|---|---|
| Onde se obtém | chatgpt.com / claude.ai | platform.openai.com / console.anthropic.com |
| Cobrança | Mensalidade fixa | Paga-se só pelo que é de fato usado - sem mínimo mensal |
| Funciona com este recurso? | **Não - não pode ser usada aqui de jeito nenhum** | **Sim - esta é a única opção suportada** |

Conseguindo uma chave: crie uma conta de desenvolvedor no console da API (não no site de consumidor) do provedor que você quer, adicione um meio de pagamento lá e gere uma chave. Os modelos que este recurso usa por padrão (`gpt-4o-mini` / `claude-haiku-4-5`) são o nível barato de cada provedor, e cada pedido envia só o texto de um campo curto - o uso realista de aprimorar biografias/tramas de vez em quando sai em centavos, não numa linha de orçamento de verdade.

Em **Beyond Elysium → Configuração do Sistema → Assistência de IA** (`be_manage_games`, só para administradores), uma única lista suspensa **Provedor** oferece três opções - só uma é configurada por vez, e só os campos dela aparecem:

- **OpenAI (ChatGPT)** - a API real da OpenAI, que só precisa de uma chave de API.
- **Claude** - a API real da Anthropic, que só precisa de uma chave de API.
- **Auto-hospedado (compatível com OpenAI)** - veja abaixo.

A que estiver escolhida vira o padrão de todo o site, usado direto para todo campo em nível de catálogo (descrições de Bloco de Esquema, texto dos Créditos - nenhum pertence a uma crônica) e como recurso de reserva para qualquer crônica que adere sem fornecer a própria.

Em **Beyond Elysium → Configuração da Crônica → Assistência de IA** (`be_manage_apr` - o mesmo nível de acesso das Configurações de Ação e Rumor, alcançável por um HST sem acesso de administrador do site), ative o recurso para uma crônica e escolha na mesma lista suspensa de três opções para dar a ela, se quiser, a própria configuração, que substitui a do site inteiro para os campos de personagem/trama/rumor/objeto do mundo dessa crônica.

**Uma chave nunca é mostrada de novo depois de salva.** Toda tela de configurações exibe só se uma chave está configurada (um indicador simples de "configurada", nunca o valor) - digite uma chave de novo para mudá-la, ou use **Limpar** para removê-la. Toda chave é criptografada em repouso.

### Usando o seu próprio servidor (auto-hospedado / compatível com OpenAI)

Escolher **Auto-hospedado (compatível com OpenAI)** na lista suspensa Provedor revela três campos: uma **URL base da API**, um nome de **Modelo** e uma chave de API. Aponte a URL base para qualquer servidor auto-hospedado que fale o mesmo formato de pedido/resposta da própria API Chat Completions da OpenAI - Ollama, LM Studio, vLLM, LocalAI ou similar - e nomeie o modelo que esse servidor está rodando. Isto não é um quarto protocolo: por baixo, é o mesmo formato de pedido da OpenAI numa URL diferente, já que toda opção auto-hospedada comum já o fala; não há um equivalente auto-hospedado igualmente comum para a API do próprio Claude, então ela não é oferecida como um sabor auto-hospedado à parte. O campo da chave de API ainda é obrigatório até para um servidor sem autenticação de verdade - muitos aceitam qualquer valor de exemplo (confira a documentação do seu servidor para saber o que ele espera, se algo).

Este é o jeito prático de eliminar por completo o custo de API por pedido: um modelo auto-hospedado não tem cobrança medida, ao custo de rodar (e pagar) o servidor você mesmo. Uma crônica que configura a própria entrada Auto-hospedada é independente do padrão de todo o site; uma crônica que recua para a chave de todo o site também herda o servidor de todo o site, então os dois nunca ficam desencontrados.

**Testar Conexão** - dentro do conjunto de campos que estiver aparecendo - envia um pedido mínimo usando o que você digitou no momento (a chave, e a URL base/modelo para o Auto-hospedado, salvos ou não ainda) e relata sucesso ou uma falha específica, então uma URL com erro de digitação ou uma chave expirada é pega antes de você contar com ela em campo. Nunca testa em silêncio uma chave já salva; uma chave nunca é enviada de volta a esta página depois de salva, então testá-la significa digitá-la de novo primeiro.

## Multisite

O Beyond Elysium roda numa rede multisite do WordPress. Cada site guarda os próprios personagens, catálogo, crônicas, configurações e papéis, totalmente separados de todo outro site da rede - isso não é um modo que se liga, é simplesmente como o plugin guarda as coisas.

Instale-o em toda a rede e deixe-o **não** ativado na rede, e cada site o liga para si. Ativá-lo na rede construiria um esquema completo e um catálogo em todo site, quisesse o site ou não.

Para deixar os administradores de site ativarem-no eles mesmos, ative **Admin da Rede → Configurações → Configurações de Menu → Plugins**. Isso os deixa ativar e desativar plugins só no próprio site; instalar, atualizar e excluir plugins continuam só do super-administrador numa rede de qualquer modo, e os plugins ativados na rede nunca aparecem na tela deles.

Excluir um site leva junto as tabelas dele do Beyond Elysium, e excluir o plugin limpa todo site que ligou "excluir dados na desinstalação" para si - um site que nunca pediu a exclusão mantém tudo, mesmo que outro site tenha pedido.

### Excluindo uma subsite

**Instale o mu-plugin de exportação de site primeiro, antes de qualquer pessoa excluir uma subsite.** É `mu-plugin/be-site-export.php` dentro da pasta do próprio plugin - copie-o para o diretório `wp-content/mu-plugins/` da rede (uma cópia de arquivo simples, sem passo de ativação; o WordPress carrega tudo que está direto dentro dessa pasta em todo pedido, em todo site, esteja o Beyond Elysium ativo ali ou não). Sem ele, excluir uma subsite pelo Admin da Rede ou pelo WP-CLI ainda funciona, mas nada é salvo antes - o WordPress só carrega um plugin comum nos sites em que ele está ativo, então as tabelas da subsite e os arquivos de anexo privados dela somem sem registro, no instante em que o plugin não estava ativo ali quando você clicou em excluir.

Com o mu-plugin no lugar, excluir uma subsite sempre grava antes um zip - um manifesto, toda linha de toda tabela do Beyond Elysium e todo arquivo sob a pasta de anexos privados da própria subsite - numa pasta ao lado da instalação do WordPress (ou para onde `BE_SITE_EXPORT_DIR` no `wp-config.php` apontar em vez disso), protegida do mesmo jeito que a pasta de anexos privados do próprio plugin: travada ao dono dela, um `.htaccess` que nega tudo e um `index.php` em branco. Uma página do Admin da Rede em que você cai logo depois mostra se funcionou; se a pasta não pôde ser gravada, a própria exclusão é recusada e nada é descartado - o site fica, em vez de ser perdido sem backup.

A pasta é recusada de cara se ficaria dentro da raiz web, já que um `.htaccess` que nega tudo não é garantia em todo servidor. Mova-a, ou defina `BE_SITE_EXPORT_DIR` para algum lugar que genuinamente fique fora da raiz web, e tente de novo.

## Impressão Segura

**Beyond Elysium → Configuração do Sistema → Impressão Segura.**

A impressão nunca se recusa. Com a impressão segura desligada, sem certificado instalado, ou numa hospedagem que não consegue assinar de jeito nenhum, fichas e relatórios ainda imprimem pelo mesmo compositor e saem com a mesma aparência - toda página carimbada com NÃO ASSINADO. Uma impressão sem assinatura nunca pode ser confundida com uma assinada, e uma crônica que nunca vai ter um certificado não fica sem poder imprimir.

Uma impressão só é assinada quando **as duas** coisas são verdade:

1. Um certificado utilizável está configurado, por três constantes do `wp-config.php`.
2. Um administrador marcou **Assinar fichas e relatórios impressos** nessa tela.

A chave é separada do certificado de propósito. Um certificado chegar ao servidor não é o mesmo que uma decisão de assinar com ele - você pode estar testando um, ou ter herdado um de quem administrava o site antes. Ela vale para todo o site em vez de por crônica, porque o certificado vale para todo o site; uma chave por crônica implicaria certificados por crônica, o que multiplica a única coisa realmente delicada aqui.

### Instalando um certificado

O plugin nunca guarda a sua chave privada. Ela nunca é enviada pelo navegador, nunca é gravada no banco de dados e nunca é guardada na pasta de envios. Ponha os dois arquivos fora da raiz web por SFTP e aponte três constantes para eles:

```php
define( 'BE_PDF_SIGNING_CERT', '/home/you/private/be-signing.crt' );
define( 'BE_PDF_SIGNING_KEY', '/home/you/private/be-signing.key' );
define( 'BE_PDF_SIGNING_PASSPHRASE', 'your passphrase' );
```

Deixe a terceira de fora se a chave não tem senha - é uma configuração real, não um engano. Com acesso ao shell, este é o comando que as duas crônicas de produção usaram:

```sh
openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 \
  -keyout be-signing.key -out be-signing.crt -cipher aes-256-cbc
```

### Hospedagens sem shell

Muita hospedagem compartilhada não dá linha de comando, então `openssl req` não está disponível - e isso, não saber onde pôr um arquivo, é o que deixa uma crônica sem impressão assinada para sempre. A tela cria um par autoassinado **na memória** e o entrega a você uma única vez, com as constantes para colar. Nada é gravado no servidor nem salvo no banco de dados. Copie os dois arquivos antes de sair da página; pedir de novo cria um certificado diferente.

Isto exige a extensão `openssl` do PHP, que não é uma exigência extra que o recurso inventa: um PDF é assinado por essa mesma extensão, então uma hospedagem sem ela não pode assinar uma ficha, venha de onde vier o certificado. Onde ela falta, a tela diz isso em vez de oferecer um botão que não pode funcionar.

Veja a página de ajuda [Impressão Segura](help/secure-printing.md) para o passo a passo completo.

## Quem Pode Ver uma Trama, Item ou Local

Toda trama, item e local tem uma configuração **Quem pode ver isto**: **Todos na crônica**, **Somente Narradores e Condutores de Trama** ou **Somente personagens que correspondem às regras que eu defini**. Uma trama nova começa só para Narradores; um item ou local novo começa aberto a todos. Ampliar a trama própria de um jogador é só do Narrador - o jogador dono dela nunca pode ampliá-la ele mesmo, embora receba tudo o mais que uma trama global tem (respostas públicas e privadas, postagens direcionadas, envios).

Escolher **Somente personagens que correspondem às regras que eu defini** abre o mesmo construtor de consultas de cláusula e valor que a Ferramenta de Consulta usa, contra os personagens da sua crônica - "Clã é Tremere", "Seita é Sabbat", ou várias cláusulas combinadas com E/OU. Uma contagem ao vivo mostra quantos personagens correspondem no momento enquanto você monta, mais um lembrete de que um personagem diretamente conectado à trama, item ou local (um dono, um detentor, um co-narrador convidado) sempre o vê também, corresponda ou não à regra. Uma regra sem nenhuma cláusula completa ainda é tratada como "nenhuma regra definida" em vez de bloquear o salvamento - adicione pelo menos uma cláusula completa antes de ela de fato restringir algo.

Uma entrada de trama (uma resposta na Linha do tempo) tem a própria escolha de três vias, à parte: **Pública** (todos que podem ver a trama), **Somente Narradores e Condutores de Trama** (privada para você, outros gerentes e o autor da própria entrada) ou, só para Narradores, **Direcionado a personagens específicos** - escolha numa lista de quem pode ver a trama no momento, afinal. Um jogador que compõe a própria entrada só vê as duas primeiras escolhas.

## Envio de Arquivos

Tramas, itens e locais podem levar, cada um, arquivos enviados - imagens e PDFs, 10 MB cada. Uma trama ou local pode levar até 20; um item leva exatamente um. Um envio segue automaticamente o público da própria entidade dele: quem pode abrir a trama, item ou local pode abrir o que está anexado a ele, e mais ninguém. O controle de envio é uma seção **Arquivos** na visão de detalhe da trama (Kit de Ferramentas do Narrador → Tramas e Rumores) e no painel de detalhe de um item ou local (Itens e Locais); um Narrador, ou o dono da trama de um jogador, vê um botão de enviar e um botão Remover por arquivo, e todos os demais que podem ver a entidade veem só a lista e um link de download.

Estes arquivos nunca passam pela biblioteca de mídia do WordPress, porque um arquivo da biblioteca de mídia é uma URL pública que qualquer um pode abrir, seja o que for que este plugin decida. Em vez disso, cada um é gravado na própria pasta de nome aleatório sob `wp-content/uploads/beyond-elysium-private/`, servido só por um pedido autenticado que reconfere o público da entidade dona toda vez - nunca por um link direto.

**Leia isto se a sua hospedagem roda nginx.** A pasta privada vem com um arquivo `.htaccess` que diz ao Apache para recusar todo pedido direto a ela. O Apache o respeita automaticamente. **O nginx não lê o `.htaccess` de jeito nenhum**, então numa hospedagem nginx essa regra não faz nada por si só - o que ainda fica entre um estranho e um arquivo é que a pasta dele tem 32 caracteres hexadecimais aleatórios no nome, nunca mostrados em lugar nenhum, num caminho que ninguém tem motivo para adivinhar. Isso é proteção de verdade, mas é inadivinhável, não travada como no Apache. Se a sua hospedagem roda nginx e você quer a mesma garantia no nível do servidor que o Apache recebe de graça, adicione uma regra à configuração nginx do seu próprio site negando pedidos diretos sob `uploads/beyond-elysium-private/`; pergunte à sua hospedagem se não tem certeza de qual servidor web você usa.

## Jogadores Propondo Itens

Um jogador pode propor um item, local ou ritual para o próprio personagem em **Minha Crônica → Propor um Item**. Ele chega como uma mudança comum na Fila de Aprovação e não numa lista à parte.

Aprovar um grava o catálogo da crônica, então exige **tanto** `be_manage_characters` (para trabalhar a fila, afinal) quanto `be_manage_world_objects` (para gravar o catálogo). Um HST e um AST têm os dois. Um revisor com direitos de personagem mas sem direitos de catálogo vê a linha e pode rejeitá-la, mas não aprová-la - senão os direitos de aprovação de personagem virariam em silêncio direitos de escrita no catálogo.

Aprovar cria a linha do catálogo e a conexão do personagem com ela numa só transação: o jogador pediu que o personagem tivesse a coisa, então uma entrada de catálogo sem a conexão seria só metade do que foi aprovado.

## API REST

Toda leitura e escrita do plugin passa pela sua API REST (namespace `be/v1`), da qual todo widget acima é um cliente fino - nada na interface de administração ou do Narrador faz algo que a API em si não exponha também. Veja a [referência da API REST](rest-api.md) para a lista completa de endpoints, parâmetros e exigências de permissão.
