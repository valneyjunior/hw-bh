<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireCoordenador();
$db   = getDb();

$setorFilter = isAdmin() ? null : $user['setor_id'];

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-t');

// ── CSV export ──────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $sql = "
        SELECT u.nome AS colaborador, l.chamado,
               l.data_acionamento::text AS data, l.hora_inicio::text AS inicio, l.hora_fim::text AS fim,
               l.total_minutos, l.motivo, l.status
        FROM lancamentos l JOIN usuarios u ON u.id = l.usuario_id
        LEFT JOIN setores s ON s.id = u.setor_id
        WHERE l.data_acionamento BETWEEN ? AND ?
          AND u.status != 'ex-colaborador'
    ";
    $params = [$from, $to];
    if ($setorFilter) { $sql .= " AND u.setor_id = ?"; $params[] = $setorFilter; }
    $sql .= " ORDER BY u.nome, l.data_acionamento, l.hora_inicio";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio-' . $from . '_' . $to . '.csv"');
    echo "\xEF\xBB\xBF";
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Colaborador','Chamado','Data','Início','Fim','Duração','Motivo','Status'], ';');
    foreach ($stmt->fetchAll() as $r) {
        $hhmm = minutesToHHMM((int)$r['total_minutos']);
        $statusLabel = match($r['status']) { 'aprovado'=>'Aprovado','recusado'=>'Recusado',default=>'Pendente' };
        fputcsv($f, [
            $r['colaborador'], $r['chamado'],
            fmtDate($r['data']), substr($r['inicio'],0,5), substr($r['fim'],0,5),
            $hhmm, $r['motivo'], $statusLabel,
        ], ';');
    }
    fclose($f);
    exit;
}

// ── Main period data ────────────────────────────────────────────────────────
$sql = "
    SELECT l.usuario_id, u.nome AS user_nome,
           l.total_minutos,
           EXTRACT(HOUR FROM l.hora_inicio)::int AS start_hour,
           EXTRACT(DOW  FROM l.data_acionamento)::int AS dow,
           l.feriado,
           l.status,
           l.data_acionamento::text          AS data_acionamento,
           l.hora_inicio::text               AS hora_inicio,
           l.hora_fim::text                  AS hora_fim,
           COALESCE(l.valor_calculado, 0)::float AS valor_calculado,
           u.salario_bruto
    FROM lancamentos l JOIN usuarios u ON u.id = l.usuario_id
    WHERE l.data_acionamento BETWEEN ? AND ?
      AND u.status != 'ex-colaborador'
";
$params = [$from, $to];
if ($setorFilter) { $sql .= " AND u.setor_id = ?"; $params[] = $setorFilter; }
$sql .= " ORDER BY l.data_acionamento, l.hora_inicio";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$byPerson = [];
$hourDist = array_fill(0, 24, 0);
$financeiro = [
    'diurno'  => ['label' => 'Dia útil diurno', 'mult' => '× 1,50', 'pct' => '+50%',  'mins' => 0, 'cnt' => 0, 'valor' => 0.0],
    'noturno' => ['label' => 'Noturno (22h–5h)', 'mult' => '× 1,80', 'pct' => '+80%',  'mins' => 0, 'cnt' => 0, 'valor' => 0.0],
    'sabado'  => ['label' => 'Sábado',           'mult' => '× 1,50', 'pct' => '+50%',  'mins' => 0, 'cnt' => 0, 'valor' => 0.0],
    'domingo' => ['label' => 'Domingo',          'mult' => '× 2,00', 'pct' => '+100%', 'mins' => 0, 'cnt' => 0, 'valor' => 0.0],
    'feriado' => ['label' => 'Feriado',          'mult' => '× 2,00', 'pct' => '+100%', 'mins' => 0, 'cnt' => 0, 'valor' => 0.0],
];
$totalValor   = 0.0;
$somaHoraBase = 0.0;
$tipoBreakdown = [
    'diurno'  => ['mins' => 0, 'cnt' => 0],
    'noturno' => ['mins' => 0, 'cnt' => 0],
    'fds'     => ['mins' => 0, 'cnt' => 0],
    'feriado' => ['mins' => 0, 'cnt' => 0],
];

