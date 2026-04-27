<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$admin  = apiAdmin();
$body   = jsonBody();
$action = $body['action'] ?? '';

if ($action === 'create') {
    $name  = trim($body['name']  ?? '');
    $email = strtolower(trim($body['email'] ?? ''));
    $role  = $body['role'] ?? 'collaborator';

    if (!$name)                       jsonOut(['error' => 'Nome é obrigatório.'], 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(['error' => 'E-mail inválido.'], 422);
    if (!in_array($role, ['admin','collaborator'])) jsonOut(['error' => 'Perfil inválido.'], 422);

    $stmt = getDb()->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) jsonOut(['error' => 'E-mail já cadastrado.'], 409);

    $id   = generateId();
    $pass = generateTempPassword();
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);

    getDb()->prepare("INSERT INTO users (id, name, email, password_hash, role, must_change_pass) VALUES (?,?,?,?,?,1)")
           ->execute([$id, $name, $email, $hash, $role]);

    jsonOut(['id' => $id, 'name' => $name, 'temp_password' => $pass]);
}

if ($action === 'reset_password') {
    $id = $body['id'] ?? '';
    if (!$id) jsonOut(['error' => 'ID inválido.'], 422);

    $stmt = getDb()->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonOut(['error' => 'Usuário não encontrado.'], 404);

    $pass = generateTempPassword();
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    getDb()->prepare("UPDATE users SET password_hash = ?, must_change_pass = 1 WHERE id = ?")
           ->execute([$hash, $id]);

    jsonOut(['temp_password' => $pass]);
}

if ($action === 'toggle_active') {
    $id = $body['id'] ?? '';
    if (!$id) jsonOut(['error' => 'ID inválido.'], 422);
    if ($id === $admin['id']) jsonOut(['error' => 'Não é possível desativar sua própria conta.'], 403);

    $stmt = getDb()->prepare("SELECT active FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) jsonOut(['error' => 'Usuário não encontrado.'], 404);

    $newActive = $u['active'] ? 0 : 1;
    getDb()->prepare("UPDATE users SET active = ? WHERE id = ?")->execute([$newActive, $id]);
    jsonOut(['active' => (bool)$newActive]);
}

if ($action === 'update') {
    $id    = $body['id']    ?? '';
    $name  = trim($body['name']  ?? '');
    $email = strtolower(trim($body['email'] ?? ''));
    $role  = $body['role'] ?? '';

    if (!$id)   jsonOut(['error' => 'ID inválido.'], 422);
    if (!$name) jsonOut(['error' => 'Nome é obrigatório.'], 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(['error' => 'E-mail inválido.'], 422);
    if (!in_array($role, ['admin','collaborator'])) jsonOut(['error' => 'Perfil inválido.'], 422);

    $db = getDb();
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonOut(['error' => 'Usuário não encontrado.'], 404);

    // Verifica duplicidade de e-mail em outro usuário
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $id]);
    if ($stmt->fetch()) jsonOut(['error' => 'E-mail já cadastrado por outro usuário.'], 409);

    $db->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?")
       ->execute([$name, $email, $role, $id]);

    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Ação inválida.'], 400);
