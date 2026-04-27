<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$admin = requireAdmin();

$db   = getDb();
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-t');

// ── CSV export ──────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $db->prepare("
        SELECT u.name AS colaborador, r.ticket AS chamado,
               r.started_at, r.ended_at,
               TIMESTAMPDIFF(MINUTE, r.started_at, r.ended_at) AS minutos,
               r.description AS descricao,
               IF(r.validated_at IS NOT NULL,'Validado','Pendente') AS status
        FROM records r JOIN users u ON u.id = r.user_id
        WHERE DATE(r.started_at) BETWEEN ? AND ?
        ORDER BY u.name, r.started_at
    ");
    $stmt->execute([$from, $to]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bh-' . $from . '_' . $to . '.csv"');
    echo "\xEF\xBB\xBF";
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Colaborador','Chamado','Início','Fim','Minutos','Duração','Descrição','Status'], ';');
    foreach ($stmt->fetchAll() as $r) {
        $hhmm = sprintf('%02d:%02d', intdiv((int)$r['minutos'], 60), (int)$r['minutos'] % 60);
        fputcsv($f, [
            $r['colaborador'], $r['chamado'],
            fmtDt($r['started_at']), fmtDt($r['ended_at']),
            $r['minutos'], $hhmm, $r['descricao'], $r['status'],
        ], ';');
    }
    fclose($f);
    exit;
}

// ── Main period data ────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT r.user_id, u.name AS user_name,
           TIMESTAMPDIFF(MINUTE, r.started_at, r.ended_at) AS minutes,
           HOUR(r.started_at) AS start_hour,
           (r.validated_at IS NOT NULL) AS is_validated
    FROM records r JOIN users u ON u.id = r.user_id
    WHERE DATE(r.started_at) BETWEEN ? AND ?
    ORDER BY r.started_at
");
$stmt->execute([$from, $to]);
$records = $stmt->fetchAll();

$byPerson = [];
$hourDist = array_fill(0, 24, 0);
foreach ($records as $r) {
    $uid = $r['user_id'];
    $byPerson[$uid] ??= ['name'=>$r['user_name'], 'vm'=>0,'pm'=>0,'vc'=>0,'pc'=>0];
    if ($r['is_validated']) { $byPerson[$uid]['vm'] += $r['minutes']; $byPerson[$uid]['vc']++; }
    else                    { $byPerson[$uid]['pm'] += $r['minutes']; $byPerson[$uid]['pc']++; }
    $hourDist[$r['start_hour']]++;
}
uasort($byPerson, fn($a,$b) => ($b['vm']+$b['pm']) <=> ($a['vm']+$a['pm']));

$totalVM  = array_sum(array_column($byPerson, 'vm'));
$totalPM  = array_sum(array_column($byPerson, 'pm'));
$totalVC  = array_sum(array_column($byPerson, 'vc'));
$totalPC  = array_sum(array_column($byPerson, 'pc'));

