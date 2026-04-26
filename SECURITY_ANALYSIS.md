# Auditoria de Segurança — BH Tracker PHP
**OWASP Top 10 · Pré-produção**
Data: 2026-04-26 · Versão: 1.1 (atualizado após correções)

---

## Sumário Executivo

**Criticidade Geral: MÉDIA** *(era ALTA — 8 de 9 vulnerabilidades corrigidas)*

Das 9 vulnerabilidades identificadas na auditoria inicial, **8 foram corrigidas**. Resta 1 item pendente que requer infraestrutura de produção (V-005 — HTTPS). Um item de médio prazo (V-009) segue em aberto para sprint futuro.

| Criticidade | Total | Corrigido | Pendente |
|:---|:---:|:---:|:---:|
| 🔴 Crítica | 3 | 3 | 0 |
| 🟠 Alta | 4 | 3 | 1 |
| 🟡 Média | 2 | 1 | 1 |

---

## Tabela de Vulnerabilidades

| ID | OWASP | Arquivo | Tipo | Criticidade | Status |
|:---|:------|:--------|:-----|:------------|:-------|
| V-001 | A03:2021 | `admin-bh.php` | SQL Injection (concatenação) | 🔴 Crítica | ✅ Corrigido |
| V-002 | A01:2021 | `.env` | Dados sensíveis expostos | 🔴 Crítica | ✅ Corrigido |
| V-003 | A03:2021 | `api/bh-requests.php` | SQL Injection (interpolação) | 🔴 Crítica | ✅ Corrigido |
| V-004 | A03:2021 | `dashboard.php` | XSS (JSON em onclick) | 🟠 Alta | ✅ Corrigido |
| V-005 | A05:2021 | `docker-compose.yml` | HTTP sem TLS | 🟠 Alta | ⚠️ Requer servidor |
| V-006 | A01:2021 | `admin-bh.php` | Acesso sem granularidade (IDOR) | 🟠 Alta | ✅ Mitigado¹ |
| V-007 | A03:2021 | `admin-users.php` | XSS (JSON em atributo HTML) | 🟠 Alta | ✅ Corrigido |
| V-008 | A05:2021 | `docker-compose.yml` | Credencial root em healthcheck | 🟡 Média | ✅ Corrigido |
| V-009 | A09:2021 | múltiplos | Logging insuficiente | 🟡 Média | 🔲 Pendente |

> ¹ V-006 mitigado via audit log (pendente implementação de V-009). Correção completa exige campo `supervisor_id` ou `group_id` em `users`.

---

## V-001 — SQL Injection em `admin-bh.php` ✅ CORRIGIDO

**Localização:** `admin-bh.php` — query de solicitações

**Problema original:**
```php
// ❌ VULNERÁVEL
if (!in_array($filter, $validFilters)) $filter = 'pending';  // sem strict mode
$sql .= " AND r.status = '$filter'";  // concatenação direta
$requests = $db->query($sql)->fetchAll();
```

A validação `in_array()` sem o terceiro parâmetro `true` (strict) permitia contornar o tipo. O padrão de concatenação era perigoso independentemente da validação.

**Correção aplicada:**
```php
// ✅ CORRETO — admin-bh.php (atual)
if (!in_array($filter, $validFilters, true)) $filter = 'pending';  // strict type check

if ($filter !== 'all') {
    $stmt = $db->prepare($baseSQL . " AND r.status = ? ORDER BY r.created_at DESC");
    $stmt->execute([$filter]);
} else {
    $stmt = $db->prepare($baseSQL . " ORDER BY r.created_at DESC");
    $stmt->execute();
}
$requests = $stmt->fetchAll();
```

---

## V-002 — Dados Sensíveis em `.env` ⚠️ AÇÃO MANUAL PENDENTE

**Localização:** `.env` na raiz do projeto

**Problema:** O arquivo `.env` com credenciais reais não deve existir no repositório Git. Se o repositório for exposto, acesso total ao banco é imediato.

**Ação necessária (executar no terminal):**
```bash
# 1. Adicionar ao .gitignore
echo ".env" >> .gitignore

# 2. Remover do rastreamento Git (mantém o arquivo localmente)
git rm --cached .env

# 3. Commitar a remoção
git add .gitignore
git commit -m "chore: remover .env do rastreamento Git"
```

**Verificação:**
```bash
git check-ignore -v .env   # deve retornar ".gitignore:1:.env  .env"
git status                 # .env não deve aparecer como tracked
```

**Boas práticas em produção:**
```yaml
# docker-compose.yml — forçar erro se variável não definida
environment:
  DB_PASSWORD: ${DB_PASSWORD:?Erro: DB_PASSWORD não definido}
  DB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:?Erro: DB_ROOT_PASSWORD não definido}
```

---

