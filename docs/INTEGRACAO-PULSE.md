# Proposta de Integração — BH Pulse → Hostweb Pulse

**Documento técnico para confronto com a arquitetura do Hostweb Pulse**
Versão 1.1 · Data: 02/06/2026 · Autor: equipe BH Pulse
Base: código-fonte real em `github.com/valneyjunior/hw-bh` · Referência: `guia-novos-modulos.md` (repo `hostweb-dm`)

---

## 1. Objetivo

Integrar o **BH Pulse** — sistema completo de Banco de Horas (FastAPI + React) — ao **Hostweb
Pulse** como um **módulo nativo**, aderente ao guia de módulos da plataforma.

**Premissas acordadas:**
1. O módulo **"Banco de Horas (Legado)"** existente no Pulse (`legado.py` / `Legado.tsx`) permanece
   **apenas para consulta histórica**. Não recebe novas funcionalidades.
2. O **BH Pulse é o sistema oficial e completo** de Banco de Horas dali em diante.
3. A autenticação é **responsabilidade da plataforma** (AD/Entra ID) — o módulo não autentica.

---

## 2. Resumo executivo

| Dimensão | Situação |
|---|---|
| **Compatibilidade de stack** | ✅ Idêntica (FastAPI/SQLAlchemy async/Pydantic v2/Alembic/React 19/Vite/Tailwind v4/PostgreSQL 16) |
| **Reaproveitamento de UI/lógica** | ~90% das telas e regras CLT migram sem reescrita |
| **Maior esforço** | Desacoplar autenticação/usuários próprios e remapear os papéis do BH para o RBAC por grupo do Pulse |
| **Bloqueadores** | Nenhum técnico; há **3 decisões de arquitetura** a fechar (seção 8) |

O risco da integração **não é tecnológico** (a base é a mesma), é de **modelo de identidade e
permissões**. Resolver isso primeiro evita retrabalho.

---

## 3. Inventário do BH Pulse (o que será integrado)

### 3.0. Stack confrontada (versões reais do repositório)

| Camada | BH Pulse (`hw-bh`) | Guia do Hostweb Pulse | Compatível |
|---|---|---|---|
| Backend | FastAPI 0.115.0 · SQLAlchemy 2.0.36 (async) · Pydantic v2.9 · Alembic 1.13 | FastAPI 0.115 · SQLAlchemy 2.0 async · Pydantic v2 · Alembic | ✅ |
| Auth libs | python-jose 3.3 · bcrypt 4.0 · slowapi 0.1.9 | JWT (plataforma) | ⚠️ removidas (auth é da plataforma) |
| E-mail | httpx 0.27 (Microsoft Graph) | Graph já existe no Pulse | ✅ reusar a do Pulse |
| Frontend | React 19 · TypeScript 5.6 · Vite 6 · Tailwind 4 · **react-router-dom 7** · recharts 2.13 · axios 1.7 | React 19 · TS · Vite · Tailwind v4 · react-router-dom v7 | ✅ |
| Banco | PostgreSQL 16 (Docker) | PostgreSQL 16 | ✅ |

> **Conclusão:** versões praticamente idênticas. Só `recharts` (gráficos dos relatórios) é uma lib
> que pode não existir no Pulse — ver seção 11 (precisa de aprovação, guia §9.3).

### 3.1. Telas / módulos
| Tela | Perfil | Função |
|---|---|---|
| Meus Registros | Analista | Lançamento de horas extras (acionamentos) + saldo |
| Banco de Horas (folgas) | Analista | Solicitação de uso do saldo (dia/meio/personalizado) |
| Escala | Analista | Disponibilidade voluntária (turnos múltiplos) |
| Acionamento | Atendimento Corp. | Calendário de disponíveis + ligar/WhatsApp |
| Validação | Coordenador/Admin | Aprovar/contestar lançamentos e folgas |
| Banco de Horas da Equipe | Coordenador/Admin | Consulta consolidada (somente leitura) |
| Escala do Setor | Coordenador/Admin | Disponibilidade por setor + exportação |
| Relatórios + Análise individual | Coordenador/Admin | KPIs, custo CLT, export PDF/Excel |
| Usuários | Admin | Cadastro, perfis, config CLT, telefone, setores coordenados |
| Setores | Admin | CRUD de setores |
| Auditoria | Admin | Trilha tamper-evident (não-repúdio) |
| Backup | Admin | Exportar/restaurar dados |

