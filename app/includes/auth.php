<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime',  '3600');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ── Leitura do usuário da sessão ─────────────────────────────────────────────

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

// ── Verificação de perfil ────────────────────────────────────────────────────

function hasRole(string $role): bool {
    $u = currentUser();
    if (!$u) return false;
    return in_array($role, $u['roles'] ?? [], true);
}

function isAdmin(): bool {
    return hasRole('administrador');
}

function isCoordenador(): bool {
    return hasRole('coordenador') || isAdmin();
}

function isAnalista(): bool {
    return hasRole('analista') || isCoordenador();
}

// ── Guards de página ─────────────────────────────────────────────────────────

function requireLogin(): array {
    $u = currentUser();
    if (!$u) { header('Location: /index.php'); exit; }
    if ($u['must_change_pass'] && strpos($_SERVER['REQUEST_URI'], 'change-password') === false) {
        header('Location: /change-password.php'); exit;
    }
    // Atualiza perfis/setor da sessão a partir do banco (detecta mudanças de perfil sem re-login)
    if (function_exists('getDb')) {
        try {
            $db   = getDb();
            $stmt = $db->prepare("
                SELECT u.must_change_pass, s.nome AS setor_nome,
                       COALESCE(ARRAY_AGG(p.nome ORDER BY p.nome) FILTER (WHERE p.nome IS NOT NULL), '{}') AS roles_arr
                FROM usuarios u
                LEFT JOIN setores s ON s.id = u.setor_id
                LEFT JOIN usuario_perfis up ON up.usuario_id = u.id
                LEFT JOIN perfis p ON p.id = up.perfil_id
                WHERE u.id = ? AND u.status = 'ativo'
                GROUP BY u.id, s.nome
            ");
            $stmt->execute([$u['id']]);
            $row = $stmt->fetch();
            if (!$row) { session_destroy(); header('Location: /index.php'); exit; }
            $raw   = $row['roles_arr'] ?? '{}';
            $clean = trim(is_string($raw) ? $raw : '{}', '{}');
            $_SESSION['user']['roles']            = $clean !== '' ? array_map('trim', explode(',', $clean)) : [];
            $_SESSION['user']['setor_nome']       = $row['setor_nome'];
            $_SESSION['user']['must_change_pass'] = (bool)$row['must_change_pass'];
        } catch (\Throwable $e) { /* ignora erros de DB para não quebrar a página */ }
    }
    return $_SESSION['user'];
}

function requireAdmin(): array {
    $u = requireLogin();
    if (!isAdmin()) { header('Location: /dashboard.php'); exit; }
    return $u;
}

function requireCoordenador(): array {
    $u = requireLogin();
    if (!isCoordenador()) { header('Location: /dashboard.php'); exit; }
    return $u;
}

// ── Guards de API ────────────────────────────────────────────────────────────

function apiLogin(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $u = $_SESSION['user'] ?? null;
    if (!$u) { http_response_code(401); echo json_encode(['error' => 'Não autenticado']); exit; }
    return $u;
}

function apiAdmin(): array {
    $u = apiLogin();
    if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Acesso negado']); exit; }
    return $u;
}

function apiCoordenador(): array {
    $u = apiLogin();
    if (!isCoordenador()) { http_response_code(403); echo json_encode(['error' => 'Acesso negado']); exit; }
    return $u;
}

// ── Login (atualiza a sessão) ────────────────────────────────────────────────

function loginUser(array $row, array $roles): void {
    $_SESSION['user'] = [
        'id'              => (int)$row['id'],
        'nome'            => $row['nome'],
        'email'           => $row['email'],
        'roles'           => $roles,
        'setor_id'        => $row['setor_id'] ? (int)$row['setor_id'] : null,
        'setor_nome'      => $row['setor_nome'] ?? null,
        'must_change_pass'=> (bool)$row['must_change_pass'],
    ];
}

// ── Redireciona após login conforme perfil ────────────────────────────────────

function homeUrl(): string {
    if (isAdmin() || isCoordenador()) return '/admin.php';
    return '/dashboard.php';
}
