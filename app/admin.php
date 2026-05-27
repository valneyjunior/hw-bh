<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireCoordenador();
$db   = getDb();

// Filtro por setor: admin vê tudo, coordenador só vê o seu setor
$setorFilter   = isAdmin() ? null : $user['setor_id'];
$setorNome     = isAdmin() ? 'Todos os setores' : ($user['setor_nome'] ?? '');

// Busca lançamentos pendentes
$sql = "
    SELECT l.*, u.nome AS user_nome, u.email AS user_email, u.salario_bruto,
           s.nome AS setor_nome,
           EXTRACT(EPOCH FROM (NOW() - (l.data_acionamento + l.hora_fim::interval)))/3600 AS delay_hours
    FROM lancamentos l
    JOIN usuarios u ON u.id = l.usuario_id
    LEFT JOIN setores s ON s.id = u.setor_id
    WHERE l.status = 'pendente'
";
$params = [];
if ($setorFilter) { $sql .= " AND u.setor_id = ?"; $params[] = $setorFilter; }
$sql .= " ORDER BY l.data_acionamento DESC, l.hora_inicio DESC";

$stmtPending = $db->prepare($sql);
$stmtPending->execute($params);
$pending = $stmtPending->fetchAll();

// Agrupado por usuário
$byUser = [];
foreach ($pending as $r) {
    $byUser[$r['usuario_id']] ??= ['nome' => $r['user_nome'], 'setor' => $r['setor_nome'], 'records' => []];
    $byUser[$r['usuario_id']]['records'][] = $r;
}

// KPIs
$sqlKpi = "SELECT
    COUNT(*) FILTER (WHERE l.status='pendente') AS pendentes,
    COUNT(*) FILTER (WHERE l.status='aprovado') AS aprovados,
    COUNT(*) FILTER (WHERE l.status='recusado') AS recusados,
    COUNT(*) FILTER (WHERE l.fora_do_prazo = TRUE AND l.status='pendente') AS fora_prazo
    FROM lancamentos l
    JOIN usuarios u ON u.id = l.usuario_id
    WHERE u.status != 'ex-colaborador'
";
$kpiParams = [];
if ($setorFilter) { $sqlKpi .= " AND u.setor_id = ?"; $kpiParams[] = $setorFilter; }
$kpi = $db->prepare($sqlKpi);
$kpi->execute($kpiParams);
$kpi = $kpi->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Validação</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-5xl mx-auto px-4 py-6 space-y-6">

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Validação de Acionamentos</h1>
      <p class="text-sm text-gray-500"><?= e($setorNome) ?></p>
    </div>
  </div>

  <!-- KPI Cards -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-blue">Pendentes</p>
      <p class="hw-kpi-value hw-kpi-blue"><?= $kpi['pendentes'] ?></p>
    </div>
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-green">Aprovados</p>
      <p class="hw-kpi-value hw-kpi-green"><?= $kpi['aprovados'] ?></p>
    </div>
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-red">Recusados</p>
      <p class="hw-kpi-value hw-kpi-red"><?= $kpi['recusados'] ?></p>
    </div>
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-orange">Fora do prazo</p>
      <p class="hw-kpi-value hw-kpi-orange"><?= $kpi['fora_prazo'] ?></p>
    </div>
  </div>

  <!-- Pendentes agrupados por colaborador -->
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
              <div class="hw-avatar w-8 h-8 text-sm"><?= strtoupper(mb_substr($data['nome'],0,1)) ?></div>
              <div>
                <span class="font-semibold text-gray-800"><?= e($data['nome']) ?></span>
                <?php if ($data['setor']): ?>
                  <span class="ml-2 hw-setor-badge"><?= e($data['setor']) ?></span>
                <?php endif; ?>
              </div>
              <span class="text-xs text-gray-400"><?= count($data['records']) ?> pendente(s) · <?= minutesToHHMM(array_sum(array_column($data['records'],'total_minutos'))) ?> h</span>
            </div>
            <button onclick="validateAll(<?= $uid ?>, this)"
              class="hw-btn-primary text-xs px-3 py-1.5">Aprovar todos</button>
          </div>

          <div class="divide-y divide-gray-100">
            <?php foreach ($data['records'] as $r): ?>
              <div id="row-<?= $r['id'] ?>" class="flex items-start gap-3 px-5 py-3 hover:bg-gray-50 transition">
                <div class="flex-1 min-w-0">
                  <?php if ($r['fora_do_prazo']): ?>
                    <div class="hw-alert-prazo text-xs mb-1.5">⚠ Fora do prazo de 48h</div>
                  <?php endif; ?>
                  <div class="flex flex-wrap items-center gap-2 mb-0.5">
                    <span class="font-mono text-xs bg-gray-100 border border-gray-200 text-gray-700 px-2 py-0.5 rounded"><?= e($r['chamado']) ?></span>
                    <span class="text-xs text-gray-500"><?= fmtDate($r['data_acionamento']) ?> · <?= substr($r['hora_inicio'],0,5) ?> → <?= substr($r['hora_fim'],0,5) ?></span>
                    <span class="text-xs font-semibold text-gray-700"><?= minutesToHHMM((int)$r['total_minutos']) ?> h</span>
                    <?php $valorEst = calcValorLancamento($r, (float)($r['salario_bruto'] ?? 0));
                    if ($valorEst > 0): ?>
                    <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-purple-50 border border-purple-200 text-purple-700"><?= fmtBRL($valorEst) ?></span>
                    <?php endif; ?>
                    <?php if ($r['feriado']): ?><span class="text-xs bg-purple-100 text-purple-700 border border-purple-200 px-2 py-0.5 rounded-full">Feriado</span><?php endif; ?>
                    <?php if ($r['origem']): ?><span class="text-xs text-gray-400">Origem: <?= e($r['origem']) ?></span><?php endif; ?>
                  </div>
                  <p class="text-sm text-gray-700"><?= e($r['motivo']) ?></p>
                </div>
                <div class="flex flex-col gap-1.5 shrink-0">
                  <button onclick="approve(<?= $r['id'] ?>, this)"
                    class="text-xs bg-green-50 hover:bg-green-100 border border-green-200 text-green-700 px-3 py-1.5 rounded-lg transition font-medium">Aprovar</button>
                  <button onclick="openReject(<?= $r['id'] ?>, '<?= e($r['chamado']) ?>')"
                    class="text-xs bg-red-50 hover:bg-red-100 border border-red-200 text-red-600 px-3 py-1.5 rounded-lg transition font-medium">Recusar</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</main>

