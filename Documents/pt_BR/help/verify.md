# Verificar Personagem

Uma página pública que confere um código de verificação e informa se o documento que o trouxe - um arquivo de personagem exportado, uma transferência de crônica ou um Cartão de Item impresso - é genuíno, e se ainda combina com o personagem ou item hoje.

## Quem pode usar

Qualquer pessoa. Não é preciso conta nem ser membro de uma crônica - esta página é aberta ao público, igual ao código impresso ou embutido no documento que ela confere.

## Como chegar lá

Abra a página Verificar Personagem diretamente, ou siga um link que já traga um código (um embutido num arquivo exportado aponta para cá). Peça o endereço da página a um Narrador ou ao administrador do seu site se você não tiver um link.

## A tela

- **Código de verificação** - uma caixa de texto (com o texto de exemplo "XXXX-XXXX") e **Verificar**. Se o próprio endereço web da página já traz um código, ele é conferido automaticamente na abertura.
- O resultado, depois de conferido, tem uma de duas formas, conforme para o que o código foi emitido:
  - **Um documento de personagem** - um aviso dizendo que a atestação é genuína, ou que foi revogada e não deve mais ser tratada como válida; **Personagem**, **Tipo de criatura**, **Status**, **Experiência** (ganha / não gasta), **Emitido por** (um link para a crônica emissora), **Emitido em** e **Tipo de documento** (Exportação do Grapevine ou Transferência de crônica); e **Ainda corresponde ao personagem hoje?** - uma lista de conferência (Nome, Status, XP ganho, XP não gasto, Ficha completa), cada um marcado sim ou não, mais quando foi conferido. Não é mostrada para um código revogado.
  - **Um cartão de item** - um aviso dizendo que o código é genuíno, ou revogado; **Item**, **Crônica** e **Emitido em**; uma nota se o item está **esgotado** ou **expirou**; e **Ainda corresponde ao item hoje?** - uma lista de conferência (Detentor, Usos restantes, Validade), cada um marcado sim ou não. Não é mostrada para um código revogado. Veja [Itens e Locais](world-objects.md).

## Tarefas comuns

### Conferir um código

1. Abra a página Verificar Personagem.
2. Digite o código em **Código de verificação** (um hífen no meio é opcional).
3. Clique em **Verificar**.

### Conferir um código que já está num link

1. Abra o link. A página o confere automaticamente e mostra o resultado.

## O que saber

- **O código não aparece na tela quando um personagem é exportado.** Ele é gravado no próprio arquivo - um Narrador ou qualquer pessoa que leia o documento exportado pode achá-lo ali, junto com um link direto para esta página.
- **O código de um cartão de item é impresso no próprio cartão** - "Verify: {link}" - sempre que Cartões de Item ou Imprimir Meus Itens é gerado. Imprimir de novo o cartão do mesmo item reutiliza o mesmo código enquanto nada nele (o nome, os usos restantes ou a validade) mudou desde então; mudar qualquer um deles, ou dá-lo a um personagem novo, imprime um código novo - o antigo continua ativo e só relata a divergência em vez de parar de funcionar.
- **Um código genuíno ainda pode dizer que as coisas não combinam.** "Genuíno" quer dizer que o documento realmente foi emitido pela crônica que diz ter o emitido - não diz nada sobre o personagem ou item ter mudado desde então. Leia a lista de conferência para isso.
- **Um personagem excluído é relatado como totalmente mudado**, não como erro - todo item da lista de conferência mostra não quando o personagem para o qual foi emitido não existe mais. Os códigos de um item excluído são revogados de vez, já que a exclusão não deixa nada com que comparar.
- **Um código emitido para uma exportação nunca expira por conta própria.** O código de uma transferência de crônica é a exceção - ele expira se ninguém a aceita em 60 dias. O código de um item também nunca expira por conta própria; um Narrador o revoga à mão com **Revogar Cartões** no próprio item se ele deve parar de funcionar. Veja [Itens e Locais](world-objects.md).
- **Nada privado é mostrado.** Esta página nunca revela a ficha completa, a biografia, as notas, o jogador do personagem nem qualquer conteúdo só para Narradores - só o punhado de fatos listados acima e se ainda combinam.
- **As conferências têm limite de frequência.** Muitas conferências em pouco tempo da mesma conexão são recusadas por um instante - isso protege o serviço, não um código em particular.
- **Um Narrador que revisa um arquivo do Grapevine enviado por um jogador vê esta mesma conferência automaticamente.** Quando um arquivo assim traz um código, a própria tela de revisão o confere contra a crônica emissora sem ninguém visitar esta página à mão - veja [Enviar um Arquivo do Grapevine](send-grapevine-file.md).

## Solução de problemas

- **"Este código não corresponde a nenhum registro de verificação. Verifique se foi digitado corretamente."** Confira se foi digitado corretamente. Um código desconhecido, expirado ou malformado mostra esta mesma mensagem - não há como saber qual deles a partir daqui.
- **"Muitas verificações desta conexão. Tente novamente em um minuto."** Espere um minuto e tente de novo.
- **"Esta atestação foi revogada pela crônica emissora." / "Este código de verificação foi revogado pela crônica que o emitiu."** A crônica que o emitiu o retirou - trate o documento ou cartão como não mais válido, mesmo que o código em si ainda resolva.
- **Todo item da lista de conferência diz não.** Ou o personagem realmente mudou desde que este documento foi emitido, ou o personagem não existe mais.
- **Detentor diz não num cartão de item.** O item foi transferido para outra pessoa desde então - o cartão em si continua conferindo como genuíno, só nomeia o detentor errado agora.

## Relacionados

- [Ficha de Personagem](character-sheet.md)
- [Imprimir / Exportar](sheet-print-export.md)
- [Enviar Ficha](transfer.md)
- [Enviar um Arquivo do Grapevine](send-grapevine-file.md)
- [Fichas Assinadas](signed-sheets.md)
- [Itens e Locais](world-objects.md)
- [Relatórios](reports.md)
- [Guia do Jogador](../player-guide.md#8-imprimindo-sua-ficha)
