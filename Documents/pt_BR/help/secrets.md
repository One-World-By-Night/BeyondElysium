# Segredos

Um texto escrito pelo Narrador que você mantém separado do texto de uma trama, item, local, personagem ou NPC - quem é dono da Chantry, o que realmente há na caixa, por que o Príncipe teme o Xerife. Um segredo tem a sua própria configuração real de "quem pode ver isto", e você escolhe exatamente quais personagens foram contados, um por um.

## Quem pode usar

Os Narradores (HST e AST) criam e gerenciam segredos e suas revelações. Um jogador nunca cria um - ele só vê **O Que Você Sabe**, os segredos já revelados aos próprios personagens.

## Como chegar lá

A aba **Segredos** do Kit de Ferramentas do Narrador, que lista todo segredo da crônica, ou a seção **Segredos** no fim da visão de detalhe de uma trama, ou o editor próprio de um item, local ou NPC.

## A tela

- Uma lista dos segredos desta entidade, cada um recolhido ao título. Clique em um para abri-lo. Na aba **Segredos** do Kit a lista contém todo segredo da crônica: busque por título, e cada linha nomeia a que o segredo está ligado. **+ Novo Segredo** ali começa um ligado a uma trama, item, local, personagem ou NPC, ou a nada (o padrão).
- Dentro de um segredo aberto: o texto dele, o seu próprio **Quem pode ver isto** (a mesma escolha Todos/Narradores/regras que uma trama ou item tem) e **Revelado a** - todos a quem foi contado, cada um com um botão **Remover**.
- **Revelar a…** - escolha um personagem, como ele soube (Em jogo, Tempo Livre, Rumor, Contado por alguém ou Outro) e, opcionalmente, **Reter para um lote de lançamento** - veja [Lançamentos](release-batches.md). **Revelar** adiciona.
- Uma revelação criada pelo repasse de um jogador mostra **Contado por** e o personagem que contou, em vez do nome de um Narrador, em **Revelado a**.
- **Adicionar Segredo** no fim - um título e um texto começam um novo, só para Narradores por padrão até você ampliar.
- **Excluir**, em cada segredo - remove-o e toda revelação nele.

## O que saber

- **Um segredo começa só para Narradores, e continua assim mesmo depois de você revelá-lo a alguém.** Revelar um segredo a um personagem registra quem foi contado - não muda por si só quem pode ver o segredo. Defina **Quem pode ver isto** como **Somente personagens que correspondem às regras que eu defini** (ou **Todos**, quando for de conhecimento comum) para uma revelação de fato chegar a esse personagem - a mesma regra de "o público decide quem vê, conexões e regras decidem quem entre eles" que toda outra coisa com público neste plugin segue.
- **Uma revelação retida funciona exatamente como um rumor ou resposta de tempo livre retido** - não chega ao personagem até o lote de lançamento dela sair. Veja [Lançamentos](release-batches.md).
- **Excluir um segredo leva junto toda revelação nele.** Não há como excluir só o segredo e manter um registro de quem o sabia.
- **Os marcadores `[ST]...[/ST]` ainda são retirados** do texto de um segredo para quem não é Narrador, igual a todo lugar - até para um personagem a quem o segredo foi de fato revelado.
- **Os jogadores podem registrar e repassar segredos próprios, se a sua crônica permite** - uma chave por crônica em Configuração da Crônica ("Jogadores e segredos": Desligado, Precisa de um Narrador ou Imediato). O **registro** de um jogador ("Aprendi algo") ainda não é um segredo - chega à Fila de Aprovação, onde você ou o liga a um segredo existente (busca por título) ou monta um novo a partir dele; aprovar de qualquer jeito grava uma revelação aprovada para o personagem que registrou. O **repasse** de um jogador (contar a outro personagem algo que o próprio personagem já sabe) também passa pela Fila de Aprovação, a menos que a sua crônica esteja em Imediato, caso em que o destinatário o lê na hora e a entrada da fila é só a sua conferência posterior - recusá-lo remove o que lhe foi mostrado. Em Imediato, o jogador do destinatário recebe e-mail (conforme a própria escolha dele em [Postagens de tramas e novidades que seus personagens podem ver](profile-settings.md)) de que o personagem dele agora sabe algo, e os HSTs, ASTs e condutores de trama da crônica também recebem e-mail, para a revisão ainda necessária não passar despercebida.

Veja [Fila de Aprovação](approval-queue.md) para o que um registro ou repasse em espera parece ali.

## Solução de problemas

- **"Falha ao carregar os segredos."** Atualize e tente de novo.
- **"Falha ao criar este segredo."** Confira se um título está preenchido.
- **Uma revelação não apareceu para o jogador.** Confira o **Quem pode ver isto** do próprio segredo - precisa ser **Somente personagens que correspondem às regras que eu defini** (uma revelação conta como um dos personagens correspondentes) ou **Todos**, não o padrão só para Narradores.
- **Tentei revelar ao mesmo personagem duas vezes.** Recusado - um personagem só pode ter um dado segredo revelado uma vez; remova a revelação existente primeiro se precisar mudar como ou quando ele soube.

## Relacionados

- [O Que Eu Sei](what-i-know.md)
- [Tramas e Rumores](plot-manager.md)
- [Itens e Locais](world-objects.md)
- [Lançamentos](release-batches.md)
- [Fila de Aprovação](approval-queue.md)
- [Configuração da Crônica](chronicle-setup.md)
