<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireLogin();

$stmt = getDb()->prepare("SELECT * FROM records WHERE user_id = ? ORDER BY started_at DESC");
$stmt->execute([$user['id']]);
$records = $stmt->fetchAll();

$pending   = array_filter($records, fn($r) => !$r['validated_at'] && !$r['rejected_at']);
$validated = array_filter($records, fn($r) =>  $r['validated_at']);
$rejected  = array_filter($records, fn($r) =>  $r['rejected_at']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Meus Registros</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-4xl mx-auto px-4 py-6 space-y-6">
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Meus Acionamentos</h1>
      <a href="/bh-request.php" class="text-xs hover:underline" style="color:var(--hw-purple)">Ver Banco de Horas &amp; Solicitar folga →</a>
    </div>
    <button onclick="openModal()"
      class="hw-btn text-sm px-4 py-2">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Novo registro
    </button>
  </div>

  <?php if (empty($records)): ?>
    <div class="text-center py-16 text-gray-400">
      <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      <p>Nenhum registro ainda. Clique em <strong>Novo registro</strong> para começar.</p>
    </div>
  <?php endif; ?>

  <?php if (!empty($pending)): ?>
    <section>
      <div class="flex items-center gap-2 mb-3">
        <span class="text-xs font-semibold text-amber-700 bg-amber-100 border border-amber-200 px-3 py-1 rounded-full">
          Pendentes de validação · <?= count($pending) ?>
        </span>
        <span class="text-xs text-gray-400"><?= sumDuration(array_values($pending)) ?></span>
      </div>
      <div class="space-y-2">
        <?php foreach ($pending as $r): ?>
          <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                  <span class="font-mono text-xs bg-white border border-amber-200 text-amber-800 px-2 py-0.5 rounded"><?= e($r['ticket']) ?></span>
                  <span class="text-xs text-gray-500"><?= fmtDt($r['started_at']) ?> → <?= fmtDt($r['ended_at']) ?></span>
                  <span class="text-xs font-semibold text-gray-700"><?= fmtDuration($r['started_at'], $r['ended_at']) ?></span>
                </div>
                <p class="text-sm text-gray-700"><?= e($r['description']) ?></p>
              </div>
              <div class="flex gap-3 shrink-0 text-xs">
                <button data-record="<?= htmlspecialchars(json_encode($r), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                  class="edit-btn font-medium hover:underline" style="color:var(--hw-purple)">Editar</button>
                <button onclick="deleteRecord('<?= $r['id'] ?>', this)" class="text-red-500 hover:underline font-medium">Excluir</button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (!empty($rejected)): ?>
    <section>
      <div class="flex items-center gap-2 mb-3">
        <span class="text-xs font-semibold text-red-700 bg-red-100 border border-red-200 px-3 py-1 rounded-full">
          Recusados · <?= count($rejected) ?>
        </span>
      </div>
      <div class="space-y-2">
        <?php foreach ($rejected as $r): ?>
          <div class="bg-red-50 border border-red-200 rounded-xl p-4">
            <div class="flex flex-wrap items-center gap-2 mb-1">
              <span class="font-mono text-xs bg-white border border-red-200 text-red-800 px-2 py-0.5 rounded"><?= e($r['ticket']) ?></span>
              <span class="text-xs text-gray-500"><?= fmtDt($r['started_at']) ?> → <?= fmtDt($r['ended_at']) ?></span>
              <span class="text-xs font-semibold text-gray-700"><?= fmtDuration($r['started_at'], $r['ended_at']) ?></span>
              <span class="text-xs text-red-600 font-medium ml-auto">✕ Recusado</span>
            </div>
            <p class="text-sm text-gray-700"><?= e($r['description']) ?></p>
            <?php if ($r['reject_reason']): ?>
              <p class="text-xs text-red-600 mt-1.5 border-t border-red-200 pt-1.5">
                <strong>Motivo:</strong> <?= e($r['reject_reason']) ?>
              </p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (!empty($validated)): ?>
    <section>
      <div class="flex items-center gap-2 mb-3">
        <span class="text-xs font-semibold text-green-700 bg-green-100 border border-green-200 px-3 py-1 rounded-full">
          Validados · <?= count($validated) ?>
        </span>
        <span class="text-xs text-gray-400"><?= sumDuration(array_values($validated)) ?></span>
      </div>
      <div class="space-y-2">
        <?php foreach ($validated as $r): ?>
          <div class="bg-green-50 border border-green-200 rounded-xl p-4">
            <div class="flex flex-wrap items-center gap-2 mb-1">
              <span class="font-mono text-xs bg-white border border-green-200 text-green-800 px-2 py-0.5 rounded"><?= e($r['ticket']) ?></span>
              <span class="text-xs text-gray-500"><?= fmtDt($r['started_at']) ?> → <?= fmtDt($r['ended_at']) ?></span>
              <span class="text-xs font-semibold text-gray-700"><?= fmtDuration($r['started_at'], $r['ended_at']) ?></span>
              <span class="text-xs text-green-600 font-medium ml-auto">✓ Validado</span>
            </div>
            <p class="text-sm text-gray-700"><?= e($r['description']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</main>

<!-- Modal -->
<div id="modal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">
    <div class="px-6 py-4 border-b flex items-center justify-between" style="background:var(--hw-gradient)">
      <h2 id="modal-title" class="font-semibold text-white">Novo registro</h2>
      <button onclick="closeModal()" class="text-white/70 hover:text-white text-xl leading-none">&times;</button>
    </div>
    <form id="record-form" class="p-6 space-y-4">
      <input type="hidden" id="record-id">
      <div class="space-y-3">
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Início</label>
          <div class="grid grid-cols-2 gap-2">
            <input type="date" id="start_date"
              class="hw-input px-3 py-2 text-sm"
              onchange="checkLateSubmission()">
            <input type="time" id="start_time"
              class="hw-input px-3 py-2 text-sm"
              onchange="checkLateSubmission()">
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Fim</label>
          <div class="grid grid-cols-2 gap-2">
            <input type="date" id="end_date"
              class="hw-input px-3 py-2 text-sm"
              onchange="checkLateSubmission()">
            <input type="time" id="end_time"
              class="hw-input px-3 py-2 text-sm"
              onchange="checkLateSubmission()">
          </div>
        </div>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Chamado</label>
        <input type="text" id="ticket" required placeholder="ex: 0426-000129"
          class="hw-input px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Descrição (Cliente + Problema)</label>
        <textarea id="description" required rows="3"
          class="hw-input px-3 py-2 text-sm resize-none"></textarea>
      </div>
      <div id="late-warn" class="hidden bg-amber-50 border border-amber-200 text-amber-700 text-sm rounded-lg px-3 py-2">
        ⚠ Este registro está fora do prazo de 48 horas. Lançamentos tardios estão sujeitos a recusa pelo coordenador.
      </div>
      <div id="form-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>
      <div class="flex gap-2 justify-end pt-1">
        <button type="button" onclick="closeModal()"
          class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancelar</button>
        <button type="submit" id="submit-btn" class="hw-btn px-4 py-2 text-sm">Salvar</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => openModal(JSON.parse(btn.dataset.record)));
  });
});

