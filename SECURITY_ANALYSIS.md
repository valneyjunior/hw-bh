# Auditoria de Segurança — BH Pulse
**OWASP Top 10 (2021) · Pré-produção**
Data: 2026-06-02 · Versão: 1.2 (atualizado após correções) · Stack: FastAPI + SQLAlchemy async + JWT + React + PostgreSQL

---

## Sumário Executivo

**Criticidade Geral: BAIXA** *(era MÉDIA — 7 de 9 itens acionáveis corrigidos; restam apenas 2, que dependem de ação manual e de infraestrutura de produção)*

A migração da stack PHP (BH Tracker) para **FastAPI + React (BH Pulse)** eliminou por design as
classes de vulnerabilidade mais críticas do sistema anterior:

- **SQL Injection** — neutralizado pelo uso de **SQLAlchemy ORM** com queries parametrizadas (não há concatenação de SQL).
- **XSS** — neutralizado pelo **escape automático do React** (JSX); não se usa `dangerouslySetInnerHTML`.
- **Senhas** — armazenadas com **bcrypt (cost 12)**.

Nesta revisão (v1.2), além das correções de v1.1 (segredo JWT, rate limiting, política de senha,
security headers, CORS configurável), foi implementado o **audit log com trilha tamper-evident
(não-repúdio)** — V-006. Restam apenas **2 itens**, ambos fora do código: rotação manual de segredos
(V-002) e TLS de produção (V-004).

| Criticidade | Total | Corrigido | Pendente |
|:---|:---:|:---:|:---:|
| 🔴 Crítica | 2 | 1 | 1 (ação manual) |
| 🟠 Alta | 3 | 2 | 1 (requer servidor) |
| 🟡 Média | 4 | 4 | 0 |
| 🟢 Por design/Mitigado | 3 | 3 | 0 |

---

## Tabela de Vulnerabilidades

| ID | OWASP | Componente | Tipo | Criticidade | Status |
|:---|:------|:-----------|:-----|:------------|:-------|
| V-001 | A02:2021 | `config.py` / `.env` | `JWT_SECRET` no valor padrão | 🔴 Crítica | ✅ Corrigido |
| V-002 | A05:2021 | `.env` (Azure/Admin) | Segredos a rotacionar (expostos) | 🔴 Crítica | ⚠️ Ação manual |
| V-003 | A02:2021 | `/admin/backup` | Backup expõe salários + hashes | 🟠 Alta | ✅ Mitigado |
| V-004 | A05:2021 | `docker-compose.yml` | HTTP sem TLS | 🟠 Alta | ⚠️ Requer servidor |
| V-005 | A07:2021 | `/v1/auth/login` | Sem rate limiting (brute force) | 🟠 Alta | ✅ Corrigido |
| V-006 | A09:2021 | múltiplos | Logging/auditoria insuficiente | 🟡 Média | ✅ Corrigido |
| V-007 | A07:2021 | `auth.py` / `main.py` | Política de senha fraca (6 chars) | 🟡 Média | ✅ Corrigido |
| V-008 | A05:2021 | FastAPI / Vite | Security headers ausentes | 🟡 Média | ✅ Corrigido |
| V-009 | A05:2021 | CORS middleware | CORS amplo / fixo no código | 🟡 Média | ✅ Corrigido |
| V-010 | A01:2021 | SQLAlchemy ORM | SQL Injection | 🟢 Por design | ✅ Protegido |
| V-011 | A03:2021 | React/JSX | XSS | 🟢 Por design | ✅ Protegido |
| V-012 | A07:2021 | `get_current_user` | Revogação de acesso | 🟢 Mitigado | ✅ Implementado |

---

## V-001 — `JWT_SECRET` no valor padrão ✅ CORRIGIDO

**Localização:** `backend/config.py` e `.env`

**Problema original:** o segredo de assinatura dos tokens JWT estava no valor de exemplo
(`"troque_por_string_aleatoria_32chars"`). Com HS256, quem conhecesse esse valor poderia **forjar
um token de admin** e obter acesso total.

