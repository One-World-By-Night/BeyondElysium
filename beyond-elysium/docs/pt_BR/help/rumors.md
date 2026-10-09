# Rumores

Adiciona um rumor à crônica - escrito à mão, ou gerado automaticamente a partir de dados reais da crônica para uma data de jogo.

## Quem pode usar

Os Narradores (HST e AST) e os Condutores de Trama - o mesmo acesso que abre o resto de [Tramas e Rumores](plot-manager.md). Um jogador nunca abre esta ferramenta; um rumor dirigido a um dos personagens dele aparece no seu próprio feed de [Minhas Tramas e Rumores](my-plots.md) e, a menos que ele tenha desligado as notificações, ele recebe um e-mail uma vez, quando um rumor confirmado chega a um dos personagens dele pela primeira vez.

## Como chegar lá

Kit de Ferramentas do Narrador → Tramas e Rumores → botão **Gerar rumores** (na grade de tramas), ou **+ Rumor** de dentro de uma trama ou ação aberta, que já preenche essa trama como a mãe.

## A tela

Duas seções.

### Escrever um manualmente

- Um **título do rumor**.
- Uma caixa de descrição em texto rico ("O que está sendo cochichado…", com Assistência de IA).
- Uma lista suspensa de mãe - qualquer trama ou ação existente, não só uma de nível superior - ou "Sem mãe".
- **Adicionar rumor**.

### Gerar a partir dos dados da crônica

- Um campo de **data do jogo** e **Pré-visualizar**.
- Depois de pré-visualizada, uma lista dos rumores que o gerador criaria para essa data - cada um com o título, a categoria e quantos personagens alcança no momento - ou uma mensagem de que nada de novo seria gerado.
- **Confirmar N** - grava todo rumor listado. Depois de confirmado, uma confirmação substitui o botão.

## Tarefas comuns

### Escrever um rumor à mão

1. Abra Kit de Ferramentas do Narrador → Tramas e Rumores → **Gerar rumores**.
2. Digite um **título do rumor** e, se quiser, o que está sendo cochichado.
3. Se quiser, escolha uma trama ou ação mãe.
4. Clique em **Adicionar rumor**.

### Gerar o conjunto padrão de rumores para uma data de jogo

1. Abra **Gerar rumores**.
2. Escolha a **data do jogo**.
3. Clique em **Pré-visualizar**.
4. Confira a lista - a contagem de destinatários de cada rumor diz quem ele alcança no momento.
5. Clique em **Confirmar N**.

### Aninhar um rumor sob uma trama ou ação

1. Abra primeiro a trama ou ação.
2. Clique em **+ Rumor** na barra de ações dela - Rumores abre com essa trama já escolhida como a mãe.

## O que saber

- **Um rumor gerado começa sem conteúdo** - é só um título e um alvo, até você abri-lo em [Tramas e Rumores](plot-manager.md) e escrevê-lo, igual a qualquer outra trama.
- **O gerador nunca repete um título já usado para essa mesma data de jogo** - pré-visualizar ou confirmar de novo para a mesma data só acrescenta o que é realmente novo.
- **O que é gerado depende das Configurações de Ação e Rumor da sua crônica.** O Conhecimento Público e levar adiante os rumores da data anterior vêm ligados por padrão; rumores pessoais, de raça, de grupo, de subgrupo e de influência também são configurados ali. Veja [Configurações de Ação e Rumor](apr-settings.md).
- **Um rumor de grupo ou subgrupo alcança todos que compartilham esse mesmo valor** - o grupo de um personagem é o seu Clã, Tribo, Kith, Tradição ou similar, e o subgrupo a sua Seita, Auspício, Aparência ou Guilda, conforme o tipo de criatura.
- **Pré-visualizar nunca envia e-mail nem grava nada** - só Confirmar faz isso, e só uma geração confirmada envia e-mail aos jogadores correspondentes.
- **O alcance de um rumor é calculado ao vivo a partir do alvo dele**, não fixado no momento em que foi criado - se um rumor tem como alvo "Brujah" e o Clã de um personagem muda depois, o alcance desse rumor muda junto.
- **Um rumor que você escreve à mão não tem data de jogo**, ao contrário de um que o gerador cria.

## Solução de problemas

- **"Falha ao pré-visualizar rumores para esta data." / "Falha ao confirmar rumores para esta data."** Tente de novo.
- **"Falha ao criar este rumor."** Um título é obrigatório.
- **"Nada de novo para esta data - todo título que o gerador produziria já existe."** Escreva um à mão se precisar de outro.
- **Um rumor não está chegando a um jogador que eu esperava.** Um rumor pessoal, de raça, de grupo, de subgrupo ou de influência só é gerado quando um personagem ativo no momento o justifica - mas, depois de gerado, alcança todo personagem que corresponde ao alvo dele, ativo ou não.
- **Não vejo este botão.** Você precisa de um papel de Narrador ou de Condutor de Trama na crônica escolhida no momento.

## Relacionados

- [Tramas e Rumores](plot-manager.md)
- [Configurações de Ação e Rumor](apr-settings.md)
- [Minhas Tramas e Rumores](my-plots.md)
- [Papéis](roles.md)
- [Guia do Narrador](../st-guide.md#9-configurações-de-ação-e-rumor-e-o-registro-de-usos-de-antecedente)
