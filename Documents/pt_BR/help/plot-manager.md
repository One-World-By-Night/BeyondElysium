# Tramas e Rumores

Onde vivem as tramas de uma crônica: crie uma, leia e responda ao que os jogadores postam, aloque as ações da noite de jogo e gere rumores.

## Quem pode usar

Os Narradores (HST e AST) e os Condutores de Trama. Um jogador nunca vê esta tela - as tramas e rumores a que ele está ligado aparecem em [Minhas Tramas e Rumores](my-plots.md). A Harpia da crônica também não vê esta aba, a menos que tenha também um papel de Narrador ou de Condutor de Trama.

## Como chegar lá

Kit de Ferramentas do Narrador → aba Tramas e Rumores.

## A tela

Esta aba tem duas visões: uma grade geral de todas as tramas e a visão de detalhe de uma única trama.

### Visão geral das tramas (a visão padrão)

- **Alocar ações** / **Gerar rumores** / **Rascunhar a partir de uma premissa** - abrem a ferramenta correspondente num painel sobre esta tela. Veja [Alocar Ações](allocate-actions.md), [Rumores](rumors.md) e [Rascunhar uma Trama a partir de uma Premissa](draft-plot.md).
- **+ Nova trama** - abre um formulário: um título (obrigatório), uma descrição em texto rico (com um botão Assistência de IA) e uma imagem de capa opcional. Se a sua crônica ligou os [Recursos de Trama](chronicle-setup.md), uma lista suspensa **Categoria** também aparece - **Trama comum** (o padrão) ou **arco**, **subtrama**, **temporada**, **episódio**; escolher subtrama ou episódio acrescenta uma lista suspensa obrigatória de trama-mãe, já que esses dois só fazem sentido aninhados sob algo. **Criar trama** salva; **Cancelar** fecha o formulário sem salvar.
- Filtros: **Status** (todos, ou `active`, `resolved`, `archived`), um filtro de quem iniciou (o padrão mostra os dois; restrinja a `player` ou `st`), um filtro de personagem (**Todas as tramas**, **Somente tramas de personagem** - a trama própria de cada personagem e suas rodadas de ação - ou **Sem tramas de personagem**) e uma busca de texto em título e descrição. Mudar qualquer um recomeça na página 1.
- Uma grade de cartões de trama - imagem de capa (ou um espaço em branco), título e selos de status, categoria (se os Recursos de Trama estão ligados e uma foi definida) e se foi iniciada por jogador ou por Narrador. Clique num cartão para abri-lo.
- **Anterior** / **Próximo**, 24 tramas por página, com a página atual e a contagem total.

### Uma única trama (visão de detalhe)

- **Todas as tramas** - um link de volta à grade.
- Imagem de capa, com **Adicionar uma imagem de capa…** / **Trocar capa…** para você definir ou substituir.
- **Quem recebeu e-mail sobre esta trama** - um link sob o botão de capa, para um HST ou AST, para o [Registro de E-mails](email-log.md) restrito a esta trama: todos que receberam e-mail sobre uma postagem nela, e todos que não receberam, com o motivo.
- Título, depois um selo de status, se foi iniciada por jogador ou por Narrador e uma data de jogo se a trama tem uma.
- **Visão Geral** - o texto da trama. **Editar visão geral** abre uma caixa de texto rico (com Assistência de IA); **Salvar** ou **Cancelar**.
- **Notas do Narrador** - uma caixa de texto rico (com Assistência de IA) sempre disponível a você e a outros Narradores e Condutores de Trama, nunca a um jogador, mesmo um que de resto pode ler a trama. Escreva, depois **Salvar notas do Narrador** - vazia até alguém escrever algo, igual ao Suspense final.
- **Quem pode ver isto** - **Todos na crônica**, **Somente Narradores e Condutores de Trama** (o padrão de uma trama nova) ou **Somente personagens que correspondem às regras que eu defini**, a última abrindo um construtor de consultas contra os seus personagens com uma contagem ao vivo de quem corresponde no momento. Escolha, depois **Salvar visibilidade**. A trama própria de um jogador mostra o mesmo controle, mas só um Narrador pode de fato mudá-lo - o dono nunca amplia a sua própria.
- **Arquivos** - quaisquer imagens ou PDFs anexados a esta trama, até 20, de 10 MB cada, com um link de download por arquivo. Você, ou o dono da trama de um jogador, ganha uma caixa de envio e um botão **Remover** por arquivo; todos os demais que podem ver a trama só veem a lista.
- **Objetivos da facção** - mostrado só quando os [Recursos de Trama](chronicle-setup.md) estão ligados, e só para gerentes. Uma lista repetível de facção, o que ela quer e NPCs-chave opcionais; **+ Adicionar objetivo de facção** adiciona uma linha em branco, **Remover** tira uma, **Salvar objetivos** grava a lista. Nunca mostrado a um jogador.
- **Sob esta trama** - quaisquer tramas aninhadas sob esta (uma ação alocada, um rumor ou outra trama aninhada à mão), cada uma um cartão clicável que abre do mesmo jeito.
- **Linha do tempo** - toda entrada até agora, a mais antiga primeiro: o tipo (`action`, `response`, `note` ou `resolution`), um selo se é **Privada** ou **Direcionado** (nada aparece para uma entrada pública comum), a data e o conteúdo. Uma entrada criada por Alocar ações ou por um uso de antecedente aparece como um resumo curto do que foi gasto e do resultado, em vez de texto cru. Abaixo dela, um formulário com uma caixa de texto rico deixa você postar uma nova entrada - um gerente pode escolher `response`, `note`, `resolution` ou `action`; um jogador, no próprio feed, só pode postar `action`. Com os Recursos de Trama ligados, este formulário também oferece uma data de linha do tempo opcional, separada de quando a entrada foi de fato postada. Toda entrada também tem a sua própria escolha **Quem pode ver esta entrada**: você tem **Pública**, **Somente Narradores e Condutores de Trama** ou **Direcionado a personagens específicos** (escolha entre quem pode ver a trama no momento); um jogador que compõe a própria entrada só vê as duas primeiras, e o autor da entrada sempre a vê, seja qual for a escolha.
- **Suspense final** - uma caixa curta de texto rico ("O que ficou sem solução…", com Assistência de IA) e o seu próprio botão **Salvar suspense final**.
- **Segredos** - um título e um texto que você mantém separados do texto da própria trama, com o seu próprio **Quem pode ver isto** e um **Revelar** a qualquer personagem (opcionalmente retido para um lote de lançamento). Um jogador só vê os segredos que lhe foram revelados, em **O Que Você Sabe** - veja [Segredos](secrets.md).
- Barra de ações: **+ Rumor**, **+ Ação**, **Conectar personagem** e **Marcar como resolvido** (diz **Resolvido** depois de clicado).

