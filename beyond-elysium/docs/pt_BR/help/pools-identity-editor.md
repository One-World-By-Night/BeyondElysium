# Reservas de Recurso e Campos de Identidade

Dois dos blocos de construção do editor de personagem: os marcadores de pontos e contador para reservas como Força de Vontade e Sangue, e as listas suspensas, caixas de seleção e campos de texto para traços nomeados como Clã e Natureza.

## Quem pode usar

Qualquer pessoa que edita um personagem vê isto dentro da aba Editar - um jogador editando o próprio personagem, ou um Narrador editando qualquer personagem da crônica. Se você só pode visualizar o personagem, cada reserva e campo aqui aparece como um valor simples somente leitura, sem contador nem entrada. Veja o [Editor de Personagem](character-editor.md) para saber quem pode abrir a aba Editar.

Alguns valores de reservas e campos são só do Narrador, não importa quem edita - veja O que saber.

## Como chegar lá

Minha Crônica → aba Editar, dentro de qualquer seção montada como reserva de recurso (Força de Vontade, Reserva de Sangue, Fúria e afins) ou campo de identidade (Clã, Natureza, Geração e afins), conforme o modelo da própria crônica.

## A tela

### Reservas de recurso

- Cada reserva ganha a sua própria linha: um rótulo, depois uma trilha **P** (Permanente) e uma trilha **T** (Temporária), cada uma uma fileira de pontos do mesmo tamanho com um número e botões **−** / **+**.
- Os pontos permanentes são quadrados; os temporários são redondos. Os dois são desenhados do mesmo tamanho de todo outro ponto da ficha - só o formato e a cor os distinguem.
- Clicar em **−** ou **+** move essa trilha de um em um, descendo até 0 ou subindo até o máximo da reserva.
- Um valor temporário acima do permanente aparece além da marca permanente, em vermelho, em vez de ser limitado - um reforço temporário acima da sua classificação normal fica visível num relance.
- O rótulo de uma reserva pode mudar conforme outra coisa na sua ficha. As reservas de Consciência e Autocontrole de um vampiro, por exemplo, mudam de rótulo para Convicção e Instinto quando você segue um Caminho que não seja a Humanidade - a mesma reserva e os mesmos pontos, só um nome diferente.

### Campos de identidade

Cada campo aparece como um destes, conforme como o modelo da crônica o montou:

- **Lista suspensa** - uma única escolha de uma lista fixa. Se o campo permite uma entrada personalizada, você pode digitar o seu próprio valor em vez de escolher um.
- **Grupo de caixas de seleção** - para um campo que permite mais de um valor ao mesmo tempo. Um contador sob o grupo mostra quantos você escolheu contra o máximo; ao chegar no máximo, as caixas restantes se desativam até você desmarcar uma.
- **Caixa numérica** - um número simples, com mínimo e máximo onde o campo os define.
- **Caixa de texto** ou **área de texto** - uma linha ou um bloco maior de texto livre. Uma área de texto também traz um botão **Assistência de IA**, mostrado só a um Narrador, mesmo no personagem de um jogador.

## Tarefas comuns

### Subir uma reserva de recurso

1. Abra o personagem na aba **Editar**.
2. Ache a linha da reserva.
3. Clique em **+** ao lado de **P** para subir a classificação permanente, ou ao lado de **T** para gastar ou recuperar pontos em jogo.
4. Confira o custo em **Alterações Pendentes** e clique em **Enviar Alterações**.

### Mudar um campo de identidade

1. Abra o personagem na aba **Editar**.
2. Ache o campo.
3. Escolha um valor novo, ou digite um onde o campo permitir.
4. Clique em **Enviar Alterações**.

### Escolher mais de uma opção num campo de seleção múltipla

1. Ache o campo - o contador sob ele mostra quantas você escolheu e o máximo.
2. Marque as opções que você quer, até esse máximo.

## O que saber

- **Mudanças permanentes são precificadas; as temporárias não.** Subir a classificação Permanente de uma reserva põe um custo de XP na fila em Alterações Pendentes, igual a qualquer outra compra. Mover a trilha Temporária - gastar ou recuperar pontos em jogo - não custa nada e não precisa de revisão.
- **Algumas reservas não são compradas.** Uma reserva que a sua crônica concede em vez de vender (Renome é o exemplo clássico) não tem custo de XP, e só um Narrador pode definir a classificação Permanente dela. Você ainda pode mover a trilha Temporária livremente, mas enviar uma mudança que sobe o lado Permanente por conta própria é recusado.
- **Os campos de identidade são livres para definir.** Mudar um não custa XP. A sua crônica ainda pode exigir a revisão de um Narrador para um campo específico, ou para uma opção nele - escolher "Antediluviano", por exemplo, enquanto toda outra escolha de Clã é automática - e isso aparece em Alterações Pendentes como precisando de aprovação do Narrador, igual a qualquer outra mudança revisada.
- **Alguns campos não podem ser limpos por um jogador.** Um campo pelo qual a sua crônica precifica outros traços - Clã é o caso usual - pode ser mudado para outro valor, mas não esvaziado. Pergunte a um Narrador se ele realmente precisa ficar vazio.
- **Todo ponto tem o mesmo tamanho** - os pontos de uma reserva aqui, a classificação de um traço no resto da ficha e um PDF assinado desenham todos o mesmo ponto de 14 pixels.

## Solução de problemas

- **"A classificação permanente de … é definida por um Narrador."** Você tentou subir uma reserva sem custo de XP (uma reserva concedida, como Renome) além do que você já tem. Peça a um Narrador que a defina.
- **"… não pode ser limpo(a) - peça a um Narrador."** Você tentou esvaziar um campo que a sua crônica usa para precificar outros traços. Mude para outro valor, ou peça a um Narrador que o limpe para você.
- **Uma caixa de seleção não marca.** Você já escolheu o máximo que este campo permite - o contador sob o grupo mostra quantas. Desmarque uma primeiro.
- **O nome de uma reserva não bate com o que eu esperava.** Algumas reservas mudam de rótulo conforme outra escolha na sua ficha (as Virtudes de um vampiro sob um Caminho que não seja a Humanidade, por exemplo). Os pontos e o valor guardado não são afetados - só o nome exibido muda.

## Relacionados

- [Editor de Personagem](character-editor.md)
- [Listas de Traços](trait-editor.md)
- [Poderes](power-editor.md)
- [Regras de Aprovação](approval-rules.md)
- [Como Funciona a Aprovação](approval-flow.md)
- [Guia do Jogador](../player-guide.md#2-editando-sua-ficha)
- [Guia do Narrador](../st-guide.md#3-criando-personagens)
