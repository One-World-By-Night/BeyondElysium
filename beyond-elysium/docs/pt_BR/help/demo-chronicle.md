# Crônica de Demonstração

Marca uma crônica para ela se redefinir sozinha, numa agenda, a um ponto de partida declarado, em vez de ficar como um exemplo único. É uma coisa diferente da crônica `be-demo` que o plugin semeia uma vez numa instalação nova (veja a linha "Excluir crônica de demonstração" de [Jogos](games.md)) - aquela é um conjunto fixo e único de personagens de exemplo; este recurso pode transformar *qualquer* crônica de *qualquer* instalação numa crônica que se reconstrói sempre.

## Quem pode usar

Só uma conta de administrador do WordPress, o mesmo nível dos próprios [Jogos](games.md). Ninguém mais pode ligar ou desligar a marcação, escolher as duas contas dela nem mudar a cadência - o HST e o AST da própria crônica não veem esta seção.

## Como chegar lá

Barra lateral do wp-admin → Beyond Elysium → Configuração do Sistema → aba Jogos → **Editar** na crônica que você quer → a seção **Crônica de demonstração** abaixo do botão de salvar.

## A tela

- **Tornar esta crônica uma demonstração** - uma caixa de seleção, desligada por padrão. Marcá-la avisa que tudo na crônica é substituído na próxima redefinição, e em toda redefinição depois dela.
- **Cadência de redefinição** - com que frequência ela se redefine: a cada 1, 3, 6, 12 ou 24 horas. O padrão é 6.
- **Conta de Narrador** e **Conta de Jogador** - dois usuários existentes do WordPress, buscados por nome ou e-mail, a quem se concede o papel de HST e de jogador em toda redefinição. Essas duas contas são as únicas que uma redefinição deixa como membros da crônica; qualquer outra pessoa que entrou desde a última redefinição é removida.
- **Salvar configurações de demonstração** - grava a marcação, a cadência e as contas.
- **Redefinir agora**, depois que a marcação está ligada - executa a redefinição imediatamente em vez de esperar a agenda, depois de uma confirmação.
- **Última redefinição** - quando a redefinição mais recente rodou, depois que houve uma.

Quem visita a crônica em qualquer ponto - Minha Crônica, o Kit de Ferramentas do Narrador, a ficha de um personagem ou o editor dela - vê um aviso que nomeia quando ela se redefine em seguida, e o seletor de crônica a rotula "- Demo".

## Tarefas comuns

### Transformar uma crônica numa demonstração

1. **Edite** a crônica na tela Jogos.
2. Marque **Tornar esta crônica uma demonstração**.
3. Escolha uma cadência.
4. Busque e escolha a **Conta de Narrador** e a **Conta de Jogador**.
5. Clique em **Salvar configurações de demonstração**.
6. Ela se redefine pela primeira vez na próxima execução agendada, ou na hora por **Redefinir agora**.

### Redefinir uma crônica de demonstração agora mesmo

1. **Edite** a crônica.
2. Clique em **Redefinir agora**.
3. Confirme. Tudo nela é substituído a partir do conteúdo declarado.

### Desligar a marcação

1. **Edite** a crônica.
2. Desmarque **Tornar esta crônica uma demonstração**.
3. Clique em **Salvar configurações de demonstração**. A redefinição agendada dela para, e excluir e renomear voltam a funcionar.

## O que saber

- **Nada numa crônica de demonstração envia e-mail a ninguém.** Todo caminho de e-mail que o plugin tem - revisões de mudanças, resumos de lançamento, avisos de postagem de trama, avisos de envio, convites - é silenciado para uma crônica de demonstração especificamente, não só para as duas contas de demonstração.
- **Uma crônica de demonstração não pode ser excluída nem renomeada** enquanto a marcação está ligada - desligue-a primeiro. Ela também se recusa a enviar um personagem a outro site, a aceitar uma oferta de transferência recebida, e o rascunho por IA fica desligado nela. Todo botão de IA ainda aparece - Assistência de IA, Rascunhar notas de interpretação, Rascunhar a partir de uma premissa, Rascunhar resumo - e clicar em um explica que o rascunho está desligado na demonstração pública e não envia nada.
- **Os envios continuam permitidos.** Uma redefinição exclui todo anexo - linhas e arquivos - junto com todo o resto, então não há nada para limpar à mão.
- **Uma redefinição perdida roda na próxima visita**, do mesmo jeito que toda tarefa agendada do WordPress - não há exigência de cron no nível do servidor além do que o resto do plugin já precisa.
- **A crônica companheira.** Ligar a marcação também cria uma segunda crônica, em `{slug}-companion`, só com a conta de Narrador como membro - nunca a conta de jogador - então a conta de jogador tem uma segunda crônica em que pode pedir para entrar. Ela se redefine junto com a principal e carrega a mesma trava.

## Solução de problemas

- **"Esta crônica não está marcada como demonstração."** em Redefinir agora - a marcação foi desligada depois que a página carregou. Recarregue.
- **"A redefinição não pôde ser executada - verifique se as duas contas de demonstração ainda são usuários reais."** Uma das duas contas foi excluída. Escolha uma nova e salve.
- **"Uma crônica de demonstração não pode ser excluída enquanto estiver marcada como demonstração."** / **"...não pode ser renomeada..."** Desligue a marcação primeiro.
- **Não vejo esta seção.** Você precisa de uma conta de administrador do WordPress.

## Relacionados

- [Jogos](games.md)
- [Configuração da Crônica](chronicle-setup.md)
- [Guia do Administrador](../admin-guide.md#crônicas-de-demonstração)
