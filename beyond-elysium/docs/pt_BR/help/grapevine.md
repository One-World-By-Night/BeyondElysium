# Importar/Exportar do Grapevine

Como um personagem - ou uma crônica inteira - passa entre o Beyond Elysium e o Grapevine 3.01, ou entre duas crônicas do Beyond Elysium: o que um arquivo de fato leva e o que não consegue levar.

## Quem pode usar

Exportar o seu próprio personagem é aberto a qualquer jogador. Exportar o de outra pessoa, e toda importação, é trabalho de Narrador (HST e AST); trazer um arquivo de jogo completo exige uma conta de administrador do site. Conferir se um documento é genuíno, em [Verificar Personagem](verify.md), é aberto a qualquer pessoa com um código - não é preciso conta.

## Como chegar lá

[Imprimir / Exportar](sheet-print-export.md) na Ficha de um personagem exporta um personagem. [Importar](import.md) traz um arquivo, ou a oferta de transferência de outra crônica. O painel [Enviar Ficha](transfer.md) de um personagem o envia direto a outra crônica.

## A tela

**O arquivo em si.** O Beyond Elysium lê e grava o mesmo formato de intercâmbio (`.gex`) que o Grapevine 3.01 usa, mais o arquivo de crônica completo do próprio Grapevine (`.gv3`) para trazer uma crônica inteira de uma vez. Uma transferência de crônica para crônica usa o mesmo documento `.gex`, enviado direto entre dois sites Beyond Elysium em vez de baixado e reenviado à mão.

**O que viaja com um personagem:**

- Campos de identidade, traços, poderes e reservas de recurso - tudo para o que o Grapevine tem um lugar.
- Itens e locais que o personagem tem - cada um se liga à entrada do catálogo da crônica receptora de mesmo nome, adicionada pelo nome se ainda não está lá.
- A experiência total ganha e não gasta.
- Antecedente e Notas, com a formatação intacta - o Grapevine não tem o conceito de texto rico, então abrir o arquivo em outro lugar pode mostrar marcas de formatação soltas em volta de texto em negrito ou listas.

**O que não viaja:**

- Favores - guardados no próprio histórico de importação do personagem no destino, nunca recriados como um favor ativo ali.
- Retrato, personalização da aparência da ficha, histórico de tramas e o registro de usos de antecedente.
- Entradas individuais do histórico de experiência - só os totais atravessam; cada crônica guarda o próprio registro daí em diante.
- O texto e os blocos só para Narradores, a menos que um Narrador os inclua especificamente na própria exportação - a exportação de um jogador é sempre limpa exatamente como a visão que ele tem da ficha. Veja [Conteúdo Só para Narradores](storyteller-only.md).

**Tipos de criatura.** Todo tipo de criatura que o Beyond Elysium traz pode ser exportado, transferido e importado. A única exceção é um tipo de criatura que um administrador adicionou a esta instalação sem um formato de exportação correspondente - exportá-lo, transferi-lo ou emitir um código de verificação para ele é recusado de cara. Bête é um caso especial: o Grapevine não tem Bête, então ele viaja como o Fera com quem divide todos os blocos, e volta como Bête no Beyond Elysium.

**Duplicatas na importação.** Trazer um personagem (ou item, local ou ritual) que já existe - pelo nome, ou pela identidade do próprio personagem quando o arquivo a traz - pede que você escolha **Ignorar**, **Sobrescrever** o que você tem ou **Importar como um registro novo e separado**. Um personagem encontrado numa crônica *diferente* do mesmo site só pode ser ignorado ou importado como novo, nunca sobrescrito a partir da sua. Duas entradas com o mesmo nome em um arquivo compartilham uma decisão, para a segunda não sobrescrever em silêncio o que a primeira acabou de gravar.

**Transferências em particular.** Uma transferência é o mesmo intercâmbio `.gex`, mas os dois lados precisam concordar: enviá-la é a sua própria aprovação do lado de origem, e nada é adicionado à outra crônica até um dos Narradores de lá revisar a oferta e aceitá-la. Veja [Enviar Ficha](transfer.md).