## Tarefas comuns

### Criar uma trama

1. Abra Kit de Ferramentas do Narrador → Tramas e Rumores.
2. Clique em **+ Nova trama**.
3. Digite um título e, se quiser, uma descrição.
4. Se quiser, adicione uma imagem de capa.
5. Clique em **Criar trama**.

### Responder à ação de um jogador

1. Abra a trama em que a ação foi postada.
2. Leia-a em **Linha do tempo**.
3. Escolha `response` na lista suspensa de tipo de entrada, escreva a resposta e clique em **Publicar**.

### Adicionar uma nota que só Narradores e Condutores de Trama podem ver

1. Abra a trama.
2. Escolha `note` na lista suspensa de tipo de entrada.
3. Escreva e clique em **Publicar**.

### Marcar uma trama como resolvida

1. Abra a trama.
2. Clique em **Marcar como resolvido**.

### Conectar um personagem a uma trama

1. Abra a trama.
2. Clique em **Conectar personagem**. Veja [Conexões](connections.md).

### Achar a trama de um personagem, ou deixá-las todas de fora

1. Escolha **Somente tramas de personagem** no filtro de personagem para ver a trama própria de cada personagem e suas rodadas de ação, ou **Sem tramas de personagem** para ver as outras tramas da crônica.
2. Digite o nome do personagem na caixa de busca para ir direto à dele.

### Enviar uma resposta que só um jogador vê

1. Abra a trama.
2. Escreva a resposta, escolha **Somente Narradores e Condutores de Trama** na lista suspensa de público da própria entrada e clique em **Publicar**. Só você, outros gerentes e o autor da entrada podem lê-la.

### Direcionar uma mensagem só a personagens específicos

1. Abra a trama.
2. Escreva a resposta e escolha **Direcionado a personagens específicos**.
3. Marque quem deve vê-la na lista (só aparecem personagens que podem ver esta trama no momento) e clique em **Publicar**.

### Mudar quem pode ver uma trama

1. Abra a trama.
2. Em **Quem pode ver isto**, escolha uma nova opção - para **Somente personagens que correspondem às regras que eu defini**, monte antes pelo menos uma cláusula completa.
3. Clique em **Salvar visibilidade**.

### Anexar um arquivo a uma trama

1. Abra a trama.
2. Em **Arquivos**, clique em **Escolher arquivo** e escolha uma imagem ou PDF (10 MB no máximo, até 20 arquivos por trama).
3. Ele aparece na lista na hora, com um link de download. Clique em **Remover** para tirá-lo de novo.

### Aninhar uma ação ou um rumor sob uma trama

1. Abra a trama sob a qual você quer.
2. Clique em **+ Ação** ou **+ Rumor** na barra de ações. A trama que você tinha aberta já está escolhida como a mãe. Veja [Alocar Ações](allocate-actions.md) ou [Rumores](rumors.md).

## O que saber

