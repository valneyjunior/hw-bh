# Guia para criação de novos módulos no Hostweb Pulse

> Este documento descreve o que um novo módulo do Pulse precisa ter para se integrar à plataforma. Use-o como contexto inicial do seu Claude Code antes de pedir para implementar qualquer módulo novo. Cada tela do menu lateral do Pulse é chamada de **módulo**.

---

## 1. Stack e padrões gerais

| Camada | Tecnologia | Linguagem/Convenção |
|---|---|---|
| Backend | FastAPI 0.115 + SQLAlchemy 2.0 (async) + Pydantic v2 + Alembic | `snake_case` |
| Frontend | React 19 + TypeScript + Vite + TailwindCSS v4 + react-router-dom v7 | `camelCase` |
| Banco | PostgreSQL 16 (Docker) | UTF-8, BRT |
| Proxy | Nginx (remove o prefixo `/api` antes de encaminhar) | — |
| Auth | JWT em `Authorization: Bearer <token>` | claims: `sub`, `tipo`, `grupo_id`, `grupo_nome` |

Regras inegociáveis:

- Backend: toda rota tem `response_model` Pydantic v2. Usar `model_dump()` / `model_validate()` (nunca `.dict()` / `.parse_obj()`).
- Backend: nenhum `ALTER TABLE` manual — sempre via Alembic.
- Frontend: nenhum `fetch` ou `axios` direto — toda chamada HTTP passa por `services/api.ts`. Prefixo `/api/v1` é automático.
- Frontend: todos os tipos de domínio em `frontend/src/types/desk.ts`.
- Python 3.11: não usar `\` dentro de expressão `{…}` em f-string. Extrair para variável antes.
- Antes de commit: `python -m py_compile` em todo `.py` alterado e `npx tsc --noEmit` no frontend.
- Não commitar `.env`.

---

## 2. Setup do ambiente de desenvolvimento

```sh
# 1. Clonar o repositório
git clone <url-do-repo>
cd hostweb-dm

# 2. Copiar variáveis de ambiente e preencher mínimas
cp .env.example .env
# Mínimo necessário para desenvolver um módulo novo:
#   POSTGRES_*  → banco local
#   JWT_SECRET  → string >= 32 chars
#   USER_*_PASS → senhas dos usuários de seed

# 3. Subir Postgres
docker-compose up -d postgres

# 4. Backend
cd backend
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
alembic upgrade head    # aplica todas as migrations
python seed.py          # cria usuários iniciais (admin + comercial/tecnologia/diretoria)
uvicorn main:app --reload --port 8000

# 5. Frontend (outro terminal)
cd frontend
npm install
npm run dev

# 6. Acessar http://localhost:5173 (login admin@hostweb.cloud com a senha definida no .env)
```

Atalho: `./start-app.sh` (na raiz) automatiza tudo acima no macOS.

**O que dá para testar sem credenciais de produção:** qualquer módulo novo que use só o Postgres local. Integrações com Desk Manager, NetBox, Kickidler, MSSQL legado, InfluxDB e Microsoft Graph exigem credenciais que ficam no servidor de produção — não tente desenvolver depend­ente delas.

---

## 3. Anatomia de um módulo

Um módulo do Pulse é composto por:

| # | O que | Onde | Obrigatório |
|---|---|---|---|
| 1 | Item no menu lateral | `frontend/src/config/navegacao.ts` | Sim |
| 2 | Rota da SPA | `frontend/src/App.tsx` | Sim |
| 3 | Página | `frontend/src/pages/<Modulo>.tsx` | Sim |
| 4 | Tipos TypeScript | `frontend/src/types/desk.ts` | Sim (se faz chamadas tipadas) |
| 5 | Router FastAPI | `backend/routers/<modulo>.py` | Sim (se tem backend) |
| 6 | Registro do router | `backend/main.py` | Sim (se tem backend) |
| 7 | Modelos SQLAlchemy | `backend/models.py` | Se persiste dados |
| 8 | Migration Alembic | `backend/migrations/versions/00XX_<descricao>.py` | Se cria/altera tabela |
| 9 | Migration de versão | `backend/migrations/versions/00YY_versao_X_Y_Z.py` | Sim, ao final |

**Identificadores do módulo (escolha antes de começar):**

- **Slug interno (kebab-case)**: `meu-modulo` — vira a URL e a string de permissão.
- **Nome do arquivo (PascalCase)**: `MeuModulo.tsx` no frontend, `meu_modulo.py` (snake_case) no backend.
- **Label visível**: o que aparece no menu (ex.: `Meu Módulo`).
- **Permissão RBAC**: idêntica à rota, prefixada com `/` (ex.: `/meu-modulo`).

A permissão é gerada automaticamente: ao adicionar o item em `NAV_ITEMS`, ele aparece como checkbox na tela Admin → Grupos. O admin marca para liberar o módulo para um grupo. Usuários `admin` ignoram todas as checagens.

---

## 4. Auth e RBAC

### 4.1. Backend — dependências disponíveis em `backend/auth.py`

```python
from auth import get_current_user, require_tipo, require_grupo, require_permissao
```

- `get_current_user` → injeta o `Usuario` autenticado. Use em rotas que só precisam saber quem é o usuário.
- `require_tipo("admin")` → bloqueia não-admin (403).
- `require_grupo("comercial", "diretoria")` → bloqueia grupos fora da lista. Admin passa.
- `require_permissao("/meu-modulo")` → exige a string de permissão na lista do grupo. Admin passa. **É o padrão para gating de módulo.**

Aplicar como dependência da rota:

```python
@router.get("", response_model=MinhaResposta)
async def listar(user: Usuario = Depends(require_permissao("/meu-modulo"))):
    return ...
