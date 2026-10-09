# Relatórios

Vinte relatórios prontos - listas, histórico, cartões, registros de tramas e ações, estatísticas e mais - cada um gerado como PDF assinado sob demanda.

## Quem pode usar

Chegar a **Beyond Elysium → Ferramenta de Consulta → Relatórios** no wp-admin exige a mesma capacidade de todo o site que abre a Ferramenta de Consulta: uma conta de HST, AST ou administrador. Com a página aberta, ela lista só os relatórios que o seu papel na crônica escolhida pode de fato executar - um Narrador vê os 20. Um Condutor de Trama vê os relatórios de trama, ação e rumor mais tudo o que é aberto a qualquer membro. Regras da Casa, o Calendário do Jogo e os cartões de item, local e ritual são abertos a todo membro da crônica por regra, jogadores inclusive - mas um jogador normalmente nunca abre esta tela do wp-admin, já que ela exige uma conta de nível Narrador ou administrador. Na prática um jogador chega às Regras da Casa por uma página do front-end que um Narrador configurou (veja [Regras da Casa](house-rules.md)), aos Cartões de Item pelo botão "Imprimir Meus Itens" da própria Ficha do Personagem (veja [Ficha de Personagem](character-sheet.md)), e ao Calendário do Jogo, aos Cartões de Local e aos Cartões de Ritual pela própria aba **Relatórios** de Minha Crônica (veja [Minha Crônica](my-chronicle.md)) - esta tela do wp-admin é onde um Narrador gera qualquer um dos 20 como PDF assinado, não o único lugar onde um jogador pode ler os três que já são abertos a ele.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Ferramenta de Consulta → aba **Relatórios**. A própria Ferramenta de Consulta fica na mesma página - veja [Ferramenta de Consulta](query-tool.md).

## A tela

- **Jogo** - uma lista suspensa com toda crônica da instalação.
- Uma tabela que lista cada um que você pode executar: o título (em inglês na tela, como na tabela abaixo), a forma (`table`, `card`, `statistics`, `narrative` ou `calendar`) e um link **Gerar PDF**.
- A linha do **Statistics Report** mostra ainda uma lista suspensa - **Qualidades**, **Defeitos**, **Influências** - escolhida antes de você clicar em **Gerar PDF**, já que é o único relatório em que o campo a resumir não é fixo.
- **Gerar PDF** baixa o relatório como PDF para a crônica escolhida no momento.

### Os 20 relatórios

| Relatório | Quem pode executar |
| --- | --- |
| Character Roster (lista de personagens) | Narradores |
| Sign-In Sheet (folha de presença) | Narradores |
| Experience History (histórico de experiência) | Narradores |
| Search Report (relatório de busca) | Narradores |
| Statistics Report (estatísticas) | Narradores |
| Merits and Flaws Report (qualidades e defeitos) | Narradores |
| Influence Report (influências) | Narradores |
| Vampire Status Report (status de vampiros) | Narradores |
| Player Roster (lista de jogadores) | Narradores |
| Player Point History (histórico de pontos do jogador) | Narradores |
| Character Equipment (equipamento dos personagens) | Narradores |
| Item Cards (cartões de item) | Todo membro |
| Location Cards (cartões de local) | Todo membro |
| Rote Cards (cartões de ritual) | Todo membro |
| Plot Report (relatório de tramas) | Narradores e Condutores de Trama |
| Master Action Report (relatório mestre de ações) | Narradores e Condutores de Trama |
| Master Rumor Report (relatório mestre de rumores) | Narradores e Condutores de Trama |
| Action and Rumor Report (ações e rumores) | Narradores e Condutores de Trama |
| Game Calendar (calendário do jogo) | Todo membro |
| Regras da Casa | Todo membro |

## Tarefas comuns

### Gerar um relatório

1. Abra Beyond Elysium → Ferramenta de Consulta → Relatórios e escolha a crônica.
2. Ache o que você quer na tabela.
3. Clique em **Gerar PDF**.

### Executar o Statistics Report num campo específico

