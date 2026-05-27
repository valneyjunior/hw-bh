<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireLogin();
$uid  = $user['id'];

$db   = getDb();
$stmt = $db->prepare("
    SELECT * FROM lancamentos
    WHERE usuario_id = ?
    ORDER BY data_acionamento DESC, hora_inicio DESC
");
$stmt->execute([$uid]);
$records = $stmt->fetchAll();

$pending   = array_filter($records, fn($r) => $r['status'] === 'pendente');
$approved  = array_filter($records, fn($r) => $r['status'] === 'aprovado');
$rejected  = array_filter($records, fn($r) => $r['status'] === 'recusado');
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
        <span class="hw-badge-pendente font-semibold">Pendentes de validação · <?= count($pending) ?></span>
      </div>
      <div class="space-y-2">
        <?php foreach ($pending as $r):
          $totalMin = (int)$r['total_minutos'];
        ?>
          <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
            <?php if ($r['fora_do_prazo']): ?>
              <div class="hw-alert-prazo mb-2 text-xs">⚠ Registro fora do prazo de 48h — sujeito a recusa pelo coordenador.</div>
            <?php endif; ?>
            <div class="flex items-start justify-between gap-3">
              <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                  <span class="font-mono text-xs bg-white border border-amber-200 text-amber-800 px-2 py-0.5 rounded"><?= e($r['chamado']) ?></span>
                  <span class="text-xs text-gray-500"><?= fmtDate($r['data_acionamento']) ?> · <?= substr($r['hora_inicio'],0,5) ?> → <?= substr($r['hora_fim'],0,5) ?></span>
                  <span class="text-xs font-semibold text-gray-700"><?= minutesToHHMM($totalMin) ?> h</span>
                  <?php if ($r['feriado']): ?>
                    <span class="text-xs bg-purple-100 text-purple-700 border border-purple-200 px-2 py-0.5 rounded-full">Feriado<?= $r['descricao_feriado'] ? ': ' . e($r['descricao_feriado']) : '' ?></span>
                  <?php endif; ?>
                </div>
                <p class="text-sm text-gray-700"><?= e($r['motivo']) ?></p>
                <?php if ($r['origem']): ?>
                  <p class="text-xs text-gray-400 mt-0.5">Origem: <?= e($r['origem']) ?></p>
                <?php endif; ?>
              </div>
              <div class="flex gap-3 shrink-0 text-xs">
                <button data-record="<?= htmlspecialchars(json_encode($r), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                  class="edit-btn font-medium hover:underline" style="color:var(--hw-purple)">Editar</button>
                <button onclick="deleteRecord(<?= $r['id'] ?>, this)" class="text-red-500 hover:underline font-medium">Excluir</button>
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
        <span class="hw-badge-recusado font-semibold">Recusados · <?= count($rejected) ?></span>
      </div>
      <div class="space-y-2">
        <?php foreach ($rejected as $r): ?>
          <div class="bg-red-50 border border-red-200 rounded-xl p-4">
            <div class="flex flex-wrap items-center gap-2 mb-1">
              <span class="font-mono text-xs bg-white border border-red-200 text-red-800 px-2 py-0.5 rounded"><?= e($r['chamado']) ?></span>
              <span class="text-xs text-gray-500"><?= fmtDate($r['data_acionamento']) ?> · <?= substr($r['hora_inicio'],0,5) ?> → <?= substr($r['hora_fim'],0,5) ?></span>
              <span class="text-xs font-semibold text-gray-700"><?= minutesToHHMM((int)$r['total_minutos']) ?> h</span>
              <span class="text-xs text-red-600 font-medium ml-auto">✕ Recusado</span>
            </div>
            <p class="text-sm text-gray-700"><?= e($r['motivo']) ?></p>
            <?php if ($r['nota_revisao']): ?>
              <p class="text-xs text-red-600 mt-1.5 border-t border-red-200 pt-1.5">
                <strong>Motivo:</strong> <?= e($r['nota_revisao']) ?>
              </p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (!empty($approved)): ?>
    <section>
      <div class="flex items-center gap-2 mb-3">
        <span class="hw-badge-aprovado font-semibold">Aprovados · <?= count($approved) ?></span>
        <span class="text-xs text-gray-400">
          <?php
          $totalAprovMin = array_sum(array_column(array_values($approved), 'total_minutos'));
          echo minutesToHHMM($totalAprovMin) . ' h';
          ?>
        </span>
      </div>
      <div class="space-y-2">
        <?php foreach ($approved as $r): ?>
          <div class="bg-green-50 border border-green-200 rounded-xl p-4">
            <div class="flex flex-wrap items-center gap-2 mb-1">
              <span class="font-mono text-xs bg-white border border-green-200 text-green-800 px-2 py-0.5 rounded"><?= e($r['chamado']) ?></span>
              <span class="text-xs text-gray-500"><?= fmtDate($r['data_acionamento']) ?> · <?= substr($r['hora_inicio'],0,5) ?> → <?= substr($r['hora_fim'],0,5) ?></span>
              <span class="text-xs font-semibold text-gray-700"><?= minutesToHHMM((int)$r['total_minutos']) ?> h</span>
              <?php if ($r['feriado']): ?>
                <span class="text-xs bg-purple-100 text-purple-700 border border-purple-200 px-2 py-0.5 rounded-full">Feriado<?= $r['descricao_feriado'] ? ': ' . e($r['descricao_feriado']) : '' ?></span>
              <?php endif; ?>
              <span class="text-xs text-green-600 font-medium ml-auto">✓ Aprovado</span>
            </div>
            <p class="text-sm text-gray-700"><?= e($r['motivo']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</main>

<!-- Modal: Novo / Editar registro -->
<div id="modal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">
    <div class="px-6 py-4 border-b flex items-center justify-between" style="background:var(--hw-gradient)">
      <h2 id="modal-title" class="font-semibold text-white">Novo registro</h2>
      <button type="button" onclick="closeModal()" class="text-white/70 hover:text-white text-xl leading-none">&times;</button>
    </div>
    <form id="record-form" class="p-6 space-y-4">
      <input type="hidden" id="record-id">

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="hw-label">Data do acionamento</label>
          <input type="date" id="data_acionamento" class="hw-input" onchange="checkPrazo()">
        </div>
        <div>
          <label class="hw-label">Chamado</label>
          <input type="text" id="chamado" required placeholder="ex: 0426-000129" class="hw-input">
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="hw-label">Hora início</label>
          <input type="text" id="hora_inicio" class="hw-input font-mono" maxlength="5" placeholder="HH:MM" oninput="applyTimeMask(this);calcDuration()">
        </div>
        <div>
          <label class="hw-label">Hora fim</label>
          <input type="text" id="hora_fim" class="hw-input font-mono" maxlength="5" placeholder="HH:MM" oninput="applyTimeMask(this);calcDuration()">
        </div>
      </div>

      <div id="duration-preview" class="hidden text-xs text-gray-500 -mt-2 text-right"></div>

      <div>
        <label class="hw-label">Motivo / Descrição <span class="text-red-500">*</span></label>
        <textarea id="motivo" required rows="3" placeholder="Cliente + descrição do problema" class="hw-input resize-none"></textarea>
      </div>

      <!-- Feriado como pergunta -->
      <div class="space-y-2">
        <label class="flex items-start gap-3 cursor-pointer select-none">
          <input type="checkbox" id="feriado" onchange="toggleFeriado()" class="mt-0.5 w-4 h-4 rounded accent-purple-600">
          <div>
            <span class="text-sm font-medium text-gray-700">Este dia foi um <strong>feriado</strong>?</span>
            <span class="block text-xs text-gray-400">(finais de semana são detectados automaticamente)</span>
          </div>
        </label>
        <div id="feriado-desc-wrap" class="hidden pl-7">
          <input type="text" id="descricao_feriado" maxlength="100"
            placeholder="Qual feriado foi esse? Ex: Corpus Christi"
            class="hw-input text-sm">
        </div>
      </div>

      <div id="late-warn" class="hidden hw-alert-prazo text-sm">
        ⚠ O prazo para lançamento de horas é de 48 horas após o acionamento. Este registro está sujeito a recusa.
      </div>
      <div id="form-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>

      <div class="flex gap-2 justify-end pt-1">
        <button type="button" onclick="closeModal()" class="hw-btn-secondary text-sm">Cancelar</button>
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
  document.getElementById('record-id').value          = record ? record.id : '';
  document.getElementById('data_acionamento').value   = record ? record.data_acionamento : '';
  document.getElementById('hora_inicio').value        = record ? record.hora_inicio.slice(0,5) : '';
  document.getElementById('hora_fim').value           = record ? record.hora_fim.slice(0,5) : '';
  document.getElementById('chamado').value            = record ? record.chamado : '';
  document.getElementById('motivo').value             = record ? record.motivo : '';
  document.getElementById('feriado').checked          = record ? !!record.feriado : false;
  document.getElementById('descricao_feriado').value  = record ? (record.descricao_feriado ?? '') : '';
  document.getElementById('feriado-desc-wrap').classList.toggle('hidden', !(record && record.feriado));
  document.getElementById('form-error').classList.add('hidden');
  document.getElementById('late-warn').classList.add('hidden');
  document.getElementById('modal').classList.replace('hidden','flex');
  calcDuration();
  if (record) checkPrazo();
}

function toggleFeriado() {
  const checked = document.getElementById('feriado').checked;
  document.getElementById('feriado-desc-wrap').classList.toggle('hidden', !checked);
  if (!checked) document.getElementById('descricao_feriado').value = '';
}

function checkPrazo() {
  const d = document.getElementById('data_acionamento').value;
  if (!d) return;
  const endDt = new Date(d + 'T23:59:00');
  const diffH = (Date.now() - endDt.getTime()) / 3600000;
  document.getElementById('late-warn').classList.toggle('hidden', diffH <= 48);
}

function applyTimeMask(el) {
  let v = el.value.replace(/\D/g, '').slice(0, 4);
  if (v.length >= 3) v = v.slice(0, 2) + ':' + v.slice(2);
  el.value = v;
}
function isValidTime(t) { return /^([01]\d|2[0-3]):[0-5]\d$/.test(t); }

function calcDuration() {
  const hi = document.getElementById('hora_inicio').value;
  const hf = document.getElementById('hora_fim').value;
  const el = document.getElementById('duration-preview');
  if (!hi || !hf) { el.classList.add('hidden'); return; }
  const [hh, mm] = hi.split(':').map(Number);
  const [hh2, mm2] = hf.split(':').map(Number);
  let mins = (hh2 * 60 + mm2) - (hh * 60 + mm);
  if (mins <= 0) mins += 1440;
  el.textContent = 'Duração: ' + String(Math.floor(mins/60)).padStart(2,'0') + ':' + String(mins%60).padStart(2,'0') + ' h';
  el.classList.remove('hidden');
}

function closeModal() {
  document.getElementById('modal').classList.replace('flex','hidden');
}

document.getElementById('record-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const id  = document.getElementById('record-id').value;
  const btn = document.getElementById('submit-btn');
  btn.disabled = true; btn.textContent = 'Salvando…';

  const data            = document.getElementById('data_acionamento').value;
  const hi              = document.getElementById('hora_inicio').value;
  const hf              = document.getElementById('hora_fim').value;
  const chamado         = document.getElementById('chamado').value;
  const motivo          = document.getElementById('motivo').value;
  const feriado         = document.getElementById('feriado').checked;
  const descFeriado     = document.getElementById('descricao_feriado').value.trim();
  const errEl           = document.getElementById('form-error');

  if (!data || !hi || !hf) {
    errEl.textContent = 'Preencha a data, hora início e hora fim.';
    errEl.classList.remove('hidden'); btn.disabled = false; btn.textContent = 'Salvar'; return;
  }

  const body = {
    action: id ? 'update' : 'create',
    id, data_acionamento: data, hora_inicio: hi, hora_fim: hf,
    chamado, motivo, feriado, descricao_feriado: descFeriado,
  };

  try {
    const res   = await fetch('/api/records.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
    const json2 = await res.json();
    if (!res.ok) {
      errEl.textContent = json2.error ?? 'Erro ao salvar.';
      errEl.classList.remove('hidden');
      btn.disabled = false; btn.textContent = 'Salvar'; return;
    }
    location.reload();
  } catch (err) {
    errEl.textContent = 'Erro de comunicação com o servidor. Tente novamente.';
    errEl.classList.remove('hidden');
    btn.disabled = false; btn.textContent = 'Salvar';
  }
});

async function deleteRecord(id, btn) {
  if (!confirm('Excluir este registro?')) return;
  btn.disabled = true;
  const res = await fetch('/api/records.php', {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'delete', id})});
  if (res.ok) location.reload();
  else { alert('Erro ao excluir.'); btn.disabled = false; }
}
</script>
</body></html>
