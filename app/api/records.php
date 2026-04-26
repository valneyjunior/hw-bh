<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$user = apiLogin();
$body = jsonBody();
$action = $body['action'] ?? '';

if ($action === 'create') {
    $started = $body['started_at'] ?? '';
    $ended   = $body['ended_at']   ?? '';
    $ticket  = trim($body['ticket'] ?? '');
    $desc    = trim($body['description'] ?? '');

    if (!$started || !$ended)  jsonOut(['error' => 'Datas são obrigatórias.'], 422);
    if (!$ticket)              jsonOut(['error' => 'Chamado é obrigatório.'], 422);
    if (!$desc)                jsonOut(['error' => 'Descrição é obrigatória.'], 422);
    if ($ended <= $started)    jsonOut(['error' => 'A data de fim deve ser posterior ao início.'], 422);

    $id = generateId();
    getDb()->prepare("INSERT INTO records (id, user_id, started_at, ended_at, ticket, description) VALUES (?,?,?,?,?,?)")
           ->execute([$id, $user['id'], $started, $ended, $ticket, $desc]);

    jsonOut(['id' => $id]);
}

if ($action === 'update') {
    $id      = $body['id']         ?? '';
    $started = $body['started_at'] ?? '';
    $ended   = $body['ended_at']   ?? '';
    $ticket  = trim($body['ticket'] ?? '');
    $desc    = trim($body['description'] ?? '');

    if (!$id)                  jsonOut(['error' => 'ID inválido.'], 422);
    if (!$started || !$ended)  jsonOut(['error' => 'Datas são obrigatórias.'], 422);
    if (!$ticket)              jsonOut(['error' => 'Chamado é obrigatório.'], 422);
    if (!$desc)                jsonOut(['error' => 'Descrição é obrigatória.'], 422);
    if ($ended <= $started)    jsonOut(['error' => 'A data de fim deve ser posterior ao início.'], 422);

    // Collaborators can only edit own non-validated records
    $stmt = getDb()->prepare("SELECT * FROM records WHERE id = ?");
    $stmt->execute([$id]);
    $rec = $stmt->fetch();

    if (!$rec) jsonOut(['error' => 'Registro não encontrado.'], 404);
    if ($user['role'] !== 'admin' && $rec['user_id'] !== $user['id']) jsonOut(['error' => 'Acesso negado.'], 403);
    if ($user['role'] !== 'admin' && $rec['validated_at']) jsonOut(['error' => 'Registro já validado não pode ser editado.'], 403);

    getDb()->prepare("UPDATE records SET started_at=?, ended_at=?, ticket=?, description=? WHERE id=?")
           ->execute([$started, $ended, $ticket, $desc, $id]);

    jsonOut(['ok' => true]);
}

if ($action === 'delete') {
    $id = $body['id'] ?? '';
    if (!$id) jsonOut(['error' => 'ID inválido.'], 422);

    $stmt = getDb()->prepare("SELECT * FROM records WHERE id = ?");
    $stmt->execute([$id]);
    $rec = $stmt->fetch();

    if (!$rec) jsonOut(['error' => 'Registro não encontrado.'], 404);
    if ($user['role'] !== 'admin' && $rec['user_id'] !== $user['id']) jsonOut(['error' => 'Acesso negado.'], 403);
    if ($user['role'] !== 'admin' && $rec['validated_at']) jsonOut(['error' => 'Registro validado não pode ser excluído.'], 403);

    getDb()->prepare("DELETE FROM records WHERE id = ?")->execute([$id]);
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Ação inválida.'], 400);
