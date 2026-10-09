# Importar

Traga personagens, itens, locais e rituais de um arquivo de intercâmbio do Grapevine, traga uma crônica inteira de um arquivo de jogo completo e revise tudo o que espera de fora desta crônica - uma transferência que o Narrador de outra crônica enviou, ou um arquivo do Grapevine que um jogador enviou direto.

## Quem pode usar

Os Narradores (HST e AST) para a aba **Personagens e Objetos do Mundo** e **Aguardando Revisão**. A aba **Arquivo de Jogo Completo** exige uma conta de administrador do site - ela não é restrita a uma crônica, já que pode criar uma totalmente nova. Chegar a **Beyond Elysium → Importar**, afinal, exige a mesma capacidade de todo o site que uma conta de HST, AST ou administrador já carrega. As duas abas só aparecem lado a lado para uma conta que tem as duas, o que na prática quer dizer um administrador do site; um Narrador que não é também administrador do site vê a ferramenta Personagens e Objetos do Mundo direto, sem faixa de abas, e nunca chega a Arquivo de Jogo Completo. Um Condutor de Trama, a Harpia da crônica e um jogador nunca veem este item de menu.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Importar.

## A tela

Quando as duas abas estão disponíveis: **Personagens e Objetos do Mundo** e **Arquivo de Jogo Completo**. Senão, a única ferramenta que se aplica à sua conta aparece direto, sem faixa de abas.

### Aguardando Revisão

Mostrada acima da ferramenta Personagens e Objetos do Mundo, e só quando uma crônica tem algo esperando - uma oferta de transferência, um personagem visitante ou um arquivo do Grapevine enviado por um jogador - fica totalmente oculta em caso contrário. Uma tabela: **Personagem**, **De**, **Status**, **Ações**.

- Uma oferta de transferência em espera (**Aguardando revisão**): **Revisar** abre a mesma prévia e as mesmas decisões de um arquivo enviado, terminando em **Aceitar Transferência** ou **Fechar**; **Recusar** a rejeita sem adicionar nada.
- Um personagem visitante (**Visitando**): **Mandar para casa** encerra a visita, **Manter permanentemente** o torna seu de vez.
- Um arquivo do Grapevine enviado por um jogador (**Aguardando revisão**, com "De" dizendo quem o enviou e se está entrando ou visitando) - **Revisar** abre a mesma prévia que o envio de um arquivo recebe, mais quem o enviou, a escolha dele entre entrar ou visitar (sua para mudar antes de aceitar) e uma nota sobre se o código de verificação do próprio arquivo ainda combina com o que a crônica de origem exportou; **Recusar** abre um formulário curto para uma nota opcional de volta a quem enviou antes de rejeitá-lo.

Veja [Enviar Ficha](transfer.md) para o lado de envio de uma transferência, e [Enviar um Arquivo do Grapevine](send-grapevine-file.md) para o arquivo próprio de um jogador.

### Personagens e Objetos do Mundo (`.gex`)

Um assistente de cinco etapas: **1. Enviar**, **2. Pré-visualizar**, **3. Corresponder Jogadores**, **4. Resolver Traços**, **5. Confirmar**.

- **Enviar** - um seletor de arquivo que aceita um arquivo `.gex`, binário ou XML, e **Processar Arquivo**.
- **Pré-visualizar** e **Resolver Traços** mostram, os dois, o mesmo conteúdo de revisão:
  - **Contagens** - quantos registros de cada tipo o arquivo leva (Jogadores, Personagens, Consultas, Itens, Rituais, Locais, Ações, Tramas, Rumores), pulando tudo o que está em zero.
  - **Avisos**, quando o analisador tem algum.
  - **Personagens Duplicados** - cada um já nesta crônica por UUID ou por nome, com uma lista suspensa **Decisão** (Ignorar, Sobrescrever ou Importar como um novo personagem separado) e, para um encontrado nesta crônica, uma tabela do que difere da ficha que já está aqui.
  - **Itens/Locais/Rituais Duplicados** - a mesma escolha Ignorar/Sobrescrever/Importar-como-novo para uma entrada de catálogo já aqui pelo nome.
  - **Traços Sinalizados** - um valor cru que o analisador não conseguiu casar exatamente, com uma lista suspensa de **Sugestão** de correspondências próximas do catálogo, uma caixa **Manter como escrito** e (se você pode gerenciar o catálogo) **Também adicionar ao catálogo**.
  - **Não Resolvidos** - um valor cru sem nenhuma correspondência no catálogo, oferecendo só **Manter como escrito, sem correspondência** e, se você pode gerenciar o catálogo, **Também adicionar ao catálogo**. Uma lista fechada, como os Níveis de Vitalidade de um tipo de criatura, não oferece nenhum dos dois: um nome ali precisa casar com uma das entradas dela, e a linha diz isso.
  - **Jogadores que Precisam de uma Correspondência** - o nome de um jogador do arquivo sem conta do WordPress confirmada ainda, e quaisquer correspondências sugeridas.
