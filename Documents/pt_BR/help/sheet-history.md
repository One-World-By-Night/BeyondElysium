# Histórico de Alterações

O registro completo de toda mudança já enviada para um personagem - aplicada, aprovada, recusada, retirada, desfeita e ainda aguardando - da mais nova para a mais antiga, agrupada pelo que foi enviado junto.

## Quem pode usar

Qualquer pessoa que pode abrir a ficha do personagem - um jogador no próprio personagem, os Narradores (HST e AST) em qualquer personagem da crônica.

## Como chegar lá

Personagem → visualização **Histórico**. O mesmo conteúdo também aparece na própria ficha sempre que **Histórico de XP** está marcado no painel **Imprimir / Exportar** - veja [Imprimir / Exportar](sheet-print-export.md).

## A tela

- **Fechar** - guarda o histórico de volta.
- **Todas, Compras, Jogo** - uma fileira de filtros. Compras são as mudanças que custam XP, e Jogo é o que acontece à mesa: pontos temporários gastos e recuperados, e prêmios de XP. Um filtro sem nada diz "Nada desse tipo ainda."
- A lista em si, a mais nova primeiro, em **pedidos**. Tudo o que foi enviado num só Enviar é um pedido, com o título "Enviado em 25 nov · 5 alterações · 6 XP" e uma linha abaixo como "2 aplicadas · 3 aguardando". Uma mudança feita sozinha é a sua própria entrada. Cada linha mostra:
  - **Status** - Esperando um Narrador, Aplicada, Aprovado, Recusado, Retirado, Desfeito ou Enviado para casa. Aplicada é uma mudança que foi direto para a ficha; Aprovado é uma que um Narrador decidiu. Uma mudança que ninguém respondeu em 60 dias diz Recusado, com "Sem resposta em 60 dias".
  - Uma descrição simples da mudança, como "Celerity 2 → 3", "Adicionado Underworld Lore" ou "+5 XP (presença em sessão)".
  - O seu custo em **XP**, quando a mudança tem um. Uma compra caseira que ainda espera um preço diz **Preço definido na aprovação**.
  - Uma linha de **Atualização do catálogo**, uma por personagem, quando uma atualização move um site para as listas próprias de cada tipo de criatura. Abra **Itens associados** sob ela para ver cada entrada personalizada que virou um item do catálogo, como "o que era → o que é". Uma atualização que tira uma escolha de Magia de Sangue de Disciplinas diz quantas linhas repetidas ela descartou porque a ficha já as tinha, e lista cada uma, com qualquer escolha que manteve que discorda de uma linha mantida no nível. Veja [Movendo um Site Antigo para as Listas por Tipo de Criatura](../admin-guide.md#movendo-um-site-antigo-para-as-listas-por-tipo-de-criatura).
  - Quando foi enviada e, depois de revisada, quando.
  - A nota do próprio autor do envio, se houver.
  - A nota do Narrador, rotulada "Narrador: ...", se houver - mantida separada da nota do autor do envio, nunca a substituindo.
  - "Alterada por um Narrador" quando um Narrador editou o pedido antes de decidi-lo.
  - **Retirar**, numa linha que ainda aguarda, para o jogador (veja abaixo).
  - **Desfazer**, numa linha que um Narrador pode tomar de volta (veja abaixo), e "Desfeito por [nome] em [data]" depois que foi.
- **Retirar todas as aguardando** e **Desfazer este conjunto** no título de um pedido, quando mais de uma das suas linhas pode ser retirada ou desfeita.
- "Nenhuma alteração ainda." quando o personagem não tem nenhum histórico.

Sob o nome do personagem, uma linha diz como está o XP: "10 XP para gastar · 6 XP retidos · 3 mudanças aguardando". O XP retido é o que as mudanças ainda aguardando custariam, separado para não ser gasto duas vezes.

## Tarefas comuns

### Conferir se uma mudança ainda está esperando

1. Abra a Ficha do personagem.
2. Clique em **Histórico** na fileira de visualizações.
3. Ache a mudança - **Esperando um Narrador** quer dizer que ainda espera; **Aplicada**, **Aprovado**, **Recusado**, **Retirado** ou **Desfeito** quer dizer que está resolvida.

### Retirar uma mudança que ainda aguarda

1. Abra a visualização **Histórico** e ache a linha marcada **Esperando um Narrador**.
2. Clique em **Retirar**. A linha passa a dizer **Retirado**, o XP retido para ela fica livre para gastar de novo, e nada dela chega à ficha. **Retirar todas as aguardando** faz o mesmo com cada linha aguardando de um pedido.
3. Uma mudança já decidida não pode ser retirada; um Narrador pode desfazê-la.

Quem pode editar o personagem pode retirar as suas mudanças aguardando: o jogador e um Narrador.

### Desfazer uma mudança que foi aprovada (Narradores)

1. Abra a visualização **Histórico** e ache a linha. **Desfazer** aparece numa linha que foi aplicada ou aprovada e que pode ser tomada de volta: um traço adicionado, removido ou mudado, uma mudança de reserva ou de identidade, um gasto de uma reserva, ou um prêmio ou correção de XP.
2. Clique em **Desfazer**. A pergunta nomeia a mudança e o XP: "Desfazer Ocultismo x4? 6 XP voltam para Alice." Acrescente uma **Nota para o jogador (opcional)** de até 1.000 caracteres e confirme.
3. O XP volta, a ficha é posta de volta como era, e a linha diz "Desfeito por [você] em [data]". O jogador recebe um aviso.
4. **Desfazer este conjunto** desfaz cada linha de um pedido que pode ser desfeita, da mais nova para a mais antiga, ou nenhuma delas.

Desfazer recusa em vez de adivinhar. "Esse XP já foi gasto. Desfaça primeiro o que ele comprou." quer dizer que o XP de um prêmio foi gasto depois. "Desfaça primeiro a alteração posterior: ..." e "[nome] mudou desde então." querem dizer que algo posterior tocou a mesma parte da ficha. Uma ficha mantida atualizada a partir de outra crônica diz "Esta ficha é mantida atualizada a partir da crônica de origem do personagem. Altere também a cópia mantida atualizada lá." Nada é reprecificado: o XP volta como foi pago.

### Ler a nota de um Narrador numa mudança

1. Abra a visualização **Histórico**.
2. Procure sob a mudança uma linha que comece com "Narrador:".

### Ler o histórico junto de uma ficha impressa ou na tela

1. Escolha **Imprimir / Exportar**, clique em **Ir** e marque **Histórico de XP**.

## O que saber

- Este é o registro completo - as mudanças aplicadas, aprovadas, recusadas, retiradas e desfeitas ficam aqui, não só as aguardando.
- Uma mudança recusada ou retirada nunca tocou a ficha. Uma desfeita tocou, e a ficha é posta de volta.
- Esta lista mostra o que aconteceu com uma mudança, não por que ela precisou da revisão de um Narrador - um Narrador vê esse raciocínio enquanto ela ainda está na [Fila de Aprovação](approval-queue.md).
- A nota do autor do envio e a nota de revisão de um Narrador ficam sempre separadas aqui, até na mesma mudança.
- Uma mudança retirada não pode ser trazida de volta; adicione-a ao carrinho de novo.

## Solução de problemas

- **"Nenhuma alteração ainda", embora eu tenha feito edições.** Nada foi enviado para este personagem ainda, ou você está olhando um personagem diferente do que esperava. O que ainda está no seu carrinho não está aqui até você enviar.
- **"Falha ao carregar o histórico de alterações."** Atualize a página.
- **Uma mudança que lembro de ter enviado não está aqui.** Cada personagem guarda o seu próprio histórico separado - confira se está no certo.
- **Não há Desfazer numa linha.** Ela ainda aguarda (retire-a), foi recusada ou retirada, é um tipo de mudança que não pode ser tomado de volta, ou você não é um Narrador.
- **"Não foi possível desfazer a alteração."** Leia a linha sob o botão; ela diz o que mudou desde então. Desfaça isso primeiro.

## Relacionados

- [Ficha de Personagem](character-sheet.md)
- [Editor de Personagem](character-editor.md)
- [Imprimir / Exportar](sheet-print-export.md)
- [Fila de Aprovação](approval-queue.md)
- [Como Funciona a Aprovação](approval-flow.md)
- [Guia do Jogador](../player-guide.md#2-editando-sua-ficha)
- [Guia do Narrador](../st-guide.md#4-conduzindo-a-fila-de-aprovação)
