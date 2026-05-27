<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$user   = apiLogin();
$body   = jsonBody();
$action = $body['action'] ?? '';

if ($action === 'create') {
    $data             = trim($body['data_acionamento']  ?? '');
    $inicio           = trim($body['hora_inicio']       ?? '');
    $fim              = trim($body['hora_fim']          ?? '');
    $chamado          = trim($body['chamado']           ?? '');
    $motivo           = trim($body['motivo']            ?? '');
    $feriado          = !empty($body['feriado']);
    $descricaoFeriado = trim($body['descricao_feriado'] ?? '');

    if (!$data || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data))
        jsonOut(['error' => 'Data do acionamento inválida.'], 422);
    if (!preg_match('/^\d{2}:\d{2}$/', $inicio))
        jsonOut(['error' => 'Hora de início inválida.'], 422);
    if (!preg_match('/^\d{2}:\d{2}$/', $fim))
        jsonOut(['error' => 'Hora de fim inválida.'], 422);
    if (!$chamado) jsonOut(['error' => 'Chamado é obrigatório.'], 422);
    if (!$motivo)  jsonOut(['error' => 'Motivo é obrigatório.'], 422);
    if ($feriado && !$descricaoFeriado)
        jsonOut(['error' => 'Informe o nome do feriado.'], 422);

    $db = getDb();

    // Validar sobreposição com intervalo de almoço (ignora se colunas ainda não existem)
    try {
        $stmtLunch = $db->prepare("SELECT lunch_start, lunch_minutes FROM usuarios WHERE id = ?");
        $stmtLunch->execute([$user['id']]);
        $lunchRow  = $stmtLunch->fetch();
        $lunchS    = $lunchRow ? substr($lunchRow['lunch_start'], 0, 5) : '12:00';
        $lunchM    = $lunchRow ? (int)$lunchRow['lunch_minutes']        : 60;
        [$lh, $lm] = array_map('intval', explode(':', $lunchS));
        $lunchStartM = $lh * 60 + $lm;
        $lunchEndM   = $lunchStartM + $lunchM;
        $iniM = (int)substr($inicio, 0, 2) * 60 + (int)substr($inicio, 3, 2);
        $fimM = (int)substr($fim,    0, 2) * 60 + (int)substr($fim,    3, 2);
        if ($iniM < $lunchEndM && $fimM > $lunchStartM) {
            $lunchEndStr = sprintf('%02d:%02d', intdiv($lunchEndM, 60), $lunchEndM % 60);
            jsonOut(['error' => "Lançamentos no intervalo de almoço ({$lunchS}–{$lunchEndStr}) não são permitidos."], 422);
        }
    } catch (\Throwable $e) { /* colunas lunch_* ainda não existem — migração pendente */ }

    $totalMinutos = totalMinutosLancamento($data, $inicio, $fim);
    $foraDoPrazo  = foraDoPrazo($data, $fim);

    $db->prepare("
        INSERT INTO lancamentos
            (usuario_id, data_acionamento, hora_inicio, hora_fim, chamado, motivo,
             feriado, descricao_feriado, total_minutos, fora_do_prazo, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente')
    ")->execute([
        $user['id'], $data, $inicio . ':00', $fim . ':00', $chamado, $motivo,
        $feriado ? 'true' : 'false',
        $feriado ? $descricaoFeriado : null,
        $totalMinutos,
        $foraDoPrazo ? 'true' : 'false',
    ]);

    $id = (int)$db->lastInsertId('lancamentos_id_seq');
    jsonOut(['id' => $id, 'total_minutos' => $totalMinutos, 'fora_do_prazo' => $foraDoPrazo]);
}

