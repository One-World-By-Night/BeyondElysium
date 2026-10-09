# Painel do Administrador

A página de entrada do menu wp-admin do Beyond Elysium: o que cada uma das outras páginas faz, onde vivem as páginas do front-end e os widgets do Elementor e um empurrão para criar a sua primeira crônica se ainda não existe nenhuma.

## Quem pode usar

Qualquer pessoa que alcança o wp-admin. Esta página só confere se você pode ver personagens, afinal - uma capacidade que todo papel básico do WordPress tem por padrão, até um Assinante comum - então não é protegida do jeito que as páginas que ela descreve são. Na prática, só os Narradores e um administrador do site têm motivo para estar aqui: um jogador comum vê o item de menu "Beyond Elysium" e esta página, mas não a maioria das linhas para as quais ela leva, já que cada uma exige o seu próprio papel mais restrito.

## Como chegar lá

Barra lateral do wp-admin → **Beyond Elysium**. O clique de nível superior em si cai aqui - ela também é listada uma segunda vez embaixo como **Painel**, a primeira linha.

## A tela

- Uma descrição curta do plugin.
- **Crie sua primeira crônica** - mostrada só enquanto toda crônica da instalação ainda é a demonstração semeada. Leva a **Vá para Configuração do Sistema → Jogos**.
- **O que está onde** - uma tabela que nomeia cada uma das outras páginas do menu (Personagens, Tramas, Itens e Locais, Ferramenta de Consulta, Importar, Configuração da Crônica, Configuração do Sistema, Documentação) e para que serve.
- **Páginas voltadas para Jogadores e Narradores** - uma segunda tabela para as duas coisas que vivem no front-end e não no wp-admin: **Painel do Jogo** (estatísticas do elenco e saúde do elenco, tanto no Kit de Ferramentas do Narrador quanto em Minha Crônica) e **Notificações** (a chave por crônica em Configuração da Crônica → Acesso à Crônica, mais a opção de sair de cada jogador na própria página de Perfil do WordPress).
- **Widgets e shortcodes** - todo widget do Elementor que este plugin adiciona (Ficha de Personagem, Lista de Personagens, Editor de Personagem, Fila de Aprovação, Gerenciador de Tramas, Minhas Tramas, Ferramenta de Consulta, Objetos do Mundo, Registro de Favores, Ferramenta de Importação, Painel do Jogo, Regras da Casa), mais o único shortcode que este plugin tem: `[be_house_rules game="chronicle-slug"]`.

## Tarefas comuns

### Descobrir onde algo vive

1. Abra **Beyond Elysium** (ou **Beyond Elysium → Painel**).
2. Leia **O que está onde** para uma página do wp-admin, ou **Páginas voltadas para Jogadores e Narradores** para algo no front-end.

### Começar a sua primeira crônica

1. Abra **Beyond Elysium**. Se **Crie sua primeira crônica** está aparecendo, clique em **Vá para Configuração do Sistema → Jogos**.
2. Criar uma crônica em si exige uma conta de administrador do site - veja [Jogos](games.md).

### Adicionar um widget a uma página

1. Confira **Widgets e shortcodes** para o que você quer.
2. Adicione-o a uma página no Elementor, ou digite o shortcode `[be_house_rules game="..."]` direto onde quer uma página de Regras da Casa ao vivo.

## O que saber

- **Esta página não confere filiação à crônica de jeito nenhum** - só descreve onde as coisas vivem e leva adiante a páginas protegidas individualmente. Conseguir vê-la não prova nada sobre o que você pode de fato fazer nas páginas que ela nomeia.
- **A chamada para ação some no instante em que existe uma segunda crônica de verdade.** Não é um item permanente, e nunca reaparece depois que a sua instalação passou da demonstração.
- **Quais das outras linhas da barra lateral você pode de fato clicar depende do seu papel.** Um jogador comum vê esta página e a Documentação; um Condutor de Trama soma Tramas; um HST ou AST vê tudo, exceto Jogos, Acesso à Crônica e qualquer coisa restrita a um administrador do site.
- Toda página de administração do Beyond Elysium, esta inclusive, traz uma pequena linha de homenagem no fim: "In Memory of Arielle 'XP Day' M."

## Solução de problemas

- **Só vejo "Painel" e "Documentação" na barra lateral.** Isso é esperado para um jogador comum - toda outra linha exige um papel de Narrador em pelo menos uma crônica, ou uma conta de administrador do site.
- **"Crie sua primeira crônica" não some.** Ela só se esconde quando existe uma crônica além da demonstração semeada - confira **Configuração do Sistema → Jogos**.
- **Um widget que adicionei a uma página não mostra nada.** A maioria dos widgets ainda precisa de uma crônica escolhida nas próprias configurações, ou de o visitante ter o papel correspondente nela - confira o documento de ajuda do próprio widget.

## Relacionados

- [Personagens do Administrador](admin-characters.md)
- [Kit de Ferramentas do Narrador](storyteller-toolkit.md)
- [Minha Crônica](my-chronicle.md)
- [Regras da Casa](house-rules.md)
- [Papéis](roles.md)
- [Guia do Administrador](../admin-guide.md#o-menu-do-wp-admin)