```

Ou criar uma dependência reusável no topo do arquivo:

```python
_perm = require_permissao("/meu-modulo")

@router.get("", response_model=MinhaResposta)
async def listar(user: Usuario = Depends(_perm)):
    return ...
```

### 4.2. Frontend — `PrivateRoute`

```tsx
<Route path="/meu-modulo" element={
  <PrivateRoute rota="/meu-modulo"><MeuModulo /></PrivateRoute>
} />
```

`PrivateRoute`:

- Redireciona para `/login` se não houver token.
- Redireciona para `/alterar-senha` se `must_change_password`.
- Bloqueia o acesso se o `tipo` não for `admin` e a `rota` não estiver em `sessionStorage.permissoes`.

Para rotas **apenas admin** (ex.: telas administrativas), use `apenasAdmin`:

```tsx
<Route path="/admin/meu-painel" element={
  <PrivateRoute apenasAdmin><MeuPainelAdmin /></PrivateRoute>
} />
```

---

## 5. Backend — receita

### 5.1. Router básico (`backend/routers/meu_modulo.py`)

```python
from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from typing import Optional
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select

from auth import get_current_user, require_permissao
from database import get_db
from models import Usuario  # adicione MeuModuloItem se criar nova tabela

router = APIRouter(tags=["meu_modulo"])

_perm = require_permissao("/meu-modulo")


# ─── Schemas Pydantic v2 ───────────────────────────────────────────────────
class ItemOut(BaseModel):
    id: int
    nome: str
    ativo: bool

    # Para serializar diretamente de modelo SQLAlchemy:
    # from pydantic import ConfigDict
    # model_config = ConfigDict(from_attributes=True)


class ItemCreate(BaseModel):
    nome: str


# ─── Endpoints ─────────────────────────────────────────────────────────────
@router.get("", response_model=list[ItemOut])
async def listar(
    db: AsyncSession = Depends(get_db),
    _user: Usuario = Depends(_perm),
):
    # Exemplo com tabela própria — adapte ao seu modelo
    # r = await db.execute(select(MeuModuloItem))
    # itens = r.scalars().all()
    # return [ItemOut.model_validate(i) for i in itens]
    return []


@router.post("", response_model=ItemOut, status_code=201)
async def criar(
    body: ItemCreate,
    db: AsyncSession = Depends(get_db),
    user: Usuario = Depends(_perm),
):
    # ... persistir e devolver
    raise HTTPException(status_code=501, detail="Implementar")
```

### 5.2. Registrar o router em `backend/main.py`

No topo (junto dos demais imports de router):

```python
from routers import meu_modulo as meu_modulo_router
```

No bloco de `include_router` (próximo ao final do arquivo):

```python
app.include_router(meu_modulo_router.router, prefix="/v1/meu-modulo")
```

**Convenção de prefixo:** `/v1/<slug-kebab>`. O Nginx remove o `/api` externo; o frontend chama `/api/v1/meu-modulo`, o FastAPI recebe `/v1/meu-modulo`.

### 5.3. Modelo SQLAlchemy (se precisa persistir)

Em `backend/models.py`, adicione a classe junto das demais (segue o padrão dos modelos existentes):

```python
class MeuModuloItem(Base):
    __tablename__ = "meu_modulo_itens"

    id:        Mapped[int]      = mapped_column(Integer, primary_key=True)
    nome:      Mapped[str]      = mapped_column(String, nullable=False)
    ativo:     Mapped[bool]     = mapped_column(Boolean, nullable=False, server_default="true")
    criado_em: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now())