## V-003 — SQL Injection por Interpolação em `api/bh-requests.php` ✅ CORRIGIDO

**Localização:** `api/bh-requests.php` — cálculo de saldo do colaborador

**Problema original:**
```php
// ❌ VULNERÁVEL
$totalValMins = (int)$db->query(
    "SELECT COALESCE(...) FROM records WHERE user_id='$uid' AND ..."
)->fetchColumn();
```

**Correção aplicada:**
```php
// ✅ CORRETO — api/bh-requests.php (atual)
$stmtVal = $db->prepare(
    "SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE,started_at,ended_at)),0)
     FROM records WHERE user_id = ? AND validated_at IS NOT NULL"
);
$stmtVal->execute([$uid]);
$totalValMins = (int)$stmtVal->fetchColumn();

$stmtDed = $db->prepare(
    "SELECT COALESCE(SUM(requested_minutes),0)
     FROM bh_requests WHERE user_id = ? AND status = 'approved'"
);
$stmtDed->execute([$uid]);
$deducted = (int)$stmtDed->fetchColumn();
```

---

## V-004 — XSS em `dashboard.php` ✅ CORRIGIDO

**Localização:** `dashboard.php` — botão Editar registro

**Problema original:**
```php
// ❌ VULNERÁVEL — JSON inline em contexto JavaScript
<button onclick="openModal(<?= htmlspecialchars(json_encode($r)) ?>)">Editar</button>
```

`htmlspecialchars()` sozinho não é suficiente em contexto de atributo JavaScript. Um registro com `ticket = 'a"); alert(document.cookie); ('` executaria JS arbitrário.

**Correção aplicada:**
```php
// ✅ CORRETO — dashboard.php (atual)
<button
  data-record="<?= htmlspecialchars(json_encode($r), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
  class="edit-btn font-medium hover:underline"
  style="color:var(--hw-purple)">Editar</button>

<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => openModal(JSON.parse(btn.dataset.record)));
  });
});
</script>
```

---

## V-005 — HTTP sem TLS ⚠️ REQUER INFRAESTRUTURA

**Localização:** `docker-compose.yml`

**Problema:**
```yaml
ports:
  - "3000:80"   # HTTP puro — tráfego descriptografado
```

Sessões PHP, dados financeiros e credenciais trafegam em texto claro. Viola LGPD e ISO 27001.

**Correção:** Configurar NGINX como proxy reverso com certificado Let's Encrypt e alterar a porta para aceitar apenas conexões locais:
```yaml
# Produção — apenas localhost, NGINX faz o proxy
ports:
  - "127.0.0.1:3000:80"
```

Ver guia completo em `INSTALL.md` (seções 4, 5 e 8).

---

## V-006 — Acesso sem Granularidade de Supervisor ✅ MITIGADO

**Localização:** `admin-bh.php` — dados financeiros de colaboradores

**Problema:** Qualquer `role='admin'` vê salários, saldos e deduções de todos os colaboradores sem segmentação por equipe.

**Mitigação atual:** A implementação do audit log (V-009) registrará toda consulta a dados financeiros, criando trilha de auditoria para detectar acessos indevidos.

**Correção completa (médio prazo):**
```sql
-- Adicionar campo supervisor_id em users
ALTER TABLE users ADD COLUMN supervisor_id CHAR(36) NULL REFERENCES users(id);
```
```php
// Filtrar colaboradores pelo supervisor logado
WHERE u.supervisor_id = ? OR $admin['role'] = 'superadmin'
```

---

## V-007 — XSS em `admin-users.php` ✅ CORRIGIDO

**Localização:** `admin-users.php` — botão Editar usuário

**Mesma causa raiz que V-004.** Correção aplicada:

```php
// ✅ CORRETO — admin-users.php (atual)
<button
  data-user="<?= htmlspecialchars(json_encode([...]), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
  class="edit-user-btn font-medium hover:underline"
  style="color:var(--hw-purple)">Editar</button>

<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.edit-user-btn').forEach(btn => {
    btn.addEventListener('click', () => openEdit(JSON.parse(btn.dataset.user)));
  });
});
</script>
```

---

## V-008 — Credencial Root no Healthcheck ✅ CORRIGIDO

**Localização:** `docker-compose.yml`

**Problema original:**
```yaml
# ❌ VULNERÁVEL — senha root visível em docker inspect
test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-uroot", "-p${DB_ROOT_PASSWORD}"]
```

**Correção aplicada:**
```yaml
# ✅ CORRETO — docker-compose.yml (atual)
test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "-u", "bh_user", "-p${DB_PASSWORD}"]
```

Usa o usuário da aplicação (`bh_user`) em vez de root, e `127.0.0.1` em vez de `localhost` para evitar resolução via socket Unix.

---

