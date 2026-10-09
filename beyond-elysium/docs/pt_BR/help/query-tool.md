# Ferramenta de Consulta

Monte uma busca filtrada nos personagens, itens, locais ou rituais de uma crônica, veja as correspondências, rode estatísticas sobre elas e salve uma consulta para reutilizar depois.

## Quem pode usar

Só os Narradores (HST e AST). A Ferramenta de Consulta lê fichas inteiras de personagens - os blocos só para Narradores inclusive - então um Condutor de Trama não tem a capacidade de que esta ferramenta precisa, embora os Condutores de Trama trabalhem em outras partes do Kit de Ferramentas do Narrador. Chegar a **Beyond Elysium → Ferramenta de Consulta** no wp-admin, afinal, exige a mesma capacidade de todo o site que uma conta de HST, AST ou administrador já carrega; a conta de um Condutor de Trama ainda pode conseguir abrir a página, mas todo pedido que ela faz - carregar campos, executar uma consulta, carregar consultas salvas - falha. Um jogador nunca vê este item de menu.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Ferramenta de Consulta → aba **Ferramenta de Consulta**. Os relatórios ficam na mesma página - veja [Relatórios](reports.md).

## A tela

- **Jogo** - uma lista suspensa com toda crônica da instalação.
- Três abas: **Buscar**, **Estatísticas**, **Consultas Salvas**.
- Uma faixa de inventário abaixo das abas: **Personagens**, **Itens**, **Locais**, **Rituais**. Trocar de inventário limpa as cláusulas, os resultados e as estatísticas que estavam na tela - uma cláusula montada contra os campos de um inventário não tem sentido contra os de outro.

### Aba Buscar

- **Buscar campos para restringir os seletores abaixo…** - filtra pelo nome a lista suspensa de campos de cada cláusula.
- **Corresponder TODAS as cláusulas (E)** / **Corresponder QUALQUER cláusula (OU)** - a lógica que une todas as cláusulas.
- Uma linha por cláusula: uma lista suspensa de campo, uma lista suspensa de operador (restringida ao que o tipo desse campo suporta), uma caixa de valor (um nome, um número ou uma data, conforme o campo e o operador), uma caixa **NÃO** e uma descrição em linguagem simples do que a cláusula quer dizer. **✕** remove uma cláusula; **+ Adicionar cláusula** adiciona outra.
- **Executar Consulta** - executa a busca. Desativado até existir pelo menos uma cláusula.
- **Salvar como…** - uma caixa de nome e **Salvar** para salvar o inventário, a lógica e as cláusulas atuais como uma consulta com nome.
- Resultados abaixo: uma tabela ordenável (as colunas dependem do inventário - Personagens mostra Nome, Tipo, Status; Itens mostra Nome, Tipo de Item, Nível; Locais mostra Nome, Tipo de Local, Nível; Rituais mostra Nome, Nível, Duração), uma coluna **Motivo da Correspondência** que explica por que cada linha casou, **Exportar CSV** e a paginação **Anterior** / **Próximo**.
- Só num resultado de **Personagens**, depois que você marcou uma ou mais linhas: um formulário de concessão em lote (quantidade de XP, um motivo obrigatório, **Conceder XP aos selecionados**), um formulário de redefinição de reserva (escolha um bloco de reserva de recurso e a reserva, **Redefinir temporário para permanente**) e um formulário de status (escolha um status, **Definir status para os selecionados**).

### Aba Estatísticas

- Uma lista suspensa de campo, uma lista suspensa de tipo de estatística (**Distribuição**, **Distribuição de Traços Distintos**, **Distribuição de Traço Específico**, **Máximas**, **Somas**), uma caixa de nome de traço (só Distribuição de Traço Específico), uma caixa **Incluir zero/nenhum** e **Executar**.
- O resultado: um total e o tamanho do maior grupo, depois uma barra por grupo dimensionada pelo maior. Clicar numa barra a expande para listar os nomes dos personagens por trás dela.

### Aba Consultas Salvas

- Toda consulta salva para esta crônica, cada uma mostrando o nome e qual inventário busca. Uma consulta salva automaticamente como a sua própria busca mais recente mostra **(automático)** - só há uma dessas por pessoa, por crônica.
- **Carregar** - troca para a aba Buscar com o inventário, a lógica e as cláusulas dessa consulta restaurados.
- **Renomear** - um pedido simples de um novo nome. Não é oferecido numa entrada automática de busca recente.
- **Excluir** - remove-a.

## Tarefas comuns

### Montar e executar uma consulta

