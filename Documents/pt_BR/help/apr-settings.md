# Configurações de Ação e Rumor

Define quantas ações de tempo livre um personagem recebe a cada data de jogo, e quais categorias de rumor a sua crônica gera automaticamente.

## Quem pode usar

Os Narradores (HST e AST). Um Condutor de Trama, a Harpia da crônica e um jogador nunca veem esta aba.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Configuração da Crônica → aba Configurações de Ação e Rumor.

## A tela

- **Crônica** - uma lista suspensa com toda crônica da instalação.
- Uma nota de que todo valor mostrado é o padrão do próprio Beyond Elysium, a menos que esta crônica o tenha alterado.
- Duas abas, **Ações** e **Rumores**.

### Aba Ações

- **Ações pessoais por personagem** - um número, 0-100. Todo personagem recebe essa quantidade de ações, seja o que for que tenha.
- **Copiar Valores Não Usados da Ação Anterior** - leva adiante um orçamento não usado da alocação anterior mais recente de um personagem.
- **Sempre adicionar Ações Comuns** - caixa de seleção.
- **Ações por nível** - uma tabela (Classificação, Ações concedidas e um botão **Remover**) que lista só as classificações que você substituiu; qualquer classificação sem linha aqui usa o padrão do próprio plugin de 2 ações por ponto. **+ Adicionar um nível** pergunta qual classificação (1-20) e a adiciona em 0, pronta para editar.
- **Antecedentes que concedem uma ação** - todo Antecedente e Influência do catálogo próprio desta crônica, cada um mostrando a quais pilhas de criatura pertence. Uma Influência sempre vem marcada e desativada - ela sempre concede uma ação. Um Antecedente só concede uma quando você o marca aqui.

### Aba Rumores

Oito caixas de seleção: **Rumores públicos**, **Rumores pessoais**, **Rumores de raça**, **Agrupar rumores (Clã, Tribo, Kith, Tradição…)**, **Subagrupar rumores (Seita, Auspício, Semblante, Guilda…)**, **Rumores de influência**, **Transportar rumores anteriores**, **Copiar descrições de rumores anteriores**.

### Ações comuns às duas abas

- **Salvar** - grava as mudanças de todas as abas juntas, seja qual for a aba que você está olhando.
- **Restaurar padrões do Grapevine** - depois de uma confirmação, redefine as ações pessoais, as duas caixas de ação, a tabela de ações por nível e a lista de antecedentes para os valores originais de 1998 do próprio Grapevine. Todo alternador de rumor fica exatamente como está. Isso só muda o que está na tela - clique em **Salvar** depois para manter.

## Tarefas comuns

### Mudar quantas ações pessoais um personagem recebe

1. Abra a aba **Ações**.
2. Defina **Ações pessoais por personagem**.
3. Clique em **Salvar**.

### Substituir as ações concedidas numa classificação específica

1. Na aba **Ações**, clique em **+ Adicionar um nível**.
2. Digite a classificação (1-20).
3. Defina as **Ações concedidas** dela.
4. Clique em **Salvar**.

### Deixar um Antecedente conceder uma ação

1. Ache-o em **Antecedentes que concedem uma ação**.
2. Marque-o.
3. Clique em **Salvar**.

### Ligar ou desligar uma categoria de rumor

1. Abra a aba **Rumores**.
2. Marque ou desmarque a categoria.
3. Clique em **Salvar**.

### Voltar aos números originais do Grapevine

1. Clique em **Restaurar padrões do Grapevine**.
2. Confirme.
3. Clique em **Salvar** para manter a mudança.

## O que saber

- **Nada aqui salva até você clicar em Salvar.** Trocar de aba, ou clicar em Restaurar padrões do Grapevine, só muda o que está na tela.
- **Restaurar padrões do Grapevine nunca toca a aba Rumores** - só os números da aba Ações e a lista de antecedentes.
- **Uma Influência sempre concede uma ação.** A lista a mostra marcada e desativada como lembrete, não como algo que você possa desligar.
- **Uma classificação sem linha em Ações por nível simplesmente usa o padrão de 2 ações por ponto** - você só precisa de uma linha aqui para uma classificação que quer diferente.
- **O nome de um antecedente precisa ser real** - só um nome do catálogo próprio desta crônica é aceito, nunca um personalizado nem escrito errado.
- **Os rumores de grupo e subgrupo leem os campos de identidade do próprio personagem.** Grupo é Clã, Tribo, Kith, Tradição, ou o equivalente em outros tipos de criatura; subgrupo é Seita, Auspício, Semblante, Guilda, ou o equivalente.
- **Estas configurações alimentam [Alocar Ações](allocate-actions.md) e [Rumores](rumors.md) diretamente.** Uma mudança aqui muda o que essas ferramentas calculam da próxima vez que rodarem - nunca nada já confirmado.

## Solução de problemas

- **"personal_actions deve estar entre 0 e 100."** Escolha um número nessa faixa.
- **"as chaves de actions_per_level devem ser os níveis de 1 a 20." / "os valores de actions_per_level devem estar entre 0 e 999."** Confira a classificação e o número que você digitou.
- **'"X" não é um nome de antecedente ou influência no catálogo desta crônica.'** Só os nomes que esta tela lista podem ser marcados - recarregue e tente de novo.
- **Não vejo esta aba.** Você precisa de um papel de Narrador na crônica escolhida no momento.
- **Uma linha que adicionei em Ações por nível sumiu.** Remover exclui essa linha - a classificação simplesmente volta ao padrão de 2 na próxima vez que você salvar. Adicione-a de novo com **+ Adicionar um nível** se não era isso que você queria.

## Relacionados

- [Configuração da Crônica](chronicle-setup.md)
- [Alocar Ações](allocate-actions.md)
- [Rumores](rumors.md)
- [Usos de Antecedente](background-uses.md)
- [Configurações de Assistência de IA (Crônica)](writing-assist-chronicle.md)
- [Papéis](roles.md)
- [Guia do Narrador](../st-guide.md#9-configurações-de-ação-e-rumor-e-o-registro-de-usos-de-antecedente)