foreach ($records as $r) {
    $uid = $r['usuario_id'];
    $byPerson[$uid] ??= ['name'=>$r['user_nome'], 'vm'=>0,'pm'=>0,'vc'=>0,'pc'=>0];
    if ($r['status'] === 'aprovado') {
        $byPerson[$uid]['vm'] += (int)$r['total_minutos'];
        $byPerson[$uid]['vc']++;
    } else {
        $byPerson[$uid]['pm'] += (int)$r['total_minutos'];
        $byPerson[$uid]['pc']++;
    }
    $h = (int)$r['start_hour'];
    if ($h >= 0 && $h < 24) $hourDist[$h]++;

    // Tipo de acionamento
    $isFeriado = in_array((string)$r['feriado'], ['t', '1', 'true'], true);
    $dow       = (int)$r['dow'];
    $isWeekend = in_array($dow, [0, 6]);
    $isNoturno = $h >= 18 || $h < 6;
    if ($isFeriado)     $tipo = 'feriado';
    elseif ($isWeekend) $tipo = 'fds';
    elseif ($isNoturno) $tipo = 'noturno';
    else                $tipo = 'diurno';
    $tipoBreakdown[$tipo]['mins'] += (int)$r['total_minutos'];
    $tipoBreakdown[$tipo]['cnt']++;

    // Acumulação financeira — apenas aprovados
    if ($r['status'] === 'aprovado') {
        $vr = ($r['valor_calculado'] > 0)
            ? (float)$r['valor_calculado']
            : calcValorLancamento($r, (float)($r['salario_bruto'] ?? 0));
        $finDow = (int)$r['dow'];
        $finH   = (int)$r['start_hour'];
        $finNot = ($finH >= 22 || $finH < 5);
        $finFer = in_array((string)($r['feriado'] ?? ''), ['t', '1', 'true'], true);
        if      ($finFer)       $finTipo = 'feriado';
        elseif  ($finDow === 0) $finTipo = 'domingo';
        elseif  ($finDow === 6) $finTipo = 'sabado';
        elseif  ($finNot)       $finTipo = 'noturno';
        else                    $finTipo = 'diurno';
        $financeiro[$finTipo]['cnt']++;
        $financeiro[$finTipo]['mins'] += (int)$r['total_minutos'];
        $financeiro[$finTipo]['valor'] += $vr;
        $totalValor   += $vr;
        $somaHoraBase += (float)($r['salario_bruto'] ?? 0) / 220.0;
    }
}
uasort($byPerson, fn($a,$b) => ($b['vm']+$b['pm']) <=> ($a['vm']+$a['pm']));

$totalVM  = array_sum(array_column($byPerson, 'vm'));
$totalPM  = array_sum(array_column($byPerson, 'pm'));
$totalVC  = array_sum(array_column($byPerson, 'vc'));
$totalPC  = array_sum(array_column($byPerson, 'pc'));
$totalAll = $totalVC + $totalPC;
$mediaMin     = $totalAll > 0 ? intdiv($totalVM + $totalPM, $totalAll) : 0;
$mediaValor   = $totalVC  > 0 ? $totalValor / $totalVC   : 0.0;
$horaBaseMedia = $totalVC > 0 ? $somaHoraBase / $totalVC : 0.0;

// ── Monthly trend — last 6 months ──────────────────────────────────────────
$trendSql = "
    SELECT TO_CHAR(l.data_acionamento,'YYYY-MM') AS mo,
           l.usuario_id AS uid, u.nome AS uname,
           SUM(l.total_minutos) AS mins,
           COUNT(*) AS cnt
    FROM lancamentos l JOIN usuarios u ON u.id = l.usuario_id
    WHERE l.data_acionamento >= DATE_TRUNC('month', CURRENT_DATE - INTERVAL '5 months')
      AND u.status != 'ex-colaborador'
";
$trendParams = [];
if ($setorFilter) { $trendSql .= " AND u.setor_id = ?"; $trendParams[] = $setorFilter; }
$trendSql .= " GROUP BY mo, l.usuario_id, u.nome ORDER BY mo";
$trendStmt = $db->prepare($trendSql);
$trendStmt->execute($trendParams);
$trend = $trendStmt->fetchAll();

