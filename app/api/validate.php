<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

$revisor = apiCoordenador();
$body    = jsonBody();
$id      = (int)($body['id'] ?? 0);
$action  = $body['action'] ?? '';

if (!$id) jsonOut(['error' => 'ID inválido.'], 422);

$db   = getDb();
$stmt = $db->prepare("
    SELECT l.*, u.nome AS user_nome, u.email AS user_email, u.setor_id
    FROM lancamentos l JOIN usuarios u ON u.id = l.usuario_id
    WHERE l.id = ?
");
$stmt->execute([$id]);
$rec = $stmt->fetch();

if (!$rec) jsonOut(['error' => 'Registro não encontrado.'], 404);

// Coordinator can only act on their sector's records
if (!isAdmin() && (int)$rec['setor_id'] !== (int)$revisor['setor_id']) {
    jsonOut(['error' => 'Acesso negado — registro de outro setor.'], 403);
}

// ── Rejeitar ─────────────────────────────────────────────────────────────────
if ($action === 'reject') {
    $reason = trim($body['reason'] ?? '');
    if (!$reason) jsonOut(['error' => 'O motivo da recusa é obrigatório.'], 422);
    if ($rec['status'] !== 'pendente') jsonOut(['error' => 'Apenas registros pendentes podem ser recusados.'], 409);

    $db->prepare("
        UPDATE lancamentos SET status='recusado', revisado_por=?, revisado_em=NOW(), nota_revisao=?
        WHERE id=?
    ")->execute([$revisor['id'], $reason, $id]);

    mailRejectRecord(
        $rec['user_email'],
        $rec['user_nome'],
        $rec['chamado'],
        fmtDate($rec['data_acionamento']) . ' ' . substr($rec['hora_inicio'], 0, 5),
        fmtDate($rec['data_acionamento']) . ' ' . substr($rec['hora_fim'], 0, 5),
        $reason
    );

    jsonOut(['rejected' => true]);
}

// ── Aprovar ──────────────────────────────────────────────────────────────────
if ($action === 'approve') {
    if ($rec['status'] !== 'pendente') jsonOut(['error' => 'Apenas registros pendentes podem ser aprovados.'], 409);

    $stmtSal = $db->prepare("SELECT salario_bruto FROM usuarios WHERE id = ?");
    $stmtSal->execute([$rec['usuario_id']]);
    $salario   = (float)($stmtSal->fetchColumn() ?? 0);
    $valorCalc = calcValorLancamento($rec, $salario);

    $db->prepare("
        UPDATE lancamentos SET status='aprovado', revisado_por=?, revisado_em=NOW(),
                               valor_calculado=?
        WHERE id=?
    ")->execute([$revisor['id'], $valorCalc > 0 ? $valorCalc : null, $id]);

    jsonOut(['approved' => true]);
}

jsonOut(['error' => 'Ação inválida. Use approve ou reject.'], 400);