```

Naming de tabela: `snake_case`, prefixada pelo nome do módulo para evitar colisão (ex.: `meu_modulo_itens`).

### 5.4. Migration de tabela (`backend/migrations/versions/00XX_meu_modulo_base.py`)

Numere com 4 dígitos, sequencial a partir do head atual. Para descobrir o head:

```sh
cd backend && alembic heads
```

Template:

```python
"""meu_modulo_base

Revision ID: 00XX
Revises: 00YY     # head atual
Create Date: 2026-MM-DD
"""
from alembic import op
import sqlalchemy as sa

revision = '00XX'
down_revision = '00YY'
branch_labels = None
depends_on = None


def upgrade():
    op.create_table(
        'meu_modulo_itens',
        sa.Column('id',        sa.Integer(),  nullable=False),
        sa.Column('nome',      sa.String(),   nullable=False),
        sa.Column('ativo',     sa.Boolean(),  nullable=False, server_default=sa.text('true')),
        sa.Column('criado_em', sa.DateTime(), nullable=False, server_default=sa.text('now()')),
        sa.PrimaryKeyConstraint('id'),
    )


def downgrade():
    op.drop_table('meu_modulo_itens')
```

Aplicar localmente: `alembic upgrade head`.

### 5.5. Quando precisar do usuário corrente

```python
@router.post("/algo")
async def fazer_algo(
    body: AlgoCreate,
    db: AsyncSession = Depends(get_db),
    user: Usuario = Depends(_perm),       # ou get_current_user direto
):
    # user.id, user.tipo, user.email, user.grupo, user.grupo.permissoes
    ...
```

### 5.6. Pool de conexão

Já configurado em `backend/config.py`: `pool_size=5, max_overflow=10`. Não duplicar.

---

## 6. Frontend — receita

### 6.1. Tipos (`frontend/src/types/desk.ts`)

Adicione ao final, agrupados por módulo:

```ts
// ─── Meu Módulo ───────────────────────────────────────────────────────────

export interface MeuModuloItem {
  id: number
  nome: string
  ativo: boolean
}

export interface MeuModuloCreate {
  nome: string
}
```

### 6.2. Página (`frontend/src/pages/MeuModulo.tsx`)

```tsx
import { useEffect, useState } from 'react'
import api from '../services/api'
import type { MeuModuloItem } from '../types/desk'

export default function MeuModulo() {
  const [itens, setItens] = useState<MeuModuloItem[]>([])
  const [loading, setLoading] = useState(true)
  const [erro, setErro] = useState('')

  useEffect(() => {
    api.get<MeuModuloItem[]>('/meu-modulo')
      .then(r => setItens(r.data))
      .catch((err: unknown) => {
        const detail = (err as { response?: { data?: { detail?: string } } })?.response?.data?.detail
        setErro(detail ?? 'Erro ao carregar itens.')
      })
      .finally(() => setLoading(false))
  }, [])

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-900">Meu Módulo</h1>
      </div>

      {loading && <p className="text-gray-400">Carregando…</p>}
      {erro && <div className="bg-red-50 border border-red-200 rounded-xl p-4 text-red-700 text-sm">{erro}</div>}

      {!loading && !erro && (
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
          {/* … render dos itens … */}
        </div>
      )}
    </div>
  )
}
```

**Observações de estilo:**

- Container raiz: `p-6 space-y-6`.
- Títulos H1: `text-2xl font-bold text-gray-900`.
- Cards: `bg-white rounded-xl border border-gray-200 p-4`.
- Acento primário: vermelho `red-700` (botões principais, badges de alerta).
- Tabelas: `divide-y divide-gray-50`, header `bg-gray-50 text-xs uppercase tracking-wide`.
- **Não inclua menu lateral, header global ou logout dentro da página** — o `Layout.tsx` cuida.

### 6.3. Sidebar (`frontend/src/config/navegacao.ts`)

Adicione um item ao array `NAV_ITEMS`:

```ts
{ rota: '/meu-modulo', to: '/meu-modulo', label: 'Meu Módulo', icon: '✨' },
```

- `rota` e `to` são iguais ao slug.
- `label` é o nome visível.
- `icon` aceita emoji ou string. Veja itens existentes para referência.

### 6.4. Rota (`frontend/src/App.tsx`)

No topo, importe a página:

```tsx
import MeuModulo from './pages/MeuModulo'
```

Dentro de `<Routes>`, junto das demais rotas privadas (dentro do `<Route element={<PrivateRoute><Layout /></PrivateRoute>}>`):

```tsx
<Route path="/meu-modulo" element={
  <PrivateRoute rota="/meu-modulo"><MeuModulo /></PrivateRoute>
} />
```

A `rota` da `PrivateRoute` deve ser **idêntica** à do `NAV_ITEMS` — é o que permite o RBAC casar com a permissão do grupo.

### 6.5. Chamadas HTTP — sempre via `services/api.ts`

```ts
import api from '../services/api'

