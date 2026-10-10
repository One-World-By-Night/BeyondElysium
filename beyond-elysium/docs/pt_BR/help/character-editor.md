# Editor de Personagem

Onde você monta um personagem novo e muda um existente. Todo traço, poder, reserva e campo de identidade de uma ficha passa por esta tela antes de chegar à ficha em si.

## Quem pode usar

Os jogadores podem criar os próprios personagens e editá-los. Os Narradores (HST e AST) podem criar um personagem para qualquer pessoa e editar qualquer personagem da crônica - inclusive campos que a cópia de um jogador desta tela nunca mostra: status, o narrador atribuído e as notas privadas do Narrador. Um Condutor de Trama ou a Harpia da crônica que não seja também membro com acesso de jogador não pode criar nem editar um personagem aqui.

Você só pode abrir esta tela para um personagem seu, a menos que seja Narrador. Qualquer outra pessoa recebe um erro, não uma visão bloqueada.

## Como chegar lá

Minha Crônica → aba Editar.

- Com um personagem já escolhido - pela aba Personagens, ou pelo link "Editar este personagem" na Ficha dele - isto abre direto na edição dele.
- Sem personagem escolhido, abre um formulário em branco para começar um novo.

Um Narrador que cria um personagem pela página Personagens do wp-admin cai nesta mesma tela.

## A tela

### Novo Personagem (nenhum personagem escolhido ainda)

- **Nome** - obrigatório.
- **Tipo de Criatura** - uma lista suspensa com os tipos de criatura permitidos desta crônica. Pulada se você chegou aqui já pronto para montar um tipo específico.
- **Este é um NPC** - uma caixa de seleção, mostrada só a um Narrador. Marcá-la troca o formulário para o layout de NPC mais rico, que acrescenta uma seção só para o Narrador com voz, trejeitos e ganchos de trama, e revela uma segunda escolha:
  - **Ficha completa** - o layout completo de NPC, igual ao acima.
  - **Somente estatísticas rápidas** - um layout curto com o mínimo (Físico/Social/Mental, Força de Vontade, Health, Habilidades/Poderes/Equipamento principais) para interpretar este NPC em uma cena sem montar um personagem inteiro. Veja **Tornar NPC Completo** abaixo para promover um depois.
- As seções da própria pilha abaixo, exatamente como as seções da ficha descritas mais adiante - preencha o que você quiser que o personagem tenha ao começar.
- **Criar Personagem** - grava o personagem. Nada do que você monta aqui é precificado nem revisado como uma mudança posterior: o que você coloca na ficha inicial é o que o personagem recebe.

Se você já é membro desta crônica, o personagem existe na hora (ou começa **pendente**, se a crônica exige aprovação de novos personagens, até um Narrador ativá-lo). Se você ainda não é membro desta crônica, isto é um pedido de entrada: você vê um aviso "Pedido Enviado", o personagem espera sem que ninguém possa abrir a ficha dele, e você só vira jogador aqui quando um Narrador aprova.

### Cabeçalho (editando um personagem existente)

- Retrato, com um botão **Adicionar um retrato…** / **Trocar retrato…**.
- O nome do personagem.
- **Este é um NPC** - uma caixa de seleção, mostrada só a um Narrador que olha um personagem que pode gerenciar. Alterná-la recarrega a ficha com o outro layout na hora.
- **Atribuído a** - qual Narrador é dono deste NPC, mostrado depois que ele é marcado como NPC. Veja [Minha Fila](my-queue.md).
- **Tornar NPC Completo** - mostrado só num NPC Rápido. Passa-o para o layout completo de vez; daqui não há como voltar ao Rápido.
- Um aviso "Você pode visualizar esta ficha, mas não editá-la." no lugar de todo controle de edição, se você pode ver este personagem mas não pode mudá-lo.

### Perfil do Quem é Quem (só NPCs)

