# Blocos de Esquema

As peças reutilizáveis de que a ficha de um personagem é feita - uma lista de traços, um conjunto de poderes em níveis, uma reserva de recurso ou um grupo de campos de identidade. Editar um bloco muda toda ficha montada a partir dele, em toda crônica que não fez a própria cópia.

## Quem pode usar

Todo bloco do catálogo compartilhado - o livro - é somente leitura aqui, para todos, administrador do site inclusive: vê-lo sem crônica escolhida mostra a mesma tela com todo campo desativado e um aviso que explica por quê. Uma regra da casa ou um traço caseiro é montado do mesmo jeito que qualquer outra mudança no catálogo próprio de uma crônica: restrito a essa crônica, pela Configuração da Crônica.

Os Narradores (HST e AST) chegam a esta tela já restrita à própria crônica, normalmente seguindo o link Personalização do catálogo da [Configuração da Crônica](chronicle-setup.md). Dali podem adicionar um bloco novinho que pertence só à crônica deles, ou editar um bloco compartilhado - o que faz a cópia própria da crônica no instante em que salvam. A cópia mantém o que a crônica mudou e segue o bloco compartilhado em todo o resto.

Um Condutor de Trama, a Harpia de uma crônica e um jogador nunca veem esta tela.

## Como chegar lá

- Administrador do site, vendo o livro: barra lateral do wp-admin → Beyond Elysium → Configuração do Sistema → aba Blocos de Esquema.
- Narrador, editando o catálogo da própria crônica: barra lateral do wp-admin → Beyond Elysium → Configuração da Crônica, escolha a sua crônica, ache **Personalização do catálogo** e clique em **Ir**. Isso abre a mesma aba já restrita à sua crônica.

## A tela

- O filtro **Tipo de seção** - Todos, ou um dos quatro tipos que uma seção pode ser: `trait_list` (uma lista de traços), `tiered_power` (poderes em níveis), `resource_pool` ou `identity_field`.
- **Mostrar blocos do sistema (N)** - uma caixa de seleção; desmarcá-la esconde os blocos que vêm com o próprio Beyond Elysium, deixando só os personalizados.
- Um aviso que nomeia a qual crônica você está restrito, quando uma está escolhida; sem nenhuma escolhida, um aviso explicando que o livro é somente leitura aqui e onde mudá-lo para uma crônica.
- Uma tabela: **Nome**, **Slug**, **Tipo de Seção**, **Sistema?** (Sim/Não), **Ações**.
  - **Editar** (ou **Ver**, sem crônica escolhida) abre o bloco abaixo.
  - **Excluir** - só oferecido para um bloco personalizado de uma crônica; nunca para um bloco do sistema e, sem crônica escolhida, de jeito nenhum.
- **+ Novo Bloco de Esquema** - só com uma crônica escolhida. Abre um formulário em branco para um bloco que pertence só a essa crônica.

### O formulário de criação/edição

