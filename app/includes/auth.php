<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime',  '3600');
    // cookie_secure ativado apenas quando HTTPS está presente
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// Headers de segurança HTTP
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

// Suprimir erros na saída em produção
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function currentUser(): ?array { return $_SESSION['user'] ?? null; }

function requireLogin(): array {
    $u = currentUser();
    if (!$u) { header('Location: /index.php'); exit; }
    if ($u['must_change_pass'] && strpos($_SERVER['REQUEST_URI'], 'change-password') === false) {
        header('Location: /change-password.php'); exit;
    }
    return $u;
}

function requireAdmin(): array {
    $u = requireLogin();
    if ($u['role'] !== 'admin') { header('Location: /dashboard.php'); exit; }
    return $u;
}

function apiLogin(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $u = $_SESSION['user'] ?? null;
    if (!$u) { http_response_code(401); echo json_encode(['error' => 'Não autenticado']); exit; }
    return $u;
}

function apiAdmin(): array {
    $u = apiLogin();
    if ($u['role'] !== 'admin') { http_response_code(403); echo json_encode(['error' => 'Acesso negado']); exit; }
    return $u;
}