Mostrado só a um Narrador que edita um NPC, separado da ficha acima - o que um jogador vê sobre este NPC no diretório [Quem é Quem](whos-who.md) da crônica, não a ficha em si:

- **Nome de Exibição** - mostrado no lugar do nome real do personagem no Quem é Quem, se definido.
- **Descrição** - o texto que um jogador lê. Uma passagem marcada com `[ST]...[/ST]` continua sendo retirada para quem não é Narrador, igual a todo lugar.
- Um retrato, separado do retrato da própria ficha.
- Quem pode ver este perfil, afinal - o mesmo seletor de público que uma trama ou item usa. Um NPC sem perfil configurado simplesmente não aparece no Quem é Quem para ninguém além de um Narrador.
- **Salvar Perfil**.

### Perfil do Quem é Quem (o personagem não-NPC de um jogador)

Mostrado só a um jogador que edita um personagem seu que não é NPC - uma versão mais estreita da seção acima, sem escolha de público além de mostrar ou esconder o personagem:

- **Nome de Exibição** - mostrado no lugar do nome real do personagem no Quem é Quem, se definido.
- **Descrição** - o texto que os outros jogadores leem. Uma passagem marcada com `[ST]...[/ST]` é retirada para quem não é Narrador, igual a todo lugar.
- **Exibir este personagem no Quem é Quem** - uma caixa de seleção. Desmarcada, o padrão, o personagem não aparece no [Quem é Quem](whos-who.md) para ninguém além de um Narrador.
- **Indicar meu nome como o jogador deste personagem** - uma caixa de seleção. Marcada, o nome de exibição da sua própria conta aparece junto do personagem no Quem é Quem.
- Um retrato, enviado direto desta tela - separado do retrato principal da ficha do personagem e da imagem do Quem é Quem de um NPC.
- **Salvar Perfil**.

Um jogador não pode escolher um público restrito aqui - um público baseado em regras continua sendo uma ferramenta do Narrador, definida pela seção de NPC acima, num personagem que um Narrador gerencia.

### Segredos (só NPCs)

Mostrado só a um Narrador que edita um NPC, depois que ele já existe - um texto guardado separado da ficha do NPC, com o seu próprio público e revelações a personagens específicos. Veja [Segredos](secrets.md).

### Ganchos (só NPCs)

Mostrado só a um Narrador que edita um NPC que já existe - um painel recolhido que lista toda trama a que este NPC está ligado, dividida em **Abertas** e **Resolvidas**, cada uma com a data da última entrada. Clique no nome de uma trama para abri-la no Kit de Ferramentas do Narrador. Um NPC ainda ligado a nada mostra "Nenhuma." sob os dois títulos.

### Antecedente e Notas

Dois campos de texto rico, salvos independentemente de todo o resto desta tela - digitar aqui e clicar em **Salvar Antecedentes e Notas** vale na hora, sem custo de XP e sem revisão de Narrador, seja o que for que esteja na fila abaixo. Um botão **Assistência de IA** aparece ao lado de cada campo só para um Narrador, mesmo no personagem de um jogador; o jogador digita o próprio texto direto.

### Solicitar XP

Mostrado só a um jogador, no próprio personagem - não a um Narrador que o edita. Recolhido por padrão. Para experiência ganha em algum lugar que esta crônica não enxerga por conta própria, na maioria das vezes um jogo ou evento feito totalmente fora do Beyond Elysium:

- **Quantidade** - um número inteiro de 1 a 10.000.
- **Onde você ganhou** - obrigatório, até 200 caracteres.
- **Data da sessão** - opcional; não pode ser depois de hoje.
- **Detalhes** - opcional, até 2.000 caracteres.
- **Enviar solicitação** - envia na hora, separado de qualquer coisa na fila em **Alterações Pendentes** abaixo, que continua na fila. O painel então diz "Enviado. Os seus Narradores vão revisar." e se limpa.

