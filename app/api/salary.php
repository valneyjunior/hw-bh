<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$admin = apiAdmin();
$body  = jsonBody();

$userId     = (int)($body['user_id']           ?? 0);
$salario    = $body['salario_bruto']            ?? null;
$adicional  = !empty($body['adicional_atrativo']);
$adicionalV = (float)($body['adicional_valor'] ?? 0);
$ws         = trim($body['work_start']          ?? '');
$we         = trim($body['work_end']            ?? '');

if (!$userId) jsonOut(['error' => 'user_id obrigatório.'], 422);
if ($salario === null || !is_numeric($salario) || (float)$salario < 0)
    jsonOut(['error' => 'Salário inválido.'], 422);
if ($adicional && $adicionalV <= 0)
    jsonOut(['error' => 'Valor do adicional deve ser maior que zero.'], 422);

if ($ws && $we) {
    if (!preg_match('/^\d{2}:\d{2}$/', $ws) || !preg_match('/^\d{2}:\d{2}$/', $we))
        jsonOut(['error' => 'Horário comercial inválido.'], 422);
}

$db   = getDb();
$stmt = $db->prepare("SELECT id FROM usuarios WHERE id = ?");
$stmt->execute([$userId]);
if (!$stmt->fetch()) jsonOut(['error' => 'Usuário não encontrado.'], 404);

$workStart    = $ws ?: '08:00';
$workEnd      = $we ?: '18:00';
$salarioBruto = (float)$salario;
$adiValor     = $adicional ? $adicionalV : 0.0;

$db->prepare("
    UPDATE usuarios SET
        salario_bruto = ?,
        adicional_atrativo = ?,
        adicional_valor = ?,
        work_start = ?,
        work_end = ?
    WHERE id = ?
")->execute([$salarioBruto, $adicional ? 'true' : 'false', $adiValor, $workStart, $workEnd, $userId]);

jsonOut(['ok' => true]);
