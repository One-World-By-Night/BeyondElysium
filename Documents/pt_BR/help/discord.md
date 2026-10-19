# Discord

Conecte o servidor do Discord de uma crônica, para que os jogadores vinculem a própria conta e leiam a ficha deles pelo Discord.

## Quem pode usar

Conectar e desconectar são do HST. Um AST vê o status da conexão, mas não os controles. Qualquer membro autenticado pode vincular a própria conta do Discord depois que uma crônica está conectada; um Narrador aprova o vínculo.

## Como chegar lá

- **Conectar:** a linha do Discord em [Configuração da Crônica](chronicle-setup.md).
- **Aprovar um vínculo:** a seção Discord em [Jogadores](chronicle-players.md).
- **Vincular a sua própria conta:** um link de confirmação que o bot te dá depois de rodar `/link` no servidor do Discord, que abre um painel em [Minha Crônica](my-chronicle.md).

## A tela

- **A linha do Discord em Configuração da Crônica:**
  - Não conectado: **Gerar um código de conexão** - um código de uso único, com 15 minutos, e um comando `/connect` pronto para rodar, com um link para convidar o bot quando um está configurado.
  - Conectado: o nome do servidor, de quando foi ouvido por último, e **Desconectar**.
- **A seção Discord em Jogadores:**
  - **Aguardando decisão** - um vínculo confirmado com **Aprovar** e **Recusar**.
  - **Vinculada** - um vínculo aprovado com **Revogar**.
  - Cada linha nomeia a conta do Discord, a conta do site a que ela foi confirmada e os personagens que carrega.
- **O painel de vínculo de Minha Crônica** (só quando você seguiu um link de confirmação): o nome da conta do Discord, os seus personagens nessa crônica para marcar, e **Confirmar**.

## Tarefas comuns

### Conectar o servidor do Discord de uma crônica

1. Abra [Configuração da Crônica](chronicle-setup.md) e abra a linha do Discord.
2. Clique em **Gerar um código de conexão**.
3. No servidor do Discord, rode o comando `/connect` mostrado, com o código.
4. A linha mostra **Conectado** quando o bot responde.

### Vincular a sua conta do Discord

1. No servidor do Discord, rode `/link`.
2. O bot te dá um link. Abra-o.
3. Entre com a sua conta, se pedir.
4. Marque os personagens que essa conta do Discord deve carregar, depois clique em **Confirmar**.
5. Um Narrador o aprova em [Jogadores](chronicle-players.md).

### Aprovar ou recusar um vínculo

1. Abra [Jogadores](chronicle-players.md).
2. Ache a conta em **Aguardando decisão**.
3. Clique em **Aprovar** ou em **Recusar**.

### Desconectar

1. Abra a linha do Discord em [Configuração da Crônica](chronicle-setup.md).
2. Clique em **Desconectar**.
3. Os jogadores continuam vinculados; só não podem agir pelo Discord até a crônica conectar de novo.

## Além de conectar e vincular

Depois que um jogador está vinculado, o bot é uma segunda forma de acessar a própria crônica, não só o site:

- **Fichas.** Leia o seu próprio personagem, ou qualquer personagem que um Narrador nomeie, com `/sheet`.
- **Estado temporário.** Gaste e recupere pontos de reservas, sofra e cure feridas, com `/spend`, `/regain`, `/wound` e `/heal` - nenhum deles precisa de aprovação. `/status` mostra as reservas, a saúde e as perdas da noite.
- **Desafios.** Desafios de Leis da Noite Revisadas e testes estáticos, resolvidos pelo que cada lado afirma, com `/challenge`, `/static`, `/test` e `/challenges`.
- **Postagem em personagem.** Poste como um personagem por um gatilho, um bloqueio, ou `/as`.
- **Noite de jogo.** `/checkin`, `/aar`, `/downtime`, `/scene` e `/plot`; um Narrador define a sala de um jogador vinculado com `/room`.
- **Dados.** Notação geral ou uma reserva de Mundo das Trevas, com `/roll`.

Todo comando roda como a conta vinculada real, então segue exatamente as mesmas regras de permissão e aprovação do site. Rode `/help` no servidor do Discord para a lista completa, ou `/help topic:<área>` para uma área em detalhe.

## O que saber

- **Um servidor, uma crônica.** Um servidor do Discord conecta a exatamente uma crônica, e uma crônica a exatamente um servidor.
- **Um código funciona uma vez e dura 15 minutos.** Um novo substitui o antigo.
- **Desconectar mantém todo vínculo.** Uma conta do Discord é a mesma conta de qualquer servidor que ela venha; reconectar não faz ninguém vincular de novo.
- **Um Narrador é aprovado na hora.** Rodar `/link` como Narrador pula a espera.
- **Agir pelo Discord é agir como você.** O que quer que o bot faça em seu nome segue exatamente as mesmas regras, aprovações e limites que fazer isso você mesmo no site.

## Solução de problemas

- **O código de conexão não funcionou.** Os códigos duram 15 minutos e funcionam uma vez. Gere um novo.
- **"Esta crônica já tem um servidor do Discord conectado."** Desconecte-o primeiro, em Configuração da Crônica.
- **Meu vínculo diz que expirou.** Rode `/link` de novo no Discord para um novo.
- **Não vejo o botão Conectar.** Só o HST pode conectar ou desconectar; um AST vê só o status.

## Relacionados

- [Configuração da Crônica](chronicle-setup.md)
- [Jogadores](chronicle-players.md)
- [Minha Crônica](my-chronicle.md)
