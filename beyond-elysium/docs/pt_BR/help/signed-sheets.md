# Fichas Assinadas

Toda ficha de personagem ou relatório impresso é um PDF de verdade que o próprio site da sua crônica gera - nunca uma impressão do navegador - e, quando o site tem um certificado de assinatura, assinado digitalmente: a prova de que os valores dos traços nele não foram editados desde que foi gerado.

## Quem pode usar

Qualquer pessoa que pode imprimir uma ficha ou relatório recebe um assinado automaticamente sempre que a assinatura está disponível - não há configuração à parte para ligar para si mesmo. Configurar a assinatura, para começar, é um trabalho único de quem cuida da hospedagem do site, não algo que um Narrador ou jogador possa fazer por dentro do Beyond Elysium.

## Como chegar lá

Esta não é uma tela própria - todo **Imprimir / Exportar**, todo **Gerar PDF** de relatório e toda exportação de personagem se apoia na mesma configuração de assinatura. Veja [Imprimir / Exportar](sheet-print-export.md) e [Relatórios](reports.md).

## A tela

**O que uma assinatura prova, e o que não prova.** Uma assinatura digital prova que o documento não mudou desde que foi gerado - os valores dos traços, o XP, tudo na página é exatamente o que o site produziu. Não prova que a ficha ainda está atual: o personagem pode ter mudado desde então. Trate uma ficha assinada como um registro daquele momento, não uma visão ao vivo.

**"Assinatura válida, signatário não confiável."** Um certificado autoassinado - o tipo que o Beyond Elysium usa - faz os leitores de PDF mostrarem isto em vez de uma marca verde simples. Isso é esperado, não é um problema: significa que o arquivo realmente não foi alterado, conferido contra um certificado em que o seu leitor só ainda não foi instruído a confiar, do mesmo jeito que o certificado de um site novo parece diferente na primeiríssima vez que você o visita. Confiar nele uma vez, pelo painel de assinaturas do próprio leitor, faz com que ele apareça como totalmente válido depois.

**Sem um certificado configurado.** As fichas e relatórios ainda imprimem - toda página sai carimbada com NÃO ASSINADO em vermelho, e o nome do arquivo termina em `-unsigned.pdf`. Uma cópia sem assinatura não prova nada sobre ter sido editada ou não, então não a trate como prova da ficha de um personagem visitante.

**Códigos de verificação.** Um jeito à parte de provar que um documento é genuíno, embutido numa exportação do Grapevine e numa transferência de crônica - não um PDF assinado. Marcar **Incluir código de verificação** antes de exportar embute no arquivo um código curto e um link; quem o tiver pode conferir esse código em [Verificar Personagem](verify.md) para confirmar que a crônica emissora de fato o emitiu, e ver se o personagem ainda combina com ele hoje (nome, status, XP ganho, XP não gasto e a ficha como um todo). O código não aparece na tela - ele é gravado no próprio arquivo. O código de uma exportação nunca expira por conta própria; o código de uma transferência expira depois de 60 dias se ninguém a aceita, e deixa de funcionar no instante em que a transferência deixa.

Um PDF assinado e um código de verificação conferem duas coisas diferentes: a assinatura do PDF prova que o próprio arquivo está intacto, enquanto um código prova que a crônica que ele diz ter o emitido de fato o emitiu, e diz se o personagem mudou desde então. Um PDF não leva um código, e um código não vem com um PDF.

## Tarefas comuns

### Conferir se um PDF assinado está intacto

1. Abra-o num leitor de PDF que mostre o status da assinatura - a maioria mostra automaticamente.
2. Confie no certificado uma vez, se o seu leitor pedir - ele aparece como válido todas as vezes depois.

### Conferir se um arquivo exportado é genuíno

1. Ache o código de verificação dele dentro do arquivo.
2. Abra [Verificar Personagem](verify.md), digite o código e clique em **Verificar**.

### Incluir um código de verificação numa exportação

1. Abra a Ficha do personagem, escolha **Exportar para Grapevine (.gex)** e clique em **Ir**.
2. Marque **Incluir código de verificação**.
3. Clique em **Baixar arquivo .gex**.

### Configurar a assinatura de um site (quem cuida da hospedagem)

1. Gere um certificado de assinatura autoassinado e uma chave.
2. Guarde os dois acima da raiz web (nunca dentro dela) e adicione `BE_PDF_SIGNING_CERT`, `BE_PDF_SIGNING_KEY` e `BE_PDF_SIGNING_PASSPHRASE` ao `wp-config.php`.
3. Imprima uma ficha ou relatório depois - ele sai assinado em vez de carimbado com NÃO ASSINADO.

Veja a [seção de fichas assinadas do Guia do Narrador](../st-guide.md#11-fichas-de-personagem-assinadas) para os comandos exatos.

## O que saber

- **A assinatura é por site, não por crônica.** Toda crônica da mesma instalação compartilha o mesmo certificado, depois que um é configurado.
- **Isto substitui por completo a impressão do navegador.** Há um só botão Imprimir, e ele sempre produz um PDF de verdade - assinado, ou carimbado com NÃO ASSINADO.
- **Um código de verificação revogado ainda dá um resultado de verdade, não um erro simples** - ele diz que a crônica emissora o retirou, diferente de um código que nunca existiu.
- **O código de verificação de um personagem excluído ainda resolve** - só relata todas as conferências como não combinando mais, já que o personagem para o qual foi emitido sumiu.
- **Nada privado nunca aparece em Verificar Personagem** - nenhuma ficha completa, biografia, notas nem o jogador do personagem, só o punhado de fatos da sua lista de conferência.
- **Um relatório também pode ser assinado.** Cada um dos 20 relatórios segue a mesma regra de assinado/NÃO ASSINADO de uma ficha de personagem.

## Solução de problemas

- **Meu PDF diz NÃO ASSINADO.** O site ainda não tem um certificado de assinatura configurado - um passo da hospedagem, não um problema com o personagem. Veja [Imprimir / Exportar](sheet-print-export.md).
- **"Este código não corresponde a nenhum registro de verificação."** Confira se foi digitado corretamente - um código desconhecido, expirado e malformado mostram todos esta mesma mensagem.
- **"Verificações demais desta conexão."** Espere um minuto e tente de novo - Verificar Personagem limita a frequência com que a mesma conexão pode conferir.
- **Meu leitor diz "signatário não confiável".** Esperado com um certificado autoassinado - veja O que uma assinatura prova, e o que não prova, acima.

## Relacionados

- [Imprimir / Exportar](sheet-print-export.md)
- [Verificar Personagem](verify.md)
- [Ficha de Personagem](character-sheet.md)
- [Relatórios](reports.md)
- [Enviar Ficha](transfer.md)
- [Importar/Exportar do Grapevine](grapevine.md)
- [Guia do Narrador: Fichas de Personagem Assinadas](../st-guide.md#11-fichas-de-personagem-assinadas)
- [Guia do Jogador: Imprimindo Sua Ficha](../player-guide.md#8-imprimindo-sua-ficha)
