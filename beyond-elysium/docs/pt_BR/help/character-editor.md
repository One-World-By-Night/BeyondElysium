# Editor de Personagem

Onde você monta um personagem novo e muda um existente. Todo traço, poder, reserva e campo de identidade de uma ficha passa por esta tela antes de chegar à ficha em si.

## Quem pode usar

Os jogadores podem criar os próprios personagens e editá-los. Os Narradores (HST e AST) podem criar um personagem para qualquer pessoa e editar qualquer personagem da crônica - inclusive campos que a cópia de um jogador desta tela nunca mostra: status, o narrador atribuído e as notas privadas do Narrador. Um Condutor de Trama ou a Harpia da crônica que não seja também membro com acesso de jogador não pode criar nem editar um personagem aqui.

Você só pode abrir esta tela para um personagem seu, a menos que seja Narrador. Qualquer outra pessoa recebe um erro, não uma visão bloqueada.

## Como chegar lá

Minha Crônica → aba **Personagem** → visualização **Editar**.

- Com um personagem já escolhido - pela aba Personagens, ou pela visualização **Editar** da seção Personagem - isto abre direto na edição dele.
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

- O retrato do personagem, se ele tem um. Adicione ou troque na visualização **História**.
- O nome do personagem.
- **Este é um NPC** - uma caixa de seleção, mostrada só a um Narrador que olha um personagem que pode gerenciar. Alterná-la recarrega a ficha com o outro layout na hora.
- **Atribuído a** - qual Narrador é dono deste NPC, mostrado depois que ele é marcado como NPC. Veja [Minha Fila](my-queue.md).
- **Tornar NPC Completo** - mostrado só num NPC Rápido. Passa-o para o layout completo de vez; daqui não há como voltar ao Rápido.
- Um aviso "Você pode visualizar esta ficha, mas não editá-la." no lugar de todo controle de edição, se você pode ver este personagem mas não pode mudá-lo.

### Antecedentes, Notas e o perfil do Quem é Quem

Antecedentes, Notas e o perfil do [Quem é Quem](whos-who.md) foram para a visualização **História** da seção Personagem, onde são salvos enquanto você digita e ficam as anotações de RP de um Narrador. Veja [Minha Crônica](my-chronicle.md).

### Segredos (só NPCs)

Mostrado só a um Narrador que edita um NPC, depois que ele já existe - um texto guardado separado da ficha do NPC, com o seu próprio público e revelações a personagens específicos. Veja [Segredos](secrets.md).

### Ganchos (só NPCs)

Mostrado só a um Narrador que edita um NPC que já existe - um painel recolhido que lista toda trama a que este NPC está ligado, dividida em **Abertas** e **Resolvidas**, cada uma com a data da última entrada. Clique no nome de uma trama para abri-la no Kit de Ferramentas do Narrador. Um NPC ainda ligado a nada mostra "Nenhuma." sob os dois títulos.

### Solicitar XP

Mostrado só a um jogador, no próprio personagem, na visualização **História** - não a um Narrador. Recolhido por padrão. Para experiência ganha em algum lugar que esta crônica não consegue ver por conta própria, na maioria das vezes um jogo ou evento realizado totalmente fora do Beyond Elysium:

- **Quantidade** - um número inteiro de 1 a 10.000.
- **Onde você ganhou** - obrigatório, até 200 caracteres.
- **Data da sessão** - opcional; não pode ser depois de hoje.
- **Detalhes** - opcional, até 2.000 caracteres.
- **Enviar solicitação** - envia na hora, separado de qualquer coisa no seu carrinho, que continua lá. O painel então diz "Enviado. Os seus Narradores vão revisar." e se limpa.

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

### O carrinho

Tudo o que você muda nas seções acima vai para o seu carrinho em vez de tocar a ficha. O carrinho é o conjunto de alterações que você fez e ainda não enviou, guardado neste dispositivo para cada personagem, então uma aba fechada não o perde.

- **O medidor.** Uma barra fixa no pé da Edição mostra sempre o que você pode gastar: "10 XP para gastar". Com alterações no carrinho ela diz "Carrinho: 3 · 6 XP · 4 restantes", e "2 XP excedidos", em vermelho, quando o carrinho custa mais do que você tem livre. O XP retido para alterações que ainda aguardam um Narrador não está livre para gastar, então já foi descontado. Um Narrador vê a contagem e o preço sem limite. O mesmo "Carrinho: 3 · 6 XP" aparece como uma etiqueta no cabeçalho do personagem em todas as visualizações, e qualquer um dos dois abre a Revisão.
- **Revisar** abre uma janela (a tela inteira no celular). As linhas vêm em dois grupos, **Aplica agora** e **Aguarda um Narrador**, cada uma com o seu preço e, nas que aguardam, o motivo. **Remover** tira uma linha e devolve aquela parte da ficha ao que era. Sob as linhas ficam o **Total**, o que é **Gasto agora**, o que fica **Restante depois** e "6 XP retidos até um Narrador decidir" para as linhas que aguardam. Uma **Nota para seu Narrador** (até 1.000 caracteres) aparece quando algo aguarda e vai com cada alteração que você enviar; os Narradores a leem na Fila de Aprovação como "Nota do jogador".
- **Enviar** manda o carrinho inteiro num só pedido. A Revisão então se lê como a confirmação de um pedido: "Enviado. 2 aplicadas agora. 3 aguardam um Narrador, com 6 XP retidos." com **Ver no Histórico**. Enviar fica desativado enquanto uma linha não puder ser enviada (a Revisão a nomeia) ou o carrinho custar mais do que você tem livre. Se o servidor recusar o conjunto, o carrinho continua como estava.
- **Esvaziar carrinho** devolve cada linha ao que a ficha tem, depois de perguntar, e mantém você onde está.
- **Mudou desde que você adicionou.** Se um Narrador mudar uma parte da ficha que o seu carrinho também mudou, essa parte volta da ficha, não do carrinho, e o carrinho diz "A ficha mudou desde que você adicionou isto: Ocultismo. Adicione de novo se ainda quiser."
- As alterações que você envia saem do carrinho. As que aguardam viram um pedido no **Histórico**, e o seu XP fica retido para elas.

