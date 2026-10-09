# Referência da API REST

Todas as rotas ficam sob o namespace `be/v1` - por exemplo `/wp-json/be/v1/games`. Toda rota de escrita exige um usuário autenticado do WordPress; a permissão de cada rota é imposta no servidor, seja o que for que qualquer interface de cliente mostre ou esconda.

`{game_slug}` restringe uma rota a uma crônica. Um pedido que nomeia um slug de jogo com o qual quem pede não tem nenhuma relação devolve `404` (não `403`), então a existência de uma crônica nunca vaza para quem está fora dela.

Enquanto uma atualização move um site para as listas próprias de cada tipo de criatura (alguns segundos), toda escrita numa rota daqui é respondida com `503 catalog_switch_in_progress` e as leituras não são afetadas. Uma execução que morreu segurando a trava deixa de recusar depois de dois minutos. Veja [Movendo um Site Antigo para as Listas por Tipo de Criatura](admin-guide.md#movendo-um-site-antigo-para-as-listas-por-tipo-de-criatura).

**Esta referência é mantida à mão contra os controladores, não gerada.** Depois de qualquer mudança num controlador, confira-a contra o método `register_routes()` desse controlador em `includes/REST/`. Um controlador sem seção aqui quer dizer que este documento está atrasado, não que o controlador não exista.

## Autorização

Toda rota abaixo é protegida por exatamente uma capacidade do WordPress (ou, onde indicado, qualquer uma ou todas de várias), conferida com escopo de crônica: `Authorization::check_request()` tenta primeiro o accessSchema quando ligado e alcançável e depois recua para a capacidade simples de quem pede mais a linha dessa pessoa em `be_game_members` para essa crônica. Veja o [Guia do Narrador](st-guide.md#1-criando-um-jogo) para o que cada papel costuma significar.

## Jogos

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/games` | `be_view_characters` | Lista *todo* jogo da instalação - correto para um seletor da equipe, nunca seguro para sustentar um seletor de crônica do front-end (veja `/my/games` abaixo). As senhas das contas de uma crônica de demonstração nunca são devolvidas |
| POST | `/games` | `be_manage_games` | Cria um jogo: `name` e, opcionalmente, `slug`, `game_type`, `description`, `settings`, `asc_role_path` e `notifications_enabled` |
| GET | `/games/{slug}` | `be_view_characters` | Obtém um jogo. As senhas das contas de uma crônica de demonstração nunca são devolvidas |
| PUT | `/games/{slug}` | `be_manage_games` | Atualiza nome, slug, descrição, configurações, `asc_role_path`, `notifications_enabled`. `settings` se mescla ao que está guardado, chave por chave; `settings.enabled_factions` se mescla um nível adiante, pilha por pilha e campo por campo, então uma escrita nomeia só os campos que muda (uma lista vazia levanta a restrição desse campo). `settings.demo` é a única rota que pode algum dia escrevê-lo - `{on, reset_hours (1/3/6/12/24, padrão 6), accounts: {storyteller, player}}`, os dois ids de conta sendo usuários reais deste site que não são administradores quando `on` é verdadeiro, ou `400 invalid_param`; a mesma conferência roda quando `POST /games` traz `settings.demo`. O `accounts_password` de uma demonstração nunca é escrito por esta rota nem por `POST /games`, e uma redefinição nunca define a senha de uma conta de administrador. Ligá-lo ou desligá-lo agenda ou desagenda a redefinição da própria crônica |
| DELETE | `/games/{slug}` | `be_manage_games` | Exclui uma crônica. Uma que ainda guarda conteúdo (personagens, tramas, objetos do mundo, os modelos próprios dela, bifurcações de bloco de esquema ou camadas de tipo de criatura, consultas salvas com nome, códigos de verificação, transferências) é recusada com `409 chronicle_has_content` e `data.counts`, a menos que `?with_content=1` - que exclui tudo, filiações inclusive, numa só transação. Nada nunca é deixado sob o slug. `POST /games` recusa um slug explícito que ainda guarda o conteúdo de uma crônica excluída (`409 slug_has_orphaned_content`), e uma renomeação para um é recusada (`409 orphan_collision`). Marcada como demonstração, tanto a exclusão quanto uma mudança de slug são recusadas de cara com `403 demo_locked` até a marcação ser desligada |
| GET | `/games/{slug}/content` | `be_manage_games` | Conta o que excluir a crônica excluiria junto (`characters`, `plots`, `world_objects`, `templates`, `schema_blocks`, `creature_stacks`, `saved_queries`, `attestations`, `transfers`) - a tela Jogos as nomeia na sua única confirmação de exclusão |
| POST | `/games/{slug}/demo/reset` | `be_manage_games` | Redefine uma crônica de demonstração ao conteúdo declarado agora em vez de esperar a agenda. `400 not_a_demo` quando a marcação não está ligada; `500 reset_failed` quando as duas contas dela já não são usuários reais |
| GET | `/{game_slug}/demo` | `be_view_characters` | `{on, reset_hours, next_reset, last_reset: {at, counts} \| null}` - se esta crônica é uma demonstração, a cadência dela, quando se redefine em seguida (`wp_next_scheduled()`) e quando foi redefinida por último |
| GET | `/my/games` | `be_view_characters` | Só as crônicas em que quem pede de fato tem uma linha de `be_game_members`, cada uma com o papel que tem ali, se está marcada como demonstração e se a crônica está ligada ao accessSchema (`{slug, name, role, demo, asc_linked}`; `asc_linked` é verdadeiro quando o site lê o accessSchema e a crônica nomeia um `asc_role_path`), mais, quando o accessSchema está ligado, cada crônica cujo `asc_role_path` concede um papel a quem pede, com o papel mais alto que tem ali - a fonte de dados de verdade do seletor de crônica de Minha Crônica / Kit de Ferramentas do Narrador |
| GET | `/{game_slug}/my/capabilities` | autenticado (qualquer) | O que quem pede de fato pode fazer *nesta única crônica* - `be_manage_characters`, `be_manage_plots`, `be_manage_schemas`, `be_manage_connections`, `be_manage_boons`, cada uma resolvida pelo mesmo `Authorization::check_request()` com escopo de crônica que toda rota de escrita usa, não pelo retrato de todo o site que toda carga de página leva. Um `game_slug` que não resolve ou quem pede sem relação com esta crônica ainda devolve `200` com toda opção `false`, nunca um erro - um seletor renderiza "sem acesso aqui" em vez de falhar |
| GET | `/branding` | `be_manage_games` | O padrão de cor de destaque de marca de todo o site, `{accent_color}` (uma cor hexadecimal, ou vazio para nenhuma) |
| PUT | `/branding` | `be_manage_games` | Define-o: `accent_color`, uma cor hexadecimal como `#1a1a1a`, ou uma string vazia para limpar. `400 invalid_param` para qualquer outra coisa |

## Blocos de Esquema

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/schema-blocks` | `be_view_characters` | Lista os blocos |
| POST | `/schema-blocks` | `be_manage_games` | Recusado com `403 book_read_only`: o livro é somente leitura; uma crônica cria os próprios blocos na rota dela abaixo |
| GET | `/schema-blocks/{slug}` | `be_view_characters` | Obtém um bloco; `?game_slug=` substitui pela bifurcação própria da crônica se ela tem uma |
| PUT | `/schema-blocks/{slug}` | `be_manage_games` | Recusado com `403 book_read_only`; um pedido que nomeia `?game_slug=` é `400 use_chronicle_route` |
| DELETE | `/schema-blocks/{slug}` | `be_manage_games` | Recusado com `403 book_read_only` |
| POST | `/{game_slug}/schema-blocks` | `be_manage_schemas` | Cria um bloco próprio desta crônica, sem livro por baixo. O slug dele precisa ser um que nenhum bloco usa |
| PUT | `/{game_slug}/schema-blocks/{slug}` | `be_manage_schemas` | Atualiza o bloco próprio desta crônica, ou a cópia dela do bloco do livro, fazendo a cópia na primeira edição; o que mudou é gravado por cima dos valores do livro, então uma correção do livro posterior ao lado chega. Qualquer objeto `description` (`{reference, description, source}`, cada um em HTML) num item/poder/nível é sanitizado no servidor - formatação/listas/tabelas sobrevivem, imagens e scripts não - seja o que for que quem pede enviar. `500 save_failed` quando o salvamento não pegou - nem a mudança nem uma cópia nova é mantida |
| DELETE | `/{game_slug}/schema-blocks/{slug}` | `be_manage_schemas` | Exclui o bloco próprio desta crônica ou a cópia dela do bloco do livro, voltando ao livro |

A entrada `definition` de um item, nível/família de poder em níveis, reserva de recurso ou campo de identidade também pode levar um cronograma de aprovação além da sua `approval` fixa: `approval_by_value` (itens de trait_list e reservas de resource_pool - uma matriz de intervalos `{from, to, approval, reason?}` resolvidos contra o valor resultante, o cronograma de uma reserva conferido só contra a classificação permanente dela), uma `approval` simples num nível de tiered_power (cada nível já é a sua própria linha) ou `approval_by_option` (identity_field - `{optionValue: {approval, reason?}}`, todo valor de uma seleção múltipla conferido, vale o mais rígido). Veja o [Guia do Administrador](admin-guide.md#aprovação-por-valor-e-aprovação-por-opção) para a interface de edição.

## Regras de Aprovação

A superfície de edição dos cronogramas descritos logo acima - uma regra por item/poder/nível/intervalo de valores/opção do catálogo que carrega uma substituição de aprovação ou um motivo, nos blocos trait_list, tiered_power, resource_pool e identity_field próprios desta crônica. O `target_type` de uma regra é um entre `item`, `item_range`, `power`, `level`, `pool_range` ou `field_option`; `item_range`/`pool_range` endereçam uma entrada `{from, to}` na matriz `approval_by_value` do próprio alvo (os dois campos obrigatórios ao criar/atualizar), `field_option` endereça uma opção no mapa `approval_by_option` do campo alvo (`option` obrigatório) e `level` endereça o nível de um poder pelo número `level`. O formato de resposta/lista de uma regra leva isso como `extra` (`[from, to]`, a string da opção ou `null`) ao lado do campo `level` existente.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/approval-rules` | `be_manage_approval_rules` | Lista toda regra definida no momento, a bifurcação própria desta crônica onde existe uma, o bloco global em caso contrário |
| POST | `/{game_slug}/approval-rules` | `be_manage_approval_rules` | Define (cria ou sobrescreve) uma regra num item do catálogo, intervalo de valores de item, poder, nível de poder, intervalo de valores de reserva, opção de campo de identidade, um bloco inteiro (`block_default`) ou a divisão dentro do tipo/fora do tipo de um bloco de poder em níveis (`block_in_type`, exige `in_type` e `out_of_type`; `400 no_in_type_test` quando nenhum tipo de criatura desta crônica declara um teste dentro do tipo para o bloco) - bifurca o bloco para esta crônica se ainda não bifurcado. `500 save_failed` quando a regra não salvou - nada é mantido, uma cópia nova inclusive |
| GET | `/{game_slug}/approval-rules/options` | `be_manage_approval_rules` | O vocabulário fixo que o formulário de criar/editar oferece: níveis de aprovação reais e predefinições de nível de motivo |
| GET | `/{game_slug}/approval-rules/default` | `be_manage_approval_rules` | A Política de Aprovação Padrão da crônica, a chave de remoção/redução e a chave dos Estatutos de Personagem da OWBN: `{ auto_approve, approval_on_removal, owbn_bylaws }` |
| PUT | `/{game_slug}/approval-rules/default` | `be_manage_approval_rules` | Define qualquer uma ou combinação: `{ auto_approve?: true \| false, approval_on_removal?: true \| false, owbn_bylaws?: true \| false }` - pelo menos uma é obrigatória. Toda outra configuração da crônica é mantida |
| PUT | `/{game_slug}/approval-rules/{id}` | `be_manage_approval_rules` | Atualiza uma regra. `500 save_failed` quando não salvou |
| DELETE | `/{game_slug}/approval-rules/{id}` | `be_manage_approval_rules` | Limpa uma regra, de volta a nenhuma substituição. `500 save_failed` quando não salvou |
| GET | `/{game_slug}/bylaws` | `be_manage_approval_rules` | A lista de referência dos Estatutos de Personagem da OWBN: toda regra conhecida de Estatuto de Personagem, cada uma com as suas `attachments` (`{clause_id, family, name, levels?, picks?, count_range?}` - `levels`/`picks` restringem uma ligação de `tiered_power` ao nível ou escolha de Ancião ou superior que ela nomeia, `count_range` (`{from, to}`) restringe uma ligação de trait_list à nova contagem sendo comprada, a mesma forma que `approval_by_value` usa) e um `link` para a cláusula de verdade no council.owbn.net. Filtros: `search` (casamento de trecho em `subject`), `tier` (casamento de trecho em `pc` ou `npc`), `attached` (`true`/`false`). Paginada como qualquer rota de lista, `per_page` até 2000 - a lista de referência deve continuar legível por inteiro |
| POST | `/{game_slug}/bylaws/refresh` | `be_manage_approval_rules` | Puxa de novo, ao vivo, toda cláusula do Estatuto de Personagem do council.owbn.net e grava o resultado como uma opção do site que vence o arquivo que vem com o plugin daí em diante. Uma cláusula ainda ativa mantém a ligação existente; uma que o council removeu perde o motivo; uma cláusula nova aparece sem ligação. Devolve `{ rule_count, attachment_count, added, removed, changed, generated_at }`. `502` numa falha de rede ou de análise - nada muda |
| POST | `/bylaws/upload` | `be_manage_games` | De todo o site, não por crônica. Envia um arquivo de estatutos já montado (a forma que `tools/bylaws/build.php` grava) como a substituição do site, para um site que não alcança o council.owbn.net direto. `multipart/form-data`, campo `file`. `400 bylaws_malformed` num arquivo sem `rules`/`attachments` ou numa regra sem `clause_id`/`path`/`subject` - nada muda |

**Política de Aprovação Padrão.** A linha de base da própria crônica - usada só quando nada acima (um item, um poder, um nível, um intervalo de valores, uma opção de campo ou o próprio `approval_rules.default` do bloco dono) resolveu um nível, afinal - é o booleano simples `settings.auto_approve` no próprio jogo (`false`/ausente: tudo precisa de revisão `st` por padrão; `true`: tudo é `auto` por padrão), lido e gravado por `/{game_slug}/approval-rules/default`, para os Narradores que gerenciam as regras poderem defini-lo. Uma regra granular sempre vence este padrão nos dois sentidos, tanto na lógica de resolução `resolve_approval_level()` quanto pela construção da própria mesclagem.

**A chave de remoção/redução.** `settings.approval_on_removal` (ausente/`false` é desligada), definida pela mesma rota, substitui toda regra e o padrão igualmente: depois de ligada, `Change_Engine::catches_removal_rule()` classifica uma mudança proposta a partir da própria ficha mantida do personagem - nunca do que o cliente envia - como uma remoção (`remove_trait`), uma contagem, nível ou valor permanente de reserva reduzido, ou um novo rótulo/troca de nome (um `modify_trait` cujo nome, especialização ou escolha de poder difere do que é mantido), inclusive um `add_trait` forjado que na verdade nomeia uma linha já mantida a uma contagem ou nível menor. Uma mudança pega sempre espera, com o motivo "Parte de uma mudança que remove, reduz ou renomeia algo.", e enviada por `/changes/submit` junto de irmãs, todas elas esperam junto sob o mesmo `submission_id`, seja o que for que a regra de cada uma diria de outro modo.

## Correções do Livro

Uma mudança que uma crônica fez a um bloco do catálogo, a um tipo de criatura ou a um modelo de ficha é gravada com o valor do livro por baixo. Quando o livro depois muda esse valor, a mudança é uma correção a revisar: derivada em toda leitura, nunca guardada, e nada move o XP de ninguém.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/catalog-corrections` | `be_manage_schemas` | `{ corrections, count }`. Cada correção tem `kind` (`block`, `stack` ou `template`), `target` (o slug do bloco ou tipo de criatura, ou o id do modelo), `target_name`, `path` (passos para dentro do documento: uma chave, ou uma lista de um item que nomeia uma entrada), `labels` (o nome de cada passo de entrada, nulo para outros passos) e `was`, `now` e `yours`, cada um com uma opção `_set`. Quando o livro removeu a entrada em que a mudança está, `removed` é verdadeiro e `changes` lista toda mudança dentro dela |
| POST | `/{game_slug}/catalog-corrections/keep` | `be_manage_schemas` | `{ kind, target, path }`: mantém o valor da crônica, gravando o valor do livro agora por baixo; numa entrada removida, a entrada passa a ser da própria crônica. `{ all: true }` mantém toda correção. Responde com a lista como está agora. `400 invalid_param` para um caminho malformado, `404 not_found` quando a crônica não tem tal camada, `500 save_failed` |
| POST | `/{game_slug}/catalog-corrections/take` | `be_manage_schemas` | `{ kind, target, path }`: adota o valor do livro, descartando a mudança da crônica ali e toda mudança dentro dele. Só para a frente: nenhum XP se move. Responde com a lista como está agora |

## Variantes do Livro

Um bloco base pode ter variantes no livro: edições que uma edição ou pacote acrescenta a ele, ou uma que o substitui por inteiro. Uma crônica escolhe as próprias; a cópia dela do bloco base é o livro com elas aplicadas, depois as mudanças próprias dela, e os personagens continuam mantendo entradas sob o slug do bloco base.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/catalog-variants` | `be_manage_schemas` | `{ bases }`: cada bloco base com variantes, pelo nome, com `base`, `base_name`, `variants` (`id`, `label`, `mode`: `add` ou `replace`) e `chosen`, os ids que esta crônica escolheu |
| POST | `/{game_slug}/catalog-variants/preview` | `be_manage_schemas` | `{ base, variants }`: as entradas que os personagens da crônica mantêm no bloco base que casam com ele agora e deixariam de casar com essas variantes escolhidas, cada uma com `character_id`, `character`, `name` e `power_name`. Uma entrada mantida casa pelo nome ou apelido; uma personalizada sempre casa. Não muda nada |
| PUT | `/{game_slug}/catalog-variants` | `be_manage_schemas` | `{ base, variants }`: escolhe-as, a substituta primeiro, depois cada aditiva em ordem, e reconstrói a cópia da crônica do bloco base; uma cópia que fica sem variante e sem mudança própria é removida. `400 unknown_base`, `unknown_variant` ou `one_replacement` quando duas substituem; `500 save_failed`. Responde com `{ bases }` |

## Pilhas de Criatura

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/creature-stacks` | `be_view_characters` | Lista as pilhas do livro. Com `?game_slug=`, as pilhas como essa crônica as tem: a camada dela no lugar de cada uma que mudou, os tipos de criatura próprios dela sem nenhum equivalente no livro e só os tipos de criatura que ela permite; um tipo só para Narradores (`stack_definition.storyteller_only`, Various) é listado para quem tem `be_manage_characters` em toda crônica e para mais ninguém |
| POST | `/creature-stacks` | `be_manage_games` | Recusado com `403 book_read_only`: os tipos de criatura do livro são somente leitura |
| GET | `/creature-stacks/{slug}` | `be_view_characters` | Obtém uma pilha, resolvida (blocos montados) com `?resolve=true`. Acrescente `?game_slug=` para a pilha como essa crônica a tem: a camada dela (uma seção que ocultou ainda listada, com `hidden: true`, e qualquer seção própria), o tipo de criatura próprio de uma crônica direto e as cópias próprias dela dos blocos. Acrescente `&for_creation=true` para também deixar de fora as seções ocultas e os blocos que só elas mostram, e restringir as opções de campos de identidade à restrição `enabled_factions` dessa crônica - só o seletor de criação de personagem; nunca aplicado ao ver ou editar um personagem existente. Acrescente `&character_id=` para também montar os blocos que esse personagem mantém além das seções da própria pilha, num tipo que permite qualquer bloco (`stack_definition.any_block`, Various); lido só por quem tem `be_manage_characters` ou pelo jogador do próprio personagem |
| PUT | `/creature-stacks/{slug}` | `be_manage_games` | Recusado com `403 book_read_only` |
| DELETE | `/creature-stacks/{slug}` | `be_manage_games` | Recusado com `403 book_read_only` |
| POST | `/{game_slug}/creature-stacks` | `be_manage_schemas` | Constrói um tipo de criatura novinho da própria crônica a partir de um `slug`, um `name`, um `game_line` opcional, pelo menos uma entrada de `sections` (`block_slug`, `label`/`display_order`/`required` opcionais) e `creation_rules` opcional. Recusado com `409 duplicate_slug` quando o slug já está em uso em qualquer lugar, livro ou crônica; `400 unknown_block` quando uma seção nomeia um bloco que a crônica não pode ler |
| PUT | `/{game_slug}/creature-stacks/{slug}` | `be_manage_schemas` | Salva `stack_definition` e/ou `creation_rules`, por inteiro, para a camada de uma crônica sobre um tipo do livro, ou direto para o tipo de criatura próprio de uma crônica |
| DELETE | `/{game_slug}/creature-stacks/{slug}` | `be_manage_schemas` | Remove a camada de uma crônica sobre um tipo do livro, para ele voltar a ser lido como o do livro. Para o tipo de criatura próprio de uma crônica (sem equivalente no livro), exclui-o de vez; recusado com `409 creature_stack_in_use` (com `count`) enquanto algum personagem em qualquer lugar ainda o tem |
| POST | `/{game_slug}/creature-stacks/{slug}/sections` | `be_manage_schemas` | Adiciona uma seção para um bloco que a crônica pode ler, a uma camada ou ao tipo de criatura próprio de uma crônica; `400` quando o bloco é desconhecido ou já está na pilha |
| PUT | `/{game_slug}/creature-stacks/{slug}/sections/{block_slug}` | `be_manage_schemas` | Oculta ou mostra uma seção (`hidden`) |
| DELETE | `/{game_slug}/creature-stacks/{slug}/sections/{block_slug}` | `be_manage_schemas` | Remove uma seção que a crônica adicionou; recusado com `400 book_section` para uma que o próprio livro declara |

## Personagens

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/characters` | `be_view_characters` | Lista, com filtros da lista |
| POST | `/{game_slug}/characters` | `be_edit_own_characters` | Cria - isento de bootstrap: um jogador sem relação prévia com a crônica ainda pode criar aqui o primeiro personagem dele. Também cria a trama própria do personagem, `<Personagem> [id] Plot`, ligada a ele como seu ator (só o jogador dele e os Narradores da crônica a veem); um personagem cuja trama não pode ser gravada não é criado (`500 create_failed`). Um personagem novo que tem algo numa seção que a crônica ocultou é recusado com `400 section_hidden`, e as entradas iniciais de uma seção oculta são deixadas de fora. Um recém-chegado sem pedido de entrada esperando é recusado com `403 join_requests_off` quando a crônica tem os pedidos de entrada desligados |
| GET | `/{game_slug}/my/characters` | `be_view_characters` | Só os personagens de quem pede, texto só para Narradores retirado igual a todo outro caminho de leitura de quem não é gerente |
| GET | `/{game_slug}/characters/{id}` | `be_view_characters` | Um personagem; quem não é gerente só pode ver o próprio. `sheet_warnings` lista, só para quem tem `be_manage_characters`, cada caminho que a ficha mantém sob uma tradição escrita de duas formas (`block_slug`, `name`, `traditions`, `levels`); fica vazio para todos os outros |
| PUT | `/{game_slug}/characters/{id}` | `be_edit_own_characters` | Atualiza. Quem não é gerente é recusado numa cópia mantida atualizada de uma anfitriã enquanto a visita ainda está aberta (`403 kept_current_elsewhere`, nomeando a crônica de origem de verdade) e de novo depois que ela termina (`403 visit_ended`), até outra visita reabri-la - um gerente nunca é bloqueado, já que as mudanças de um Narrador da anfitriã são encaminhadas à origem na aprovação em vez disso |
| DELETE | `/{game_slug}/characters/{id}` | `be_manage_characters` | Exclui, em cascata as mudanças/instantâneos/estilo de ficha dele |
| GET | `/{game_slug}/characters/statuses` | `be_manage_characters` | O vocabulário fixo de status (`active`, `inactive`, `retired`, `dead`, `pending`) - alimenta o seletor de status em lote em vez de codificar a lista uma segunda vez no cliente |
| POST | `/{game_slug}/characters/bulk-status` | `be_manage_characters` | Define o mesmo status num lote de personagens. Devolve um resultado por personagem (`{results: [{id, success, error?}], updated}`), nunca tudo ou nada - um id ruim ou alheio no lote falha só essa entrada |
| POST | `/{game_slug}/characters/{id}/pool-purchases` | `be_manage_characters` | Concede um item de trait_list, `{block_slug, name}`, pago pela reserva de recurso que o `_meta.paid_from` desse bloco nomeia (por exemplo um Espinho de Wraith do XP de Sombra da própria Sombra) em vez do XP do próprio personagem. Recusa `400 not_pool_funded` quando o bloco não declara nenhuma, `400 unknown_item` para um nome que não está no catálogo dele, `400 insufficient_balance` quando a reserva não pode cobri-lo; grava uma mudança `pool_spend` aprovada em caso de sucesso |
| GET | `/{game_slug}/characters/{id}/hooks` | `be_manage_characters` | Toda trama a que este personagem está ligado, dividida em `{open, resolved}` pelo status da própria trama, cada uma `{plot_id, title, latest_entry_date}`. Exclui a trama própria do personagem (a conexão automática de ator que todo personagem ganha na criação) |
| GET | `/wp-users` | `be_manage_games` | Não restrito a um jogo - a busca de um administrador do site por contas do WordPress por nome de exibição, e-mail ou login |
| GET | `/{game_slug}/wp-users` | `be_manage_characters` | A busca de contas de um Narrador da crônica, para atribuir um jogador a um personagem ou adicionar um jogador: `search` exige pelo menos três letras de um nome, e um endereço de e-mail só volta quando a busca é esse endereço exato. Num multisite busca toda conta da rede, não só deste site |
| PUT | `/{game_slug}/characters/{id}/order/{block_slug}` | `be_manage_characters` OU `be_edit_own_characters` | Salva uma nova ordem para as entradas que um personagem mantém num bloco cujo `player_order` está ligado (`400 not_player_order` em caso contrário). Corpo: `order`, uma lista das posições atuais das entradas mantidas na nova ordem, e `names`, os nomes das entradas na mesma ordem. Um jogador pode fazê-lo só para o próprio personagem (`403 ownership_denied`). Nada é precificado nem revisado. `409 sheet_changed` quando a lista já não combina com o que foi carregado; `400 invalid_order` para um par malformado. Responde `{order}`, as entradas como salvas |

## Quem é Quem (perfis públicos de NPCs e de personagens de jogadores)

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/npcs` | `be_view_characters` | Todo NPC cujo perfil do Quem é Quem quem vê pode ver - um gerente vê todos. `{id, name, public_description, image_url, titles, factions}`, nunca a ficha |
| GET | `/{game_slug}/npcs/{id}` | `be_view_characters` | O perfil de um NPC, mesma forma. `404` para um não-gerente que o público do perfil não alcança, ou para um id real que não é um NPC |
| GET | `/{game_slug}/profiles` | `be_view_characters` | Todo perfil de NPC e de personagem de jogador que quem vê pode ver, cada um com a forma de `/npcs` mais `kind` (`npc`/`pc`), `played_by` (o nome de exibição do jogador, só quando esse personagem tem `profile_show_player` definido - senão `null`) e `portrait_attachment_id` (um id para `GET /{game_slug}/attachments/{id}`, quando o personagem enviou um pela rota de anexos; `null` em caso contrário) |
| PUT | `/{game_slug}/characters/{id}/profile` | `be_edit_own_characters` | Atualiza os campos de perfil público de um personagem. Um gerente pode definir `public_name`, `public_description`, `public_image_id`, `profile_audience` (qualquer um entre `everyone`/`storytellers`/`restricted`) e `profile_audience_rules`. Quem não é gerente e edita o próprio personagem pode definir `public_name`, `public_description`, `profile_audience` (`everyone` ou `storytellers` - `400 audience_not_allowed` para `restricted`) e `profile_show_player`; `public_image_id` vindo de quem não é gerente é recusado com `403 image_not_allowed`, e `profile_audience_rules` é ignorado. `403 ownership_denied` para um personagem que não é do próprio e que não é gerenciável |

## Mudanças

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/characters/{character_id}/changes` | `be_view_characters` | O histórico de mudanças de um personagem. Quem não é gerente nunca vê um `visit_note` ou `visit_pairing` de uma crônica anfitriã, e o total da página também os deixa de fora |
| POST | `/{game_slug}/characters/{character_id}/changes` | `be_edit_own_characters` | Envia uma mudança - aprova automaticamente e aplica na hora se as regras de aprovação do bloco permitirem. Quem não é gerente é recusado do mesmo jeito que `PUT /{game_slug}/characters/{id}` numa cópia própria de uma anfitriã mantida atualizada em outro lugar ou recém-encerrada (`403 kept_current_elsewhere`/`403 visit_ended`); o envio de um gerente passa, encaminhado à origem na aprovação em vez disso (veja as linhas de encaminhamento da anfitriã acima). Uma compra sem preço de catálogo (produção caseira) é guardada com `change_data.cost_pending: true` e `xp_cost` 0 e espera um Narrador precificá-la; nunca se aprova sozinha, e o `chosen_cost` do próprio jogador numa produção caseira é descartado. Uma compra numa seção que a crônica ocultou (uma entrada adicionada, ou uma contagem, nível ou classificação permanente maior) é recusada com `400 section_hidden`; reduzir e remover continuam, toda leitura do que é mantido ali também, e uma mudança enviada antes de a seção ser ocultada ainda pode ser aprovada. O `chosen_cost` de um Narrador num traço personalizado o precifica no envio, de 0 a 500: por ponto numa lista contada em pontos, uma vez numa lista de compras únicas (`atomic`). Um `modify_trait` ou `remove_trait` num poder em níveis alcança a linha mantida com esse nome na tradição que `trait.tradition` (ou, num modify, `previous.tradition`) nomeia, escrita como guardada; sem tradição nomeada ele alcança toda linha com esse nome, como antes. Uma tradição num add ou modify é guardada na grafia do catálogo, e uma que o catálogo não lista é recusada com `400 unknown_tradition` a menos que a linha sendo modificada já esteja guardada exatamente sob essa tradição; um modify que reescreveria uma linha para um caminho e tradição que outra linha já tem é recusado com `400 already_held`. Uma mudança que nomeia uma seção que o tipo de criatura do personagem não lista é recusada com `400 unknown_block`, exceto num tipo que permite qualquer bloco (Various): ali um Narrador pode nomear qualquer seção, e qualquer outra pessoa só uma que o personagem já tem. `xp_earn`/`xp_adjust` têm regras próprias para quem não é gerente - veja [Experiência](#experiência) abaixo. Com `settings.approval_on_removal` ligada e esta mudança em si uma remoção, uma classificação menor, um novo rótulo ou uma troca de nome, ela espera um Narrador por conta própria, com o motivo "Parte de uma mudança que remove, reduz ou renomeia algo." - nunca pelo que o cliente afirma, decidida a partir da ficha mantida do próprio personagem. Um poder `_meta.spent_from` (Edges de Caçador) sempre custa 0 XP e é recusado com `400 not_enough_unspent` sem pontos não gastos suficientes na reserva nomeada dele, `400 rank_not_unlocked` sem o próximo posto abaixo desse mesmo caminho já mantido, ou `400 creed_restricted` para uma família que o credo do próprio personagem não pode comprar de jeito nenhum; uma reserva `_meta.raised_by` (as Virtudes de Caçador) sempre custa 0 XP e é recusada com `400 not_enough_temporary` sem pontos temporários suficientes na reserva de que converte |
| POST | `/{game_slug}/characters/{character_id}/changes/submit` | `be_edit_own_characters` | Envia um conjunto inteiro de mudanças juntas, `{ changes: [...] }` (cada uma na forma do corpo da própria rota de mudança única), numa só transação sob um `submission_id` compartilhado. Quem não é gerente é recusado numa cópia própria de uma anfitriã mantida atualizada em outro lugar ou recém-encerrada, como na rota de mudança única (`403 kept_current_elsewhere`/`403 visit_ended`). Com `settings.approval_on_removal` ligada, qualquer mudança do conjunto que é uma remoção, uma classificação menor, um novo rótulo ou uma troca de nome faz toda mudança do conjunto esperar junto, com o motivo compartilhado. Uma falha de validação ou de preço em qualquer uma mudança recusa o conjunto inteiro - `400`/`403`, nada enviado. Devolve `{ submission_id, changes: [...] }` |
| GET | `/{game_slug}/changes` | `be_manage_characters` | A fila de aprovação - toda mudança pendente (ou filtrada) em toda a crônica. Filtros: `status`, `change_type`, `character_id` e `approval_level` (`auto` ou `st`), que pagina e totaliza só esse nível. Uma mudança que espera um preço também traz `cost_units`, `{ per: "dot" or "pick", units, negative }`: o que um preço cobre - os novos pontos de uma lista de traços, uma escolha para um poder - para um cliente poder mostrar o total enquanto é digitado. Cada linha também traz `submission_id` - duas ou mais mudanças pendentes que o compartilham foram enviadas juntas |
| POST | `/{game_slug}/changes/batch-approve` | `be_manage_characters` | Aprova várias mudanças numa só chamada. Devolve `approved`, `skipped` (faltando, já revisada, editada desde então, ou não permitida), `needs_cost` (mudanças esperando um preço) e `needs_secret_choice` (mudanças `log_knowledge`, que precisam de entrada por linha - veja [Segredos](#segredos)): nenhuma delas é jamais aprovada num lote, e são nomeadas aqui em vez disso. Dois ou mais dos ids dados que compartilham um `submission_id` aprovam juntos, numa só transação, reembolsos e reduções primeiro, depois o resto - qualquer uma delas falhando pula o grupo inteiro; todo o resto da chamada ainda é aprovado independentemente |
| GET | `/{game_slug}/my/changes` | `be_view_characters` | Só as mudanças pendentes próprias de quem pede, em todo personagem que possui, nesta única crônica. Quem não é gerente nunca vê um `visit_note` ou `visit_pairing` |
| GET | `/my/changes` | `be_view_characters` | As mudanças próprias de quem pede em toda crônica do site em que tem um personagem: tudo o que está pendente, mais tudo o que foi revisado nos últimos 30 dias, o envio mais novo primeiro. Cada linha traz `description` (linguagem simples, o mesmo `Change_Description::describe()` que a fila de aprovação usa), `display_status` (`pending`, `approved`, `auto_approved` ou `refused`) e `game_slug`/`game_name`. Um `visit_note` ou `visit_pairing` nunca é listado. Registrada antes de `/{game_slug}/changes` para uma crônica literalmente com o slug `my` não poder escondê-la |
| POST | `/{game_slug}/characters/{character_id}/preview-changes` | `be_edit_own_characters` | Precifica um conjunto de mudanças propostas sem enviá-las. Cada resultado tem `xp_cost`, `priced` e `unpriced_reason` (`custom_no_catalog_entry`): `priced: false` quer dizer que ainda não existe preço e um Narrador o define na aprovação, então o `0` não é um preço e não move `running_xp_unspent`. Uma mudança que falha na validação de verdade traz em vez disso `invalid: true` e um `error` (`{code, message}`) - `xp_cost`/`priced` não carregam significado real nessa linha, já que a mudança seria recusada num envio de verdade, não precificada |
| PUT | `/{game_slug}/changes/{id}` | `be_manage_characters` | Aprova ou rejeita; envia ao jogador que enviou uma notificação por e-mail, a menos que ele ou a crônica tenha optado por sair. Aprovar uma mudança com `cost_pending` exige `xp_cost`, um número inteiro de 0 a 500 - por ponto numa lista de traços contada em pontos, o valor inteiro para uma compra única (uma lista `atomic`) ou um poder: sem ele a resposta é 400 `cost_required`, e um valor fora dessa faixa é 400 `invalid_param`. O preço é gravado no traço, o total é deduzido e `cost_pending` é limpo. `xp_cost` não é lido para nenhuma outra mudança. Aprovar uma mudança `log_knowledge` exige `secret_id` ou `entity_type`/`entity_id` - veja [Segredos](#segredos). Aprovar uma para uma cópia visitante com uma visita aberta e combinada de manter atualizado a encaminha ao lar de verdade do personagem em vez de aplicá-la - a linha diz `forwarded`, nunca `approved`. Aprovar um `visit_note` - uma nota que uma crônica anfitriã compartilhou sobre a própria cópia visitante - não tem efeito na ficha: cai só para Narradores na trama própria do personagem; recusar um não registra nada |

## Instantâneos

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/characters/{character_id}/snapshots` | `be_manage_characters` | Lista os instantâneos salvos de um personagem |
| POST | `/{game_slug}/characters/{character_id}/snapshots` | `be_manage_characters` | Cria um manualmente (também criado automaticamente a cada 25 mudanças aprovadas) |
| GET | `/{game_slug}/characters/{character_id}/snapshots/{id}` | `be_manage_characters` | O estado completo da ficha de um instantâneo |

## Estilo da Ficha

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/characters/{character_id}/sheet-style` | `be_view_characters` | Obtém a personalização da ficha de um personagem |
| PUT | `/{game_slug}/characters/{character_id}/sheet-style` | `be_customize_sheet` | Atualiza - uma capacidade à parte de `be_manage_characters`, que pode ser concedida por usuário |
| DELETE | `/{game_slug}/characters/{character_id}/sheet-style` | `be_customize_sheet` | Redefine para os padrões |

## Operações em Lote (ações do conjunto de resultados da própria Ferramenta de Consulta)

Três ações em lote que um Narrador pode executar contra os resultados selecionados de uma consulta, todas na mesma forma: escolha linhas na Ferramenta de Consulta e aplique uma ação à seleção inteira de uma vez em vez de um personagem por vez.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| POST | `/{game_slug}/experience/bulk-award` | `be_manage_characters` | Concede o mesmo valor de XP a vários personagens de uma vez. Uma cópia visitante com uma visita aberta e combinada de manter atualizado é encaminhada ao lar de verdade dela em vez disso - nada muda aqui, e a linha de mudança do próprio prêmio diz `forwarded` |
| POST | `/{game_slug}/resource-pools/bulk-reset` | `be_manage_characters` | Redefine a classificação temporária de uma reserva de recurso nomeada de volta à permanente, numa seleção - o reabastecimento de Força de Vontade/Sangue de fim de sessão, para um grupo inteiro de uma vez. Um personagem que não tem a reserva nomeada, ou que pertence a outra crônica, é pulado em silêncio, não tratado como erro |
| POST | `/{game_slug}/characters/bulk-status` | `be_manage_characters` | Veja Personagens, acima - listado aqui também por ser a mesma forma de ação em lote da Ferramenta de Consulta |

## Experiência

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| POST | `/{game_slug}/experience/apply` | `be_manage_characters` | Aplica um valor diferente, com sinal, a cada um de vários personagens numa só chamada: `{ reason, awards: [ { character_id, amount } ] }`, `amount` um número inteiro de -10.000 a 10.000, nunca zero. Cada linha vale por si - recusada com `not_in_chronicle` para um personagem fora desta crônica, `below_zero` para um valor negativo maior que o XP Ganho do próprio personagem - e a resposta lista toda linha em ordem com o seu resultado e, depois de aplicada, os totais novos. Um valor positivo grava uma mudança `xp_earn`, um negativo `xp_adjust`, as duas aprovadas na hora - a menos que o personagem seja uma cópia visitante com uma visita aberta e combinada de manter atualizado, que encaminha o prêmio ao lar de verdade dela (`applied: true, forwarded: true`, nada mudado aqui) |

O `xp_earn` de um jogador enviado por `POST /{game_slug}/characters/{character_id}/changes` (acima) é um pedido, não um prêmio direto: `change_data` precisa de `request: { where, date?, note? }` (`where` obrigatório, até 200 caracteres; `date` uma data real não posterior a hoje; `note` até 2.000 caracteres), e o servidor monta `change_data.reason` ele mesmo como `"Requested: {where}"` ou `"Requested: {where}, {date}"`. Sempre espera um Narrador. `xp_adjust` por essa mesma rota é recusado com `invalid_change_type` para quem não tem `be_manage_characters` nesta crônica - só um Narrador pode enviar um, com a forma `{ amount, reason? }` inalterada.

## Campos de Consulta

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/query-fields` | `be_view_characters` | O catálogo de campos que o construtor de consultas oferece |

## Modelos

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/templates` | `be_manage_templates` | Lista os modelos globais (base), para o editor de modelos - uma ficha recebe o layout dela de `resolve` |
| POST | `/templates` | `be_manage_games` | Recusado com `403 book_read_only`: os modelos do livro são somente leitura |
| GET | `/templates/{id}` | `be_manage_templates` | Um modelo global; o modelo próprio de uma crônica é `404` aqui e listado na própria rota dele |
| PUT | `/templates/{id}` | `be_manage_games` | Recusado com `403 book_read_only` |
| DELETE | `/templates/{id}` | `be_manage_games` | Recusado com `403 book_read_only` |
| GET | `/{game_slug}/templates/resolve` | `be_view_characters` | Resolve o layout efetivo de uma pilha de criatura nesta crônica (a bifurcação do jogo se existe, senão o modelo global) |
| GET | `/{game_slug}/templates` | `be_manage_templates` | Lista as bifurcações de modelo próprias desta crônica |
| POST | `/{game_slug}/templates` | `be_manage_templates` | Faz um modelo para esta crônica. Um do mesmo tipo de criatura e espécie de um modelo do livro grava como próprio tudo em que difere dele, o que deixa de fora inclusive, e uma mudança posterior do livro ao lado dele o alcança |
| PUT | `/{game_slug}/templates/{id}` | `be_manage_templates` | Atualiza o modelo próprio de uma crônica, gravando o que mudou por cima do modelo do livro do mesmo tipo de criatura e espécie |
| DELETE | `/{game_slug}/templates/{id}` | `be_manage_templates` | Exclui a bifurcação própria de uma crônica |

## Tramas

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/plots` | `be_view_characters` | Lista tramas. `character_plots=only` mantém a trama própria de cada personagem e suas rodadas de ação (tramas ligadas a um personagem por `apr_actor`); `exclude` mantém toda outra trama; qualquer outra coisa é `400` |
| POST | `/{game_slug}/plots` | `be_submit_actions` OU `be_manage_plots` | Cria - um jogador pode começar um fio de trama por uma ação, um Narrador pode criar um direto. A trama de quem não é gerente é sempre uma trama de jogador própria dele (1.1.0 §2.3a): exige `character_id` (precisa ser o próprio de quem pede, `403` em caso contrário), `audience: restricted` forçado, ligada por uma conexão `plot_owner`. A trama de um gerente tem como padrão `audience` `storytellers` (nunca alcançável por jogadores) e pode definir `audience`/`audience_rules` explicitamente. O `is_rumor: true` de um Narrador salva a trama e a etiqueta de rumor dela juntas ou nenhuma - `500 create_failed` quando nada foi mantido |
| GET | `/{game_slug}/my/plots` | `be_view_characters` | Só as tramas próprias de quem pede - dele por conexão, ou alcançáveis por `target_query` |
| POST | `/{game_slug}/plots/allocate-actions` | `be_manage_plots` | Aloca os espaços de ação de uma rodada. Com `commit`, a trama da data e toda entrada de ação são salvas juntas ou nenhuma - `500 allocation_failed` quando nada foi mantido. A trama da data fica sob a trama própria do personagem a menos que `parent_plot_id` nomeie outra |
| POST | `/{game_slug}/plots/generate-rumors` | `be_manage_plots` | Gera os rumores padrão de uma data: uma prévia, ou com `commit`, salvos, e cada jogador correspondente recebe um e-mail uma vez. Uma confirmação mantém todo rumor ou nenhum - `500 generate_failed`, e ninguém recebe e-mail - e duas confirmações na mesma crônica se revezam, então a segunda acha os rumores da primeira e os pula |
| GET | `/{game_slug}/plots/{id}` | `be_view_characters` | Uma trama com o seu fio de entradas, conexões, anexos (nunca `stored_name`) e `is_owner` (verdadeiro só para o jogador dono desta trama, falso para todos os demais, um gerente inclusive). Escondida pelo próprio `audience`/`audience_rules` (1.1.0 §2.1) ou pela alocação de ação de outro personagem é `404` a menos que você gerencie tramas, e nunca listada entre as filhas de uma trama |
| PUT | `/{game_slug}/plots/{id}` | `be_manage_plots` | Atualiza, `audience` inclusive (`everyone`/`storytellers`/`restricted`) e `audience_rules` (`{conditions, logic}`, obrigatório não vazio quando `audience` é `restricted`) - `400 invalid_param` para um valor inválido ou uma matriz `conditions` vazia |
| DELETE | `/{game_slug}/plots/{id}` | `be_manage_plots` | Exclui, em cascata as entradas, conexões e anexos dela (linhas e arquivos). A trama própria de um personagem é recusada (`409 character_plot`) - vai embora quando o personagem é excluído |
| GET | `/{game_slug}/plots/{id}/member-candidates` | `be_submit_actions` OU `be_manage_plots` | Só o dono da trama de um jogador ou um gerente (senão `403 ownership_denied`): personagens ativos que não são NPC e ainda não estão conectados, só nome e id (1.1.0 §2.3a) |
| POST | `/{game_slug}/plots/{id}/members` | `be_submit_actions` OU `be_manage_plots` | Adiciona uma conexão `plot_member` - só o dono ou um gerente; quem não é gerente fica preso à lista de candidatos mesmo que envie um `character_id` direto |
| DELETE | `/{game_slug}/plots/{id}/members/{connection_id}` | `be_submit_actions` OU `be_manage_plots` | Remove uma conexão `plot_member` - um gerente pode remover qualquer um, o dono só quem ele mesmo adicionou (`403 not_your_addition` em caso contrário). A conexão própria do dono nunca é alcançável aqui |
| GET | `/{game_slug}/plots/{id}/visible-characters` | `be_manage_plots` | Todo personagem que pode ver esta trama no momento, só nome e id - sustenta o seletor de entrada direcionada |
| PUT | `/{game_slug}/plots/{id}/rumor-levels` | `be_manage_plots` | Define os textos de nível de um rumor como um conjunto: `levels`, um objeto de número de nível (1 a 10) para texto. Um nível nomeado com texto é gravado, um enviado vazio é excluído, um deixado de fora fica intocado |

## Entradas (respostas/ações da trama)

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/plots/{plot_id}/entries` | `be_view_characters` | Lista as entradas de uma trama (`404` para a alocação de ação de outro personagem, como acima), cada uma com o seu próprio `audience` (`plot`/`storytellers`/`characters`), `shared_at` e, numa entrada direcionada, `audience_character_ids` |
| POST | `/{game_slug}/plots/{plot_id}/entries` | `be_submit_actions` OU `be_manage_plots` | Adiciona uma entrada. `audience` tem como padrão `plot`; quem não é gerente só pode escolher `plot` ou `storytellers` (`403` para `characters`); `characters` exige um `audience_character_ids` não vazio, cada id um personagem que pode ver a trama no momento (`400` em caso contrário). `share_with_home` (booleano) compartilha o conteúdo da própria entrada como uma nota com a crônica de origem de verdade do personagem da trama, numa visita online (qualquer estado menos terminal, ou encerrada há até 30 dias), mantida atualizada ou não - em silêncio não faz nada numa visita inelegível ou numa cópia levada à mão sem origem registrada, carimbando `shared_at` só quando de fato enviou |
| PUT | `/{game_slug}/entries/{id}` | `be_submit_actions` OU `be_manage_plots` | Atualiza. `audience`/`audience_character_ids` ficam totalmente intocados quando não enviados - uma edição simples de conteúdo nunca volta um público escolhido a `plot`. `share_with_home` compartilha de novo como uma nota nova própria, exatamente como na criação |
| DELETE | `/{game_slug}/entries/{id}` | `be_manage_plots` | Exclui |

## Segredos

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/secrets` | `be_view_characters` | Todo segredo de uma entidade (`entity_type`/`entity_id` obrigatórios: `plot`/`item`/`location`/`npc`). Filtrado por público e com `[ST]` retirado para quem não é gerente |
| POST | `/{game_slug}/secrets` | `be_manage_plots` | Cria, ligado a uma entidade real deste jogo. `title` obrigatório; `content`, `audience`, `audience_rules` como os de uma trama |
| PUT | `/{game_slug}/secrets/{id}` | `be_manage_plots` | Atualiza `title`/`content`/`audience`/`audience_rules` |
| DELETE | `/{game_slug}/secrets/{id}` | `be_manage_plots` | Exclui, em cascata toda revelação nele |
| GET | `/{game_slug}/secrets/{id}/reveals` | `be_manage_plots` | Toda revelação de um segredo |
| POST | `/{game_slug}/secrets/{id}/reveals` | `be_manage_plots` | Revela-o a um personagem: `character_id` obrigatório, `how` um entre `game`/`downtime`/`rumor`/`told`/`other` (padrão `game`), `note`, `held`, `release_batch_id` opcionais. `409 already_revealed` para um personagem a quem este segredo já foi revelado |
| DELETE | `/{game_slug}/secrets/{id}/reveals/{reveal_id}` | `be_manage_plots` | Remove uma revelação |
| GET | `/{game_slug}/secrets/all` | `be_manage_plots` | Todo segredo da crônica, opcionalmente restringido por `search` contra o título - para o seletor "vincular a um segredo existente" da Fila de Aprovação, nunca filtrado por público |
| GET | `/{game_slug}/my/secrets` | `be_view_characters` | `{known, waiting}` - `known` é todo segredo revelado a um dos personagens próprios de quem pede (`id`, `reveal_id`, `character_id`, `character_name`, `title`, `content`, `entity_type`, `entity_name`, `how`, `told_by`, `approved`, `can_pass`, `learned_at`); `waiting` são as mudanças `log_knowledge`/`pass_secret` pendentes ou recusadas de quem pede |
| GET | `/{game_slug}/my/secrets/people` | `be_view_characters` | Os personagens que quem pede pode nomear como a pessoa que lhe contou algo: o Quem é Quem dele mais os personagens na ponta de uma conexão personagem-para-personagem a partir de um dos próprios personagens ativos dele, os próprios personagens entre eles quando estão no Quem é Quem ou conectados, ordenados por nome - `[ { id, name, kind } ]` com `kind` `pc` ou `npc`. Um gerente recebe todo personagem ativo |
| POST | `/{game_slug}/my/secrets/log` | `be_view_characters` | Registra o que um dos personagens próprios de quem pede aprendeu - `character_id` (o próprio de quem pede), `title`, `details`, `how` e, opcionalmente, `teller_character_id` ou `teller_name`. Arquiva uma mudança `log_knowledge` pendente, sempre de nível `st`. `403 secret_passing_off` quando a crônica tem isto desligado; `400 invalid_param` quando `teller_character_id` não está nessa lista ou é o próprio `character_id` que aprende |
| POST | `/{game_slug}/secrets/{id}/pass` | `be_view_characters` | Conta a outro personagem um segredo que o personagem próprio de quem pede conhece - `from_character_id` (o próprio de quem pede), `to_character_id`, `note` opcional. Recusa, em ordem: `403 secret_passing_off`; `403` quem conta não é um personagem próprio de quem pede; `404` quem conta não aprendeu o segredo (uma revelação retida e não lançada inclusive - nunca confirma que o segredo existe); `403 knowledge_not_approved`; `400 secret_already_public` (público `everyone`); `404` o destinatário não está no Quem é Quem de quem pede; `400` contar a si mesmo; `409 already_known`. Arquiva uma mudança `pass_secret` pendente, sempre de nível `st`; em `immediate`, também grava na hora uma revelação não aprovada, que o destinatário pode ler mas não repassar até um Narrador aprovar a mudança |

Aprovar uma mudança `log_knowledge` (`PUT /{game_slug}/changes/{id}`) também aceita `secret_id` (um segredo existente) ou `entity_type`/`entity_id`/`title`/`content` (um novo); `400` quando nenhum é dado. Aprovar `log_knowledge` ou `pass_secret` exige `be_manage_plots` além do `be_manage_characters` próprio da rota (`403 secret_capability_denied` em caso contrário, rejeitar ainda permitido); uma aprovação em lote pula uma mudança `log_knowledge` para o balde próprio `needs_secret_choice`, já que não pode ser decidida sem entrada por linha.

## Configurações de Ação e Rumor (APR)

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/apr-settings` | `be_manage_apr` | A configuração de ações de tempo livre e de geração de rumores desta crônica |
| PUT | `/{game_slug}/apr-settings` | `be_manage_apr` | Atualiza-a |
| GET | `/{game_slug}/apr-settings/backgrounds` | `be_manage_apr` | O catálogo de Antecedentes disponíveis para configurar como concedendo ação |

## Assistência de IA

Integração de assistência de escrita por IA no servidor (seção "Assistência de Escrita por IA" do Guia do Administrador). Dois pares de rotas, tratados do mesmo jeito: um par de todo o site para campos que não pertencem a nenhuma crônica, e um par restrito à crônica para todo o resto. A capacidade exigida pela própria rota de gerar é resolvida no servidor a partir do `field_context` do pedido, nunca confiada ao cliente.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| POST | `/ai-assist` | Resolvida por `field_context` | Gera uma sugestão para um campo de todo o site (descrições do catálogo de Blocos de Esquema, texto dos Créditos) |
| GET | `/ai-assist/settings` | `be_manage_games` | O provedor de todo o site, as opções de chave configurada e as substituições de URL base/modelo personalizados - nunca a chave em si |
| PUT | `/ai-assist/settings` | `be_manage_games` | Atualiza o provedor de todo o site, a chave (uma string vazia a limpa), a URL base ou o modelo |
| POST | `/ai-assist/test` | `be_manage_games` | Testa uma combinação de provedor/chave/URL base/modelo direto do corpo do pedido - nunca uma chave salva |
| POST | `/{game_slug}/ai-assist` | Resolvida por `field_context` | Gera uma sugestão para um campo restrito à crônica (personagem, trama, rumor, objeto do mundo). `403 demo_locked` numa crônica marcada como demonstração |
| GET | `/{game_slug}/ai-assist/settings` | `be_manage_apr` | A adesão própria desta crônica, o provedor, as opções de chave configurada e as substituições de URL base/modelo |
| PUT | `/{game_slug}/ai-assist/settings` | `be_manage_apr` | Atualiza as configurações próprias de Assistência de IA desta crônica. `500 save_failed` quando o salvamento não pegou |
| POST | `/{game_slug}/ai-assist/test` | `be_manage_apr` | Testa uma combinação de provedor/chave/URL base/modelo para esta crônica, antes de salvar |
| POST | `/{game_slug}/ai-assist/npc-draft` | `be_manage_characters` | Rascunha os campos de notas de interpretação só para Narradores de um NPC a partir do nome, tipo de criatura, identidade, perfil público, biografia e notas dele. Devolve só `{data: {...}}` - nada é gravado. `403 demo_locked` numa crônica de demonstração |
| POST | `/{game_slug}/ai-assist/plot-draft` | `be_manage_plots` | Rascunha uma trama a partir de uma premissa de uma linha, opcionalmente baseada em personagens/NPCs escolhidos (só o perfil público e os campos de identidade deles) e facções. Ao contrário de toda outra rota de Assistência de IA, esta grava na hora em vez de devolver uma prévia: uma transação cria a trama (`audience: storytellers`), 3-5 entradas `note` só para Narradores e 2-3 rumores retidos e etiquetados, revertendo por inteiro numa resposta inválida ou incompleta. Devolve `{plot_id}`. `403 demo_locked` numa crônica de demonstração |
| POST | `/{game_slug}/ai-assist/recap-draft` | `be_manage_sessions` | Rascunha os campos de resumo de uma sessão a partir da presença e dos relatórios pós-jogo da própria sessão. Devolve só `{data: {...}}` - nada é gravado. `403 demo_locked` numa crônica de demonstração |

## Usos de Antecedente

Registrar o que um jogador de fato fez com uma ação de tempo livre alocada, e um Narrador julgá-lo.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/characters/{character_id}/spendable` | `be_view_characters` | Quantas ações este personagem ainda tem para gastar na data de jogo atual |
| GET | `/{game_slug}/characters/{character_id}/background-uses` | `be_view_characters` | Lista os usos registrados deste personagem |
| POST | `/{game_slug}/characters/{character_id}/background-uses` | `be_submit_actions` OU `be_manage_characters` | Registra o que o jogador fez com uma ação. `500 record_failed` quando não pôde ser salvo - nada é mantido |
| POST | `/{game_slug}/characters/{character_id}/background-uses/clear` | `be_manage_characters` | Limpa todo uso registrado contra este personagem para uma data de jogo |
| POST | `/{game_slug}/background-uses/clear-date` | `be_manage_characters` | Limpa de uma vez os usos registrados de todos os personagens para uma data de jogo |
| PUT | `/{game_slug}/background-uses/{id}` | `be_submit_actions` OU `be_manage_characters` OU `be_manage_plots` | Atualiza um uso registrado - um Narrador preenchendo o resultado julgado, ou o jogador editando o próprio antes de ser revisado |
| DELETE | `/{game_slug}/background-uses/{id}` | `be_submit_actions` OU `be_manage_characters` | Exclui um uso registrado |

## Conexões

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/connections` | `be_manage_connections` OU `be_manage_plots` | Lista as conexões entre personagens/objetos. Só a equipe: a lista mostra quem tem o quê e de quem é cada alocação de ação. Todo id numa linha (`id`, `game_id`, `source_id`, `target_id`, `created_by`) é um inteiro, e `target_id` é `null` quando a conexão não tem alvo |
| POST | `/{game_slug}/connections` | `be_manage_connections` | Cria |
| PUT | `/{game_slug}/connections/{id}` | `be_manage_connections` | Atualiza |
| DELETE | `/{game_slug}/connections/{id}` | `be_manage_connections` | Exclui |

## Consulta

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| POST | `/{game_slug}/query` | `be_run_queries` E `be_manage_characters` | Executa uma consulta avulsa contra a lista de personagens |
| POST | `/{game_slug}/statistics` | `be_run_queries` E `be_manage_characters` | Executa uma das cinco estatísticas embutidas da lista |
| GET | `/{game_slug}/queries` | `be_run_queries` E `be_manage_characters` | Lista as consultas salvas |
| POST | `/{game_slug}/queries` | `be_run_queries` E `be_manage_characters` | Salva uma consulta |
| PUT | `/{game_slug}/queries/{id}` | `be_run_queries` E `be_manage_characters` | Atualiza uma consulta salva |
| DELETE | `/{game_slug}/queries/{id}` | `be_run_queries` E `be_manage_characters` | Exclui uma consulta salva |

## Objetos do Mundo (itens, locais, rituais)

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/world-objects` | `be_view_characters` | Lista, filtrável por tipo. Com `object_type`, qualquer propriedade desse tipo também filtra: um número exato ou com `_min`/`_max`, texto exato em qualquer caixa e uma lista - as `abilities` de um item, as `spheres` de um ritual - pelo nome de uma entrada. Itens/locais são restringidos pelo próprio `audience`/`audience_rules` para quem não é gerente (1.1.0 §2.5, um personagem conectado sempre incluído); rituais e favores passam intocados |
| POST | `/{game_slug}/world-objects` | `be_manage_world_objects` | Cria. Nome, raridade e custo são texto simples (no máximo 255, 20 e 100 caracteres; mais longo é `400`), descrição e limitações permitem o mesmo HTML de uma postagem. `audience`/`audience_rules` aceitos na mesma forma que os de uma trama; significativos só para item/local. Um favor é recusado (`409 use_boon_ledger`) - os favores são feitos em `/boons` |
| GET | `/{game_slug}/world-objects/{id}` | `be_view_characters` | Obtém um, com os anexos embutidos (nunca `stored_name`). Escondido pelo próprio público para quem não é gerente, a menos que um personagem conectado o alcance |
| PUT | `/{game_slug}/world-objects/{id}` | `be_manage_world_objects` | Atualiza, limpo e limitado do mesmo jeito que uma criação. `audience`/`audience_rules` atualizáveis do mesmo jeito. `409 use_boon_ledger` para um favor |
| DELETE | `/{game_slug}/world-objects/{id}` | `be_manage_world_objects` | Exclui, em cascata os anexos dele (linhas e arquivos). `409 use_boon_ledger` para um favor, que nunca é excluído |
| POST | `/{game_slug}/world-objects/{id}/copy-for-character` | `be_manage_world_objects` | Copia um item para um personagem: `character_id` e um `name` opcional. A cópia mantém todo campo, propriedade e regra de público, é sempre restrita, conecta-se a esse personagem como detentor dela e começa o próprio histórico. Só itens (`409 items_only`) |
| POST | `/{game_slug}/world-objects/{id}/use` | `be_manage_world_objects` OU `be_edit_own_characters` | Gasta um uso de um item: `character_id` e uma `note` opcional. O jogador do próprio detentor ou um Narrador (`403 not_holder`); `400 no_uses` para um item sem usos definidos, `409 used_up` ou `409 expired` |
| POST | `/{game_slug}/world-objects/{id}/transfer` | `be_manage_world_objects` | Passa um item a outro personagem: `how` (`given`, `traded`, `stolen` ou `lost`), `to_character_id` (omitido para `lost`) e uma `note` opcional. Gravado no histórico do item. Um favor não é um item (`409 use_boon_ledger`) |
| GET | `/{game_slug}/world-objects/{id}/events` | `be_manage_world_objects` | O histórico próprio de um item, o mais antigo primeiro. Cada evento é `given`, `taken`, `traded`, `stolen`, `lost`, `used`, `copied`, `proposed` ou `adjusted` |
| POST | `/{game_slug}/world-objects/{id}/revoke-cards` | `be_manage_world_objects` | Revoga todo código de verificação já impresso para um item. Um cartão já impresso passa então a verificar como revogado; uma reimpressão emite um código novo |
| GET | `/{game_slug}/locations/{id}/links` | `be_view_characters` | Os vínculos nomeados de um local, `[{id, label, source_type, source_id, name}]`, em que `label` é `owner`, `domain`, `haven` ou `based_at`. Um gerente vê todo vínculo. Qualquer outra pessoa recebe só a lista "quem está aqui": os NPCs baseados ali cujo perfil público próprio a alcança |
| POST | `/{game_slug}/locations/{id}/links` | `be_manage_world_objects` | Adiciona um vínculo: `label` (um dos quatro) e `source_id`, um personagem desta crônica. `400 invalid_param` |
| DELETE | `/{game_slug}/locations/{id}/links/{link_id}` | `be_manage_world_objects` | Remove um vínculo |

## Catálogo de Itens (dados do livro declarados, somente leitura)

Uma lista de itens vinda do livro, sem crônica, um arquivo JSON por livro de regras, que vem com o plugin e nunca é guardada numa tabela do banco de dados. Sustenta **Adicionar do livro** em Itens e Locais e **Começar a partir de uma entrada do livro** em Propor um Item; os dois leem esta rota e depois criam ou propõem um item comum pelas rotas acima.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/items/catalog` | `be_view_characters` | Não restrito a um jogo - qualquer conta autenticada o lê. `{ items, books }`: `items` filtrados por `search` (trecho do nome, sem diferenciar caixa), `book` (o slug do próprio livro, exato) e `item_type` (correspondência exata sem diferenciar caixa contra `properties.item_type`); cada um traz `key`, `name`, `object_type`, `description`, `properties`, `source { book, code, page }`, `book`, `book_slug` e `book_ref` (`{book_slug}:{key}`, o que `properties.book_ref` recebe depois de copiado para uma crônica). `books` lista todo livro com pelo menos um item, cada um `{slug, name}` |

## Anexos

Arquivos numa trama, item, local ou personagem - imagens e PDFs, 10 MB cada, até 20 para uma trama/local, exatamente 1 para um item ou personagem. O anexo próprio de um personagem é só imagem (sem PDF). Nunca servidos pela biblioteca de mídia do WordPress; veja "Envio de Arquivos" do Guia do Administrador.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| POST | `/{game_slug}/attachments` | `be_submit_actions` OU `be_manage_plots` OU `be_manage_world_objects` | Envia. Exige `entity_type` (`plot`/`item`/`location`/`character`) e `entity_id`, mais um arquivo nos parâmetros de arquivo do pedido. A conferência de verdade é por entidade: um Narrador sempre, ou o dono da própria trama (`403` em caso contrário); itens/locais são só `be_manage_world_objects`; um personagem é o próprio dono ou um gerente - alcançável por um jogador comum pelo mesmo `be_submit_actions` que todo papel de jogador já tem. `400 invalid_file_type`/`file_too_large` contra o conteúdo real do arquivo, nunca o tipo declarado (só imagens para um personagem); `409 limit_reached` no limite |
| GET | `/{game_slug}/attachments/{id}` | `be_view_characters` | Transmite os bytes crus do arquivo (não JSON) depois que o público de quem pede alcança a entidade dona - `404` em caso contrário, sem revelar nada do arquivo. Para um personagem, o próprio dono sempre o alcança, seja qual for o público do Quem é Quem do personagem |
| DELETE | `/{game_slug}/attachments/{id}` | `be_submit_actions` OU `be_manage_plots` OU `be_manage_world_objects` | Exclui - a linha do banco de dados e o arquivo juntos, ou nenhum. A mesma conferência por entidade do envio |

## Favores

`be_manage_boons` é concedida em todo o site a todo papel do WordPress, `subscriber` inclusive - a conferência de verdade por crônica continua sendo o papel próprio de quem pede em `be_game_members` ali (`boons`, `hst` ou `ast`); um visitante autenticado sem filiação nesta crônica ainda recebe `403` das rotas de escrita abaixo.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/boons` | `be_view_characters` | O registro - do jogo inteiro, ou a divisão devidos/devidos-a-ele de um personagem |
| POST | `/{game_slug}/boons` | `be_manage_boons` | Registra um novo favor |
| PUT | `/{game_slug}/boons/{id}/repay` | `be_manage_boons` | Marca um favor como pago - uma atualização transacional simétrica. `500 save_failed` quando não salvou |

## Importar (arquivos de intercâmbio - `.gex`, de personagem ou restritos ao jogo)

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| POST | `/{game_slug}/import/parse` | `be_import` | Envia e analisa; devolve uma prévia, guarda o trabalho por uma hora |
| GET | `/{game_slug}/import/{job_id}` | `be_import` | Busca de novo o estado atual de um trabalho guardado |
| POST | `/{game_slug}/import/{job_id}/commit` | `be_import` | Confirma - recusa enquanto algo estiver não resolvido/sinalizado; transacional; seguro para reenviar |

Um personagem cujo próprio tipo de criatura não tem pilha que vem com o plugin neste site, ou não está ativado nesta crônica, é recusado em vez de gravado: a matriz `refused` da prévia traz uma linha `{character, reason}` para cada um desses personagens, e a matriz `characters` da confirmação relata essa linha com `action: "refused"` e `id: 0` em vez de criá-lo. Todos os outros do mesmo arquivo ainda são importados.

## Exportar

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| POST | `/{game_slug}/characters/{id}/export` | `be_manage_characters` OU `be_edit_own_characters` | Exporta um personagem para um documento XML `.gex` do Grapevine. `422 not_exportable` para um tipo de criatura sem equivalente no Grapevine (um que um administrador adicionou). `hide_st` pede a cópia de um jogador - nenhum bloco só para Narradores, nenhum texto marcado com `[ST]...[/ST]` em notas, biografia ou termos de favor - e só importa para um Narrador: a exportação do próprio jogador é sempre essa cópia, seja o que for que o pedido diga. `as_transfer: true` é recusado (`400 use_transfer_route`); um documento de transferência vem só de `/transfers/outbound`. `verify` cunha uma atestação nova embutida no documento, então cada chamada emite um código novo - não é grátis chamar repetidamente para o mesmo download, e o `document_hash` guardado dela cobre exatamente este documento (redigido ou não) e não o `sheet_hash` sempre não redigido da ficha, então uma reconferência posterior compara contra o que foi de fato entregue |

## Importação de Jogo (arquivos de jogo `.gv3` completos - não restritos a um jogo, já que uma crônica nova pode ainda não existir)

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| POST | `/import/game/parse` | `be_import` E `be_manage_games` | Envia e analisa um arquivo de jogo completo |
| GET | `/import/game/{job_id}` | `be_import` E `be_manage_games` | Busca de novo um trabalho guardado |
| POST | `/import/game/{job_id}/commit` | `be_import` E `be_manage_games` | Cria uma nova crônica, ou mescla numa existente, a partir do conteúdo do arquivo |

As tramas, rumores e ações de um arquivo de jogo completo têm um destino de importação de verdade. A prévia leva os totais deles em `counts` e, num objeto `narrative`, `{plots, rumors, actions}: {already_present}` (uma contagem de ensaio do que reimportar pularia, pela mesma regra de título e data que a própria confirmação usa) e `unmatched_names` (nomes de personagens de elenco ou de ação que não casam com ninguém, no arquivo ou na crônica de destino). O `resolutions.import_kinds` da própria `commit` (`{plots?, rumors?, actions?}`, cada um um booleano, marcado - importado - quando ausente) escolhe quais dos três gravar; o resultado relata `plots`/`rumors`/`actions` (criados) e `skipped_plots`/`skipped_rumors`/`skipped_actions` (já presentes, ou todos desse tipo quando a caixa própria estava desligada) ao lado dos `items`/`locations`/`rotes`/`characters` existentes, mais `unmatched_cast`/`unmatched_actors` que nomeiam quem não casou com ninguém. A consulta própria do Grapevine de um rumor se converte numa regra de público de verdade quando é exatamente uma condição que esta instalação reconhece; uma consulta que precisa de mais que isso (várias condições ao mesmo tempo, uma negada, ou uma que precisa de um nome de traço e de uma contagem) mantém o rumor só para Narradores com uma nota na trama explicando por quê, em vez de adivinhar. As consultas em si, prêmios de XP, modelos, entradas de calendário e configurações de alocação de ação/rumor ainda ficam de fora - as contagens próprias deles continuam em `skipped`.

## Transferências (visitas de crônica para crônica)

Enviar um personagem para visitar uma crônica diferente - nesta instalação, ou num site Beyond Elysium totalmente diferente - com um aperto de mão de verdade e uma atestação em vez de uma exportação/reimportação simples. Um Narrador aprova cada lado: a crônica de origem a envia, e a crônica anfitriã revisa a oferta e a aceita ou recusa. Uma visita nunca tira o personagem de casa - ele continua ativo lá o tempo todo, e a lista de personagens da própria origem mostra toda crônica onde ele também está ativo (o campo `visits` de `GET /{game_slug}/characters`, cada um com `keep_current`, `delivered_at` e `unreachable_since`). Qualquer número de visitas pode estar aberto para um personagem ao mesmo tempo, uma por anfitriã. O `visiting_from` próprio de um personagem (a única linha de entrada, se houver) traz `unreachable_since` também - definido no lado que passou um dia inteiro sem uma entrega ou recebimento confirmado de uma atualização mantida atualizada, limpo pela próxima que passar; uma visita simples (não mantida atualizada) nunca o traz, já que nada nunca é agendado para chegar numa.

**Estados.** Origem: `offered` (enviada, esperando) → `visiting` (a anfitriã aceitou) → `ended`, ou `declined` / `expired`. Anfitriã: `offered` → `visiting` → `ended`, ou `refused` / `expired`. `released` (origem) e `retained` (anfitriã) querem dizer a mesma coisa de cada lado: a ficha de registro passa à anfitriã de vez. Não há rota de Reconhecer - depois que uma anfitriã aceita, ela chama a origem direto e a linha própria da origem se move sozinha; qualquer um dos lados que encerra uma visita chama o outro do mesmo jeito, cada chamada levando um código de verificação novo e de vida curta que o site receptor chama de volta para confirmar. Um lado que não pode ser alcançado, ou que recusa o código, ainda não é tentado de novo - a ação local vale de qualquer modo.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/transfers` | `be_manage_characters` | Lista as transferências de que esta crônica é parte neste site - linhas de saída de que é origem, linhas de entrada que hospeda - sem conteúdos guardados |
| POST | `/{game_slug}/transfers/outbound` | `be_manage_characters` | Envia um personagem. Sempre devolve o documento de transferência; dados `host_site` e `host_slug`, também o oferece a essa crônica, e a linha fica `offered` até a anfitriã responder. Um `keep_current` opcional pede para manter a ficha do personagem atualizada na anfitriã pelo tempo que a visita durar - levado junto da oferta, a anfitriã ainda precisa concordar. `422 not_exportable`, sem gravar nada, para um tipo de criatura sem equivalente no Grapevine. `409 already_travelling` enquanto o personagem já tem uma visita aberta a essa mesma anfitriã, inclusive uma que outro pedido gravou um instante antes (uma visita a uma anfitriã *diferente* não é afetada - qualquer número pode estar aberto ao mesmo tempo); `500 create_failed` quando a transferência não salvou. `403 demo_locked` numa crônica marcada como demonstração |
| POST | `/{game_slug}/transfers/{id}/release` | `be_manage_characters` | Lado da origem: abre mão de um personagem `visiting` de vez. Avisa a anfitriã, cuja linha própria passa a `retained` |
| POST | `/{game_slug}/transfers/{id}/decline` | `be_manage_characters` | Lado da origem: cancela uma transferência `offered`. Revoga o código de verificação dela, então uma oferta ainda esperando na anfitriã já não pode ser aceita |
| POST | `/{game_slug}/transfers/inbound` | nenhuma (pública por desenho) | Chamada pelo site *remetente*. Verificada contra o próprio `/verify/{code}` do remetente, que precisa responder por um código emitido para uma transferência (o código de uma exportação verificada é recusado, `400 verify_failed`); grava uma linha `offered` com o documento e envia e-mail aos HSTs e ASTs desta crônica. Devolve `202 {pending_review: true}`. 10 pedidos por minuto por IP; `409 already_offered` enquanto o personagem já está esperando ou visitando aqui, inclusive uma oferta gravada um instante antes; `500 create_failed` quando a oferta não salvou; `429 too_many_offers` além de 50 ofertas esperando. `403 demo_locked` numa crônica marcada como demonstração. Um `home_site` com endereço link-local, não especificado ou compartilhado é recusado com `400 unsafe_site` antes de qualquer pedido ser feito a ele |
| GET | `/{game_slug}/transfers/{id}/review` | `be_import` | Lado da anfitriã: a prévia de importação de uma transferência `offered` - a mesma forma que `GET /{game_slug}/import/{job_id}` devolve. Uma duplicata encontrada nesta crônica também traz `changes`: uma linha `{section, entry, here, arriving}` por diferença da ficha que já está aqui (`here` nulo para algo que só está chegando, `arriving` nulo para algo que só está aqui); uma correspondência em outra crônica não traz nenhuma |
| POST | `/{game_slug}/transfers/{id}/accept` | `be_import` | Lado da anfitriã: aceita uma oferta com `resolutions` de importação. Pergunta de novo ao site de origem primeiro (`400 verify_failed` se ele cancelou), exige uma decisão para toda duplicata (Ignorar é recusado - recuse a oferta em vez disso), importa numa só transação, passa a linha para `visiting` e então chama a origem direto para a linha própria dela também se mover. Um `keep_current_accepted` opcional, verdadeiro só quando a própria origem pediu, define `keep_current`/`keep_current_accepted` nas duas linhas de uma vez |
| POST | `/{game_slug}/transfers/{id}/refuse` | `be_import` | Lado da anfitriã: recusa uma oferta; nada é gravado |
| POST | `/{game_slug}/transfers/{id}/send-home` | `be_manage_characters` | Lado da anfitriã: encerra uma visita (`visiting` → `ended`). Avisa a origem, cuja linha própria também termina |
| POST | `/{game_slug}/transfers/{id}/retain` | `be_manage_characters` | Lado da anfitriã: mantém um personagem visitante de vez (`visiting` → `retained`). Avisa a origem, cuja linha própria passa a `released` |
| POST | `/{game_slug}/transfers/{uuid}/from-host` | nenhuma (pública por desenho) | Lado da origem: a anfitriã contando a esta crônica a própria aceitação ou o fim de uma visita, ou (`type change`/`note`) uma mudança local que a anfitriã não pôde aplicar - uma cópia visitante com uma visita aberta e combinada de manter atualizado encaminha para cá em vez de aplicar qualquer coisa. Uma `note` é arquivada como uma nova mudança `visit_note`, sem efeito na ficha; aprovada, cai só para Narradores na trama própria do personagem. (`type moved`) a anfitriã se renomeou - identificada pelo slug que esta crônica ainda tem registrado, levando o novo slug e o nome da crônica para substituí-lo; a linha de saída própria desta crônica é atualizada, nada mais. (`type pairing`) o pedido de uma anfitriã para parear um personagem enviado por um jogador com esta crônica: não nomeia nenhuma visita existente, já que esta crônica nunca ouviu falar do personagem - o código emitido pela origem do próprio arquivo é resolvido localmente (sem chamada de rede) para achar de qual personagem se trata de verdade, e uma mudança `visit_pairing` pendente é arquivada, nunca aprovada automaticamente; aprová-la cria uma nova visita de saída aqui (`visiting`, as duas opções de manter atualizado ligadas) e avisa a anfitriã; recusá-la, ou 60 dias sem resposta, deixa a cópia da própria anfitriã sem par. Verificada contra o próprio `/verify/{code}` da anfitriã, presa a esta chamada exata (um código emitido para uma visita, estado ou tipo de chamada diferente é recusado); recusa uma chamada cujo emissor verificado não combina com o site anfitrião declarado; recusa além de 50 itens ainda esperando dessa única anfitriã (`429 too_many_waiting`); recusa uma `change` cujo `change_type` a própria rota de mudanças não aceitaria (`400 invalid_param`); recusa um segundo `pairing` para o mesmo personagem vindo da mesma anfitriã enquanto um está esperando (`409 already_waiting`); recusa um site anfitrião com endereço link-local, não especificado ou compartilhado (`400 unsafe_site`). Marcação numa mudança ou nota encaminhada é limpa com `wp_kses_post()`, e a fila de aprovação mostra o endereço do site da anfitriã ao lado do nome dela num `pairing`. 10 pedidos por minuto por IP |
| POST | `/{game_slug}/transfers/{uuid}/from-home` | nenhuma (pública por desenho) | Lado da anfitriã: a origem contando a esta crônica que uma visita terminou, uma mudança de manter atualizado ou (`type update`) a ficha própria de um personagem mantido atualizado - nome, `sheet_data`, XP ganho e não gasto e a próxima sequência - verificada contra o próprio `/verify/{code}` do site de origem (espécie `transfer`, a mesma espécie que o código da própria visita original carrega) e recusada em sequência igual ou abaixo da já aplicada (`409 stale_sequence`). Um instantâneo é tirado primeiro; toda entrada `trait_list`/`tiered_power` é reconferida contra o catálogo próprio desta crônica independentemente do que era na origem, caindo como `custom` onde esta crônica não tem correspondência; uma linha é adicionada ao `update_log` próprio da visita (as 50 mais novas: quando, sequência, as seções que mudaram, as entradas que caíram como personalizadas). Ligações de trama, presença, conexões e as cópias próprias desta crônica dos blocos nunca são tocadas. (`type pairing_accepted`) a origem confirmando que aprovou um pedido de pareamento - define `keep_current_accepted` na linha já existente desta crônica e grava o uuid real próprio da origem para ela, já que um personagem pareado nunca divide o mesmo uuid dos dois lados. 10 pedidos por minuto por IP |
| POST | `/{game_slug}/transfers/{id}/keep-current` | `be_manage_characters` | Qualquer um dos lados: liga ou desliga manter atualizado (`on`) para uma visita aberta, nomeando a linha própria de qualquer lado pelo próprio id. Ligar define o `keep_current` desta linha; o outro lado fica sabendo quando a visita está `visiting`. Desligar limpa `keep_current` e `keep_current_accepted` aqui e, do mesmo jeito, no outro lado |
| POST | `/{game_slug}/transfers/{id}/keep-current/accept` | `be_manage_characters` | Lado da anfitriã: concorda em manter atualizado um personagem `visiting`, depois da revisão (o `keep_current_accepted` do próprio `accept` é a mesma concordância na hora da revisão). Avisa a origem, que define `keep_current`/`keep_current_accepted` para combinar |
| POST | `/{game_slug}/transfers/{id}/note` | `be_manage_characters` | Lado da anfitriã: compartilha uma nota de texto livre sobre um personagem visitante com a origem de verdade dele, numa visita aberta e combinada de manter atualizado. Sem efeito na ficha de um jeito ou de outro |

Um personagem casado pela identidade (uuid) só é desta crônica para sobrescrever quando vive aqui. Um que vive em outra crônica deste site é relatado como `matched_by: "uuid_elsewhere"` e pode ser ignorado ou importado como um personagem novo com a própria identidade - nunca sobrescrito. Isso vale para toda importação, não só transferências.

## Envios (um jogador enviando o próprio arquivo do Grapevine direto a uma crônica)

A contraparte iniciada pelo jogador das Transferências: nenhum Narrador na ponta de envio - qualquer pessoa autenticada pode enviar um `.gex` exportado a qualquer crônica, entrando nela ou visitando para um jogo. Reaproveita a mesma forma de revisar/aceitar/recusar que as Transferências já estabeleceram, presa a `game_id` e não a um slug, para uma renomeação de crônica não deixar um arquivo em espera órfão.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| POST | `/{game_slug}/submissions/preview` | `be_edit_own_characters` | Isento de bootstrap, a mesma regra da criação de personagem. Lê o envio e informa quais dos personagens dele podem ser enviados aqui, com um motivo para um que não pode; não guarda nada - `413 file_too_large` além de 5 MB, `400 unsupported_format` para um `.gv3`, `400 invalid_format` para qualquer outra coisa não reconhecida, `422 no_character` para um arquivo sem nenhum |
| POST | `/{game_slug}/submissions` | `be_edit_own_characters` | Isento de bootstrap. Relê o envio e grava o pedido; `character_index` escolhe um entre vários, `arrival` (`joining` ou `visiting`, obrigatório), um `home_chronicle` opcional e `keep_current` (booleano) - pede à anfitriã de um envio visitante que tente parear com a origem de verdade dele depois de aceito; guardado na linha, não tem efeito num `joining`. `400 choose_character` para vários personagens sem índice; `400 not_allowed` quando o personagem escolhido falha na mesma conferência de permitido-aqui que o `preview` relata; `409 join_already_requested` para um não-membro que já tem um personagem de entrada pendente montado à mão aqui (e o inverso, de `POST /{game_slug}/characters`, confere esta tabela também); `409 already_waiting` para um segundo arquivo à mesma crônica; `403 join_requests_off` para um arquivo `joining` de um recém-chegado sem pedido de entrada esperando quando a crônica tem os pedidos de entrada desligados; `429 too_many_waiting` além de 50 na crônica toda. Envia e-mail a todo HST e AST |
| POST | `/{game_slug}/submissions/{id}/withdraw` | `be_edit_own_characters` | Só quem enviou (`404 submission_not_found` para qualquer outra pessoa, ou um `game_slug` errado) - `waiting` → `withdrawn` |
| GET | `/my/submissions` | `be_view_characters` | Os últimos 20 próprios de quem pede, em qualquer crônica. Registrada antes de `/{game_slug}/submissions` para uma crônica literalmente com o slug `my` não poder escondê-la |
| GET | `/{game_slug}/submissions` | `be_import` | As linhas `waiting` desta crônica, cada uma com `sender_name` |
| GET | `/{game_slug}/submissions/{id}/review` | `be_import` | A mesma forma de prévia que `GET /{game_slug}/import/{job_id}` devolve, mais `overwrite_allowed`/`existing_owner` em cada duplicata - um arquivo enviado por jogador nunca pode sobrescrever um personagem que ainda não é dele, nem um casado pelo nome. Um personagem que já não é permitido (uma restrição adicionada desde que foi enviado) aparece como um aviso em vez de bloquear a revisão em silêncio |
| GET | `/{game_slug}/submissions/{id}/verification` | `be_import` | O código de verificação embutido conferido contra o site emissor dele, se o arquivo traz um - a mesma conferência de sete resultados que `GET /verify/{code}` executa, alcançável aqui sem um Narrador visitar essa página à mão |
| POST | `/{game_slug}/submissions/{id}/accept` | `be_import` | Importa pelo mesmo pipeline que toda importação usa, com quem enviou forçado sobre o resultado: `wp_user_id` quem enviou, `player_name` limpo, `status` ativo, nunca um NPC, sem narrador. Uma entrada torna quem enviou membro; uma visita se comporta exatamente como aceitar uma transferência recebida, com a própria opção `keep_current` levada para essa nova linha. Quando o envio pediu para ser mantido atualizado e o arquivo trazia um código de verificação de verdade, aceitar também envia à origem de verdade do arquivo um pedido de pareamento (`type pairing` em `from-host`) - dispara e esquece; a origem pode nunca responder, ou pode recusar, caso em que a cópia própria da anfitriã simplesmente fica `keep_current` sem `keep_current_accepted`, igual a qualquer outro pedido não aceito |
| POST | `/{game_slug}/submissions/{id}/refuse` | `be_import` | Recusa-o com uma nota opcional; nada é gravado. Envia e-mail a quem enviou |

## Membros da Crônica

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/members` | `be_manage_games` | Lista os membros e papéis desta crônica |
| POST | `/{game_slug}/members` | `be_manage_games` | Adiciona um membro, ou muda o papel de um membro existente |
| DELETE | `/{game_slug}/members/{wp_user_id}` | `be_manage_games` | Remove o acesso restrito à crônica de um membro |

## Jogadores da Crônica (para o HST e o AST da crônica)

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/players` | `be_manage_characters` | `{players: [{wp_user_id, display_name, since, characters: [{id, name}]}], asc_role_path, join_link}` - os membros com papel de jogador da crônica, por nome de exibição, cada um com os personagens ligados a ele, o caminho de jogador do accessSchema (`null` numa crônica não ligada, em que toda rota de jogadores responde) e o link de entrada público da própria crônica (`auth=sso` só quando ligada) |
| POST | `/{game_slug}/players` | `be_manage_characters` | `wp_user_id` de uma conta existente. Junta-a ao site como assinante quando ela não está nele (multisite), grava uma linha de filiação `player` e concede `{asc_role_path}/player` pelo owbn-core, depois atualiza os papéis em cache dessa única conta. Devolve `{status, site_added, asc: {attempted, granted, role_path, message}}` com `status` `added` (201), `already_player` ou `staff` (200, um membro da equipe deixado inalterado); `404 no_account` para uma conta que não existe. Uma concessão que o accessSchema recusa é relatada em `asc` e a filiação permanece |
| DELETE | `/{game_slug}/players/{wp_user_id}` | `be_manage_characters` | Remove a linha de filiação `player` e revoga o papel de jogador; os personagens da conta ficam intocados. Devolve `{status: removed or not_member, asc: {attempted, revoked, role_path, message}}`; `409 staff_member` para um membro da equipe |
| GET | `/{game_slug}/players/invites` | `be_manage_characters` | Os convites abertos da crônica: `[{id, email, invited_by, invited_at, characters: [{id, name}]}]`, cada um com os personagens que esperam o e-mail dele |
| POST | `/{game_slug}/players/invites` | `be_manage_characters` | `email` (o endereço exato; `400 invalid_email` em caso contrário), `character_ids` (os personagens de jogador da crônica), `send_email` (padrão true). Uma conta com esse e-mail em qualquer lugar da rede, sejam quais forem as maiúsculas, vira jogador na hora, como `POST /players` faz, e os personagens são ligados a ela: `200 {status: linked, wp_user_id, display_name, player, linked, skipped}`. Em caso contrário o convite é guardado, os personagens guardam o e-mail em `pending_player_email`, o e-mail entra no índice da rede e, com `send_email`, sai um convite: `201 {status: invited, invite_id, held, skipped, email_sent}`. Um personagem ligado a outra pessoa nunca é movido: fica em `skipped` com `reason: linked_elsewhere` e `linked_to`. Quando uma conta com esse e-mail entra (`wp_login`), é criada (`user_register`) ou faz pela primeira vez um pedido autenticado a um site que guarda um convite, cada site assim a torna jogador, liga os personagens que esperam com uma linha de histórico cada um (`player_link`) e marca o convite como aceito |
| DELETE | `/{game_slug}/players/invites/{id}` | `be_manage_characters` | Cancela um convite aberto e limpa o e-mail pendente dos personagens dele; `404 invite_not_found` para um que não está aberto nesta crônica |
| POST | `/{game_slug}/players/{wp_user_id}/characters` | `be_manage_characters` | `character_ids`: liga cada um a esse membro da crônica, com uma linha de histórico; devolve `{linked: [{id, name}], skipped: [{id, name?, linked_to?, reason}]}`. `404 not_member` para uma conta que não é membro |
| DELETE | `/{game_slug}/players/{wp_user_id}/characters/{character_id}` | `be_manage_characters` | Desvincula o personagem dessa conta, com uma linha de histórico; `404 not_linked` quando não está ligado a ela nesta crônica |
| GET | `/{game_slug}/players/join-requests` | `be_manage_characters` | Todo pedido de entrada desta crônica, os que esperam primeiro: `[{id, wp_user_id, display_name, message, status, created_at, character: {id, name} or null, submission_id, note}]` |
| POST | `/{game_slug}/players/join-requests/{id}/approve` | `be_manage_characters` | Concede a filiação (`Chronicle_Players::add()`) e, quando o pedido leva um personagem, o ativa; encerra o pedido e envia e-mail a quem pediu. `404 not_found`; `409 already_answered` para um que não está `waiting`; `400 review_the_file_instead` quando o pedido leva um arquivo do Grapevine, sem conceder nada - aceite-o em Importar em vez disso, o que também encerra o pedido |
| POST | `/{game_slug}/players/join-requests/{id}/refuse` | `be_manage_characters` | `note` (opcional). Exclui um personagem pendente que o pedido leva, ou recusa um envio que ele leva; encerra o pedido e envia e-mail a quem pediu com a nota. Os mesmos `404`/`409` de approve |

## Registro de E-mails (o registro de e-mails de uma crônica, para o HST e o AST dela)

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/mail-log` | `be_manage_characters` | Uma página do registro da crônica, a mais nova primeiro, com `X-WP-Total` e `X-WP-TotalPages`. Cada linha: `{id, created_at, wp_user_id, recipient_name, recipient_email, kind, kind_label, subject, result, result_label, reason, reason_label, error, entity_type, entity_id, entity_label}`. `result` é `sent`, `failed`, `skipped` (não enviado, com um `reason`) ou `queued` (retido para o resumo diário do destinatário). Nunca traz o que uma mensagem dizia. Filtros: `search` (nome, endereço ou assunto), `kind`, `result`, `since` (`day`, `week`, `month` ou `all`), `entity_type` com `entity_id`, `wp_user_id`; `page` e `per_page` (padrão 20, no máximo 100). Só as linhas de uma crônica; o slug de outra crônica é `403` para quem não é Narrador dela |
| GET | `/{game_slug}/mail-log/options` | `be_manage_characters` | `{kinds: [{key, label}], results: [{key, label}], periods: [{key, label}], retention_days}` - o que os filtros oferecem, e por quantos dias uma linha é mantida (90) |

## Sessões de Jogo (noites de jogo, presença, relatórios pós-jogo, destaque)

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/sessions` | `be_view_characters` | As noites de jogo da crônica, a mais próxima primeiro; `from` e `to` (datas) restringem o intervalo. Cada uma: `{id, game_date, start_time, place, notes, downtime_opens_at, downtime_deadline_at, downtime_extensions, default_batch_id, reports_due_at, ...}`. Para quem não é gerente, o texto `[ST]` é retirado de `notes` e o `recap` só para Narradores fica de fora |
| POST | `/{game_slug}/sessions` | `be_manage_sessions` | Cria uma. `game_date` é obrigatório e único na crônica (`409 duplicate_date`). Opcionais: `start_time`, `place`, `notes`, `reports_due_at`, `downtime_opens_at`, `downtime_deadline_at`, `downtime_extensions`, `default_batch_id` e `recap` (`{key_events, player_decisions, npcs_involved: [{name, status}], cliffhanger, prep}`) |
| PUT | `/{game_slug}/sessions/{id}` | `be_manage_sessions` | Atualiza qualquer um desses campos; `409 duplicate_date` quando outra noite já tem a data |
| DELETE | `/{game_slug}/sessions/{id}` | `be_manage_sessions` | Exclui uma. `409 session_in_use` enquanto tem presença, uma escalação ou um relatório pós-jogo |
| PUT | `/{game_slug}/session-settings` | `be_manage_characters` | Define as configurações de sessão da crônica, mescladas ao que está salvo: `attendance_xp`, `report_xp` e `spotlight_days` (números inteiros), e `release_schedule`, `{rules: [...]}`. Uma regra é `{type: weekly, weekday, time}` ou `{type: monthly, day_of_month (1 a 28), time}`; uma regra que não valida é descartada. Responde `{sessions, release_schedule}` |
| GET | `/{game_slug}/sessions/{id}/attendance` | `be_manage_sessions` | Todos os registrados: `{id, session_id, character_id, visitor_name, visitor_chronicle, recorded_by, created_at}` |
| POST | `/{game_slug}/sessions/{id}/attendance` | `be_manage_sessions` | Registra a presença de um: um `character_id`, ou um `visitor_name` com um `visitor_chronicle` opcional. `404 character_not_found`, `400 invalid_candidate`, `409 already_signed_in` |
| DELETE | `/{game_slug}/sessions/{id}/attendance/{attendance_id}` | `be_manage_sessions` | Remove um registro de presença |
| POST | `/{game_slug}/sessions/{id}/award-attendance-xp` | `be_manage_characters` | Concede XP a todo personagem registrado. `amount` tem como padrão o `attendance_xp` da crônica (1). `409 already_awarded` depois de feito, a menos que `force` seja enviado. Responde `{awarded_count, amount}` |
| POST | `/{game_slug}/sessions/{id}/downtime-extensions` | `be_manage_apr` | Dá a um personagem um prazo de tempo livre mais tardio para esta noite: `character_id` e `until`. Substitui o prazo próprio da noite só para esse personagem |
| DELETE | `/{game_slug}/sessions/{id}/downtime-extensions/{character_id}` | `be_manage_apr` | Retira-o; o personagem volta ao prazo próprio da noite |
| GET | `/{game_slug}/sessions/{id}/reports` | `be_edit_own_characters` | Os relatórios pós-jogo da noite: todos para um gerente (`be_manage_plots` ou `be_manage_characters`), só os próprios de quem pede para qualquer outra pessoa. O texto `[ST]` é retirado de `did`, `wants` e `to_staff` para quem não é gerente |
| POST | `/{game_slug}/sessions/{id}/reports` | `be_edit_own_characters` | Arquiva um relatório para o personagem próprio de quem pede: `character_id`, com `did`, `wants` e `to_staff`. `403 ownership_denied`; `400 session_in_future` para uma noite depois de hoje; `409 reports_closed` depois que `reports_due_at` passou; `409 already_exists` quando o personagem já tem um para esta noite |
| PUT | `/{game_slug}/sessions/{id}/reports` | `be_edit_own_characters` | Muda-o, sob as mesmas regras |
| POST | `/{game_slug}/after-game-reports/{report_id}/read` | `be_manage_plots` OU `be_manage_characters` | Marca um relatório como lido |
| POST | `/{game_slug}/sessions/{id}/award-report-xp` | `be_manage_characters` | Concede XP a todo personagem com um relatório da noite. `amount` tem como padrão o `report_xp` da crônica (1); `409 already_awarded` depois de feito, a menos que `force` seja enviado. Responde `{awarded_count, amount}` |
| GET | `/{game_slug}/spotlight` | `be_manage_plots` OU `be_manage_characters` | A verificação de destaque: o perfil de atenção de todo personagem ativo que não é NPC, os sinalizados primeiro e depois os atendidos há menos tempo. Cada um: `{character_id, name, last_attended, active_plots, last_staff_post_at, last_report_at, flagged}`. Um personagem é sinalizado quando não teve nenhuma postagem da equipe, ou nenhuma dentro do `spotlight_days` da crônica |

## Facções e Cargos

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/factions` | `be_view_characters` | Toda facção que quem vê pode ver, pelo público dela. `is_member` diz se um dos personagens próprios de quem pede pertence. `goals` está presente só para um gerente ou um membro, e `audience_rules` só para um gerente |
| POST | `/{game_slug}/factions` | `be_manage_factions` | Cria uma: `name`, `faction_type` (texto livre; o editor sugere seita, clã, coterie, alcateia, chantry, corte, cabala, septo, grupo, casa, outro), `parent_id`, `description`, `goals`, `status` (`active` ou `disbanded`), `audience`, `audience_rules` |
| GET | `/{game_slug}/factions/{id}` | `be_view_characters` | Uma facção que quem vê pode ver |
| PUT | `/{game_slug}/factions/{id}` | `be_manage_factions` | Atualiza-a |
| DELETE | `/{game_slug}/factions/{id}` | `be_manage_factions` | Exclui-a e desliga os cargos dela |
| GET | `/{game_slug}/factions/{id}/members` | `be_view_characters` | A lista de membros, só para um membro da facção ou um gerente (`403 roster_denied`). Cada um: `{id, character_id, character_name, rank, is_leader, is_public, created_at}`; `is_public` é se a participação aparece no perfil público do personagem |
| GET | `/{game_slug}/factions/{id}/members/candidates` | `be_view_characters` | Um seletor só de nomes dos personagens que podem ser adicionados, `[{id, name}]` |
| POST | `/{game_slug}/factions/{id}/members` | `be_view_characters` | Adiciona um personagem: `character_id`. Um Narrador, ou um líder da facção (`403 ownership_denied`), que pode adicionar só personagens de jogador ativos (`400 invalid_candidate`). `409 already_member` |
| DELETE | `/{game_slug}/factions/{id}/members/{character_id}` | `be_view_characters` | Remove um membro. Um Narrador, ou um líder da facção, que não pode remover a si mesmo nem outro líder (`403 cannot_remove_leader`) |
| PATCH | `/{game_slug}/factions/{id}/members/{character_id}` | `be_view_characters` | Muda um membro: `rank`; `is_leader` e `is_public` são só de um Narrador. Uma facção mantém pelo menos um líder (`400 update_failed`). `404 member_not_found` |
| GET | `/{game_slug}/positions` | `be_view_characters` | Todo ofício que quem vê pode ver, `faction_id` restringindo a uma facção. Cada um: `{id, faction_id, title, since, audience, held, character_id, character_name, ...}`; `holder_public`, `audience_rules` e `notes` estão presentes só para um gerente |
| POST | `/{game_slug}/positions` | `be_manage_factions` | Cria um: `title`, `faction_id`, `character_id` (o detentor), `holder_public`, `audience`, `audience_rules`, `notes` |
| PUT | `/{game_slug}/positions/{id}` | `be_manage_factions` | Atualiza-o; uma mudança de detentor é gravada no histórico dele |
| DELETE | `/{game_slug}/positions/{id}` | `be_manage_factions` | Exclui-o e o histórico de detentores dele |
| GET | `/{game_slug}/positions/{id}/history` | `be_manage_factions` | Todo detentor em ordem, `[{id, character_id, character_name, started, ended}]` |
| GET | `/{game_slug}/position-presets` | `be_manage_factions` | As sugestões de título que o editor oferece, agrupadas, `{group: [title, ...]}` |

## Lotes de Lançamento

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/release-batches` | `be_manage_plots` | Os lotes da crônica, o mais novo primeiro; `status` (`draft`, `scheduled` ou `released`) restringe. Cada um: `{id, name, release_at, status, released_at, notified_at, rumor_count, entry_count, ...}` |
| POST | `/{game_slug}/release-batches` | `be_manage_plots` | Cria um: `name` e um `release_at` opcional. Com um horário é `scheduled`, sem um é um `draft` |
| PUT | `/{game_slug}/release-batches/{id}` | `be_manage_plots` | Muda `name`, `release_at` (vazio o limpa) ou `status` (`draft` ou `scheduled`). `409 batch_released` depois que ele saiu |
| DELETE | `/{game_slug}/release-batches/{id}` | `be_manage_plots` | Exclui um lote em rascunho ou agendado; os itens dele voltam a rascunho. `409 batch_released` |
| GET | `/{game_slug}/release-batches/{id}/items` | `be_manage_plots` | O que o lote guarda: `{rumors, entries, reveals}` |
| POST | `/{game_slug}/release-batches/{id}/items` | `be_manage_plots` | Adiciona um: `type` (`plot`, `entry` ou `reveal`) e `id`. O item fica retido, com o id deste lote nele |
| DELETE | `/{game_slug}/release-batches/{id}/items/{type}/{item_id}` | `be_manage_plots` | Tira um; ele volta a rascunho |
| POST | `/{game_slug}/release-batches/{id}/release-now` | `be_manage_plots` | Lança o lote agora. `409 already_out` |
| POST | `/{game_slug}/release-batches/release-now` | `be_manage_plots` | Faz um lote, o enche e o lança numa só chamada: `items`, uma lista de `{type, id}` (`plot` ou `entry`), e um `name` opcional |

## Escalações de NPC

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/castings` | `be_view_characters` | As escalações da noite: `session_id` é obrigatório (`400 invalid_request`, `404 session_not_found`). Um Narrador vê toda escalação dela, qualquer outra pessoa só as próprias |
| POST | `/{game_slug}/castings` | `be_manage_characters` | Escala um membro da crônica como um NPC para uma noite: `session_id`, `character_id`, `wp_user_id` e um `brief` opcional. `400 not_an_npc`, `400 not_a_member`, `409 already_cast` quando o NPC já tem uma escalação nessa noite |
| GET | `/{game_slug}/castings/my-upcoming` | `be_view_characters` | As escalações próprias de quem pede datadas de hoje em diante, com o nome do NPC e a data da noite |
| GET | `/{game_slug}/castings/members` | `be_manage_characters` | Os membros que podem ser escalados, `[{id, name, role}]` |
| PUT | `/{game_slug}/castings/{id}` | `be_manage_characters` | Muda o membro escalado (`wp_user_id`) ou o `brief` |
| DELETE | `/{game_slug}/castings/{id}` | `be_manage_characters` | Remove uma escalação |
| GET | `/{game_slug}/castings/{id}/brief` | `be_view_characters` | A ficha somente leitura: as seções resolvidas do NPC mais o texto de ficha próprio da escalação. Só o membro escalado ou um Narrador pode lê-la (`403 ownership_denied`), e o membro escalado só enquanto a janela de acesso da escalação está aberta |
| GET | `/{game_slug}/castings/{id}/brief.pdf` | `be_view_characters` | A mesma ficha como PDF, assinado como toda outra ficha |

## Fila de Tempo Livre e Minha Fila

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/downtime/queue` | `be_manage_plots` | Uma linha para a trama de ação de cada personagem numa data de jogo, as sem resposta primeiro. `game_date` é obrigatório (`400 invalid_param`). Cada uma: `{plot_id, character_id, character_name, player_id, player_name, action_count, last_action_at, answered, answer_release_state, window_state, assigned_to, connections: [{type, id, name}]}`. `answer_release_state` é `not_answered`, `immediate`, `draft`, `scheduled` ou `released`; `window_state` é `none`, `not_open`, `open` ou `closed`; `connections` são os outros personagens, NPCs, itens e locais ligados à trama |
| GET | `/{game_slug}/my/queue` | `be_manage_plots` OU `be_manage_characters` | O trabalho próprio de quem pede nesta crônica: `{downtime, plots, castings, unassigned}`. `downtime` são as tramas de ação sem resposta atribuídas a ele (em qualquer data de jogo), `plots` as tramas comuns atribuídas a ele cuja postagem mais nova de um jogador é mais nova que a postagem mais nova da equipe, `castings` as escalações dele para noites de hoje em diante e `unassigned` as contagens, `{downtime, plots}`, dos mesmos dois tipos que ninguém possui ainda |
| GET | `/{game_slug}/staff` | `be_manage_plots` OU `be_manage_characters` | Todo membro `hst`, `ast` e `narrator` da crônica, `[{id, name, role}]`: o seletor por trás de todo campo de responsável |

## Entrar em uma Crônica (para quem pede, autenticado mas ainda não membro)

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/joinable` | autenticado | Toda crônica deste site que aceita pedidos de entrada (`settings.join_requests`, ausente se lê como ligado) e de que quem pede ainda não é membro: `[{slug, name}]` |
| POST | `/{game_slug}/join` | autenticado | `message` (obrigatório, ≤1.000 caracteres). `403 join_requests_off` quando a crônica os tem desligados; `409 already_a_member`; `409 join_already_requested` quando um já está esperando; `429 too_many_requests` depois de a conta abrir três pedidos nesta crônica no último dia. Numa rede, adiciona a conta a este site como assinante quando ela ainda não tem papel aqui, antes de o pedido abrir. Envia e-mail a todo HST e AST uma vez. Devolve `201` com a nova linha |
| GET | `/{game_slug}/join` | autenticado | O pedido em espera próprio de quem pede nesta crônica, ou `null`: `{id, game_id, wp_user_id, message, character_id, submission_id, status, note, reviewed_by, reviewed_at, created_at}`. `message` tem o `[ST]...[/ST]` retirado igual a todo lugar onde quem não é gerente o lê |
| DELETE | `/{game_slug}/join` | autenticado | Retira o pedido em espera próprio de quem pede; exclui um personagem pendente começado para ele. `404 not_found` quando nenhum está esperando |

O ramo `joining` de `Characters_Controller::create_item()` e `Submissions_Controller::create_item()` ligam, os dois, um personagem ou arquivo novo a um pedido já em espera (`Join_Request::tie_character()`/`tie_submission()`) quando existe um, sem uma segunda notificação já que uma já saiu quando o pedido abriu; sem um pedido em espera, os dois recuam para o comportamento original e não modificado de pendente/notificar. A rota de resolver modelo de `Templates_Controller` e a rota de rascunho de cálculo de `Creation_Tally_Controller` admitem, as duas, qualquer conta autenticada numa crônica de verdade (`allow_bootstrap`), igual ao precedente da própria rota de criação, para quem pede poder montar um personagem antes de existir qualquer pedido.

## Status da Configuração

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| PUT | `/{game_slug}/chronicle-setup` | `be_manage_chronicle_setup` | As configurações que um HST pode mudar na própria crônica sem o `be_manage_games` completo: `enabled_stacks`, `enabled_factions` (um campo por vez), `require_new_character_approval`, `accent_color`, `purchase_scope`, `starting_xp`, `join_requests` e `secret_passing`. `purchase_scope` liga as listas de compra abertas a todo tipo de criatura: `abilities`, `backgrounds` e `merits_flaws`, cada uma verdadeira ou falsa. `starting_xp` é um número inteiro não negativo, ou uma string vazia para limpá-lo de volta a nenhum. `secret_passing` é um entre `off`, `approval` ou `immediate`. Uma escrita leva só as áreas que muda e as outras ficam como estavam; uma área desconhecida, ou um valor que não é claramente ligado ou desligado (ou, para `starting_xp`, não um número inteiro não negativo; para `secret_passing`, não um dos três valores), é um `400`. Mescla nas configurações guardadas da crônica. |
| GET | `/{game_slug}/setup-status` | `be_manage_characters` | As linhas da lista de conferência da Configuração da Crônica, calculadas ao vivo contra dados reais toda vez - nunca guardadas, então nada aqui fica desatualizado entre visitas. Cada linha tem `id`, `status` (`attention`, `ok` ou `info`), `title`, `detail`, `fix` e `actionable`; são dezoito, mais uma na crônica de demonstração. Uma linha opcional se lê `info` até a crônica ter definido algo próprio, depois `ok`; a linha Personalização do catálogo se lê `attention` enquanto a crônica tem correções do livro a revisar (veja Correções do Livro), e a linha Variantes do livro lista as variantes que ela escolheu (veja Variantes do Livro). `summary` dá `attention`, `ok`, `info` e `total`, as linhas que há a fazer (a linha da demonstração não conta). Só a equipe (um HST ou AST): um jogador é recusado. |

## Configurações de Autorização

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/authorization-settings` | `be_manage_games` | Se a conferência do accessSchema está ligada, e se um cliente real do accessSchema é detectado |
| PUT | `/authorization-settings` | `be_manage_games` | Liga ou desliga a conferência do accessSchema, em todo o site |

## Gerenciamento de Dados

De todo o site, não restrito a um jogo - vive na tela de administração Acesso à Crônica.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/data-management` | `be_manage_games` | Se desinstalar o plugin também exclui os dados dele |
| PUT | `/data-management` | `be_manage_games` | Liga ou desliga essa adesão |
| GET | `/data-management/export` | `be_manage_games` | Uma exportação JSON completa de toda tabela do plugin, para um backup ou uma migração |

## Créditos

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/credits` | `be_view_characters` | O texto de créditos de todo o site e a lista in memoriam |
| PUT | `/credits` | `be_manage_games` | Atualiza o texto dos créditos. `in_memoriam` no corpo é ignorado; a lista não tem caminho de escrita |

## Documentação

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/docs/{slug}` | `be_view_characters` | Serve um dos guias que vêm no próprio plugin (`st-guide`, `admin-guide`, `player-guide`, `rest-api` - este mesmo documento) como Markdown, `{ slug, content, language, fallback }`, para a tela Documentação dentro do plugin e o painel de ajuda. Quem tem como idioma o português (Brasil) recebe a tradução em `docs/pt_BR/` quando há uma: `language` é `pt_BR` e `fallback` é `false`; sem uma, o original em inglês volta com `language` `en` e `fallback` `true`. Qualquer outra pessoa recebe inglês, `fallback` `false` |
| GET | `/docs/help/{key}` | `be_view_characters` | A página de ajuda de uma tela, `docs/help/{key}.md`, como Markdown: `{ key, content, language, fallback }` - o que o `?` de uma tela abre no painel de ajuda, no idioma de quem vê do mesmo jeito que `/docs/{slug}`. `404` para uma chave que não nomeia nenhuma página em inglês |

## Estatísticas do Jogo

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/stats` | `be_manage_characters` | Os números agregados do painel do Narrador - contagens de personagens, mudanças pendentes, tramas ativas (sem contar a trama própria de cada personagem), atividade recente e `players_without_active_character` (só uma contagem; veja a rota de detalhe abaixo). Em cache por um minuto; uma ação de revisão invalida o cache da própria crônica na hora |
| GET | `/{game_slug}/stats/players-without-active-character` | `be_manage_characters` | A lista de jogadores de verdade por trás dessa contagem - todo membro com papel de jogador com zero personagens `active` (nenhum personagem, ou só um aposentado/morto/pendente - a mesma condição), resolvido para um nome de exibição. Não fica em cache; buscada só quando o cartão de saúde do elenco do painel é aberto |

## Fichas (PDF da ficha de personagem, assinado quando o site tem um certificado)

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/sheets/pdf` | `be_view_characters` | Devolve os bytes do PDF de um ou mais personagens (`character_ids`, separados por vírgula, no máximo 50). Um gerente pode pedir qualquer personagem da crônica; quem não é gerente só o próprio - um id negado ou faltando falha o pedido inteiro, e também um cujo tipo de criatura já não existe (`404 creature_stack_not_found`, nomeando o personagem). Assinado quando o site tem um certificado de assinatura; sem um o PDF é carimbado com NÃO ASSINADO em toda página e o nome do arquivo termina em `-unsigned.pdf`. Opcionais `full_power_names`, `background`, `notes`, `xp_history`, `show_cost` (padrão ligado) e `page_size` (`letter` ou `a4`; omitido, o idioma do site decide: Carta para um locale dos EUA, do Canadá, do México ou das Filipinas, A4 em caso contrário; qualquer outra coisa é um 400) |
| GET | `/{game_slug}/sheets/availability` | `be_view_characters` | Pré-conferência: a assinatura está configurada neste site agora (`ok: false` quer dizer que as impressões saem sem assinatura) |

## Verificar

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/verify/{code}` | nenhuma (pública por desenho) | Confirma que o código de atestação de um documento exportado/impresso é real e inalterado - não restrito a um jogo, e de propósito alcançável por qualquer um com o link, não só membros da crônica. Sustenta a conferência voltada a pessoas da página `be-verify` |

## Relatórios (os 20 relatórios GV301-plus, cartões e saída em lote)

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/reports` | `be_view_reports` | Lista os relatórios que quem pede pode executar nesta crônica: chave, título, forma, entidade. Cada relatório também precisa da própria capacidade - relatórios de personagens e jogadores `be_manage_characters`, de trama/ação/rumor `be_manage_plots`; os cartões de item/local/ritual, o Calendário do Jogo e as Regras da Casa não precisam de mais nada. Executar um relatório sem ela é `403 report_forbidden` |
| GET | `/{game_slug}/reports/{report_key}` | `be_view_reports` | Devolve o relatório resolvido como JSON simples - sem assinatura, sem PDF. Feito para uma visão ao vivo do front-end (o próprio widget/shortcode de Regras da Casa o usa); funciona para qualquer relatório do registro, não só Regras da Casa |
| GET | `/{game_slug}/reports/{report_key}/pdf` | `be_view_reports` | Devolve os bytes do PDF de um relatório, assinado ou carimbado com NÃO ASSINADO exatamente como uma ficha. `conditions`/`logic` restringem um relatório `table`/`card` do mesmo jeito que o construtor de consultas (um `conditions` vazio quer dizer todos no escopo); `stat_field`/`stat_type` parametrizam o Statistics Report genérico; `character_id` (nesta rota e na forma JSON acima) restringe um relatório de forma `card` (por exemplo `item-cards`) só aos objetos do mundo conectados a esse único personagem - um gerente pode passar qualquer personagem da crônica, quem não é gerente só o próprio (`404 character_not_found` para um jogo que não combina, `403 ownership_denied` para o personagem de outra pessoa). `object_id` (nesta rota e na forma JSON acima) restringe um relatório de forma `card` (`item-cards`, `location-cards`) ao único objeto do mundo com esse id; nunca amplia o que quem pede pode ver, então quem não é gerente ainda só recebe um objeto que o público dos seus personagens alcança. `404 report_not_found` para uma chave desconhecida |
| GET | `/{game_slug}/reports/availability` | `be_view_reports` | Quais relatórios de cartão quem pede pode executar para um personagem (`character_id`), `{report_key: bool}`. Um Narrador pode executar todos; qualquer outra pessoa pode executar um relatório de cartão que precisa de um bloco detentor (os Cartões de Ritual precisam de um bloco que guarda rituais) só para um personagem cujo tipo de criatura o tem |

## Auditoria de Pontos

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/{game_slug}/characters/{id}/point-audit` | `be_manage_characters` | A auditoria de pontos detalhada de um personagem - toda linha mantida, precificada ou marcada explicitamente sem preço com um motivo legível por máquina. Nunca `be_view_characters`/`be_edit_own_characters`: um total geral calculado em cima de um bloco só para Narradores vazaria os valores guardados dele aritmeticamente, então quem não é gerente recebe `403`, nunca um total reduzido. Um personagem cujo tipo de criatura já não existe é `404 creature_stack_not_found`. O `modifier` da linha de um poder em níveis é quanto um modificador de posto mudou o custo dela, com sinal, e `modifier_side` diz qual: `in_type` ou `out_of_type`; os dois são `null` numa linha que nenhum modificador mudou. `complete` é sempre `false` - isto não é uma conta, veja [st-guide.md](st-guide.md) |

## Cálculo da Construção

O cálculo da construção de um personagem em andamento contra as `creation_rules` declaradas do tipo de criatura dele: o que cada passo cobre, o saldo próprio de cada reserva, toda sinalização de limite e o que sobra para o XP. Um guia, nunca uma trava - nada aqui bloqueia um salvamento.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| POST | `/{game_slug}/creation-tally` | `be_edit_own_characters` ou `be_manage_characters` | Calcula uma construção em rascunho que ainda não está salva. Corpo: `stack_slug` (obrigatório) e `sheet_data` (a ficha em rascunho em andamento; o padrão é vazio). `400 invalid_param` para um `stack_slug` faltando, um que não resolve para uma pilha de criatura de verdade, ou um `sheet_data` que não é um objeto |
| GET | `/{game_slug}/characters/{id}/creation-tally` | `be_manage_characters` | O mesmo cálculo lido da ficha salva própria de um personagem pendente, para um Narrador que revisa uma construção nova. `404 character_not_found` para um personagem de outra crônica; `404 creature_stack_not_found` para um cujo tipo de criatura já não existe |

As duas rotas devolvem a mesma forma, do único motor `Services\Creation_Tally`, na ordem de passos do livro: `steps` - uma entrada por passo declarado, cada uma trazendo o próprio `kind`, `label` e `applies`, mais o que esse tipo relata (`prioritized`/`budget` listam o `used` de cada seção coberta contra o `allowed` e `over`; `budget` também lista as suas `quotas`, cada uma `met` contra `min` e `ok`; `earned`/`free` dão os `points`/`spent` da reserva; `limit` dá os próprios `flags`; `grant`/`start` dão o que resolveram). `pools` - o `own`, `earned`, `spent` e `left` de toda reserva nomeada. `limits` - as sinalizações de todo passo `limit`, mescladas (`target`, `reason` - `max_points`, `max_rating`, `min_rating` ou `ceiling` - e o valor que a disparou). `grants_missing` - as entradas de um passo de concessão que a ficha ainda não tem. `xp` - `starting`, `needed` (o que nenhum orçamento, reserva ou concessão cobriu, precificado aos preços de compra do próprio `Cost_Engine`) e `left`, que fica negativo numa construção estourada sem nada impedindo.

## Traduções (tradução de termos do catálogo)

De todo o site, não restrito a uma crônica - uma instalação, um idioma. Toda rota exige `be_manage_translations`, concedida independentemente de `be_manage_schemas`. `locale` em toda rota abaixo é um código de locale simples (por exemplo `pt_BR`), não validado contra a lista de idiomas instalados do próprio WordPress - uma crônica num idioma sem tradução do núcleo do WordPress instalada ainda pode ser trabalhada aqui.

| Método | Caminho | Capacidade | Notas |
|---|---|---|---|
| GET | `/translations` | `be_manage_translations` | Uma página de termos do catálogo para `?locale=`, unidos à esquerda com a tradução deles. Filtros: `status` (`untranslated` ou um valor real de `Translation::STATUSES`), `block`, `search` (trecho do termo em inglês), `has_translation` (`1`/`0`). Paginada, `per_page` limitado a 500 (o próprio aviso D38/D52 do §6 contra um truncamento por falta de padrão, contra 8.298 linhas) |
| GET | `/translations/progress` | `be_manage_translations` | Totais por locale, uma divisão por status e uma divisão por bloco (total de termos e quantos estão traduzidos) - a barra de progresso e a linha de status da tela Traduções |
| GET | `/translations/locales` | `be_manage_translations` | Os locales que já têm pelo menos uma linha de tradução de verdade (`with_rows`), mais todo locale que o próprio WordPress tem instalado (`installed`, `en_US` sempre primeiro) - as sugestões próprias do seletor de idioma |
| POST | `/translations` | `be_manage_translations` | Cria ou substitui a tradução de um termo para um locale. Aceita `string_id` ou `source_text` - nomear um termo que o `rescan()` ainda não indexou cria a linha de texto dele em vez de dar 404. Corpo: `locale`, `translation`, `status` (padrão `draft`) e um entre `string_id`/`source_text` |
| PATCH | `/translations/{id}` | `be_manage_translations` | Atualiza o `translation` e/ou o `status` próprios de uma linha de tradução existente. `404 not_found` para um id desconhecido |
| DELETE | `/translations/{id}` | `be_manage_translations` | Limpa uma linha de tradução por completo (não o termo do catálogo em si, que continua indexado para uma tradução futura) |
| POST | `/translations/bulk` | `be_manage_translations` | Define muitas linhas de uma vez por `source_text`, casando `{ locale, rows: [{source_text, translation, status}] }` - sustenta "marcar selecionados como aprovados". Uma linha ruim é pulada e contada, nunca aborta o lote. Devolve `{updated, skipped}` |
| GET | `/translations/export` | `be_manage_translations` | Baixa um CSV que respeita os mesmos filtros que `GET /translations` aceita, `text/csv` com um BOM UTF-8: colunas `source_text`, `translation`, `status` |
| POST | `/translations/import-csv` | `be_manage_translations` | Envio multipart de um CSV na forma da exportação. `dry_run=1` relata `{added, updated, unchanged, unmatched, conflicts, sample}` sem gravar nada - um conflito é o arquivo discordando de si mesmo (duas linhas para o mesmo termo com duas traduções diferentes), não o arquivo discordando do que já está salvo, o que é uma atualização comum |
| POST | `/translations/rescan` | `be_manage_translations` | Percorre de novo o catálogo real e atual e atualiza o índice de termos contra ele - todo bloco de esquema, do sistema e toda bifurcação de crônica. Devolve `{added, updated, orphaned}` |
