# Alinhamento — Módulo Banco de Horas no Pulse

> Documento para alinhamento prévio antes de iniciar a integração do BH Tracker
> como módulo no Hostweb Pulse. Conforme orientado no `guia-novos-modulos.md`,
> itens que fogem do padrão devem ser alinhados com o integrador antes de implementar.

---

## Contexto

O **BH Tracker** é uma aplicação PHP standalone de controle de Banco de Horas,
desenvolvida para a Hostweb. A proposta é portá-la como módulo do **Pulse**,
seguindo os padrões definidos no guia (`FastAPI + SQLAlchemy async + React 19 + TS`).

O módulo envolve:
- Lançamento e validação de horas extras por colaboradores e coordenadores
- Cálculo financeiro CLT por tipo de hora (diurno, noturno, FDS, feriado)
- Relatórios gerenciais com breakdown de custo por colaborador
- Configuração individual de jornada (horário de entrada/saída, intervalo de almoço)
- Configuração de salário bruto por colaborador (base para cálculo CLT)

**Premissa confirmada:** o sistema será implantado com banco vazio — não há migração
de dados. As migrations Alembic apenas criam as tabelas (`CREATE TABLE`), sem
nenhum backfill ou transformação de dados históricos.

---

## Pontos que precisam de alinhamento

### 1. Biblioteca de gráficos no frontend

O módulo tem relatórios com gráficos de barras e linha (distribuição de horas por tipo,
tendência mensal, custo CLT).

No Pulse React, qual lib de gráficos está aprovada ou em uso?

- `recharts` (mais idiomático para React)
- `react-chartjs-2` (wrapper do Chart.js, menor curva de aprendizado)
- Outra já presente no `package.json`

Não será adicionada lib nova sem autorização — só confirmar o que já existe ou o que está aprovado.

---

### 2. Mapeamento de "setor" — grupos do Pulse ou tabela própria?

O BH Tracker tem o conceito de **setor**: cada colaborador pertence a um setor,
e o coordenador só vê/valida colaboradores do seu próprio setor.

No Pulse, já existe o conceito de **grupo** (`grupo_id` no JWT).
Duas opções:

**Opção A — Reusar grupos do Pulse como setores BH**
- Sem tabela nova
- O `grupo_id` do JWT já identifica o setor do usuário
- Restrição: coordenador do grupo X só vê lançamentos do grupo X
- Risco: grupos do Pulse podem ter semântica diferente de "setor operacional"

**Opção B — Criar tabela `bh_setores` própria**
- Mais flexível e isolado
- Requer migration nova + campo `bh_setor_id` na `bh_config_usuario`
- Maior esforço, mas não acopla ao modelo de grupos existente

**Qual faz mais sentido para o modelo atual do Pulse?**

---

### 3. Dados específicos do BH por usuário — como estender o `Usuario` do Pulse?

O BH precisa armazenar por colaborador:

| Campo | Tipo | Descrição |
|---|---|---|
| `salario_bruto` | decimal | Base para cálculo CLT (÷ 220h) |
| `work_start` | time | Início da jornada (ex.: 08:00) |
| `work_end` | time | Fim da jornada (ex.: 18:00) |
| `lunch_start` | time | Início do intervalo de almoço |
| `lunch_minutes` | smallint | Duração do almoço (30/60/90/120 min) |

O guia proíbe alterar `models.py` de tabelas existentes sem alinhar.
A proposta é criar uma tabela separada (não toca no `Usuario` existente):

```sql
CREATE TABLE bh_config_usuario (
    usuario_id    INTEGER PRIMARY KEY REFERENCES usuarios(id) ON DELETE CASCADE,
    salario_bruto NUMERIC(10,2) NOT NULL DEFAULT 0,
    work_start    TIME NOT NULL DEFAULT '08:00',
    work_end      TIME NOT NULL DEFAULT '18:00',
    lunch_start   TIME NOT NULL DEFAULT '12:00',
    lunch_minutes SMALLINT NOT NULL DEFAULT 60
);
```