Você também pode adicionar ao carrinho sem abrir a Edição: o painel que abre quando você toca num traço (veja [Ficha do Personagem](character-sheet.md)) tem **Adicionar ao carrinho**, por exemplo "Subir para x4 · 1 XP", e as linhas de traços e de poderes da Edição, e o diálogo de Adicionar, têm um botão **Sobre** que abre o mesmo painel.

Se você sair com algo no carrinho, o navegador avisa antes de você navegar para fora, e o carrinho é salvo neste dispositivo enquanto você trabalha.

## Tarefas comuns

### Criar um personagem novo

1. Abra Minha Crônica e escolha a sua crônica, se você joga em mais de uma.
2. Na aba **Personagens**, clique em **+ Novo Personagem**. Um Narrador também pode fazer isso na aba Personagens do [Kit de Ferramentas do Narrador](storyteller-toolkit.md), onde **+ Novo NPC** abre o formulário com a caixa de NPC já marcada.
3. Digite um **Nome** e escolha um **Tipo de Criatura**. Um Narrador também vê **Vários**, o tipo de criatura que pode ter qualquer seção de qualquer tipo de criatura.
4. Preencha as seções com que você quer que o personagem comece.
5. Clique em **Criar Personagem**.

### Editar um personagem existente

1. Abra o personagem e clique em **Editar** na fileira de visualizações.
2. Use o controle de cada seção para adicionar, mudar ou remover um traço, poder, valor de reserva ou campo de identidade.
3. Clique em **Revisar** no medidor no pé da tela (ou na etiqueta do carrinho no cabeçalho) e confira cada linha e os totais.
4. Se algo aguardar um Narrador, você pode escrever uma **Nota para seu Narrador**. Depois clique em **Enviar**.

### Atualizar o Antecedente ou as Notas

1. Abra o personagem e clique em **História**.
2. Digite em **Antecedentes** ou **Notas**.
3. Não há nada para clicar: salva um instante depois que você para de digitar, e a linha sob os campos diz **Salvo**. Isso é independente do seu carrinho.

### Voltar ao seu carrinho

1. Abra o personagem. A etiqueta do carrinho no cabeçalho, e o medidor na Edição, mostram o que você deixou nele.
2. Uma parte da ficha que um Narrador mudou nesse meio-tempo não volta; o carrinho a nomeia para você adicioná-la de novo.
3. Um rascunho da ficha inteira salvo por uma versão anterior aparece uma vez na Edição com **Restaurar** ou **Descartar**; restaurá-lo o transforma no seu carrinho.

### Esvaziar o carrinho

1. Clique em **Revisar** e depois em **Esvaziar carrinho**.
2. Confirme "Esvaziar o carrinho?" - cada linha volta ao que a ficha tem, e você continua onde está.

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
- **O carrinho é por dispositivo.** O seu carrinho é salvo neste navegador enquanto você edita, então uma aba fechada ou uma falha não perde o seu trabalho - mas ele vive só no dispositivo que você usou, até você enviá-lo ou esvaziá-lo.
- **Uma cópia visitante mantida atualizada não pode ser editada aqui.** Enquanto este personagem tem uma visita aberta e combinada, mantida atualizada, em outro lugar, editar ou enviar uma mudança é recusado até a visita terminar - "Mantido atualizado a partir de [crônica]. Faça as mudanças lá." Edite no lar verdadeiro dele. Veja [Enviar Ficha](transfer.md).

## Solução de problemas

- **As minhas mudanças sumiram depois que enviei.** As linhas enviadas saem do carrinho. As que aguardam um Narrador aparecem como um pedido na visualização **Histórico**, onde você pode retirá-las, e no seu Painel.
- **Não vejo o carrinho.** O medidor fica no pé da Edição, e a etiqueta do carrinho no cabeçalho aparece quando o carrinho tem algo.
- **Enviar não clica.** O carrinho custa mais do que você tem livre para gastar (o medidor diz "XP excedidos"), ou uma linha não pode ser enviada como está - a Revisão a nomeia. Remova-a, reduza o que está comprando, ou peça mais XP a um Narrador.
- **Um nome que digitei diz que não está no catálogo.** Confira a grafia primeiro. Se é mesmo novo, esta seção pode não aceitar entradas personalizadas - pergunte a um Narrador.
- **"O seu personagem já está esperando os Narradores desta crônica aprovarem."** Você já tem um pedido de entrada pendente aqui - espere um Narrador revisá-lo antes de começar outro.
- **Não consigo abrir um personagem que sei que existe.** Ou ele não está ligado à sua conta ainda, ou pertence a outra pessoa e você não é Narrador aqui - peça a um Narrador que o atribua a você.
- **Nada do que enviei foi salvo.** O conjunto inteiro é enviado junto e falha junto, e o carrinho fica como estava - leia o erro na Revisão, corrija o que está errado e clique em **Enviar** de novo.
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