<!-- Modal: Recusar -->
<div id="modal-reject" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
    <div class="px-6 py-4 border-b flex items-center justify-between" style="background:var(--hw-gradient)">
      <h2 class="font-semibold text-white">Recusar registro</h2>
      <button onclick="closeReject()" class="text-white/70 hover:text-white text-xl leading-none">&times;</button>
    </div>
    <div class="p-6 space-y-4">
      <p class="text-sm text-gray-600">Chamado: <strong id="reject-chamado" class="font-mono text-gray-800"></strong></p>
      <input type="hidden" id="reject-id">
      <div>
        <label class="hw-label">Motivo da recusa <span class="text-red-500">*</span></label>
        <textarea id="reject-reason" rows="4" required
          placeholder="Descreva o motivo. O colaborador receberá por e-mail."
          class="hw-input resize-none"></textarea>
        <p class="text-xs text-gray-400 mt-1">O colaborador receberá este motivo por e-mail.</p>
      </div>
      <div id="reject-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>
      <div class="flex gap-2 justify-end">
        <button onclick="closeReject()" class="hw-btn-secondary text-sm">Cancelar</button>
        <button onclick="confirmReject()" id="reject-btn" class="hw-btn-danger text-sm px-4 py-2">Confirmar recusa</button>
      </div>
    </div>
  </div>
</div>

<script>
async function approve(id, btn) {
  btn.disabled = true;
  const res = await fetch('/api/validate.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id, action:'approve'})});
  const data = await res.json();
  if (res.ok) { fadeRow(id); } else { alert(data.error ?? 'Erro.'); btn.disabled = false; }
}

async function validateAll(userId, btn) {
  if (!confirm('Aprovar todos os registros pendentes deste colaborador?')) return;
  btn.disabled = true; btn.textContent = 'Aprovando…';
  const section = btn.closest('section');
  const rowIds = [...section.querySelectorAll('[id^="row-"]')].map(el => el.id.replace('row-',''));
  await Promise.all(rowIds.map(id =>
    fetch('/api/validate.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id, action:'approve'})})
  ));
  location.reload();
}

function fadeRow(id) {
  const row = document.getElementById('row-' + id);
  if (row) { row.style.transition = 'opacity .3s'; row.style.opacity = '0'; setTimeout(() => row.remove(), 300); }
}

function openReject(id, chamado) {
  document.getElementById('reject-id').value           = id;
  document.getElementById('reject-chamado').textContent = chamado;
  document.getElementById('reject-reason').value        = '';
  document.getElementById('reject-error').classList.add('hidden');
  document.getElementById('modal-reject').classList.replace('hidden','flex');
  setTimeout(() => document.getElementById('reject-reason').focus(), 100);
}
function closeReject() { document.getElementById('modal-reject').classList.replace('flex','hidden'); }

async function confirmReject() {
  const id     = document.getElementById('reject-id').value;
  const reason = document.getElementById('reject-reason').value.trim();
  const errEl  = document.getElementById('reject-error');
  if (!reason) { errEl.textContent = 'O motivo é obrigatório.'; errEl.classList.remove('hidden'); return; }
  const btn = document.getElementById('reject-btn');
  btn.disabled = true; btn.textContent = 'Enviando…';
  const res  = await fetch('/api/validate.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,action:'reject',reason})});
  const data = await res.json();
  if (!res.ok) { errEl.textContent = data.error ?? 'Erro.'; errEl.classList.remove('hidden'); btn.disabled=false; btn.textContent='Confirmar recusa'; return; }
  fadeRow(id); closeReject();
}

</script>
</body></html>
