<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$admin  = apiAdmin();
$body   = jsonBody();
$action = $body['action'] ?? '';

if ($action === 'create') {
    $nome = trim($body['nome'] ?? '');
    if (!$nome) jsonOut(['error' => 'Nome é obrigatório.'], 422);
    if (strlen($nome) > 100) jsonOut(['error' => 'Nome muito longo (máx. 100 caracteres).'], 422);

    $db = getDb();
    $stmt = $db->prepare("SELECT id FROM setores WHERE LOWER(nome) = LOWER(?)");
    $stmt->execute([$nome]);
    if ($stmt->fetch()) jsonOut(['error' => 'Já existe um setor com esse nome.'], 409);

    $db->prepare("INSERT INTO setores (nome) VALUES (?)")->execute([$nome]);
    $id = (int)$db->lastInsertId('setores_id_seq');
    jsonOut(['id' => $id, 'nome' => $nome]);
}

if ($action === 'update') {
    $id   = (int)($body['id']   ?? 0);
    $nome = trim($body['nome']  ?? '');

    if (!$id)   jsonOut(['error' => 'ID inválido.'], 422);
    if (!$nome) jsonOut(['error' => 'Nome é obrigatório.'], 422);

    $db = getDb();
    $stmt = $db->prepare("SELECT id FROM setores WHERE LOWER(nome) = LOWER(?) AND id != ?");
    $stmt->execute([$nome, $id]);
    if ($stmt->fetch()) jsonOut(['error' => 'Já existe um setor com esse nome.'], 409);

    $db->prepare("UPDATE setores SET nome = ? WHERE id = ?")->execute([$nome, $id]);
    jsonOut(['ok' => true]);
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) jsonOut(['error' => 'ID inválido.'], 422);

    $db = getDb();
    $stmt = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE setor_id = ?");
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        jsonOut(['error' => 'Não é possível excluir: existem colaboradores neste setor.'], 409);
    }

    $db->prepare("DELETE FROM setores WHERE id = ?")->execute([$id]);
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Ação inválida.'], 400);