- **Corresponder Jogadores** - a mesma lista de jogadores que precisam de uma correspondência, ou uma nota de que o formato deste arquivo não leva nenhuma identidade de jogador para casar (XML), ou de que todos já casaram.
- **Confirmar** - quando tudo está resolvido: "Pronto para confirmar o trabalho [id]. Isto cria todo item, local, ritual e personagem numa só transação - nada é gravado a menos que tudo dê certo." **Confirmar** aplica; o resultado lista quantos registros de cada tipo foram processados, cada personagem/item/local/ritual pelo nome (e a ação dele, quando não é uma criação simples) e uma nota quando o arquivo também levava tipos de registro que esta ferramenta não importa.

### Arquivo de Jogo Completo (`.gv3`)

Um assistente de cinco etapas: **1. Enviar**, **2. Pré-visualizar**, **3. Escolher Destino**, **4. Resolver**, **5. Confirmar**.

- **Enviar** - um seletor de arquivo que aceita um arquivo `.gv3` (binário) e **Processar Arquivo**.
- **Pré-visualizar** - o título da crônica, o mesmo conteúdo de revisão da etapa Pré-visualizar do assistente `.gex`, uma seção **Tramas, Boatos e Ações** (abaixo) e **Não Importado por Esta Ferramenta**: contagens de consultas, prêmios de XP, modelos e entradas de calendário que o arquivo leva (e configurações de alocação de ação/rumor, quando presentes) que esta ferramenta deixa de fora.
- **Escolher Destino** - **Criar uma nova crônica** (com uma caixa **Nome**) ou **Mesclar em uma crônica existente** (uma lista suspensa das crônicas desta instalação). Mesclar nunca sobrescreve a crônica existente por inteiro - só os registros que colidem pelo nome precisam de uma decisão, em seguida.
- **Resolver** - a prévia, conferida de novo contra o destino que você escolheu, então qualquer duplicata mostrada é real para essa crônica específica.
- **Confirmar** - o mesmo padrão de contagem de bloqueios do assistente `.gex`; quando pronto, uma linha que nomeia o trabalho e diz que está criando uma nova crônica com o nome que você deu, ou mesclando na crônica que você escolheu. **Confirmar** aplica; o resultado nomeia a crônica criada ou mesclada e as contagens de personagens, itens, locais, rituais, tramas, rumores e ações processados.

#### Tramas, Boatos e Ações

Quando o arquivo leva alguma, uma caixa de seleção por tipo - **Importar N trama(s)**, **Importar N boato(s)**, **Importar N ação(ões)** - cada uma marcada por padrão, com quantas de cada já existem na crônica de destino (por título e data) e serão puladas de qualquer modo. Uma linha abaixo nomeia qualquer personagem do elenco ou de ação que não casa com ninguém, no arquivo ou na crônica.

- **As tramas** chegam só para Narradores. Cada membro do elenco que casa com um personagem de verdade vira uma conexão com esse personagem; um nome que não casa com ninguém, e o próprio narrador do Grapevine, vão para as notas de Narrador da trama. Cada desdobramento vira uma nota datada, só para Narradores.
- **Os rumores** chegam montados do mesmo jeito que o próprio gerador de rumores do Kit de Ferramentas do Narrador monta um: um rumor feito chega entregue, então os alvos dele já podem lê-lo (sem e-mail - as importações nunca enviam um); um rumor não feito chega retido, em nenhum lote, para um Narrador lançar depois. O direcionamento do próprio Grapevine passa junto onde pode: uma consulta com uma condição limpa vira uma regra de público de verdade; os rumores MultiKey/MultiMatch levam a chave de nível e a correspondência direto. Uma consulta que esta instalação não consegue representar como uma regra - mais de uma condição ao mesmo tempo, uma condição de "NÃO combina" ou uma que precisa de um nome de traço e de uma contagem - mantém o rumor só para Narradores, com uma nota na trama explicando por quê.
- **As ações** casam com o personagem do mesmo jeito que todo o resto neste arquivo. Uma correspondência entra no histórico de ações próprio do personagem para a data (o mesmo registro que a Fila de Tempo Livre já usa), uma entrada por subação; uma ação feita a marca como resolvida. Um nome que não casa com nenhum personagem é pulado e listado.

Importar o mesmo arquivo de novo nunca duplica nenhum dos três - uma trama ou rumor já presente por título e data, ou uma ação já registrada para esse personagem e data, é pulado e contado, não recriado.

