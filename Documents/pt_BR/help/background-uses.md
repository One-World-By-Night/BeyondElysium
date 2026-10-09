# Usos de Antecedente

O registro do que um personagem fez com uma ação de antecedente orçada em uma data de jogo - o que foi registrado, o que um Narrador decidiu que resultou disso e o que sobra do orçamento.

## Quem pode usar

Qualquer pessoa que pode abrir a ficha de um personagem pode abrir o registro de usos de antecedente desse mesmo personagem - um jogador no seu, os Narradores (HST e AST) em qualquer personagem da crônica. Registrar um uso para o seu próprio personagem não exige nada além de ser o jogador dele. Preencher o que resultou de um uso - o Resultado - é só do Narrador, até no seu próprio personagem. As duas operações "Limpar tudo" também são só do Narrador.

## Como chegar lá

- Ficha do Personagem → escolha **Usos de antecedente** na lista de ações e clique em **Ir**.
- Também mostrado automaticamente em Kit de Ferramentas do Narrador → Tramas e Rumores → **Alocar ações**, logo abaixo da tabela, depois que um Narrador confirma uma alocação para um personagem e data. Veja [Alocar Ações](allocate-actions.md).

## A tela

- **Data do jogo** - mostrada como um campo próprio acima do registro só quando você abre isto pela Ficha do Personagem; o padrão é a data de hoje, e mudá-la recarrega o registro daquela data. Dentro de Alocar Ações o registro já conhece a data recém-alocada, então não há seletor à parte.
- Um bloco por antecedente que o personagem tem orçado no momento (uma Influência, ou um Antecedente que as Configurações de Ação e Rumor da sua crônica definiram para conceder uma), cada um mostrando:
  - Quanto resta - **Orçamento: N** a partir do ponto de entrada da própria Ficha do Personagem, ou **Gasto X / Y** (marcado **(acima do orçamento)** em vermelho quando os usos o excedem) logo depois que um Narrador confirma uma alocação dentro de Alocar Ações.
  - Todo uso já registrado para esse antecedente na data mostrada, cada um com o seu próprio texto e, depois que um Narrador preenche um, o seu resultado.
  - Uma caixa de texto ("O que eles fizeram?") e um botão **Registrar um uso**.
- **Pessoal** - a ação que todo personagem recebe, sejam quais forem os antecedentes que tenha. Aparece como um bloco orçado depois que um Narrador alocou as ações do personagem, tanto pela Ficha do Personagem quanto dentro de Alocar Ações.
- **Outros antecedentes** - todo outro antecedente que o personagem tem, cada um com a nota "Sem orçamento de ação - defina este antecedente em Configurações de Ação e Rumor", e a sua própria caixa de texto e botão Registrar um uso.
- **Limpar tudo para este Personagem** / **Limpar tudo para esta Data** (só Narrador) - cada um abre uma confirmação nomeando o que vai remover antes de fazer qualquer coisa.

## Tarefas comuns

### Registrar o que o seu personagem fez

1. Abra a Ficha do personagem, escolha **Usos de antecedente** e clique em **Ir**.
2. Escolha a **Data do jogo**.
3. Ache o antecedente que você usou, ou procure em **Outros antecedentes**.
4. Digite o que aconteceu.
5. Clique em **Registrar um uso**.

### Preencher o resultado de um uso (Narrador)

1. Abra **Usos de antecedente** do personagem.
2. Ache o uso sob o antecedente dele.
3. Digite na caixa **Resultado…** dele.

### Limpar um uso antes de ser julgado

1. Abra **Usos de antecedente**.
2. Ache o seu uso - ele só mostra um botão **Limpar** enquanto nenhum resultado foi registrado nele.
3. Clique em **Limpar**.

### Limpar todo uso de um personagem ou de uma data (Narrador)

1. Abra **Usos de antecedente** de qualquer personagem da crônica.
2. Clique em **Limpar tudo para este Personagem** ou **Limpar tudo para esta Data**.
3. Confirme.

## O que saber

- **Registrar nunca espera um orçamento.** Até um antecedente que a sua crônica não configurou para conceder uma ação pode ter um uso registrado nele - ele só aparece em "Outros antecedentes" com uma nota explicando por quê.
- **Quando um Narrador preenche um resultado, o uso trava.** Você não pode mais limpá-lo nem editá-lo por conta própria.
- **Limpar remove o registro, não o orçamento.** Limpar tudo para este Personagem/Data apaga só os usos registrados - nunca toca o que a alocação de um antecedente de fato concedeu.
- **O orçamento é ao vivo, não um instantâneo histórico.** O número mostrado sempre reflete a alocação mais recente do personagem, mesmo que você tenha escolhido uma data de jogo mais antiga para olhar.
- **Cada uso conta como um**, seja o que for que descreva - não há como, por esta tela, registrar um uso que valha mais de um contra o orçamento.
- **Registrar, editar e limpar o seu próprio uso seguem a janela de tempo livre da data do jogo**, quando há uma definida - veja [Tempo Livre](downtime-queue.md). Um Narrador nunca é afetado por ela.

## Solução de problemas

- **"Falha ao carregar o registro de antecedentes."** Atualize e tente de novo.
- **"Não foi possível salvar o uso de antecedente."** Nada foi registrado - tente de novo.
- **Não vejo Pessoal.** Nenhuma ação foi alocada para este personagem ainda - peça a um Narrador que execute Alocar Ações.
- **Não vejo um antecedente que sei que o meu personagem tem.** Ele aparece em "Outros antecedentes" se a sua crônica ainda não o configurou para conceder uma ação - pergunte a um Narrador.
- **Não consigo limpar o meu próprio uso.** Um Narrador já registrou um resultado nele, então está travado.
- **Não vejo Limpar tudo para este Personagem/Data.** Esses são só para Narradores.

## Relacionados

- [Ficha de Personagem](character-sheet.md)
- [Alocar Ações](allocate-actions.md)
- [Tempo Livre](downtime-queue.md)
- [Configurações de Ação e Rumor](apr-settings.md)
- [Guia do Jogador](../player-guide.md#6-registrando-usos-de-antecedente)
- [Guia do Narrador](../st-guide.md#9-configurações-de-ação-e-rumor-e-o-registro-de-usos-de-antecedente)
