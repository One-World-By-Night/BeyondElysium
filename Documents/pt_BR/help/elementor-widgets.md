# Widgets do Elementor

O Beyond Elysium adiciona doze widgets do Elementor, agrupados na categoria própria "Beyond Elysium", que colocam as suas ferramentas do front-end em qualquer página que você monte - mais um shortcode para uma página que não é montada com o Elementor.

## Quem pode usar

Quem monta as páginas deste site - normalmente um administrador do site ou outro Editor do WordPress. Colocar um widget usa as permissões de edição de página do próprio Elementor; o Beyond Elysium não acrescenta nem muda quem pode fazer isso. Depois que um widget está numa página, o que um *visitante* pode fazer com ele é outra questão, decidida quando a página carrega - veja O que saber abaixo.

## Como chegar lá

Abra qualquer página no editor do Elementor. Todo widget do Beyond Elysium vive na própria categoria **Beyond Elysium** do painel de widgets, ao lado dos embutidos do próprio Elementor.

## A tela

Todo widget tem a mesma forma: arraste-o para a página e preencha a seção **Conteúdo** dele no painel à esquerda. Todos têm um campo **Slug do Jogo** - a crônica que ele mostra - e a maioria não tem mais nada.

| Widget | O que mostra | Configurações extras | Documento completo |
| --- | --- | --- | --- |
| Ficha de Personagem | Uma ficha de personagem somente leitura e imprimível. | ID do Personagem (0 lê `?character_id=` do endereço da própria página); Tipo de Modelo (Ficha Completa / Ficha Compacta / Ficha para Celular) | [Ficha de Personagem](character-sheet.md) |
| Lista de Personagens | A lista de personagens desta crônica, com busca e filtros. | Tipo de Criatura; Filtro de Status Padrão; URL da Página da Ficha (liga cada nome à sua página de Ficha do Personagem; em branco, os nomes aparecem como texto simples); Por Página | [Personagens](character-list.md) |
| Editor de Personagem | O formulário voltado a jogadores/Narradores para criar e editar uma ficha de personagem. | ID do Personagem (0 lê `?character_id=`; ainda 0 depois disso abre o modo de criação); Slug da Pilha de Criatura (fixa o modo de criação em um tipo de criatura; em branco deixa o jogador escolher) | [Editor de Personagem](character-editor.md) |
| Fila de Aprovação | As mudanças pendentes de jogadores para os Narradores aprovarem ou rejeitarem, em todo personagem da crônica. | nenhuma | [Fila de Aprovação](approval-queue.md) |
| Gerenciador de Tramas | O Kit de Ferramentas do Narrador para criar e conduzir tramas, ações e rumores. | Filtro de Status Padrão (Todos / Ativo / Resolvido / Arquivado) | [Tramas e Rumores](plot-manager.md) |
| Minhas Tramas | Um feed das tramas e rumores a que um jogador está ligado. | nenhuma | [Minhas Tramas e Rumores](my-plots.md) |
| Ferramenta de Consulta | Monta e executa consultas salvas contra esta crônica, mais estatísticas do elenco. | nenhuma | [Ferramenta de Consulta](query-tool.md) |
| Objetos do Mundo | Gerencia itens, locais, rituais e favores da crônica. | Tipo Padrão (Itens / Locais / Rituais); Mostrar Controles de Criar/Editar (esconde da vista os botões de adicionar/editar) | [Itens e Locais](world-objects.md) |
| Registro de Favores | Um registro transacional de favores devidos e pagos entre personagens. | ID do Personagem (0 mostra o registro da crônica inteira; um ID específico restringe a esse personagem) | [Registro de Favores](boon-ledger.md) |
| Ferramenta de Importação | Importa para a crônica um arquivo de intercâmbio (.gex) de personagem ou jogo do Grapevine. | nenhuma | [Importar](import.md) |
| Painel do Jogo | A página de entrada de uma crônica - os Narradores veem estatísticas agregadas e atividade recente, os jogadores veem os próprios personagens, mudanças pendentes e tramas. | URL da Página da Ficha; URL da Página da Fila de Aprovação; URL da Página da Lista; URL da Página de Tramas (atalhos do Narrador - em branco omite o link) | [Painel do Jogo](game-dashboard.md) |
| Regras da Casa | Toda nota de regra da casa definida no catálogo desta crônica. | nenhuma | [Regras da Casa](house-rules.md) |

### `[be_house_rules]` (shortcode)

Para uma página que não é montada com o Elementor. `[be_house_rules game="chronicle-slug"]` renderiza a mesma visão ao vivo de Regras da Casa que o widget. Aceita um atributo, `game` - o slug da crônica - e não tem painel de configurações, já que é texto simples digitado no conteúdo da página ou postagem.

## Tarefas comuns

### Adicionar a lista de personagens de uma crônica a uma página

1. Abra a página no editor do Elementor.
2. Arraste **Lista de Personagens**, da categoria **Beyond Elysium**, para a página.
3. Defina **Slug do Jogo** como o slug dessa crônica.
4. Se quiser, defina **Tipo de Criatura**, **Filtro de Status Padrão**, **URL da Página da Ficha** e **Por Página**.