1. Abra Beyond Elysium → Ferramenta de Consulta e escolha a crônica.
2. Escolha um inventário na faixa abaixo das abas.
3. Clique em **+ Adicionar cláusula** e escolha um campo, um operador e um valor. Adicione mais cláusulas se precisar e escolha **Corresponder TODAS as cláusulas (E)** ou **Corresponder QUALQUER cláusula (OU)**.
4. Clique em **Executar Consulta**.

### Salvar uma consulta para depois

1. Monte e execute uma consulta como acima.
2. Digite um nome em **Salvar como…**.
3. Clique em **Salvar**.

### Reutilizar uma consulta salva

1. Abra a aba **Consultas Salvas**.
2. Clique em **Carregar** na que você quer.

### Executar uma estatística

1. Abra a aba **Estatísticas**.
2. Escolha um campo e um tipo de estatística.
3. Clique em **Executar**.
4. Clique numa barra para ver os nomes por trás desse grupo.

### Conceder XP, redefinir uma reserva ou definir o status de um grupo de personagens

1. Execute uma consulta de **Personagens** que devolva o grupo que você quer.
2. Marque a linha de cada personagem.
3. Preencha o formulário de concessão em lote, redefinição de reserva ou status que aparece abaixo dos resultados e clique no botão dele.

## O que saber

- **Esta ferramenta é de um Narrador.** Tudo o que ela devolve - os valores guardados de um bloco só para Narradores inclusive - é filtrado para quem não é Narrador desta crônica antes de uma única cláusula ser avaliada, e um NPC também nunca aparece nos resultados para mais ninguém. Na prática isso só importa se um pedido de algum modo chega ao motor fora da conferência normal de permissão, já que a própria página é só para Narradores.
- **Trocar de inventário recomeça.** Condições, resultados e qualquer resultado de estatística são limpos no instante em que você escolhe outro inventário - nada passa de um para outro por acidente.
- **As ações em lote funcionam só em resultados de Personagens.** As linhas de Itens, Locais e Rituais não são personagens, então conceder XP, redefinir uma reserva ou definir um status nunca aparece para elas.
- **Um ID de personagem ruim numa seleção é pulado, nunca adivinhado.** Se o personagem de uma linha não existe mais, ou pertence a outra crônica, é relatado como falha em vez de tocado ou descartado em silêncio.
- **Exportar CSV cobre só a página que você está olhando**, não o conjunto inteiro de resultados - passe pelas páginas e exporte cada uma se precisar de tudo.
- **Tempers é um campo do inventário de Itens**, que guarda os requisitos de espírito ligado de um fetiche ou talen (por exemplo, o custo de Gnosis dele). Uma cláusula como `Tempers contains Gnosis` acha todo item com um temper de Gnosis, seja qual for a contagem.
- **Uma consulta salva com nome é compartilhada** - todo Narrador da crônica pode carregá-la, mas só o criador dela ou um Narrador pode renomeá-la ou excluí-la. A sua entrada de "busca mais recente" é só sua; ninguém mais a vê na própria lista de Consultas Salvas.
- Este é um trabalho de mesa, melhor num teclado, mas os resultados ainda se leem num celular - quando não há espaço para as colunas, cada resultado vira um cartão em vez de rolar para o lado.

## Solução de problemas

- **"Falha ao carregar a lista de campos. Tente atualizar a página."** Atualize a página.
- **"Falha ao carregar consultas salvas."** Atualize e tente de novo.
- **Executar Consulta não clica.** Adicione pelo menos uma cláusula primeiro.
- **Uma consulta mostra um erro em vez de resultados.** A mensagem diz o que o servidor recusou - um campo que o inventário escolhido não tem, digamos, ou uma cláusula sem valor. "Falha ao executar esta consulta." quer dizer que o próprio pedido falhou; tente de novo.
- **Vejo a página mas toda ação falha.** Você provavelmente não tem um papel de Narrador (HST ou AST) na crônica que escolheu - a conta de um Condutor de Trama às vezes ainda abre esta página, mas a Ferramenta de Consulta em si exige posição de Narrador. Confira a crônica que você escolheu, ou pergunte a um HST/AST.
- **Não vejo este item de menu de jeito nenhum.** Ele exige um papel de Narrador em pelo menos uma crônica, ou uma conta de administrador.

## Relacionados

- [Relatórios](reports.md)
- [Itens e Locais](world-objects.md)
- [Papéis](roles.md)
- [Conteúdo Só para Narradores](storyteller-only.md)
- [Guia do Narrador](../st-guide.md#10-montando-e-executando-consultas)