if ($action === 'update') {
    $id               = (int)($body['id']                ?? 0);
    $data             = trim($body['data_acionamento']   ?? '');
    $inicio           = trim($body['hora_inicio']        ?? '');
    $fim              = trim($body['hora_fim']           ?? '');
    $chamado          = trim($body['chamado']            ?? '');
    $motivo           = trim($body['motivo']             ?? '');
    $feriado          = !empty($body['feriado']);
    $descricaoFeriado = trim($body['descricao_feriado']  ?? '');

    if (!$id) jsonOut(['error' => 'ID inválido.'], 422);
    if (!$data || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data))
        jsonOut(['error' => 'Data inválida.'], 422);
    if (!preg_match('/^\d{2}:\d{2}$/', $inicio) || !preg_match('/^\d{2}:\d{2}$/', $fim))
        jsonOut(['error' => 'Horários inválidos.'], 422);
    if (!$chamado) jsonOut(['error' => 'Chamado é obrigatório.'], 422);
    if (!$motivo)  jsonOut(['error' => 'Motivo é obrigatório.'], 422);
    if ($feriado && !$descricaoFeriado)
        jsonOut(['error' => 'Informe o nome do feriado.'], 422);

    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM lancamentos WHERE id = ?");
    $stmt->execute([$id]);
    $rec = $stmt->fetch();

    if (!$rec) jsonOut(['error' => 'Registro não encontrado.'], 404);
    if (!isAdmin() && (int)$rec['usuario_id'] !== $user['id']) jsonOut(['error' => 'Acesso negado.'], 403);
    if (!isAdmin() && $rec['status'] !== 'pendente') jsonOut(['error' => 'Apenas registros pendentes podem ser editados.'], 403);

    // Validar sobreposição com intervalo de almoço (ignora se colunas ainda não existem)
    try {
        $ownerId = isAdmin() ? (int)$rec['usuario_id'] : $user['id'];
        $stmtLunch2 = $db->prepare("SELECT lunch_start, lunch_minutes FROM usuarios WHERE id = ?");
        $stmtLunch2->execute([$ownerId]);
        $lunchRow2   = $stmtLunch2->fetch();
        $lunchS2     = $lunchRow2 ? substr($lunchRow2['lunch_start'], 0, 5) : '12:00';
        $lunchM2     = $lunchRow2 ? (int)$lunchRow2['lunch_minutes']        : 60;
        [$lh2, $lm2] = array_map('intval', explode(':', $lunchS2));
        $lunchStartM2 = $lh2 * 60 + $lm2;
        $lunchEndM2   = $lunchStartM2 + $lunchM2;
        $iniM2 = (int)substr($inicio, 0, 2) * 60 + (int)substr($inicio, 3, 2);
        $fimM2 = (int)substr($fim,    0, 2) * 60 + (int)substr($fim,    3, 2);
        if ($iniM2 < $lunchEndM2 && $fimM2 > $lunchStartM2) {
            $lunchEndStr2 = sprintf('%02d:%02d', intdiv($lunchEndM2, 60), $lunchEndM2 % 60);
            jsonOut(['error' => "Lançamentos no intervalo de almoço ({$lunchS2}–{$lunchEndStr2}) não são permitidos."], 422);
        }
    } catch (\Throwable $e) { /* colunas lunch_* ainda não existem — migração pendente */ }

    $totalMinutos = totalMinutosLancamento($data, $inicio, $fim);
    $foraDoPrazo  = foraDoPrazo($data, $fim);

    $db->prepare("
        UPDATE lancamentos SET
            data_acionamento=?, hora_inicio=?, hora_fim=?, chamado=?, motivo=?,
            feriado=?, descricao_feriado=?, total_minutos=?, fora_do_prazo=?
        WHERE id=?
    ")->execute([
        $data, $inicio . ':00', $fim . ':00', $chamado, $motivo,
        $feriado ? 'true' : 'false',
        $feriado ? $descricaoFeriado : null,
        $totalMinutos,
        $foraDoPrazo ? 'true' : 'false',
        $id,
    ]);

    jsonOut(['ok' => true, 'total_minutos' => $totalMinutos]);
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) jsonOut(['error' => 'ID inválido.'], 422);

    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM lancamentos WHERE id = ?");
    $stmt->execute([$id]);
    $rec = $stmt->fetch();

    if (!$rec) jsonOut(['error' => 'Registro não encontrado.'], 404);
    if (!isAdmin() && (int)$rec['usuario_id'] !== $user['id']) jsonOut(['error' => 'Acesso negado.'], 403);
    if (!isAdmin() && $rec['status'] !== 'pendente') jsonOut(['error' => 'Apenas registros pendentes podem ser excluídos.'], 403);

    $db->prepare("DELETE FROM lancamentos WHERE id = ?")->execute([$id]);
    jsonOut(['ok' => true]);
}

jsonOut(['error' => 'Ação inválida.'], 400);