// ── Monthly trend — last 6 months ──────────────────────────────────────────
$trend = $db->query("
    SELECT DATE_FORMAT(r.started_at,'%Y-%m') AS mo,
           r.user_id AS uid, u.name AS uname,
           SUM(TIMESTAMPDIFF(MINUTE,r.started_at,r.ended_at)) AS mins,
           COUNT(*) AS cnt
    FROM records r JOIN users u ON u.id = r.user_id
    WHERE r.started_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01')
    GROUP BY mo, r.user_id ORDER BY mo
")->fetchAll();

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
    <!-- Quick ranges -->
    <div class="flex gap-2 text-xs">
      <?php
        $ranges = [
          'Este mês'       => [date('Y-m-01'), date('Y-m-t')],
          'Mês passado'    => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
          'Últimos 3 meses'=> [date('Y-m-01', strtotime('-2 months')), date('Y-m-t')],
          'Este ano'       => [date('Y-01-01'), date('Y-12-31')],
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
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
    <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
      <p class="text-xs font-medium text-gray-500 mb-1">Total de acionamentos</p>
      <p class="text-3xl font-bold hw-stat"><?= $totalVC + $totalPC ?></p>
      <p class="text-xs text-gray-400 mt-0.5"><?= $totalVC ?> validados · <?= $totalPC ?> pendentes</p>
    </div>
    <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
      <p class="text-xs font-medium text-gray-500 mb-1">Horas validadas</p>
      <p class="text-3xl font-bold text-green-600"><?= chartHHMM($totalVM) ?></p>
      <p class="text-xs text-gray-400 mt-0.5"><?= $totalVC ?> registros</p>
    </div>
    <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
      <p class="text-xs font-medium text-gray-500 mb-1">Horas pendentes</p>
      <p class="text-3xl font-bold text-amber-500"><?= chartHHMM($totalPM) ?></p>
      <p class="text-xs text-gray-400 mt-0.5"><?= $totalPC ?> registros</p>
    </div>
    <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
      <p class="text-xs font-medium text-gray-500 mb-1">Colaboradores</p>
      <p class="text-3xl font-bold" style="color:var(--hw-purple)"><?= count($byPerson) ?></p>
      <p class="text-xs text-gray-400 mt-0.5">ativos no período</p>
    </div>
  </div>

  <!-- Individual cards -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-<?= min(count($byPerson), 5) ?> gap-3">
    <?php
    $i = 0;
    foreach ($byPerson as $uid => $p):
      $totalMin = $p['vm'] + $p['pm'];
      $pct = $totalVM + $totalPM > 0 ? round($totalMin / ($totalVM + $totalPM) * 100) : 0;
    ?>
    <div class="bg-white rounded-2xl p-4" style="box-shadow:var(--hw-shadow)">
      <div class="flex items-center gap-2 mb-3">
        <div class="hw-avatar w-8 h-8 text-sm shrink-0">
          <?= strtoupper(mb_substr($p['name'], 0, 1)) ?>
        </div>
        <p class="text-sm font-semibold text-gray-800 truncate"><?= e($p['name']) ?></p>
      </div>
      <p class="text-xl font-bold text-gray-900"><?= chartHHMM($totalMin) ?></p>
      <p class="text-xs text-gray-400 mb-2"><?= $p['vc'] + $p['pc'] ?> acionamentos · <?= $pct ?>% do total</p>
      <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
        <div class="h-full rounded-full" style="width:<?= $pct ?>%;background:var(--hw-gradient)"></div>
      </div>
      <div class="flex justify-between mt-2 text-xs text-gray-400">
        <span class="text-green-600 font-medium"><?= chartHHMM($p['vm']) ?> val.</span>
        <span class="text-amber-500 font-medium"><?= chartHHMM($p['pm']) ?> pend.</span>
      </div>
    </div>
    <?php $i++; endforeach; ?>
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

  <!-- Chart row 3: hour distribution -->
  <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
    <h3 class="text-sm font-semibold text-gray-700 mb-1">Distribuição por hora do dia</h3>
    <p class="text-xs text-gray-400 mb-4">
      Horário de início dos acionamentos no período —
      <span class="font-medium" style="color:#6b0fa8">▪ Madrugada 0–5h</span>
      <span class="ml-2 font-medium" style="color:#e8001c">▪ Manhã 6–11h</span>
      <span class="ml-2 text-emerald-600 font-medium">▪ Tarde 12–17h</span>
      <span class="ml-2 text-amber-500 font-medium">▪ Noite 18–23h</span>
    </p>
    <canvas id="chartHours24" style="max-height:200px"></canvas>
  </div>

  <?php endif; ?>
</main>

<script>
<?php
$names     = array_values(array_column($byPerson, 'name'));
$valHours  = array_values(array_map(fn($p) => round($p['vm']/60, 2), $byPerson));
$pendHours = array_values(array_map(fn($p) => round($p['pm']/60, 2), $byPerson));
$valMins   = array_values(array_column($byPerson, 'vm'));
$pendMins  = array_values(array_column($byPerson, 'pm'));
$valCounts = array_values(array_column($byPerson, 'vc'));
$pendCounts= array_values(array_column($byPerson, 'pc'));

$trendLabels = array_map('mlabel', $trendMonths);
$trendSeries = [];
foreach ($trendUsers as $u) {
    $data = [];
    foreach ($trendMonths as $mo) {
        $data[] = isset($u['data'][$mo]) ? round($u['data'][$mo] / 60, 2) : 0;
    }
    $trendSeries[] = ['name' => $u['name'], 'data' => $data];
}

echo 'const names='      . json_encode($names)       . ';';
echo 'const valHours='   . json_encode($valHours)     . ';';
echo 'const pendHours='  . json_encode($pendHours)    . ';';
echo 'const valMins='    . json_encode($valMins)      . ';';
echo 'const pendMins='   . json_encode($pendMins)     . ';';
echo 'const valCounts='  . json_encode($valCounts)    . ';';
echo 'const pendCounts=' . json_encode($pendCounts)   . ';';
echo 'const hourDist='   . json_encode(array_values($hourDist)) . ';';
echo 'const trendLabels='. json_encode($trendLabels)  . ';';
echo 'const trendSeries='. json_encode($trendSeries)  . ';';
?>

// Hostweb brand palette for charts
const PALETTE = ['#e8001c','#6b0fa8','#10B981','#F59E0B','#3B82F6','#06B6D4','#EC4899','#F97316'];

function hhmm(hours) {
  const t = Math.round(hours * 60);
  return `${String(Math.floor(t/60)).padStart(2,'0')}:${String(t%60).padStart(2,'0')}`;
}
function hhmmFromMins(m) {
  return `${String(Math.floor(m/60)).padStart(2,'0')}:${String(m%60).padStart(2,'0')}`;
}

Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
Chart.defaults.font.size   = 12;

// ── Horas por colaborador (stacked bar) ─────────────────────────────────────
new Chart(document.getElementById('chartHours'), {
  type: 'bar',
  data: {
    labels: names,
    datasets: [
      { label: 'Validadas', data: valHours,  backgroundColor: '#10B981', borderRadius: 4, stack: 'h' },
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

// ── Acionamentos por colaborador (stacked bar) ──────────────────────────────
new Chart(document.getElementById('chartCount'), {
  type: 'bar',
  data: {
    labels: names,
    datasets: [
      { label: 'Validados', data: valCounts,  backgroundColor: '#10B981', borderRadius: 4, stack: 'c' },
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

// ── Evolução mensal (line) ──────────────────────────────────────────────────
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

// ── Filtro: máscara e conversão de data ─────────────────────────────────────
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

// ── Distribuição por hora (bar 0-23) ────────────────────────────────────────
new Chart(document.getElementById('chartHours24'), {
  type: 'bar',
  data: {
    labels: Array.from({length:24}, (_,i) => `${String(i).padStart(2,'0')}h`),
    datasets: [{
      label: 'Acionamentos',
      data: hourDist,
      backgroundColor: Array.from({length:24}, (_,i) => {
        if (i <  6) return '#6b0fa8'; // madrugada — roxo Hostweb
        if (i < 12) return '#e8001c'; // manhã — vermelho Hostweb
        if (i < 18) return '#10B981'; // tarde — verde
        return '#F59E0B';              // noite — âmbar
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
