# Pilhas de Criatura

A lista de montagem da ficha de um tipo de criatura inteiro: quais seções de blocos de esquema ele usa, em que ordem, e quaisquer regras especiais para montar um personagem desse tipo. Um tipo de criatura novinho passa a existir aqui, sem nenhum código.

## Quem pode usar

Um administrador do site gerencia o catálogo compartilhado de que toda crônica se serve - a versão desta tela a que se chega direto, sem crônica escolhida. Todo tipo de criatura que vem com o plugin vive ali, somente leitura: ninguém, nem mesmo um administrador do site, pode mudar um diretamente. Uma crônica muda como um tipo que vem com o plugin funciona para si mesma sobrepondo uma camada, em [Configuração da Crônica](chronicle-setup.md).

Os Narradores (HST e AST) chegam à mesma tela já restrita à própria crônica, normalmente seguindo o link Tipos de criatura da [Configuração da Crônica](chronicle-setup.md). Dali podem construir um tipo de criatura genuinamente novo que pertence só à crônica deles - com slug, nome e seções próprios, sem nenhum equivalente que vem com o plugin - ou editar a camada própria da crônica sobre um tipo que vem com ele. Abrir esta tela sem crônica escolhida mostra a um Narrador a mesma tabela em modo somente leitura, com uma nota que o aponta para a Configuração da Crônica.

Um Condutor de Trama, a Harpia de uma crônica e um jogador nunca veem esta tela.

## Como chegar lá

- Administrador do site, vendo o livro: barra lateral do wp-admin → Beyond Elysium → Configuração do Sistema → aba Pilhas de Criatura.
- Narrador, construindo ou editando os tipos de criatura da própria crônica: barra lateral do wp-admin → Beyond Elysium → Configuração da Crônica, escolha a sua crônica, ache **Tipos de criatura** e siga o link dele para Pilhas de Criatura. Isso abre a mesma aba já restrita à sua crônica.

## A tela

- **Ocultar pilhas do sistema (N)** - uma caixa de seleção; marcá-la esconde os tipos de criatura que vêm com o próprio Beyond Elysium, deixando só os personalizados.
- Um aviso que nomeia a qual crônica você está restrito, quando uma está escolhida.
- Uma tabela: **Nome**, **Slug**, **Linha de Jogo**, **Sistema?** (Sim/Não), **Ações**.
  - **Editar** (ou **Ver**, sem crônica escolhida) abre a pilha abaixo.
  - **Restaurar para o livro** - só para a camada própria de uma crônica sobre um tipo que vem com o plugin; descarta a camada, então o tipo volta a ser lido como o do livro.
- **+ Nova Pilha de Criatura** - só com uma crônica escolhida. Abre um formulário em branco para um tipo de criatura que pertence só a essa crônica.

### O formulário de criação

