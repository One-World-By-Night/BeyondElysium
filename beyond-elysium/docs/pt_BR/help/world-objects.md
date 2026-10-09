# Itens e Locais

O catálogo da crônica de itens, locais e rituais que existem por conta própria, independentes de qualquer personagem - crie-os, edite-os, faça uma variante com Duplicar, copie um item para um personagem específico e conecte-os a quem os tem.

## Quem pode usar

Os Narradores (HST e AST). Chegar a **Beyond Elysium → Itens e Locais** no wp-admin exige a mesma capacidade de todo o site que uma conta de HST, AST ou administrador já carrega. Um Condutor de Trama, a Harpia da crônica e um jogador nunca veem este item de menu - uma Harpia gerencia favores no [Registro de Favores](boon-ledger.md) à parte, e os favores nunca aparecem neste catálogo.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Itens e Locais.

## A tela

- **Jogo** - uma lista suspensa com toda crônica da instalação. Escolher uma carrega o catálogo dessa crônica.
- **Novo item**, **Novo local** ou **Novo ritual** - abre um formulário de criação em branco; o rótulo segue a aba ativa.
- **Novo item do livro**, só itens - abre o seletor do livro em vez de um formulário em branco. Veja **Adicionar do livro** abaixo.
- Abas: **Itens**, **Locais**, **Rituais**. Os favores têm o seu próprio registro e nunca aparecem aqui.
- **Buscar nome…** - filtra a lista da aba ativa pelo nome.
- **Catálogo / Cópias pessoais / Todos**, só itens - quais itens a lista mostra. **Catálogo** (o padrão) esconde toda cópia feita para um personagem; **Cópias pessoais** mostra só essas; **Todos** mostra as duas. Veja **Copiar para um personagem** abaixo.
- Uma tabela paginada, com colunas que mudam por aba:
  - Itens: Nome, Tipo, Dano, Nível.
  - Locais: Nome, Tipo, Proprietário, Segurança.
  - Rituais: Nome, Nível, Esferas.
- Clique numa linha para abri-la no painel de detalhe ao lado da lista.
- **Anterior** / **Próximo**, com a página atual e a contagem total.

### O painel de detalhe

- Um **caminho de navegação**, só locais, mostrado quando o local está dentro de outro - "Centro › Elysium."
- Nome e descrição. Um Narrador vê qualquer passagem `[ST]...[/ST]` na descrição ou numa propriedade de texto destacada no lugar, para a parte que um jogador nunca recebe ser fácil de notar; um jogador nunca vê a passagem. Veja [Conteúdo Só para Narradores](storyteller-only.md).
- **Baseado em {fonte}**, só itens, mostrado só numa cópia - o item de que foi copiada. Veja **Copiar para um personagem** abaixo.
- **Esgotado.** / **Expirado.**, só itens, mostrado só depois que um se aplica - veja **Usos e Expira** abaixo.
- Toda propriedade que o tipo de objeto define, formatada para o seu tipo:
  - **Itens** - Tipo, Subtipo, Nível, Bônus, Tipo de Dano, Quantidade de Dano, Ocultabilidade, Poderes, Aparência, Usos, Usos Restantes, Expira, mais entradas de lista de traços para Tempers, Negativos, Habilidades e Disponibilidade.
  - **Locais** - Tipo, Proprietário, Onde, Aparência, Acesso, Segurança, Traços de Segurança, Retestes de Segurança, Barreira, Umbra, Afinidade, Totem, mais uma lista de traços de Vínculos (a de texto livre original que o Grapevine trouxe - veja **Vínculos** abaixo para a mais nova, a de verdade). **Proprietário** e **Onde** mostram o nome de um personagem ligado de verdade ou do local pai no lugar do texto digitado, onde houver um - veja O que saber.
  - **Rituais** - Nível, Duração, Descrição, Graus, mais uma lista de traços de Esferas.