### 3.2. Backend (arquivos reais)
- **Prefixo:** `/v1/banco-de-horas/...` — **já aderente** à convenção `/v1/<slug>` do Pulse ✅
- **Router único:** `backend/routers/banco_de_horas.py` (**~1.860 linhas**, monolítico). Aderente ao
  "um router por módulo" do guia, porém grande — avaliar quebra em sub-arquivos no porte.
- **Módulos de apoio:** `auth.py`, `audit.py`, `email_service.py`, `config.py`, `database.py`, `models.py`, `seed.py`, `main.py`
- **Tabelas (7):** `bh_setores`, `bh_usuarios`, `bh_config_usuario`, `bh_lancamentos`, `bh_folgas`, `bh_escala`, `bh_audit_logs`
- **Migrations (6):** `0001_bh_base` · `0002_bh_escala` · `0003_adicional_atrativo` · `0004_telefone_setores_coordenados` · `0005_escala_unique` · `0006_audit_logs` (isoladas — renumerar na sequência do Pulse)
- **Auth inline** em `main.py` (`/v1/auth/login`, `/v1/auth/alterar-senha`) → **será removida** (plataforma)

### 3.1-b. Frontend (arquivos reais)
- **16 páginas** em `frontend/src/pages/`: `BancoDeHoras`, `BhLancamento`, `BhFolgas`, `BhEscala`,
  `BhAcionamento`, `BhValidacao`, `BhBancoEquipe`, `BhEscalaSetor`, `BhRelatorios`,
  `BhRelatorioColaborador`, `BhUsuarios`, `BhSetores`, `BhAuditoria`, `BhBackup`, `Login`, `AlterarSenha`
- **Componentes** em `frontend/src/components/`: `HoraInput`, `Paginacao` *(migram — utilitários do BH)* · `Layout`, `Sidebar`, `PrivateRoute` *(descartados — chrome da plataforma)*
- **Client HTTP:** `services/api.ts` com `baseURL = VITE_API_URL` próprio e **funções nomeadas**
  (`getMeusLancamentos`, `aprovarLancamento`…) → adaptar ao padrão do Pulse (`api.get('/banco-de-horas')`, prefixo `/api/v1` automático)

### 3.3. Regras de negócio (núcleo de valor — migram integralmente)
- Cálculo CLT slot-a-slot: base `salário ÷ 220`, diurno/noturno (22h–05h, +20%), domingo/feriado, **cruzamento de meia-noite** tratado.
- Detecção automática de **feriados nacionais** (incl. móveis).
- **Segregação de função:** coordenador valida apenas os setores que coordena; **lançamento de coordenador exige aprovação do diretor** (admin).
- Fluxo de **contestação** (correção e reenvio).

### 3.4. Segurança já implementada
JWT, bcrypt(12), rate limiting no login, security headers, política de senha, **audit log com hash
encadeado (não-repúdio)** e verificação de integridade. *(Ver `SECURITY_ANALYSIS.md`.)*

---

## 4. Aderência ao guia de módulos (de → para)

