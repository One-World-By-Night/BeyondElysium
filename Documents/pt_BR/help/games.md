# Jogos

Crie, renomeie e exclua crônicas - o recipiente de nível superior a que todo personagem, trama e personalização de catálogo pertence.

## Quem pode usar

Só uma conta de administrador do WordPress. Todo Narrador, Condutor de Trama, Harpia e jogador pode ver a lista de crônicas em outro lugar - o seletor de crônica nas próprias telas - mas só um administrador do site pode criar, renomear ou excluir uma aqui.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Configuração do Sistema → aba Jogos.

## A tela

- Uma tabela de toda crônica da instalação: **Nome**, **Slug**, **Tipo**, **Criado** e **Ações** (**Editar**, **Excluir**). "Nenhum jogo ainda. Crie o primeiro abaixo." quando não existe nenhum.
- **+ Novo Jogo** - abre um formulário: **Nome** (obrigatório), **Slug** (opcional - derivado do nome se deixado em branco), **Tipo de Jogo** (o padrão é `met`), **Descrição** e **Salvar**/**Cancelar**. Sem botão de Assistência de IA aqui - uma crônica que você ainda está criando ainda não tem nada a que ele se ligar.
- **Editar** numa linha abre o mesmo formulário, preenchido. O campo **Descrição** dele ganha um botão **Assistência de IA**. Mudar o **Slug** aqui mostra um aviso sobre o que uma renomeação move. Abaixo do formulário, uma seção **Crônica de demonstração** transforma esta crônica numa que se redefine sozinha numa agenda - veja [Crônica de Demonstração](demo-chronicle.md).
- **Excluir** numa linha pede uma confirmação. Se a crônica não contém nada, só pergunta "Excluir "X"?"; se contém conteúdo de verdade, a confirmação diz o quê - por exemplo, "Excluir "X" e tudo o que há nela - 22 personagens, 1 trama? Isso não pode ser desfeito." - e um sim exclui tudo.
- Depois de uma renomeação bem-sucedida, um aviso nomeia o novo slug e quantos personagens, bifurcações de blocos de esquema, referências de páginas e widgets do Elementor foram movidos junto.

## Tarefas comuns

### Criar uma crônica

1. Clique em **+ Novo Jogo**.
2. Digite um **Nome**. Deixe **Slug** em branco para gerar um.
3. Clique em **Salvar**.
4. Abra a [Configuração da Crônica](chronicle-setup.md) em seguida para ver o que falta configurar.

### Renomear o slug de uma crônica

1. Clique em **Editar** na linha dela.
2. Mude o **Slug** e leia o aviso que aparece.
3. Clique em **Salvar**.

### Editar o nome, o tipo ou a descrição de uma crônica

1. Clique em **Editar** na linha dela.
2. Mude o campo.
3. Clique em **Salvar**.

### Excluir uma crônica

1. Clique em **Excluir** na linha dela.
2. Leia a confirmação - ela nomeia tudo o que a crônica contém.
3. Confirme.

## O que saber

- **Criar uma crônica aqui faz de você o HST dela na hora** - sem passo à parte, e ninguém mais é adicionado automaticamente. Use [Acesso à Crônica](chronicle-access.md) para adicionar outros Narradores, um Condutor de Trama ou a Harpia da sua crônica.
- **Uma crônica nova começa sem mais nada configurado.** A [Configuração da Crônica](chronicle-setup.md) é onde você vê o que falta.
- **Renomear o slug move tudo o que o nomeia** - todo personagem, qualquer bloco de esquema personalizado, as páginas e widgets que referenciam esta crônica, os códigos de verificação ativos e o lado deste site de qualquer transferência. Um slug já em uso, ou deixado por uma crônica que você excluiu, é recusado antes de qualquer coisa se mover.
- **Excluir uma crônica é definitivo e total.** A única confirmação nomeia exatamente o que há dentro, e um sim remove cada pedacinho - personagens, tramas, itens e locais, os modelos próprios e blocos de catálogo personalizados dela, consultas salvas, códigos de verificação e transferências. Nada nunca é deixado sob o slug para uma crônica posterior de mesmo nome herdar.
- **Tipo de Jogo é texto livre.** `met` é o único conjunto de regras que o Beyond Elysium roda hoje - deixe como está a menos que tenha um motivo específico para mudar.

## Solução de problemas

- **"Já existe um jogo com este slug."** Escolha um slug diferente.
- **"Personagens ou registros de uma crônica excluída ainda estão armazenados sob este slug. Escolha um slug diferente."** Esse slug não está livre para reuso - escolha outro.
- **"Não é possível renomear: personagens ou registros de uma crônica excluída ainda estão armazenados sob este slug, e esta crônica os assumiria. Escolha um slug diferente."** O mesmo problema, encontrado ao renomear - escolha outro slug.
- **"Não é possível renomear: uma crônica que já usa este slug deixou blocos de esquema personalizados para trás…"** Raro, e só possível de antes de isso ser corrigido - escolha outro slug.
- **"Esta crônica ainda contém personagens ou outro conteúdo. Exclua-a com seu conteúdo, ou mantenha-a."** Clique em **Excluir** de novo - a confirmação nomeará o que há dentro e removerá tudo.
- **Não vejo esta aba.** Você precisa de uma conta de administrador do WordPress.

## Relacionados

- [Crônica de Demonstração](demo-chronicle.md)
- [Configuração da Crônica](chronicle-setup.md)
- [Acesso à Crônica](chronicle-access.md)
- [Configurações de Ação e Rumor](apr-settings.md)
- [Personagens do Administrador](admin-characters.md)
- [Painel do Administrador](admin-dashboard.md)
- [Papéis](roles.md)
- [Crônicas](chronicles.md)
- [Guia do Narrador](../st-guide.md#1-criando-um-jogo)
- [Guia do Administrador](../admin-guide.md#o-que-um-hst-pode-e-não-pode-fazer)