1. Ache **Statistics Report** na tabela.
2. Escolha **Qualidades**, **Defeitos** ou **Influências** na lista suspensa ao lado dele.
3. Clique em **Gerar PDF**.

## O que saber

- **Sem um certificado de assinatura, todos ainda imprimem** - carimbados com NÃO ASSINADO em toda página, com o nome do arquivo terminando em `-unsigned.pdf`. Veja [Fichas Assinadas](signed-sheets.md).
- **Os cartões imprimem quatro por página**, em paisagem, dois por dois, num tamanho real de cartão de 5 por 3 polegadas - os Cartões de Item, de Local e de Ritual compartilham o mesmo layout. Um cartão com figura (o primeiro arquivo anexado de um item ou local) a imprime à esquerda; um cartão cujo texto não cabe numa face continua num segundo cartão impresso ao lado, rotulado como verso, e esse par ocupa a linha inteira para os dois dobrarem ao meio e virarem um cartão.
- **Os Cartões de Item podem ser restritos a um personagem.** Esse é o botão "Imprimir Meus Itens" da própria ficha do personagem, não um controle desta tela - veja [Ficha de Personagem](character-sheet.md).
- **Os Cartões de Ritual funcionam diferente para um mago e para um Narrador.** Um Narrador que os executa aqui vê todo ritual do catálogo da própria crônica. Um jogador que escolhe um personagem na própria aba Relatórios de Minha Crônica vê só os rituais que esse personagem realmente tem, casados pelo nome contra o catálogo - um ritual mantido sem entrada própria no catálogo ainda imprime, montado direto a partir do nome/nota/fonte do catálogo. Só um personagem cuja pilha tem de fato um bloco que guarda rituais (magos, hoje) pode usá-lo; Minha Crônica esconde a aba para o personagem de qualquer outra pessoa.
- **O Calendário do Jogo lista as noites de jogo da crônica.** Toda noite no calendário de Noites de Jogo aparece, a mais próxima primeiro, com data, hora de início, lugar e notas, no PDF e na aba Relatórios de Minha Crônica. Uma crônica sem nenhuma recebe uma nota curta dizendo isso. O texto `[ST]` de um Narrador nas notas é removido para os jogadores. Veja [Noites de Jogo](game-nights.md).
- **As Regras da Casa não têm equivalente no Grapevine**, e são as únicas que também podem viver numa página do front-end em vez de serem geradas sob demanda - veja [Regras da Casa](house-rules.md).
- **Os de ação leem o registro da trama como realmente aconteceu.** O Total, o Crescimento e o que sobra Sem Uso de uma linha de orçamento depois de cada uso aparecem em linhas próprias; quando vários jogadores postaram na mesma trama e um Narrador respondeu a um, nem sempre dá para saber para a postagem de quem era a resposta, e ele diz isso ("A resposta veio depois de várias ações") em vez de adivinhar.
- **O texto só para Narradores é retirado do mesmo jeito que em todo lugar** - uma passagem `[ST]...[/ST]` num item ou local nunca chega a um relatório que um não-Narrador possa executar.

## Solução de problemas

- **"Você não tem permissão para executar este relatório."** O seu papel nesta crônica não cobre esse - confira a tabela acima para ver quem pode executá-lo.
- **"Relatório não encontrado."** A lista da página está desatualizada - recarregue e tente de novo.
- **"Ainda não existe nenhum jogo - crie um em Beyond Elysium → Configuração do Sistema → Jogos primeiro."** Só um administrador do site pode criar um.
- **Não vejo este item de menu de jeito nenhum.** Ele exige o mesmo tipo de conta que abre a Ferramenta de Consulta - um papel de Narrador em pelo menos uma crônica, ou uma conta de administrador.

## Relacionados

- [Ferramenta de Consulta](query-tool.md)
- [Minha Crônica](my-chronicle.md)
- [Regras da Casa](house-rules.md)
- [Itens e Locais](world-objects.md)
- [Ficha de Personagem](character-sheet.md)
- [Fichas Assinadas](signed-sheets.md)
- [Papéis](roles.md)
- [Guia do Narrador](../st-guide.md#12-relatórios-cartões-e-saída-em-lote)