| Item do guia | BH Pulse hoje | Ação para virar módulo Pulse | Esforço |
|---|---|---|---|
| Stack/linguagem | Aderente | — | — |
| Prefixo backend `/v1/<slug>` | `/v1/banco-de-horas` | Manter | 🟢 |
| Router em `routers/` | `routers/banco_de_horas.py` | Renomear conforme padrão | 🟢 |
| Tipos em `types/desk.ts` | `types/bh.ts` | Mover/mesclar | 🟢 |
| HTTP via `services/api.ts` (`/api/v1`) | `api.ts` próprio | Trocar pelo client do Pulse | 🟢 |
| Sidebar `navegacao.ts` | Próprio | Adaptar ao `NAV_ITEMS` do Pulse | 🟢 |
| Rotas via `PrivateRoute rota=` | `PrivateRoute apenas*` | Adaptar à assinatura do Pulse | 🟠 |
| **RBAC `require_permissao("/slug")`** | Perfis cumulativos próprios | **Remapear (ver seção 6)** | 🔴 |
| **Auth central** | Login/JWT/bcrypt próprios | **Remover; usar a do Pulse** | 🔴 |
| **Usuário central** | `bh_usuarios` próprio | **Extensão do `Usuario` do Pulse (ver seção 7)** | 🔴 |
| Migrations sequenciais + `versoes_sistema` | `0001`–`0006` isoladas | Renumerar + registrar versão | 🟠 |
| Backup global | Módulo próprio | **Remover** (é da plataforma) | 🟢 |
| Reset de senha | Próprio | **Remover** (auth da plataforma) | 🟢 |
| Audit log | Próprio (hash-chain) | Manter como tabela do módulo | 🟢 |

---

## 5. O que será descartado (absorvido pela plataforma)

| Artefato no `hw-bh` | Por quê |
|---|---|
| `pages/Login.tsx`, `pages/AlterarSenha.tsx` | Autenticação central do Pulse (AD/Entra ID) |
| `main.py` → `/v1/auth/*`, `auth.py` (JWT/bcrypt), `slowapi` | Auth/rate-limit herdados da plataforma |
| Reset de senha (`/admin/usuarios/{id}/resetar-senha`) | Gestão de identidade da plataforma |
| `pages/BhBackup.tsx` + `/admin/backup`, `/admin/restore` | Backup é responsabilidade de infraestrutura, não de módulo |
| `components/Layout.tsx`, `Sidebar.tsx`, `PrivateRoute.tsx`, `config/navegacao.ts` | **Chrome da plataforma** — o Pulse fornece o seu |
| `services/api.ts` (baseURL próprio) | Substituído pelo `services/api.ts` do Pulse (`/api/v1`) |

> Tudo isso já está **isolado** no nosso código, então a remoção é limpa. **Migram** as 12 telas de
> negócio + `HoraInput`/`Paginacao` + toda a lógica CLT.

---

## 6. Auth e RBAC — proposta de mapeamento

O Pulse controla **acesso a módulo** por **grupo + permissão** (`require_permissao("/banco-de-horas")`).
Já os **papéis internos do BH** (analista, coordenador, atendimento) não são "acesso a tela", são
**função dentro do processo** — algo que o Pulse não modela nativamente.

**Proposta:**
1. **Acesso ao módulo** → permissão `/banco-de-horas` no RBAC do Pulse (libera o item no menu).
2. **Papel interno do BH** → atributo de domínio do módulo, numa tabela de extensão
   (`bh_perfil_usuario`, 1:1 com `usuarios.id`), administrável dentro do próprio módulo BH.
   Mantém a lógica de "coordenador valida setor X" e "diretor aprova coordenador" sem poluir o RBAC global.

| Conceito BH | Onde vive no Pulse |
|---|---|
| Pode abrir o módulo BH | Permissão `/banco-de-horas` (grupo) |
| É analista / coordenador / atendimento | `bh_perfil_usuario.perfis` (extensão do módulo) |
| Setores que coordena | `bh_perfil_usuario.setores_coordenados` |
| É diretor (aprova coordenador) | `tipo = admin` do Pulse **ou** flag de domínio |

> **Ponto a confirmar:** o "admin" do BH equivale ao `admin` global do Pulse, ou é um papel de
> negócio (diretor de RH/operações) distinto do superadmin de TI? Isso muda o gating.

---

## 7. Modelo de dados — usuários e dados sensíveis

Hoje o BH tem `bh_usuarios` próprio. Na integração, o **colaborador é o `Usuario` central do Pulse**
(provido pelo AD). Os dados específicos do BH viram **extensão 1:1**:

```
usuarios (Pulse / AD)
   └── bh_perfil_usuario (1:1)   → perfis BH, telefone, setores_coordenados
   └── bh_config_usuario (1:1)   → salário, jornada, almoço, adicional atrativo
```