**Confirma essa abordagem? Ou prefere colunas nullable direto na tabela `usuarios`?**

---

### 4. RBAC — distinção entre analista e coordenador

No Pulse, o acesso ao módulo é liberado via checkbox em Admin → Grupos.
O BH precisa de dois níveis de acesso:

- `/banco-de-horas` → colaborador: lança horas, vê próprio histórico e saldo
- `/banco-de-horas/admin` → coordenador/gestor: valida lançamentos, vê relatórios e custos CLT de toda a equipe

Perguntas:
- A distinção de coordenador no Pulse é pelo `tipo` do usuário, por grupo ou por permissão granular?
- Serão dois itens separados no menu (um para analista, um para coordenador) ou uma única entrada com visões diferentes?

---

### 5. Head atual do Alembic

Para numerar corretamente as migrations do módulo BH e evitar conflito no merge:

```sh
cd backend && alembic heads
```

Compartilhe a saída para que as migrations sejam numeradas na sequência certa.

---

## O que já está pronto (será portado, não reescrito do zero)

| Funcionalidade | Status |
|---|---|
| Lógica de cálculo CLT por slot horário (diurno ×1.50, noturno ×1.80, dom/fer ×2.00/2.20) | ✅ Pronto |
| Validação de lançamentos (sobreposição de horários, conflito com intervalo de almoço) | ✅ Pronto |
| Controle de saldo de banco de horas (acumulado por colaborador) | ✅ Pronto |
| Fluxo de aprovação/recusa com nota de revisão | ✅ Pronto |
| Relatório geral com KPIs, gráficos e breakdown financeiro CLT | ✅ Pronto |
| Análise individual por colaborador com histórico e custo estimado | ✅ Pronto |
| Exportação CSV | ✅ Pronto |
| Gestão de escala (folgas, compensações) | ✅ Pronto |
| Importação em lote via planilha | ✅ Pronto |

---

## O que será entregue via PR

Com as 5 respostas acima, a entrega será na branch `feat/banco-de-horas`:

| Arquivo | Descrição |
|---|---|
| `backend/routers/banco_de_horas.py` | Toda a lógica: lançamentos, validação, relatórios, cálculo CLT |
| `backend/models.py` *(adição)* | Modelos `BhLancamento`, `BhConfigUsuario`, `BhSetor` (se necessário) |
| `backend/migrations/versions/00XX_bh_base.py` | Cria as tabelas `bh_*` |
| `backend/migrations/versions/00YY_bh_versao.py` | Registra a versão em `versoes_sistema` |
| `backend/main.py` *(2 linhas)* | Registra o router com prefixo `/v1/banco-de-horas` |
| `frontend/src/types/desk.ts` *(adição)* | Tipos `BhLancamento`, `BhConfigUsuario` etc. |
| `frontend/src/pages/BancoDeHoras.tsx` | Tela do analista (dashboard + lançamento) |
| `frontend/src/pages/BhValidacao.tsx` | Tela de validação (coordenador/admin) |
| `frontend/src/pages/BhRelatorios.tsx` | Relatórios com gráficos e breakdown CLT |
| `frontend/src/pages/BhRelatorioColaborador.tsx` | Análise individual por colaborador |
| `frontend/src/config/navegacao.ts` *(adição)* | Item(ns) no menu lateral |
| `frontend/src/App.tsx` *(adição)* | Rotas com `PrivateRoute` |

O PR incluirá: resumo do módulo, lista de arquivos, validações `py_compile` + `tsc --noEmit`,
confirmação do `alembic heads` e plano de teste manual — conforme checklist do guia (seção 9).

---

*Atualizado em 29/05/2026 · BH Tracker v1.0 → Pulse (módulo) · banco vazio, sem migração de dados*
