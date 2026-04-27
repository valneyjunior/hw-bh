<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$body   = jsonBody();
$action = $body['action'] ?? '';

// ── Criar solicitação (colaborador) ─────────────────────────────────────────
if ($action === 'create') {
    $user        = apiLogin();
    $requestDate = trim($body['request_date'] ?? '');
    $periodType  = trim($body['period_type']  ?? '');
    $customStart = trim($body['custom_start'] ?? '');
    $customEnd   = trim($body['custom_end']   ?? '');
    $reason      = trim($body['reason']       ?? '');

    $requestDateEnd = trim($body['request_date_end'] ?? '');

    $validPeriods = ['full', 'half_morning', 'half_afternoon', 'custom'];

    if (!$requestDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestDate))
        jsonOut(['error' => 'Data inválida.'], 422);
    if (!in_array($periodType, $validPeriods))
        jsonOut(['error' => 'Tipo de período inválido.'], 422);
    if (!$reason)
        jsonOut(['error' => 'O motivo é obrigatório para concluir a solicitação.'], 422);

    $db = getDb();

    // Horário comercial do colaborador
    $stmt = $db->prepare("SELECT work_start, work_end FROM collaborator_salary WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    $schedule = $stmt->fetch();
    $ws = $schedule ? substr($schedule['work_start'], 0, 5) : '08:00';
    $we = $schedule ? substr($schedule['work_end'],   0, 5) : '18:00';

    $wsM           = (int)substr($ws, 0, 2) * 60 + (int)substr($ws, 3, 2);
    $weM           = (int)substr($we, 0, 2) * 60 + (int)substr($we, 3, 2);
    $totalWorkMins = $weM - $wsM - 120; // desconta 2h de almoço
    $halfMins      = (int)floor($totalWorkMins / 2);

    if ($periodType === 'full') {
        $mins = $totalWorkMins;
    } elseif ($periodType === 'half_morning') {
        $mins = $halfMins;
    } elseif ($periodType === 'half_afternoon') {
        $mins = $totalWorkMins - $halfMins;
    } else {
        if (!$customStart || !$customEnd ||
            !preg_match('/^\d{2}:\d{2}$/', $customStart) ||
            !preg_match('/^\d{2}:\d{2}$/', $customEnd))
            jsonOut(['error' => 'Horários personalizados inválidos.'], 422);
        $csM = (int)substr($customStart, 0, 2) * 60 + (int)substr($customStart, 3, 2);
        $ceM = (int)substr($customEnd,   0, 2) * 60 + (int)substr($customEnd,   3, 2);
        if ($ceM <= $csM) jsonOut(['error' => 'Horário de início deve ser anterior ao término.'], 422);
        $minsPerDay = $ceM - $csM;

        // Multi-day: multiply by number of days in range
        $days = 1;
        if ($requestDateEnd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestDateEnd) && $requestDateEnd > $requestDate) {
            $start = new DateTime($requestDate);
            $end   = new DateTime($requestDateEnd);
            $days  = (int)$start->diff($end)->days + 1;
        } else {
            $requestDateEnd = null;
        }
        $mins = $minsPerDay * $days;
    }

    if ($mins <= 0) jsonOut(['error' => 'Tempo calculado inválido.'], 422);

    // Verificar saldo disponível
    $uid = $user['id'];

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

    $balance = $totalValMins - $deducted;
    if ($mins > $balance) jsonOut(['error' => 'Saldo insuficiente. Disponível: ' . minutesToHHMM($balance) . '.'], 422);

    $id = generateId();
    $db->prepare("INSERT INTO bh_requests (id,user_id,requested_minutes,request_date,request_date_end,period_type,reason) VALUES (?,?,?,?,?,?,?)")
       ->execute([$id, $uid, $mins, $requestDate, $requestDateEnd ?: null, $periodType, $reason]);

    jsonOut(['id' => $id]);
}

// ── Revisar solicitação (admin) ──────────────────────────────────────────────
if ($action === 'review') {
    $admin  = apiAdmin();
    $id     = $body['id']          ?? '';
    $status = $body['status']      ?? '';
    $note   = trim($body['review_note'] ?? '');

    if (!$id) jsonOut(['error' => 'ID inválido.'], 422);
    if (!in_array($status, ['approved','rejected'])) jsonOut(['error' => 'Status inválido.'], 422);

    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM bh_requests WHERE id = ?");
    $stmt->execute([$id]);
    $req = $stmt->fetch();
    if (!$req) jsonOut(['error' => 'Solicitação não encontrada.'], 404);
    if ($req['status'] !== 'pending') jsonOut(['error' => 'Solicitação já foi processada.'], 409);

    $db->prepare("UPDATE bh_requests SET status=?, reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?")
       ->execute([$status, $admin['id'], $note ?: null, $id]);

    jsonOut(['ok' => true, 'status' => $status]);
}

// ── Dedução administrativa (admin) ───────────────────────────────────────────
if ($action === 'admin_deduct') {
    $admin  = apiAdmin();
    $userId = trim($body['user_id'] ?? '');
    $mins   = (int)($body['minutes'] ?? 0);
    $date   = trim($body['date']    ?? '');
    $reason = trim($body['reason']  ?? '');

    if (!$userId) jsonOut(['error' => 'Colaborador não selecionado.'], 422);
    if ($mins <= 0) jsonOut(['error' => 'Informe o tempo a deduzir.'], 422);
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonOut(['error' => 'Data inválida.'], 422);
    if (!$reason) jsonOut(['error' => 'O motivo é obrigatório.'], 422);

    $db = getDb();

    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'collaborator' AND active = 1");
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) jsonOut(['error' => 'Colaborador não encontrado.'], 404);

    $id = generateId();
    $db->prepare("
        INSERT INTO bh_requests
            (id, user_id, requested_minutes, request_date, period_type, reason, status, reviewed_by, reviewed_at)
        VALUES (?,?,?,?,'admin_deduction',?,'approved',?,NOW())
    ")->execute([$id, $userId, $mins, $date, $reason, $admin['id']]);

    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Ação inválida.'], 400);
