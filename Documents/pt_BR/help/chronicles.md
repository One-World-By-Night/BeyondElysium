# Crônicas

Uma crônica é o único recipiente a que todo o resto pertence - todo personagem, trama e personalização de catálogo é restrito a uma crônica e nunca visível a partir de outra.

## Quem pode usar

Todos pertencem a uma crônica, ou podem ser levados a uma, em algum nível. Criar, renomear ou excluir uma é trabalho só de um administrador do site, em [Jogos](games.md). Todo o resto - filiação, configuração do dia a dia e jogo comum - é restrito à crônica que você está olhando, conforme o seu papel ali.

## Como chegar lá

Não há uma tela única - uma crônica é o que você escolhe no topo de [Minha Crônica](my-chronicle.md), do [Kit de Ferramentas do Narrador](storyteller-toolkit.md) e da maioria das telas do wp-admin voltadas ao Narrador, por uma lista suspensa **Crônica** (ou **Jogo**).

## A tela

**Trocar de crônica.** Minha Crônica e o Kit de Ferramentas do Narrador trazem, cada um, uma lista suspensa **Crônica** no topo, que lista toda crônica a que você pertence, marcada com o seu papel em cada uma. Escolher outra recarrega tudo abaixo dela - seus personagens, as abas que você vê, tudo - para essa crônica. As telas do wp-admin que funcionam entre crônicas (Personagens, Itens e Locais, Ferramenta de Consulta, Importar, Configuração da Crônica e outras) trazem um seletor parecido, geralmente rotulado **Jogo**, que lista toda crônica da instalação e não só as que você pertence.

**O que é compartilhado entre todas as crônicas:**

- A sua conta do WordPress e os papéis que você tem - um papel é definido por crônica, mas a conta em si é a mesma em todo lugar.
- O catálogo base: os blocos de esquema e modelos do próprio Beyond Elysium, até uma crônica bifurcar a sua própria cópia.
- As pilhas de criatura - quais tipos de criatura existem, afinal, e de que a ficha de cada um é montada. Elas nunca são por crônica; uma crônica só restringe quais delas oferece, em [Configuração da Crônica](chronicle-setup.md).
- As configurações de todo o site: se o accessSchema é usado, o provedor de Assistência de IA usado por padrão e se desinstalar o plugin exclui os dados dele.

**O que é restrito a uma crônica:**

- Todo personagem, trama, item, local e ritual.
- Filiação e papéis - quem é HST, AST, Condutor de Trama, Favores ou Jogador aqui especificamente.
- A bifurcação própria de uma crônica de um bloco de esquema ou modelo, depois que ela faz uma.
- Quais tipos de criatura são oferecidos, e qualquer restrição de subfacção dentro de um (Vampiro sim, mas sem Sabbat, por exemplo).
- Regras de aprovação, configurações de Ação e Rumor e configurações de notificação.
- Códigos de verificação e transferências, depois de emitidos.

**A cópia de um bloco de esquema de uma crônica mantém o que ela mudou e segue o bloco compartilhado no resto**, então as correções do catálogo ainda chegam a ela. Um modelo copiado é diferente: ele deixa de seguir o modelo compartilhado, e uma mudança posterior ali precisa ser refeita à mão.

## Tarefas comuns

### Trocar para outra crônica

1. Abra [Minha Crônica](my-chronicle.md) ou o [Kit de Ferramentas do Narrador](storyteller-toolkit.md).
2. Use a lista suspensa **Crônica** no topo.

### Achar o slug de uma crônica

1. wp-admin → Configuração do Sistema → Jogos. Veja [Jogos](games.md).

### Ver o que é personalizado para a sua crônica e o que é compartilhado

1. Abra [Configuração da Crônica](chronicle-setup.md) - as linhas **Regras de aprovação**, **Personalização do catálogo** e **Modelos de ficha** informam, cada uma, se a sua crônica tem a própria ou usa os padrões do Beyond Elysium, e ficam verdes quando tem a própria.

### Adicionar um jogador novo à sua crônica (Narrador)

1. Crie um personagem para ele, ou atribua um existente a ele - veja [Personagens](character-list.md#atribuir-ou-trocar-o-jogador-de-um-personagem-narrador). Qualquer um dos dois faz a crônica aparecer no seletor de Minha Crônica dele; não há um passo de filiação à parte na 1.0.0.

### Criar uma crônica (administrador do site)

1. wp-admin → Configuração do Sistema → Jogos → **+ Novo Jogo**. Veja [Jogos](games.md).

## O que saber

- **A lista suspensa Crônica em Minha Crônica e no Kit de Ferramentas do Narrador só lista as crônicas a que você já pertence** - não é um diretório de toda crônica do site.
- **Os seletores de Jogo do wp-admin são diferentes** - listam toda crônica da instalação, mas usar a maior parte do que há por trás deles ainda exige um papel de verdade na que você escolher.
- **Entrar numa crônica nova não se faz por um seletor.** Começar o seu primeiro personagem numa crônica a que você ainda não pertence é um pedido de entrada - ele espera pendente até um Narrador de lá aprovar. Veja [Papéis](roles.md).
- **Renomear o slug de uma crônica move tudo o que a nomeia** - personagens, blocos de catálogo bifurcados, páginas, códigos de verificação e transferências, tudo vai junto. Excluir uma é definitivo: a única confirmação nomeia tudo o que ela contém, e um sim remove tudo de vez. Veja [Jogos](games.md).
- **Uma crônica começa sem nada configurado além do catálogo base.** [Configuração da Crônica](chronicle-setup.md) é onde você vê exatamente o que falta.

## Solução de problemas

- **"Você ainda não pertence a nenhuma crônica."** Peça ao Narrador dessa crônica que adicione você - veja [Personagens](character-list.md#atribuir-ou-trocar-o-jogador-de-um-personagem-narrador). Na 1.0.0 esse é o único jeito de entrar; ainda não há um link de entrada por conta própria.
- **Uma crônica em que acabei de entrar ainda não está na minha lista suspensa.** O seu primeiro personagem ali provavelmente ainda é um pedido de entrada pendente.
- **"Ainda não existe nenhum jogo - crie um em Beyond Elysium → Configuração do Sistema → Jogos primeiro."** Só um administrador do site pode criar um - veja [Jogos](games.md).
- **Não vejo uma ferramenta de Narrador que espero.** Confira a lista suspensa **Crônica** - o seu papel pode ser diferente entre crônicas.

## Relacionados

- [Minha Crônica](my-chronicle.md)
- [Kit de Ferramentas do Narrador](storyteller-toolkit.md)
- [Jogos](games.md)
- [Configuração da Crônica](chronicle-setup.md)
- [Acesso à Crônica](chronicle-access.md)
- [Blocos de Esquema](schema-blocks.md)
- [Pilhas de Criatura](creature-stacks.md)
- [Papéis](roles.md)
- [Guia do Narrador: Criando um Jogo](../st-guide.md#1-criando-um-jogo)
