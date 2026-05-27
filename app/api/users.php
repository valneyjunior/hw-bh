<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$caller = apiCoordenador();
$body   = jsonBody();
$action = $body['action'] ?? '';

$callerIsAdmin      = isAdmin();
$callerSetorId      = $callerIsAdmin ? null : (int)$caller['setor_id'];

// ── Criar usuário ─────────────────────────────────────────────────────────────
if ($action === 'create') {
    $nome    = trim($body['nome']   ?? '');
    $email   = strtolower(trim($body['email'] ?? ''));
    $setorId = (int)($body['setor_id'] ?? 0) ?: null;
    $perfis  = $body['perfis'] ?? ['analista'];
    $salario = (float)($body['salario_bruto'] ?? 0);
    $adicional = !empty($body['adicional_atrativo']);
    $adiValor  = (float)($body['adicional_valor'] ?? 0);
    $ws         = trim($body['work_start']    ?? '08:00');
    $we         = trim($body['work_end']      ?? '18:00');
    $lunchStart = trim($body['lunch_start']   ?? '12:00');
    $lunchMins  = (int)($body['lunch_minutes'] ?? 60);
    if (!in_array($lunchMins, [30, 60, 90, 120])) $lunchMins = 60;

    if (!$nome)  jsonOut(['error' => 'Nome é obrigatório.'], 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(['error' => 'E-mail inválido.'], 422);

    // Coordenador só pode criar no seu setor e sem perfil admin
    if (!$callerIsAdmin) {
        $setorId = $callerSetorId;
        $perfis  = array_filter((array)$perfis, fn($p) => $p !== 'administrador');
    }

    $validPerfis = ['analista','coordenador','administrador'];
    $perfis = array_values(array_filter($perfis, fn($p) => in_array($p, $validPerfis)));
    if (empty($perfis)) $perfis = ['analista'];

    $db   = getDb();
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) jsonOut(['error' => 'E-mail já cadastrado.'], 409);

    $pass = generateToken(6); // 12-char hex temp password
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);

    $db->prepare("
        INSERT INTO usuarios (nome, email, senha_hash, status, setor_id, salario_bruto,
                              adicional_atrativo, adicional_valor, work_start, work_end,
                              lunch_start, lunch_minutes, must_change_pass)
        VALUES (?, ?, ?, 'ativo', ?, ?, ?, ?, ?, ?, ?, ?, TRUE)
    ")->execute([$nome, $email, $hash, $setorId, $salario,
                 $adicional ? 'true' : 'false', $adiValor, $ws, $we,
                 $lunchStart, $lunchMins]);

    $newId = (int)$db->lastInsertId('usuarios_id_seq');

    // Assign roles
    foreach ($perfis as $perfilNome) {
        $pid = $db->prepare("SELECT id FROM perfis WHERE nome = ?")->execute([$perfilNome])
               ? $db->query("SELECT id FROM perfis WHERE nome = '$perfilNome'")->fetchColumn()
               : null;
        if ($pid) {
            $db->prepare("INSERT INTO usuario_perfis (usuario_id, perfil_id) VALUES (?,?) ON CONFLICT DO NOTHING")
               ->execute([$newId, $pid]);
        }
    }

    jsonOut(['id' => $newId, 'nome' => $nome, 'temp_password' => $pass]);
}

