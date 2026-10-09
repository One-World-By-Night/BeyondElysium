# Jogadores

Revise pedidos de entrada, convide um jogador por e-mail com os personagens dele, veja quem ainda não entrou e gerencie os seus jogadores e quais personagens são deles, tudo em uma só tela. O HST e o AST fazem isso aqui. Os papéis da equipe (HST, AST, Condutor de Trama, Harpia) são definidos em [Acesso à Crônica](chronicle-access.md) por um administrador do site. A aba aparece em toda crônica; uma crônica ligada aos papéis do OWbN também concede e revoga esse papel conforme os jogadores são adicionados e removidos, e o link de entrada dela autentica quem pede pelo OWbN.

## Quem pode usar

O HST e o AST da crônica, em toda crônica. Um Condutor de Trama, a Harpia e os jogadores não veem esta aba.

## Como chegar lá

Abra o [Kit de Ferramentas do Narrador](storyteller-toolkit.md), escolha a crônica e depois **Jogadores**.

## A tela

- **Pedidos para entrar** - o link de entrada da crônica, com um botão **Copiar link**, e toda conta que pediu para entrar: a mensagem dela, um personagem que ela começou ou um arquivo do Grapevine que enviou, se algum dos dois carrega o pedido, e **Aprovar**/**Recusar** num que espera. Uma nota digitada antes de **Recusar** vai para quem pediu. Veja [Entrar em uma Crônica](joining.md) para o lado de quem pede.
- **Convidar um jogador** - uma caixa para o endereço de e-mail, uma lista dos personagens de jogador da crônica para marcar e uma caixa, marcada por padrão, para enviar a ele um convite por e-mail. Digitar três letras de um nome em vez de um endereço procura contas existentes; **Usar este e-mail** preenche o da pessoa.
- **A lista de personagens** - os livres primeiro, depois os que esperam o e-mail de alguém, depois os já ligados a um jogador (esses você não pode marcar). Digite na caixa de filtro dela para restringir por nome.
- **Aguardando o primeiro acesso** - todo convite para o qual ninguém entrou ainda: o e-mail, os personagens que o esperam, quem o enviou e quando, com um botão **Cancelar**.
- **Jogadores** - todos que são jogadores nesta crônica, cada um com seus personagens. Cada personagem tem **Desvincular**; cada jogador tem **Adicionar personagens** e **Remover**.
- Uma linha sob **Jogadores** nomeia o papel do OWbN que acompanha ser jogador aqui, como `chronicle/kony/player`.
- Depois de qualquer coisa que você faz, uma mensagem diz o que aconteceu: quem virou jogador, quais personagens foram vinculados, quais foram deixados em paz e por quê.

## Tarefas comuns

### Revisar um pedido de entrada

1. Leia a mensagem e abra um personagem ou arquivo que ele traga, se algum estiver anexado.
2. Clique em **Aprovar** para fazer dele um jogador - um personagem que ele começou fica ativo, um arquivo que ele enviou ainda precisa da própria revisão em Importar.
3. Ou digite uma nota e clique em **Recusar** - um personagem que ele começou para o pedido é excluído, e a nota é enviada a ele por e-mail.

### Convidar um jogador com os personagens dele

1. Digite o endereço de e-mail dele.
2. Marque os personagens dele. Use a caixa de filtro para achá-los pelo nome.
3. Deixe **Enviar a ele um convite por e-mail** marcado se ele deve receber um e-mail, depois clique em **Convidar**.
4. Leia a mensagem. Se ele já tem uma conta do OWbN com esse e-mail, ele é jogador agora e os personagens são dele. Se não, o convite espera em **Aguardando o primeiro acesso**, e na primeira vez que ele entrar pelo OWbN com esse endereço de e-mail ele entra na crônica e encontra os personagens esperando.

### Dar mais personagens a um jogador

1. Clique em **Adicionar personagens** ao lado dele.
2. Marque os personagens e clique em **Vincular os personagens marcados**.

### Tirar um personagem de um jogador

1. Clique em **Desvincular** no personagem e confirme. O personagem fica na crônica, ligado a ninguém, pronto para dar a outra pessoa.

### Cancelar um convite

1. Clique em **Cancelar** ao lado dele em **Aguardando o primeiro acesso** e confirme. Os personagens dele deixam de esperar esse e-mail.

### Remover um jogador

1. Clique em **Remover** ao lado dele e confirme.
2. Ele deixa de ser jogador aqui. Os personagens dele ficam exatamente como estão.

## O que saber

- **Os pedidos de entrada podem ser desligados** em [Configuração da Crônica](chronicle-setup.md). Desligado, a crônica sai da lista de entrada e o link de entrada dela recusa um pedido novo; um que já espera ainda é revisado aqui.
- **Aprovar um personagem ou aceitar um arquivo é o único jeito de entrar.** Os dois tornam a conta um jogador e encerram o pedido ao mesmo tempo - não há um passo separado de "aprovar o pedido" além disso para nenhum dos dois.
- **Aprovar um pedido que traz um arquivo do Grapevine é recusado, com uma nota apontando para Importar** - o arquivo precisa da própria revisão de resoluções/duplicatas lá, que uma aprovação simples não pode dar. Recusar continua funcionando, sem excluir nada do arquivo em si.
- **O e-mail precisa combinar.** Um convite é aceito pela conta cujo endereço de e-mail é o que você digitou, sejam quais forem as maiúsculas. Se a pessoa entra com outro endereço, o convite continua esperando; cancele-o e convide o endereço que ela usa, ou vincule os personagens dela à mão depois que ela for jogadora.
- **O e-mail de convite sai uma vez,** quando você convida, e só se a caixa está marcada. Ele nomeia a sua crônica e você, e leva à crônica com o login do OWbN.
- **Um personagem já ligado a outra pessoa nunca é movido.** Ele é listado na mensagem e deixado em paz; desvincule-o dela primeiro.
- **Os convites funcionam por toda a rede.** A mesma pessoa pode ser convidada por várias crônicas; ela entra em cada uma na primeira vez que entrar.
- **A equipe nunca é alterada aqui.** Convidar um HST ou AST vincula os personagens dele e deixa o papel em paz, e **Remover** só tira jogadores.
- **Os papéis do OWbN acompanham.** Virar jogador aqui também concede o papel de jogador da crônica no OWbN, e removê-lo o tira. Se o OWbN recusa, a mensagem diz por quê; o jogador ainda é adicionado aqui, e um administrador do OWbN pode conceder o papel.
- **Um e-mail pendente definido num personagem** pela lista de Personagens também é um convite. Aparece em **Aguardando o primeiro acesso** e vincula do mesmo jeito.
- **A vinculação é registrada.** O histórico de cada personagem mostra a quem ele foi vinculado ou de quem foi desvinculado.
- **Remover não é excluir.** A conta, os personagens e o lugar da pessoa em qualquer outra crônica ficam intocados.

## Solução de problemas

- **Não há aba Jogadores.** Você não é o HST nem o AST da crônica.
- **"Este pedido traz um arquivo do Grapevine - revise e aceite-o em Importar, o que também encerra este pedido."** Aprovar foi recusado de propósito - vá revisar o arquivo em Importar, que também encerra o pedido depois que você aceitar.
- **Um convite ainda espera depois de a pessoa entrar.** Ela usou outro endereço de e-mail. Confira o endereço da conta dela no OWbN.
- **"... está vinculado a ..., então não foi alterado."** Desvincule esse personagem do outro jogador primeiro e adicione-o de novo.
- **"O OWbN não concedeu ..."** O jogador é adicionado aqui. Passe a mensagem a um administrador do OWbN, que pode conceder o papel.

## Relacionados

- [Entrar em uma Crônica](joining.md)
- [Kit de Ferramentas do Narrador](storyteller-toolkit.md)
- [Personagens](character-list.md)
- [Configuração da Crônica](chronicle-setup.md)
- [Acesso à Crônica](chronicle-access.md)
- [Papéis](roles.md)
