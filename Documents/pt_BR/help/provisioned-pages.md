# Páginas do Front-End

As quatro páginas que o Beyond Elysium cria automaticamente na primeira vez que roda: Minha Crônica, Kit de Ferramentas do Narrador, Ficha de Personagem (Impressão) e Verificar Personagem.

## Quem pode usar

Ler qualquer uma das quatro páginas é irrestrito, igual a qualquer outra página do WordPress - a ferramenta de front-end de cada página confere quem pode fazer o quê depois que carrega, não a página em si. Recriar uma página que falta exige um administrador do site: a linha **Páginas do front-end** da lista de conferência da Configuração da Crônica é visível a qualquer Narrador que abra a [Configuração da Crônica](chronicle-setup.md), mas a correção dela só vale para o papel Administrador do site.

## Como chegar lá

wp-admin → **Páginas** lista as quatro ao lado do conteúdo comum do seu site, cada uma com o seu slug fixo:

| Página | Slug |
| --- | --- |
| Minha Crônica | `be-player` |
| Kit de Ferramentas do Narrador | `be-storyteller` |
| Ficha de Personagem (Impressão) | `character-sheet-print` |
| Verificar Personagem | `be-verify` |

Para conferir se as quatro ainda existem, ou recriar uma que falta: wp-admin → Beyond Elysium → Configuração da Crônica → aba Configuração da Crônica → a linha **Páginas do front-end**.

## A tela

Estas não são telas próprias do Beyond Elysium - cada uma é uma página comum do WordPress com uma linha de conteúdo: um único ponto de montagem para uma das ferramentas de front-end do plugin.

- **Minha Crônica** (`be-player`) e **Kit de Ferramentas do Narrador** (`be-storyteller`) trazem, cada uma, uma ferramenta em abas que troca de crônica. Veja [Minha Crônica](my-chronicle.md) e [Kit de Ferramentas do Narrador](storyteller-toolkit.md).
- **Ficha de Personagem (Impressão)** (`character-sheet-print`) renderiza a ficha de um único personagem sem nada do cabeçalho, rodapé nem barra de administração do seu tema ao redor. Visitada com um personagem e uma crônica nomeados no próprio endereço e `print=1` acrescentado, ela abre o diálogo de impressão do navegador automaticamente depois que a ficha carregou - uma impressão simples do navegador, separada do PDF assinado que a própria ação **Imprimir / Exportar** da tela Ficha abre direto. Veja [Imprimir / Exportar](sheet-print-export.md).
- **Verificar Personagem** (`be-verify`) é a página pública para a qual o link próprio de um código de verificação aponta. Veja [Verificar Personagem](verify.md).

Cada uma das quatro é criada uma só vez - na primeira vez que este plugin roda, ou na primeira vez que ele atualiza para além de uma versão que ainda não tinha uma - e nunca é sobrescrita nem recriada depois que existe uma página naquele slug.

## Tarefas comuns

### Achar onde uma página está ligada, ou adicionar uma ao seu menu

1. wp-admin → **Páginas**, e procure **Minha Crônica**, **Kit de Ferramentas do Narrador**, **Ficha de Personagem (Impressão)** ou **Verificar Personagem**.
2. Adicione as que os seus visitantes precisam ao menu de navegação do seu site - o plugin cria as páginas mas não adiciona nenhuma delas a um menu.

### Recriar uma página que foi excluída

1. wp-admin → Beyond Elysium → Configuração da Crônica → aba Configuração da Crônica.
2. Ache a linha **Páginas do front-end** - ela diz "Precisa de atenção" e nomeia qual página falta.
3. Clique em **Ir**.

### Renomear uma página sem quebrá-la

1. Mude o **Título** da página à vontade, em wp-admin → Páginas.
2. Deixe o **Slug** exatamente como foi criado - os links próprios do plugin e os códigos gerados apontam para os slugs fixos da tabela acima, não para o título nem para a posição no menu.

## O que saber

- **Uma página só é criada se nada já existe naquele slug.** Se o seu site já tem uma página própria em, digamos, `be-verify`, de antes de este plugin ser instalado, o Beyond Elysium a deixa em paz em vez de sobrescrevê-la - o que também quer dizer que Verificar Personagem não funcionará até esse slug ficar livre ou a página em conflito ser movida.
- **Nenhuma das quatro fixa uma crônica específica.** Minha Crônica e o Kit de Ferramentas do Narrador leem a crônica que um visitante escolhe na própria lista suspensa; a Ficha de Personagem (Impressão) e Verificar Personagem leem o personagem, a crônica ou o código direto do endereço web da página. Você nunca vai precisar de uma cópia à parte de nenhuma das quatro por crônica.
- **O slug mostrado acima é a identidade da página para o plugin**, visível como o nome da postagem em wp-admin → Páginas. Com o ajuste comum de link permanente "Nome da postagem" e sem página pai, esse também é o endereço da página no front-end (por exemplo, `/be-player/`) - uma estrutura de link permanente diferente, ou mover a página para baixo de um pai na hierarquia, muda o endereço sem mudar o slug que o plugin procura.
- **A Ficha de Personagem (Impressão) não tem ações nem navegação, de propósito** - esse é o ponto, para nada além da própria ficha aparecer quando ela é impressa.
- **Excluir uma destas páginas não exclui nada do que ela exibe.** Só derruba esse ponto de entrada - personagens, crônicas e códigos de verificação ficam intocados, e a lista de conferência da Configuração da Crônica sinaliza a página como faltando até ela ser recriada.
- **Renomear uma crônica não toca estas páginas de jeito nenhum** - nenhuma das quatro tem o slug de uma crônica embutido no próprio endereço, só nos links que os visitantes seguem para chegar a uma crônica específica depois de lá dentro.

## Solução de problemas

- **Uma página de que preciso não está em wp-admin → Páginas de jeito nenhum.** Ela pode não ter sido criada ainda, se esta instalação é anterior à página que se procura - abra a Configuração da Crônica e use o botão **Ir** da linha **Páginas do front-end** para criá-la.
- **Verificar Personagem (ou Ficha de Personagem Impressão) não mostra nada nesse endereço.** Outra coisa já ocupa esse exato slug - procure outra página em `be-verify` ou `character-sheet-print` e mova-a se precisa da página própria do Beyond Elysium ali.
- **Movi ou renomeei uma página e agora um link de dentro do plugin está quebrado.** Renomear o título da página é seguro; mudar o slug dela não é - veja O que saber acima.
- **A linha Páginas do front-end da Configuração da Crônica ainda diz que algo falta depois que cliquei em Ir.** Recarregue a lista de conferência - ela reconfere ao vivo a cada carregamento, então uma visão desatualizada ainda pode mostrar o status antigo por um instante.

## Relacionados

- [Minha Crônica](my-chronicle.md)
- [Kit de Ferramentas do Narrador](storyteller-toolkit.md)
- [Verificar Personagem](verify.md)
- [Imprimir / Exportar](sheet-print-export.md)
- [Configuração da Crônica](chronicle-setup.md)
- [Widgets do Elementor](elementor-widgets.md)
- [Jogos](games.md)
