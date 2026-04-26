<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

$admin = apiAdmin();
$body  = jsonBody();
$id    = $body['id'] ?? '';
$action= $body['action'] ?? 'toggle'; // toggle | reject

if (!$id) jsonOut(['error' => 'ID inválido.'], 422);

$db   = getDb();
$stmt = $db->prepare("SELECT r.*, u.name AS user_name, u.email AS user_email FROM records r JOIN users u ON u.id = r.user_id WHERE r.id = ?");
$stmt->execute([$id]);
$rec = $stmt->fetch();

if (!$rec) jsonOut(['error' => 'Registro não encontrado.'], 404);

// ── Rejeitar ─────────────────────────────────────────────────────────────────
if ($action === 'reject') {
    $reason = trim($body['reason'] ?? '');
    if (!$reason) jsonOut(['error' => 'O motivo da recusa é obrigatório.'], 422);
    if ($rec['validated_at']) jsonOut(['error' => 'Não é possível recusar um registro já validado.'], 409);

    $now = date('Y-m-d H:i:s');
    $db->prepare("UPDATE records SET rejected_at=?, rejected_by=?, reject_reason=? WHERE id=?")
       ->execute([$now, $admin['id'], $reason, $id]);

    // E-mail ao colaborador
    mailRejectRecord(
        $rec['user_email'],
        $rec['user_name'],
        $rec['ticket'],
        fmtDt($rec['started_at']),
        fmtDt($rec['ended_at']),
        $reason
    );

    jsonOut(['rejected' => true]);
}

// ── Toggle validação ─────────────────────────────────────────────────────────
if ($rec['rejected_at']) jsonOut(['error' => 'Registro recusado não pode ser validado. Exclua e relance.'], 409);

if ($rec['validated_at']) {
    $db->prepare("UPDATE records SET validated_at=NULL, validated_by=NULL WHERE id=?")->execute([$id]);
    jsonOut(['validated' => false]);
} else {
    $now = date('Y-m-d H:i:s');
    $db->prepare("UPDATE records SET validated_at=?, validated_by=? WHERE id=?")->execute([$now, $admin['id'], $id]);
    jsonOut(['validated' => true, 'validated_at' => $now]);
}
