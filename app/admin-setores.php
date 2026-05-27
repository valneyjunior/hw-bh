<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireAdmin();
$db   = getDb();

$setores = $db->query("
    SELECT s.id, s.nome,
           COUNT(u.id) AS total_usuarios
    FROM setores s
    LEFT JOIN usuarios u ON u.setor_id = s.id AND u.status != 'ex-colaborador'
    GROUP BY s.id, s.nome
    ORDER BY s.nome
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Setores</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-3xl mx-auto px-4 py-6 space-y-6">

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Setores</h1>
      <p class="text-sm text-gray-500">Gerencie os setores da empresa</p>
    </div>
    <button onclick="openCreate()" class="hw-btn text-sm px-4 py-2">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Novo setor
    </button>
  </div>

  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <table class="w-full text-sm">
      <thead class="hw-table-head">
        <tr>
          <th class="px-5 py-3 text-left">Nome do setor</th>
          <th class="px-5 py-3 text-center">Colaboradores</th>
          <th class="px-5 py-3"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100" id="setores-tbody">
        <?php foreach ($setores as $s): ?>
          <tr id="srow-<?= $s['id'] ?>" class="hw-table-row">
            <td class="px-5 py-3 font-medium text-gray-800">
              <div class="flex items-center gap-2">
                <div class="w-2 h-2 rounded-full shrink-0" style="background:var(--hw-gradient)"></div>
                <?= e($s['nome']) ?>
              </div>
            </td>
            <td class="px-5 py-3 text-center">
              <span class="<?= (int)$s['total_usuarios'] > 0 ? 'hw-setor-badge' : 'text-gray-400 text-xs' ?>">
                <?= (int)$s['total_usuarios'] > 0 ? $s['total_usuarios'] . ' ativo(s)' : 'Sem usuários' ?>
              </span>
            </td>
            <td class="px-5 py-3">
              <div class="flex gap-3 justify-end text-xs">
                <button onclick="openEdit(<?= $s['id'] ?>, '<?= e($s['nome']) ?>')"
                  class="font-medium hover:underline" style="color:var(--hw-purple)">Renomear</button>
                <?php if ((int)$s['total_usuarios'] === 0): ?>
                  <button onclick="deleteSetor(<?= $s['id'] ?>, '<?= e($s['nome']) ?>', this)"
                    class="text-red-500 hover:underline">Excluir</button>
                <?php else: ?>
                  <span class="text-gray-300 cursor-not-allowed" title="Remova os colaboradores antes de excluir">Excluir</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($setores)): ?>
          <tr><td colspan="3" class="px-5 py-10 text-center text-gray-400">Nenhum setor cadastrado.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</main>

<!-- Modal: Criar / Renomear setor -->
<div id="modal-setor" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
    <div class="px-6 py-4 border-b flex items-center justify-between" style="background:var(--hw-gradient)">
      <h2 id="modal-setor-title" class="font-semibold text-white">Novo setor</h2>
      <button type="button" onclick="closeSetor()" class="text-white/70 hover:text-white text-xl leading-none">&times;</button>
    </div>
    <form id="setor-form" class="p-6 space-y-4">
      <input type="hidden" id="s-id">
      <div>
        <label class="hw-label">Nome do setor</label>
        <input type="text" id="s-nome" required maxlength="100"
          placeholder="Ex: Redes, Sustentação, Segurança…" class="hw-input">
      </div>
      <div id="setor-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>
      <div class="flex gap-2 justify-end">
        <button type="button" onclick="closeSetor()" class="hw-btn-secondary text-sm">Cancelar</button>
        <button type="submit" id="s-btn" class="hw-btn px-4 py-2 text-sm">Salvar</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCreate() {
  document.getElementById('modal-setor-title').textContent = 'Novo setor';
  document.getElementById('s-id').value   = '';
  document.getElementById('s-nome').value = '';
  document.getElementById('setor-error').classList.add('hidden');
  document.getElementById('modal-setor').classList.replace('hidden','flex');
  setTimeout(() => document.getElementById('s-nome').focus(), 50);
}

function openEdit(id, nome) {
  document.getElementById('modal-setor-title').textContent = 'Renomear setor';
  document.getElementById('s-id').value   = id;
  document.getElementById('s-nome').value = nome;
  document.getElementById('setor-error').classList.add('hidden');
  document.getElementById('modal-setor').classList.replace('hidden','flex');
  setTimeout(() => document.getElementById('s-nome').focus(), 50);
}

function closeSetor() {
  document.getElementById('modal-setor').classList.replace('flex','hidden');
}

document.getElementById('setor-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const id   = document.getElementById('s-id').value;
  const nome = document.getElementById('s-nome').value.trim();
  const btn  = document.getElementById('s-btn');
  const errEl = document.getElementById('setor-error');
  btn.disabled = true; btn.textContent = 'Salvando…';

  try {
    const res  = await fetch('/api/setores.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action: id ? 'update' : 'create', id: id ? parseInt(id) : undefined, nome})
    });
    const data = await res.json();
    if (!res.ok) {
      errEl.textContent = data.error ?? 'Erro ao salvar.';
      errEl.classList.remove('hidden');
    } else { location.reload(); }
  } catch {
    errEl.textContent = 'Erro de comunicação.';
    errEl.classList.remove('hidden');
  }
  btn.disabled = false; btn.textContent = 'Salvar';
});

async function deleteSetor(id, nome, btn) {
  if (!confirm(`Excluir o setor "${nome}"? Esta ação não pode ser desfeita.`)) return;
  btn.disabled = true;
  try {
    const res  = await fetch('/api/setores.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'delete', id})
    });
    const data = await res.json();
    if (res.ok) location.reload();
    else { alert(data.error ?? 'Erro ao excluir.'); btn.disabled = false; }
  } catch { alert('Erro de comunicação.'); btn.disabled = false; }
}
</script>
</body></html>
