# PROMPT — Claude Code | Sistema BH Tecnologia

> Cole este prompt inteiro no chat do Claude Code dentro do VS Code ou terminal.
> Projeto: `bh-tracker-php` (renomear internamente para **BH Tecnologia**)

---

## AUTONOMIA TOTAL SOBRE O PROJETO

Você tem permissão completa para:
- Redefinir e reorganizar a árvore de arquivos como achar melhor
- Apagar arquivos desnecessários, redundantes ou mal organizados
- Recriar o sistema do zero se a estrutura atual não suportar os novos requisitos
- Refatorar qualquer parte do código sem restrições
- Migrar o banco de dados de MySQL/SQLite para **PostgreSQL**

**Antes de qualquer alteração**, leia toda a estrutura do projeto, mapeie o que existe, identifique o que deve ser mantido, removido ou recriado e apresente o plano antes de executar.

---

## CONTEXTO DO PROJETO

Este é um sistema PHP de controle de **Banco de Horas (BH)** chamado **BH Tecnologia**, usado por uma empresa de TI. O sistema permite que analistas/colaboradores registrem horas trabalhadas fora do horário comercial em regime de **escala voluntária** (não há sobreaviso obrigatório), e que coordenadores e administradores gerenciem e validem essas horas.

O sistema possui:
- Login e autenticação por perfis
- Lançamento de horas por colaboradores
- Aprovação/recusa por coordenadores
- Painel administrativo
- Gerenciamento de setores (CRUD exclusivo do Admin)
- Recuperação de senha por e-mail (link com validade de 1 hora)
- Controle de prazo de 48h para lançamento (alerta ao colaborador e coordenador)
- Importação em lote (bulk) de horas de sistemas legados, exclusivo ao coordenador, com campo de origem
- Seção de ex-colaboradores (arquivar ou excluir permanentemente), exclusivo ao Admin
- Formato de data `dd/mm/aaaa hh:mm` em todo o sistema
- Formato de moeda **BRL** (`R$ 1.000,00`) em todo o sistema
- Escala voluntária mensal com relatório de voluntários por colaborador

---

## STACK TECNOLÓGICA

- **Backend:** PHP (mantenha a versão já instalada no projeto)
- **Banco de dados:** **PostgreSQL 16** via Docker (PDO pgsql driver)
- **Frontend:** HTML, CSS, JS puros (sem frameworks JS pesados) — mantenha o padrão existente
- **Identidade visual:** seguir rigorosamente as especificações da seção abaixo

---

## IDENTIDADE VISUAL — REFERÊNCIA OBRIGATÓRIA

A interface deve ser **idêntica em estilo e estrutura** ao sistema **Pulse** da Hostweb Data Center, conforme a imagem de referência fornecida. Reproduza fielmente cada elemento descrito abaixo.

### Layout geral

