# Impressão Segura

Se as fichas de personagem e os relatórios impressos levam uma assinatura digital, o que este site tem configurado e como conseguir um certificado de assinatura se a sua hospedagem não deixa você criar um.

## Quem pode usar

Os administradores do site. Um Narrador conduz uma crônica; uma chave de assinatura pertence a quem administra o servidor, então o gerador de certificado é só para administradores, embora o resto da tela seja visível a quem pode gerenciar crônicas.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Configuração do Sistema → aba **Impressão Segura**.

## Imprimir sempre funciona

Esta é a parte importante, e é de propósito: **a impressão nunca se recusa**. Com a impressão segura desligada, sem certificado instalado, ou numa hospedagem que não consegue assinar de jeito nenhum, as fichas e relatórios ainda imprimem pelo mesmo compositor e saem com a mesma aparência. Toda página é carimbada com **NÃO ASSINADO**.

Uma impressão sem assinatura nunca pode ser confundida com uma assinada, e uma crônica que nunca vai ter um certificado não fica sem poder imprimir. O carimbo é a resposta para essas crônicas, não um prêmio de consolação.

## As duas coisas que precisam ser verdade juntas

Uma impressão só é assinada quando **as duas** valem:

1. Um certificado utilizável está configurado, por três constantes do `wp-config.php`.
2. Um administrador marcou **Assinar fichas e relatórios impressos** nesta tela.

A chave existe à parte do certificado de propósito. Um certificado chegar ao servidor não é o mesmo que uma decisão de assinar com ele - você pode estar testando um, ou ter herdado um de quem administrava o site antes. A assinatura continua desligada até alguém dizer que não.

A chave vale para todo o site, não por crônica, porque o certificado vale para todo o site. Uma chave por crônica implicaria certificados por crônica, o que multiplica a única coisa realmente delicada aqui: lidar com uma chave privada.

## Instalando um certificado

O plugin nunca guarda a sua chave privada. Ela nunca é enviada pelo navegador, nunca é gravada no banco de dados e nunca é guardada na pasta de envios. Você coloca os arquivos no servidor, por SFTP, em algum lugar fora da raiz web, e aponta três constantes para eles no `wp-config.php`:

```php
define( 'BE_PDF_SIGNING_CERT', '/home/you/private/be-signing.crt' );
define( 'BE_PDF_SIGNING_KEY', '/home/you/private/be-signing.key' );
define( 'BE_PDF_SIGNING_PASSPHRASE', 'your passphrase' );
```

Se a chave não tem senha, deixe a terceira constante de fora. É uma configuração real, não um engano, e a tela a relata como tal.

A tabela **Status** diz quais das três estão definidas e se os dois arquivos podem de fato ser lidos - nunca a chave em si, e nunca a senha.

### Se você tem acesso ao shell

Este é o comando que as duas crônicas de produção usaram:

```sh
openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 \
  -keyout be-signing.key -out be-signing.crt -cipher aes-256-cbc
```

## Se a sua hospedagem não tem shell

Muita hospedagem compartilhada não dá linha de comando, então `openssl req` simplesmente não está disponível - e isso, não saber onde pôr um arquivo, é o que deixa uma crônica sem impressão assinada para sempre.

A tela pode criar um par para você. Dê a ela um nome de assinante e uma senha, e ela monta um certificado autoassinado e uma chave privada criptografada **na memória** e os entrega a você uma única vez.

Nada é gravado no servidor e nada é salvo no banco de dados - nem a chave, nem a senha, nem o certificado. Copie os dois arquivos para um lugar seguro antes de sair da página. Peça de novo e você recebe um certificado diferente, não o mesmo de volta.

Daí em diante é igual ao acima: envie os dois arquivos por SFTP para algum lugar fora da raiz web e adicione as três constantes.

### Se o botão não está lá

Gerar exige a extensão `openssl` do PHP. Onde ela falta, a tela diz isso em vez de oferecer um botão que não pode funcionar.

Isso não é uma exigência extra que este recurso inventa. Um PDF é assinado por essa mesma extensão, então uma hospedagem sem ela não pode assinar uma ficha, venha de onde vier o certificado. Esses sites imprimem sem assinatura, que é exatamente para o que serve o carimbo NÃO ASSINADO.

## "Assinatura válida, signatário não confiável"

Um leitor de PDF dirá algo assim sobre um certificado autoassinado, em vez de mostrar um tique verde. Isso é esperado, e não é uma falha.

Um tique verde quer dizer que uma autoridade certificadora comercial garante o signatário. Um certificado autoassinado quer dizer que *esta crônica* garante o documento - que é o que um Narrador atestando a ficha do seu próprio jogador de fato é. A assinatura ainda prova que o arquivo não foi alterado desde que foi impresso, que é o que importa quando uma ficha viaja entre crônicas.

## Veja também

- [Imprimir e exportar uma ficha](sheet-print-export.md)
- [Fichas assinadas](signed-sheets.md)
- [Verificar uma ficha](verify.md)
- [Relatórios](reports.md)
