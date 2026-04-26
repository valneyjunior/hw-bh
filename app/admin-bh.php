<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$admin = requireAdmin();
$db    = getDb();
$tab   = $_GET['tab'] ?? 'solicitacoes';

// ── Dados de solicitações (exclui deduções administrativas) ─────────────────
$filter = $_GET['status'] ?? 'pending';
$validFilters = ['pending','approved','rejected','all'];
if (!in_array($filter, $validFilters, true)) $filter = 'pending';

$baseSQL = "SELECT r.*, u.name AS user_name, rv.name AS reviewer_name
            FROM bh_requests r
            JOIN users u ON u.id = r.user_id
            LEFT JOIN users rv ON rv.id = r.reviewed_by
            WHERE (r.period_type IS NULL OR r.period_type != 'admin_deduction')";

if ($filter !== 'all') {
    $stmt = $db->prepare($baseSQL . " AND r.status = ? ORDER BY r.created_at DESC");
    $stmt->execute([$filter]);
} else {
    $stmt = $db->prepare($baseSQL . " ORDER BY r.created_at DESC");
    $stmt->execute();
}
$requests = $stmt->fetchAll();

$pendingCount = $db->query(
    "SELECT COUNT(*) FROM bh_requests
     WHERE status='pending' AND (period_type IS NULL OR period_type != 'admin_deduction')"
)->fetchColumn();

