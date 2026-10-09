# Alocar Ações

Transforma os antecedentes de um personagem num orçamento de ações para a noite de jogo - uma cota Pessoal mais uma subação por Influência ou Antecedente que se qualifica - e o salva como uma trama.

## Quem pode usar

Os Narradores (HST e AST). Um Condutor de Trama também chega a esta ferramenta pelo mesmo acesso a Tramas e Rumores, mas o seletor de personagens dela só lista personagens que pertencem a quem a usa - para um Condutor de Trama, isso quer dizer o seu próprio personagem, se tiver um nesta crônica, não o elenco. Um jogador nunca abre esta ferramenta diretamente; depois que você confirma uma alocação, esse jogador vê o próprio orçamento no painel [Usos de Antecedente](background-uses.md) da ficha do personagem e posta na trama em [Minhas Tramas e Rumores](my-plots.md).

## Como chegar lá

Kit de Ferramentas do Narrador → Tramas e Rumores → botão **Alocar ações** (na grade de tramas), ou **+ Ação** de dentro de uma trama aberta, que já preenche essa trama como a mãe.

## A tela

- **Selecionar um personagem…** - todo personagem da crônica.
- Um campo de **data do jogo**.
- Uma lista suspensa de trama-mãe, que oferece só tramas de nível superior, ou "Sem trama-mãe".
- **Pré-visualizar** - calcula a alocação sem salvar nada.
- Depois de pré-visualizada, uma tabela (empilhada em cartões numa tela estreita):

  | Coluna | Mostra |
  | --- | --- |
  | Subação | `Personal`, ou a Influência/Antecedente de que ela é feita |
  | Nível | A classificação do personagem nessa Influência ou Antecedente (`0` para Personal) |
  | Total | O orçamento de pontos de ação desta subação para a data |
  | Não usado | O que sobra depois de quaisquer Usos de Antecedente já registrados para esta data |
  | Crescimento | Qualquer bônus carregado de uma alocação anterior |

  Uma linha é sinalizada (acima do orçamento) quando os usos registrados excedem o total dela.
- **Confirmar** - salva a alocação como uma trama. Depois de confirmada, uma nota "Confirmado como trama #N" aparece, e o registro de [Usos de Antecedente](background-uses.md) do personagem para essa mesma data abre logo abaixo da tabela, pronto para registrar o que aconteceu.

## Tarefas comuns

### Alocar as ações de um personagem para uma data de jogo

1. Abra Kit de Ferramentas do Narrador → Tramas e Rumores → **Alocar ações**.
2. Escolha o personagem e a **data do jogo**.
3. Se quiser, escolha uma trama-mãe.
4. Clique em **Pré-visualizar**.
5. Confira a tabela de subações.
6. Clique em **Confirmar**.

### Refazer uma alocação depois que os antecedentes de um personagem mudam

1. Abra **Alocar ações** para o mesmo personagem e a mesma data de jogo.
2. Clique em **Pré-visualizar** e depois em **Confirmar** de novo.

### Aninhar uma alocação sob uma trama existente

1. Abra primeiro a trama.
2. Clique em **+ Ação** na barra de ações dela - isso abre Alocar ações com essa trama já escolhida como a mãe.

## O que saber

- **Todo personagem sempre recebe uma subação Pessoal**, dimensionada pela configuração de ações Pessoais da sua crônica, seja o que for que tenha.
- **Uma Influência ou um Antecedente configurado soma mais uma subação**, de tamanho igual ao dobro da classificação do personagem nele, a menos que as Configurações de Ação e Rumor da sua crônica substituam o total para essa classificação exata. Veja [Configurações de Ação e Rumor](apr-settings.md).
- **Ações não usadas e crescimento só passam da alocação anterior mais recente de um personagem se as configurações da sua crônica permitirem** - senão toda data começa do zero, embora o crescimento já ganho continue passando de qualquer modo.
- **Confirmar de novo para o mesmo personagem e data atualiza essa mesma trama** - nunca cria uma segunda, e nunca apaga as próprias postagens de um jogador nem nada já registrado em Usos de Antecedente para essa data.
- **As ações de uma data ficam sob a trama própria do personagem**, a menos que você escolha outra mãe. Todo personagem tem uma, chamada `<Personagem> [id] Plot`.
- **Uma trama-mãe só vale na primeira vez que você confirma** para um personagem e data; confirmar de novo depois nunca move a trama, mesmo que você escolha outra mãe.
- **Esta ferramenta calcula um orçamento - não toca o XP nem a ficha do personagem.**
- **Um jogador só pode postar ações enquanto a janela de tempo livre dessa data está aberta.** Veja [Tempo Livre](downtime-queue.md) - isso só vale quando a sessão da própria data de jogo tem um horário ou prazo de abertura definido.
- Numa tela estreita a tabela de subações vira cartões empilhados em vez de rolar para o lado.

## Solução de problemas

- **Pré-visualizar não clica.** Escolha primeiro um personagem e uma data de jogo.
- **"Falha ao calcular esta alocação." / "Falha ao confirmar esta alocação."** Tente de novo. Uma confirmação que falha não salva nada - nenhuma trama, nenhuma ação - então tentar de novo é seguro.
- **Uma subação mostra total 0.** Confira se o personagem de fato tem essa Influência ou Antecedente, e se as Configurações de Ação e Rumor da sua crônica a listam em Antecedentes configurados - uma Influência sempre se qualifica por si só.
- **A lista de personagens está vazia ou falta alguém.** Se você é Condutor de Trama, esta lista só mostra o seu próprio personagem, não o elenco - peça a um HST ou AST que faça esta alocação.
- **Não vejo este botão.** Você precisa de um papel de Narrador ou de Condutor de Trama na crônica escolhida no momento.

## Relacionados

- [Tramas e Rumores](plot-manager.md)
- [Usos de Antecedente](background-uses.md)
- [Tempo Livre](downtime-queue.md)
- [Configurações de Ação e Rumor](apr-settings.md)
- [Minhas Tramas e Rumores](my-plots.md)
- [Papéis](roles.md)
- [Guia do Narrador](../st-guide.md#9-configurações-de-ação-e-rumor-e-o-registro-de-usos-de-antecedente)
