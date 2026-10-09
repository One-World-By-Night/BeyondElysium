# Auditoria de Pontos

Todo traço, poder, reserva de recurso e campo de identidade que a ficha de um personagem tem, precificado linha a linha contra as mesmas regras que uma compra usa - e, para tudo o que não pode ser precificado, um motivo simples.

## Quem pode usar

Só os Narradores (HST e AST). Isto é protegido com mais rigor que a visão comum de uma ficha, até para o jogador do próprio personagem: um jogador nunca vê isto, nem no próprio personagem. A auditoria lê os dados completos do personagem, os blocos só para Narradores inclusive, e um total que revela o valor deles precisa ficar atrás do mesmo muro que os próprios blocos.

## Como chegar lá

Ficha do Personagem → escolha **Auditoria de pontos** na lista de ações e clique em **Ir** (só para Narradores; a lista não a oferece a mais ninguém).

## A tela

- **Fechar** - guarda a auditoria de volta.
- Uma linha de resumo: **Gasto**, **Ganho**, **Líquido** (Gasto menos Ganho), **XP registrado** (o Ganho registrado do próprio personagem menos o Não Gasto) e **Variação** (Líquido menos XP registrado).
- **Precificou N de M linhas.** - quanto da ficha esta auditoria conseguiu de fato precificar.
- Uma frase de ressalva que nomeia quantas linhas não puderam ser precificadas e, quando há um, o motivo mais comum.
- O resto do relatório, agrupado sob os títulos de seção da própria ficha. Cada linha mantida é:
  - **Precificada** - o nome, o custo em XP e, onde o preço de um poder depende do tipo do seu personagem, por quanto: "(+N fora do tipo)" para um comprado fora dele, "(−N dentro do tipo)" para um comprado dentro dele com desconto. Numa lista contada em pontos, como Habilidades ou Antecedentes, o nome traz uma contagem "×N" acima de um e o custo cobre todo ponto. Uma Qualidade, Defeito, Ritual ou outra compra única é precificada uma vez, seja qual for o número com que foi importada; onde o catálogo dá uma escolha de custos, um número importado que seja um deles é o custo escolhido. Uma linha que devolve XP (um Defeito, por exemplo) mostra o valor com um sinal de menos.
  - **Sem preço** - o nome e um motivo simples: "item do catálogo não tem custo", "nome não está no catálogo", "família não está no catálogo", "nível não tem custo", "personalizado, sem entrada no catálogo", "campo de identidade, sem custo de catálogo", "reserva de recursos ainda não tem regra de precificação" ou "bloco possuído não está no catálogo". Uma linha também pode trazer "não declarado pela pilha" - a seção que a guarda não faz parte do modelo do tipo de criatura do próprio personagem.

## Tarefas comuns

### Conferir a contabilidade de XP de um personagem

1. Abra a Ficha do personagem.
2. Escolha **Auditoria de pontos** e clique em **Ir**.
3. Leia a linha de resumo, depois a frase de ressalva, antes de tratar o total como algo definitivo.

### Descobrir por que um traço não contou para o total

1. Abra **Auditoria de pontos**.
2. Ache o traço sob a seção dele - uma linha sem preço mostra o motivo ao lado.

## O que saber

- **Isto nunca é uma conta.** Uma ficha de verdade sempre tem linhas para as quais o catálogo ainda não tem custo - todo campo de identidade (Clã, Natureza e afins) é uma delas, de propósito: é listado, mas nunca é precificado. Leia a linha de cobertura e a ressalva antes de tratar o total como uma resposta.
- **Todo preço aqui vem das mesmas regras que uma compra usa.** Nada aqui é um segundo número, adivinhado à parte - se um traço mantido é precificado em 6 XP aqui, comprá-lo do zero custa o mesmo.
- **Uma reserva de recurso ganha uma linha, seja mantida ou não.** Uma reserva sem preço (Sangue, na maioria das pilhas) ainda ganha uma linha - só nunca é precificada.
- **Uma diferença entre Líquido e XP registrado é normal**, não sinal de problema - cresce a cada linha que o catálogo ainda não consegue precificar.
- **Carrega do zero toda vez que você abre.** Nada aqui fica em cache, então sempre reflete a ficha como está agora.

## Solução de problemas

- **"Falha ao carregar a auditoria de pontos."** Atualize e tente de novo.
- **"O tipo de criatura deste personagem não existe mais, então seus pontos não podem ser auditados."** O tipo de criatura do personagem foi removido antes de o Beyond Elysium deixar de permitir isso - pergunte a um administrador do site.
- **Não vejo este botão.** A auditoria de pontos é só para Narradores - você precisa ser HST ou AST nesta crônica, até para vê-la no seu próprio personagem.
- **Um traço que espero ver precificado mostra um motivo.** Isso não é um erro na ficha - quer dizer que o próprio catálogo ainda não tem custo registrado para esse traço, família ou nível exato.

## Relacionados

- [Ficha de Personagem](character-sheet.md)
- [Editor de Personagem](character-editor.md)
- [Listas de Traços](trait-editor.md)
- [Poderes](power-editor.md)
- [Reservas de Recurso e Campos de Identidade](pools-identity-editor.md)
- [Guia do Narrador](../st-guide.md#13-a-auditoria-de-pontos)
