# Configurações de Assistência de IA (Crônica)

Liga o botão de Assistência de IA para os campos longos da própria crônica e, opcionalmente, dá à crônica o seu próprio provedor de IA e chave em vez dos do site.

## Quem pode usar

Os Narradores (HST e AST). Um Condutor de Trama, a Harpia da crônica e um jogador nunca veem esta aba - e até um Narrador que liga isto aqui só vê o botão de Assistência de IA em si nos campos que já pode gerenciar. Veja [Assistência de Escrita por IA](writing-assist.md) para saber quais são, campo a campo.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Configuração da Crônica → aba Assistência de IA.

## A tela

- **Crônica** - uma lista suspensa com toda crônica da instalação.
- Uma nota de que qualquer chave digitada aqui precisa ser uma chave de API real do console de desenvolvedor da OpenAI ou da Anthropic - nunca um login do ChatGPT Plus ou do Claude Pro - e de que deixar a chave em branco usa a chave do site inteiro.
- **Ativar Assistência de IA para esta crônica** - uma caixa de seleção. Nada abaixo aparece até ela ser marcada.
- Depois de ativada:
  - **Provedor** - uma lista suspensa: **OpenAI (ChatGPT)**, **Claude** ou **Auto-hospedado (compatível com OpenAI)**. Só os campos da opção escolhida aparecem abaixo, e escolher uma salva na hora.
  - **OpenAI** ou **Claude** mostra um conjunto de campos: **A chave de API própria desta crônica** (uma caixa de senha, que mostra um texto "configurada" depois que existe uma), um botão **Limpar** depois que existe uma e **Testar Conexão**.
  - **Auto-hospedado (compatível com OpenAI)** mostra uma nota sobre servidores compatíveis (Ollama, LM Studio, vLLM, LocalAI e similares), depois **URL base da API**, **Modelo**, **A chave de API própria desta crônica**, **Limpar** e **Testar Conexão**.
  - **Salvar**.
- Uma linha "Salvo." aparece depois de um salvamento bem-sucedido. **Testar Conexão** relata o resultado na própria linha, sob os campos de qualquer provedor que estiver aparecendo.

## Tarefas comuns

### Ligar a Assistência de IA para esta crônica

1. Marque **Ativar Assistência de IA para esta crônica**.
2. Escolha um **Provedor**.
3. Clique em **Salvar**.

### Dar à crônica a própria chave

1. Escolha o **Provedor** a que a chave pertence.
2. Digite a chave em **A chave de API própria desta crônica**.
3. Clique em **Testar Conexão** para conferi-la.
4. Clique em **Salvar**.

### Trocar de provedor

1. Escolha outra opção em **Provedor** - isso salva na hora.
2. Preencha os campos próprios desse provedor e clique em **Salvar**.

### Remover a chave própria da crônica

1. Clique em **Limpar** ao lado do campo de chave desse provedor.

### Apontar a crônica para um servidor auto-hospedado

1. Defina **Provedor** como **Auto-hospedado (compatível com OpenAI)**.
2. Preencha **URL base da API** e **Modelo**.
3. Digite a chave do servidor, se ele precisar de uma.
4. Clique em **Testar Conexão** e depois em **Salvar**.

## O que saber

- **Uma chave salva nunca é mostrada de novo.** O campo diz "configurada" depois que existe uma; digitar algo novo a substitui, **Limpar** a remove, e testar uma chave salva significa digitá-la de novo primeiro - uma chave guardada nunca é enviada de volta a esta página.
- **Deixar a chave em branco usa a chave do site inteiro** - e, para Auto-hospedado, o servidor do site inteiro também. A crônica herda o que um administrador do site configurou, a menos que você substitua aqui.
- **O Provedor salva no instante em que você o escolhe**, antes de você tocar em Salvar - só os campos de chave, URL e modelo esperam o Salvar.
- **Isto precisa ser uma chave de API de desenvolvedor de verdade, cobrada por pedido** - não um login do ChatGPT Plus ou do Claude Pro, que não podem ser usados aqui.
- **Auto-hospedado não é um quarto provedor.** É o mesmo formato de pedido da OpenAI, apontado para o endereço e o modelo do seu próprio servidor.
- **Isto só afeta os campos de personagem, trama, rumor e objeto do mundo desta própria crônica.** Os campos de todo o catálogo, como a descrição de um traço compartilhado, sempre usam a configuração do site inteiro.

## Solução de problemas

- **"Digite a chave acima primeiro - uma chave salva nunca é enviada de volta para esta página, então ela precisa ser reinserida para ser testada."** Digite a chave (ou, para Auto-hospedado, a URL e o modelo) antes de clicar em Testar Conexão.
- **"Não foi possível conectar com essas configurações."** A chave, a URL ou o modelo está errado - confira com quem emitiu a chave. Esta tela nunca relata um motivo mais específico que esse.
- **Não vejo esta aba.** Você precisa de um papel de Narrador na crônica escolhida no momento.
- **Liguei isto mas ninguém vê um botão de Assistência de IA.** O botão é protegido por campo pelo papel de gerenciamento mais amplo daquele conteúdo - um Condutor de Trama, a Harpia ou um jogador nunca o vê, nem aqui. Veja [Assistência de Escrita por IA](writing-assist.md).
- **Não vejo os campos que espero.** Só os campos do **Provedor** escolhido aparecem - troque-o para ver os outros.

## Relacionados

- [Assistência de Escrita por IA](writing-assist.md)
- [Configuração da Crônica](chronicle-setup.md)
- [Configurações de Ação e Rumor](apr-settings.md)
- [Configurações de Assistência de IA (Site)](writing-assist-site.md)
- [Guia do Administrador](../admin-guide.md#configurando)