- **Nome**.
- **Slug** - só na criação; permanente depois de definido.
- **Tipo de Seção** - trait_list, tiered_power, resource_pool ou identity_field. Mudar isto num bloco existente limpa o que está abaixo de volta à forma vazia desse tipo.
- **Somente Narrador** - esconde esta seção inteira, e todo valor que um personagem tem nela, de todo jogador: na ficha, no editor e em qualquer exportação ou impressão.
- O editor de definição que combina com o Tipo de Seção que você escolheu:
  - **Lista de traços** (Qualidades, Antecedentes, Habilidades e afins) - as opções globais **Permitir múltiplas seleções**, **Permitir entradas personalizadas**, **Os jogadores definem sua própria ordem** (as entradas mantidas aparecem e se reordenam na ordem que o jogador definiu, achatadas - sem agrupamento), **Ordenar alfabeticamente**, **Lista negativa (estilo defeito)** (as entradas devolvem XP em vez de custá-lo), **Atômico** (adicionar a mesma entrada de novo acrescenta uma nova em vez de subir a contagem), **Máximo por item** e uma chave **Custo por pré-requisito** (abaixo). Uma tabela de itens: **Nome**, **Custo**, **Categoria**, um botão **Descrição**, uma lista suspensa **Aprovação**, um campo **Motivo**, um botão **Aprovação por valor**, uma substituição **Permitir múltiplos** (padrão do bloco / sempre / nunca - só mostrada depois que "Permitir múltiplas seleções" está ligada acima) e **Remover**. Ligar **Custo por pré-requisito** troca um custo fixo por um derivado do nível de outro bloco e acrescenta um botão **Pré-requisitos** a cada item para as entradas específicas de que ele precisa - usado para um rote ou um rito cujo preço segue a Esfera ou o Posto que ele exige.
  - **Poder em níveis** (Disciplinas, Dons, Esferas e afins) - um painel de **Configurações globais**: uma opção **Sequencial** (ter um nível implica ter todo nível abaixo dele), uma caixa **Magia de sangue** que acrescenta uma lista **Tradições** válida para o bloco todo, uma opção **Os jogadores definem sua própria ordem** (os poderes mantidos aparecem e se reordenam na ordem que o jogador definiu, achatados - sem agrupamento) e uma tabela **Níveis e custos** - uma linha por nível nomeado (Básico, Intermediário, Avançado, Ancião e assim por diante, para um bloco que os tem) que dá o **Custo** desse nível, o **Modificador dentro do tipo**, o **Modificador fora do tipo** e os **Degraus na escada** (quantos níveis numerados pertencem a ele). Uma chave **Esta é uma trilha sem níveis** troca a tabela de níveis por uma única taxa fixa de **XP por nível**, ou por um seletor **Derivado de um bloco** para um custo que segue o nível de outro bloco (pareado com os Pré-requisitos de cada poder, igual a uma lista de traços acima). Abaixo das configurações globais, uma entrada por poder: o nome, uma **Substituição de aprovação** para o poder inteiro, um botão **Descrição** e (para magia de sangue) uma **Restrição** e a sua própria lista de tradições de oferenda; depois uma tabela de níveis - **Nível** (em branco quer dizer Ancião ou superior), **Nível**, **Nome do poder**, **Custo**, **Aprovação**, **Motivo de aprovação**, um botão **Descrição** e **Remover**.
  - **Reserva de recurso** (Força de Vontade, Sangue, Gnosis e afins) - uma tabela de reservas: **Nome** (com uma consulta de nome entre blocos opcional embaixo), **Tipo de valor**, **Início padrão**, **Mín**, **Máx**, **Passo**, **Custo por ponto**, **Pontos gratuitos**, uma caixa **Progressivo (cada ponto custa o valor do seu próprio nível)**, uma caixa **Redução paga (precificado ao diminuir, não ao aumentar)** (Progressivo e Redução paga são mutuamente exclusivos - marcar um limpa o outro), um botão **Aprovação por valor** e **Remover**.
  - **Campo de identidade** (Clã, Natureza, Geração e afins) - uma tabela de campos: **Nome**, **Tipo** (texto, seleção, seleção múltipla, número, área de texto), **Obrigatório**, **Opções** (separadas por vírgula, só para seleção e seleção múltipla), um botão **Aprovação por opção** e **Remover**.
  - Os botões **Descrição**, **Aprovação por valor**, **Aprovação por opção** e **Pré-requisitos** abrem, cada um, um pequeno editor modal - veja [Descrições e Cronogramas de Aprovação do Catálogo](schema-block-notes.md).
- **Mostrar/Ocultar avançado (JSON bruto)** - a mesma definição como texto simples. **Aplicar JSON** confere se é válido antes de substituir o que está acima; um JSON inválido nunca é aplicado.
- **Salvar** / **Cancelar** - com uma crônica escolhida. Sem nenhuma escolhida, todo campo acima fica desativado e a única ação é **Fechar**.

## Tarefas comuns

### Adicionar um item novo a uma lista de traços

1. Abra Blocos de Esquema pelo link Personalização do catálogo da [Configuração da Crônica](chronicle-setup.md), para ficar restrito a uma crônica - um administrador do site que vê o livro direto não pode editar nada aqui.
2. Clique em **Editar** no bloco.
3. Clique em **+ Adicionar item**.
4. Digite o **Nome**, o **Custo** e a **Categoria** dele.
5. Clique em **Salvar**.

### Esconder uma seção dos jogadores por completo

1. Edite o bloco.
2. Marque **Somente Narrador - ocultar esta seção e seus dados dos jogadores**.
3. Clique em **Salvar**.

### Criar uma variante só da crônica de um bloco compartilhado

1. Abra a [Configuração da Crônica](chronicle-setup.md) da sua crônica.
2. Ache **Personalização do catálogo** e clique em **Ir**.
3. Clique em **Editar** no bloco que você quer mudar, ou em **+ Novo Bloco de Esquema** para algo novinho.
4. Faça as suas mudanças.
5. Clique em **Salvar**. Isso cria ou atualiza a cópia própria da sua crônica; o bloco compartilhado fica intocado.

### Excluir um bloco personalizado

1. Ache-o na tabela - nunca possível para um bloco com **Sistema = Sim**.
2. Clique em **Excluir**.
3. Leia o aviso sobre o que o referencia e confirme.

## O que saber