- Raridade, Custo e Limitações, mostrados só quando definidos.
- **Dentro Deste Local**, só locais, mostrado quando outros locais estão aninhados dentro deste - cada um nomeado, por exemplo todo cômodo dentro de um prédio.
- **Quem Está Aqui**, só locais - quem está `based_at` neste local agora. Você vê todo vínculo nomeado, com qual deles (proprietário, domínio, refúgio ou baseado aqui); um jogador só vê os NPCs baseados aqui cujo próprio perfil público o alcança (veja [Quem é Quem](whos-who.md)) - nunca o rótulo de um vínculo, nunca o refúgio de outro jogador.
- **Quem pode ver isto** - só itens e locais, nunca rituais: **Todos na crônica** (o padrão), **Somente Narradores e Condutores de Trama** ou **Somente personagens que correspondem às regras que eu defini**, que abre um construtor de consultas contra os seus personagens com uma contagem ao vivo de quem corresponde no momento. Um personagem diretamente conectado ao item ou local (o detentor dele, por exemplo) sempre o vê, seja qual for esta configuração.
- **Arquivos** - só itens e locais: quaisquer imagens ou PDFs anexados, até um para um item ou 20 para um local, de 10 MB cada, com um link de download por arquivo e, para você, uma caixa de envio e um botão **Remover** por arquivo.
- **Segredos**, itens e locais, só Narradores - todo segredo ligado a esta entrada, cada um com o seu público e a quem foi revelado, direto na visão de detalhe. Veja [Segredos](secrets.md). A visão de detalhe própria de um jogador mostra, no lugar, o que os personagens dele já sabem dela.
- **Conexões** - todo personagem conectado a este objeto, e as ferramentas para adicionar ou remover um. Veja [Conexões](connections.md).
- **Histórico**, só itens - todo evento registrado contra este item, o mais antigo primeiro: dado, tomado, trocado, roubado, perdido, usado, copiado, proposto e ajustado, cada um com quando aconteceu e qualquer nota anexada.
- Botões **Editar**, **Duplicar**, (só itens) **Copiar para um personagem** e (só itens) **Transferir** abaixo da visão de detalhe.
- **Imprimir cartão**, itens e locais, para um Narrador - imprime o cartão só desta entrada como PDF, o mesmo layout de cartão que Cartões de Item e Cartões de Local usam em Relatórios, mas só para a entrada que você tem aberta. A cópia do cartão de um jogador continua sendo **Imprimir Meus Itens**.

### Copiar para um personagem

Só itens - uma cópia de verdade, independente, de um item do catálogo, feita para um personagem específico, distinta de **Duplicar** (um formulário de criação em branco preenchido a partir do original, conectado a ninguém) e de uma **Conexão** comum (a mesma linha do catálogo compartilhada por todos os conectados a ela).

1. Abra o item e clique em **Copiar para um personagem**.
2. Escolha o personagem e, se quiser, dê à cópia um nome próprio (senão ela mantém o nome da origem).
3. Clique em **Copiar**. A nova cópia abre no editor, já conectada a esse personagem e visível só a ele.

Copiar leva todo campo e propriedade, e qualquer arquivo anexado à origem - como um arquivo próprio e separado, então mudar o arquivo de um item nunca toca o do outro. A cópia é sempre visível só ao seu detentor, como **Somente personagens que correspondem às regras que eu defini**, seja o que for que o **Quem pode ver isto** da própria origem diga; amplie a partir daí como qualquer outro item se ela deve alcançar mais alguém. O item de origem nunca é mudado.

### Adicionar do livro

