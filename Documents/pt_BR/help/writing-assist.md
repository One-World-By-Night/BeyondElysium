# Assistência de Escrita por IA

Um botão que pede a uma IA que redija ou aprimore o texto de um campo longo, sem sair do campo ao lado do qual ele fica.

## Quem pode usar

Os Narradores (HST e AST) o veem ao lado dos campos longos da própria crônica: o Antecedente e as Notas de um personagem, os campos de notas de interpretação de um NPC só para Narradores, a visão geral e o suspense final de uma trama, uma entrada de trama, a descrição de um rumor e a descrição, as limitações e outras propriedades de texto de um item ou local. Um administrador do site vê o mesmo botão também em algumas telas de todo o site - as notas de referência, descrição e fonte do catálogo compartilhado de traços e poderes, o motivo de uma regra de aprovação, a descrição própria de uma crônica na tela Jogos e o texto dos Créditos do site. Um Condutor de Trama, a Harpia da crônica e um jogador nunca o veem, mesmo ao lado de um campo que podem editar de outro modo - ele é sempre protegido pela capacidade de gerenciamento mais ampla daquele conteúdo, nunca pela simples permissão de editar o campo.

## Como chegar lá

Não há uma página própria - procure um botão **Assistência de IA** ao lado de qualquer campo longo que você já pode gerenciar.

## A tela

- **Assistência de IA** - o botão em si, ao lado do campo.
- Clicar nele abre um modal intitulado **Assistência de IA**:
  - Se o campo já tem texto: uma nota de que isso vai melhorar o texto existente, mantendo o significado e os detalhes, com **Cancelar** e **Aprimorar isto**.
  - Se o campo está vazio: uma caixa **Sobre o que deveria ser isto?** para uma linha curta de direção, com **Cancelar** e **Gerar**.
  - Enquanto trabalha, o botão diz **Gerando…**.
  - Quando uma sugestão volta: o texto da sugestão, um lembrete de que nada foi salvo ainda e **Voltar**, **Regenerar** e **Aceitar**.
- **Aceitar** coloca a sugestão no campo e fecha o modal - não salva nada por si só.
- **Voltar** retorna ao passo de instrução ou aprimoramento sem fechar o modal; **Regenerar** pede de novo a partir dali.
- Uma falha aparece como uma frase simples dentro do modal no lugar de uma sugestão.

## Tarefas comuns

### Preencher um campo vazio

1. Clique em **Assistência de IA** ao lado do campo.
2. Digite uma linha curta em **Sobre o que deveria ser isto?**
3. Clique em **Gerar**.
4. Leia a sugestão e clique em **Aceitar** para colocá-la - ou em **Regenerar** para tentar de novo, ou em **Voltar**/**Cancelar** para recuar.
5. Salve o campo do jeito normal - **Aceitar** o preenche, não o salva.

### Aprimorar algo que você já escreveu

1. Digite antes o seu próprio rascunho no campo.
2. Clique em **Assistência de IA**.
3. Clique em **Aprimorar isto**.
4. Revise o resultado e depois **Aceitar**, **Regenerar** ou **Voltar**.
5. Salve o campo como de costume.

## O que saber

- **Numa crônica de demonstração o botão explica em vez de redigir.** Ele ainda aparece, e clicar nele diz que o rascunho por IA está desligado na demonstração pública e que nada foi enviado. O mesmo vale para Rascunhar notas de interpretação, Rascunhar a partir de uma premissa e Rascunhar resumo.
- **Nada é salvo por este modal em si.** Aceitar só preenche o campo - você ainda precisa clicar no Salvar ou Enviar do próprio campo.
- **A sua crônica precisa ligar isto antes.** Mesmo com uma chave configurada para o site inteiro, um campo restrito à crônica (personagem, trama, rumor, item, local, motivo de aprovação) não faz nada até um HST ou AST ativar a Assistência de IA para essa crônica - veja o [Guia do Administrador](../admin-guide.md#assistência-de-escrita-por-ia).
- **Limitado a 20 sugestões por minuto, por pessoa.** Pedir mais rápido que isso recebe uma mensagem de esperar e tentar de novo em vez de um resultado.
- **Nunca inventa regras específicas do jogo.** É instruída a escrever prosa, não uma decisão - trate quaisquer números ou mecânicas que ela escreva como algo a conferir você mesmo.
- **Texto longo é aparado antes de ser enviado.** Um campo muito longo é cortado antes de sair, e a direção curta que você digita para um campo vazio tem o próprio limite, mais curto.
- **Qual provedor responde depende do que está configurado** - OpenAI, Claude ou um servidor auto-hospedado compatível. O endpoint personalizado de uma crônica precisa ser um endereço público; o de um administrador do site pode ser local. Esta tela não mostra qual está ativo.

## Solução de problemas

- **Não vejo um botão Assistência de IA de jeito nenhum.** Ou você não tem a capacidade de gerenciamento mais ampla deste campo, ou é um campo que esta ferramenta não cobre.
- **"A assistência de IA ainda não foi configurada - peça a quem gerencia esta crônica (ou o site) para adicionar uma chave de API."** Ninguém configurou uma chave ainda, ou a sua crônica não ligou o recurso - pergunte a um HST, AST ou ao administrador do seu site.
- **"Muitas sugestões no último minuto - aguarde um momento e tente novamente."** Espere cerca de um minuto e tente de novo.
- **"Não foi possível alcançar o provedor de IA. Tente novamente em instantes." / "O provedor de IA retornou um erro. Verifique se a chave de API configurada ainda é válida."** A chave ou conexão configurada está falhando - avise quem a gerencia.
- **"O provedor de IA retornou uma resposta vazia."** Clique em **Regenerar**, ou tente uma instrução mais específica.
- **Cliquei em Aceitar mas a minha mudança não ficou.** Você provavelmente saiu antes de salvar o campo em si - Aceitar só o preenche, salvar ainda é um passo à parte.

## Relacionados

- [Editor de Personagem](character-editor.md)
- [Tramas e Rumores](plot-manager.md)
- [Rumores](rumors.md)
- [Regras de Aprovação](approval-rules.md)
- [Configurações de Assistência de IA (Crônica)](writing-assist-chronicle.md)
- [Configurações de Assistência de IA (Site)](writing-assist-site.md)
- [Guia do Administrador](../admin-guide.md#assistência-de-escrita-por-ia)
