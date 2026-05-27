<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$user   = apiLogin();
$body   = jsonBody();
$action = $body['action'] ?? '';

// ── Marcar disponibilidade ────────────────────────────────────────────────────
if ($action === 'save') {
    $data       = trim($body['data']       ?? '');
    $turno      = trim($body['turno']      ?? '');
    $observacao = trim($body['observacao'] ?? '');

    if (!$data || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data))
        jsonOut(['error' => 'Data inválida.'], 422);

    $validTurnos = ['manha','tarde','noite','dia_todo'];
    if (!in_array($turno, $validTurnos))
        jsonOut(['error' => 'Turno inválido.'], 422);

    if ($data < date('Y-m-d'))
        jsonOut(['error' => 'Não é possível marcar disponibilidade em datas passadas.'], 422);

    $db = getDb();
    $db->prepare("
        INSERT INTO escala_voluntaria (usuario_id, data_disponivel, turno, observacao)
        VALUES (?, ?, ?, ?)
        ON CONFLICT (usuario_id, data_disponivel) DO UPDATE
            SET turno = EXCLUDED.turno,
                observacao = EXCLUDED.observacao
    ")->execute([$user['id'], $data, $turno, $observacao ?: null]);

    jsonOut(['ok' => true]);
}

// ── Remover disponibilidade ───────────────────────────────────────────────────
if ($action === 'remove') {
    $data = trim($body['data'] ?? '');

    if (!$data || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data))
        jsonOut(['error' => 'Data inválida.'], 422);

    $db = getDb();
    $db->prepare("DELETE FROM escala_voluntaria WHERE usuario_id = ? AND data_disponivel = ?")
       ->execute([$user['id'], $data]);

    jsonOut(['ok' => true]);
}

// ── Listar disponibilidades do usuário ────────────────────────────────────────
if ($action === 'list') {
    $from = trim($body['from'] ?? date('Y-m-01'));
    $to   = trim($body['to']   ?? date('Y-m-t', strtotime('+1 month')));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))
        jsonOut(['error' => 'Datas inválidas.'], 422);

    $db   = getDb();
    $stmt = $db->prepare("
        SELECT data_disponivel::text AS data, turno, observacao
        FROM escala_voluntaria
        WHERE usuario_id = ? AND data_disponivel BETWEEN ? AND ?
        ORDER BY data_disponivel
    ");
    $stmt->execute([$user['id'], $from, $to]);

    jsonOut(['data' => $stmt->fetchAll()]);
}

jsonOut(['error' => 'Ação inválida.'], 400);
