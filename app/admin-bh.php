<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireCoordenador();
$db   = getDb();

$setorFilter = isAdmin() ? null : $user['setor_id'];
$setorNome   = isAdmin() ? 'Todos os setores' : ($user['setor_nome'] ?? '');

$sql = "
    SELECT s.*, u.nome AS user_nome, u.email AS user_email, st.nome AS setor_nome,
           rev.nome AS revisor_nome
    FROM solicitacoes_bh s
    JOIN usuarios u ON u.id = s.usuario_id
    LEFT JOIN setores st ON st.id = u.setor_id
    LEFT JOIN usuarios rev ON rev.id = s.revisado_por
    WHERE 1=1
";
$params = [];
if ($setorFilter) { $sql .= " AND u.setor_id = ?"; $params[] = $setorFilter; }
$sql .= " ORDER BY s.criado_em DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$solicitacoes = $stmt->fetchAll();

$pending  = array_filter($solicitacoes, fn($s) => $s['status'] === 'pendente');
$reviewed = array_filter($solicitacoes, fn($s) => $s['status'] !== 'pendente');

$tipoLabels = [
    'dia_inteiro'        => 'Dia inteiro',
    'meio_periodo_manha' => 'Meio período — Manhã',
    'meio_periodo_tarde' => 'Meio período — Tarde',
    'personalizado'      => 'Personalizado',
    'deducao_admin'      => 'Dedução admin',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Banco de Horas</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-4xl mx-auto px-4 py-6 space-y-6">

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Banco de Horas — Solicitações</h1>
      <p class="text-sm text-gray-500"><?= e($setorNome) ?></p>
    </div>
    <?php if (isAdmin()): ?>
    <button onclick="openDeducao()" class="hw-btn text-sm px-4 py-2">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg>
      Lançar dedução
    </button>
    <?php endif; ?>
  </div>

  <?php if (!empty($pending)): ?>
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-5 py-3 border-b border-amber-100 bg-amber-50/50">
      <span class="hw-badge-pendente font-semibold">Aguardando aprovação · <?= count($pending) ?></span>
    </div>
    <div class="divide-y divide-gray-100">
      <?php foreach ($pending as $s):
        $dateLabel = fmtDate($s['data_inicio']);
        if ($s['data_fim'] && $s['data_fim'] !== $s['data_inicio']) $dateLabel .= ' a ' . fmtDate($s['data_fim']);
      ?>
        <div id="sbh-<?= $s['id'] ?>" class="px-5 py-3 flex items-start gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-2 mb-0.5">
              <div class="hw-avatar w-7 h-7 text-xs shrink-0"><?= strtoupper(mb_substr($s['user_nome'],0,1)) ?></div>
              <span class="font-medium text-gray-800"><?= e($s['user_nome']) ?></span>
              <?php if ($s['setor_nome']): ?><span class="hw-setor-badge"><?= e($s['setor_nome']) ?></span><?php endif; ?>
              <span class="text-xs font-semibold text-gray-800"><?= minutesToHHMM((int)$s['total_minutos']) ?> h</span>
              <span class="text-xs hw-setor-badge"><?= $tipoLabels[$s['tipo']] ?? $s['tipo'] ?></span>
              <span class="text-xs text-gray-500"><?= $dateLabel ?></span>
            </div>
            <?php if ($s['motivo']): ?><p class="text-sm text-gray-600 ml-9"><?= e($s['motivo']) ?></p><?php endif; ?>
          </div>
          <div class="flex gap-1.5 shrink-0">
            <button onclick="review(<?= $s['id'] ?>, 'aprovado', this)" class="hw-btn-primary text-xs px-3 py-1.5">Aprovar</button>
            <button onclick="openRejectBh(<?= $s['id'] ?>, '<?= e($s['user_nome']) ?>')" class="hw-btn-danger text-xs px-3 py-1.5">Rejeitar</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php else: ?>
  <div class="text-center py-12 text-gray-400"><p class="text-sm">Nenhuma solicitação pendente.</p></div>
  <?php endif; ?>

  <?php if (!empty($reviewed)): ?>
  <section>
    <h2 class="text-sm font-semibold text-gray-700 mb-3">Histórico</h2>
    <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
      <table class="w-full text-sm">
        <thead class="hw-table-head">
          <tr>
            <th class="px-4 py-3 text-left">Colaborador</th>
            <th class="px-4 py-3 text-left">Tipo</th>
            <th class="px-4 py-3 text-left">Data</th>
            <th class="px-4 py-3 text-left">Duração</th>
            <th class="px-4 py-3 text-left">Status</th>
            <th class="px-4 py-3 text-left">Revisado por</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach (array_slice(array_values($reviewed), 0, 50) as $s):
            $dateLabel = fmtDate($s['data_inicio']);
            if ($s['data_fim'] && $s['data_fim'] !== $s['data_inicio']) $dateLabel .= ' a ' . fmtDate($s['data_fim']);
            $badge = match($s['status']){ 'aprovado'=>'hw-badge-aprovado','recusado'=>'hw-badge-recusado',default=>'hw-badge-pendente' };
            $label = match($s['status']){ 'aprovado'=>'Aprovado','recusado'=>'Rejeitado',default=>'Pendente' };
          ?>
            <tr class="hw-table-row">
              <td class="px-4 py-2.5 font-medium text-gray-800"><?= e($s['user_nome']) ?></td>
              <td class="px-4 py-2.5 text-gray-600"><?= $tipoLabels[$s['tipo']] ?? $s['tipo'] ?></td>
              <td class="px-4 py-2.5 text-gray-600 whitespace-nowrap"><?= $dateLabel ?></td>
              <td class="px-4 py-2.5 font-semibold"><?= minutesToHHMM((int)$s['total_minutos']) ?> h</td>
              <td class="px-4 py-2.5"><span class="<?= $badge ?>"><?= $label ?></span></td>
              <td class="px-4 py-2.5 text-gray-600"><?= e($s['revisor_nome'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

</main>

<!-- Modal: Rejeitar BH -->
<div id="modal-reject-bh" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
    <div class="px-6 py-4 border-b flex items-center justify-between" style="background:var(--hw-gradient)">
      <h2 class="font-semibold text-white">Rejeitar solicitação</h2>
      <button onclick="closeRejectBh()" class="text-white/70 hover:text-white text-xl leading-none">&times;</button>
    </div>
    <div class="p-6 space-y-4">
      <p class="text-sm text-gray-600">Colaborador: <strong id="rbh-nome"></strong></p>
      <input type="hidden" id="rbh-id">
      <div>
        <label class="hw-label">Motivo da rejeição <span class="text-red-500">*</span></label>
        <textarea id="rbh-reason" rows="3" class="hw-input resize-none" placeholder="Informe o motivo..."></textarea>
      </div>
      <div id="rbh-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>
      <div class="flex gap-2 justify-end">
        <button onclick="closeRejectBh()" class="hw-btn-secondary text-sm">Cancelar</button>
        <button onclick="confirmRejectBh()" id="rbh-btn" class="hw-btn-danger text-sm px-4 py-2">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<?php if (isAdmin()): ?>
<!-- Modal: Dedução admin -->
<div id="modal-deducao" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
    <div class="px-6 py-4 border-b flex items-center justify-between" style="background:var(--hw-gradient)">
      <h2 class="font-semibold text-white">Lançar dedução manual</h2>
      <button onclick="closeDeducao()" class="text-white/70 hover:text-white text-xl leading-none">&times;</button>
    </div>
    <div class="p-6 space-y-4">
      <div>
        <label class="hw-label">Colaborador</label>
        <select id="ded-user" class="hw-input">
          <?php
          $allUsers = $db->query("SELECT id, nome FROM usuarios WHERE status='ativo' ORDER BY nome")->fetchAll();
          foreach ($allUsers as $u): ?>
            <option value="<?= $u['id'] ?>"><?= e($u['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="hw-label">Horas</label><input type="number" id="ded-horas" min="0" max="999" class="hw-input" placeholder="0"></div>
        <div><label class="hw-label">Minutos</label><input type="number" id="ded-minutos" min="0" max="59" class="hw-input" placeholder="0"></div>
      </div>
      <div>
        <label class="hw-label">Motivo</label>
        <input type="text" id="ded-motivo" class="hw-input" placeholder="Ex: Atraso injustificado em 05/05">
      </div>
      <div id="ded-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>
      <div class="flex gap-2 justify-end">
        <button onclick="closeDeducao()" class="hw-btn-secondary text-sm">Cancelar</button>
        <button onclick="saveDeducao()" id="ded-btn" class="hw-btn px-4 py-2 text-sm">Lançar</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
async function review(id, status, btn) {
  btn.disabled = true;
  const res = await fetch('/api/bh-requests.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'review',id,status})});
  if(res.ok){const row=document.getElementById('sbh-'+id);if(row){row.style.opacity='0';setTimeout(()=>row.remove(),300);}}
  else{const d=await res.json();alert(d.error??'Erro.');btn.disabled=false;}
}
function openRejectBh(id,nome){document.getElementById('rbh-id').value=id;document.getElementById('rbh-nome').textContent=nome;document.getElementById('rbh-reason').value='';document.getElementById('rbh-error').classList.add('hidden');document.getElementById('modal-reject-bh').classList.replace('hidden','flex');}
function closeRejectBh(){document.getElementById('modal-reject-bh').classList.replace('flex','hidden');}
async function confirmRejectBh(){
  const id=document.getElementById('rbh-id').value,reason=document.getElementById('rbh-reason').value.trim();
  const errEl=document.getElementById('rbh-error');
  if(!reason){errEl.textContent='Motivo obrigatório.';errEl.classList.remove('hidden');return;}
  const btn=document.getElementById('rbh-btn');btn.disabled=true;btn.textContent='Enviando…';
  const res=await fetch('/api/bh-requests.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'review',id,status:'recusado',nota_revisao:reason})});
  if(res.ok){const row=document.getElementById('sbh-'+id);if(row){row.style.opacity='0';setTimeout(()=>row.remove(),300);}closeRejectBh();}
  else{const d=await res.json();errEl.textContent=d.error??'Erro.';errEl.classList.remove('hidden');btn.disabled=false;btn.textContent='Confirmar';}
}
function openDeducao(){document.getElementById('modal-deducao')?.classList.replace('hidden','flex');}
function closeDeducao(){document.getElementById('modal-deducao')?.classList.replace('flex','hidden');}
async function saveDeducao(){
  const uid=document.getElementById('ded-user').value;
  const h=parseInt(document.getElementById('ded-horas').value)||0;
  const m=parseInt(document.getElementById('ded-minutos').value)||0;
  const motivo=document.getElementById('ded-motivo').value.trim();
  const errEl=document.getElementById('ded-error');
  if(h+m<=0){errEl.textContent='Informe ao menos 1 minuto.';errEl.classList.remove('hidden');return;}
  if(!motivo){errEl.textContent='Motivo obrigatório.';errEl.classList.remove('hidden');return;}
  const btn=document.getElementById('ded-btn');btn.disabled=true;btn.textContent='Lançando…';
  const res=await fetch('/api/bh-requests.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'admin_deduction',usuario_id:uid,total_minutos:h*60+m,motivo})});
  if(res.ok){closeDeducao();location.reload();}
  else{const d=await res.json();errEl.textContent=d.error??'Erro.';errEl.classList.remove('hidden');btn.disabled=false;btn.textContent='Lançar';}
}
</script>
</body></html>