- **Os Recursos de Trama vêm desligados por padrão.** Um HST ou AST os liga para a crônica inteira em [Configuração da Crônica](chronicle-setup.md). Desligados, esta tela fica exatamente como sempre foi - sem seletor de categoria, sem seção de Objetivos de Facção, sem campo de data de linha do tempo. A maioria das crônicas nunca precisa deles; existem para as poucas que querem a estrutura extra.
- **A busca e os três filtros só restringem a grade que você está olhando** - status, quem iniciou, tramas de personagem e texto de título/descrição. Não alcançam as entradas nem as notas de uma trama.
- **Todo personagem tem a sua própria trama**, chamada `<Personagem> [id] Plot` e feita junto com o personagem, PC ou NPC. As ações de cada data de jogo desse personagem ficam sob ela, e o título dela acompanha o nome do personagem até você renomeá-la. Só você, outros gerentes e o jogador do personagem a veem, e ela não pode ser excluída sozinha - vai embora quando o personagem vai.
- **As entradas de uma trama de alocação de ações são travadas.** O formulário de entrada comum recusa qualquer coisa com a forma de uma entrada de Alocar ações ou de Usos de Antecedente - essas só são criadas ou mudadas pelas suas próprias ferramentas. Veja [Alocar Ações](allocate-actions.md) e [Usos de Antecedente](background-uses.md).
- **A ação de um jogador só fica editável até você responder.** Depois que existe uma entrada `response` depois dela, a ação faz parte do registro e trava.
- **Só você e outros gerentes podem ver ou abrir a trama de alocação de ações de outra pessoa.** Um jogador só vê a sua própria em [Minhas Tramas e Rumores](my-plots.md).
- **Uma trama criada por esta tela sempre conta como iniciada por Narrador**, até uma montada a partir da ideia de um Condutor de Trama.
- **Fechar o painel de Alocar ações ou de Rumores recarrega o que você estava olhando**, então tudo o que você acabou de confirmar aparece na hora.
- **Uma trama ou entrada pode ser retida para um lançamento posterior** em vez de sair no instante em que você a escreve - veja [Lançamentos](release-batches.md). Uma trama ou entrada retida traz um selo "Rascunho" ou "Em um lote de lançamento" aqui até o lote dela ser lançado; você e outros gerentes sempre a veem, seja como for, exatamente como de costume.
- **As tramas e rumores de um arquivo de jogo completo também caem aqui.** Uma importação `.gv3` (veja [Importar](import.md)) traz as tramas do próprio Grapevine só para Narradores com o elenco delas conectado, e os rumores exatamente como o gerador de rumores monta um - os feitos entregues, os abertos retidos para você lançar. Uma ação importada entra na trama por data do próprio personagem que a Fila de Tempo Livre já lê, como qualquer outra.
- Numa tela estreita esta grade nunca rola para o lado - já é um layout de cartões empilhados.

## Solução de problemas

- **"Falha ao carregar tramas."** Atualize e tente de novo.
- **"Nenhuma trama corresponde a estes filtros."** Limpe um filtro ou a caixa de busca.
- **"Falha ao criar esta trama."** Um título é obrigatório - confira o campo e tente de novo.
- **"Falha ao salvar."** Tente salvar de novo a visão geral ou o suspense final. Se continuar falhando, recarregue a trama.
- **"Falha ao adicionar esta entrada."** Tente postar de novo. Se continuar falhando, a trama pode não existir mais. Direcionar uma postagem exige pelo menos um personagem marcado.
- **"Falha ao enviar este arquivo."** Só imagens (JPEG, PNG, GIF, WebP) e PDFs são permitidos, 10 MB no máximo - confira o arquivo e tente de novo.
- **"Esta trama já atingiu o número máximo de arquivos que pode ter."** Remova um primeiro - 20 para uma trama, um para um item.
- **Não vejo esta aba de jeito nenhum.** Você não tem um papel de Narrador ou de Condutor de Trama na crônica escolhida no momento - troque de crônica, ou peça a um HST/AST que confira o seu papel.

## Relacionados

- [Configuração da Crônica](chronicle-setup.md) - ligue ou desligue os Recursos de Trama
- [Minhas Tramas e Rumores](my-plots.md)
- [Alocar Ações](allocate-actions.md)
- [Rumores](rumors.md)
- [Rascunhar uma Trama a partir de uma Premissa](draft-plot.md)
- [Conexões](connections.md)
- [Usos de Antecedente](background-uses.md)
- [Lançamentos](release-batches.md)
- [Tempo Livre](downtime-queue.md)
- [Kit de Ferramentas do Narrador](storyteller-toolkit.md)
- [Papéis](roles.md)
- [Conteúdo Só para Narradores](storyteller-only.md)
- [Guia do Administrador - Quem Pode Ver uma Trama, Item ou Local](../admin-guide.md#quem-pode-ver-uma-trama-item-ou-local)
- [Guia do Narrador](../st-guide.md#8-tramas-ações-e-rumores)