### Ligar uma lista a uma página de ficha

1. Monte uma página à parte com um widget **Ficha de Personagem**, com ID do Personagem deixado em 0.
2. No widget **Lista de Personagens**, defina **URL da Página da Ficha** como o endereço dessa página.
3. Os nomes dos personagens na lista viram links; clicar em um abre essa página com o personagem certo já escolhido.

### Mostrar as Regras da Casa de uma crônica sem o Elementor

1. Edite a página ou postagem no editor comum do WordPress.
2. Adicione um bloco Shortcode (ou digite direto, no Editor Clássico) com `[be_house_rules game="chronicle-slug"]`.

### Achar o slug de uma crônica

1. wp-admin → Configuração do Sistema → Jogos. Veja [Jogos](games.md).

## O que saber

- **Todo widget precisa de um Slug do Jogo de verdade.** Erre ou deixe em branco e o widget não tem nada a mostrar - ache o slug de uma crônica em [Jogos](games.md).
- **Um widget fica fixo numa crônica na hora de montar a página.** Isso difere das páginas próprias do plugin, Minha Crônica e Kit de Ferramentas do Narrador, que deixam um visitante trocar entre todas as crônicas a que pertence. Para oferecer mais de uma crônica pelo Elementor, monte uma página (ou uma seção) por crônica, cada uma com a sua própria instância do widget e o seu próprio Slug do Jogo.
- **Colocar um widget não dá acesso a ninguém.** As rotas de cada widget aplicam as próprias conferências de permissão quando a página carrega, exatamente como dentro das páginas próprias do plugin - pôr a Fila de Aprovação numa página não deixa um jogador usá-la, só dá a todos um lugar para tentar. Veja o "Quem pode usar" de cada widget para saber quem vê o quê.
- **Os campos ID do Personagem recorrem a um parâmetro da URL.** A Ficha de Personagem, o Editor de Personagem e o Registro de Favores tratam, todos, o ID do Personagem `0` como "ler `?character_id=` do endereço da própria página" - útil quando a URL da Página da Ficha leva à mesma página para todo personagem.
- **O ID do Personagem do Editor de Personagem que continua 0 sem parâmetro na URL também é modo de criação**, não um erro - o widget então mostra um formulário em branco para começar um personagem novo.
- **Mostrar Controles de Criar/Editar, em Objetos do Mundo, é só cosmético.** Desligá-lo esconde da vista os botões de adicionar/editar - não impede nem pode impedir as rotas de fundo de conferir quem pode gravar, então escondê-lo de uma página que um jogador pode ver é uma cortesia, não uma medida de segurança.
- **Estes widgets não substituem as quatro páginas próprias do plugin.** O Beyond Elysium já cria automaticamente Minha Crônica, Kit de Ferramentas do Narrador, Ficha de Personagem (Impressão) e Verificar Personagem - use estes widgets para somar as mesmas ferramentas a uma página de desenho seu, ou para mostrar uma ferramenta sozinha numa página. Veja [Páginas do Front-End](provisioned-pages.md).

## Solução de problemas

- **Um widget não mostra nada, ou mostra um erro.** Confira se o Slug do Jogo é o slug de uma crônica de verdade, escrito exatamente como aparece em [Jogos](games.md).
- **Um widget não mostra nada de útil para um visitante de verdade.** Isso é a conferência de papel da própria ferramenta funcionando como projetada - veja o documento de ajuda do próprio widget (ligado na tabela acima) para saber quem pode usá-lo e o que um jogador vê no lugar.
- **Os nomes dos personagens na Lista de Personagens não são links.** A URL da Página da Ficha está em branco - defina-a como a página que leva o seu widget de Ficha de Personagem.
- **Clicar no nome de um personagem não mostra o personagem certo.** Confirme que o widget de Ficha de Personagem na página de destino tem o ID do Personagem em 0, para ler `?character_id=` do endereço que a Lista de Personagens monta - um ID do Personagem diferente de zero ali sempre mostra esse único personagem, seja qual for o nome em que se clicou.
- **O shortcode `[be_house_rules]` não mostra nada.** Confira se o atributo `game="..."` é o slug de uma crônica de verdade.

## Relacionados

- [Ficha de Personagem](character-sheet.md)
- [Personagens](character-list.md)
- [Editor de Personagem](character-editor.md)
- [Fila de Aprovação](approval-queue.md)
- [Tramas e Rumores](plot-manager.md)
- [Minhas Tramas e Rumores](my-plots.md)
- [Ferramenta de Consulta](query-tool.md)
- [Itens e Locais](world-objects.md)
- [Registro de Favores](boon-ledger.md)
- [Importar](import.md)
- [Painel do Jogo](game-dashboard.md)
- [Regras da Casa](house-rules.md)
- [Jogos](games.md)
- [Páginas do Front-End](provisioned-pages.md)
- [Painel do Administrador](admin-dashboard.md)