## Tarefas comuns

### Importar um arquivo de intercâmbio de personagem ou objeto do mundo

1. Abra Beyond Elysium → Importar e escolha a crônica.
2. Em **Enviar**, escolha um arquivo `.gex` e clique em **Processar Arquivo**.
3. Resolva todo personagem duplicado, item/local/ritual duplicado, traço sinalizado e traço não resolvido mostrado.
4. Clique em **Próximo: Corresponder Jogadores**, depois em **Próximo: Resolver Traços**, depois em **Próximo: Confirmar**.
5. Clique em **Confirmar**.

### Resolver um traço sinalizado

1. Ache-o em **Traços Sinalizados**.
2. Escolha o nome do catálogo mais próximo em **Sugestão**, ou marque **Manter como escrito** para mantê-lo exatamente como o arquivo o tem.
3. Se você pode gerenciar o catálogo e quer que importações futuras casem isto automaticamente, marque também **Também adicionar ao catálogo**.

### Resolver um personagem duplicado

1. Ache-o em **Personagens Duplicados**.
2. Leia pelo que ele foi casado e, se mostrado, o que difere da ficha que já está aqui.
3. Escolha **Ignorar**, **Sobrescrever** ou **Importar como um novo personagem separado**.

### Importar uma crônica inteira de um arquivo de jogo

1. Abra Beyond Elysium → Importar → aba **Arquivo de Jogo Completo** (só administrador do site).
2. Em **Enviar**, escolha um arquivo `.gv3` e clique em **Processar Arquivo**.
3. Clique em **Próximo: Escolher Destino**, depois escolha **Criar uma nova crônica** (e dê um nome) ou **Mesclar em uma crônica existente** (e escolha uma).
4. Clique em **Próximo: Resolver** e trate toda duplicata e todo traço sinalizado.
5. Clique em **Próximo: Confirmar** e depois em **Confirmar**.

### Revisar e aceitar uma transferência de outra crônica

1. Abra Beyond Elysium → Importar. Uma oferta em espera aparece em **Aguardando Revisão**.
2. Clique em **Revisar**.
3. Tome todas as decisões que a prévia pede.
4. Clique em **Aceitar Transferência**.

### Encerrar a visita do personagem de outra crônica

1. Ache-o em **Aguardando Revisão**.
2. Clique em **Mandar para casa** para encerrar a visita, ou em **Manter permanentemente** para torná-lo seu de vez.

### Revisar e aceitar um arquivo do Grapevine que um jogador enviou

