<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$body   = jsonBody();
$action = $body['action'] ?? '';

// ── Criar solicitação (analista) ─────────────────────────────────────────────
if ($action === 'create') {
    $user       = apiLogin();
    $dataInicio = trim($body['data_inicio']  ?? '');
    $dataFim    = trim($body['data_fim']     ?? '');
    $tipo       = trim($body['tipo']         ?? '');
    $customIni  = trim($body['hora_inicio']  ?? '');
    $customFim  = trim($body['hora_fim']     ?? '');
    $motivo     = trim($body['motivo']       ?? '');

    $validTipos = ['dia_inteiro','meio_periodo_manha','meio_periodo_tarde','personalizado'];

    if (!$dataInicio || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio))
        jsonOut(['error' => 'Data inválida.'], 422);
    if (!in_array($tipo, $validTipos))
        jsonOut(['error' => 'Tipo de período inválido.'], 422);
    if (!$motivo)
        jsonOut(['error' => 'O motivo é obrigatório.'], 422);

    $db = getDb();

    // Work schedule from the user record
    $stmt = $db->prepare("SELECT work_start, work_end FROM usuarios WHERE id = ?");
    $stmt->execute([$user['id']]);
    $schedule = $stmt->fetch();
    $ws = $schedule ? substr($schedule['work_start'], 0, 5) : '08:00';
    $we = $schedule ? substr($schedule['work_end'],   0, 5) : '18:00';

    $wsM           = (int)substr($ws, 0, 2) * 60 + (int)substr($ws, 3, 2);
    $weM           = (int)substr($we, 0, 2) * 60 + (int)substr($we, 3, 2);
    $totalWorkMins = $weM - $wsM - 120; // deduct 2h lunch
    $halfMins      = (int)floor($totalWorkMins / 2);

    if ($tipo === 'dia_inteiro') {
        $mins = $totalWorkMins;
    } elseif ($tipo === 'meio_periodo_manha') {
        $mins = $halfMins;
    } elseif ($tipo === 'meio_periodo_tarde') {
        $mins = $totalWorkMins - $halfMins;
    } else {
        if (!$customIni || !$customFim ||
            !preg_match('/^\d{2}:\d{2}$/', $customIni) ||
            !preg_match('/^\d{2}:\d{2}$/', $customFim))
            jsonOut(['error' => 'Horários personalizados inválidos.'], 422);
        $csM = (int)substr($customIni, 0, 2) * 60 + (int)substr($customIni, 3, 2);
        $ceM = (int)substr($customFim, 0, 2) * 60 + (int)substr($customFim, 3, 2);
        if ($ceM <= $csM) jsonOut(['error' => 'Horário de início deve ser anterior ao término.'], 422);
        $minsPerDay = $ceM - $csM;

        $days = 1;
        if ($dataFim && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim) && $dataFim > $dataInicio) {
            $start = new DateTime($dataInicio);
            $end   = new DateTime($dataFim);
            $days  = (int)$start->diff($end)->days + 1;
        } else {
            $dataFim = null;
        }
        $mins = $minsPerDay * $days;
    }

    if ($mins <= 0) jsonOut(['error' => 'Tempo calculado inválido.'], 422);

    // Check available balance
    $uid = $user['id'];

    $stmtVal = $db->prepare("SELECT COALESCE(SUM(total_minutos),0) FROM lancamentos WHERE usuario_id = ? AND status = 'aprovado'");
    $stmtVal->execute([$uid]);
    $totalAprovMins = (int)$stmtVal->fetchColumn();

    $stmtDed = $db->prepare("SELECT COALESCE(SUM(total_minutos),0) FROM solicitacoes_bh WHERE usuario_id = ? AND status = 'aprovado'");
    $stmtDed->execute([$uid]);
    $deducted = (int)$stmtDed->fetchColumn();

    $balance = $totalAprovMins - $deducted;
    if ($mins > $balance) jsonOut(['error' => 'Saldo insuficiente. Disponível: ' . minutesToHHMM($balance) . '.'], 422);

    $horaIni = ($tipo === 'personalizado' && $customIni) ? $customIni : null;
    $horaFim = ($tipo === 'personalizado' && $customFim) ? $customFim : null;

    $db->prepare("
        INSERT INTO solicitacoes_bh (usuario_id, tipo, data_inicio, data_fim, hora_inicio, hora_fim, total_minutos, motivo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$uid, $tipo, $dataInicio, $dataFim ?: null, $horaIni, $horaFim, $mins, $motivo]);

    $newId = (int)$db->lastInsertId('solicitacoes_bh_id_seq');
    jsonOut(['id' => $newId, 'total_minutos' => $mins]);
}

// ── Revisar solicitação (coordenador/admin) ───────────────────────────────────
if ($action === 'review') {
    $revisor       = apiCoordenador();
    $id            = (int)($body['id']            ?? 0);
    $status        = trim($body['status']         ?? '');
    $notaRevisao   = trim($body['nota_revisao']   ?? '');

    if (!$id) jsonOut(['error' => 'ID inválido.'], 422);
    if (!in_array($status, ['aprovado','recusado'])) jsonOut(['error' => 'Status inválido.'], 422);
    if ($status === 'recusado' && !$notaRevisao) jsonOut(['error' => 'Motivo da recusa é obrigatório.'], 422);

    $db   = getDb();
    $stmt = $db->prepare("SELECT sbh.*, u.setor_id FROM solicitacoes_bh sbh JOIN usuarios u ON u.id = sbh.usuario_id WHERE sbh.id = ?");
    $stmt->execute([$id]);
    $req = $stmt->fetch();
    if (!$req) jsonOut(['error' => 'Solicitação não encontrada.'], 404);
    if ($req['status'] !== 'pendente') jsonOut(['error' => 'Solicitação já foi processada.'], 409);

    // Sector check for coordinators
    if (!isAdmin() && (int)$req['setor_id'] !== (int)$revisor['setor_id']) {
        jsonOut(['error' => 'Acesso negado — solicitação de outro setor.'], 403);
    }

    $db->prepare("UPDATE solicitacoes_bh SET status=?, revisado_por=?, revisado_em=NOW(), nota_revisao=? WHERE id=?")
       ->execute([$status, $revisor['id'], $notaRevisao ?: null, $id]);

    jsonOut(['ok' => true, 'status' => $status]);
}

// ── Dedução administrativa (admin) ────────────────────────────────────────────
if ($action === 'admin_deduction') {
    $admin      = apiAdmin();
    $usuarioId  = (int)($body['usuario_id']    ?? 0);
    $totalMins  = (int)($body['total_minutos'] ?? 0);
    $motivo     = trim($body['motivo']         ?? '');

    if (!$usuarioId) jsonOut(['error' => 'Colaborador não selecionado.'], 422);
    if ($totalMins <= 0) jsonOut(['error' => 'Informe ao menos 1 minuto.'], 422);
    if (!$motivo) jsonOut(['error' => 'O motivo é obrigatório.'], 422);

    $db   = getDb();
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE id = ? AND status = 'ativo'");
    $stmt->execute([$usuarioId]);
    if (!$stmt->fetch()) jsonOut(['error' => 'Colaborador não encontrado.'], 404);

    $today = date('Y-m-d');
    $db->prepare("
        INSERT INTO solicitacoes_bh (usuario_id, tipo, data_inicio, total_minutos, motivo, status, revisado_por, revisado_em)
        VALUES (?, 'deducao_admin', ?, ?, ?, 'aprovado', ?, NOW())
    ")->execute([$usuarioId, $today, $totalMins, $motivo, $admin['id']]);

    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Ação inválida.'], 400);