- Fundo geral da página: **branco (#FFFFFF)** ou cinza muito claro (#F5F6FA)
- Sidebar fixa à esquerda com largura aproximada de **200px**
- Área de conteúdo principal ocupa o restante da largura
- Sem bordas pesadas; separação feita por sombra sutil (`box-shadow: 0 1px 4px rgba(0,0,0,0.08)`)

### Sidebar (menu lateral)

- Fundo: **cinza escuro quase preto** (`#1E2228` ou `#22262E`)
- Logotipo no topo: nome da empresa em branco, subtítulo em cinza claro abaixo (`font-size: 11px; color: #8A9BB0`)
- Itens de menu: ícone à esquerda + texto, cor padrão `#A0AEC0`, hover com fundo levemente mais claro e texto branco
- Item ativo: fundo `#2D3748` ou destaque com borda esquerda azul (`border-left: 3px solid #3B82F6`), texto branco
- Ícones de notificação: badge vermelho arredondado com número (`background: #E53E3E`)
- Rodapé da sidebar: nome do usuário logado + cargo em menor, com ícone de avatar; link "→ Sair" em cinza claro

### Topbar / Cabeçalho da área de conteúdo

- Fundo branco com sombra inferior leve
- Título da página em **negrito** (`font-size: 20px; font-weight: 700; color: #1A202C`)
- Filtros de período como **botões pill** (arredondados): padrão fundo branco + borda cinza, ativo com fundo **vermelho/laranja** (`#E53E3E` ou `#DD4B1A`) e texto branco
- Intervalo de datas: texto cinza médio entre os botões
- Checkbox "Incluir X" ao lado: estilo nativo com label cinza

### Cards de métricas (KPI cards)

Linha de cards horizontais abaixo do filtro de período, cada um com:

- Fundo branco, borda `1px solid #E2E8F0`, `border-radius: 8px`, `padding: 16px 20px`
- Título do card em **caixa alta**, `font-size: 11px`, `font-weight: 600`, `letter-spacing: 0.05em`
- Cada card tem sua cor de título e número:
  - **ABERTOS** → azul (`#3182CE`)
  - **FECHADOS** → verde (`#38A169`)
  - **AGUARDANDO ATENDIMENTO** → laranja (`#DD6B20`)
  - **EM ANÁLISE** → amarelo/âmbar (`#D69E2E`)
  - **AGUARDANDO CLIENTE** → verde-água (`#319795`)
  - **SLA OK** → azul (`#3182CE`)
  - **SLA RISCO** → amarelo (`#D69E2E`)
  - **SLA EXPIRADO** → vermelho (`#E53E3E`)
- Número principal: `font-size: 36px; font-weight: 700`
- Link "ver chamados" em azul claro com seta `▼`, `font-size: 12px`

> **Para o BH Tecnologia**, substituir os cards por métricas equivalentes:
> - **PENDENTES** (azul) | **APROVADOS** (verde) | **RECUSADOS** (vermelho) | **FORA DO PRAZO** (laranja) | **HORAS NO CICLO** (verde-água) | **VALOR ESTIMADO** (azul)

### Tabelas e listagens

- Cabeçalho da tabela: fundo `#F7FAFC`, texto `#718096`, `font-size: 12px`, `font-weight: 600`, `text-transform: uppercase`
- Linhas alternadas com fundo levemente diferente (`#FAFAFA`) ou separadas por linha `1px solid #EDF2F7`
- Hover na linha: fundo `#EBF8FF` (azul bem claro)
- Status em **badges pill**: `border-radius: 999px`, `padding: 2px 10px`, `font-size: 12px`
  - Pendente: fundo amarelo claro, texto amarelo escuro
  - Aprovado: fundo verde claro, texto verde
  - Recusado: fundo vermelho claro, texto vermelho
  - Fora do prazo: fundo laranja claro, texto laranja escuro

### Botões

- **Primário (Aprovar / Salvar):** fundo `#3182CE`, texto branco, `border-radius: 6px`, `padding: 8px 16px`
- **Perigo (Recusar / Excluir):** fundo `#E53E3E`, texto branco
- **Secundário / Neutro:** fundo branco, borda `#CBD5E0`, texto `#4A5568`
- Hover: versão ligeiramente mais escura da cor de fundo (`filter: brightness(0.92)`)
- Todos com `font-size: 14px; font-weight: 500; cursor: pointer`

### Gráficos e painéis

- Fundo branco, borda `1px solid #E2E8F0`, `border-radius: 8px`
- Título do painel: `font-size: 15px; font-weight: 600; color: #2D3748; padding: 16px`
- Subtítulo/legenda: `font-size: 12px; color: #718096`
- Cores dos gráficos: azul `#4299E1` e verde `#48BB78` (como no Volume Diário da referência)
- Barras horizontais (Chamados por Cliente): vermelho `#E53E3E` com rótulo à direita

### Tipografia

- Fonte principal: **Inter** (Google Fonts) — `font-family: 'Inter', sans-serif`
- Tamanhos: corpo `14px`, labels `12px`, títulos de seção `20px bold`, subtítulos `15px semi-bold`
- Cor de texto padrão: `#2D3748` (quase preto)
- Texto secundário/labels: `#718096` (cinza médio)

### Formulários (modais e páginas de cadastro)

- Inputs: borda `1px solid #CBD5E0`, `border-radius: 6px`, `padding: 8px 12px`, `font-size: 14px`
- Focus: borda `#3182CE` com `box-shadow: 0 0 0 3px rgba(49,130,206,0.15)`
- Labels acima do campo, `font-size: 13px; font-weight: 500; color: #4A5568`
- Checkboxes e radio buttons com estilo nativo mas com accent-color `#3182CE`
- Modais com overlay `rgba(0,0,0,0.4)`, fundo branco, `border-radius: 10px`, sombra `0 10px 40px rgba(0,0,0,0.15)`
- **Modais fecham APENAS pelo botão "X" ou botão "Cancelar"** — clicar fora da caixa não fecha o modal

### Alertas e notificações

- Alerta de prazo excedido (48h): banner ou badge com fundo `#FFF5F5`, borda esquerda `4px solid #E53E3E`, texto `#C53030`
- Alerta informativo: fundo `#EBF8FF`, borda `#3182CE`, texto `#2B6CB0`
- Toast de sucesso: fundo `#F0FFF4`, borda `#38A169`

---

## TAREFAS — O QUE DEVE SER FEITO

### 1. NOME E BRANDING

Substitua **todas** as ocorrências de "BH Tracker" por **"BH Tecnologia"** em:
- Título da aba do navegador
- Tela de login
- Menu lateral/superior
- Rodapé
- E-mails automáticos
- Qualquer outro lugar que apareça

---

### 2. BANCO DE DADOS — MIGRAR PARA POSTGRESQL

- Crie o schema completo em PostgreSQL conforme as tabelas descritas na Seção de Banco de Dados abaixo
- Migre todos os dados existentes
- Ajuste todas as queries do sistema para sintaxe PostgreSQL
- Configure a conexão via variável de ambiente no `.env`

---

### 3. PERFIS DE ACESSO — REGRAS GERAIS

O sistema possui **quatro perfis**, que podem ser **cumulativos** (um usuário pode ter mais de um perfil simultaneamente):

| Perfil | Descrição |
|---|---|
| **Analista** | Lança horas, faz solicitações de banco de horas, visualiza apenas os próprios dados |
| **Coordenador** | Visualiza, aprova e recusa apenas os colaboradores e lançamentos do seu setor |
| **Administrador** | Acesso total: cria usuários, gerencia setores, redefine senhas, vê todos os setores |

> Perfis são cumulativos. Exemplo: Valney pode ser Coordenador + Admin, acumulando as permissões de ambos.

---

### 4. CADASTRO DE USUÁRIOS

No cadastro de cada usuário, os seguintes campos devem existir:

- Nome completo
- E-mail (login)
- Senha (com hash seguro)
- Perfil(s): checkboxes múltiplos — `[ ] Analista  [ ] Coordenador  [ ] Administrador`
- Setor: seleção obrigatória (lista vem da tabela `setores`)
- **Salário bruto** (campo numérico, visível apenas para Admin e Coordenador do setor)
- **Adicional Atrativo**: checkbox `[ ] Sim / [ ] Não` + campo de valor em R$ (exibido apenas se marcado "Sim") — coluna `adicional_valor` no banco
- Status: Ativo / Inativo

> Colaboradores já existentes devem ser automaticamente vinculados ao setor **Serviços**.

---

### 5. LANÇAMENTO DE HORAS — VISÃO DO ANALISTA

O analista vê **apenas seus próprios lançamentos**. O formulário de lançamento deve conter:

- **Data do acionamento**: campo com seletor de calendário visual + opção de digitar no formato militar `dd/mm/aaaa hh:mm`
- **Hora início** e **Hora fim**
- **Motivo/descrição** (obrigatório)
- **Pergunta: "Este dia foi um feriado?"** com checkbox. Ao marcar, exibe campo de texto perguntando **"Qual feriado foi esse?"** (obrigatório se feriado marcado). Finais de semana são detectados automaticamente pelo algoritmo — não confundir "fins de semana" (errado) com **"finais de semana"** (correto)
- O sistema calcula e exibe automaticamente o total de horas do lançamento

**Regra de prazo:** Se a data do acionamento for anterior a 48 horas, exibir alerta:
> *"O prazo para lançamento de horas é de 48 horas após o acionamento. Este registro está sujeito a recusa."*

No painel do coordenador, lançamentos fora do prazo de 48h devem ter um **indicador visual de alerta** (ícone + cor diferente), com instrução para o coordenador orientar o colaborador.

---

### 6. CÁLCULO AUTOMÁTICO DE HORAS E ADICIONAL

Com base no salário cadastrado e nas horas lançadas, o sistema deve calcular os valores devidos conforme a CLT:

| Situação | Percentual |
|---|---|
| Hora extra — dia útil | 50% sobre o valor/hora |
| Hora extra — final de semana | 100% sobre o valor/hora |
| Hora extra — feriado | 100% sobre o valor/hora |
| Adicional noturno (22h–05h) | 20% sobre o valor/hora |
| Sobreaviso voluntário (se aplicável) | 1/3 do valor/hora por hora de sobreaviso |

**Fórmula base:**
```
Valor/hora = Salário mensal / 220
Hora extra dia útil = Valor/hora × 1,50
Hora extra final de semana / feriado = Valor/hora × 2,00
Adicional noturno = Valor/hora × 0,20 (acumulável com hora extra)
```

> O campo de **Adicional Atrativo** (`adicional_valor`, se marcado) deve somar o valor informado ao total calculado.

O resultado deve ser exibido:
- No detalhamento do lançamento (visível ao coordenador e admin)
- No relatório de fechamento do ciclo

**Formato de moeda:** sempre exibir em BRL com `R$ ` + `number_format($v, 2, ',', '.')`.  
Helper PHP disponível em `includes/functions.php`:
```php
function fmtBRL(float $v): string {
    return 'R$\u{00a0}' . number_format($v, 2, ',', '.');
}
```

---

### 7. APROVAÇÃO E RECUSA — VISÃO DO COORDENADOR

O coordenador vê apenas os colaboradores do **seu setor**. Para cada lançamento:

- **Botão Aprovar** → aprova diretamente
- **Botão Recusar** → abre modal obrigatório onde o coordenador informa o **motivo da recusa** (campo `nota_revisao`); ao confirmar, o motivo é registrado e enviado por **e-mail automático ao colaborador**
- Lançamentos fora do prazo de 48h ficam destacados com alerta visual

---

### 8. SOLICITAÇÃO DE BANCO DE HORAS — VISÃO DO ANALISTA

O analista pode solicitar compensação das horas acumuladas. O formulário deve ter:

- **Tipo:** Dia inteiro / Meio período / Personalizado
- **Data(s):** seletor de calendário visual, com suporte a intervalo de datas no modo Personalizado
- **Horário início / fim** (modo Personalizado)
- **Motivo:** campo obrigatório
- Exibir **resumo antes de confirmar**: data/período, tipo, horário e total de horas a deduzir

---

### 9. IMPORTAÇÃO EM LOTE (BULK) — EXCLUSIVO DO COORDENADOR

O coordenador pode importar horas de sistemas legados:

- Upload de arquivo (CSV ou Excel) ou formulário manual em lote
- Campo obrigatório: **"Origem dos dados"** (ex: "Sistema Legado X", "Planilha Janeiro 2025")
- Registros importados ficam marcados com a origem na listagem
- Ação disponível apenas para o perfil Coordenador (e Admin)

**Atenção — assinatura correta de funções:**
```php
// CORRETO — recebe 3 strings separadas:
$totalMinutos = totalMinutosLancamento($data, $horaInicio, $horaFim);
$foraDoPrazo  = foraDoPrazo($data, $horaFim);

// ERRADO — não passar objetos DateTime:
// $totalMinutos = totalMinutosLancamento($dtStart, $dtEnd);  // ← TypeError!
```

---

### 10. EX-COLABORADORES — EXCLUSIVO DO ADMIN

- Ao desligar um colaborador, o Admin pode escolher entre **Arquivar** (move para seção "Ex-Colaboradores") ou **Excluir permanentemente**
- A seção "Ex-Colaboradores" exibe histórico completo, mas sem novos lançamentos possíveis
- Exclusão permanente remove todos os dados relacionados (lançamentos, solicitações, histórico), com **confirmação de alerta** antes

---

### 11. EDIÇÃO DE DADOS CADASTRAIS

- Admin pode editar qualquer dado de qualquer colaborador
- Coordenador pode editar dados dos colaboradores do seu setor (exceto salário de outros coordenadores)
- Campos editáveis: nome, e-mail, cargo, setor, perfil(s), salário, adicional atrativo, status

---

### 12. FORMATO DE DATA E HORA

Em **todo o sistema** (formulários, listagens, relatórios, e-mails):
- Datas no formato: `dd/mm/aaaa`
- Data e hora: `dd/mm/aaaa hh:mm`
- Entradas pelo usuário: aceitar formato militar ou seletor visual de calendário (os dois coexistindo)

---

### 13. RECUPERAÇÃO DE SENHA

- Link "Esqueci minha senha" na tela de login
- Usuário informa e-mail cadastrado → recebe link de redefinição com validade de 1 hora
- Após redefinição, o link expira imediatamente
- Se o e-mail não estiver cadastrado, exibir mensagem neutra (não expor que o e-mail não existe)

---

### 14. ESCALA VOLUNTÁRIA MENSAL

O sistema tem uma área onde os colaboradores se cadastram **voluntariamente** para disponibilidade de plantão em cada mês.

#### Visão do Analista

- No menu principal, item **"Minha Escala"**
- Exibe um **calendário mensal** (mês atual + próximo mês disponível para pré-inscrição)
- O analista clica nos dias em que estará disponível — o dia selecionado fica destacado visualmente
- Campos opcionais por dia marcado:
  - **Turno preferencial**: Manhã / Tarde / Noite / Disponível o dia todo
  - **Observação livre** (ex: "só a partir das 14h")
- Mensagem visível na tela: *"Sua disponibilidade é voluntária e não gera obrigação de atendimento."*

**Atenção — campo correto na API:**
```javascript
// CORRETO — o campo se chama 'data', não 'date':
body: JSON.stringify({action: 'save', data, turno, observacao})
body: JSON.stringify({action: 'remove', data})
// ERRADO:
// body: JSON.stringify({action: 'save', date, turno, observacao})  // ← "Data inválida"!
```

#### Visão do Coordenador / Admin

- **Escala do Setor** (coordenador) ou **Escala Geral** (admin)
- Calendário mensal com colaboradores disponíveis em cada dia
- Dias sem nenhum voluntário ficam destacados em vermelho
- Navegação entre meses (anterior/próximo)
- **Duas exportações CSV:**
  - `?export=csv` — Detalhamento diário (data, colaborador, setor, turno, observação)
  - `?export=relatorio` — Resumo por colaborador (nome, setor, total de dias, distribuição por turno)
- Tabela inline de **"Resumo por colaborador"** com: nome, setor, dias marcados, breakdown de turnos (Manhã/Tarde/Noite/Dia todo)

---

### 15. GERENCIAMENTO DE SETORES — EXCLUSIVO DO ADMIN

- Página `admin-setores.php` acessível pelo menu lateral (item "Setores")
- Listagem de todos os setores com quantidade de colaboradores ativos
- **Criar** novo setor: modal com campo nome (máx. 100 chars), valida duplicatas
- **Renomear** setor: mesmo modal, pré-preenchido
- **Excluir** setor: botão disponível **apenas se não houver colaboradores ativos**; confirmação obrigatória
- API em `api/setores.php` com actions: `create`, `update`, `delete`

---

## BANCO DE DADOS — SCHEMA POSTGRESQL (ATUAL)

```sql
-- Setores (criar antes de usuarios)
CREATE TABLE setores (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL
);

-- Usuários
CREATE TABLE usuarios (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    setor_id INT REFERENCES setores(id),
    salario_bruto NUMERIC(10,2),
    adicional_atrativo BOOLEAN DEFAULT FALSE,
    adicional_valor NUMERIC(10,2),          -- NÃO usar valor_adicional_atrativo
    must_change_pass BOOLEAN DEFAULT FALSE,
    status VARCHAR(20) DEFAULT 'ativo',     -- ativo | inativo | ex-colaborador
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW()
);

-- Perfis disponíveis
CREATE TABLE perfis (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(50) UNIQUE NOT NULL        -- analista | coordenador | administrador
);

-- Relação usuário <-> perfis (cumulativo)
CREATE TABLE usuario_perfis (
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    perfil_id INT REFERENCES perfis(id),
    PRIMARY KEY (usuario_id, perfil_id)
);

-- Lançamentos de horas
CREATE TABLE lancamentos (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    data_acionamento DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fim TIME NOT NULL,
    total_minutos INT,
    feriado BOOLEAN DEFAULT FALSE,
    descricao_feriado VARCHAR(100),         -- nome do feriado (obrigatório se feriado=true)
    motivo TEXT NOT NULL,
    ticket VARCHAR(100),
    fora_do_prazo BOOLEAN DEFAULT FALSE,
    origem VARCHAR(255),                    -- para bulk/legado
    status VARCHAR(20) DEFAULT 'pendente',  -- pendente | aprovado | recusado
    nota_revisao TEXT,                      -- NÃO usar motivo_recusa
    valor_calculado NUMERIC(10,2),
    criado_em TIMESTAMP DEFAULT NOW(),
    revisado_por INT REFERENCES usuarios(id), -- NÃO usar aprovado_por
    revisado_em TIMESTAMP                     -- NÃO usar aprovado_em
);

-- Solicitações de banco de horas
CREATE TABLE solicitacoes_bh (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo VARCHAR(20) NOT NULL,              -- dia_inteiro | meio_periodo | personalizado
    data_inicio DATE NOT NULL,
    data_fim DATE,
    hora_inicio TIME,
    hora_fim TIME,
    motivo TEXT NOT NULL,
    total_minutos INT,
    status VARCHAR(20) DEFAULT 'pendente',
    revisado_por INT REFERENCES usuarios(id),
    revisado_em TIMESTAMP,
    nota_revisao TEXT,
    criado_em TIMESTAMP DEFAULT NOW()
);

-- Tokens de recuperação de senha
CREATE TABLE tokens_senha (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    token VARCHAR(255) UNIQUE NOT NULL,
    expira_em TIMESTAMP NOT NULL,
    usado BOOLEAN DEFAULT FALSE
);

-- Escala voluntária
CREATE TABLE escala_voluntaria (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE,
    data_disponivel DATE NOT NULL,
    turno VARCHAR(20) DEFAULT 'dia_todo',   -- manha | tarde | noite | dia_todo
    observacao TEXT,
    criado_em TIMESTAMP DEFAULT NOW(),
    atualizado_em TIMESTAMP DEFAULT NOW(),
    UNIQUE (usuario_id, data_disponivel)
);
```

**Dados iniciais obrigatórios:**
```sql
INSERT INTO perfis (nome) VALUES ('analista'), ('coordenador'), ('administrador');
```

> Setores são gerenciados pelo Admin pelo sistema — não usar INSERT fixo no schema.

---

## NOMES DE COLUNAS — REFERÊNCIA CRÍTICA

| ❌ Nome antigo (errado) | ✅ Nome atual (correto) | Tabela |
|---|---|---|
| `valor_adicional_atrativo` | `adicional_valor` | `usuarios` |
| `aprovado_por` | `revisado_por` | `lancamentos`, `solicitacoes_bh` |
| `aprovado_em` | `revisado_em` | `lancamentos`, `solicitacoes_bh` |
| `motivo_recusa` | `nota_revisao` | `lancamentos`, `solicitacoes_bh` |
| `total_horas` | `total_minutos` | `lancamentos`, `solicitacoes_bh` |
| `data_acionamento TIMESTAMP` | `data_acionamento DATE` | `lancamentos` |

---

## COMPORTAMENTOS OBRIGATÓRIOS DO FRONTEND

### Modais
- **Nunca fechar ao clicar fora** — remover qualquer `modal.addEventListener('click', e => { if(e.target===this) close() })`
- Fechar apenas pelo botão "X" ou botão "Cancelar"

### Botões de submit (fetch)
- Sempre envolver em `try { ... } catch (err) { ... }` para evitar que o botão fique preso em "Salvando…" quando o servidor retorna erro não-JSON (ex: PHP 500 com HTML)

```javascript
try {
  const res  = await fetch('/api/...', { method: 'POST', ... });
  const data = await res.json();
  if (!res.ok) { errEl.textContent = data.error ?? 'Erro ao salvar.'; ... return; }
  location.reload();
} catch (err) {
  errEl.textContent = 'Erro de comunicação com o servidor. Tente novamente.';
  btn.disabled = false; btn.textContent = 'Salvar';
}
```

### Formato monetário (JS)
```javascript
const fmt = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
fmt.format(valor); // → "R$ 1.000,00"
```

---

## ESTRUTURA DE ARQUIVOS

```
app/
├── includes/
│   ├── auth.php          — funções de autenticação e controle de perfis
│   ├── db.php            — conexão PostgreSQL via PDO
│   ├── functions.php     — helpers: fmtDate(), fmtBRL(), totalMinutosLancamento(), foraDoPrazo()
│   └── nav.php           — sidebar com links por perfil (Admin vê Setores)
├── api/
│   ├── records.php       — CRUD de lançamentos
│   ├── bh.php            — CRUD de solicitações BH
│   ├── escala.php        — save/remove disponibilidade (campo: 'data', não 'date')
│   ├── setores.php       — CRUD de setores (admin only)
│   └── users.php         — CRUD de usuários (admin/coordenador)
├── assets/
│   └── app.css           — variáveis CSS e classes utilitárias hw-*
├── sql/
│   └── init_postgres.sql — schema completo
├── dashboard.php         — visão do analista
├── escala.php            — calendário de disponibilidade do analista
├── bh-request.php        — solicitação de banco de horas
├── admin.php             — validação de lançamentos (coordenador/admin)
├── admin-bh.php          — gestão de solicitações BH
├── admin-escala.php      — escala do setor/geral + relatório de voluntários
├── admin-import.php      — importação em lote
├── admin-reports.php     — relatórios gerenciais
├── admin-users.php       — gestão de usuários
├── admin-setores.php     — gestão de setores (admin only)
├── login.php
├── logout.php
├── forgot-password.php
├── reset-password.php
└── change-password.php
```

---

## OBSERVAÇÕES FINAIS

1. **Arquivo `.env`** deve conter as variáveis de conexão PostgreSQL. Nunca subir `.env` no git.
2. **Segurança:** senhas sempre com `password_hash()` / `password_verify()`. Queries com prepared statements (sem SQL injection).
3. **E-mails:** use PHPMailer ou função nativa `mail()` já configurada no projeto — não altere a configuração SMTP existente.
4. **Relatórios:** ao exportar (CSV), manter o formato `dd/mm/aaaa hh:mm` e incluir coluna de valor calculado.
5. **PostgreSQL — `lastInsertId`:** usar `$db->lastInsertId('tabela_id_seq')` (ex: `'setores_id_seq'`).
6. **Roles via ARRAY_AGG:** PostgreSQL retorna `{analista,coordenador}` como string — parsear com `trim($raw, '{}')` + `explode(',', $clean)`.
7. **Escala — ON CONFLICT:** usar `ON CONFLICT (usuario_id, data_disponivel) DO UPDATE SET turno=..., observacao=..., atualizado_em=NOW()`.
8. **Ao final**, apresente um resumo do que foi criado, alterado, removido e migrado.

---

## PREPARAÇÃO PARA INTEGRAÇÃO COM ERP (módulo futuro)

> Veja o arquivo `ERP_INTEGRACAO.md` na raiz do projeto para instruções completas de como este sistema pode ser acoplado como módulo ao ERP existente.

---

*Prompt atualizado em: 27/05/2026 | Sistema: BH Tecnologia | Versão: 3.0*