$trendMonths = [];
$trendUsers  = [];
foreach ($trend as $t) {
    $trendMonths[$t['mo']] = true;
    $trendUsers[$t['uid']] ??= ['name'=>$t['uname'], 'data'=>[]];
    $trendUsers[$t['uid']]['data'][$t['mo']] = (int)$t['mins'];
}
ksort($trendMonths);
$trendMonths = array_keys($trendMonths);

$mpt = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
function mlabel(string $ym): string {
    global $mpt;
    [$y,$m] = explode('-', $ym);
    return $mpt[(int)$m] . '/' . substr($y, 2);
}
function chartHHMM(int $mins): string {
    return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Relatórios</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-6xl mx-auto px-4 py-6 space-y-6">

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Relatórios</h1>
      <p class="text-sm text-gray-500"><?= $setorFilter ? e($user['setor_nome'] ?? '') : 'Todos os setores' ?></p>
    </div>
  </div>

  <!-- Filter bar -->
  <form method="GET" class="flex flex-wrap items-end gap-3" onsubmit="convertDates()">
    <div>
      <label class="block text-xs font-medium text-gray-600 mb-1">De</label>
      <input type="text" id="from_text" placeholder="DD/MM/AAAA" maxlength="10"
        value="<?= date('d/m/Y', strtotime($from)) ?>"
        class="hw-input px-3 py-2 text-sm font-mono w-36"
        oninput="applyDateMask(this)">
      <input type="hidden" name="from" id="from_iso" value="<?= e($from) ?>">
    </div>
    <div>
      <label class="block text-xs font-medium text-gray-600 mb-1">Até</label>
      <input type="text" id="to_text" placeholder="DD/MM/AAAA" maxlength="10"
        value="<?= date('d/m/Y', strtotime($to)) ?>"
        class="hw-input px-3 py-2 text-sm font-mono w-36"
        oninput="applyDateMask(this)">
      <input type="hidden" name="to" id="to_iso" value="<?= e($to) ?>">
    </div>
    <button type="submit" class="hw-btn text-sm px-4 py-2">Aplicar</button>
    <div class="flex gap-2 text-xs">
      <?php
        $ranges = [
          'Este mês'        => [date('Y-m-01'), date('Y-m-t')],
          'Mês passado'     => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
          'Últimos 3 meses' => [date('Y-m-01', strtotime('-2 months')), date('Y-m-t')],
          'Este ano'        => [date('Y-01-01'), date('Y-12-31')],
        ];
        foreach ($ranges as $label => [$f, $t]):
          $active = ($from === $f && $to === $t);
      ?>
        <a href="?from=<?= $f ?>&to=<?= $t ?>"
          class="px-3 py-2 border rounded-lg transition whitespace-nowrap font-medium
            <?= $active ? 'text-white border-transparent' : 'border-gray-300 text-gray-600 hover:bg-white' ?>"
          <?= $active ? 'style="background:var(--hw-gradient)"' : '' ?>>
          <?= $label ?>
        </a>
      <?php endforeach; ?>
    </div>
    <a href="?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&export=csv"
      class="ml-auto flex items-center gap-2 border border-gray-300 hover:bg-white text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
      </svg>
      Exportar CSV
    </a>
  </form>

  <?php if (empty($records)): ?>
    <div class="text-center py-24 text-gray-400">
      <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
      </svg>
      <p>Nenhum registro no período selecionado.</p>
    </div>
  <?php else: ?>

  <!-- Summary cards -->
  <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-blue">Total de acionamentos</p>
      <p class="hw-kpi-value hw-kpi-blue"><?= $totalVC + $totalPC ?></p>
      <p class="text-xs text-gray-400 mt-0.5"><?= $totalVC ?> aprovados · <?= $totalPC ?> pendentes</p>
    </div>
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-green">Horas aprovadas</p>
      <p class="hw-kpi-value hw-kpi-green"><?= chartHHMM($totalVM) ?></p>
      <p class="text-xs text-gray-400 mt-0.5"><?= $totalVC ?> registros</p>
    </div>
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-amber">Horas pendentes</p>
      <p class="hw-kpi-value hw-kpi-amber"><?= chartHHMM($totalPM) ?></p>
      <p class="text-xs text-gray-400 mt-0.5"><?= $totalPC ?> registros</p>
    </div>
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-teal">Colaboradores</p>
      <p class="hw-kpi-value hw-kpi-teal"><?= count($byPerson) ?></p>
      <p class="text-xs text-gray-400 mt-0.5">ativos no período</p>
    </div>
    <div class="hw-kpi-card">
      <p class="hw-kpi-title" style="color:#6b0fa8">Média por acionamento</p>
      <p class="hw-kpi-value" style="color:#6b0fa8"><?= chartHHMM($mediaMin) ?></p>
      <p class="text-xs text-gray-400 mt-0.5">duração média</p>
    </div>
  </div>

  <!-- Individual cards -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-<?= min(count($byPerson), 5) ?> gap-3">
    <?php foreach ($byPerson as $uid => $p):
      $totalMin = $p['vm'] + $p['pm'];
      $pct = $totalVM + $totalPM > 0 ? round($totalMin / ($totalVM + $totalPM) * 100) : 0;
    ?>
    <div class="bg-white rounded-2xl p-4" style="box-shadow:var(--hw-shadow)">
      <div class="flex items-center gap-2 mb-3">
        <div class="hw-avatar w-8 h-8 text-sm shrink-0"><?= strtoupper(mb_substr($p['name'], 0, 1)) ?></div>
        <p class="text-sm font-semibold text-gray-800 truncate"><?= e($p['name']) ?></p>
      </div>
      <p class="text-xl font-bold text-gray-900"><?= chartHHMM($totalMin) ?></p>
      <p class="text-xs text-gray-400 mb-2"><?= $p['vc'] + $p['pc'] ?> acionamentos · <?= $pct ?>% do total</p>
      <a href="/admin-report-colaborador.php?usuario_id=<?= $uid ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"
         class="inline-block text-xs font-medium hover:underline mt-1" style="color:var(--hw-purple)">Ver detalhe →</a>
      <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
        <div class="h-full rounded-full" style="width:<?= $pct ?>%;background:var(--hw-gradient)"></div>
      </div>
      <div class="flex justify-between mt-2 text-xs text-gray-400">
        <span class="text-green-600 font-medium"><?= chartHHMM($p['vm']) ?> apr.</span>
        <span class="text-amber-500 font-medium"><?= chartHHMM($p['pm']) ?> pend.</span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Charts row 1 -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
      <h3 class="text-sm font-semibold text-gray-700 mb-4">Horas por colaborador</h3>
      <canvas id="chartHours"></canvas>
    </div>
    <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
      <h3 class="text-sm font-semibold text-gray-700 mb-4">Acionamentos por colaborador</h3>
      <canvas id="chartCount"></canvas>
    </div>
  </div>

  <!-- Chart row 2: trend -->
  <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
    <h3 class="text-sm font-semibold text-gray-700 mb-1">Evolução mensal — últimos 6 meses</h3>
    <p class="text-xs text-gray-400 mb-4">Total de horas por colaborador ao longo do tempo</p>
    <canvas id="chartTrend" style="max-height:280px"></canvas>
  </div>

  <!-- Chart row 3: tipo distribution + hour distribution -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
      <h3 class="text-sm font-semibold text-gray-700 mb-1">Tipo de acionamento</h3>
      <p class="text-xs text-gray-400 mb-4">Distribuição por período — dia útil, noturno, fim de semana e feriado</p>
      <canvas id="chartTipo" style="max-height:260px"></canvas>
    </div>
    <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
      <h3 class="text-sm font-semibold text-gray-700 mb-1">Distribuição por hora do dia</h3>
      <p class="text-xs text-gray-400 mb-4">
        Horário de início dos acionamentos —
        <span class="font-medium" style="color:#6b0fa8">▪ Madrugada</span>
        <span class="ml-2 font-medium" style="color:#e8001c">▪ Manhã</span>
        <span class="ml-2 text-emerald-600 font-medium">▪ Tarde</span>
        <span class="ml-2 text-amber-500 font-medium">▪ Noite</span>
      </p>
      <canvas id="chartHours24" style="max-height:200px"></canvas>
    </div>
  </div>

  <!-- Custo estimado CLT -->
  <?php if ($totalVC > 0): ?>
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-5 py-4 border-b" style="background:linear-gradient(135deg,rgba(107,15,168,.06),rgba(232,0,28,.04))">
      <h3 class="font-semibold text-gray-900">Custo estimado — acionamentos aprovados</h3>
      <p class="text-xs text-gray-500 mt-0.5">CLT art. 59 (hora extra +50%) · art. 73 (adicional noturno +20%) · base: salário ÷ 220h</p>
    </div>
    <div class="p-5 space-y-5">

      <div class="grid grid-cols-3 gap-4">
        <div class="hw-kpi-card">
          <p class="hw-kpi-title" style="color:#6b0fa8">Total a pagar</p>
          <p class="hw-kpi-value-brl" style="color:#6b0fa8"><?= fmtBRL($totalValor) ?></p>
          <p class="text-xs text-gray-400 mt-0.5"><?= $totalVC ?> acionamentos aprovados</p>
        </div>
        <div class="hw-kpi-card">
          <p class="hw-kpi-title hw-kpi-teal">Média por acionamento</p>
          <p class="hw-kpi-value-brl hw-kpi-teal"><?= fmtBRL($mediaValor) ?></p>
          <p class="text-xs text-gray-400 mt-0.5">custo médio individual</p>
        </div>
        <div class="hw-kpi-card">
          <p class="hw-kpi-title hw-kpi-blue">Hora base média</p>
          <p class="hw-kpi-value-brl hw-kpi-blue"><?= fmtBRL($horaBaseMedia) ?>/h</p>
          <p class="text-xs text-gray-400 mt-0.5">salário médio ÷ 220h</p>
        </div>
      </div>

      <div class="overflow-hidden rounded-xl border border-gray-100">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gray-50 border-b border-gray-100">
              <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Tipo</th>
              <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Qtd</th>
              <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Horas</th>
              <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">% Qtd</th>
              <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Adicional CLT</th>
              <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Valor total</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php
            $corTipo = [
                'diurno'  => 'text-emerald-700',
                'noturno' => 'text-purple-700',
                'sabado'  => 'text-blue-700',
                'domingo' => 'text-orange-600',
                'feriado' => 'text-red-700',
            ];
            foreach ($financeiro as $tk => $tf):
              if ($tf['cnt'] === 0) continue;
              $pctQ = $totalVC > 0 ? round($tf['cnt'] / $totalVC * 100) : 0;
            ?>
            <tr class="hover:bg-gray-50/50 transition">
              <td class="px-4 py-3 font-medium <?= $corTipo[$tk] ?>"><?= $tf['label'] ?></td>
              <td class="px-4 py-3 text-right text-gray-700"><?= $tf['cnt'] ?></td>
              <td class="px-4 py-3 text-right font-mono text-gray-700"><?= chartHHMM($tf['mins']) ?></td>
              <td class="px-4 py-3 text-right">
                <div class="inline-flex items-center gap-2">
                  <div class="h-1.5 w-16 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full" style="width:<?= $pctQ ?>%;background:var(--hw-gradient)"></div>
                  </div>
                  <span class="text-gray-600 w-8 text-right"><?= $pctQ ?>%</span>
                </div>
              </td>
              <td class="px-4 py-3 text-right">
                <span class="font-mono text-xs font-semibold px-2 py-1 rounded-lg bg-gray-100 text-gray-700"><?= $tf['mult'] ?></span>
                <span class="ml-1 text-xs text-gray-400"><?= $tf['pct'] ?></span>
              </td>
              <td class="px-4 py-3 text-right font-semibold <?= $corTipo[$tk] ?>"><?= fmtBRL($tf['valor']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="bg-gray-50 border-t-2 border-gray-200 font-semibold">
              <td class="px-4 py-3 text-gray-700">Total aprovado</td>
              <td class="px-4 py-3 text-right text-gray-700"><?= $totalVC ?></td>
              <td class="px-4 py-3 text-right font-mono text-gray-700"><?= chartHHMM($totalVM) ?></td>
              <td class="px-4 py-3 text-right text-gray-600">100%</td>
              <td class="px-4 py-3"></td>
              <td class="px-4 py-3 text-right text-lg font-bold" style="color:var(--hw-purple)"><?= fmtBRL($totalValor) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</main>

<script>
<?php
$names      = array_values(array_column($byPerson, 'name'));
$valHours   = array_values(array_map(fn($p) => round($p['vm']/60, 2), $byPerson));
$pendHours  = array_values(array_map(fn($p) => round($p['pm']/60, 2), $byPerson));
$valMins    = array_values(array_column($byPerson, 'vm'));
$pendMins   = array_values(array_column($byPerson, 'pm'));
$valCounts  = array_values(array_column($byPerson, 'vc'));
$pendCounts = array_values(array_column($byPerson, 'pc'));

$trendLabels = array_map('mlabel', $trendMonths);
$trendSeries = [];
foreach ($trendUsers as $u) {
    $data = [];
    foreach ($trendMonths as $mo) {
        $data[] = isset($u['data'][$mo]) ? round($u['data'][$mo] / 60, 2) : 0;
    }
    $trendSeries[] = ['name' => $u['name'], 'data' => $data];
}

echo 'const names='       . json_encode($names)       . ';';
echo 'const valHours='    . json_encode($valHours)     . ';';
echo 'const pendHours='   . json_encode($pendHours)    . ';';
echo 'const valMins='     . json_encode($valMins)      . ';';
echo 'const pendMins='    . json_encode($pendMins)     . ';';
echo 'const valCounts='   . json_encode($valCounts)    . ';';
echo 'const pendCounts='  . json_encode($pendCounts)   . ';';
echo 'const hourDist='    . json_encode(array_values($hourDist)) . ';';
echo 'const trendLabels=' . json_encode($trendLabels)  . ';';
echo 'const trendSeries=' . json_encode($trendSeries)  . ';';
echo 'const tipoCnts='    . json_encode([
    $tipoBreakdown['diurno']['cnt'],
    $tipoBreakdown['noturno']['cnt'],
    $tipoBreakdown['fds']['cnt'],
    $tipoBreakdown['feriado']['cnt'],
]) . ';';
echo 'const tipoMins='    . json_encode([
    $tipoBreakdown['diurno']['mins'],
    $tipoBreakdown['noturno']['mins'],
    $tipoBreakdown['fds']['mins'],
    $tipoBreakdown['feriado']['mins'],
]) . ';';
?>

const PALETTE = ['#e8001c','#6b0fa8','#10B981','#F59E0B','#3B82F6','#06B6D4','#EC4899','#F97316'];

function hhmm(hours) {
  const t = Math.round(hours * 60);
  return `${String(Math.floor(t/60)).padStart(2,'0')}:${String(t%60).padStart(2,'0')}`;
}
function hhmmFromMins(m) {
  return `${String(Math.floor(m/60)).padStart(2,'0')}:${String(m%60).padStart(2,'0')}`;
}

Chart.defaults.font.family = "'Inter', 'Segoe UI', system-ui, sans-serif";
Chart.defaults.font.size   = 12;

new Chart(document.getElementById('chartHours'), {
  type: 'bar',
  data: {
    labels: names,
    datasets: [
      { label: 'Aprovadas', data: valHours,  backgroundColor: '#10B981', borderRadius: 4, stack: 'h' },
      { label: 'Pendentes', data: pendHours, backgroundColor: '#F59E0B', borderRadius: 4, stack: 'h' },
    ]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { position: 'bottom' },
      tooltip: {
        callbacks: {
          label: ctx => ` ${ctx.dataset.label}: ${hhmmFromMins(ctx.datasetIndex===0 ? valMins[ctx.dataIndex] : pendMins[ctx.dataIndex])}`
        }
      }
    },
    scales: {
      x: { stacked: true, grid: { display: false } },
      y: { stacked: true, ticks: { callback: v => hhmm(v) } }
    }
  }
});

new Chart(document.getElementById('chartCount'), {
  type: 'bar',
  data: {
    labels: names,
    datasets: [
      { label: 'Aprovados', data: valCounts,  backgroundColor: '#10B981', borderRadius: 4, stack: 'c' },
      { label: 'Pendentes', data: pendCounts, backgroundColor: '#F59E0B', borderRadius: 4, stack: 'c' },
    ]
  },
  options: {
    responsive: true,
    plugins: { legend: { position: 'bottom' } },
    scales: {
      x: { stacked: true, grid: { display: false } },
      y: { stacked: true, ticks: { stepSize: 1 } }
    }
  }
});

new Chart(document.getElementById('chartTrend'), {
  type: 'line',
  data: {
    labels: trendLabels,
    datasets: trendSeries.map((s, i) => ({
      label: s.name,
      data: s.data,
      borderColor:     PALETTE[i % PALETTE.length],
      backgroundColor: PALETTE[i % PALETTE.length] + '18',
      tension: 0.35,
      fill: false,
      pointRadius: 5,
      pointHoverRadius: 7,
    }))
  },
  options: {
    responsive: true,
    plugins: {
      legend: { position: 'bottom' },
      tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${hhmm(ctx.raw)}` } }
    },
    scales: {
      x: { grid: { display: false } },
      y: { ticks: { callback: v => hhmm(v) }, beginAtZero: true }
    }
  }
});

function applyDateMask(el) {
  let v = el.value.replace(/\D/g, '');
  if (v.length > 8) v = v.slice(0, 8);
  let out = v.slice(0, 2);
  if (v.length > 2) out += '/' + v.slice(2, 4);
  if (v.length > 4) out += '/' + v.slice(4, 8);
  el.value = out;
}
function convertDates() {
  ['from', 'to'].forEach(prefix => {
    const text = document.getElementById(prefix + '_text').value;
    const m = text.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (m) document.getElementById(prefix + '_iso').value = `${m[3]}-${m[2]}-${m[1]}`;
  });
}

new Chart(document.getElementById('chartTipo'), {
  type: 'bar',
  data: {
    labels: ['Dia útil', 'Noturno', 'Fim de semana', 'Feriado'],
    datasets: [
      {
        label: 'Acionamentos',
        data: tipoCnts,
        backgroundColor: ['#10B981','#6b0fa8','#3B82F6','#e8001c'],
        borderRadius: 6,
        yAxisID: 'yCount',
      },
      {
        label: 'Horas',
        data: tipoMins.map(m => Math.round(m / 60 * 100) / 100),
        backgroundColor: ['#10B98140','#6b0fa840','#3B82F640','#e8001c40'],
        borderRadius: 6,
        yAxisID: 'yHours',
      },
    ]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { position: 'bottom' },
      tooltip: {
        callbacks: {
          label: ctx => {
            if (ctx.datasetIndex === 0) return ` ${ctx.raw} acionamento${ctx.raw !== 1 ? 's' : ''}`;
            return ` ${hhmm(ctx.raw)} horas`;
          }
        }
      }
    },
    scales: {
      x: { grid: { display: false } },
      yCount: { position: 'left',  ticks: { stepSize: 1 }, beginAtZero: true },
      yHours: { position: 'right', ticks: { callback: v => hhmm(v) }, beginAtZero: true, grid: { display: false } },
    }
  }
});

new Chart(document.getElementById('chartHours24'), {
  type: 'bar',
  data: {
    labels: Array.from({length:24}, (_,i) => `${String(i).padStart(2,'0')}h`),
    datasets: [{
      label: 'Acionamentos',
      data: hourDist,
      backgroundColor: Array.from({length:24}, (_,i) => {
        if (i <  6) return '#6b0fa8';
        if (i < 12) return '#e8001c';
        if (i < 18) return '#10B981';
        return '#F59E0B';
      }),
      borderRadius: 4,
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          title: ctx => {
            const h = parseInt(ctx[0].label);
            return `${String(h).padStart(2,'0')}:00 – ${String(h+1).padStart(2,'0')}:00`;
          },
          label: ctx => ` ${ctx.raw} acionamento${ctx.raw !== 1 ? 's' : ''}`
        }
      }
    },
    scales: {
      x: { grid: { display: false } },
      y: { ticks: { stepSize: 1 }, beginAtZero: true }
    }
  }
});
</script>
</body></html>
