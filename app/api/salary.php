<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$admin  = apiAdmin();
$body   = jsonBody();

$userId = $body['user_id']        ?? '';
$salary = $body['monthly_salary'] ?? null;
$ws     = $body['work_start']     ?? null;
$we     = $body['work_end']       ?? null;

if (!$userId) jsonOut(['error' => 'user_id obrigatório.'], 422);
if ($salary === null || !is_numeric($salary) || $salary < 0)
    jsonOut(['error' => 'Salário inválido.'], 422);

// Validar horários se fornecidos
if ($ws !== null || $we !== null) {
    if (!preg_match('/^\d{2}:\d{2}$/', $ws ?? '') || !preg_match('/^\d{2}:\d{2}$/', $we ?? ''))
        jsonOut(['error' => 'Horário comercial inválido.'], 422);
    if (strtotime($ws) >= strtotime($we))
        jsonOut(['error' => 'Horário de início deve ser anterior ao término.'], 422);
}

$db = getDb();

$stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'collaborator'");
$stmt->execute([$userId]);
if (!$stmt->fetch()) jsonOut(['error' => 'Colaborador não encontrado.'], 404);

$workStart = $ws ?? '08:00';
$workEnd   = $we ?? '18:00';

$db->prepare("INSERT INTO collaborator_salary (user_id, monthly_salary, work_start, work_end)
              VALUES (?,?,?,?)
              ON DUPLICATE KEY UPDATE monthly_salary=?, work_start=?, work_end=?, updated_at=NOW()")
   ->execute([$userId, $salary, $workStart, $workEnd, $salary, $workStart, $workEnd]);

jsonOut(['ok' => true]);