- **Nome**.
- **Slug** - permanente depois de definido.
- **Linha de Jogo** - texto livre. Toda pilha que vem com o plugin é `met`, o único conjunto de regras que o Beyond Elysium roda hoje.
- Tabela de **Seções**, montada localmente até você salvar: **Bloco** (uma lista suspensa de todo bloco de esquema que você pode ler, os blocos personalizados da própria crônica inclusive), **Rótulo**, **Ordem**, **Obrigatório**, **Bloco negativo** (opcional), **Dentro do tipo** e **Remover**. **+ Adicionar seção** acrescenta uma nova linha. Pelo menos uma seção é obrigatória.
- **Regras de criação** - um editor passo a passo de como um personagem novo desse tipo é montado. Veja [O editor de regras de criação](#o-editor-de-regras-de-criação) abaixo.
- **Criar** / **Cancelar**.

### A visão de edição

- Com uma crônica escolhida, **Nome** e **Linha de Jogo** são mostrados mas fixos depois de definidos - para um tipo que vem com o plugin são os do próprio livro, para um tipo de criatura seu são o que você deu na criação.
- A mesma tabela de **Seções**. **Oculto** (na última coluna), **+ Adicionar seção** e o **Remover** de uma seção salvam, cada um, no instante em que você os usa - não há Salvar à parte para eles. **Rótulo**, **Ordem**, **Obrigatório**, **Bloco negativo** e os testes **Dentro do tipo** de uma seção ficam preparados aqui e só valem quando você clica em **Salvar** abaixo, junto com qualquer mudança nas Regras de criação.
  - As seções de um tipo que vem com o plugin podem ser ocultadas mas nunca removidas; as seções do seu próprio tipo de criatura podem ser adicionadas e removidas livremente, já que nenhuma vem de um livro.
- O mesmo editor de regras de criação do formulário de criação.
- **Salvar** / **Fechar**, embaixo - só **Salvar** grava as mudanças de Rótulo/Ordem/Obrigatório/Bloco negativo/Dentro do tipo/Regras de criação.

### O teste dentro do tipo

O botão **Dentro do tipo** de cada seção abre um pequeno editor para a regra que decide se uma compra dessa seção conta como dentro do tipo ou fora do tipo - a diferença por trás de as Disciplinas do próprio clã custarem menos que as de outro clã, dos Dons de raça do próprio Garou, da Esfera de especialidade do próprio Mago e similares. Deixá-lo vazio quer dizer que tudo nessa seção é dentro do tipo, igual a uma seção sem essa regra hoje.

- **+ Adicionar teste** adiciona uma linha. Cada linha escolhe um **tipo** - `names` (o nome da própria entrada), `facet` (o `group` ou `subgroup` dela, para um bloco que os tem), `chosen` (algo que o jogador escolheu na criação, como uma escolha dentro do clã) ou `all` (todo teste aninhado precisa passar) - e, para todo tipo menos `chosen`, de onde vêm os valores: um campo do personagem (`field`), uma tabela de consulta guardada num campo de identidade (`map`) ou uma lista fixa (`constant`).
- Uma condição opcional **Limitar a um campo** só roda o teste quando um campo nomeado tem, ou não tem, um valor, ou está definido ou não - necessária num caso como o de um ghoul, que é um Mortal sem Família Revenant, em que "não definido" em si é o estado que importa e não um valor a ignorar.
- **Remover** exclui um teste; **Salvar** no modal o prepara na seção - ele ainda não é gravado até o botão **Salvar** principal abaixo.

### O editor de regras de criação

Uma lista reordenável de passos que descreve como um personagem novo desse tipo de criatura é montado - o que um jogador escolhe, o que uma reserva de pontos livres cobre, o que o cálculo de construção de um Narrador deve sinalizar. Todo gênero que o Beyond Elysium traz declara as próprias regras reais assim; construir o seu tipo de criatura do zero funciona do mesmo jeito, ou pode ser deixado vazio para "nada de especial - precificar tudo ao custo de compra comum."

- **+ Adicionar etapa** acrescenta um passo; **Subir** / **Descer** o reordenam; **Remover** o exclui.
- Cada passo tem um **tipo**, escolhido numa lista suspensa, e os campos próprios desse tipo:

| Tipo | O que faz |
| --- | --- |
| `prioritized` | Atribui um conjunto fixo de valores a uma lista de seções, o maior primeiro - Atributos 7/5/3 |
| `budget` | Cobre de graça uma quantidade fixa de entradas de uma seção, opcionalmente filtrada só para dentro do tipo, um teto de nível ou um teste dentro do tipo nomeado, e pode acompanhar cotas (pelo menos um Dom de raça, auspício e tribo) |
| `free` | Gasta uma reserva nomeada de pontos no que nenhum passo de orçamento cobriu, a uma taxa por seção ou por entrada |
| `earned` | Soma pontos a uma reserva nomeada a partir do que o personagem já tem - o valor de um Defeito, uma desordem, um traço negativo |
| `limit` | Sinaliza uma seção ou entrada que passa do total de pontos, passa ou fica abaixo de uma classificação, ou fica acima da classificação de outra entrada - um aviso para o Narrador, nunca um bloqueio |
| `start` | Define o valor inicial de uma entrada - um número fixo, uma consulta pelo valor de outro campo ou uma fórmula (a maior de duas Virtudes, a média de duas arredondada para cima e assim por diante) |
| `grant` | Dá ao personagem uma entrada fixa, ou uma escolhida de uma tabela de consulta, que não custa nada e não conta contra nenhum orçamento |

- Um passo pode trazer uma condição **Somente quando**, da mesma forma que a condição de campo do teste Dentro do tipo acima.
- **Mostrar avançado (JSON bruto)** mostra o documento inteiro como texto, para um passo que os campos próprios deste editor ainda não cobrem; **Aplicar JSON** confere se é válido antes de substituir o que está acima.
- O que este editor guarda só vale quando você clica no botão **Salvar** principal.

A construção ao vivo de um personagem - o que cada passo ainda precisa, o saldo de cada reserva e o que resta a comprar ao preço comum - aparece como um painel **Cálculo da Construção** ao lado do formulário de criação de personagem, e de novo para um Narrador que revisa um personagem pendente. Nada que ele relata bloqueia um salvamento; uma construção estourada simplesmente começa abaixo de zero, para o Narrador ver por quê.

## Tarefas comuns

### Construir um tipo de criatura totalmente novo para a sua crônica

1. Primeiro, construa os [blocos de esquema](schema-blocks.md) de que o novo tipo precisa e que nada mais usa ainda.
2. Abra a [Configuração da Crônica](chronicle-setup.md) da sua crônica, ache **Tipos de criatura** e siga o link dele para Pilhas de Criatura.
3. Clique em **+ Nova Pilha de Criatura**.
4. Digite um **Nome**, **Slug** e **Linha de Jogo**.
5. Clique em **+ Adicionar seção** para cada bloco de que a ficha precisa, escolhendo o **Bloco**, o **Rótulo** e a **Ordem**.
6. Clique em **Criar**.
7. De volta à linha **Tipos de criatura** da Configuração da Crônica, ative o seu novo tipo para que personagens possam de fato ser criados nele.
8. Construa um [Modelo](templates.md) para ele, para a ficha dele se dispor do jeito que você quer e não num padrão gerado.

### Mudar como um tipo de criatura que vem com o plugin funciona para a sua crônica

1. Abra a [Configuração da Crônica](chronicle-setup.md) da sua crônica, ache **Tipos de criatura** e siga o link dele para Pilhas de Criatura.
2. Clique em **Editar** no tipo que vem com o plugin.
3. Oculte uma seção, adicione uma sua ou mude as regras de criação dele.
4. As mudanças aqui salvam de uma vez; não há botão **Salvar** à parte para seções.

### Dar a uma seção uma contraparte negativa

1. Edite a pilha.
2. Na seção, escolha o **Bloco negativo** dela (por exemplo, pareando Qualidades com Defeitos).

### Excluir o seu próprio tipo de criatura

1. Ache-o na tabela, restrita à sua crônica - só oferecido para um tipo de criatura que a sua crônica construiu, nunca para um que vem com o plugin.
2. Clique em **Excluir** e confirme.
3. Se algum personagem em qualquer lugar ainda é esse tipo de criatura, nada é excluído - a tela diz quantos. Um personagem não pode mudar de tipo de criatura, então esses personagens precisam ser excluídos primeiro.

## O que saber

- **Um tipo de criatura que vem com o plugin é somente leitura em todo lugar, para todos.** Uma crônica muda como um funciona para si com a camada própria; ninguém edita o livro diretamente, administrador do site inclusive.
- **Um tipo de criatura que a sua crônica construiu pertence só a essa crônica.** Não tem equivalente no livro, e nenhuma outra crônica pode vê-lo, ativá-lo ou usá-lo.
- **Um tipo de criatura que você inventa não pode sair do site.** A exportação para o Grapevine e a transferência de crônica para crônica viajam as duas como um arquivo do Grapevine, e o Grapevine não tem raça para um tipo inventado - exportar ou transferir um personagem dele é recusado. Todo tipo que vem com o plugin viaja; uma Bête sai como a Fera com quem divide todos os blocos e volta como Bête.
- **Um tipo de criatura que personagens usam não pode ser excluído.** As fichas, auditorias e impressões deles dependem dele, então a exclusão espera até nenhum personagem em lugar nenhum ser desse tipo.
- **Construir a pilha não a torna disponível sozinha.** Uma crônica ainda precisa ativar um novo tipo de criatura na linha Tipos de criatura da [Configuração da Crônica](chronicle-setup.md) antes de um personagem poder ser criado nele.
- **Um tipo que vem com o plugin vem ligado por padrão, a menos que se declare desligado.** Uma crônica nova oferece todo tipo de criatura que vem com o plugin, exceto um que se declara desligado por padrão; nenhum faz isso hoje.
- **Various é o tipo de criatura só para Narradores.** Um Narrador recebe a oferta ao criar um personagem em toda crônica, seja o que for que a crônica ativou, e nenhum jogador - nem no seletor de criação, nem num arquivo que um jogador envia, nem pela API. Ele não tem regras de criação, então nada nele é precificado. Pode ter **qualquer seção de qualquer tipo de criatura**, então um Narrador pode dizer "esta criatura tem Disciplinas e Dons" e montá-la com as seções que servirem. A ficha dele já lista as famílias de poder de todo tipo de criatura (Disciplinas, Magia de Sangue, Dons, Artes, Reinos, Esferas, Arcanoi, Lores, Edges, Shintai, Hekau, Fenômenos Psíquicos, Magia da Sebe, Artes Marciais, Teurgia, Poderes Fomori e Bioaprimoramentos), e uma lista cada para Habilidades, Antecedentes, Qualidades, Defeitos e Tempers que contém as entradas de todo tipo de criatura, cada nome uma vez; **Outros Poderes** é a lista livre para entradas que nenhum catálogo lista. Todo o resto (rituais, rotes, Disciplinas combinadas) vem de **Adicionar uma seção** no [editor de personagem](character-editor.md). A linha Tipos de criatura da Configuração da Crônica deixa Various de fora, já que ele está sempre disponível a um Narrador.
- **Ainda não ter Modelo é normal.** Um tipo de criatura sem [Modelo](templates.md) autoral ainda gera uma ficha, montada a partir das próprias seções; construa um quando quiser que ela se disponha diferente.
- **A posição de uma seção aqui é só o layout padrão.** Uma crônica que quer a ficha arrumada de outro jeito a substitui em [Modelos](templates.md) em vez de mudar a pilha.
- **Ocultar, adicionar ou remover uma seção salva na hora; todo o resto espera o Salvar.** O Rótulo, a Ordem, o Obrigatório, o Bloco negativo e os testes Dentro do tipo de uma seção, e o editor de Regras de criação inteiro, ficam todos preparados até você clicar no botão **Salvar** principal - feche a tela sem salvar e eles se perdem.
- **Oculto só bloqueia compras novas - nunca esconde o que um personagem já tem.** Um personagem que já tem algo nessa seção ainda o vê na ficha, na impressão e exportação, na auditoria de pontos e na fila de aprovação exatamente como antes. Só adicionar uma entrada nova, ou subir uma contagem, nível ou classificação, é recusado ali; reduzir, remover e aprovar uma mudança enviada antes de ela ser ocultada continuam funcionando.
- **Não ter regras de criação é uma escolha real e suportada, não uma lacuna.** Um tipo de criatura sem nada no editor de Regras de criação precifica toda compra ao custo comum, sem reservas livres, orçamentos nem cálculo de construção - exatamente como todo tipo de criatura se comportava antes de este editor existir.
- **As regras de criação nunca bloqueiam um salvamento.** Uma construção estourada simplesmente começa abaixo de zero de XP; o Narrador que a revisa vê exatamente por quê, em vez de o jogador ser impedido.

## Solução de problemas

- **Não vejo + Nova Pilha de Criatura, ou Editar só oferece Ver.** Você chegou aqui sem crônica escolhida - passe pelo link Tipos de criatura da Configuração da Crônica.
- **Não vejo esta aba de jeito nenhum.** A Configuração do Sistema exige uma conta de administrador do WordPress, ou um papel de Narrador em alguma crônica.
- **"Já existe uma pilha de criatura com este slug."** Os slugs são únicos na instalação inteira, tipos que vêm com o plugin e construídos por crônicas igualmente - escolha outro.
- **"block_slug desconhecido."** Uma seção nomeou um bloco que a sua crônica não pode ler - construa antes esse [bloco de esquema](schema-blocks.md), ou escolha um que já existe.
- **"Este tipo de criatura ainda é mantido por pelo menos um personagem."** Exclua primeiro esses personagens, ou mantenha o tipo.
- **Mudei os testes Dentro do tipo de uma seção ou um passo das regras de criação, e não pegou.** Esses dois ficam preparados localmente até o botão **Salvar** principal - ocultar, adicionar ou remover uma seção é o único tipo de mudança de seção que salva na hora.

## Relacionados

- [Blocos de Esquema](schema-blocks.md)
- [Modelos](templates.md)
- [Configuração da Crônica](chronicle-setup.md)
- [Jogos](games.md)
- [Auditoria de Pontos](point-audit.md)
- [Importar/Exportar do Grapevine](grapevine.md)
- [Guia do Administrador: Blocos de Esquema e Pilhas de Criatura](../admin-guide.md#blocos-de-esquema-e-pilhas-de-criatura)
- [Guia do Administrador: Adicionando um Tipo de Criatura sem Código](../admin-guide.md#adicionando-um-tipo-de-criatura-sem-código)
- [Guia do Administrador: Regras de Criação](../admin-guide.md#regras-de-criação)
