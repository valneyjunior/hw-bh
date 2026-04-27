<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireLogin();
$db   = getDb();
$uid  = $user['id'];

$validated = $db->prepare("SELECT * FROM records WHERE user_id = ? AND validated_at IS NOT NULL");
$validated->execute([$uid]);
$validatedRecs = $validated->fetchAll();

$totalValMins = 0;
foreach ($validatedRecs as $r)
    $totalValMins += (strtotime($r['ended_at']) - strtotime($r['started_at'])) / 60;

$stmtDed = $db->prepare("SELECT COALESCE(SUM(requested_minutes),0) FROM bh_requests WHERE user_id=? AND status='approved'");
$stmtDed->execute([$uid]);
$deducted    = (int)$stmtDed->fetchColumn();
$balanceMins = $totalValMins - $deducted;

$stmtSchedule = $db->prepare("SELECT work_start, work_end FROM collaborator_salary WHERE user_id = ?");
$stmtSchedule->execute([$uid]);
$schedule  = $stmtSchedule->fetch();
$workStart = $schedule ? substr($schedule['work_start'], 0, 5) : '08:00';
$workEnd   = $schedule ? substr($schedule['work_end'],   0, 5) : '18:00';

$reqs = $db->prepare("
    SELECT r.*, u.name AS reviewer_name
    FROM bh_requests r LEFT JOIN users u ON u.id = r.reviewed_by
    WHERE r.user_id = ? ORDER BY r.created_at DESC
");
$reqs->execute([$uid]);
$myRequests = $reqs->fetchAll();

$stmtPending = $db->prepare("
    SELECT * FROM records
    WHERE user_id = ? AND validated_at IS NULL AND rejected_at IS NULL
    ORDER BY started_at DESC
");
$stmtPending->execute([$uid]);
$pendingRecs = $stmtPending->fetchAll();

$stmtRejected = $db->prepare("
    SELECT * FROM records
    WHERE user_id = ? AND rejected_at IS NOT NULL
    ORDER BY rejected_at DESC
    LIMIT 10
");
$stmtRejected->execute([$uid]);
$rejectedRecs = $stmtRejected->fetchAll();

$ptLabels = [
    'full'            => 'Dia inteiro',
    'half_morning'    => 'Meio período — Manhã',
    'half_afternoon'  => 'Meio período — Tarde',
    'custom'          => 'Personalizado',
    'admin_deduction' => 'Dedução por atraso',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Banco de Horas</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
.flatpickr-day.selected,.flatpickr-day.selected:hover{background:var(--hw-purple)!important;border-color:var(--hw-purple)!important;color:#fff!important}
.flatpickr-day.today{border-color:var(--hw-purple)}
.flatpickr-day:hover{background:rgba(107,15,168,.08)}
.flatpickr-months .flatpickr-prev-month:hover svg,.flatpickr-months .flatpickr-next-month:hover svg{fill:var(--hw-purple)}
</style>
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-3xl mx-auto px-4 py-6 space-y-6">

  <div class="flex items-center justify-between">
    <h1 class="text-xl font-bold text-gray-900">Banco de Horas</h1>
    <a href="/dashboard.php" class="text-sm hover:underline" style="color:var(--hw-purple)">← Meus registros</a>
  </div>

  <!-- Cards de saldo -->
  <div class="grid grid-cols-3 gap-4">
    <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
      <p class="text-xs text-gray-400 mb-1">Total validado</p>
      <p class="text-2xl font-bold text-gray-800"><?= minutesToHHMM($totalValMins) ?></p>
    </div>
    <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
      <p class="text-xs text-gray-400 mb-1">Deduções (folgas)</p>
      <p class="text-2xl font-bold text-red-500">-<?= minutesToHHMM((int)$deducted) ?></p>
    </div>
    <div class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
      <p class="text-xs text-gray-400 mb-1">Saldo disponível</p>
      <p class="text-2xl font-bold <?= $balanceMins >= 0 ? 'text-green-600' : 'text-red-600' ?>">
        <?= minutesToHHMM(max(0, $balanceMins)) ?>
      </p>
    </div>
  </div>

  <!-- Registros pendentes de validação -->
  <?php if (!empty($pendingRecs)): ?>
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-5 py-3 border-b border-amber-100 flex items-center gap-2" style="background:rgba(245,158,11,.06)">
      <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <p class="text-xs font-semibold text-amber-700 uppercase tracking-wide">
        Aguardando validação (<?= count($pendingRecs) ?>)
      </p>
    </div>
    <div class="divide-y divide-gray-100">
      <?php foreach ($pendingRecs as $r): ?>
        <div class="px-5 py-3 flex items-center gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-mono text-xs bg-amber-50 border border-amber-200 text-amber-800 px-2 py-0.5 rounded"><?= e($r['ticket']) ?></span>
              <span class="text-xs text-gray-500"><?= fmtDt($r['started_at']) ?> → <?= fmtDt($r['ended_at']) ?></span>
              <span class="text-xs font-semibold text-gray-700"><?= fmtDuration($r['started_at'], $r['ended_at']) ?></span>
            </div>
            <p class="text-sm text-gray-600 mt-0.5 truncate"><?= e($r['description']) ?></p>
          </div>
          <span class="text-xs font-medium px-2 py-0.5 rounded-full border bg-amber-50 border-amber-200 text-amber-700 shrink-0">Pendente</span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Registros recusados recentes -->
  <?php if (!empty($rejectedRecs)): ?>
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-5 py-3 border-b border-red-100 flex items-center gap-2" style="background:rgba(232,0,28,.04)">
      <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
      </svg>
      <p class="text-xs font-semibold uppercase tracking-wide" style="color:var(--hw-red)">
        Recusados (<?= count($rejectedRecs) ?>)
      </p>
    </div>
    <div class="divide-y divide-gray-100">
      <?php foreach ($rejectedRecs as $r): ?>
        <div class="px-5 py-3">
          <div class="flex flex-wrap items-center gap-2 mb-0.5">
            <span class="font-mono text-xs bg-red-50 border border-red-200 text-red-800 px-2 py-0.5 rounded"><?= e($r['ticket']) ?></span>
            <span class="text-xs text-gray-500"><?= fmtDt($r['started_at']) ?> → <?= fmtDt($r['ended_at']) ?></span>
          </div>
          <?php if ($r['reject_reason']): ?>
            <p class="text-xs text-red-600 mt-0.5">Motivo: <?= e($r['reject_reason']) ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Nova solicitação -->
  <?php if ($balanceMins > 0): ?>
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-5 py-4 border-b" style="background:linear-gradient(135deg,rgba(232,0,28,.04),rgba(107,15,168,.04))">
      <h2 class="font-semibold text-gray-900">Solicitar uso de banco de horas</h2>
      <p class="text-xs text-gray-400 mt-0.5">Saldo disponível: <?= minutesToHHMM($balanceMins) ?> · Jornada: <?= $workStart ?> — <?= $workEnd ?></p>
    </div>
    <form id="req-form" class="p-5 space-y-4">

      <!-- Tipo de período -->
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-2">Tipo de período</label>
        <div class="grid grid-cols-3 gap-2">
          <?php
            $cards = [
              ['full',   'Dia inteiro',  'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
              ['half',   'Meio período', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
              ['custom', 'Personalizado','M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ];
          ?>
          <?php foreach ($cards as [$val, $lbl, $icon]): ?>
          <label id="pcard-<?= $val ?>" class="flex flex-col items-center gap-1.5 p-3 rounded-xl border-2 cursor-pointer transition text-center"
            style="<?= $val==='full' ? 'border-color:var(--hw-purple);background:rgba(107,15,168,.04)' : 'border-color:#e5e7eb' ?>">
            <input type="radio" name="period_type" value="<?= $val ?>" <?= $val==='full'?'checked':'' ?> class="sr-only">
            <svg class="w-5 h-5" style="color:<?= $val==='full'?'var(--hw-purple)':'#9ca3af' ?>" id="picon-<?= $val ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $icon ?>"/>
            </svg>
            <span class="text-xs font-medium" id="ptxt-<?= $val ?>" style="color:<?= $val==='full'?'var(--hw-purple)':'#6b7280' ?>"><?= $lbl ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Data da folga (Dia inteiro / Meio período) -->
      <div id="single-date-section">
        <label class="block text-xs font-medium text-gray-700 mb-1">Data da folga</label>
        <input type="text" id="req-date" placeholder="Selecione a data" readonly
          class="hw-input px-3 py-2 text-sm cursor-pointer w-44">
        <input type="hidden" id="req-date-iso">
      </div>

      <!-- Meio período: manhã ou tarde -->
      <div id="half-section" class="hidden">
        <label class="block text-xs font-medium text-gray-700 mb-2">Qual período?</label>
        <div class="grid grid-cols-2 gap-2">
          <?php foreach (['morning'=>'Manhã','afternoon'=>'Tarde'] as $hval=>$hlbl): ?>
          <label id="hcard-<?= $hval ?>" class="flex items-center justify-center gap-2 p-3 rounded-xl border-2 cursor-pointer transition"
            style="<?= $hval==='morning'?'border-color:var(--hw-purple);background:rgba(107,15,168,.04)':'border-color:#e5e7eb' ?>">
            <input type="radio" name="half_type" value="<?= $hval ?>" <?= $hval==='morning'?'checked':'' ?> class="sr-only">
            <span class="text-sm font-medium" id="htxt-<?= $hval ?>" style="color:<?= $hval==='morning'?'var(--hw-purple)':'#6b7280' ?>"><?= $hlbl ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Personalizado: intervalo de datas + horários -->
      <div id="custom-section" class="hidden space-y-3">
        <div class="bg-gray-50 rounded-xl p-4 space-y-2">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Início</p>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-500 mb-1 block">Data</label>
              <input type="text" id="custom-date-start" placeholder="Selecione" readonly
                class="hw-input px-3 py-2 text-sm cursor-pointer w-full">
              <input type="hidden" id="custom-date-start-iso">
            </div>
            <div>
              <label class="text-xs text-gray-500 mb-1 block">Das</label>
              <input type="time" id="custom-time-start" class="hw-input px-3 py-2 text-sm w-full">
            </div>
          </div>
        </div>
        <div class="bg-gray-50 rounded-xl p-4 space-y-2">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Término</p>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-gray-500 mb-1 block">Data</label>
              <input type="text" id="custom-date-end" placeholder="Selecione" readonly
                class="hw-input px-3 py-2 text-sm cursor-pointer w-full">
              <input type="hidden" id="custom-date-end-iso">
            </div>
            <div>
              <label class="text-xs text-gray-500 mb-1 block">Às</label>
              <input type="time" id="custom-time-end" class="hw-input px-3 py-2 text-sm w-full">
            </div>
          </div>
        </div>
      </div>

      <!-- Resumo dinâmico -->
      <div id="req-summary" class="hidden rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-4 py-2 bg-gray-50 border-b border-gray-200">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Resumo da solicitação</p>
        </div>
        <div class="px-4 py-3 space-y-1.5 text-sm">
          <div class="flex justify-between">
            <span class="text-gray-500">Data</span>
            <span id="sum-date" class="font-medium text-gray-800 text-right">—</span>
          </div>
          <div id="sum-days-row" class="hidden flex justify-between">
            <span class="text-gray-500">Dias</span>
            <span id="sum-days" class="font-medium text-gray-800">—</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-500">Período</span>
            <span id="sum-period" class="font-medium text-gray-800 text-right">—</span>
          </div>
          <div class="flex justify-between border-t border-gray-100 pt-1.5 mt-0.5">
            <span class="font-medium text-gray-600">Total a deduzir</span>
            <span id="sum-hours" class="font-bold" style="color:var(--hw-red)">—</span>
          </div>
        </div>
      </div>

      <!-- Motivo (obrigatório) -->
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">
          Motivo <span style="color:var(--hw-red)">*</span>
        </label>
        <textarea id="req-reason" rows="2" placeholder="Ex: Compensação de acionamento em março"
          class="hw-input px-3 py-2 text-sm resize-none"></textarea>
      </div>

      <div id="req-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>
      <button type="submit" id="req-btn" class="hw-btn w-full py-2.5 text-sm justify-center">
        Confirmar solicitação
      </button>
    </form>
  </div>
  <?php else: ?>
  <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 text-sm text-amber-700 text-center">
    Sem saldo disponível para solicitar folga no momento.
  </div>
  <?php endif; ?>

  <!-- Histórico -->
  <?php if (!empty($myRequests)): ?>
  <section>
    <h2 class="text-sm font-semibold text-gray-700 mb-3">Histórico de solicitações</h2>
    <div class="space-y-2">
      <?php foreach ($myRequests as $r):
        $statusMap = [
          'pending'  => ['Aguardando aprovação', 'bg-amber-50 border-amber-200 text-amber-700'],
          'approved' => ['Aprovado', 'bg-green-50 border-green-200 text-green-700'],
          'rejected' => ['Rejeitado', 'bg-red-50 border-red-200 text-red-600'],
        ];
        [$slabel, $scls] = $statusMap[$r['status']];
        $rh = intdiv($r['requested_minutes'], 60);
        $rm = $r['requested_minutes'] % 60;

        // Build date label
        $dateLabel = '';
        if ($r['request_date']) {
            $dateLabel = date('d/m/Y', strtotime($r['request_date']));
            if (!empty($r['request_date_end']) && $r['request_date_end'] !== $r['request_date'])
                $dateLabel .= ' a ' . date('d/m/Y', strtotime($r['request_date_end']));
        }
        $ptLabel = $ptLabels[$r['period_type'] ?? ''] ?? '';
      ?>
        <?php $isAdminDed = ($r['period_type'] ?? '') === 'admin_deduction'; ?>
        <div class="border rounded-xl p-4 <?= $isAdminDed ? 'bg-red-50 border-red-200' : 'bg-white border-gray-200' ?>" style="box-shadow:0 2px 8px rgba(0,0,0,.06)">
          <div class="flex items-start justify-between gap-3">
            <div class="flex-1">
              <div class="flex flex-wrap items-center gap-2 mb-1">
                <span class="font-semibold <?= $isAdminDed ? 'text-red-700' : 'text-gray-800' ?>"><?= $isAdminDed ? '-' : '' ?><?= sprintf('%02d:%02d', $rh, $rm) ?> h</span>
                <?php if (!$isAdminDed): ?>
                <span class="text-xs px-2 py-0.5 rounded-full border font-medium <?= $scls ?>"><?= $slabel ?></span>
                <?php endif; ?>
                <?php if ($dateLabel): ?>
                  <span class="text-xs text-gray-500"><?= e($dateLabel) ?></span>
                <?php endif; ?>
                <?php if ($ptLabel): ?>
                  <span class="text-xs px-2 py-0.5 rounded-full bg-purple-50 border border-purple-100 text-purple-700 font-medium"><?= e($ptLabel) ?></span>
                <?php endif; ?>
                <span class="text-xs text-gray-400"><?= fmtDt($r['created_at']) ?></span>
              </div>
              <?php if ($r['reason']): ?>
                <p class="text-sm text-gray-600"><?= e($r['reason']) ?></p>
              <?php endif; ?>
              <?php if ($r['review_note']): ?>
                <p class="text-xs text-gray-400 mt-0.5 italic">Resposta: <?= e($r['review_note']) ?></p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
const workStart   = '<?= $workStart ?>';
const workEnd     = '<?= $workEnd ?>';
const balanceMins = <?= (int)$balanceMins ?>;

function timeToMins(t) {
  const [h, m] = t.split(':').map(Number);
  return h * 60 + m;
}
function minsToHHMM(m) {
  return String(Math.floor(m / 60)).padStart(2,'0') + ':' + String(m % 60).padStart(2,'0');
}
function dateToISO(d) {
  return d.getFullYear() + '-' +
    String(d.getMonth()+1).padStart(2,'0') + '-' +
    String(d.getDate()).padStart(2,'0');
}
function formatDate(iso) {
  const [y, m, d] = iso.split('-');
  return `${d}/${m}/${y}`;
}

const ptLocale = {
  months: {
    longhand: ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'],
    shorthand: ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez']
  },
  weekdays: {
    longhand: ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'],
    shorthand: ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb']
  },
  firstDayOfWeek: 0
};

const fpBase = {
  dateFormat: 'Y-m-d',
  altInput: true,
  altFormat: 'd/m/Y',
  locale: ptLocale,
  disableMobile: false,
};

// Single date picker (Dia inteiro / Meio período)
const fpSingle = flatpickr('#req-date', {
  ...fpBase,
  onChange: ([date]) => {
    document.getElementById('req-date-iso').value = date ? dateToISO(date) : '';
    updateSummary();
  }
});

// Custom range pickers
let fpEnd;
const fpStart = flatpickr('#custom-date-start', {
  ...fpBase,
  onChange: ([date]) => {
    const iso = date ? dateToISO(date) : '';
    document.getElementById('custom-date-start-iso').value = iso;
    if (fpEnd && iso) fpEnd.set('minDate', iso);
    updateSummary();
  }
});
fpEnd = flatpickr('#custom-date-end', {
  ...fpBase,
  onChange: ([date]) => {
    document.getElementById('custom-date-end-iso').value = date ? dateToISO(date) : '';
    updateSummary();
  }
});

// Fix Flatpickr altInput to inherit hw-input styling
document.querySelectorAll('.flatpickr-input.form-control').forEach(el => {
  el.classList.add('hw-input','px-3','py-2','text-sm');
});

// ── Period info calculation ───────────────────────────────────────────────────
function getPeriodInfo() {
  const pt       = document.querySelector('input[name="period_type"]:checked')?.value;
  const wsM      = timeToMins(workStart);
  const weM      = timeToMins(workEnd);
  const totalW   = weM - wsM;
  const LUNCH    = 120;
  const workMins = totalW - LUNCH;
  const halfM    = Math.floor(workMins / 2);
  const morningEnd  = minsToHHMM(wsM + halfM);          // ex: 12:00
  const afternoonSt = minsToHHMM(wsM + halfM + LUNCH);  // ex: 14:00

  if (pt === 'full') {
    const dateIso = document.getElementById('req-date-iso').value;
    if (!dateIso) return null;
    return { dateLabel: formatDate(dateIso), periodLabel: `Dia inteiro · ${workStart} — ${workEnd} (−2h almoço)`,
             mins: workMins, days: 1, periodType: 'full', requestDate: dateIso };
  }

  if (pt === 'half') {
    const dateIso = document.getElementById('req-date-iso').value;
    if (!dateIso) return null;
    const ht = document.querySelector('input[name="half_type"]:checked')?.value;
    if (ht === 'morning')
      return { dateLabel: formatDate(dateIso), periodLabel: `Manhã · ${workStart} — ${morningEnd}`,
               mins: halfM, days: 1, periodType: 'half_morning', requestDate: dateIso };
    return { dateLabel: formatDate(dateIso), periodLabel: `Tarde · ${afternoonSt} — ${workEnd}`,
             mins: workMins - halfM, days: 1, periodType: 'half_afternoon', requestDate: dateIso };
  }

  if (pt === 'custom') {
    const startIso  = document.getElementById('custom-date-start-iso').value;
    const endIso    = document.getElementById('custom-date-end-iso').value;
    const startTime = document.getElementById('custom-time-start').value;
    const endTime   = document.getElementById('custom-time-end').value;
    if (!startIso || !endIso || !startTime || !endTime) return null;
    const csM = timeToMins(startTime), ceM = timeToMins(endTime);
    if (ceM <= csM) return null;
    const minsPerDay = ceM - csM;
    const days = Math.round((new Date(endIso+'T00:00:00') - new Date(startIso+'T00:00:00')) / 86400000) + 1;
    const totalMins  = days * minsPerDay;
    const dateLabel  = startIso === endIso ? formatDate(startIso) : `${formatDate(startIso)} a ${formatDate(endIso)}`;
    return { dateLabel, periodLabel: `${startTime} — ${endTime}`,
             mins: totalMins, days, periodType: 'custom',
             requestDate: startIso,
             requestDateEnd: startIso !== endIso ? endIso : null };
  }
  return null;
}

function updateSummary() {
  const el = document.getElementById('req-summary');
  if (!el) return;
  const info = getPeriodInfo();
  if (!info) { el.classList.add('hidden'); return; }
  document.getElementById('sum-date').textContent   = info.dateLabel;
  document.getElementById('sum-period').textContent = info.periodLabel;
  document.getElementById('sum-hours').textContent  = minsToHHMM(info.mins) + ' h';
  const daysRow = document.getElementById('sum-days-row');
  if (info.days > 1) {
    document.getElementById('sum-days').textContent = `${info.days} dias × ${minsToHHMM(info.mins / info.days)}`;
    daysRow.classList.remove('hidden');
  } else {
    daysRow.classList.add('hidden');
  }
  el.classList.remove('hidden');
}

// ── Card styling helpers ──────────────────────────────────────────────────────
function setPeriodCard(val) {
  ['full','half','custom'].forEach(v => {
    const active = v === val;
    document.getElementById('pcard-' + v).style.borderColor = active ? 'var(--hw-purple)' : '#e5e7eb';
    document.getElementById('pcard-' + v).style.background  = active ? 'rgba(107,15,168,.04)' : '';
    document.getElementById('picon-' + v).style.color       = active ? 'var(--hw-purple)' : '#9ca3af';
    document.getElementById('ptxt-'  + v).style.color       = active ? 'var(--hw-purple)' : '#6b7280';
  });
  const isCustom = val === 'custom';
  document.getElementById('single-date-section').classList.toggle('hidden', isCustom);
  document.getElementById('half-section').classList.toggle('hidden', val !== 'half');
  document.getElementById('custom-section').classList.toggle('hidden', !isCustom);
  updateSummary();
}

document.querySelectorAll('input[name="period_type"]').forEach(r => {
  r.addEventListener('change', () => setPeriodCard(r.value));
});

function setHalfCard(val) {
  ['morning','afternoon'].forEach(v => {
    const active = v === val;
    document.getElementById('hcard-' + v).style.borderColor = active ? 'var(--hw-purple)' : '#e5e7eb';
    document.getElementById('hcard-' + v).style.background  = active ? 'rgba(107,15,168,.04)' : '';
    document.getElementById('htxt-'  + v).style.color       = active ? 'var(--hw-purple)' : '#6b7280';
  });
  updateSummary();
}

document.querySelectorAll('input[name="half_type"]').forEach(r => {
  r.addEventListener('change', () => setHalfCard(r.value));
});

['custom-time-start','custom-time-end'].forEach(id => {
  document.getElementById(id)?.addEventListener('change', updateSummary);
});

// ── Submit ────────────────────────────────────────────────────────────────────
document.getElementById('req-form')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const errEl  = document.getElementById('req-error');
  errEl.classList.add('hidden');

  const info = getPeriodInfo();
  if (!info) {
    errEl.textContent = 'Selecione a data e o período corretamente.';
    errEl.classList.remove('hidden'); return;
  }

  const reason = document.getElementById('req-reason').value.trim();
  if (!reason) {
    errEl.textContent = 'O motivo é obrigatório para concluir a solicitação.';
    errEl.classList.remove('hidden');
    document.getElementById('req-reason').focus();
    return;
  }

  if (info.mins > balanceMins) {
    errEl.textContent = `Saldo insuficiente. Disponível: ${minsToHHMM(balanceMins)}.`;
    errEl.classList.remove('hidden'); return;
  }

  const btn = document.getElementById('req-btn');
  btn.disabled = true; btn.textContent = 'Enviando…';

  const payload = {
    action:           'create',
    request_date:     info.requestDate,
    request_date_end: info.requestDateEnd || null,
    period_type:      info.periodType,
    reason,
  };
  if (info.periodType === 'custom') {
    payload.custom_start = document.getElementById('custom-time-start').value;
    payload.custom_end   = document.getElementById('custom-time-end').value;
  }

  try {
    const res  = await fetch('/api/bh-requests.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    let data = {};
    try { data = await res.json(); } catch (_) {}
    if (!res.ok) {
      errEl.textContent = data.error ?? `Erro ${res.status} ao enviar. Verifique se a migration foi aplicada.`;
      errEl.classList.remove('hidden');
      btn.disabled = false; btn.textContent = 'Confirmar solicitação';
      return;
    }
    location.reload();
  } catch (err) {
    errEl.textContent = 'Erro de conexão. Tente novamente.';
    errEl.classList.remove('hidden');
    btn.disabled = false; btn.textContent = 'Confirmar solicitação';
  }
});
</script>
</body></html>
