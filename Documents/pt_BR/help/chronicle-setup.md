# Configuração da Crônica

Uma única lista de conferência para configurar uma crônica. Cada linha é calculada a partir do que a crônica de fato tem, então uma linha fica verde quando você a faz e volta se você desfaz. As configurações que vivem nesta página abrem logo abaixo da linha; todo o resto leva à página que faz o trabalho. Você pode voltar e mudar qualquer coisa quando quiser.

## Quem pode usar

Só a equipe: o **HST** ou **AST** de uma crônica, ou um administrador do site. Um jogador é recusado, e a aba não aparece para uma conta que não pode configurar uma crônica. O **HST** da sua crônica pode mudar **Tipos de criatura**, **Aprovação de novo personagem**, **Listas de compra**, **Restrições de subfacção** e **Identidade Visual**; um AST vê essas mesmas linhas em cinza, e ainda pode abrir o link do Grapevine. **Recursos de trama** e (só na crônica de demonstração) **Excluir crônica de demonstração** continuam só de um administrador do site. Toda outra linha é um link simples para uma página protegida pelo seu próprio papel. Um Condutor de Trama, a Harpia de uma crônica e um jogador não têm motivo para estar aqui - nada nesta tela é deles para consertar.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Configuração da Crônica. Esta é a primeira aba do centro e abre por padrão. Enquanto uma crônica ainda não tem personagens, um aviso no seu Painel do WordPress, na página de Plugins e nas telas do próprio Beyond Elysium aponta para cá; **Dispensar** o esconde para você, só para essa crônica.

## A tela