// ── Dados financeiros ───────────────────────────────────────────────────────
$collaborators = $db->query("
    SELECT u.id, u.name, u.email,
           COALESCE(s.monthly_salary, 0)       AS monthly_salary,
           COALESCE(s.work_start, '08:00:00')  AS work_start,
           COALESCE(s.work_end,   '18:00:00')  AS work_end,
           s.updated_at AS salary_updated
    FROM users u
    LEFT JOIN collaborator_salary s ON s.user_id = u.id
    WHERE u.role = 'collaborator' AND u.active = 1
    ORDER BY u.name
")->fetchAll();

$financials = [];
foreach ($collaborators as $c) {
    $recs = $db->prepare("SELECT * FROM records WHERE user_id = ? AND validated_at IS NOT NULL");
    $recs->execute([$c['id']]);
    $validated = $recs->fetchAll();

    $stmtDed = $db->prepare("SELECT COALESCE(SUM(requested_minutes),0) FROM bh_requests WHERE user_id=? AND status='approved'");
    $stmtDed->execute([$c['id']]);
    $deducted = (int)$stmtDed->fetchColumn();

    $calc = calcBhValue($validated, (float)$c['monthly_salary']);

    $totalValMins = 0;
    foreach ($validated as $r) {
        $totalValMins += (strtotime($r['ended_at']) - strtotime($r['started_at'])) / 60;
    }
    $balanceMins = $totalValMins - $deducted;

    $financials[$c['id']] = [
        'user'        => $c,
        'calc'        => $calc,
        'totalValMins'=> $totalValMins,
        'deductedMins'=> (int)$deducted,
        'balanceMins' => $balanceMins,
        'records'     => count($validated),
    ];
}

// ── Histórico de deduções administrativas ───────────────────────────────────
$adminDeductions = $db->query("
    SELECT r.*, u.name AS user_name, a.name AS admin_name
    FROM bh_requests r
    JOIN users u ON u.id = r.user_id
    LEFT JOIN users a ON a.id = r.reviewed_by
    WHERE r.period_type = 'admin_deduction'
    ORDER BY r.created_at DESC
    LIMIT 100
")->fetchAll();

// Balances as JS object for client-side preview
$balancesJs = [];
foreach ($financials as $uid => $f) $balancesJs[$uid] = (int)$f['balanceMins'];
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
</style>
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-5xl mx-auto px-4 py-6">

  <!-- Tabs -->
  <div class="flex gap-1 border-b border-gray-200 mb-6">
    <?php foreach (['solicitacoes'=>'Solicitações de Folga', 'financeiro'=>'Valor Financeiro', 'deducoes'=>'Deduções'] as $k=>$label): ?>
      <a href="?tab=<?= $k ?>"
        class="px-5 py-2.5 text-sm font-medium rounded-t-lg transition
          <?= $tab===$k ? 'bg-white border border-b-white border-gray-200 -mb-px font-semibold' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100' ?>"
        <?= $tab===$k ? 'style="color:var(--hw-red)"' : '' ?>>
        <?= $label ?>
        <?php if ($k==='solicitacoes' && $pendingCount > 0): ?>
          <span class="ml-1.5 text-xs font-semibold px-1.5 py-0.5 rounded-full hw-badge"><?= $pendingCount ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($tab === 'solicitacoes'): ?>
  <!-- ── SOLICITAÇÕES ─────────────────────────────────────────────────────── -->

    <div class="flex gap-2 mb-5 text-xs">
      <?php foreach (['pending'=>'Pendentes','approved'=>'Aprovadas','rejected'=>'Rejeitadas','all'=>'Todas'] as $k=>$l): ?>
        <a href="?tab=solicitacoes&status=<?= $k ?>"
          class="px-3 py-1.5 rounded-full border transition font-medium <?= $filter===$k ? 'text-white border-transparent' : 'border-gray-300 text-gray-600 hover:bg-gray-50' ?>"
          <?= $filter===$k ? 'style="background:var(--hw-gradient)"' : '' ?>>
          <?= $l ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($requests)): ?>
      <div class="text-center py-20 text-gray-400"><p>Nenhuma solicitação <?= $filter !== 'all' ? 'com este status' : '' ?>.</p></div>
    <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($requests as $req):
          $statusMap = [
            'pending'  => ['label'=>'Pendente',  'cls'=>'bg-amber-100 text-amber-700 border-amber-200'],
            'approved' => ['label'=>'Aprovado',  'cls'=>'bg-green-100 text-green-700 border-green-200'],
            'rejected' => ['label'=>'Rejeitado', 'cls'=>'bg-red-100 text-red-600 border-red-200'],
          ];
          $s = $statusMap[$req['status']];
          $h = intdiv($req['requested_minutes'], 60);
          $m = $req['requested_minutes'] % 60;
        ?>
          <div id="req-<?= $req['id'] ?>" class="bg-white rounded-2xl p-5" style="box-shadow:var(--hw-shadow)">
            <div class="flex items-start justify-between gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                  <div class="hw-avatar w-7 h-7 text-xs shrink-0">
                    <?= strtoupper(mb_substr($req['user_name'],0,1)) ?>
                  </div>
                  <span class="font-semibold text-gray-800"><?= e($req['user_name']) ?></span>
                  <span class="text-xs px-2 py-0.5 rounded-full border font-medium <?= $s['cls'] ?>"><?= $s['label'] ?></span>
                  <span class="text-xs text-gray-400"><?= fmtDt($req['created_at']) ?></span>
                </div>
                <?php
                  $ptMap = ['full'=>'Dia inteiro','half_morning'=>'Manhã','half_afternoon'=>'Tarde','custom'=>'Personalizado'];
                  $dateDisplay = '';
                  if ($req['request_date']) {
                      $dateDisplay = date('d/m/Y', strtotime($req['request_date']));
                      if (!empty($req['request_date_end']) && $req['request_date_end'] !== $req['request_date'])
                          $dateDisplay .= ' a ' . date('d/m/Y', strtotime($req['request_date_end']));
                  }
                ?>
                <div class="flex flex-wrap items-center gap-2 mt-1">
                  <span class="text-sm font-bold text-gray-900"><?= sprintf('%02d:%02d', $h, $m) ?> horas solicitadas</span>
                  <?php if ($dateDisplay): ?>
                    <span class="text-xs text-gray-500"><?= e($dateDisplay) ?></span>
                  <?php endif; ?>
                  <?php if ($req['period_type']): ?>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-purple-50 border border-purple-100 text-purple-700 font-medium"><?= $ptMap[$req['period_type']] ?? e($req['period_type']) ?></span>
                  <?php endif; ?>
                </div>
                <?php if ($req['reason']): ?>
                  <p class="text-sm text-gray-600 mt-1"><?= e($req['reason']) ?></p>
                <?php endif; ?>
                <?php if ($req['review_note']): ?>
                  <p class="text-xs text-gray-400 mt-1 italic">Nota: <?= e($req['review_note']) ?></p>
                <?php endif; ?>
                <?php if ($req['reviewed_at']): ?>
                  <p class="text-xs text-gray-400 mt-0.5">
                    <?= $req['status']==='approved'?'Aprovado':'Rejeitado' ?> por <?= e($req['reviewer_name'] ?? '—') ?> em <?= fmtDt($req['reviewed_at']) ?>
                  </p>
                <?php endif; ?>
              </div>

              <?php if ($req['status'] === 'pending'): ?>
                <div class="flex flex-col gap-2 shrink-0">
                  <button onclick="review('<?= $req['id'] ?>', 'approved')"
                    class="bg-green-600 hover:bg-green-700 text-white text-xs font-medium px-4 py-2 rounded-lg transition">
                    Aprovar
                  </button>
                  <button onclick="review('<?= $req['id'] ?>', 'rejected')"
                    class="border border-red-300 text-red-600 hover:bg-red-50 text-xs font-medium px-4 py-2 rounded-lg transition">
                    Rejeitar
                  </button>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'financeiro'): ?>
  <!-- ── FINANCEIRO ──────────────────────────────────────────────────────── -->

    <div class="space-y-4">
      <?php foreach ($financials as $uid => $f):
        $c    = $f['user'];
        $calc = $f['calc'];
        $bal  = $f['balanceMins'];
        $hasSalary = $c['monthly_salary'] > 0;
      ?>
        <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
          <!-- Header -->
          <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100" style="background:linear-gradient(135deg,rgba(232,0,28,.04),rgba(107,15,168,.04))">
            <div class="flex items-center gap-3">
              <div class="hw-avatar w-9 h-9 text-sm">
                <?= strtoupper(mb_substr($c['name'],0,1)) ?>
              </div>
              <div>
                <p class="font-semibold text-gray-800"><?= e($c['name']) ?></p>
                <p class="text-xs text-gray-400"><?= e($c['email']) ?></p>
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-xs text-gray-500">Entrada:</span>
              <input type="time" id="ws-<?= $uid ?>" value="<?= substr($c['work_start'],0,5) ?>"
                class="hw-input px-2 py-1.5 text-sm w-24">
              <span class="text-xs text-gray-500">Saída:</span>
              <input type="time" id="we-<?= $uid ?>" value="<?= substr($c['work_end'],0,5) ?>"
                class="hw-input px-2 py-1.5 text-sm w-24">
              <span class="text-xs text-gray-500">Salário:</span>
              <div class="flex items-center border border-gray-300 rounded-lg overflow-hidden">
                <span class="bg-gray-50 px-2 py-1.5 text-xs text-gray-500 border-r border-gray-300">R$</span>
                <input type="number" id="sal-<?= $uid ?>" value="<?= number_format((float)$c['monthly_salary'],2,'.','') ?>"
                  step="0.01" min="0" placeholder="0,00"
                  class="w-28 px-2 py-1.5 text-sm focus:outline-none">
              </div>
              <button onclick="saveSalary('<?= $uid ?>')" class="hw-btn text-xs px-3 py-1.5">
                Salvar
              </button>
            </div>
          </div>

          <!-- Stats -->
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-0 divide-x divide-y sm:divide-y-0 divide-gray-100">
            <div class="px-5 py-4">
              <p class="text-xs text-gray-400 mb-0.5">Horas validadas</p>
              <p class="text-lg font-bold text-gray-800"><?= minutesToHHMM($f['totalValMins']) ?></p>
            </div>
            <div class="px-5 py-4">
              <p class="text-xs text-gray-400 mb-0.5">Deduções aprovadas</p>
              <p class="text-lg font-bold text-red-500">-<?= minutesToHHMM($f['deductedMins']) ?></p>
            </div>
            <div class="px-5 py-4">
              <p class="text-xs text-gray-400 mb-0.5">Saldo disponível</p>
              <p class="text-lg font-bold <?= $bal >= 0 ? 'text-green-600' : 'text-red-600' ?>"><?= minutesToHHMM(max(0,$bal)) ?></p>
            </div>
            <div class="px-5 py-4">
              <p class="text-xs text-gray-400 mb-0.5">Valor do banco</p>
              <p class="text-lg font-bold <?= $hasSalary ? '' : 'text-gray-300' ?>"
                 <?= $hasSalary ? 'style="color:var(--hw-purple)"' : '' ?>>
                <?= $hasSalary ? 'R$ ' . number_format($calc['totalValue'], 2, ',', '.') : '—' ?>
              </p>
            </div>
          </div>

          <?php if ($hasSalary && $calc['totalMinutes'] > 0): ?>
          <div class="px-5 py-3 border-t border-gray-100 bg-gray-50">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Composição do valor (CLT)</p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
              <?php
              $breakdown = [
                ['Regular',           $calc['minutes']['regular'],         1.0,  'gray-600'],
                ['Noturno +20%',      $calc['minutes']['noturno'],         1.2,  '[#6b0fa8]'],
                ['Domingo +100%',     $calc['minutes']['domingo'],         2.0,  'amber-600'],
                ['Dom. Noturno +120%',$calc['minutes']['domingo_noturno'], 2.2,  '[#e8001c]'],
              ];
              foreach ($breakdown as [$lbl, $mins, $rate, $color]):
                $v = ($mins / 60) * $calc['hourlyRate'] * $rate;
              ?>
                <div class="bg-white border border-gray-200 rounded-lg px-3 py-2">
                  <p class="text-<?= $color ?> font-medium"><?= $lbl ?></p>
                  <p class="text-gray-700 font-semibold mt-0.5"><?= minutesToHHMM((int)round($mins)) ?></p>
                  <p class="text-gray-400">R$ <?= number_format($v,2,',','.') ?></p>
                </div>
              <?php endforeach; ?>
            </div>
            <p class="text-xs text-gray-400 mt-2">
              Base: R$ <?= number_format($c['monthly_salary'],2,',','.') ?> ÷ 220h = R$ <?= number_format($calc['hourlyRate'],2,',','.') ?>/h
            </p>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

  <?php elseif ($tab === 'deducoes'): ?>
  <!-- ── DEDUÇÕES ───────────────────────────────────────────────────────── -->

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <!-- Formulário -->
      <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
        <div class="px-5 py-4 border-b" style="background:linear-gradient(135deg,rgba(232,0,28,.06),rgba(107,15,168,.04))">
          <h2 class="font-semibold text-gray-900">Registrar dedução por atraso</h2>
          <p class="text-xs text-gray-400 mt-0.5">A dedução é aplicada imediatamente ao saldo do colaborador</p>
        </div>
        <div class="p-5 space-y-4">

          <!-- Colaborador -->
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Colaborador</label>
            <select id="ded-user" class="hw-input px-3 py-2 text-sm" onchange="onDedUserChange()">
              <option value="">— Selecione —</option>
              <?php foreach ($collaborators as $c): ?>
              <option value="<?= $c['id'] ?>" data-balance="<?= (int)($financials[$c['id']]['balanceMins'] ?? 0) ?>">
                <?= e($c['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Preview de saldo -->
          <div id="ded-balance-row" class="hidden rounded-xl border border-gray-200 px-4 py-3">
            <div class="flex items-center justify-between text-sm">
              <span class="text-gray-500">Saldo atual</span>
              <span id="ded-balance-cur" class="font-bold text-gray-800">—</span>
            </div>
            <div id="ded-balance-after-row" class="hidden flex items-center justify-between text-sm mt-1 pt-1 border-t border-gray-100">
              <span class="text-gray-500">Após a dedução</span>
              <span id="ded-balance-after" class="font-bold" style="color:var(--hw-red)">—</span>
            </div>
          </div>

          <!-- Data -->
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Data do atraso</label>
            <input type="text" id="ded-date" placeholder="Selecione a data" readonly
              class="hw-input px-3 py-2 text-sm cursor-pointer w-44">
            <input type="hidden" id="ded-date-iso">
          </div>

          <!-- Duração -->
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-2">Tempo a deduzir</label>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <p class="text-xs text-gray-400 mb-1">Horas</p>
                <input type="number" id="ded-hours" min="0" max="23" value="0"
                  class="hw-input px-3 py-2 text-sm" oninput="onDedDurationChange()">
              </div>
              <div>
                <p class="text-xs text-gray-400 mb-1">Minutos</p>
                <input type="number" id="ded-mins" min="0" max="59" value="0"
                  class="hw-input px-3 py-2 text-sm" oninput="onDedDurationChange()">
              </div>
            </div>
          </div>

          <!-- Motivo -->
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">
              Motivo <span style="color:var(--hw-red)">*</span>
            </label>
            <textarea id="ded-reason" rows="2"
              placeholder="Ex: Atraso de 30min não justificado em 28/04/2026"
              class="hw-input px-3 py-2 text-sm resize-none"></textarea>
          </div>

          <div id="ded-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>
          <button onclick="submitDeduction()" id="ded-btn" class="hw-btn w-full py-2.5 text-sm justify-center" style="background:var(--hw-gradient)">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
            </svg>
            Registrar dedução
          </button>
        </div>
      </div>

      <!-- Histórico -->
      <div>
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Histórico de deduções</h3>
        <?php if (empty($adminDeductions)): ?>
          <div class="bg-white rounded-2xl p-8 text-center text-gray-400 text-sm" style="box-shadow:var(--hw-shadow)">
            Nenhuma dedução registrada ainda.
          </div>
        <?php else: ?>
          <div class="space-y-2">
            <?php foreach ($adminDeductions as $d):
              $dh = intdiv($d['requested_minutes'], 60);
              $dm = $d['requested_minutes'] % 60;
            ?>
            <div class="bg-white rounded-xl px-4 py-3" style="box-shadow:0 2px 8px rgba(0,0,0,.06)">
              <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                  <div class="flex flex-wrap items-center gap-2 mb-0.5">
                    <div class="hw-avatar w-6 h-6 text-xs shrink-0">
                      <?= strtoupper(mb_substr($d['user_name'],0,1)) ?>
                    </div>
                    <span class="font-semibold text-sm text-gray-800"><?= e($d['user_name']) ?></span>
                    <?php if ($d['request_date']): ?>
                      <span class="text-xs text-gray-500"><?= date('d/m/Y', strtotime($d['request_date'])) ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if ($d['reason']): ?>
                    <p class="text-xs text-gray-600 mt-0.5"><?= e($d['reason']) ?></p>
                  <?php endif; ?>
                  <p class="text-xs text-gray-400 mt-1">
                    Registrado por <?= e($d['admin_name'] ?? '—') ?> · <?= fmtDt($d['created_at']) ?>
                  </p>
                </div>
                <span class="text-sm font-bold shrink-0" style="color:var(--hw-red)">
                  -<?= sprintf('%02d:%02d', $dh, $dm) ?> h
                </span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>

  <?php endif; ?>
</main>

<!-- Modal de revisão -->
<div id="modal-review" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
    <div class="px-6 py-4 flex items-center justify-between" style="background:var(--hw-gradient)">
      <h2 id="review-title" class="font-semibold text-white">Revisar solicitação</h2>
      <button onclick="closeReview()" class="text-white/70 hover:text-white text-xl leading-none">&times;</button>
    </div>
    <div class="p-6">
      <input type="hidden" id="review-id">
      <input type="hidden" id="review-action">
      <div class="mb-4">
        <label class="block text-xs font-medium text-gray-700 mb-1">Nota (opcional)</label>
        <textarea id="review-note" rows="3" placeholder="Ex: Folga programada para 05/05..."
          class="hw-input px-3 py-2 text-sm resize-none"></textarea>
      </div>
      <div class="flex gap-2">
        <button onclick="closeReview()" class="flex-1 border border-gray-300 text-sm py-2.5 rounded-lg hover:bg-gray-50 transition">Cancelar</button>
        <button onclick="confirmReview()" id="review-confirm-btn"
          class="flex-1 text-sm font-medium text-white py-2.5 rounded-lg transition bg-green-600 hover:bg-green-700">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
// ── Utilitários ───────────────────────────────────────────────────────────────
function minsToHHMM(m) {
  const abs = Math.abs(m);
  return (m < 0 ? '-' : '') + String(Math.floor(abs/60)).padStart(2,'0') + ':' + String(abs%60).padStart(2,'0');
}
function dateToISO(d) {
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
}

const ptLocale = {
  months:{longhand:['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'],shorthand:['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez']},
  weekdays:{longhand:['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'],shorthand:['Dom','Seg','Ter','Qua','Qui','Sex','Sáb']},
  firstDayOfWeek:0
};

// Flatpickr para data do atraso
flatpickr('#ded-date', {
  dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y', locale: ptLocale,
  onChange: ([date]) => {
    document.getElementById('ded-date-iso').value = date ? dateToISO(date) : '';
  }
});

// ── Saldo preview ─────────────────────────────────────────────────────────────
const colBalances = <?= json_encode($balancesJs) ?>;

function getDedMins() {
  const h = parseInt(document.getElementById('ded-hours')?.value) || 0;
  const m = parseInt(document.getElementById('ded-mins')?.value)  || 0;
  return h * 60 + m;
}

function updateDedPreview() {
  const uid = document.getElementById('ded-user')?.value;
  const row = document.getElementById('ded-balance-row');
  if (!uid || !row) return;
  const bal    = colBalances[uid] ?? 0;
  const dedMins = getDedMins();
  document.getElementById('ded-balance-cur').textContent = minsToHHMM(bal) + ' h';
  row.classList.remove('hidden');
  const afterRow = document.getElementById('ded-balance-after-row');
  if (dedMins > 0) {
    const after = bal - dedMins;
    document.getElementById('ded-balance-after').textContent = minsToHHMM(after) + ' h';
    document.getElementById('ded-balance-after').style.color = after < 0 ? 'var(--hw-red)' : '#16a34a';
    afterRow.classList.remove('hidden');
  } else {
    afterRow.classList.add('hidden');
  }
}

function onDedUserChange()    { updateDedPreview(); }
function onDedDurationChange(){ updateDedPreview(); }

// ── Submeter dedução ──────────────────────────────────────────────────────────
async function submitDeduction() {
  const errEl  = document.getElementById('ded-error');
  errEl.classList.add('hidden');

  const userId  = document.getElementById('ded-user').value;
  const dateIso = document.getElementById('ded-date-iso').value;
  const total   = getDedMins();
  const reason  = document.getElementById('ded-reason').value.trim();

  if (!userId)  { errEl.textContent = 'Selecione um colaborador.';     errEl.classList.remove('hidden'); return; }
  if (!dateIso) { errEl.textContent = 'Selecione a data do atraso.';   errEl.classList.remove('hidden'); return; }
  if (total<=0) { errEl.textContent = 'Informe o tempo a deduzir.';    errEl.classList.remove('hidden'); return; }
  if (!reason)  { errEl.textContent = 'O motivo é obrigatório.';       errEl.classList.remove('hidden'); return; }

  const btn = document.getElementById('ded-btn');
  btn.disabled = true; btn.textContent = 'Salvando…';

  try {
    const res = await fetch('/api/bh-requests.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action: 'admin_deduct', user_id: userId, minutes: total, date: dateIso, reason })
    });
    let data = {};
    try { data = await res.json(); } catch (_) {}
    if (!res.ok) {
      errEl.textContent = data.error ?? 'Erro ao registrar.';
      errEl.classList.remove('hidden');
      btn.disabled = false; btn.textContent = 'Registrar dedução';
      return;
    }
    location.reload();
  } catch (err) {
    errEl.textContent = 'Erro de conexão. Tente novamente.';
    errEl.classList.remove('hidden');
    btn.disabled = false; btn.textContent = 'Registrar dedução';
  }
}

// ── Revisão de solicitações ───────────────────────────────────────────────────
function review(id, action) {
  document.getElementById('review-id').value     = id;
  document.getElementById('review-action').value = action;
  document.getElementById('review-note').value   = '';
  const btn = document.getElementById('review-confirm-btn');
  document.getElementById('review-title').textContent = action === 'approved' ? 'Aprovar solicitação' : 'Rejeitar solicitação';
  btn.className = btn.className.replace(/bg-\w+-600 hover:bg-\w+-700/, action === 'approved' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700');
  document.getElementById('modal-review').classList.replace('hidden','flex');
}
function closeReview() {
  document.getElementById('modal-review').classList.replace('flex','hidden');
}
async function confirmReview() {
  const id     = document.getElementById('review-id').value;
  const action = document.getElementById('review-action').value;
  const note   = document.getElementById('review-note').value;
  const btn    = document.getElementById('review-confirm-btn');
  btn.disabled = true; btn.textContent = 'Salvando…';
  const res = await fetch('/api/bh-requests.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'review', id, status: action, review_note: note})
  });
  if (res.ok) { location.reload(); }
  else { alert('Erro ao processar.'); btn.disabled = false; }
}

async function saveSalary(uid) {
  const val = parseFloat(document.getElementById('sal-' + uid).value);
  const ws  = document.getElementById('ws-' + uid).value;
  const we  = document.getElementById('we-' + uid).value;
  if (isNaN(val) || val < 0) { alert('Valor inválido.'); return; }
  if (!ws || !we) { alert('Informe os horários de entrada e saída.'); return; }
  const res = await fetch('/api/salary.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({user_id: uid, monthly_salary: val, work_start: ws, work_end: we})
  });
  if (res.ok) { location.reload(); }
  else { const d = await res.json(); alert(d.error ?? 'Erro ao salvar.'); }
}

document.getElementById('modal-review').addEventListener('click', function(e) {
  if (e.target === this) closeReview();
});
</script>
</body></html>