Só itens - começa um item novo a partir do catálogo declarado, somente leitura, do livro (equipamento dos livros de regras do Mind's Eye Theatre) em vez de um formulário em branco.

1. Clique em **Novo item do livro**.
2. Busque pelo nome, ou restrinja por **Livro** ou **Tipo**.
3. Clique em **Usar este** na entrada que você quer. O formulário de criação comum abre, preenchido com o nome, a descrição e todo atributo que o livro imprime dessa entrada.
4. Mude o que precisar e clique em **Criar**.

O livro em si é somente leitura - o que você recebe é um item novo comum no catálogo da sua própria crônica, livre para editar ou excluir daí em diante, igual a um montado à mão. Ele não é conectado a nenhum personagem e não carrega nenhum link visível de volta à entrada do livro de que partiu.

### Usos e Expira

Só itens, no formulário de criação/edição: **Usos** (quantas vezes pode ser usado, afinal, deixe em branco para ilimitado) e, depois que o item existe, **Usos Restantes** e **Expira** (uma data). Depois que **Usos** é definido, o item mostra **Esgotado** no instante em que os **Usos Restantes** chegam a zero; **Expira**, depois de passado, mostra **Expirado** sozinho, sejam quais forem os usos. Os dois são conferidos sempre que o item é usado - veja os Cartões de Item de [Relatórios](reports.md) para como usos e validade são impressos num cartão, e cada edição de qualquer um é registrada no **Histórico** do próprio item.

### Transferir

Só itens - move a conexão de um item de quem o tem agora para um novo personagem num só passo, ou a limpa, sem ninguém com ele.

1. Abra o item e clique em **Transferir**.
2. Escolha como mudou de mãos: **Dado**, **Trocado**, **Roubado** ou **Perdido**.
3. Para qualquer coisa menos **Perdido**, escolha o destinatário.
4. Se quiser, adicione uma nota e clique em **Transferir**.

Toda conexão existente entre o item e um personagem é removida primeiro - a transferência de um item físico é exclusiva, não mais uma conexão compartilhada ao lado de outras - e a mudança é registrada no **Histórico** do próprio item.

### Códigos de verificação

Só itens. Toda vez que o cartão de um item é impresso - **Cartões de Item** em Relatórios, ou o **Imprimir Meus Itens** de um jogador - ele traz uma linha "Verify: {link}" que um jogador pode abrir para confirmar que o cartão é genuíno e ver se ainda combina com o item hoje (quem o tem, usos restantes e validade). Veja [Verificar Personagem](verify.md).

Imprimir de novo o cartão do mesmo item reutiliza o mesmo código enquanto nada nele (o nome, os usos restantes ou a validade) mudou desde então; uma mudança de verdade, ou um novo detentor, imprime um código novo. Um código antigo nunca é descartado em silêncio - continua ativo e só relata a divergência, a menos que você mesmo o revogue.

- **Revogar Cartões**, na visão de detalhe do item - revoga na hora todo código já impresso para este item. Nada no item em si muda; só os códigos impressos dele deixam de verificar.

### O formulário de criação/edição

- **Nome** (obrigatório), uma **Descrição** em texto rico (com um botão Assistência de IA), **Raridade**, **Custo**, uma **Limitações** em texto rico (com um botão Assistência de IA). Para um item ou local, **Quem pode ver isto** também - veja a descrição do próprio painel de detalhe acima; não é oferecido para um ritual.
- **Dentro de**, só locais - uma lista suspensa de todo outro local desta crônica. Deixe "Nenhum (nível superior)" para um local que não está dentro de nada.
- Um campo por propriedade que o tipo de objeto define - uma caixa de uma linha, uma caixa numérica, uma caixa de data, uma caixa de texto rico (com o seu próprio botão Assistência de IA, para uma propriedade de texto longo como a Aparência de um item ou a Descrição de um ritual) ou, para uma propriedade de lista de traços, uma linha repetível Nome/Contagem/Nota com o seu próprio **Adicionar** e **Remover**.
- **Vínculos**, só locais, mostrado depois que o local já existe (não ao criá-lo pela primeira vez) - quem é o **Proprietário**, de quem é o **Domínio**, de quem é o **Refúgio** e quem está **Baseado aqui**. Cada vínculo nomeia um personagem de verdade; **Adicionar Vínculo** acrescenta um, **Remover** tira um. Não é o mesmo que a lista de traços **Vínculos** mais antiga entre as propriedades acima, que é texto livre que o Grapevine já escreveu e é deixada exatamente como estava.
- **Segredos**, só itens e locais, mostrado depois que já existe - um texto guardado separado do texto do item ou local, com o seu próprio público e revelações. Veja [Segredos](secrets.md).
- **Salvar Alterações** (editando) ou **Criar** (novo), e **Cancelar**.

"Ainda não existe nenhum jogo - crie um em Beyond Elysium → Configuração do Sistema → Jogos primeiro." aparece no lugar de tudo acima quando não existe nenhuma crônica na instalação.

## Tarefas comuns

### Criar um item, local ou ritual

1. Abra Beyond Elysium → Itens e Locais e escolha a crônica.
2. Escolha a aba **Itens**, **Locais** ou **Rituais**.
3. Clique em **Novo item**, **Novo local** ou **Novo ritual** (conforme a aba que você escolheu).
4. Preencha **Nome** e as propriedades que se aplicam.
5. Clique em **Criar**.

### Adicionar um item do livro

1. Clique em **Novo item do livro**.
2. Busque ou filtre para achar a entrada e clique em **Usar este**.
3. Mude o que precisar no formulário preenchido e clique em **Criar**.

### Editar uma entrada existente

1. Ache-a na lista - use **Buscar nome…** se a lista for longa.
2. Clique na linha dela para abrir o painel de detalhe.
3. Clique em **Editar**.
4. Mude o que precisar e clique em **Salvar Alterações**.

### Fazer uma variante de uma entrada existente

1. Abra a entrada que você quer copiar.
2. Clique em **Duplicar**.
3. O formulário abre preenchido a partir do original, com o nome "Cópia de [nome original]" - mude o que deve diferir.
4. Clique em **Criar**. A entrada original nunca é tocada.

### Copiar um item para um personagem

1. Abra o item.
2. Clique em **Copiar para um personagem**.
3. Escolha o personagem e, se quiser, renomeie a cópia.
4. Clique em **Copiar**. Ela abre no editor, conectada a esse personagem e visível só a ele - o item original nunca é tocado.

### Transferir um item a um novo personagem

1. Abra o item.
2. Clique em **Transferir**.
3. Escolha como mudou de mãos e, a menos que seja **Perdido**, o destinatário.
4. Se quiser, adicione uma nota e clique em **Transferir**.

### Dar a um item um número limitado de usos ou uma data de validade

1. Abra o item e clique em **Editar**.
2. Defina **Usos** como quantas vezes pode ser usado (deixe em branco para ilimitado), ou defina **Expira** como uma data.
3. Clique em **Salvar Alterações**. Depois que **Usos** é definido, **Usos Restantes** começa igual a ele e conta para baixo conforme o item é usado.

### Restringir quem pode ver um item ou local

1. Abra-o e clique em **Editar** (ou defina ao criar).
2. Em **Quem pode ver isto**, escolha **Somente Narradores e Condutores de Trama** ou monte uma regra em **Somente personagens que correspondem às regras que eu defini**.
3. Salve. Um personagem já conectado a ele (o detentor dele, por exemplo) continua vendo de qualquer jeito.

### Anexar um arquivo a um item ou local

1. Abra-o.
2. Em **Arquivos**, clique em **Escolher arquivo** e escolha uma imagem ou PDF (10 MB no máximo; um arquivo para um item, 20 para um local).
3. Ele aparece na lista na hora. Clique em **Remover** para tirá-lo de novo.

### Conectar um item ou local ao personagem que o tem

1. Abra o item ou local.
2. Em **Conexões**, siga [Conexões](connections.md).

### Aninhar um local dentro de outro, ou definir de quem ele é

1. Abra o local e clique em **Editar** (ou defina ao criar).
2. Em **Dentro de**, escolha o local em que ele está, se houver.
3. Depois que o local existe, use **Vínculos** para nomear o Proprietário, o Domínio, o Refúgio ou quem está Baseado ali - cada um um personagem de verdade.
4. Salvar.

## O que saber

- **O Proprietário e o Onde de um local preferem um vínculo real ao texto digitado, mas o texto digitado nunca se perde.** Os campos Proprietário/Onde do próprio Grapevine continuam indo e voltando pela importação e exportação sem mudar; um vínculo real de Proprietário ou um pai em Dentro de simplesmente tem precedência na exibição onde houver um, aqui e no relatório Cartões de Local igualmente.
- **Um local não pode ser excluído enquanto há algo dentro dele.** Mova ou exclua primeiro o que está aninhado dentro.
- **Um personagem conectado por qualquer vínculo de local sempre o vê**, a mesma regra que já vale para o detentor de um item - um Proprietário, detentor do Domínio, detentor do Refúgio ou alguém Baseado ali vê o local seja qual for o público dele.
- **Duplicar nunca toca o original.** Ele preenche um formulário de criação novo a partir da entrada que você duplicou - nada é salvo até você clicar em **Criar**, e a entrada de origem fica inalterada de um jeito ou de outro.
- **Os favores não são gerenciados aqui.** Itens, Locais e Rituais dividem este catálogo; um favor é registrado, quitado e guardado só no [Registro de Favores](boon-ledger.md).
- **O texto só para Narradores é invisível para todos os outros.** Qualquer texto `[ST]...[/ST]` num campo de descrição ou limitações nunca chega a um jogador, na lista do catálogo nem em qualquer outro lugar onde apareça - você sempre o vê por inteiro aqui, já que só um Narrador abre esta página.
- **A mesma entrada pode ser conectada a muitos personagens ao mesmo tempo** - conectar um item a cinquenta personagens são cinquenta conexões comuns à mesma linha, não cinquenta cópias. Quando a cópia de um personagem precisar ser única (uma herança, algo que se danifica ou é renomeado), use **Copiar para um personagem** em vez disso.
- **Copiar para um personagem é sempre restrito ao detentor, e sempre mantém o próprio arquivo.** O **Quem pode ver isto** da cópia começa como **Somente personagens que correspondem às regras que eu defini**, seja qual for a configuração da própria origem, e qualquer arquivo anexado à origem é duplicado e não compartilhado - editar ou remover o arquivo da cópia nunca toca o da origem.
- **Ainda não existe um botão "Marcar um uso".** Gastar um uso deveria ser algo que um jogador faz no próprio item que tem, mas nenhuma superfície para o jogador foi construída - hoje o único jeito de reduzir **Usos Restantes** é redigitá-lo à mão em **Editar**, o que é registrado como uma edição comum (**Ajustado** no **Histórico** do item), não como um uso registrado de verdade.
- Numa tela estreita esta lista do catálogo se empilha em cartões em vez de rolar para o lado.

## Solução de problemas

- **"Falha ao carregar objetos do mundo."** Atualize e tente de novo.
- **"Falha ao carregar o catálogo do livro. Tente novamente."** Mostrado dentro do seletor do livro quando o catálogo não pode ser buscado - tente de novo.
- **"Nenhum item/local/ritual corresponde a estes filtros."** Limpe a caixa de busca.
- **"Falha ao salvar este objeto do mundo."** Confira se **Nome** está preenchido e tente de novo.
- **"Falha ao carregar este item. Tente novamente."** Mostrado no lugar do formulário de editar ou duplicar quando a entrada não pôde ser buscada - recarregue a página.
- **"Falha ao enviar este arquivo."** Só imagens (JPEG, PNG, GIF, WebP) e PDFs são permitidos, 10 MB no máximo.
- **"Este item já atingiu o número máximo de arquivos que pode ter."** Um item aceita exatamente um arquivo - remova-o primeiro para anexar outro.
- **"Mova ou exclua primeiro o que está dentro deste local."** Mostrado ao excluir um local que tem outro local aninhado dentro - mova o aninhado para fora (defina o **Dentro de** dele como outra coisa, ou como nenhum) ou exclua-o primeiro.
- **"parent_id deve ser um local real neste jogo."** A escolha de **Dentro de** não resolveu para um local real - atualize e tente de novo.
- **"Falha ao copiar este item."** Tente de novo. Só um item pode ser copiado assim - um local ou ritual nunca mostra o botão.
- **"Falha ao transferir este item."** Tente de novo - confira se um destinatário está escolhido, a menos que **Perdido** esteja escolhido.
- **Não vejo este item de menu de jeito nenhum.** Ele exige um papel de Narrador em pelo menos uma crônica, ou uma conta de administrador.

## Relacionados

- [Conexões](connections.md)
- [Quem é Quem](whos-who.md)
- [Segredos](secrets.md)
- [Registro de Favores](boon-ledger.md)
- [Relatórios](reports.md)
- [Ferramenta de Consulta](query-tool.md)
- [Importar](import.md)
- [Assistência de Escrita por IA](writing-assist.md)
- [Conteúdo Só para Narradores](storyteller-only.md)
- [Guia do Administrador - Quem Pode Ver uma Trama, Item ou Local](../admin-guide.md#quem-pode-ver-uma-trama-item-ou-local)
- [Guia do Administrador](../admin-guide.md#o-menu-do-wp-admin)
