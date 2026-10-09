# Configurações de Assistência de IA (Site)

O provedor de IA e a chave de todo o site: usados direto para a Assistência de IA em nível de catálogo (descrições de Bloco de Esquema, texto dos Créditos) e como recurso de reserva para qualquer crônica que liga a Assistência de IA sem fornecer uma chave própria.

## Quem pode usar

Só o administrador do site - o papel Administrador do WordPress. Nenhum papel de crônica alcança esta tela, HST e AST inclusive: o provedor de Assistência de IA e a chave próprios de uma crônica são definidos em [Configurações de Assistência de IA (Crônica)](writing-assist-chronicle.md), que qualquer Narrador alcança para a própria crônica.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Configuração do Sistema → aba Assistência de IA.

## A tela

- Uma nota de que qualquer chave digitada aqui precisa ser uma chave de API real do console de desenvolvedor da OpenAI ou da Anthropic - nunca um login do ChatGPT Plus ou do Claude Pro.
- **Provedor** - uma lista suspensa: **OpenAI (ChatGPT)**, **Claude** ou **Auto-hospedado (compatível com OpenAI)**. Só os campos da opção escolhida aparecem abaixo.
- **OpenAI** ou **Claude**, o que estiver escolhido, mostra: **Chave de API** (uma caixa de senha, que diz "•••••••• (configurada - deixe em branco para manter)" depois que existe uma), **Limpar** depois que existe uma e **Testar Conexão**.
- **Auto-hospedado (compatível com OpenAI)** mostra uma nota sobre servidores compatíveis (Ollama, LM Studio, vLLM, LocalAI e similares), depois **URL base da API**, **Modelo**, **Chave de API**, **Limpar** e **Testar Conexão**.
- **Salvar**.

Uma linha "Salvo." aparece depois de um salvamento bem-sucedido. **Testar Conexão** relata o resultado na própria linha, sob os campos de qualquer provedor que estiver aparecendo.

## Tarefas comuns

### Ligar o provedor de IA do site

1. Escolha um **Provedor**.
2. Digite uma **Chave de API**.
3. Clique em **Testar Conexão** para conferi-la.
4. Clique em **Salvar**.

### Trocar de provedor

1. Escolha outra opção em **Provedor**.
2. Preencha os campos próprios desse provedor.
3. Clique em **Salvar**.

### Apontar o site para um servidor auto-hospedado

1. Defina **Provedor** como **Auto-hospedado (compatível com OpenAI)**.
2. Preencha **URL base da API** e **Modelo**.
3. Digite a chave do servidor, se ele precisar de uma.
4. Clique em **Testar Conexão** e depois em **Salvar**.

### Remover a chave guardada

1. Clique em **Limpar** ao lado do campo de chave.

## O que saber

- **Escolher um Provedor não o salva por si só.** Ao contrário da versão desta tela para a crônica, trocar o **Provedor** aqui só muda o que você está olhando - nada é gravado até você clicar em **Salvar**, que salva o Provedor escolhido no momento junto com quaisquer mudanças de chave, URL ou modelo abaixo dele.
- **Uma chave salva nunca é mostrada de novo.** O campo diz "configurada" depois que existe uma; digitar algo novo a substitui, **Limpar** a remove, e testar uma chave salva significa digitá-la de novo primeiro - uma chave guardada nunca é enviada de volta a esta página.
- **Este é o recurso de reserva, não a configuração própria de uma crônica.** Qualquer crônica que liga a Assistência de IA sem uma chave própria usa esta; uma crônica com a chave própria ignora esta tela por completo para os próprios campos. Veja [Configurações de Assistência de IA (Crônica)](writing-assist-chronicle.md).
- **Mais que o botão da crônica depende desta chave.** O editor de descrição do catálogo de Blocos de Esquema e o campo de texto dos Créditos são de todo o site, não restritos a uma crônica, então sempre usam esta chave, nunca a própria de uma crônica.
- **Isto precisa ser uma chave de API de desenvolvedor de verdade, cobrada por pedido** - não um login do ChatGPT Plus ou do Claude Pro, que não podem ser usados aqui.
- **Auto-hospedado não é um quarto provedor.** É o mesmo formato de pedido da OpenAI, apontado para o endereço e o modelo do seu próprio servidor - não há um equivalente auto-hospedado oferecido para o formato da API do próprio Claude.

## Solução de problemas

- **"Digite a chave acima primeiro - uma chave salva nunca é enviada de volta para esta página, então ela precisa ser reinserida para ser testada."** Digite de novo a chave (ou, para Auto-hospedado, a URL e o modelo) antes de clicar em Testar Conexão.
- **"Não foi possível conectar com essas configurações."** A chave, a URL ou o modelo está errado - confira com quem emitiu a chave. Esta tela nunca relata um motivo mais específico que esse.
- **Não vejo esta aba.** Você precisa do papel Administrador do site - um Editor, até um que gerencia as configurações de Assistência de IA da própria crônica, não alcança esta tela.
- **A Assistência de IA de uma crônica ainda não funciona depois que configurei isto.** Confirme que a própria crônica ligou a Assistência de IA - esta tela só fornece a chave de reserva, não ativa o recurso para nenhuma crônica. Veja [Configurações de Assistência de IA (Crônica)](writing-assist-chronicle.md).
- **Escolhi outro Provedor e parece que nada mudou.** Clique em **Salvar** - escolher um Provedor aqui só muda o que é mostrado na tela até você fazê-lo.
- **A Assistência de IA parou de funcionar depois que as chaves de segurança do site mudaram.** Uma chave salva é criptografada com as chaves secretas do próprio WordPress do site, então mudá-las (ou mover o site para um novo arquivo de configuração sem elas) a deixa ilegível. Digite a chave de novo e clique em **Salvar**.

## Relacionados

- [Configurações de Assistência de IA (Crônica)](writing-assist-chronicle.md)
- [Assistência de Escrita por IA](writing-assist.md)
- [Blocos de Esquema](schema-blocks.md)
- [Créditos](credits.md)
- [Jogos](games.md)
- [Guia do Administrador](../admin-guide.md#configurando)
