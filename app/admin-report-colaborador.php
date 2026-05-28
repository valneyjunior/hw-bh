<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireCoordenador();
$db   = getDb();

$setorFilter = isAdmin() ? null : (int)$user['setor_id'];

$uid  = (int)($_GET['usuario_id'] ?? 0);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-t');

if (!$uid) { header('Location: /admin-reports.php'); exit; }

// Carregar colaborador
$stmtC = $db->prepare("
    SELECT u.*, s.nome AS setor_nome
    FROM usuarios u LEFT JOIN setores s ON s.id = u.setor_id
    WHERE u.id = ?
");
$stmtC->execute([$uid]);
$collab = $stmtC->fetch();
if (!$collab) { header('Location: /admin-reports.php'); exit; }
if ($setorFilter && (int)$collab['setor_id'] !== $setorFilter) {
    header('Location: /admin-reports.php'); exit;
}

$salario  = (float)($collab['salario_bruto'] ?? 0);
$horaBase = $salario > 0 ? $salario / 220.0 : 0.0;

// Lançamentos no período
$stmtL = $db->prepare("
    SELECT l.*,
           l.data_acionamento::text           AS data_acionamento,
           l.hora_inicio::text                AS hora_inicio,
           l.hora_fim::text                   AS hora_fim,
           EXTRACT(DOW  FROM l.data_acionamento)::int AS dow,
           EXTRACT(HOUR FROM l.hora_inicio)::int      AS start_hour,
           COALESCE(l.valor_calculado, 0)::float      AS valor_calculado,
           rev.nome AS revisor_nome
    FROM lancamentos l
    LEFT JOIN usuarios rev ON rev.id = l.revisado_por
    WHERE l.usuario_id = ? AND l.data_acionamento BETWEEN ? AND ?
    ORDER BY l.data_acionamento DESC, l.hora_inicio DESC
");
$stmtL->execute([$uid, $from, $to]);
$lancamentos = $stmtL->fetchAll();

// Saldo BH total (histórico completo, não apenas o período)
$stmtAprov = $db->prepare("SELECT COALESCE(SUM(total_minutos),0) FROM lancamentos WHERE usuario_id=? AND status='aprovado'");
$stmtAprov->execute([$uid]);
$totalAprovAllTime = (int)$stmtAprov->fetchColumn();
$stmtDed = $db->prepare("SELECT COALESCE(SUM(total_minutos),0) FROM solicitacoes_bh WHERE usuario_id=? AND status='aprovado'");
$stmtDed->execute([$uid]);
$deducted = (int)$stmtDed->fetchColumn();
$saldoBH  = $totalAprovAllTime - $deducted;

// Tendência mensal — últimos 6 meses
$stmtTrend = $db->prepare("
    SELECT TO_CHAR(data_acionamento,'YYYY-MM') AS mo,
           SUM(total_minutos)  AS mins,
           COUNT(*)            AS cnt
    FROM lancamentos
    WHERE usuario_id = ?
      AND data_acionamento >= DATE_TRUNC('month', CURRENT_DATE - INTERVAL '5 months')
    GROUP BY mo ORDER BY mo
");
$stmtTrend->execute([$uid]);
$trend = $stmtTrend->fetchAll();
$trendLabels = [];
$trendMins   = [];
$mpt = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
foreach ($trend as $t) {
    [$y, $m] = explode('-', $t['mo']);
    $trendLabels[] = $mpt[(int)$m] . '/' . substr($y, 2);
    $trendMins[]   = round((int)$t['mins'] / 60, 2);
}

// Cálculo de breakdown financeiro
$financeiro = [
    'diurno'  => ['label' => 'Dia útil diurno', 'mult' => '× 1,50', 'pct' => '+50%',  'mins' => 0, 'cnt' => 0, 'valor' => 0.0],
    'noturno' => ['label' => 'Noturno (22h–5h)', 'mult' => '× 1,80', 'pct' => '+80%',  'mins' => 0, 'cnt' => 0, 'valor' => 0.0],
    'sabado'  => ['label' => 'Sábado',           'mult' => '× 1,50', 'pct' => '+50%',  'mins' => 0, 'cnt' => 0, 'valor' => 0.0],
    'domingo' => ['label' => 'Domingo',          'mult' => '× 2,00', 'pct' => '+100%', 'mins' => 0, 'cnt' => 0, 'valor' => 0.0],
    'feriado' => ['label' => 'Feriado',          'mult' => '× 2,00', 'pct' => '+100%', 'mins' => 0, 'cnt' => 0, 'valor' => 0.0],
];
$totalValor   = 0.0;
$minsAprov    = 0;
$minsPend     = 0;
$cntAprov     = 0;
$cntPend      = 0;
$cntRecusado  = 0;

foreach ($lancamentos as &$l) {
    $finFer = in_array((string)($l['feriado'] ?? ''), ['t','1','true'], true);
    $finDow = (int)$l['dow'];
    $finH   = (int)$l['start_hour'];
    $finNot = ($finH >= 22 || $finH < 5);

    if      ($finFer)       $tipo = 'feriado';
    elseif  ($finDow === 0) $tipo = 'domingo';
    elseif  ($finDow === 6) $tipo = 'sabado';
    elseif  ($finNot)       $tipo = 'noturno';
    else                    $tipo = 'diurno';
    $l['_tipo'] = $tipo;

    if ($l['status'] === 'aprovado') {
        $vr = ($l['valor_calculado'] > 0)
            ? (float)$l['valor_calculado']
            : calcValorLancamento($l, $salario);
        $l['_valor'] = $vr;
        $financeiro[$tipo]['cnt']++;
        $financeiro[$tipo]['mins'] += (int)$l['total_minutos'];
        $financeiro[$tipo]['valor'] += $vr;
        $totalValor += $vr;
        $minsAprov  += (int)$l['total_minutos'];
        $cntAprov++;
    } elseif ($l['status'] === 'pendente') {
        $l['_valor'] = calcValorLancamento($l, $salario);
        $minsPend += (int)$l['total_minutos'];
        $cntPend++;
    } else {
        $l['_valor'] = 0.0;
        $cntRecusado++;
    }
}
unset($l);

$corTipo = [
    'diurno'  => ['bg' => 'bg-emerald-100',  'text' => 'text-emerald-700', 'label' => 'Diurno'],
    'noturno' => ['bg' => 'bg-purple-100',   'text' => 'text-purple-700',  'label' => 'Noturno'],
    'sabado'  => ['bg' => 'bg-blue-100',     'text' => 'text-blue-700',    'label' => 'Sábado'],
    'domingo' => ['bg' => 'bg-orange-100',   'text' => 'text-orange-600',  'label' => 'Domingo'],
    'feriado' => ['bg' => 'bg-red-100',      'text' => 'text-red-700',     'label' => 'Feriado'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — <?= e($collab['nome']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-5xl mx-auto px-4 py-6 space-y-6">

  <!-- Cabeçalho -->
  <div class="flex items-center justify-between">
    <div class="flex items-center gap-4">
      <a href="/admin-reports.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"
         class="text-sm hover:underline" style="color:var(--hw-purple)">← Relatórios</a>
      <div class="hw-avatar w-12 h-12 text-lg"><?= strtoupper(mb_substr($collab['nome'],0,1)) ?></div>
      <div>
        <h1 class="text-xl font-bold text-gray-900"><?= e($collab['nome']) ?></h1>
        <div class="flex items-center gap-2 mt-0.5">
          <?php if ($collab['setor_nome']): ?>
            <span class="hw-setor-badge"><?= e($collab['setor_nome']) ?></span>
          <?php endif; ?>
          <?php if ($salario > 0): ?>
            <span class="text-xs text-gray-400">Salário: <?= fmtBRL($salario) ?> · Hora base: <?= fmtBRL($horaBase) ?>/h</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Filtro de período -->
  <form method="GET" class="flex flex-wrap items-end gap-3" onsubmit="convertDates()">
    <input type="hidden" name="usuario_id" value="<?= $uid ?>">
    <div>
      <label class="block text-xs font-medium text-gray-600 mb-1">De</label>
      <input type="text" id="from_text" placeholder="DD/MM/AAAA" maxlength="10"
        value="<?= date('d/m/Y', strtotime($from)) ?>"
        class="hw-input px-3 py-2 text-sm font-mono w-36" oninput="applyDateMask(this)">
      <input type="hidden" name="from" id="from_iso" value="<?= e($from) ?>">
    </div>
    <div>
      <label class="block text-xs font-medium text-gray-600 mb-1">Até</label>
      <input type="text" id="to_text" placeholder="DD/MM/AAAA" maxlength="10"
        value="<?= date('d/m/Y', strtotime($to)) ?>"
        class="hw-input px-3 py-2 text-sm font-mono w-36" oninput="applyDateMask(this)">
      <input type="hidden" name="to" id="to_iso" value="<?= e($to) ?>">
    </div>
    <button type="submit" class="hw-btn text-sm px-4 py-2">Aplicar</button>
    <?php
    $ranges = [
        'Este mês'        => [date('Y-m-01'), date('Y-m-t')],
        'Mês passado'     => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        'Últimos 3 meses' => [date('Y-m-01', strtotime('-2 months')), date('Y-m-t')],
        'Este ano'        => [date('Y-01-01'), date('Y-12-31')],
    ];
    foreach ($ranges as $rl => [$rf, $rt]):
        $ra = ($from === $rf && $to === $rt);
    ?>
    <a href="?usuario_id=<?= $uid ?>&from=<?= $rf ?>&to=<?= $rt ?>"
       class="px-3 py-2 border rounded-lg text-xs font-medium transition whitespace-nowrap
         <?= $ra ? 'text-white border-transparent' : 'border-gray-300 text-gray-600 hover:bg-white' ?>"
       <?= $ra ? 'style="background:var(--hw-gradient)"' : '' ?>><?= $rl ?></a>
    <?php endforeach; ?>
  </form>

  <!-- KPI Cards -->
  <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-blue">Acionamentos</p>
      <p class="hw-kpi-value hw-kpi-blue"><?= count($lancamentos) ?></p>
      <p class="text-xs text-gray-400 mt-0.5"><?= $cntAprov ?> apr · <?= $cntPend ?> pend</p>
    </div>
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-green">Horas aprovadas</p>
      <p class="hw-kpi-value hw-kpi-green"><?= minutesToHHMM($minsAprov) ?></p>
      <p class="text-xs text-gray-400 mt-0.5"><?= $cntAprov ?> registros</p>
    </div>
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-amber">Horas pendentes</p>
      <p class="hw-kpi-value hw-kpi-amber"><?= minutesToHHMM($minsPend) ?></p>
      <p class="text-xs text-gray-400 mt-0.5"><?= $cntPend ?> registros</p>
    </div>
    <div class="hw-kpi-card">
      <p class="hw-kpi-title" style="color:#6b0fa8">Custo estimado</p>
      <p class="hw-kpi-value-brl" style="color:#6b0fa8"><?= fmtBRL($totalValor) ?></p>
      <p class="text-xs text-gray-400 mt-0.5">aprovados no período</p>
    </div>
    <div class="hw-kpi-card <?= $saldoBH >= 0 ? '' : 'border border-red-200 bg-red-50' ?>">
      <p class="hw-kpi-title <?= $saldoBH >= 0 ? 'hw-kpi-teal' : 'hw-kpi-red' ?>">Saldo BH total</p>
      <p class="hw-kpi-value <?= $saldoBH >= 0 ? 'hw-kpi-teal' : 'hw-kpi-red' ?>"><?= minutesToHHMM(abs($saldoBH)) ?></p>
      <p class="text-xs text-gray-400 mt-0.5"><?= $saldoBH >= 0 ? 'disponível' : 'negativo' ?></p>
    </div>
  </div>

  <?php if (empty($lancamentos)): ?>
  <div class="text-center py-20 text-gray-400">
    <p class="text-sm">Nenhum lançamento no período selecionado.</p>
  </div>
  <?php else: ?>

  <!-- Tendência mensal -->
  <?php if (!empty($trendLabels)): ?>
  <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
    <h3 class="text-sm font-semibold text-gray-700 mb-1">Evolução mensal — últimos 6 meses</h3>
    <p class="text-xs text-gray-400 mb-4">Total de horas por mês</p>
    <canvas id="chartTrend" style="max-height:200px"></canvas>
  </div>
  <?php endif; ?>

  <!-- Breakdown financeiro CLT -->
  <?php if ($cntAprov > 0): ?>
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-5 py-4 border-b" style="background:linear-gradient(135deg,rgba(107,15,168,.06),rgba(232,0,28,.04))">
      <h3 class="font-semibold text-gray-900">Custo estimado CLT — <?= e($collab['nome']) ?></h3>
      <p class="text-xs text-gray-500 mt-0.5">
        Hora base: <?= fmtBRL($horaBase) ?>/h · art. 59 (+50% hora extra) · art. 73 (+20% noturno)
      </p>
    </div>
    <div class="overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-gray-50 border-b border-gray-100">
            <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Tipo</th>
            <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Qtd</th>
            <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Horas</th>
            <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">% Qtd</th>
            <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Adicional CLT</th>
            <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Valor</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($financeiro as $tk => $tf):
            if ($tf['cnt'] === 0) continue;
            $pctQ = $cntAprov > 0 ? round($tf['cnt'] / $cntAprov * 100) : 0;
            $cor  = $corTipo[$tk];
          ?>
          <tr>
            <td class="px-4 py-3 font-medium <?= $cor['text'] ?>"><?= $tf['label'] ?></td>
            <td class="px-4 py-3 text-right text-gray-700"><?= $tf['cnt'] ?></td>
            <td class="px-4 py-3 text-right font-mono text-gray-700"><?= minutesToHHMM($tf['mins']) ?></td>
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
            <td class="px-4 py-3 text-right font-semibold <?= $cor['text'] ?>"><?= fmtBRL($tf['valor']) ?></td>
          </tr>
          <?php endforeach; ?>
          <tr class="bg-gray-50 border-t-2 border-gray-200 font-semibold">
            <td class="px-4 py-3 text-gray-700">Total aprovado</td>
            <td class="px-4 py-3 text-right text-gray-700"><?= $cntAprov ?></td>
            <td class="px-4 py-3 text-right font-mono text-gray-700"><?= minutesToHHMM($minsAprov) ?></td>
            <td class="px-4 py-3 text-right text-gray-600">100%</td>
            <td class="px-4 py-3"></td>
            <td class="px-4 py-3 text-right text-base font-bold" style="color:var(--hw-purple)"><?= fmtBRL($totalValor) ?></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Tabela de lançamentos -->
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-5 py-3 border-b bg-gray-50/50">
      <h3 class="text-sm font-semibold text-gray-700">Todos os lançamentos — <?= fmtDate($from) ?> a <?= fmtDate($to) ?></h3>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="hw-table-head">
          <tr>
            <th class="px-4 py-3 text-left">Data</th>
            <th class="px-4 py-3 text-left">Chamado</th>
            <th class="px-4 py-3 text-left">Horário</th>
            <th class="px-4 py-3 text-left">Duração</th>
            <th class="px-4 py-3 text-left">Tipo</th>
            <th class="px-4 py-3 text-right">Valor est.</th>
            <th class="px-4 py-3 text-left">Status</th>
            <th class="px-4 py-3 text-left">Revisão</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($lancamentos as $l):
            $statusBadge = match($l['status']) {
                'aprovado' => 'hw-badge-aprovado',
                'recusado' => 'hw-badge-recusado',
                default    => 'hw-badge-pendente',
            };
            $statusLabel = match($l['status']) {
                'aprovado' => 'Aprovado',
                'recusado' => 'Recusado',
                default    => 'Pendente',
            };
            $cor  = $corTipo[$l['_tipo']];
            $isFer = in_array((string)($l['feriado'] ?? ''), ['t','1','true'], true);
          ?>
          <tr class="hw-table-row <?= $l['status'] === 'recusado' ? 'opacity-50' : '' ?>">
            <td class="px-4 py-2.5 text-gray-600 whitespace-nowrap">
              <?= fmtDate($l['data_acionamento']) ?>
              <?php if ($isFer): ?>
                <span class="ml-1 text-xs bg-red-100 text-red-700 border border-red-200 px-1.5 py-0.5 rounded-full">Feriado</span>
              <?php endif; ?>
              <?php if (!empty($l['fora_do_prazo']) && in_array((string)$l['fora_do_prazo'], ['t','1','true'], true)): ?>
                <span class="ml-1 text-xs bg-amber-100 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded-full">Fora prazo</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-2.5">
              <span class="font-mono text-xs bg-gray-100 border border-gray-200 text-gray-700 px-2 py-0.5 rounded"><?= e($l['chamado']) ?></span>
            </td>
            <td class="px-4 py-2.5 font-mono text-xs text-gray-600 whitespace-nowrap">
              <?= substr($l['hora_inicio'], 0, 5) ?> → <?= substr($l['hora_fim'], 0, 5) ?>
            </td>
            <td class="px-4 py-2.5 font-semibold text-gray-700"><?= minutesToHHMM((int)$l['total_minutos']) ?></td>
            <td class="px-4 py-2.5">
              <span class="text-xs font-medium px-2 py-0.5 rounded-full <?= $cor['bg'] ?> <?= $cor['text'] ?>"><?= $cor['label'] ?></span>
            </td>
            <td class="px-4 py-2.5 text-right">
              <?php if ($l['status'] !== 'recusado' && $l['_valor'] > 0): ?>
                <span class="font-semibold <?= $l['status'] === 'aprovado' ? $cor['text'] : 'text-gray-400' ?>"><?= fmtBRL($l['_valor']) ?></span>
                <?php if ($l['status'] === 'pendente'): ?>
                  <span class="text-xs text-gray-400 block">estimado</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-gray-300">—</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-2.5"><span class="<?= $statusBadge ?>"><?= $statusLabel ?></span></td>
            <td class="px-4 py-2.5 text-xs text-gray-500">
              <?php if ($l['revisado_em']): ?>
                <?= e($l['revisor_nome'] ?? '—') ?><br>
                <span class="text-gray-400"><?= fmtDt($l['revisado_em']) ?></span>
                <?php if ($l['nota_revisao']): ?>
                  <p class="italic text-gray-400 mt-0.5"><?= e($l['nota_revisao']) ?></p>
                <?php endif; ?>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php endif; ?>
</main>

<script>
function applyDateMask(el) {
  let v = el.value.replace(/\D/g, '');
  if (v.length > 8) v = v.slice(0, 8);
  let out = v.slice(0, 2);
  if (v.length > 2) out += '/' + v.slice(2, 4);
  if (v.length > 4) out += '/' + v.slice(4, 8);
  el.value = out;
}
function convertDates() {
  ['from', 'to'].forEach(p => {
    const m = document.getElementById(p + '_text').value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (m) document.getElementById(p + '_iso').value = `${m[3]}-${m[2]}-${m[1]}`;
  });
}

<?php if (!empty($trendLabels)): ?>
Chart.defaults.font.family = "'Inter', 'Segoe UI', system-ui, sans-serif";
Chart.defaults.font.size   = 12;
new Chart(document.getElementById('chartTrend'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($trendLabels) ?>,
    datasets: [{
      label: 'Horas',
      data: <?= json_encode($trendMins) ?>,
      backgroundColor: 'rgba(107,15,168,.25)',
      borderColor: '#6b0fa8',
      borderWidth: 2,
      borderRadius: 6,
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: ctx => {
            const t = Math.round(ctx.raw * 60);
            return ` ${String(Math.floor(t/60)).padStart(2,'0')}:${String(t%60).padStart(2,'0')} horas`;
          }
        }
      }
    },
    scales: {
      x: { grid: { display: false } },
      y: {
        beginAtZero: true,
        ticks: {
          callback: v => {
            const t = Math.round(v * 60);
            return `${String(Math.floor(t/60)).padStart(2,'0')}:${String(t%60).padStart(2,'0')}`;
          }
        }
      }
    }
  }
});
<?php endif; ?>
</script>
</body></html>