**Correção aplicada:**
1. Gerado e gravado no `.env` um segredo forte (`secrets.token_urlsafe(48)`).
2. Adicionado, no `startup` da aplicação, um **alerta automático** caso o segredo continue no padrão:
```python
# config.py
@property
def jwt_secret_inseguro(self) -> bool:
    return self.JWT_SECRET == "troque_por_string_aleatoria_32chars"

# main.py
@app.on_event("startup")
async def _avisos_seguranca():
    if settings.jwt_secret_inseguro:
        logger.warning("⚠️  JWT_SECRET está no valor PADRÃO inseguro — defina um segredo forte ...")
```

**Validação:** token assinado com o segredo antigo passou a ser **rejeitado com HTTP 401**.

> Atenção operacional: mantenha o `JWT_SECRET` **fixo** entre deploys (variável de ambiente/secret).
> Gerar um novo a cada restart invalidaria todas as sessões ativas.

---

## V-002 — Segredos sensíveis a rotacionar ⚠️ AÇÃO MANUAL

**Localização:** `.env` (raiz)

O `.env` contém credenciais reais: `AZURE_CLIENT_SECRET` (Microsoft Graph), `ADMIN_PASSWORD` e
`POSTGRES_PASSWORD`. O arquivo **já está protegido** pelo `.gitignore` (não é versionado) ✅.

Porém, como o `AZURE_CLIENT_SECRET` e o token do GitHub do repositório foram manuseados durante o
desenvolvimento, recomenda-se **rotacioná-los**:

- **Azure**: portal Entra ID → App registration → *Certificates & secrets* → revogar o secret atual e gerar novo.
- **GitHub**: Settings → Developer settings → *Personal access tokens* → revogar o token embutido no remote e reconfigurar com o Git Credential Manager.
- **ADMIN_PASSWORD**: trocar pela tela do sistema após o primeiro acesso.

**Boas práticas no compose:**
```yaml
environment:
  POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:?defina POSTGRES_PASSWORD}
  JWT_SECRET: ${JWT_SECRET:?defina JWT_SECRET}
```

---

## V-003 — Backup expõe dados sensíveis ✅ MITIGADO

**Localização:** `routers/banco_de_horas.py` — `GET /admin/backup`

O backup JSON inclui **salários, e-mails, telefones e hashes de senha** de todos os colaboradores.
É restrito a `admin` via `require_tipo("admin")`, mas o **arquivo gerado é altamente sensível** (LGPD).

**Mitigações já aplicadas:**
- Endpoint exige perfil **admin**.
- Tela de Backup exibe **aviso de confidencialidade** (LGPD).
- `.gitignore` exclui `bh-pulse-backup-*.json`, `*.dump`, `*.sql.bak`.

**Melhorias recomendadas (médio prazo):**
- Criptografar o arquivo de backup (ex.: AES via senha do admin) antes do download.
- Registrar cada backup/restore no audit log (ver V-006).
- Avaliar omitir/rotacionar `senha_hash` no export (forçar redefinição no restore).

---

## V-004 — HTTP sem TLS ⚠️ REQUER INFRAESTRUTURA

**Localização:** `docker-compose.yml`
```yaml
ports:
  - "3001:3001"   # frontend  — HTTP puro
  - "8001:8000"   # backend   — HTTP puro
```
O **token JWT** trafega no header `Authorization` em texto claro — sujeito a roubo em rede não
confiável. Viola LGPD e ISO 27001.

