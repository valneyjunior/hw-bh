<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireAdmin();

$tab = $_GET['tab'] ?? 'validacao';

$db = getDb();

$stmt = $db->query("
  SELECT r.*, u.name AS user_name,
         TIMESTAMPDIFF(HOUR, r.ended_at, r.created_at) AS delay_hours
  FROM records r
  JOIN users u ON u.id = r.user_id
  ORDER BY r.started_at DESC
");
$allRecords = $stmt->fetchAll();

$pending   = array_filter($allRecords, fn($r) => !$r['validated_at'] && !$r['rejected_at']);
$validated = array_filter($allRecords, fn($r) =>  $r['validated_at']);
$rejected  = array_filter($allRecords, fn($r) =>  $r['rejected_at']);

$byUser = [];
foreach ($pending as $r) {
    $byUser[$r['user_id']] ??= ['name' => $r['user_name'], 'records' => []];
    $byUser[$r['user_id']]['records'][] = $r;
}

$summary = [];
foreach ($validated as $r) {
    $month = date('Y-m', strtotime($r['started_at']));
    $uid   = $r['user_id'];
    $summary[$month][$uid] ??= ['name' => $r['user_name'], 'minutes' => 0];
    $summary[$month][$uid]['minutes'] += (strtotime($r['ended_at']) - strtotime($r['started_at'])) / 60;
}
krsort($summary);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Painel Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-5xl mx-auto px-4 py-6">

  <!-- Tabs -->
  <div class="flex gap-1 border-b border-gray-200 mb-6">
    <?php foreach (['validacao' => 'Validação', 'resumo' => 'Resumo', 'lancamento' => 'Lançamento BH'] as $k => $label): ?>
      <a href="?tab=<?= $k ?>"
        class="px-5 py-2.5 text-sm font-medium rounded-t-lg transition <?= $tab === $k
            ? 'bg-white border border-b-white border-gray-200 -mb-px font-semibold'
            : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100' ?>"
        <?= $tab === $k ? 'style="color:var(--hw-red)"' : '' ?>>
        <?= $label ?>
        <?php if ($k === 'validacao' && count($pending)): ?>
          <span class="ml-1.5 text-xs font-semibold px-1.5 py-0.5 rounded-full hw-badge"><?= count($pending) ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- ── TAB: VALIDAÇÃO ── -->
  <?php if ($tab === 'validacao'): ?>

    <?php if (empty($pending)): ?>
      <div class="text-center py-20 text-gray-400">
        <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p>Nenhum registro pendente de validação.</p>
      </div>
    <?php else: ?>
      <div class="space-y-6">
        <?php foreach ($byUser as $uid => $data): ?>
          <section class="bg-white border border-gray-100 rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
            <div class="flex items-center justify-between px-5 py-3 bg-gray-50 border-b border-gray-100">
              <div class="flex items-center gap-3">
                <div class="hw-avatar w-8 h-8 text-sm">
                  <?= strtoupper(mb_substr($data['name'], 0, 1)) ?>
                </div>
                <span class="font-semibold text-gray-800"><?= e($data['name']) ?></span>
                <span class="text-xs text-gray-400"><?= count($data['records']) ?> pendente(s) · <?= sumDuration($data['records']) ?></span>
              </div>
              <button onclick="validateAll('<?= $uid ?>', this)"
                class="text-xs bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg transition font-medium">
                Validar todos
              </button>
            </div>
            <div class="divide-y divide-gray-100">
              <?php foreach ($data['records'] as $r): ?>
                <div id="row-<?= $r['id'] ?>" class="flex items-start gap-3 px-5 py-3 hover:bg-gray-50 transition">
                  <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2 mb-0.5">
                      <span class="font-mono text-xs bg-gray-100 border border-gray-200 text-gray-700 px-2 py-0.5 rounded"><?= e($r['ticket']) ?></span>
                      <span class="text-xs text-gray-500"><?= fmtDt($r['started_at']) ?> → <?= fmtDt($r['ended_at']) ?></span>
                      <span class="text-xs font-semibold text-gray-700"><?= fmtDuration($r['started_at'], $r['ended_at']) ?></span>
                      <?php if ($r['delay_hours'] > 48): ?>
                        <span class="text-xs bg-red-100 border border-red-200 text-red-600 px-2 py-0.5 rounded-full font-medium"
                              title="Lançado <?= $r['delay_hours'] ?>h após o encerramento">
                          ⚠ Fora do prazo (<?= $r['delay_hours'] ?>h)
                        </span>
                      <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-700"><?= e($r['description']) ?></p>
                  </div>
                  <div class="flex flex-col gap-1.5 shrink-0">
                    <button onclick="toggleValidate('<?= $r['id'] ?>', false, this)"
                      class="text-xs bg-green-50 hover:bg-green-100 border border-green-200 text-green-700 px-3 py-1.5 rounded-lg transition font-medium">
                      Validar
                    </button>
                    <button onclick="openReject('<?= $r['id'] ?>', '<?= e($r['ticket']) ?>')"
                      class="text-xs bg-red-50 hover:bg-red-100 border border-red-200 text-red-600 px-3 py-1.5 rounded-lg transition font-medium">
                      Recusar
                    </button>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <!-- ── TAB: RESUMO ── -->
  <?php elseif ($tab === 'resumo'): ?>

    <?php if (empty($summary)): ?>
      <div class="text-center py-20 text-gray-400">
        <p>Nenhum registro validado ainda.</p>
      </div>
    <?php else: ?>
      <div class="space-y-8">
        <?php foreach ($summary as $month => $users): ?>
          <?php
            [$y, $m] = explode('-', $month);
            $months_pt = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
            $monthLabel = $months_pt[(int)$m] . '/' . $y;
            $totalMin = array_sum(array_column($users, 'minutes'));
          ?>
          <section>
            <div class="flex items-center gap-3 mb-3">
              <h2 class="text-base font-bold text-gray-800"><?= $monthLabel ?></h2>
              <span class="hw-section-tag">Total <?= minutesToHHMM($totalMin) ?></span>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
              <?php foreach ($users as $uid => $u): ?>
                <div class="bg-white border border-gray-100 rounded-xl p-4" style="box-shadow:var(--hw-shadow)">
                  <div class="hw-avatar w-9 h-9 text-sm mb-2">
                    <?= strtoupper(mb_substr($u['name'], 0, 1)) ?>
                  </div>
                  <p class="text-sm font-medium text-gray-800 truncate"><?= e($u['name']) ?></p>
                  <p class="text-2xl font-bold text-gray-900 mt-1 hw-stat"><?= minutesToHHMM($u['minutes']) ?></p>
                  <p class="text-xs text-gray-400 mt-0.5">horas validadas</p>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <!-- ── TAB: LANÇAMENTO BH ── -->
  <?php elseif ($tab === 'lancamento'): ?>

    <?php if (empty($validated)): ?>
      <div class="text-center py-20 text-gray-400">
        <p>Nenhum registro validado para exportar.</p>
      </div>
    <?php else: ?>
      <div class="mb-4 flex items-center gap-3">
        <p class="text-sm text-gray-500">Selecione o mês/colaborador e copie a tabela para colar no sistema de BH.</p>
        <button onclick="copyTable()" class="ml-auto hw-btn text-xs px-3 py-1.5">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
          Copiar
        </button>
      </div>

      <div class="flex flex-wrap gap-3 mb-4">
        <select id="filter-month" onchange="applyFilters()"
          class="hw-input px-3 py-2 text-sm w-auto">
          <option value="">Todos os meses</option>
          <?php
            $months = array_unique(array_map(fn($r) => substr($r['started_at'], 0, 7), array_values($validated)));
            rsort($months);
            foreach ($months as $mo):
              [$y,$m] = explode('-', $mo);
              $months_pt = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
          ?>
            <option value="<?= $mo ?>"><?= $months_pt[(int)$m] . '/' . $y ?></option>
          <?php endforeach; ?>
        </select>
        <select id="filter-user" onchange="applyFilters()"
          class="hw-input px-3 py-2 text-sm w-auto">
          <option value="">Todos os colaboradores</option>
          <?php
            $names = [];
            foreach ($validated as $r) $names[$r['user_id']] = $r['user_name'];
            foreach ($names as $uid => $name):
          ?>
            <option value="<?= $uid ?>"><?= e($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="overflow-x-auto rounded-xl border border-gray-100" style="box-shadow:var(--hw-shadow)">
        <table id="bh-table" class="w-full text-sm bg-white">
          <thead>
            <tr class="border-b border-gray-100 text-left" style="background:linear-gradient(135deg,rgba(232,0,28,.04),rgba(107,15,168,.04))">
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wide">Colaborador</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wide">Chamado</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wide">Início</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wide">Fim</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wide">Duração</th>
              <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wide">Descrição</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($validated as $r): ?>
              <tr class="hover:bg-gray-50 transition bh-row"
                data-month="<?= substr($r['started_at'], 0, 7) ?>"
                data-uid="<?= $r['user_id'] ?>">
                <td class="px-4 py-2.5 font-medium text-gray-800"><?= e($r['user_name']) ?></td>
                <td class="px-4 py-2.5 font-mono text-xs text-gray-700"><?= e($r['ticket']) ?></td>
                <td class="px-4 py-2.5 text-gray-600 whitespace-nowrap"><?= fmtDt($r['started_at']) ?></td>
                <td class="px-4 py-2.5 text-gray-600 whitespace-nowrap"><?= fmtDt($r['ended_at']) ?></td>
                <td class="px-4 py-2.5 font-semibold text-gray-800"><?= fmtDuration($r['started_at'], $r['ended_at']) ?></td>
                <td class="px-4 py-2.5 text-gray-700"><?= e($r['description']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  <?php endif; ?>
</main>

<!-- Modal: Recusar registro -->
<div id="modal-reject" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
    <div class="px-6 py-4 border-b flex items-center justify-between" style="background:var(--hw-gradient)">
      <h2 class="font-semibold text-white">Recusar registro</h2>
      <button onclick="closeReject()" class="text-white/70 hover:text-white text-xl leading-none">&times;</button>
    </div>
    <div class="p-6 space-y-4">
      <p class="text-sm text-gray-600">Chamado: <strong id="reject-ticket" class="font-mono text-gray-800"></strong></p>
      <input type="hidden" id="reject-id">
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">
          Motivo da recusa <span class="text-red-500">*</span>
        </label>
        <textarea id="reject-reason" rows="4" required
          placeholder="Descreva o motivo da recusa. Este texto será enviado por e-mail ao colaborador."
          class="hw-input px-3 py-2 text-sm resize-none"></textarea>
        <p class="text-xs text-gray-400 mt-1">O colaborador receberá este motivo por e-mail.</p>
      </div>
      <div id="reject-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>
      <div class="flex gap-2 justify-end pt-1">
        <button onclick="closeReject()" class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition">
          Cancelar
        </button>
        <button onclick="confirmReject()" id="reject-btn"
          class="px-4 py-2 text-sm bg-red-600 hover:bg-red-700 text-white rounded-lg transition font-medium">
          Confirmar recusa
        </button>
      </div>
    </div>
  </div>
</div>

<script>
async function toggleValidate(id, isValidated, btn) {
  btn.disabled = true;
  const res  = await fetch('/api/validate.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({id})
  });
  const data = await res.json();
  if (res.ok) {
    const row = document.getElementById('row-' + id);
    row.style.transition = 'opacity .3s';
    row.style.opacity = '0';
    setTimeout(() => row.remove(), 300);
  } else {
    alert(data.error ?? 'Erro ao validar.');
    btn.disabled = false;
  }
}

async function validateAll(userId, btn) {
  if (!confirm('Validar todos os registros pendentes deste colaborador?')) return;
  btn.disabled = true;
  btn.textContent = 'Validando…';
  const section = btn.closest('section');
  const rowEls  = section.querySelectorAll('[id^="row-"]');
  const promises = [...rowEls].map(el => {
    const id = el.id.replace('row-', '');
    return fetch('/api/validate.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({id})
    });
  });
  await Promise.all(promises);
  location.reload();
}

function applyFilters() {
  const month = document.getElementById('filter-month').value;
  const uid   = document.getElementById('filter-user').value;
  document.querySelectorAll('.bh-row').forEach(row => {
    const okMonth = !month || row.dataset.month === month;
    const okUser  = !uid   || row.dataset.uid   === uid;
    row.style.display = okMonth && okUser ? '' : 'none';
  });
}

function openReject(id, ticket) {
  document.getElementById('reject-id').value      = id;
  document.getElementById('reject-ticket').textContent = ticket;
  document.getElementById('reject-reason').value  = '';
  document.getElementById('reject-error').classList.add('hidden');
  document.getElementById('modal-reject').classList.replace('hidden','flex');
  setTimeout(() => document.getElementById('reject-reason').focus(), 100);
}
function closeReject() {
  document.getElementById('modal-reject').classList.replace('flex','hidden');
}
async function confirmReject() {
  const id     = document.getElementById('reject-id').value;
  const reason = document.getElementById('reject-reason').value.trim();
  const errEl  = document.getElementById('reject-error');
  if (!reason) {
    errEl.textContent = 'O motivo da recusa é obrigatório.';
    errEl.classList.remove('hidden');
    return;
  }
  const btn = document.getElementById('reject-btn');
  btn.disabled = true; btn.textContent = 'Enviando…';

  const res  = await fetch('/api/validate.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'reject', id, reason})
  });
  const data = await res.json();
  if (!res.ok) {
    errEl.textContent = data.error ?? 'Erro ao recusar.';
    errEl.classList.remove('hidden');
    btn.disabled = false; btn.textContent = 'Confirmar recusa';
    return;
  }
  const row = document.getElementById('row-' + id);
  if (row) { row.style.opacity='0'; setTimeout(()=>row.remove(),300); }
  closeReject();
}
document.getElementById('modal-reject').addEventListener('click', function(e) {
  if (e.target === this) closeReject();
});

function copyTable() {
  const rows = [...document.querySelectorAll('.bh-row')].filter(r => r.style.display !== 'none');
  const lines = rows.map(r => [...r.querySelectorAll('td')].map(td => td.textContent.trim()).join('\t'));
  navigator.clipboard.writeText(lines.join('\n')).then(() => {
    const btn = event.currentTarget;
    const orig = btn.innerHTML;
    btn.textContent = 'Copiado!';
    setTimeout(() => btn.innerHTML = orig, 2000);
  });
}
</script>
</body></html>