**Enviando o seu próprio arquivo direto.** Um jogador também pode simplesmente enviar um arquivo exportado direto a uma crônica - entrando nela, ou visitando para um jogo - sem nenhum Narrador do lado de quem envia. Ele é revisado exatamente como uma importação enviada, e nada é adicionado até um Narrador de lá aceitar. Veja [Enviar um Arquivo do Grapevine](send-grapevine-file.md).

**Verificação.** Um código de verificação pode ser embutido numa exportação, ou é emitido automaticamente para uma transferência, para quem tiver o arquivo poder confirmar que é genuíno e ver se ainda combina com o personagem. Veja [Fichas Assinadas](signed-sheets.md).

## Tarefas comuns

### Exportar o seu próprio personagem

1. Abra a Ficha do seu personagem.
2. Escolha **Exportar para Grapevine (.gex)**, clique em **Ir** e depois em **Baixar arquivo .gex**. Veja [Imprimir / Exportar](sheet-print-export.md).

### Trazer um personagem de um arquivo

1. Abra [Importar](import.md) e envie o arquivo `.gex`.
2. Resolva toda duplicata e todo traço sinalizado.
3. Confirme.

### Enviar um personagem a outra crônica

1. Abra a Ficha do personagem, escolha **Enviar Ficha** e clique em **Ir**.
2. Preencha os dados da outra crônica, ou deixe em branco para só baixar o arquivo. Veja [Enviar Ficha](transfer.md).

### Conferir se um arquivo exportado é genuíno

1. Abra [Verificar Personagem](verify.md) e digite o código de verificação dele.

## O que saber

- **Sobrescrever mantém o que o arquivo não leva.** Importar por cima de um personagem existente substitui o que o arquivo fornece e deixa o resto da ficha - uma seção que a sua crônica adicionou, ou qualquer coisa para a qual o Grapevine não tem lugar - exatamente como estava.
- **Nada é gravado até você confirmar ou aceitar.** Toda etapa de prévia é somente leitura, e uma confirmação se aplica de uma vez, tudo ou nada.
- **Um código de verificação, depois de emitido para uma exportação, nunca expira por conta própria** - o código de uma transferência é a exceção, e expira em 60 dias se ninguém a aceita.
- **O formato é detectado automaticamente.** Envie o `.gex` que tiver - a página Importar rejeita um arquivo `.gv3` com uma mensagem que aponta para a ferramenta Arquivo de Jogo Completo, e um `.gex` enviado lá pelo caminho inverso.
- **Um `.gex` binário leva os endereços de e-mail dos jogadores**, que o Beyond Elysium pode casar automaticamente com uma conta; um `.gex` XML não leva, então todo personagem precisa ter o jogador atribuído à mão depois.

## Solução de problemas

- **"O tipo de criatura deste personagem não tem equivalente no Grapevine, então ele não pode ser exportado ou transferido."** Um tipo de criatura adicionado por um administrador, sem formato de exportação correspondente - nada pode ser feito daqui.
- **"Este arquivo não é um arquivo de intercâmbio do Grapevine reconhecido."** O arquivo não é um `.gex` de verdade - se for um arquivo de crônica completo, use Arquivo de Jogo Completo.
- **A minha exportação não tem o meu texto só para Narradores.** Esperado na exportação de um jogador - um Narrador pode incluí-lo na própria exportação do mesmo personagem.
- **Um personagem trocado parece estar sem algo.** Confira O que não viaja acima antes de supor que algo deu errado - algumas coisas nunca são levadas, de propósito.

## Relacionados

- [Importar](import.md)
- [Enviar Ficha](transfer.md)
- [Enviar um Arquivo do Grapevine](send-grapevine-file.md)
- [Imprimir / Exportar](sheet-print-export.md)
- [Verificar Personagem](verify.md)
- [Fichas Assinadas](signed-sheets.md)
- [Itens e Locais](world-objects.md)
- [Conteúdo Só para Narradores](storyteller-only.md)
- [Guia do Narrador: Importando do Grapevine](../st-guide.md#5-importando-do-grapevine)