**Correção (produção):** NGINX como proxy reverso com TLS (Let's Encrypt), expondo apenas HTTPS, e
restringir as portas internas ao loopback:
```yaml
ports:
  - "127.0.0.1:8001:8000"   # backend acessível só via proxy
```
Ajustar também `APP_URL` e o `allow_origins` do CORS para o domínio HTTPS.

---

## V-005 — Sem rate limiting no login ✅ CORRIGIDO

**Localização:** `main.py` — `POST /v1/auth/login`

**Problema original:** sem limite de tentativas → exposto a **força bruta** de credenciais.

**Correção aplicada** (dependência `slowapi==0.1.9`):
```python
from slowapi import Limiter, _rate_limit_exceeded_handler
from slowapi.util import get_remote_address

limiter = Limiter(key_func=get_remote_address)
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)

@app.post("/v1/auth/login")
@limiter.limit("5/minute")
async def login(request: Request, payload: LoginIn, ...):
    ...
```

**Validação:** após 5 tentativas em 1 minuto, a 6ª retornou **HTTP 429**.

> Em produção atrás de proxy/NGINX, repassar `X-Forwarded-For` para que o IP de origem real
> seja usado como chave do limitador.

---

## V-006 — Logging / auditoria insuficiente ✅ CORRIGIDO

**OWASP A09:2021 — Security Logging and Monitoring Failures**

**Correção aplicada** — implementado audit log com **trilha tamper-evident (não-repúdio)** em
`backend/audit.py` + tabela `bh_audit_logs` (migration `0006`).

**Como o não-repúdio é garantido:**
- **Encadeamento por hash (SHA-256):** cada registro inclui o hash do anterior. Qualquer
  alteração/remoção retroativa quebra a cadeia e é detectada na verificação.
- **Atomicidade:** o registro entra na **mesma transação** da operação — não há ação sem log
  nem log sem ação.
- **Serialização:** `pg_advisory_xact_lock` evita corrida na escrita da cadeia.
- **Snapshot de identidade:** grava nome/e-mail do autor (sobrevive à exclusão do usuário) + **IP** + timestamp.
- **Sobrevive ao restore:** o restore usa `DELETE` ordenado (não `TRUNCATE CASCADE`), preservando
  `bh_audit_logs`, e a própria restauração é auditada.

```python
# audit.py — encadeamento e verificação
async def registrar(db, *, usuario, acao, recurso, recurso_id=None, ip=None, detalhes=None) -> str:
    await db.execute(text("SELECT pg_advisory_xact_lock(:k)").bindparams(k=_LOCK_KEY))
    hash_anterior = (await db.execute(select(BhAuditLog.hash_registro)
                     .order_by(BhAuditLog.id.desc()).limit(1))).scalar_one_or_none() or "GENESIS"
    # hash_registro = sha256(conteúdo + hash_anterior) ...
```

**Eventos auditados:** login (sucesso/falha), alteração de senha, aprovar/recusar/contestar
lançamento, aprovar/recusar folga, criar/editar/config/reset-senha/desativar/arquivar/restaurar
usuário, **baixar/restaurar backup**.

**Endpoints (admin):** `GET /admin/auditoria` (consulta paginada) e
`GET /admin/auditoria/verificar` (recalcula a cadeia e aponta o registro adulterado).

**Validação:** adulterar um registro via SQL fez a verificação retornar
`integro=false, quebrado_no_id=1`; restaurar o valor voltou a `integro=true`. Frontend em
**Auditoria** (menu admin) com botão "Verificar integridade".

---

## V-007 — Política de senha fraca ✅ CORRIGIDO

**Localização:** `auth.py`, `main.py` (`alterar_senha`), `routers/banco_de_horas.py` (criar usuário / reset)

**Problema original:** mínimo de apenas **6 caracteres**, sem exigência de complexidade.

**Correção aplicada** — função central reutilizada em **alterar senha, criar usuário e reset de senha**:
```python
# auth.py
def validar_forca_senha(senha: str) -> Optional[str]:
    if len(senha) < 8:
        return "A senha deve ter ao menos 8 caracteres."
    if not any(c.isalpha() for c in senha):
        return "A senha deve conter ao menos uma letra."
    if not any(c.isdigit() for c in senha):
        return "A senha deve conter ao menos um número."
    return None
```

**Validação:** criar usuário com senha `"123"` retornou **HTTP 400 — "A senha deve ter ao menos 8
caracteres."** (rejeitado antes de qualquer escrita). O gerador automático do sistema (10 chars)
satisfaz a política.

---

## V-008 — Security headers ausentes ✅ CORRIGIDO

**Correção aplicada** — middleware em `main.py` adiciona os cabeçalhos a **todas as respostas**:
```python
@app.middleware("http")
async def security_headers(request: Request, call_next):
    resp = await call_next(request)
    resp.headers["X-Content-Type-Options"] = "nosniff"
    resp.headers["X-Frame-Options"] = "DENY"
    resp.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
    resp.headers["Permissions-Policy"] = "camera=(), microphone=(), geolocation=()"
    return resp
```

**Validação:** os 4 cabeçalhos foram confirmados na resposta de `GET /health`.

> Pendente para a fase de produção (depende de V-004): habilitar **HSTS** (`Strict-Transport-Security`)
> sob HTTPS e uma **Content-Security-Policy** restritiva no frontend servido pelo NGINX (testar em
> staging, pois CSP mal configurada bloqueia assets do Vite).

---

## V-009 — CORS fixo no código ✅ CORRIGIDO

**Problema original:** as origens estavam codificadas (`localhost`) diretamente no `main.py`,
exigindo alteração de código para mudar de ambiente.

**Correção aplicada** — origens agora vêm de variável de ambiente (`config.py` → `CORS_ORIGINS`):
```python
# config.py
CORS_ORIGINS: str = "http://localhost:3001,http://127.0.0.1:3001"
@property
def cors_origins_list(self) -> list[str]:
    return [o.strip() for o in self.CORS_ORIGINS.split(",") if o.strip()]

# main.py
app.add_middleware(CORSMiddleware, allow_origins=settings.cors_origins_list, ...)
```

**Em produção:** basta definir `CORS_ORIGINS=https://seu-dominio` no `.env` — sem tocar no código.
Como a autenticação usa **token no header `Authorization`** (não cookie), o `allow_credentials=True`
pode ser desativado se não houver uso de cookies.

---

## V-010 — SQL Injection 🟢 PROTEGIDO POR DESIGN

Todas as consultas usam **SQLAlchemy** com expressões parametrizadas (`select().where(Coluna == valor)`),
sem concatenação de strings SQL. O driver `asyncpg` faz o binding dos parâmetros.

**Único uso de SQL textual** (`text()`) está no restore, e apenas com **identificadores de origem
interna fixa** (nomes de tabela/sequência num dicionário do código), nunca com entrada do usuário:
```python
# routers/banco_de_horas.py — restore (valores NÃO vêm do cliente)
await db.execute(text(f"SELECT setval('{seq}', (SELECT COALESCE(MAX(id),1) FROM {tabela}))"))
```
> Diretriz: manter qualquer `text()` futuro **sem interpolar dados do usuário** — usar binds `:param`.

---

## V-011 — XSS 🟢 PROTEGIDO POR DESIGN

O React escapa automaticamente todo conteúdo renderizado em JSX. O projeto **não usa**
`dangerouslySetInnerHTML`. Os links de WhatsApp (`wa.me`) e telefone (`tel:`) são construídos a
partir de dígitos sanitizados (`telefone.replace(/\D/g, '')`).

> Diretriz: evitar `dangerouslySetInnerHTML`; ao renderizar HTML de terceiros no futuro, sanitizar com DOMPurify.

---

## V-012 — Revogação de acesso 🟢 MITIGADO

Embora o JWT seja *stateless*, o `get_current_user` **revalida o usuário no banco a cada requisição**:
```python
# auth.py
result = await db.execute(select(Usuario).where(Usuario.email == email, Usuario.ativo == True))
```
Logo, **desativar um usuário revoga o acesso imediatamente** (a próxima requisição falha), sem
precisar esperar o token expirar. Expiração do token: `JWT_EXPIRE_HOURS = 8` (razoável).

> Melhoria futura: refresh tokens + blacklist para revogação granular por dispositivo.

---

## Proteções já implementadas (pontos fortes)

| Proteção | Implementação |
|:---|:---|
| Hash de senha | `bcrypt` cost 12 (`auth.py`) |
| Senhas nunca em texto claro | Apenas `senha_hash` persistido |
| Controle de acesso por perfil | `require_tipo`, `require_perfil`, `require_acionamento` |
| Escopo de coordenador | Validação limitada aos setores coordenados (`_setores_coordenados`) |
| Segregação de função | Coordenador não aprova o próprio lançamento (`requer_aprovacao_diretor`) |
| Troca de senha no 1º acesso | `must_change_password` |
| Mensagem de login genérica | "Credenciais inválidas" (não revela se e-mail existe) |
| Validação de entrada | Pydantic v2 em todos os payloads |
| `.env` fora do Git | `.gitignore` cobre `.env`, backups e dumps |
| E-mail resiliente | Falha no Graph não quebra a operação |

---

## Checklist Pré-Produção

### 🔴 Obrigatório (bloqueante)
- [x] **V-001**: `JWT_SECRET` forte gerado + alerta de startup se padrão
- [ ] **V-002**: rotacionar `AZURE_CLIENT_SECRET`, token GitHub e `ADMIN_PASSWORD` ← **ação manual sua**
- [ ] **V-004**: HTTPS via NGINX + Let's Encrypt; portas internas só no loopback ← **requer servidor**

### 🟠 Alta prioridade
- [x] **V-005**: rate limiting no `/v1/auth/login` (`slowapi`, 5/min)
- [x] **V-003**: backup restrito a admin + aviso LGPD + `.gitignore`
- [ ] **V-003**: (melhoria) criptografar arquivo de backup

### 🟡 Médio prazo
- [x] **V-006**: `bh_audit_logs` tamper-evident + auditoria em operações sensíveis + verificação de integridade
- [x] **V-007**: política de senha forte (8+, letra + número)
- [x] **V-008**: middleware de security headers (HSTS/CSP pendentes p/ produção)
- [x] **V-009**: CORS configurável via `CORS_ORIGINS`

### 🟢 Verificações de design (manter)
- [x] **V-010**: ORM parametrizado; nenhum `text()` com dado de usuário
- [x] **V-011**: sem `dangerouslySetInnerHTML`; entradas sanitizadas
- [x] **V-012**: `ativo == True` revalidado a cada request

---

## Conformidade

| Norma | Pontos atendidos | Pendências |
|:---|:---|:---|
| **LGPD** | Acesso autenticado e por perfil; aviso de confidencialidade no backup; **trilha de auditoria de acessos/alterações** | TLS (V-004); criptografia de backup |
| **ISO 27001/27002** | Controle de acesso, hash de senha, segregação de função, **logging/monitoramento (V-006)** | Gestão de segredos em produção (V-002) |
| **ISO 20000** | Migrations versionadas; backup/restore documentado; **trilha de auditoria de mudanças (não-repúdio)** | — |

---

## Histórico de Versões

| Versão | Data | Alteração |
|:-------|:-----|:----------|
| 1.0 | 2026-06-02 | Auditoria inicial do BH Pulse (FastAPI + React) — 12 itens mapeados; SQLi e XSS protegidos por design |
| 1.1 | 2026-06-02 | Corrigidos e validados: V-001 (JWT_SECRET), V-005 (rate limit), V-007 (política de senha), V-008 (security headers), V-009 (CORS configurável). Restam V-002 (rotação manual), V-004 (TLS) e V-006 (audit log) |
| 1.2 | 2026-06-02 | V-006 implementado: audit log tamper-evident (hash encadeado) com verificação de integridade — não-repúdio. Restam apenas V-002 (manual) e V-004 (servidor) |

---

*Recomenda-se complementar com teste de penetração (OWASP ZAP / nuclei) e revisão de dependências
(`pip-audit`, `npm audit`) antes do go-live.*