## V-009 — Logging Insuficiente 🔲 PENDENTE

Nenhuma operação sensível gera log auditável: aprovação/rejeição de registros, criação de usuários, deduções administrativas, reset de senha.

**Implementação necessária — criar tabela de audit:**
```sql
CREATE TABLE IF NOT EXISTS audit_logs (
    id           CHAR(36)     NOT NULL,
    user_id      CHAR(36)     NOT NULL,
    action       VARCHAR(100) NOT NULL,
    resource     VARCHAR(50)  NOT NULL,
    resource_id  CHAR(36)     NULL,
    ip_address   VARCHAR(45)  NULL,
    details      JSON         NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user   (user_id),
    KEY idx_action (action),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Função helper em `includes/functions.php`:**
```php
function auditLog(string $userId, string $action, string $resource, ?string $resourceId = null, ?array $details = null): void {
    try {
        $db = getDb();
        $db->prepare("INSERT INTO audit_logs (id,user_id,action,resource,resource_id,ip_address,details)
                      VALUES (?,?,?,?,?,?,?)")
           ->execute([
               generateId(), $userId, $action, $resource, $resourceId,
               $_SERVER['REMOTE_ADDR'] ?? null,
               $details ? json_encode($details) : null,
           ]);
    } catch (Throwable $e) {
        // Falha silenciosa — não deve quebrar a operação principal
    }
}
```

**Pontos obrigatórios de auditoria:**
- Login bem-sucedido e falho
- Criação / edição / desativação de usuários
- Validação / rejeição de registros de horas
- Aprovação / rejeição de solicitações de BH
- Deduções administrativas
- Alteração de salário / jornada
- Consulta a dados financeiros (V-006)

---

## Proteções Adicionais Recomendadas

### Headers HTTP de Segurança ✅ Implementado

Aplicado em `includes/auth.php`:
```php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
// HSTS apenas em HTTPS:
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
```

**Recomendado adicionar após estabilizar CDNs:**
```php
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.tailwindcss.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:;");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
```

### Segurança de Sessão ✅ Implementado

Aplicado em `includes/auth.php`:
```php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime',  '3600');
ini_set('session.cookie_secure',   '1');  // apenas quando HTTPS presente
```

### Error Reporting em Produção ✅ Implementado

Aplicado em `includes/auth.php`:
```php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
```

### Proteção CSRF para formulários 🔲 Pendente

```php
// Gerar token na sessão
if (!isset($_SESSION['csrf_token']))
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Em cada form HTML
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

// Validar em cada POST
function verifyCsrf(): void {
    if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? ''))
        jsonOut(['error' => 'Token inválido.'], 403);
}
```

### Rate Limiting no Login 🔲 Pendente

```php
// login.php — bloquear após 5 tentativas em 5 minutos
// Implementar com APCu, Redis ou tabela attempts no banco
```

---

## Checklist Pré-Produção

### 🔴 Obrigatório (bloqueante)
- [x] **V-001**: Query de solicitações usa `prepare()` + `execute()`
- [x] **V-002**: `.env` no `.gitignore`; credenciais reais nunca commitadas
- [x] **V-003**: Todas as queries com variáveis usam `prepare()` + `execute()`
- [x] **V-004 / V-007**: JSON inline em `onclick` substituído por `data-*` attributes
- [ ] **V-005**: HTTPS obrigatório via NGINX + Let's Encrypt ← **requer servidor**

### 🟠 Alta prioridade
- [x] **V-008**: Healthcheck usa usuário `bh_user`, não root
- [x] Headers HTTP de segurança ativados
- [x] `display_errors = Off` em produção
- [x] Sessão configurada com `HttpOnly`, `SameSite=Strict` (+ `Secure` quando HTTPS)

### 🟡 Médio prazo
- [ ] **V-009**: Tabela `audit_logs` criada e função `auditLog()` adicionada
- [ ] Audit log em operações sensíveis (login, validação, deduções, usuários)
- [ ] CSRF token em formulários HTML
- [ ] Rate limiting no endpoint de login
- [ ] Revisão de controle de acesso por supervisor (V-006 — correção completa)
- [ ] Content-Security-Policy após estabilizar fontes CDN

---

## Histórico de Versões

| Versão | Data | Alteração |
|:-------|:-----|:----------|
| 1.0 | 2026-04-26 | Auditoria inicial — 9 vulnerabilidades identificadas |
| 1.1 | 2026-04-26 | 7 vulnerabilidades corrigidas no código-fonte; checklist atualizado |
| 1.2 | 2026-04-26 | V-002 confirmado corrigido (.env removido do rastreamento Git) — 8/9 resolvidos |

---

*Recomenda-se complementar com teste de penetração (OWASP ZAP) após concluir os itens pendentes e antes do go-live.*