const { data } = await api.get<MeuModuloItem[]>('/meu-modulo')
await api.post<MeuModuloItem>('/meu-modulo', { nome: 'Novo' })
await api.patch<MeuModuloItem>(`/meu-modulo/${id}`, { nome: 'Atualizado' })
await api.delete(`/meu-modulo/${id}`)
```

O baseURL `/api/v1` é aplicado automaticamente. O token vai no header também automaticamente. Em caso de 401 o usuário é redirecionado para `/login`.

---

## 7. Versionamento e changelog

Toda mudança significativa do Pulse (módulo novo, feature, correção visível) registra uma versão em `versoes_sistema`, via migration.

### 7.1. Numeração

Padrão semântico:

- `MAJOR.MINOR.PATCH` (ex.: `2.48.0`).
- Pergunte ao integrador qual é a próxima versão antes de gravar.
- Use `MINOR` para módulo/funcionalidade nova, `PATCH` para correção.

### 7.2. Migration de versão (`backend/migrations/versions/00ZZ_versao_X_Y_Z.py`)

```python
"""versao_2_49_0

Revision ID: 00ZZ
Revises: 00YY
Create Date: 2026-MM-DD
"""
from alembic import op
from sqlalchemy import text

revision = '00ZZ'
down_revision = '00YY'
branch_labels = None
depends_on = None


DESCRICAO = """Tipo: Funcionalidade | Data: DD/MM/YYYY

Novo módulo Meu Módulo

[Novo] Lista de itens ativos por usuário com filtros e exportação.
[Novo] Endpoint /v1/meu-modulo para CRUD básico."""


def upgrade():
    op.execute(
        text("""
            INSERT INTO versoes_sistema (versao, descricao, autor, created_at)
            VALUES (:versao, :descricao, :autor, now())
            ON CONFLICT DO NOTHING
        """).bindparams(versao='2.49.0', descricao=DESCRICAO, autor='<Seu Nome>')
    )


def downgrade():
    op.execute("DELETE FROM versoes_sistema WHERE versao = '2.49.0'")
```

Tipos do changelog: `Funcionalidade` (novo), `Correção` (fix), `Manutenção` (refactor/infra). Use `[Novo]`, `[Correção]`, `[Alterado]` nos itens.

Se a mesma entrega já tem uma migration de schema, **pode-se combinar** o INSERT da versão dentro dela (ver `0049_legado_usuario_ativo.py` como exemplo). Caso contrário, crie uma migration separada só para a versão.

---

## 8. Validação pré-commit (obrigatório)

```sh
# Para cada .py alterado
python -m py_compile backend/routers/meu_modulo.py
python -m py_compile backend/migrations/versions/00XX_*.py

# Frontend
cd frontend && npx tsc --noEmit && cd ..

