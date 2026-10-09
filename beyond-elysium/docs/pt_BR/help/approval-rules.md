# Regras de Aprovação

Um só lugar para ver e definir toda exceção de aprovação do catálogo da sua crônica - quais itens, poderes, níveis de poder, valores de reserva ou opções de campo precisam da revisão de um Narrador - mais o padrão próprio da sua crônica para tudo o que uma regra não cobre.

## Quem pode usar

Os Narradores (HST e AST) gerenciam aqui as regras de aprovação da própria crônica e a sua **Política de Aprovação Padrão**. Tudo na página precisa só de um papel de Narrador na crônica que você escolheu.

Um Condutor de Trama, a Harpia de uma crônica e um jogador nunca veem esta tela. O que ela decide aparece para um jogador apenas como se a própria mudança enviada dele espera a revisão de um Narrador antes de valer.

## Como chegar lá

- Barra lateral do wp-admin → Beyond Elysium → Configuração do Sistema → aba Regras de Aprovação.
- Ou, pela lista de conferência da própria crônica: [Configuração da Crônica](chronicle-setup.md) → linha **Regras de aprovação** → **Ir**.

## A tela

- **Crônica** - uma lista suspensa com toda crônica da instalação, não só as que você gerencia. Escolher uma em que você não tem papel de Narrador carrega um erro, não uma lista de regras.
- **Política de Aprovação Padrão** - dois botões de opção, salvos no instante em que você escolhe um:
  - **Pendente por padrão** - uma regra abaixo pode marcar algo como Auto.
  - **Aprovar automaticamente por padrão** - uma regra abaixo pode exigir a revisão de um Narrador.
