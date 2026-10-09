# Conexões

Liga duas coisas - um personagem a uma trama, um item ou local a quem o tem, ou um contato de fora sem ficha própria. A mesma ferramenta cuida de tudo, onde quer que você veja uma seção **Conexões** ou um botão **Conectar personagem**.

## Quem pode usar

Os Narradores (HST e AST) adicionam, editam e removem conexões onde quer que esta ferramenta apareça - as conexões de uma trama, as conexões de um item ou local com personagens e a seção Conexões própria de um personagem na ficha dele. Um Condutor de Trama, que de resto trabalha em Tramas e Rumores igual a um Narrador, pode abrir este mesmo painel a partir de uma trama e ver as conexões atuais, mas adicionar ou remover uma ali é recusado - mais restrito que o resto do acesso dele a Tramas e Rumores. A Harpia da crônica e um jogador comum nunca veem este painel.

## Como chegar lá

- Kit de Ferramentas do Narrador → Tramas e Rumores → abra uma trama → **Conectar personagem** na barra de ações dela.
- Beyond Elysium → Itens e Locais (wp-admin) → abra um item ou local → a seção **Conexões** dele.
- Minha Crônica → aba Ficha → a seção **Conexões** de um personagem - só aparece ali para um Narrador.

## A tela

- Uma lista suspensa para o tipo de coisa a que conectar: **Personagem nesta crônica**, **Externo (somente nome)**, **Trama**, **Objeto do mundo**, **Etiqueta**.
- Conforme essa escolha: uma caixa **Buscar personagens…**, **Buscar tramas…** ou **Buscar objetos do mundo…** que restringe a lista enquanto você digita, uma caixa de texto simples para um nome externo, ou nada extra para uma Etiqueta pura. Os personagens são todos os desta crônica, NPCs inclusive e marcados "(NPC)"; um nome que dois deles compartilham traz o número de cada um, como "(#12)".
- **Rótulo** - uma etiqueta curta de texto livre para o relacionamento (por exemplo "irmã" ou "envolvido em"). Desativado para uma conexão Externa, já que o nome que você digitou já serve de rótulo.
- Uma segunda caixa de texto para **Notas** (opcional).
- **Adicionar conexão** - desativado até você escolher ou digitar um alvo válido.
- Abaixo do formulário, toda conexão existente desta entidade: o nome da outra ponta, um selo nomeando o tipo (a menos que seja uma entrada Externa), o rótulo da conexão se tem um, as notas se houver, e um botão **Remover**.
- "Nenhuma conexão ainda." quando a lista está vazia; "Carregando…" enquanto busca.

## Tarefas comuns

### Conectar um personagem a uma trama

1. Abra a trama (Kit de Ferramentas do Narrador → Tramas e Rumores).
2. Clique em **Conectar personagem** na barra de ações dela.
3. Deixe a lista suspensa em **Personagem nesta crônica**, digite parte do nome do personagem e escolha-o na lista.
4. Se quiser, adicione um **Rótulo** e **Notas**.
5. Clique em **Adicionar conexão**.

### Conectar um item ou local ao personagem que o tem

1. Abra o item ou local (Beyond Elysium → Itens e Locais).
2. Na seção **Conexões** dele, deixe a lista suspensa em **Personagem nesta crônica**.
3. Escolha o personagem e, se quiser, adicione um **Rótulo** (por exemplo "carregado por") ou **Notas**.
4. Clique em **Adicionar conexão**.

### Registrar um contato de fora sem ficha de personagem

1. Abra o painel de onde quer que se aplique.
2. Defina a lista suspensa como **Externo (somente nome)**.
3. Digite o nome da pessoa.
4. Clique em **Adicionar conexão**. O nome que você digitou vira ao mesmo tempo o rótulo da conexão e o nome de exibição dela na lista.

### Remover uma conexão

1. Ache-a na lista.
2. Clique em **Remover**.

## O que saber

- **Esta única ferramenta cobre cinco tipos de ligação**, não só "conectar um personagem" - a mesma lista suspensa também liga a outra trama, a um objeto do mundo, a uma etiqueta livre ou a um nome de fora sem registro no sistema.
- **Um Condutor de Trama pode olhar mas não tocar, numa trama.** Abrir Conectar personagem a partir de uma trama mostra o formulário e a lista atual, mas Adicionar conexão e Remover falham os dois para um Condutor de Trama - só um Narrador pode de fato mudar as conexões de uma trama.
- **Remover é imediato** - não há etapa de confirmação nem desfazer. Você teria de adicioná-la de novo do zero.
- **A lista de Conexões de um personagem pode incluir um favor de que ele participa**, mostrado como um objeto do mundo com o nome do favor, rotulado `owed_by` ou `owed_to`. Removê-lo aqui quebra o registro desse favor no Registro de Favores em vez de marcá-lo como pago - use o controle **Marcar como pago** do próprio registro para um favor.
- **O mesmo item ou local pode ser conectado a muitos personagens ao mesmo tempo** - é compartilhado, não copiado. Se a cópia de um personagem precisar ser única depois (uma herança, algo que se danifica ou é renomeado), duplique o item na tela Itens e Locais e conecte essa cópia.

## Solução de problemas

- **"Falha ao carregar conexões."** Atualize e tente de novo.
- **"Falha ao criar esta conexão."** Na maioria das vezes é um Condutor de Trama tentando adicionar uma numa trama, o que é só para Narradores - confira quem está autenticado. Também pode querer dizer que o alvo não existe mais.
- **"Falha ao remover esta conexão."** Tente de novo, ou peça a um Narrador que a remova.
- **Adicionar conexão não clica.** Escolha ou digite um alvo primeiro - Externo exige um nome digitado, todo o resto exige uma seleção.
- **Um favor sumiu do Registro de Favores.** Alguém removeu uma das duas conexões dele da seção Conexões de um personagem. Isso não pode ser reparado daqui - registre o favor de novo.
- **Não vejo esta seção de jeito nenhum.** Ela é só para Narradores - um Condutor de Trama, a Harpia da crônica e um jogador nunca a veem.

## Relacionados

- [Tramas e Rumores](plot-manager.md)
- [Ficha de Personagem](character-sheet.md)
- [Registro de Favores](boon-ledger.md)
- [Papéis](roles.md)
- [Conteúdo Só para Narradores](storyteller-only.md)
- [Guia do Narrador](../st-guide.md#12-relatórios-cartões-e-saída-em-lote)
