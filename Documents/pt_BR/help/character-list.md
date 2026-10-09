# Personagens

Uma lista de personagens com busca e ordenação, com um link para a ficha de cada um.

## Quem pode usar

Qualquer membro da crônica pode abrir esta aba. Como jogador - inclusive um Condutor de Trama ou a Harpia da crônica que não seja também HST ou AST - você vê aqui só os seus próprios personagens. Os Narradores (HST e AST) veem todos os personagens da crônica, mais os controles para atribuir ou trocar o jogador de um personagem e para excluir um personagem. Os NPCs nunca aparecem nesta lista para ninguém - os Narradores cuidam deles na página Personagens do wp-admin.

## Como chegar lá

Minha Crônica → aba Personagens.

## A tela

- **+ Novo Personagem** (em Minha Crônica) - abre o editor sem personagem, pronto para começar um.
- **Filtros** - **Tipo** (os tipos de criatura da própria crônica, ou "Todos os tipos"), **Status** ("Todos os status", ou ativo/inativo/aposentado/morto/pendente) e uma caixa de busca que procura pelo nome enquanto você digita.
- **A tabela** (empilhada em cartões numa tela estreita):

  | Coluna | Mostra |
  | --- | --- |
  | (retrato) | Uma miniatura, quando o personagem tem uma |
  | Nome | Leva à aba Ficha; traz um selo "Também ativo em outro lugar" enquanto o personagem tem uma visita aberta a outra crônica - "Não é possível contatar uma visita" se uma ficou em silêncio |
  | Tipo | Tipo de criatura |
  | Status | ativo, inativo, aposentado, morto ou pendente |
  | XP Ganho | Total de experiência ganha |
  | XP Não Gasto | Experiência ainda não gasta |
  | Jogador | O nome do jogador atribuído. Extras só para Narradores: "(não atribuído)" quando ninguém está ligado, e um aviso "agora tem uma conta - Confirmar" quando se encontra uma correspondência de e-mail pendente |
  | Aplicar XP | Só para Narradores: uma caixa para um valor e o seu próprio botão **Aplicar** |
  | Ações | Só para Narradores: Atribuir jogador / Trocar jogador, Excluir |

  Clique no cabeçalho de uma coluna para ordenar por ela; clique de novo para inverter a ordem.
- **Paginação** - Anterior / Próxima, com a página atual e o total.
- **Atribuir um jogador** (janela só para Narradores) - busque por pelo menos três letras de um nome, ou por um endereço de e-mail exato (uma busca parcial por nome nunca mostra um e-mail); atribua um resultado, remova o jogador atual ou registre um e-mail pendente para quem ainda não criou uma conta. Um e-mail pendente é um convite: o personagem se liga a essa pessoa na primeira vez que ela entrar com esse endereço, e numa crônica ligada aos papéis do OWbN o convite também aparece na aba [Jogadores](chronicle-players.md).
- **Aplicar XP** (só para Narradores) - digite um número inteiro na caixa de uma linha e clique no **Aplicar** dessa linha, ou digite em várias linhas - em quantas páginas quiser - e clique uma vez em **Aplicar tudo** abaixo da tabela, com um só **Motivo** compartilhado por todas as linhas que ele envia. Um número negativo tira XP; é recusado, com uma nota sob a caixa, se levaria o XP Ganho abaixo de zero. O que você digita fica lá enquanto você muda de página, ordena, filtra ou busca, até você aplicar ou clicar em **Limpar valores**. O XP aplicado é concedido na hora, igual ao prêmio de um Narrador em qualquer outro lugar - nunca passa pela Fila de Aprovação.

## Tarefas comuns

### Achar o seu personagem

1. Abra **Minha Crônica** → **Personagens**.
2. Clique no nome para abrir a Ficha.

### Filtrar ou buscar na lista

1. Escolha um **Tipo** ou **Status**, ou digite um nome na caixa de busca.

### Ordenar por uma coluna

1. Clique no cabeçalho da coluna - Nome, Tipo, Status, XP Ganho, XP Não Gasto ou Jogador.
2. Clique de novo para inverter a ordem.

### Atribuir ou trocar o jogador de um personagem (Narrador)

1. Clique em **Atribuir jogador** (ou **Trocar jogador**) na linha desse personagem.
2. Digite pelo menos três letras do nome da pessoa, ou o e-mail exato.
3. Clique em **Atribuir** ao lado da pessoa certa - ou, se ela ainda não criou uma conta, digite o e-mail no campo de e-mail pendente e clique em **Salvar**.

### Confirmar uma correspondência de jogador pendente (Narrador)

1. Quando "... agora tem uma conta -" aparecer em **Jogador**, clique em **Confirmar**.

### Excluir um personagem (Narrador)

1. Clique em **Excluir** na linha desse personagem.
2. Confirme. Isso remove de vez o personagem, o histórico de alterações, os instantâneos, o estilo da ficha e as conexões.

### Dar ou tirar XP de um ou mais personagens (Narrador)

1. Digite um número inteiro em **Aplicar XP** para cada personagem - um número negativo tira XP.
2. Preencha o **Motivo** uma vez, abaixo da tabela.
3. Clique no **Aplicar** da linha, ou em **Aplicar tudo** para enviar todas as linhas que você digitou, inclusive as de outras páginas.

## O que saber

- Como jogador, esta aba mostra só os personagens ligados à sua conta - não o resto da lista da crônica.
- O filtro **Tipo** só oferece os tipos de criatura que esta crônica ativou.
- Excluir um personagem (Narrador) não pode ser desfeito - leva junto o histórico de alterações, os instantâneos, o estilo da ficha e as conexões.
- Um selo "Também ativo em outro lugar"/"Não é possível contatar uma visita" ao lado de um nome quer dizer que o personagem tem uma visita aberta a outra crônica - passar o mouse sobre ele nomeia todas as crônicas onde está ativo. Um personagem hospedado aqui vindo de outra crônica não mostra selo nesta lista - a ficha dele traz o aviso "Visitando de ..." no lugar. Veja [Enviar Ficha](transfer.md).

## Solução de problemas

- **"Nenhum personagem encontrado."** Limpe ou ajuste o filtro de Tipo, Status ou a busca.
- **Não vejo Atribuir jogador, Aplicar XP nem Excluir.** Esses são só para Narradores.
- **Aplicar ou Aplicar tudo não clica.** Digite um motivo e um número inteiro de -10.000 a 10.000, diferente de zero.
- **"Isso deixaria o XP Ganho abaixo de zero."** O valor negativo é maior do que o personagem ganhou; aplique uma correção menor, ou [Solicite XP](character-editor.md#solicitar-xp) se um jogador precisa que ele seja adicionado antes.
- **Um personagem que eu sei que existe não está aqui.** Se ele não está ligado à sua conta e você não é Narrador, não aparece - peça a um Narrador que o atribua a você.
- **"Falha ao carregar personagens."** Atualize a página.

## Relacionados

- [Minha Crônica](my-chronicle.md)
- [Ficha de Personagem](character-sheet.md)
- [Editor de Personagem](character-editor.md)
- [Enviar Ficha](transfer.md)
- [Papéis](roles.md)
- [Guia do Jogador](../player-guide.md#1-seus-personagens)