function openModal(record) {
  document.getElementById('modal-title').textContent = record ? 'Editar registro' : 'Novo registro';
  document.getElementById('record-id').value = record ? record.id : '';
  if (record) {
    const [sd, st] = record.started_at.split(' ');
    const [ed, et] = record.ended_at.split(' ');
    document.getElementById('start_date').value = sd;
    document.getElementById('start_time').value = st.slice(0, 5);
    document.getElementById('end_date').value   = ed;
    document.getElementById('end_time').value   = et.slice(0, 5);
  } else {
    ['start_date','start_time','end_date','end_time']
      .forEach(id => document.getElementById(id).value = '');
  }
  document.getElementById('ticket').value      = record ? record.ticket : '';
  document.getElementById('description').value = record ? record.description : '';
  document.getElementById('form-error').classList.add('hidden');
  document.getElementById('late-warn').classList.add('hidden');
  document.getElementById('modal').classList.replace('hidden', 'flex');
}

function checkLateSubmission() {
  const d = document.getElementById('end_date').value;
  const t = document.getElementById('end_time').value || '00:00';
  if (!d) return;
  const diffH = (Date.now() - new Date(d + 'T' + t).getTime()) / 3600000;
  document.getElementById('late-warn').classList.toggle('hidden', diffH <= 48);
}

function closeModal() {
  document.getElementById('modal').classList.replace('flex', 'hidden');
  document.getElementById('late-warn').classList.add('hidden');
}

document.getElementById('record-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const id  = document.getElementById('record-id').value;
  const btn = document.getElementById('submit-btn');
  btn.disabled = true; btn.textContent = 'Salvando…';

  const startDate = document.getElementById('start_date').value;
  const startTime = document.getElementById('start_time').value;
  const endDate   = document.getElementById('end_date').value;
  const endTime   = document.getElementById('end_time').value;

  const errEl = document.getElementById('form-error');
  if (!startDate || !startTime) {
    errEl.textContent = 'Selecione a data e hora de início.';
    errEl.classList.remove('hidden');
    btn.disabled = false; btn.textContent = 'Salvar';
    return;
  }
  if (!endDate || !endTime) {
    errEl.textContent = 'Selecione a data e hora de fim.';
    errEl.classList.remove('hidden');
    btn.disabled = false; btn.textContent = 'Salvar';
    return;
  }

  const started_at = startDate + ' ' + startTime + ':00';
  const ended_at   = endDate   + ' ' + endTime   + ':00';
  const body = {
    action: id ? 'update' : 'create',
    id, started_at, ended_at,
    ticket:      document.getElementById('ticket').value,
    description: document.getElementById('description').value,
  };

  const res  = await fetch('/api/records.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
  const data = await res.json();

  if (!res.ok) {
    document.getElementById('form-error').textContent = data.error;
    document.getElementById('form-error').classList.remove('hidden');
    btn.disabled = false; btn.textContent = 'Salvar';
    return;
  }
  location.reload();
});

async function deleteRecord(id, btn) {
  if (!confirm('Excluir este registro?')) return;
  btn.disabled = true;
  const res = await fetch('/api/records.php', {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'delete', id})});
  if (res.ok) location.reload();
  else { alert('Erro ao excluir.'); btn.disabled = false; }
}

document.getElementById('modal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});
</script>
</body></html>