- As tabelas operacionais (`bh_lancamentos`, `bh_folgas`, `bh_escala`) referenciam `usuarios.id` do Pulse.
- **LGPD:** salário e jornada são dados sensíveis. Confirmar **quem pode ler** (provável: admin/diretor e o próprio colaborador) e manter a trilha de auditoria sobre esses acessos.

---

## 8. Decisões pendentes (para o diretor)

| # | Decisão | Impacto |
|---|---|---|
| D1 | "Admin do BH" = `admin` global do Pulse, ou papel de negócio separado? | Define o gating de aprovação do diretor |
| D2 | AD é **Entra ID (OIDC)** ou **AD on-premises (LDAP)**? | Define a estratégia de SSO/identidade |
| D3 | Papéis internos do BH em tabela de extensão (proposta) ou mapeados a grupos do Pulse? | Define onde o admin gerencia analista/coordenador |
| D4 | Migração de dados: os dados atuais do nosso BH (setores, lançamentos, config) entram no Pulse? | Define necessidade de script de carga |
| D5 | Slug do módulo: `banco-de-horas` (e o legado vira `bh-legado`/"Consulta Legada")? | Evita colisão de rota/permissão |

---

## 9. Plano de migração (faseado)

**Fase 0 — Alinhamento (sem código):** fechar D1–D5 e validar o modelo de identidade (seção 7).

**Fase 1 — Esqueleto do módulo:** criar `routers/banco_de_horas.py` no Pulse, registrar em `main.py`,
item no `NAV_ITEMS`, rota com `PrivateRoute rota="/banco-de-horas"`, página-índice. Gating por
`require_permissao`.

**Fase 2 — Identidade:** substituir `bh_usuarios` por `bh_perfil_usuario` + `bh_config_usuario`
referenciando `usuarios`. Remover auth/login/reset próprios.

**Fase 3 — Domínio:** portar lançamentos, validação, folgas, escala, acionamento, relatórios
(regras CLT intactas). Migrations renumeradas na sequência do Pulse.

**Fase 4 — Auditoria + versão:** integrar `bh_audit_logs`; registrar a versão em `versoes_sistema`.

**Fase 5 — Carga de dados (se D4 = sim):** script de importação dos dados atuais.

**Fase 6 — Hand-off:** PR conforme seção 9 do guia (validações `tsc`/`py_compile`/`alembic heads`,
plano de teste, changelog).

---

## 10. Checklist de hand-off (do guia, seção 9.4)

- [ ] Slug e permissão consistentes (backend `prefix`, `navegacao.ts`, `App.tsx`, `require_permissao`)
- [ ] `response_model` em todas as rotas
- [ ] Tipos em `types/desk.ts`, sem `any`
- [ ] HTTP só via `services/api.ts`
- [ ] Nenhum `ALTER TABLE` manual — só Alembic
- [ ] `py_compile` e `npx tsc --noEmit` limpos
- [ ] `alembic heads` retorna **um único** head
- [ ] Versão registrada em `versoes_sistema`
- [ ] Plano de teste manual no PR

---

## 11. Riscos

| Risco | Mitigação |
|---|---|
| Remapeamento de RBAC subestimado | Fechar D1/D3 na Fase 0; é o item de maior esforço |
| Colisão com tabelas/rotas do módulo legado | Prefixo `bh_*` já isola tabelas; definir slug (D5) |
| Conflito de numeração de migrations no merge | Renumerar a partir do `alembic heads` no momento do PR |
| Dados sensíveis (salário) sob novo modelo de acesso | Auditar acesso + confirmar regra LGPD (seção 7) |
| **`recharts` ausente no Pulse** (gráficos dos relatórios) | Guia §9.3 exige aprovação de lib nova — confirmar com o integrador ou trocar pela lib de gráficos já usada no Pulse |
| Router de 1.860 linhas dificulta revisão do PR | Avaliar quebrar `banco_de_horas.py` em sub-módulos no porte |
| Divergência silenciosa do padrão | Guia, seção 12: alinhar antes de implementar |

---

*Este documento serve de base para o integrador confrontar com a arquitetura atual do Hostweb Pulse.
Recomenda-se fechar as decisões D1–D5 antes de iniciar a Fase 1.*