- **Dois catálogos, uma tela - mas só um deles é editável aqui.** Sem crônica escolhida, você está olhando o livro que toda crônica sem cópia própria compartilha, somente leitura para todos, administrador do site inclusive. Com uma crônica escolhida, você edita só a cópia própria dessa crônica - a sua primeira edição a cria, e ela então deixa de seguir qualquer mudança posterior no original compartilhado.
- **Ver a aba não é o mesmo que poder usá-la aqui.** Qualquer conta com capacidade de Narrador pode abrir esta tela, mas ler ou mudar as cópias próprias de uma crônica específica ainda exige um papel de Narrador de verdade nessa crônica.
- **Um bloco do sistema nunca pode ser excluído**, em nenhum dos catálogos.
- **O que você adiciona a um bloco compartilhado do sistema sobrevive a uma atualização.** Um item, poder ou campo novo que uma crônica adiciona à cópia própria de um bloco do sistema é mantido na próxima vez que o Beyond Elysium atualiza o catálogo. O que essa atualização sobrescreve são os dados de catálogo da própria linha que vem com o plugin - nome, custo e afins; uma atualização do livro num valor que a sua crônica também mudou aparece como uma [correção do livro](chronicle-setup.md#a-tela) a resolver, nunca uma sobrescrita silenciosa.
- **A cópia própria de uma crônica mantém as suas mudanças e pega o resto.** O que a sua crônica mudou - um custo, uma regra de aprovação, um item que adicionou ou removeu - fica como você deixou. Tudo o que ela nunca tocou segue o bloco compartilhado pelas atualizações do próprio Beyond Elysium.
- **As escadas de poderes numerados normalmente vêm Sequenciais.** Com isso marcado, o Custo de cada nível é só o preço daquele degrau - comprar do zero até o nível 3 cobra os níveis 1, 2 e 3 juntos, não só o nível 3.
- **Aprovação, Motivo, Aprovação por valor e Aprovação por opção aqui são exatamente os mesmos dados** que a tela de [Regras de Aprovação](approval-rules.md) gerencia - mude um e veja refletido no outro.
- **Os modificadores Dentro do tipo e Fora do tipo de um nível de um poder em níveis só se aplicam onde uma seção de [Pilha de Criatura](creature-stacks.md) nomeia um teste Dentro do tipo.** Sem teste declarado, tudo nessa seção é dentro do tipo e o modificador nunca dispara - os dois são declarados em lugares diferentes de propósito, já que o mesmo bloco pode ser dentro do tipo para a seção de um tipo de criatura e sem teste para a de outro.
- **Progressivo e Redução paga descrevem duas formas de precificar diferentes, não duas intensidades da mesma coisa.** Progressivo precifica cada ponto pela taxa do degrau dele (uma reserva que cresce custa mais por ponto conforme sobe); Redução paga precifica uma *redução* a partir do valor inicial da reserva, a forma de que uma reserva estilo Defeito definida por Narrador precisa. Marcar uma sempre limpa a outra.
- **Custo por pré-requisito e Derivado de um bloco fazem a mesma coisa numa lista de traços e num poder em níveis respectivamente** - o preço segue o nível de uma entrada em outro bloco, nomeada por item pelo botão Pré-requisitos, em vez de um número fixo digitado aqui.
- **Os jogadores definem sua própria ordem é por crônica, como toda outra opção aqui.** Ligá-la na cópia própria da sua crônica de um bloco nunca muda a cópia de outra crônica, nem o livro. As entradas mantidas de um personagem nesse bloco aparecem e se reordenam achatadas, na ordem que o jogador deixou, sem agrupamento - uma crônica que a desliga volta à exibição agrupada normal. Veja [Editor de Personagem: Reordenando uma Lista](character-editor.md#reordenando-uma-lista) para o que um jogador vê.

## Solução de problemas

- **Não vejo Editar, Excluir nem + Novo Bloco de Esquema, só Ver.** Você chegou aqui sem crônica escolhida - o livro é somente leitura para todos, administrador do site inclusive. Passe pelo link Personalização do catálogo da Configuração da Crônica.
- **Toda crônica que tento mostra um erro de permissão.** Você pode ver esta aba sem ser Narrador em lugar nenhum - cada crônica ainda confere se você de fato tem um papel de Narrador nela.
- **Não vejo esta aba de jeito nenhum.** A Configuração do Sistema exige uma conta de administrador do WordPress, ou um papel de Narrador em alguma crônica.
- **"Já existe um bloco de esquema com este slug."** Os slugs são únicos na instalação inteira, blocos compartilhados e de crônicas igualmente - escolha outro.
- **A minha edição de JSON bruto não foi aplicada.** Não era um JSON válido - o erro aparece acima da caixa; corrija e clique em **Aplicar JSON** de novo.
- **Editei o item de um bloco do sistema e a minha mudança sumiu depois de uma atualização.** Uma nova semeadura atualiza os dados de catálogo que já estão no catálogo do sistema; só o que você adicionou de novo sobrevive intocado. Bifurque o bloco para a sua crônica se precisa que a mudança dure - e se o valor do próprio livro realmente mudou, procure-o como uma [correção do livro](chronicle-setup.md#a-tela) na Configuração da Crônica e não como uma edição perdida.

## Relacionados

- [Descrições e Cronogramas de Aprovação do Catálogo](schema-block-notes.md)
- [Pilhas de Criatura](creature-stacks.md)
- [Modelos](templates.md)
- [Regras de Aprovação](approval-rules.md)
- [Configuração da Crônica](chronicle-setup.md)
- [Jogos](games.md)
- [Conteúdo Só para Narradores](storyteller-only.md)
- [Como Funciona a Aprovação](approval-flow.md)
- [Guia do Administrador: Blocos de Esquema e Pilhas de Criatura](../admin-guide.md#blocos-de-esquema-e-pilhas-de-criatura)
- [Guia do Administrador: Descrições e Cronogramas de Aprovação](../admin-guide.md#descrições-e-cronogramas-de-aprovação-em-itens-do-catálogo)
