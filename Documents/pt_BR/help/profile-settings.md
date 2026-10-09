# Configurações de Perfil

Campos do Beyond Elysium na tela de perfil comum do WordPress: se você recebe e-mail sobre as mudanças aprovadas ou rejeitadas dos seus próprios personagens, como você fica sabendo de postagens de trama e de coisas novas que os seus personagens podem ver e - só para o administrador do site - se um usuário específico pode personalizar como as suas fichas de personagem parecem.

## Quem pode usar

Todos com uma conta no site veem **Notificações de Alteração** e **Postagens de tramas e novidades que seus personagens podem ver** no próprio perfil. Só um administrador do site vê **Personalização da Ficha**, no próprio perfil ou no de qualquer outra pessoa.

## Como chegar lá

Barra lateral do wp-admin → **Perfil** (um administrador do site vê isto aninhado em **Usuários → Seu Perfil**). Um administrador do site também pode abrir a versão desta tela de outro usuário em **Usuários → Todos os Usuários**, editando esse usuário.

## A tela

Uma seção "Beyond Elysium" aparece na tela de perfil, abaixo dos campos do próprio WordPress:

- **Personalização da Ficha** - uma caixa de seleção, mostrada só a um administrador do site: "Permitir que este usuário personalize suas próprias fichas de personagem (fonte, cores, imagem de fundo, gráficos de seção)." Marcá-la para um usuário e salvar o perfil deixa essa pessoa usar [Personalizar Aparência](sheet-customize.md) em qualquer ficha que ela já possa editar.
- **Notificações de Alteração** - uma caixa de seleção, mostrada a quem edita um perfil que pode editar: "Não me enviar e-mail quando um narrador aprovar ou rejeitar uma das minhas alterações de personagem enviadas." Marcada quer dizer que você optou por sair; desmarcada (o padrão) quer dizer que você recebe esse e-mail.

Os dois salvam com o próprio botão **Atualizar Perfil** da tela de perfil - não há um salvar à parte só para esses dois.

## Tarefas comuns

### Desligar os e-mails de aprovação de alterações

1. Abra o seu próprio perfil.
2. Em "Beyond Elysium", marque "Não me enviar e-mail quando um narrador aprovar ou rejeitar uma das minhas alterações de personagem enviadas."
3. Clique em **Atualizar Perfil**.

### Conceder a personalização da ficha a um jogador

1. Como administrador do site, abra o perfil desse usuário em **Usuários → Todos os Usuários**.
2. Em "Beyond Elysium", marque "Permitir que este usuário personalize suas próprias fichas de personagem…"
3. Clique em **Atualizar Perfil**.

## O que saber

- **A concessão é por usuário, não por personagem nem por crônica.** Depois de marcada, essa pessoa pode estilizar todo personagem que já pode editar, em toda crônica a que pertence.
- **Os Narradores (HST e AST) já têm a personalização da ficha** sem esta caixa - é assim que um jogador comum a recebe. Veja [Personalizar Aparência](sheet-customize.md).
- **Este é o único pedaço do controle de notificações de alteração que um jogador tem diretamente.** Se uma crônica envia esses e-mails de qualquer modo é uma configuração separada, no nível da crônica, que um administrador do site controla - veja [Acesso à Crônica](chronicle-access.md). Optar por sair aqui quer dizer que você nunca recebe o e-mail, seja qual for essa configuração; deixá-la desmarcada só faz você receber o e-mail de uma crônica que tem as notificações ligadas.
- **Os seus Narradores podem ver que você optou por sair.** O [Registro de E-mails](email-log.md) de cada crônica mostra "A pessoa desativou os e-mails do Beyond Elysium" ao lado de tudo o que ele teria enviado a você. Não mostra mais nada sobre o motivo, e nunca o corpo de um e-mail.
- **Esta é uma tela de perfil do WordPress, não uma página do Beyond Elysium.** Todo o resto nela - seu nome, e-mail, senha - é WordPress comum, sem relação com este plugin.

## Solução de problemas

- **Não vejo uma seção "Beyond Elysium" de jeito nenhum.** Você está olhando um perfil que não pode editar - a maioria dos usuários só vê isto no próprio perfil.
- **Não vejo Personalização da Ficha.** Só um administrador do site vê esta caixa, em qualquer perfil.
- **Concedi a personalização da ficha mas o usuário ainda não vê Personalizar aparência numa ficha.** Confirme que a mudança realmente salvou (reabra o perfil dele e confira se a caixa continua marcada) e confirme que ele está olhando um personagem que já pode editar - a concessão não passa por cima da propriedade.
- **Ainda recebo e-mails de aprovação de alterações depois de optar por sair.** Confirme que você clicou em **Atualizar Perfil** depois de marcar a caixa - a caixa sozinha não vale até o perfil ser salvo.

## Relacionados

- [Personalizar Aparência](sheet-customize.md)
- [Ficha de Personagem](character-sheet.md)
- [Acesso à Crônica](chronicle-access.md)
- [Papéis](roles.md)