- **Remoções, reduções de classificação, reclassificações e renomeações** - uma caixa de seleção, salva no instante em que você a marca ou limpa, desligada por padrão. Ligada, uma remoção, uma classificação menor, um novo rótulo ou uma troca de nome sempre espera um Narrador - seja o que for que uma regra acima diga, e seja qual for a política padrão aplicada - e tudo o que for enviado junto com uma espera com ela, como um conjunto, mostrado na [Fila de Aprovação](approval-queue.md) como "Enviadas em conjunto."
- **Estatutos de Personagem da OWBN** - uma caixa de seleção, salva no instante em que você a marca ou limpa, desligada por padrão. Ligada, todo Estatuto de Personagem da OWBN já ligado a uma qualidade, defeito, antecedente, habilidade ou poder soma o seu próprio motivo a uma compra correspondente, além de tudo o que esta crônica já exige. A lista de referência que acompanha fica no fim da tela, recolhida até você abri-la - veja **Referência dos Estatutos de Personagem da OWBN** abaixo.
- Um botão **Nova Regra**, depois uma tabela de toda regra definida no momento para a crônica escolhida. **Nova Regra** limpa o formulário abaixo da tabela e leva você até ele, então você nunca rola além de uma lista longa para começar uma. As colunas da tabela são **Bloco** (com o tipo de criatura ao lado, para as Habilidades de Vampiro e as de Lobisomem se lerem diferente, ou o slug do bloco entre colchetes onde dois blocos compartilham um nome e nenhum tipo de criatura é dono deles), **Alvo** (o item, poder, nível, intervalo ou opção que ela trata), **Aprovação**, **Motivo**, **Editar**, **Excluir**.
- O formulário **Nova Regra** / **Editar Regra** abaixo da tabela:
  - **Bloco** - todo bloco de lista de traços, poder em níveis, reserva de recurso e campo de identidade, numa lista em que você pode digitar para filtrar, agrupada sob um título em negrito do tipo de criatura dono dela, que é o tipo que o nome carrega: as Disciplinas de Vampiro ficam em Vampiro embora um ghoul Mortal também as use, e uma edição como Disciplinas (Dark Ages) fica ao lado delas. Blocos que nenhum tipo de criatura possui sozinho (os Traços Físicos, Sociais e Mentais) ficam em **Usado por vários tipos de criatura**, e os blocos próprios de uma crônica em **Fora de qualquer tipo de criatura**; um tipo de criatura que esta crônica não ativou ainda é listado, marcado como não habilitado. Onde dois blocos de um grupo compartilham um nome, cada um mostra o seu slug entre colchetes.
  - **Escopo** - depois que um bloco é escolhido: **Um item, poder, reserva ou campo específico…** (os seletores por alvo abaixo), **O bloco inteiro** (toda compra nele, sem nada mais específico definido, precisa deste nível) ou, só num bloco de poder em níveis com um [teste dentro do tipo](creature-stacks.md#o-teste-dentro-do-tipo) de verdade declarado em algum tipo de criatura desta crônica, **Dentro do tipo / fora do tipo** (dois níveis separados - um para uma compra que o teste passa, um para uma que ele não passa).
  - Escolher **Um item, poder, reserva ou campo específico…** abre o que vem em seguida, que depende do tipo de seção do bloco:
    - **Lista de traços** - **Item**, depois **Escopo**: **O item inteiro**, ou **Um intervalo de valor específico** (acrescenta **De** e **Até**).
    - **Reserva de recurso** - **Reserva**, depois **De** e **Até** (o valor permanente dela).
    - **Campo de identidade** - **Campo** (só os que têm opções de verdade), depois **Opção**.
    - **Poder em níveis** - **Poder**, depois **Escopo**: **O poder inteiro**, ou **Apenas um nível** (acrescenta **Nível**, escolhido entre os degraus numerados do próprio poder; uma escolha de Ancião ou superior não tem nível numerado para escolher aqui).
  - **O bloco inteiro** usa o campo **Nível de aprovação** abaixo dele, igual a qualquer outro alvo.
  - **Dentro do tipo / fora do tipo** troca **Nível de aprovação** e **Motivo** por dois próprios: **Nível de aprovação dentro do tipo** e **Nível de aprovação fora do tipo**, cada um **(não definido - um Narrador decide)**, **auto** ou **st**.
  - **Nível de aprovação** - **(não definido - um Narrador decide)**, **auto** ou **st**.
  - **Motivo predefinido** - uma lista suspensa de formulações comuns que soma ao que já está em Motivo.
  - **Motivo** - texto livre, com um botão **Assistência de IA** que ajuda a redigir uma citação curta nomeando a autoridade de aprovação do mundo real por trás desta regra.
  - **Salvar** e (só ao editar) **Cancelar**.

- **Referência dos Estatutos de Personagem da OWBN** - no fim da tela, recolhida até você clicar nela, intitulada com quantas cláusulas lista. Dentro:
  - **Buscar**, **Nível** e um seletor **Vinculado ou não**, que filtram a lista.
  - **Atualizar do council.owbn.net** - puxa de novo, ao vivo, toda cláusula do Estatuto de Personagem. Uma cláusula que você já ligou mantém a ligação enquanto ainda existir no site do próprio council; uma cláusula que o council removeu depois deixa de dar um motivo; uma cláusula novinha aparece sem ligação.
  - **Enviar um arquivo** - para um site que não alcança o council.owbn.net direto, carrega no lugar um arquivo de estatutos já montado. Uma ação própria de administrador do site, não de um Narrador.
  - A lista em si, 50 cláusulas por vez com um botão **Mostrar mais**: o número da cláusula de cada regra (com link para a cláusula de verdade no council.owbn.net), assunto, nível de PC, nível de NPC, coordenador(es) e a que está ligada, se a algo.

Editar uma regra existente trava o Bloco e o alvo dela - só Aprovação e Motivo podem mudar.

## Tarefas comuns

### Exigir revisão acima de um certo valor

1. Escolha a sua **Crônica** na lista suspensa.
2. No formulário abaixo da tabela, escolha o **Bloco**.
3. Escolha o **Item** (ou **Reserva**) e, para um item, defina **Escopo** como **Um intervalo de valor específico**.
4. Digite **De** e **Até**.
5. Escolha um **Nível de aprovação**.
6. Clique em **Salvar**.

### Exigir aprovação para uma opção de um campo

1. Escolha a sua **Crônica**.
2. Escolha o **Bloco** do campo de identidade, depois o **Campo**, depois a **Opção**.
3. Escolha um **Nível de aprovação**.
4. Clique em **Salvar**.

### Mudar o que acontece por padrão

1. Escolha a sua **Crônica**.
2. Em **Política de Aprovação Padrão**, escolha **Pendente por padrão** ou **Aprovar automaticamente por padrão**. Isto exige uma conta de administrador do site.

### Sempre esperar numa remoção, numa classificação menor, num novo rótulo ou numa troca de nome

1. Escolha a sua **Crônica**.
2. Marque **Sempre aguardar um Narrador em uma remoção, uma redução de classificação, uma reclassificação ou uma renomeação**.

### Exigir a aprovação dos Estatutos de Personagem da OWBN

1. Escolha a sua **Crônica**.
2. Marque **Exigir aprovação dos Estatutos de Personagem da OWBN**.
3. Uma compra que um estatuto já cobre agora o cita no instante em que um jogador a envia - nenhuma outra configuração é necessária.

### Consultar um estatuto antes de aprovar uma compra

1. No fim da tela, abra **Referência dos Estatutos de Personagem da OWBN** e digite o nome da entrada em **Buscar**.
2. Clique no número da cláusula dela para ler a cláusula de verdade no council.owbn.net.

### Editar ou remover uma regra existente

1. Procure na tabela.
2. Clique em **Editar** para mudar a Aprovação ou o Motivo dela, ou em **Excluir** para limpá-la de volta a não definida.

## O que saber

- **Uma regra sempre vence a Política de Aprovação Padrão, nos dois sentidos.** Passar uma crônica para Aprovar automaticamente por padrão nunca deixa passar em silêncio algo que uma regra já sinaliza para revisão - nem uma regra sem Motivo.
- **Estes são os mesmos dados que os [Blocos de Esquema](schema-blocks.md) gerenciam na própria tela.** Definir uma regra aqui muda exatamente o que editar o item, poder, nível, reserva ou campo direto naquela tela mudaria - duas portas para a mesma fechadura. Na primeira edição de uma crônica, qualquer uma cria a cópia própria dessa crônica do bloco.
- **Um intervalo ou opção é conferido contra o valor que está sendo alcançado**, nunca o que um jogador tinha antes. O intervalo de uma reserva de recurso confere só a classificação permanente - gastar ou recuperar pontos em jogo nunca dispara uma revisão.
- **Excluir uma regra limpa a exceção, não a entrada do catálogo** - o item, poder ou campo em si fica exatamente como estava, só sem uma exigência especial de aprovação.
- **Um salvamento rejeitado nunca deixa uma cópia pela metade.** Definir ou limpar uma regra só cria a cópia própria da sua crônica do bloco depois que a mudança realmente dá certo.
- **Só dois níveis de aprovação: auto e st.** Não há uma etapa de coordenador à parte - um Motivo que cita um coordenador ainda passa pelo clique de Aprovar do próprio Narrador na [Fila de Aprovação](approval-queue.md).
- **Dentro do tipo / fora do tipo só alcança um bloco de poder em níveis cujo tipo de criatura de fato declara um teste para isso.** Um bloco sem teste dentro do tipo em nenhum tipo de criatura desta crônica recusa a regra de cara (`no_in_type_test`) - defini-la ali nunca dispararia, já que toda compra já conta como dentro do tipo.
- **Ver a aba não é o mesmo que poder usá-la aqui.** Qualquer conta com capacidade de Narrador pode abrir esta tela, mas ler ou definir regras de uma crônica específica ainda exige um papel de Narrador de verdade nela.
- **O motivo de um estatuto soma ao motivo próprio de uma regra, nunca o substitui.** Se uma regra acima já definiu um Motivo na mesma compra, a citação do estatuto vem depois dele, na própria linha.
- **Os níveis de PC e de NPC de uma ligação são separados, sem recuo entre eles.** Uma cláusula que diz "NPC: Unregulated" não soma nada quando o personagem é um NPC, mesmo que o nível de PC dela possa exigir Aprovação do Coordenador.
- **Atualizar nunca perde uma ligação que você definiu**, desde que a cláusula a que ela pertence ainda esteja ativa no council.owbn.net - só uma cláusula que o council removeu perde o motivo dela.

## Solução de problemas

- **A crônica que escolhi mostra um erro em vez de regras.** Você não é Narrador nessa crônica - a lista suspensa lista toda crônica da instalação, não só as que você gerencia.
- **Não vejo esta aba de jeito nenhum.** Regras de Aprovação exige uma conta de administrador do WordPress, ou um papel de Narrador em alguma crônica.
- **A minha regra parece não se aplicar.** Confira se ela trata o valor que de fato está sendo alcançado, não uma diferença de antes, e se nada mais específico a sobrepõe - uma Aprovação por intervalo de valores vence uma Aprovação simples de item, que vence a Política de Aprovação Padrão da própria crônica.
- **Não consigo mudar o alvo de uma regra.** Editar muda só Aprovação e Motivo - exclua a regra e crie uma nova para apontar para outro item, poder, nível, reserva ou opção.

## Relacionados

- [Blocos de Esquema](schema-blocks.md)
- [Descrições e Cronogramas de Aprovação do Catálogo](schema-block-notes.md)
- [Fila de Aprovação](approval-queue.md)
- [Como Funciona a Aprovação](approval-flow.md)
- [Editor de Personagem](character-editor.md)
- [Configuração da Crônica](chronicle-setup.md)
- [Assistência de Escrita por IA](writing-assist.md)
- [Guia do Administrador: Regras de Aprovação](../admin-guide.md#regras-de-aprovação)
- [Guia do Narrador: Conduzindo a Fila de Aprovação](../st-guide.md#4-conduzindo-a-fila-de-aprovação)