- **Crônica** - uma lista suspensa com toda crônica da instalação. Escolher uma recarrega a lista de conferência para ela, e a escolha vai para o endereço da página, então fica quando você troca para Acesso à Crônica ou Configurações de Ação e Rumor, segue um link **Ir** ou aperta Voltar.
- Uma linha de resumo: quantas linhas estão prontas, depois quantas precisam de atenção - "5 de 18 prontas. 3 itens precisam de atenção." ou "18 de 18 prontas. Nada precisa de atenção." A própria linha da crônica de demonstração não conta, embora apareça na demonstração.
- A lista de conferência - uma linha por coisa a configurar, cada uma com um selo de status, um título e uma linha de detalhe, e um botão:
  - O selo é **Precisa de atenção** (âmbar) para algo de que uma crônica precisa, **✓ Concluído** (verde, e a linha inteira fica verde clara) depois de definido, ou **Informação** (cinza) para uma linha opcional que você não definiu.
  - **Ir** sai desta página para a que faz o trabalho. **Configurar** (ou **Alteração**, depois que a linha está pronta) abre os controles da linha logo abaixo dela, e **Fechar** os dobra de novo. Uma linha que precisa de atenção começa aberta; toda outra linha começa dobrada, então uma crônica terminada é uma lista curta. Salvar uma linha que precisava de atenção a deixa verde e a dobra.
  - **Tipos de criatura** - *Precisa de atenção* até você escolher quais tipos de criatura esta crônica oferece quando alguém cria um personagem; todo tipo vem aberto por padrão. Os controles são uma caixa de seleção por tipo de criatura - todo tipo que vem com o plugin, mais quaisquer que a sua própria crônica construiu - e um botão **Salvar**; pelo menos um precisa continuar marcado. Salvar todos conta como uma escolha. Um link abaixo da lista abre as [Pilhas de Criatura](creature-stacks.md), já restritas à sua crônica, para construir um tipo de criatura genuinamente novo em vez de só ativar um existente.
  - **Narradores** - *Precisa de atenção* até pelo menos um HST ou AST ser atribuído. **Ir** abre [Acesso à Crônica](chronicle-access.md).
  - **Aprovação de novo personagem** - *Precisa de atenção* até você escolher se um personagem criado por um jogador começa pendente ou ativo: **Requer aprovação** ou **Ativo imediatamente**.
  - **Experiência inicial** - *Informação* até você definir um valor, depois *Concluído*, que o nomeia ("Um novo personagem começa com 25 de experiência, que sua construção pode gastar."). Esse valor é concedido automaticamente na criação, e o custo da própria construção - o que quer que as [regras de criação](creature-stacks.md#o-editor-de-regras-de-criação) do tipo de criatura dele não cubram ainda - é cobrado dele no mesmo passo, a menos que quem cria marque **Personagem existente**.
  - **Páginas do front-end** - *Precisa de atenção* se alguma das quatro páginas provisionadas (Minha Crônica, Kit de Ferramentas do Narrador e as páginas de impressão/verificação) está faltando, nomeando qual. **Ir** refaz o provisionamento das páginas.
  - **Personagens** - *Precisa de atenção* até a crônica ter pelo menos um personagem. **Ir** abre a página Personagens do wp-admin para ela.
  - **Regras de aprovação** - *Concluído* quando a crônica escolheu uma política de aprovação padrão ou tem uma regra própria; a linha de detalhe diz quantas regras há e o que acontece com uma mudança que nenhuma regra cobre. **Ir** abre a aba [Regras de Aprovação](approval-rules.md).
  - **Personalização do catálogo** - *Concluído* quando a crônica personalizou pelo menos um bloco de esquema ou tipo de criatura, ou *Precisa de atenção* sempre que uma atualização do livro mudou algo que a sua crônica também mudou, na cópia própria dela. Sem nada a revisar, **Ir** abre a aba Blocos de Esquema como de costume. Com algo a revisar, a linha abre um painel no lugar: uma linha por correção em palavras simples ("Disciplines › Elder cost: O livro dizia 12, agora diz 15. O seu: 10."), cada uma com **Manter a minha** (mantém o seu valor, a mudança do próprio livro é ignorada), **Usar a do livro** (adota o novo valor do livro, a sua própria mudança é descartada) e, onde há onde fazer outra edição, **Editar**. **Manter todas as minhas**, acima da lista, resolve de uma vez toda correção mostrada mantendo os seus valores. Nada aqui move XP de um jeito ou de outro.
  - **Variantes do livro** - *Informação* até você escolher uma, depois *Concluído*. Alguns blocos que o livro traz têm edições alternativas - Disciplinas de Dark Ages, o pacote de Arcanoi do próprio OWBN e similares - e esta linha deixa a sua crônica trocar uma, sem nunca tocar o bloco base. *Concluído* quando você escolheu qualquer uma, nomeando-as ("Vampire Disciplines: Dark Ages (Grapevine's Dark Ages Menus, for Faith and Fire)."). **Configurar** lista todo bloco que tem variantes, cada um com um botão de opção para uma variante **substituta** (só uma por vez, ou "O do próprio livro" para nenhuma) e uma caixa de seleção por variante **aditiva** (qualquer número). Salvar mostra uma prévia primeiro: se alguma entrada mantida por um personagem deixar de casar com o catálogo na nova escolha, ela lista cada uma e pergunta **Salvar mesmo assim** ou **Cancelar** antes de gravar qualquer coisa - largar uma variante nunca remove o que um personagem já tem, só o que ele pode comprar novo daí em diante.
  - **Modelos de ficha** - *Concluído* quando a crônica substituiu pelo menos um modelo. **Ir** abre a aba Modelos.
  - **Ações de tempo livre e rumores** - *Concluído* quando a crônica salvou qualquer configuração própria de Ação e Rumor. **Ir** abre [Configurações de Ação e Rumor](apr-settings.md).
  - **Recursos de trama** - desligado por padrão, e *Concluído* enquanto a chave está ligada. Ligado, soma Objetivos de Facção a uma trama, as categorias Arco/Subtrama/Temporada/Episódio ao criar uma e uma data opcional numa entrada da linha do tempo - estrutura extra de que a maioria das crônicas nunca precisa. Uma caixa de seleção e nenhum Salvar à parte; a mudança de um administrador do site vale na hora.
  - **Identidade Visual** - *Concluído* quando a crônica tem a própria cor de destaque, usada na moldura do Kit de Ferramentas do Narrador e de Minha Crônica (realces, botões primários). O HST da sua crônica escolhe uma cor e ela salva assim que é escolhida, ou clica em **Usar o padrão do site** para remover a substituição e voltar ao que o administrador do site definiu em [Identidade Visual](branding.md). Qualquer HST pode definir isto, não só um administrador do site - não muda nada além do visual desta crônica.
  - **Restrições de subfacção** - *Concluído* quando pelo menos um campo do catálogo tem a própria lista de valores permitidos. É um nível mais fino que Tipos de criatura: dentro de um tipo de criatura que você já ativou, restrinja um campo real do catálogo só aos valores que a sua crônica usa - uma Seita ou Clã de Vampiro, uma Tribo de Lobisomem e qualquer campo de forma parecida em outro tipo. Cada campo assim ganha uma lista de caixas com os valores reais e o seu próprio botão **Salvar**; pelo menos um valor precisa continuar marcado. Demora um instante para abrir, porque lista todo campo que acha.
  - **Listas de compra** - três chaves, todas desligadas por padrão: **Habilidades**, **Antecedentes** e **Qualidades e Defeitos**. *Concluído* quando pelo menos uma está ligada, e a linha de detalhe nomeia qual. Cada tipo de criatura compra das próprias listas. Ligue uma chave e todo tipo de criatura desta crônica pode comprar das entradas de todo tipo de criatura para essa área, precificada pela lista de onde cada entrada vem - um Vampiro poderia pegar uma Habilidade só de Mago, por exemplo. As listas em si continuam separadas, e importar um arquivo do Grapevine na crônica casa nomes contra a lista mais ampla também, então uma Habilidade só de Mago no arquivo de um Vampiro entra como entrada de catálogo e não como personalizada. As chaves funcionam em qualquer combinação (Habilidades ligada e o resto desligado está bem), e cada uma é tudo ou nada: você não pode abrir uma lista a alguns tipos de criatura e não a outros. Cada chave salva assim que você a marca.
  - **Arquivos do Grapevine dos jogadores** - um link que os jogadores podem usar para enviar a esta crônica o arquivo do Grapevine de um personagem, com esta crônica já escolhida quando seguem. Um botão **Copiar link** o copia. *Concluído* quando um jogador enviou um arquivo por ele. Veja [Enviar um Arquivo do Grapevine](send-grapevine-file.md).
  - **Pedidos para entrar** - ligado por padrão. *Concluído* quando a sua crônica escolheu explicitamente de um jeito ou de outro; *Informação* até lá. Ligado, qualquer pessoa autenticada pode pedir para entrar em Minha Crônica, sem nada concedido até um Narrador aprovar; desligado, esta crônica sai da lista de entrada e pedir é recusado. Revise um pedido na própria seção Pedidos para entrar da aba Jogadores. Veja [Entrar em uma Crônica](joining.md) e [Jogadores](chronicle-players.md).
  - **Jogadores e segredos** - *Concluído* quando a sua crônica escolheu explicitamente um modo; *Informação* até lá (lê-se **Precisa de um Narrador**). Três botões de opção: **Desligado** (os jogadores não podem registrar o que um personagem aprendeu nem contar um segredo a outro personagem), **Precisa de um Narrador** (um registro ou repasse espera revisão antes de chegar a alguém), **Imediato** (um segredo repassado chega ao destinatário na hora, com um Narrador ainda o revisando depois). Veja [Segredos](secrets.md) e [O Que Eu Sei](what-i-know.md).
  - **Crônica de demonstração** - mostrada só enquanto a crônica escolhida é a demonstração semeada. Sempre *Informação*. Um administrador do site recebe aqui um botão **Excluir crônica de demonstração**.

## Tarefas comuns

### Conferir o que falta configurar

1. Abra Configuração da Crônica e escolha a crônica na lista suspensa.
2. Leia a linha de resumo e depois a lista de cima para baixo - tudo marcado *Precisa de atenção* está inacabado, e já está aberto para você agir.

### Limitar quais tipos de criatura são oferecidos

1. Ache a linha **Tipos de criatura**. Ela está aberta enquanto precisa de atenção; senão clique em **Alteração**.
2. Desmarque qualquer tipo que esta crônica não usa.
3. Clique em **Salvar**. A linha fica verde e dobra.

### Exigir a aprovação de um Narrador para novos personagens

1. Ache a linha **Aprovação de novo personagem**.
2. Escolha **Requer aprovação**.

### Dar experiência inicial a novos personagens

1. Ache a linha **Experiência inicial** e clique em **Configurar**.
2. Digite um valor.
3. Clique em **Salvar**. Um novo personagem criado daí em diante começa com esse tanto de XP, e o custo da própria construção é cobrado dele automaticamente.

### Resolver uma correção do livro

1. Ache a linha **Personalização do catálogo** - ela já está aberta, já que uma correção do livro sempre precisa de atenção.
2. Leia a linha em palavras simples de cada correção: o que o livro diz agora e o que a cópia própria da sua crônica ainda diz.
3. Clique em **Manter a minha** para manter o valor da própria crônica, ou em **Usar a do livro** para adotar o novo - ou clique em **Manter todas as minhas** acima da lista para manter todo valor mostrado sem ler cada linha.
4. Se uma correção oferece **Editar**, siga-o para fazer outra mudança nesse mesmo item depois de resolver qual valor manter.

### Trocar por uma variante do livro

1. Ache a linha **Variantes do livro** e clique em **Configurar**.
2. Para um bloco que tem variantes, escolha uma **substituta** (ou deixe "O do próprio livro") e marque as **aditivas** que quiser.
3. Clique no botão **Salvar** do próprio bloco.
4. Se algum personagem já tem uma entrada que a nova escolha deixaria sem correspondência, revise a lista e clique em **Salvar mesmo assim** para prosseguir ou em **Cancelar** para recuar - nada é gravado até você escolher.

### Ligar os recursos de trama ampliados

1. Ache **Recursos de trama** e clique em **Configurar**.
2. Marque a caixa. Ela salva assim que você a marca.

### Restringir uma subfacção (por exemplo, sem Sabbat)

1. Ache **Restrições de subfacção** e clique em **Configurar**.
2. Ache o campo que você quer restringir (por exemplo, o campo Seita do Vampiro).
3. Desmarque os valores que você não quer oferecidos.
4. Clique no botão **Salvar** do próprio campo.

### Deixar os jogadores comprarem da lista de todo tipo de criatura

1. Ache **Listas de compra** e clique em **Configurar**.
2. Marque a área que você quer abrir: Habilidades, Antecedentes ou Qualidades e Defeitos. Ela salva assim que você a marca.
3. Os jogadores desta crônica agora veem a lista completa dessa área, de todo tipo de criatura, quando adicionam um traço.

### Compartilhar o link para os jogadores enviarem os arquivos do Grapevine

1. Ache **Arquivos do Grapevine dos jogadores** e clique em **Configurar**.
2. Clique em **Copiar link** e compartilhe-o do jeito que compartilharia qualquer outro link.

### Voltar e mudar algo que você terminou

1. Clique em **Alteração** numa configuração que vive nesta página, ou em **Ir** numa linha que leva a outra página.
2. Faça a mudança. A linha continua verde enquanto aquilo de que ela lê ainda estiver lá.

### Excluir a crônica de demonstração

1. Escolha a crônica de demonstração na lista suspensa.
2. Ache a linha **Crônica de demonstração** e clique em **Excluir crônica de demonstração**.
3. Confirme "Excluir a crônica de demonstração e todos os 23 personagens de exemplo? Isso não pode ser desfeito."

## O que saber

- **Nada aqui é uma configuração única que você completa e esquece.** Cada linha é calculada ao vivo a partir do que realmente existe - se o seu último AST sai, a linha Narradores volta a *Precisa de atenção* sozinha, da próxima vez que alguém abrir esta página.
- **Verde quer dizer que a crônica tem, não que você marcou algo.** Uma linha lê o que está de fato guardado: uma escolha feita, um bloco personalizado, um modelo substituído, uma configuração salva. Exclua a substituição do modelo e *Modelos de ficha* volta a cinza; nada mantém uma marca depois que aquilo que ela representa some.
- **As linhas opcionais ficam cinza até você defini-las, nunca âmbar.** Só Tipos de criatura, Narradores, Aprovação de novo personagem, Páginas do front-end e Personagens podem pedir atenção. Todo o resto funciona com os padrões do próprio Beyond Elysium até você decidir o contrário.
- **A crônica em que você trabalha segue você.** O slug dela está no endereço da página, então Acesso à Crônica, Configurações de Ação e Rumor e Regras de Aprovação abrem na mesma crônica, e um link **Ir** abre a página dele na crônica que você estava olhando.
- **Restringir tipos de criatura ou subfacções nunca toca um personagem existente.** Só muda o que um personagem *novo* pode ser ou escolher - um personagem construído antes da restrição mantém o valor e continua totalmente legível, editável e aprovável.
- **Ausente ou totalmente marcado sempre quer dizer "toda opção aberta",** nos dois seletores - nunca "nenhuma". Pelo menos um tipo de criatura, e pelo menos um valor por campo restrito, precisa continuar marcado.
- **Salvar uma restrição de subfacção nunca apaga outra.** A restrição de Seita de um Vampiro e a de Clã dele salvam de forma independente.
- **Desligar uma lista de compra não tira nada.** Um personagem que já tem uma entrada da lista de outro tipo de criatura a mantém e ainda pode mudá-la ou removê-la, mas ela não pode mais ser comprada de novo, e a Auditoria de Pontos não pode mais precificá-la a partir do catálogo.
- **Uma lista de compra só amplia o que pode ser comprado.** Nada é gravado em nenhuma lista do catálogo, então desligar uma não deixa vestígio, e as telas de Blocos de Esquema ainda mostram a lista própria de cada tipo de criatura.
- **Um link Ir ainda leva à página que nomeia, mesmo que você não possa fazer nada quando chegar lá.** Se você não é administrador do site, seguir Ir até Acesso à Crônica não mostrará nada de novo.
- **Um AST vê as linhas de configuração exatamente como um jogador - em cinza, somente leitura.** Tipos de criatura, Aprovação de novo personagem, Listas de compra, Restrições de subfacção e Identidade Visual são de um HST; Recursos de trama é de um administrador do site.
- **Experiência inicial e Variantes do livro nunca podem ficar âmbar.** Nenhuma das duas é necessária para conduzir uma crônica - ficam cinza (Informação) até você definir uma, depois verdes (Concluído), o mesmo padrão que Personalização do catálogo e Modelos de ficha já seguem, mas nunca pedem atenção do jeito que Tipos de criatura ou Narradores podem.
- **Largar uma variante do livro nunca toca o que um personagem já tem.** A prévia só lista entradas para você decidir com informação completa; "Salvar mesmo assim" remove a cópia própria da crônica do conteúdo dessa variante, mas todo personagem mantém o que já tem exatamente como está.
- **Uma correção do livro nunca é guardada - é calculada do zero toda vez que a página carrega,** comparando a cópia própria da sua crônica com a versão atual do livro. Resolver uma, de um jeito ou de outro, é o que faz ela parar de aparecer; não há nada para ficar desatualizado.
- **Manter a minha e Usar a do livro nunca movem XP.** Qualquer escolha só muda qual valor é guardado na própria cópia do catálogo - o que um personagem já tem, e o que já gastou, fica intocado de qualquer jeito.

## Solução de problemas

- **"Ainda não existe nenhuma crônica - crie uma em Beyond Elysium → Configuração do Sistema → Jogos primeiro."** Só um administrador do site pode criar uma.
- **Um salvamento mostra um erro.** A mensagem diz por quê - geralmente que a mudança precisa do HST da sua crônica, ou (para Recursos de trama e excluir a crônica de demonstração) de um administrador do site.
- **A lista mostra uma linha em que não posso agir.** Só o HST da sua crônica pode mudar Tipos de criatura, Aprovação de novo personagem, Listas de compra, Restrições de subfacção ou Identidade Visual; só um administrador do site pode mudar Recursos de trama ou excluir a crônica de demonstração. Todo o resto vê a mesma linha, em cinza, como informação.
- **Uma linha que terminei não está verde.** Uma linha só fica verde quando algo de verdade está guardado para ela. Regras de aprovação precisa de uma política padrão escolhida ou de uma regra criada na aba Regras de Aprovação; Ações de tempo livre e rumores precisa de uma configuração salva em Configurações de Ação e Rumor; Personalização do catálogo precisa de um bloco personalizado; Modelos de ficha precisa de um modelo substituído. Confira também a lista suspensa Crônica: a página abre na crônica nomeada no endereço, que pode não ser aquela em que você trabalhou.
- **Personalização do catálogo não fica verde - continua pedindo atenção.** Ela tem pelo menos uma correção do livro ainda não resolvida. Abra o painel e resolva cada linha com Manter a minha, Usar a do livro ou Manter todas as minhas; a linha não pode ficar verde enquanto alguma estiver pendente.
- **Restrições de subfacção diz que nenhum dos meus tipos de criatura ativados tem um campo restringível.** Normal se os tipos que você ativou não têm um campo de forma Clã/Seita/Tribo no catálogo - não há nada a restringir.
- **Um botão Salvar não clica.** Você desmarcou toda opção dessa lista - pelo menos uma precisa continuar marcada.
- **Um aviso no meu Painel diz que uma crônica ainda não está configurada.** Aparece para uma crônica sem personagens e aponta para cá; **Dispensar** o remove para você. Some sozinho quando a crônica tem um personagem.

## Relacionados

- [Pilhas de Criatura](creature-stacks.md)
- [Acesso à Crônica](chronicle-access.md)
- [Entrar em uma Crônica](joining.md)
- [Jogadores](chronicle-players.md)
- [Enviar um Arquivo do Grapevine](send-grapevine-file.md)
- [Configurações de Ação e Rumor](apr-settings.md)
- [Identidade Visual](branding.md)
- [Configurações de Assistência de IA (Crônica)](writing-assist-chronicle.md)
- [Jogos](games.md)
- [Personagens do Administrador](admin-characters.md)
- [Papéis](roles.md)
- [Crônicas](chronicles.md)
- [Guia do Narrador](../st-guide.md#configuração-da-crônica-o-que-falta-configurar)
- [Guia do Administrador](../admin-guide.md#o-que-um-hst-pode-e-não-pode-fazer)