// ── Atualizar usuário ─────────────────────────────────────────────────────────
if ($action === 'update') {
    $id      = (int)($body['id']    ?? 0);
    $nome    = trim($body['nome']   ?? '');
    $email   = strtolower(trim($body['email'] ?? ''));
    $setorId = (int)($body['setor_id'] ?? 0) ?: null;
    $perfis  = $body['perfis'] ?? [];
    $salario = (float)($body['salario_bruto'] ?? 0);
    $adicional = !empty($body['adicional_atrativo']);
    $adiValor  = (float)($body['adicional_valor'] ?? 0);
    $ws         = trim($body['work_start']    ?? '');
    $we         = trim($body['work_end']      ?? '');
    $lunchStart = trim($body['lunch_start']   ?? '12:00');
    $lunchMins  = (int)($body['lunch_minutes'] ?? 60);
    if (!in_array($lunchMins, [30, 60, 90, 120])) $lunchMins = 60;

    if (!$id)   jsonOut(['error' => 'ID inválido.'], 422);
    if (!$nome) jsonOut(['error' => 'Nome é obrigatório.'], 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(['error' => 'E-mail inválido.'], 422);

    // Coordenador só pode editar usuários do seu setor e não pode promover a admin
    if (!$callerIsAdmin) {
        $setorId = $callerSetorId;
        $perfis  = array_filter((array)$perfis, fn($p) => $p !== 'administrador');
    }

    $validPerfis = ['analista','coordenador','administrador'];
    $perfis = array_values(array_filter($perfis, fn($p) => in_array($p, $validPerfis)));
    if (empty($perfis)) $perfis = ['analista'];

    $db   = getDb();
    $stmt = $db->prepare("SELECT id, setor_id FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $targetUser = $stmt->fetch();
    if (!$targetUser) jsonOut(['error' => 'Usuário não encontrado.'], 404);

    // Garantia extra: coordenador não pode editar usuário de outro setor
    if (!$callerIsAdmin && (int)$targetUser['setor_id'] !== $callerSetorId) {
        jsonOut(['error' => 'Acesso negado — usuário de outro setor.'], 403);
    }

    $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
    $stmt->execute([$email, $id]);
    if ($stmt->fetch()) jsonOut(['error' => 'E-mail já cadastrado por outro usuário.'], 409);

    $db->prepare("
        UPDATE usuarios SET nome=?, email=?, setor_id=?, salario_bruto=?,
                            adicional_atrativo=?, adicional_valor=?,
                            work_start=?, work_end=?,
                            lunch_start=?, lunch_minutes=?
        WHERE id=?
    ")->execute([$nome, $email, $setorId, $salario,
                 $adicional ? 'true' : 'false', $adiValor,
                 $ws ?: '08:00', $we ?: '18:00',
                 $lunchStart ?: '12:00', $lunchMins, $id]);

    // Resync roles
    $db->prepare("DELETE FROM usuario_perfis WHERE usuario_id = ?")->execute([$id]);
    foreach ($perfis as $perfilNome) {
        $pid = $db->query("SELECT id FROM perfis WHERE nome = " . $db->quote($perfilNome))->fetchColumn();
        if ($pid) {
            $db->prepare("INSERT INTO usuario_perfis (usuario_id, perfil_id) VALUES (?,?)")
               ->execute([$id, $pid]);
        }
    }

    jsonOut(['ok' => true]);
}

// ── Reset de senha ────────────────────────────────────────────────────────────
if ($action === 'reset_password') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) jsonOut(['error' => 'ID inválido.'], 422);

    $db   = getDb();
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonOut(['error' => 'Usuário não encontrado.'], 404);

    $pass = generateToken(6);
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE usuarios SET senha_hash=?, must_change_pass=TRUE WHERE id=?")->execute([$hash, $id]);

    jsonOut(['temp_password' => $pass]);
}

// ── Alterar status ────────────────────────────────────────────────────────────
if ($action === 'change_status') {
    if (!$callerIsAdmin) jsonOut(['error' => 'Acesso negado.'], 403);

    $id        = (int)($body['id']     ?? 0);
    $newStatus = trim($body['status']  ?? '');

    if (!$id) jsonOut(['error' => 'ID inválido.'], 422);
    if (!in_array($newStatus, ['ativo','inativo','ex-colaborador'])) jsonOut(['error' => 'Status inválido.'], 422);
    if ($id === $caller['id']) jsonOut(['error' => 'Não é possível alterar o status da sua própria conta.'], 403);

    $db   = getDb();
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonOut(['error' => 'Usuário não encontrado.'], 404);

    $db->prepare("UPDATE usuarios SET status=? WHERE id=?")->execute([$newStatus, $id]);
    jsonOut(['ok' => true, 'status' => $newStatus]);
}

// ── Excluir permanentemente (somente ex-colaborador) ─────────────────────────
if ($action === 'delete_permanent') {
    if (!$callerIsAdmin) jsonOut(['error' => 'Acesso negado.'], 403);

    $id = (int)($body['id'] ?? 0);
    if (!$id) jsonOut(['error' => 'ID inválido.'], 422);
    if ($id === $caller['id']) jsonOut(['error' => 'Não é possível excluir a própria conta.'], 403);

    $db   = getDb();
    $stmt = $db->prepare("SELECT id, status FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) jsonOut(['error' => 'Usuário não encontrado.'], 404);
    if ($u['status'] !== 'ex-colaborador') jsonOut(['error' => 'Apenas ex-colaboradores podem ser excluídos permanentemente.'], 409);

    $db->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Ação inválida.'], 400);