1. Abra Beyond Elysium → Importar. Um arquivo em espera aparece em **Aguardando Revisão**.
2. Clique em **Revisar**. O nome e e-mail de quem enviou, a escolha dele entre entrar/visitar, uma nota de verificação (se o arquivo leva um código) e - se ele pediu - "O jogador quer que isto seja mantido atualizado com a crônica de origem." aparecem acima da prévia de costume.
3. Tome todas as decisões que a prévia pede. Um personagem duplicado que já pertence a outra pessoa pode ser importado como novo ou recusado, nunca sobrescrito.
4. Mude **Entrando**/**Visitando** se a escolha de quem enviou não é a que você quer, depois clique em **Aceitar Ficha**.

### Recusar um arquivo enviado por um jogador

1. Ache-o em **Aguardando Revisão**.
2. Clique em **Recusar**.
3. Adicione uma nota para quem enviou, se quiser (opcional), depois clique em **Recusar ficha**.

## O que saber

- **Nada é gravado até você confirmar ou aceitar.** Toda etapa antes disso é só prévia, e uma confirmação se aplica numa só transação, tudo ou nada - uma falha no meio não deixa nada para trás.
- **O formato é detectado automaticamente.** Envie o `.gex` que tiver, binário ou XML - você não escolhe um formato. Um `.gv3` enviado a Personagens e Objetos do Mundo, ou um `.gex` enviado a Arquivo de Jogo Completo, é rejeitado com uma mensagem que diz qual ferramenta usar.
- **Um nome duplicado é resolvido uma só vez.** Duas entradas com o mesmo nome num arquivo compartilham uma decisão, então escolher Sobrescrever não deixa uma segunda entrada sobrescrever em silêncio o que a primeira acabou de gravar.
- **Alguns tipos de registro ainda não têm lugar.** As consultas, prêmios de XP, modelos e entradas de calendário de um arquivo de jogo completo (e configurações de alocação de ação/rumor, quando presentes) são contados e deixados de fora em vez de adivinhados. Tramas, rumores e ações têm lugar - veja Tramas, Boatos e Ações acima.
- **As decisões voltam ao zero com um arquivo ou destino novo.** Clicar em Recomeçar, enviar um arquivo novo ou escolher outro destino de mesclagem limpa toda decisão de traço e duplicata que você tinha tomado - nada passa de um trabalho ou destino diferente.
- **Os arquivos XML não levam identidade de jogador.** Uma exportação binária inclui os endereços de e-mail dos jogadores, que o Beyond Elysium pode casar automaticamente; uma exportação XML não, então todo personagem precisa ter o jogador atribuído à mão depois, pela lista.
- **As listas de compra abertas de uma crônica contam.** Se a crônica ligou Habilidades, Antecedentes ou Qualidades e Defeitos em [Configuração da Crônica](chronicle-setup.md), uma entrada do arquivo que só outro tipo de criatura lista (uma Habilidade só de Mago no arquivo de um Vampiro, digamos) casa como a entrada de catálogo que é, e não é oferecida como uma personalizada que espera o preço de um Narrador. Com a lista desligada, ela ainda entra como personalizada.
- **Um tipo de criatura sem equivalente no Grapevine** ainda pode chegar dentro de um arquivo importado, mas não pode ser exportado, transferido nem receber código de verificação depois.
- **Um personagem cujo tipo de criatura não existe aqui é recusado, não adivinhado.** Quando o arquivo nomeia um tipo de criatura que este site nunca construiu, ou que esta crônica desligou, esse único personagem é nomeado na prévia ("Chimera: não existe o tipo de criatura Chimera neste site") e pulado na confirmação - nada é gravado para ele. Todos os outros do mesmo arquivo ainda são importados normalmente. Todo tipo de criatura que o próprio Grapevine suporta - Hunter e Various inclusive - vem aqui e é importado normalmente; isto só pega um tipo totalmente inventado, ou um que a sua crônica desligou em [Configuração da Crônica](chronicle-setup.md). Various é a exceção a "desligado": um Narrador sempre pode importá-lo, e um arquivo que um jogador envia e o nomeia é recusado.
- **Uma transferência também precisa da sua revisão.** Aceitar uma em Aguardando Revisão é o mesmo fluxo de prévia e decisão de um arquivo, e nada é adicionado a esta crônica até você clicar em Aceitar Transferência.
- **Um arquivo enviado por um jogador funciona do mesmo jeito, com uma regra extra.** Um personagem duplicado que já pertence a outra pessoa nunca pode ser sobrescrito a partir de um arquivo enviado por um jogador - só ignorado ou importado como um novo personagem separado.
- **Aceitar um arquivo visitante mantido atualizado tenta pareá-lo com o lar verdadeiro dele.** Se quem enviou pediu para mantê-lo atualizado e o arquivo leva um código de verificação, aceitá-lo como visita também envia um pedido de pareamento à crônica de origem que o código nomeia - dispara e esquece, então uma origem que nunca responde, ou recusa, simplesmente deixa a cópia aqui sem par em vez de fazer a própria aceitação falhar.

## Solução de problemas

- **"É necessário enviar um arquivo."** Escolha um arquivo antes de clicar em **Processar Arquivo**.
- **"Este arquivo não é um arquivo de intercâmbio do Grapevine reconhecido."** O arquivo não é um `.gex` de verdade. Se for um arquivo de crônica completo, use a aba **Arquivo de Jogo Completo**.
- **"A importação de arquivo de jogo completo (.gv3) ainda não é suportada - exporte um arquivo de intercâmbio .gex."** Você enviou um arquivo `.gv3` a Personagens e Objetos do Mundo. Troque para **Arquivo de Jogo Completo** (só administrador do site) e envie-o lá.
- **"Esta rota aceita um arquivo de jogo completo do Grapevine (.gv3, binário) - um arquivo de intercâmbio .gex deve passar pela página de Importação normal."** Você enviou um arquivo `.gex` a Arquivo de Jogo Completo. Use Personagens e Objetos do Mundo.
- **Confirmar não clica.** Restam decisões pendentes - a contagem necessária é mostrada acima do botão. Volte e resolva cada uma.
- **Não vejo a aba Arquivo de Jogo Completo.** Ela exige uma conta de administrador do site, não só a de um Narrador.
- **Não vejo este item de menu de jeito nenhum.** Ele exige um papel de Narrador em pelo menos uma crônica, ou uma conta de administrador.

## Relacionados

- [Enviar Ficha](transfer.md)
- [Enviar um Arquivo do Grapevine](send-grapevine-file.md)
- [Itens e Locais](world-objects.md)
- [Papéis](roles.md)
- [Importar/Exportar do Grapevine](grapevine.md)
- [Guia do Administrador](../admin-guide.md#importar)
- [Guia do Narrador](../st-guide.md#5-importando-do-grapevine)