# Build completo (opcional aqui, mas o integrador rodará no deploy)
cd frontend && npm run build && cd ..
```

Histórico real: já tivemos o sistema cair em produção por `SyntaxError` que passou despercebido sem `py_compile`. **Não pule essa etapa.**

Heads do alembic (deve ser um único):

```sh
cd backend && alembic heads
```

Se houver mais de um head, há conflito de migration — converse com o integrador.

---

## 9. Hand-off — como entregar para integração

O dono do repositório (integrador) é responsável pelo merge em `main` e pelo deploy em produção. Você entrega via PR.

### 9.1. Branch e commits

```sh
git checkout -b feat/meu-modulo
# … trabalho …
git add <arquivos específicos>      # nunca git add -A se houver arquivos não relacionados
git commit -m "feat: módulo Meu Módulo — descrição curta"
git push origin feat/meu-modulo
```

### 9.2. Conteúdo do PR

Inclua no corpo:

1. **Resumo do módulo:** o que faz, para qual perfil de usuário.
2. **Lista de arquivos novos/alterados** com função de cada.
3. **Versão proposta** (ex.: `2.49.0`) e a migration que a registra.
4. **Validações executadas:** saída de `tsc`, `py_compile` e (se aplicável) `alembic heads`.
5. **Plano de teste manual:** que telas abrir, que ações fazer, o que esperar.
6. **Dependências externas:** se o módulo precisa de credenciais, endpoint externo, variável de ambiente nova etc.
7. **Migrations:** confirmação de que a numeração casa com o head do repositório no momento do PR (rebase se necessário).

### 9.3. O que NÃO fazer

- Não rodar `bash scripts/deploy-push.sh` ou `deploy-pull.sh` — só o integrador faz isso (tem acesso ao servidor).
- Não tocar em `.env`, `frontend/dist/`, `scripts/`, `CLAUDE.md`, `docs/guia-novos-modulos.md` (este arquivo) sem alinhar.
- Não criar migration manual fora de `backend/migrations/versions/` — sempre via Alembic.
- Não alterar `services/api.ts`, `auth.py`, `models.py` de tabelas existentes, ou `Layout.tsx` sem combinar.
- Não introduzir libs novas no `requirements.txt` ou `package.json` sem justificativa documentada — discutir antes.

### 9.4. Checklist final antes de abrir o PR

- [ ] Slug e permissão definidos e consistentes em backend (`prefix`), frontend (`navegacao.ts`, `App.tsx`) e RBAC (`require_permissao`).
- [ ] `response_model` em todas as rotas FastAPI.
- [ ] Tipos do front em `types/desk.ts`, nenhum `any` deixado para trás.
- [ ] Nenhum `fetch`/`axios` direto — só `services/api.ts`.
- [ ] Nenhum `ALTER TABLE` manual — só Alembic.
- [ ] `py_compile` limpo em todos os `.py` novos/alterados.
- [ ] `npx tsc --noEmit` limpo.
- [ ] `alembic heads` retorna **um único** head.
- [ ] Migration de versão registrada em `versoes_sistema`.
- [ ] Plano de teste manual descrito no PR.

---

## 10. Pegadinhas comuns

- **NBSP em código:** alguns editores inserem `\xa0` (no-break space) no lugar de espaço normal, e o Python pode aceitar mas regex/`re.match` se comporta diferente. Se algo se comportar estranho, abra o arquivo em modo bytes e cheque (`od -c arquivo.py | head`).
- **f-string com `\` em expressão:** Python 3.11 rejeita. Extrair a string com `\` para uma variável antes de usar dentro de `{…}`.
- **`float - Decimal` em Python:** levanta `TypeError`. Se o driver devolver uma coluna como `Decimal` (ex.: `pymssql` para colunas `decimal`) e outra como `float`, converta ambas com `float(...)` antes de operar.
- **`.in_([])` em SQLAlchemy:** filtros com lista vazia retornam zero linhas — geralmente é bug, raramente é intenção. Cheque `if lista is not None and lista:` antes.
- **`max-h` sozinho não limita renderização:** se você precisa de "até N por tela", além de `max-h-[XXX]` no container faça `array.slice(0, N).map(...)`. CSS não controla o DOM.
- **Sticky em modal:** o backdrop `fixed inset-0` bloqueia o scroll da página. Modais com lista variável precisam de `flex flex-col max-h-[90vh]`, header/footer `shrink-0` e body `overflow-y-auto flex-1`.
- **Cache do navegador no deploy:** depois do deploy-pull, faça `Cmd+Shift+R` / `Ctrl+Shift+R` para forçar o navegador a baixar o bundle novo.

---

## 11. Referências rápidas no repositório

- Documentação geral: `CLAUDE.md` (raiz)
- Exemplos completos de módulo com tabela + RBAC: `backend/routers/legado.py` + `frontend/src/pages/Legado.tsx` (módulo Banco de Horas) e `backend/routers/kickidler.py` + `frontend/src/pages/Kickidler.tsx`.
- Padrão de migration de tabela: `backend/migrations/versions/0040_kickidler_base.py`.
- Padrão de migration só de versão: `backend/migrations/versions/0041_kickidler_versao.py`.
- Padrão de modal com scroll: `frontend/src/pages/admin/Grupos.tsx`.
- Padrão de cabeçalho fixo (sticky) + cards: `frontend/src/pages/Legado.tsx`.
- Padrão de slide-over igual à visualização de Chamados: `frontend/src/components/Timeline.tsx` e o frame em `Legado.tsx`.

---

## 12. Dúvidas e divergências

Se o módulo precisar de algo que foge do padrão deste documento (lib nova, alteração em arquivo de outra equipe, integração com sistema externo, fluxo de auth diferente), **pare e alinhe com o integrador antes de implementar**. Divergências silenciosas geram retrabalho no merge.