Um pedido sempre espera um Narrador, sejam quais forem as configurações de aprovação desta crônica, e aparece na Fila de Aprovação com o que você digitou. Veja o [Guia do Jogador](../player-guide.md#2-editando-sua-ficha) e a [Fila de Aprovação](approval-queue.md).

### Seções da ficha

O resto da tela é o modelo da própria crônica, uma caixa por seção, no mesmo layout que a ficha somente leitura usa:

- **Listas de traços** (Habilidades, Antecedentes, Qualidades e afins) - cada entrada mantida aparece como uma linha compacta com um botão de editar. **+ Adicionar** abre o mesmo formulário para uma nova: escolha um nome do catálogo ou digite o seu onde a seção permite, defina uma contagem ou nível e, se quiser, uma especialização (ou, numa entrada repetível, uma resposta a "Quem ou o quê?") e uma nota. **Adicionar um nome que você já tem aumenta essa entrada em vez de começar uma segunda** - uma especialização rotula uma posse, então um foco diferente não compra um segundo Briga; só as entradas que o catálogo marca como repetíveis (dois Serviçais, dois campos de estudo) ganham uma linha cada, uma por resposta. Remover uma entrada mantida a marca para remoção em vez de apagá-la de vez, então você ainda pode desfazer antes de enviar. Veja [Listas de Traços](trait-editor.md).
- **Poderes** (Disciplinas, Dons, Artes e outros catálogos em níveis) - adicione um poder, depois suba ou desça com um seletor +/−, ou troque para uma lista de verificação que nomeia cada degrau até o seu nível atual. **Poderes com classificação e escolhas de Ancião e acima são duas listas separadas**: a nota é um número na escada, uma escolha é um poder nomeado acima dela, e as duas nunca dividem uma contagem. As escolhas ficam abaixo dos poderes com classificação, agrupadas por posto. Um poder de feitiçaria de sangue também pergunta por uma tradição. Veja [Poderes](power-editor.md).
- **Reservas de recurso** (Força de Vontade, Reserva de Sangue, Fúria e afins) - uma linha permanente e uma temporária por reserva, cada uma com o seu seletor +/− e a sua exibição de pontos.
- **Campos de identidade** (Clã, Natureza, Geração e outros campos nomeados) - uma lista suspensa, grupo de caixas, caixa numérica, caixa de texto ou área de texto, conforme o campo.

Veja [Reservas de Recurso e Campos de Identidade](pools-identity-editor.md) para os dois últimos em detalhe.

### Adicionar uma seção (personagens Vários)

O editor de um personagem Vários lista os poderes de todo tipo de criatura desde o início, então nada precisa ser adicionado antes de você escolher Obtenebração, um Dom ou uma Esfera: Disciplinas e Magia de Sangue (Vampiro), Dons (Lobisomem e Fera), Artes e Reinos (Changeling), Esferas (Mago), Arcanoi (Wraith), Lores (Demônio), Edges (Caçador), Disciplinas e Shintai (Kuei-Jin), Hekau (Múmia) e Fenômenos Psíquicos, Magia da Sebe, Artes Marciais, Teurgia, Poderes Fomori e Bioaprimoramentos. Habilidades, Antecedentes, Qualidades, Defeitos e Temperamentos são, cada um, uma lista com as entradas de todo tipo de criatura, sem duplicatas, e **Outros Poderes** serve para poderes que você inventa. Uma seção sem nada dentro fica fora da ficha final.

Um Narrador que edita ou cria um personagem cujo tipo de criatura é **Vários** também vê um botão **Adicionar uma seção** sob as seções, para tudo o que o editor ainda não lista (rituais, rotes, Disciplinas combinadas e o resto). Ele abre uma lista das seções que ainda não estão na ficha, que você pode digitar para filtrar, agrupadas sob um título em negrito do tipo de criatura dono dela (Vampiro, Lobisomem, Mago e os demais), depois as seções que vários tipos de criatura compartilham, depois as que não pertencem a nenhum. Escolha uma e clique em **Adicionar**: uma caixa vazia para ela aparece na ficha, e a primeira entrada que você adiciona é o que a mantém ali - uma seção sem nada não é salva. As seções que um personagem Vários já tem aparecem na ficha dele, na impressão e na exportação assinada sem precisar ser adicionadas de novo. Só um Narrador vê este botão, e só em Vários; todo outro tipo de criatura mantém as seções que o seu modelo lista.

A seção de notas de interpretação só para o Narrador de um NPC (voz, trejeitos, motivações e afins) também traz um botão **Rascunhar notas de interpretação** ao lado do título, desativado quando todos os seus campos já têm texto (um campo que você nunca escreveu conta como vazio, então um NPC novinho pode ser rascunhado na hora). Numa crônica de demonstração ele fica sempre clicável, para poder explicar que o rascunho está desligado. Veja [Assistência de Escrita por IA](writing-assist.md).

### Reordenando uma Lista

Uma lista de traços ou seção de poderes que a crônica configurou para deixar os jogadores escolherem a própria ordem (veja [Blocos de Esquema](schema-blocks.md)) aparece achatada, na ordem em que você a deixou por último, sem agrupamento - e com um botão **Reordenar** acima da lista. Clique nele para arrastar as linhas ao lugar, ou use os botões **▲**/**▼** ao lado de cada uma; **Salvar ordem** envia a nova ordem na hora, sem aprovação e sem custo de XP, já que nada do que você tem muda. **Cancelar** deixa a ordem exatamente como estava. Uma crônica que desliga isso para uma seção volta à exibição agrupada normal, e o botão Reordenar some.

### Alterações Pendentes

Tudo o que você muda nas seções acima fica na fila aqui em vez de tocar a ficha na hora:

- Uma lista corrida, uma linha por mudança, nomeando o que mudou e, quando o preço carrega, o custo em XP e se precisa de revisão do Narrador. Um traço ou poder que não está no catálogo diz "Preço definido por um Narrador na aprovação" em vez de um custo: ainda não tem preço, e o total o deixa de fora até um Narrador definir um.
- O **Total** de XP de tudo o que está na fila e **Não gasto após** - como ficaria o seu XP depois que tudo for aplicado. Como jogador, isso vira um aviso se ficar negativo.
- **Enviar Alterações** - envia tudo o que está na fila ao servidor junto, num só pedido. Como jogador, fica desativado se deixaria você com XP negativo.
- **Descartar** - abre uma confirmação ("Descartar alterações não salvas?") antes de reverter todo campo ao que está na ficha agora e levar você de volta à Ficha do personagem.

Depois de enviar, você pode ver um destes, ou os dois:

- Um aviso de que o envio falhou - nada nele foi salvo, já que o conjunto inteiro dá certo ou falha junto. Revise e clique em **Enviar Alterações** de novo para tentar outra vez.
- Um aviso de que algumas mudanças foram enviadas e aguardam a aprovação do Narrador - não aparecem na ficha até lá. Se a sua crônica sempre espera numa remoção, numa classificação menor, num novo rótulo ou numa troca de nome (Regras de Aprovação), tudo o que essa regra pegar espera junto com o que mais você enviou ao lado, até uma adição que de outro modo passaria direto.

Se você sair com algo na fila sem enviar, o navegador avisa antes de você navegar para fora, e as suas edições são salvas neste navegador automaticamente. Da próxima vez que você abrir o mesmo personagem aqui, um aviso oferece **Restaurar** ou **Descartar** esse rascunho salvo.

## Tarefas comuns

### Criar um personagem novo

1. Abra Minha Crônica e escolha a sua crônica, se você joga em mais de uma.
2. Na aba **Personagens**, clique em **+ Novo Personagem**. Um Narrador também pode fazer isso na aba Personagens do [Kit de Ferramentas do Narrador](storyteller-toolkit.md), onde **+ Novo NPC** abre o formulário com a caixa de NPC já marcada.
3. Digite um **Nome** e escolha um **Tipo de Criatura**. Um Narrador também vê **Vários**, o tipo de criatura que pode ter qualquer seção de qualquer tipo de criatura.
4. Preencha as seções com que você quer que o personagem comece.
5. Clique em **Criar Personagem**.

### Editar um personagem existente

1. Abra a Ficha do personagem e clique em **Editar este personagem** (ou abra a aba **Editar** com esse personagem já escolhido).
2. Use o controle de cada seção para adicionar, mudar ou remover um traço, poder, valor de reserva ou campo de identidade.
3. Confira o **Total** e o **Não gasto após** corridos em Alterações Pendentes.
4. Clique em **Enviar Alterações**.

### Atualizar o Antecedente ou as Notas

1. Abra o personagem na aba **Editar**.
2. Digite em **Antecedentes** ou **Notas**.
3. Clique em **Salvar Antecedentes e Notas**. Isso salva na hora, independente do que está na fila em Alterações Pendentes.

### Restaurar um rascunho de uma sessão anterior

1. Abra o personagem na aba **Editar**. Se você deixou alterações não salvas aqui antes, um aviso diz isso e informa quando foram salvas.
2. Clique em **Restaurar** para trazê-las de volta, ou em **Descartar** para largá-las de vez.

### Descartar alterações antes de enviar

1. Em Alterações Pendentes, clique em **Descartar**.
2. Confirme "Descartar alterações não salvas?" - isso reverte todo campo e leva você de volta à Ficha do personagem.

## O que saber

- **Entrar versus criar.** Começar o seu primeiro personagem numa crônica a que você ainda não pertence é um pedido de entrada, não uma criação comum: ele espera pendente, os Narradores da crônica recebem um e-mail, e um Narrador ativá-lo é o que torna você jogador ali. Só um desses pedidos pode esperar por crônica de cada vez.
- **Todo personagem ganha a sua própria trama.** Criar um - PC ou NPC - também cria `<Personagem> [id] Plot`, que só o jogador do personagem e os Narradores da crônica veem. Veja o [Gerenciador de Tramas](plot-manager.md).
- **Uma ficha inicial não é precificada.** Ao contrário de toda mudança posterior, o que você monta na ficha de um personagem novinho é gravado como está - sem custo de XP e sem revisão de Narrador das entradas individuais. Se a sua crônica exige aprovação de novos personagens, o ponto de controle é o status do próprio personagem, não o que está na ficha.
- **A Health se preenche sozinha.** A seção Health de um personagem novo já vem preenchida no instante em que ele é criado - não é algo que você monta.
- **Alguns campos são só do Narrador, até no seu próprio personagem.** O seu status, o seu narrador atribuído e as notas privadas do seu Narrador sobre você nunca aparecem aqui para um jogador, e não podem ser mudados aqui nem por você.
- **Poderes numerados se somam.** Comprar um poder novo no nível 3 custa os níveis 1, 2 e 3 juntos - não só o nível 3.
- **Alguns poderes gastam Traços em vez de XP.** Alguns catálogos (os Edges do Caçador, por exemplo) precificam um poder em Traços gastos de uma reserva nomeada em outra parte da ficha, nunca em XP - comprar um marca essa quantidade de pontos da reserva como "gastos" (mostrado ao lado dos pontos) sem baixar a classificação da própria reserva, e remover o poder os libera de novo. Comprar um exige pontos não gastos suficientes nessa reserva, e alguns catálogos também exigem já ter o caminho do próprio poder no posto abaixo dele.
- **Uma reserva que diz "Elevar com [reserva] (N)" não aceita um clique nos pontos.** Algumas reservas (as Virtudes do Caçador, por exemplo) só sobem convertendo N pontos temporários de outra reserva nomeada - clique no botão, não nos pontos, e ele recusa se essa reserva não tem pontos temporários suficientes agora. Enquanto você preenche um personagem novo, essas reservas aceitam cliques nos pontos, até os pontos livres que as regras de criação dão (três pontos de Virtude para um Caçador); o Cálculo da Construção avisa quando você passa deles, e a ficha é recusada até você voltar para dentro. Depois que o personagem existe, só o botão as eleva.
- **Uma classificação acima do máximo do livro espera por um Narrador.** Algumas reservas podem ir além do que o livro permite (o Equilíbrio de uma Múmia vai até 10, e o livro para em 5). Pedir uma classificação acima do máximo do livro envia a alteração a um Narrador com o motivo, e um personagem novo não pode começar acima dele.
- **Um nome personalizado sempre precisa de revisão.** Digitar um nome que não está no catálogo só salva em seções que permitem isso, e mesmo assim sempre vai a um Narrador para aprovação, não importa como a sua crônica configurou a aprovação automática.
- **Os pontos são todos do mesmo tamanho** - nesta tela, na ficha e num PDF assinado. Os pontos de uma reserva e a classificação de um traço usam o mesmo ponto.
- **Os rascunhos são por dispositivo.** As suas edições em andamento são salvas automaticamente neste navegador enquanto você as faz, então uma aba fechada ou uma falha não perde o seu trabalho - mas esse rascunho vive só no dispositivo em que você digitou, e só até você enviar ou descartar.
- **Uma cópia visitante mantida atualizada não pode ser editada aqui.** Enquanto este personagem tem uma visita aberta e combinada, mantida atualizada, em outro lugar, editar ou enviar uma mudança é recusado até a visita terminar - "Mantido atualizado a partir de [crônica]. Faça as mudanças lá." Edite no lar verdadeiro dele. Veja [Enviar Ficha](transfer.md).

## Solução de problemas

- **As minhas mudanças sumiram depois que enviei.** Provavelmente estão pendentes da revisão do Narrador, não perdidas - confira o "Ver histórico" da Ficha ou as mudanças pendentes do seu Painel.
- **Enviar Alterações não clica.** Ou nada está na fila ainda, ou (como jogador) enviar deixaria você com XP negativo - reduza o que está comprando, ou peça mais a um Narrador.
- **Um nome que digitei diz que não está no catálogo.** Confira a grafia primeiro. Se é mesmo novo, esta seção pode não aceitar entradas personalizadas - pergunte a um Narrador.
- **"O seu personagem já está esperando os Narradores desta crônica aprovarem."** Você já tem um pedido de entrada pendente aqui - espere um Narrador revisá-lo antes de começar outro.
- **Não consigo abrir um personagem que sei que existe.** Ou ele não está ligado à sua conta ainda, ou pertence a outra pessoa e você não é Narrador aqui - peça a um Narrador que o atribua a você.
- **Nada do que enviei foi salvo.** O conjunto inteiro é enviado junto e falha junto - revise o erro, corrija o que está errado e clique em **Enviar Alterações** de novo.
- **"Mantido atualizado a partir de [crônica]. Faça as mudanças lá."** Este personagem é uma cópia visitante mantida atualizada - edite na crônica nomeada; a mudança chega aqui sozinha.

## Relacionados

- [Ficha de Personagem](character-sheet.md)
- [Listas de Traços](trait-editor.md)
- [Poderes](power-editor.md)
- [Reservas de Recurso e Campos de Identidade](pools-identity-editor.md)
- [Fila de Aprovação](approval-queue.md)
- [Enviar Ficha](transfer.md)
- [Assistência de Escrita por IA](writing-assist.md)
- [Quem é Quem](whos-who.md)
- [Minha Fila](my-queue.md)
- [Papéis](roles.md)
- [Como Funciona a Aprovação](approval-flow.md)
- [Guia do Jogador](../player-guide.md#2-editando-sua-ficha)
- [Guia do Narrador](../st-guide.md#3-criando-personagens)
