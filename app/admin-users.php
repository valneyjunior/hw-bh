<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireAdmin();

$db    = getDb();
$stmt  = $db->query("SELECT id, name, email, role, active, must_change_pass, created_at FROM users ORDER BY name");
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Usuários</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-4xl mx-auto px-4 py-6">
  <div class="flex items-center justify-between mb-6">
    <h1 class="text-xl font-bold text-gray-900">Usuários</h1>
    <button onclick="openCreate()" class="hw-btn text-sm px-4 py-2">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Novo usuário
    </button>
  </div>

  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-gray-100 text-left" style="background:linear-gradient(135deg,rgba(232,0,28,.04),rgba(107,15,168,.04))">
          <th class="px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Nome</th>
          <th class="px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">E-mail</th>
          <th class="px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Perfil</th>
          <th class="px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
          <th class="px-5 py-3"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100" id="user-table">
        <?php foreach ($users as $u): ?>
          <tr id="urow-<?= $u['id'] ?>" class="hover:bg-gray-50 transition">
            <td class="px-5 py-3 font-medium text-gray-800">
              <div class="flex items-center gap-2">
                <div class="hw-avatar w-7 h-7 text-xs shrink-0">
                  <?= strtoupper(mb_substr($u['name'], 0, 1)) ?>
                </div>
                <?= e($u['name']) ?>
                <?php if ($u['must_change_pass']): ?>
                  <span class="ml-1 text-xs bg-amber-100 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded-full">1º acesso</span>
                <?php endif; ?>
              </div>
            </td>
            <td class="px-5 py-3 text-gray-600"><?= e($u['email']) ?></td>
            <td class="px-5 py-3">
              <span class="text-xs font-medium px-2 py-0.5 rounded-full
                <?= $u['role'] === 'admin' ? '' : 'bg-gray-100 text-gray-600 border border-gray-200' ?>"
                <?= $u['role'] === 'admin' ? 'style="background:linear-gradient(135deg,rgba(107,15,168,.1),rgba(232,0,28,.08));color:var(--hw-purple);border:1px solid rgba(107,15,168,.2)"' : '' ?>>
                <?= $u['role'] === 'admin' ? 'Admin' : 'Colaborador' ?>
              </span>
            </td>
            <td class="px-5 py-3">
              <span class="text-xs font-medium px-2 py-0.5 rounded-full
                <?= $u['active'] ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200' ?>">
                <?= $u['active'] ? 'Ativo' : 'Inativo' ?>
              </span>
            </td>
            <td class="px-5 py-3">
              <div class="flex gap-3 justify-end text-xs">
                <button data-user="<?= htmlspecialchars(json_encode(['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']]), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                  class="edit-user-btn font-medium hover:underline" style="color:var(--hw-purple)">Editar</button>
                <button onclick="resetPassword('<?= $u['id'] ?>', '<?= e($u['name']) ?>')" class="font-medium hover:underline" style="color:var(--hw-red)">Resetar senha</button>
                <?php if ($u['id'] !== $user['id']): ?>
                  <button onclick="toggleActive('<?= $u['id'] ?>', <?= $u['active'] ? 'true' : 'false' ?>, this)"
                    class="<?= $u['active'] ? 'text-red-500' : 'text-green-600' ?> hover:underline font-medium">
                    <?= $u['active'] ? 'Desativar' : 'Ativar' ?>
                  </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>

<!-- Modal: editar usuário -->
<div id="modal-edit" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
    <div class="px-6 py-4 flex items-center justify-between" style="background:var(--hw-gradient)">
      <h2 class="font-semibold text-white">Editar colaborador</h2>
      <button onclick="closeEdit()" class="text-white/70 hover:text-white text-xl leading-none">&times;</button>
    </div>
    <form id="edit-form" class="p-6 space-y-4">
      <input type="hidden" id="edit-id">
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Nome completo</label>
        <input type="text" id="edit-name" required class="hw-input px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">E-mail</label>
        <input type="email" id="edit-email" required class="hw-input px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Perfil</label>
        <select id="edit-role" class="hw-input px-3 py-2 text-sm">
          <option value="collaborator">Colaborador</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div id="edit-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>
      <div class="flex gap-2 justify-end pt-1">
        <button type="button" onclick="closeEdit()"
          class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancelar</button>
        <button type="submit" id="edit-btn" class="hw-btn px-4 py-2 text-sm">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: novo usuário -->
<div id="modal-create" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
    <div class="px-6 py-4 flex items-center justify-between" style="background:var(--hw-gradient)">
      <h2 class="font-semibold text-white">Novo usuário</h2>
      <button onclick="closeCreate()" class="text-white/70 hover:text-white text-xl leading-none">&times;</button>
    </div>
    <form id="create-form" class="p-6 space-y-4">
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Nome completo</label>
        <input type="text" id="new-name" required class="hw-input px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">E-mail</label>
        <input type="email" id="new-email" required class="hw-input px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-700 mb-1">Perfil</label>
        <select id="new-role" class="hw-input px-3 py-2 text-sm">
          <option value="collaborator">Colaborador</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div id="create-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>
      <div class="flex gap-2 justify-end pt-1">
        <button type="button" onclick="closeCreate()"
          class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancelar</button>
        <button type="submit" id="create-btn" class="hw-btn px-4 py-2 text-sm">Criar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: senha temporária -->
<div id="modal-pass" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 text-center">
    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-green-100 mb-4">
      <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
      </svg>
    </div>
    <h3 id="pass-title" class="font-semibold text-gray-900 mb-1">Senha temporária</h3>
    <p id="pass-subtitle" class="text-sm text-gray-500 mb-4">Compartilhe com o usuário:</p>
    <div class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 mb-4">
      <p id="pass-value" class="font-mono text-xl font-bold text-gray-900 tracking-widest"></p>
    </div>
    <p class="text-xs text-gray-400 mb-5">O usuário deverá redefinir a senha no primeiro acesso.</p>
    <button onclick="copyPass()" id="copy-btn" class="hw-btn w-full py-2.5 text-sm justify-center mb-2">
      Copiar senha
    </button>
    <button onclick="closePass()"
      class="w-full border border-gray-300 text-sm text-gray-600 hover:bg-gray-50 py-2.5 rounded-lg transition">
      Fechar
    </button>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.edit-user-btn').forEach(btn => {
    btn.addEventListener('click', () => openEdit(JSON.parse(btn.dataset.user)));
  });
});

function openCreate() {
  document.getElementById('create-form').reset();
  document.getElementById('create-error').classList.add('hidden');
  document.getElementById('modal-create').classList.replace('hidden','flex');
}
function closeCreate() {
  document.getElementById('modal-create').classList.replace('flex','hidden');
}

document.getElementById('create-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('create-btn');
  btn.disabled = true; btn.textContent = 'Criando…';

  const res  = await fetch('/api/users.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      action: 'create',
      name:   document.getElementById('new-name').value,
      email:  document.getElementById('new-email').value,
      role:   document.getElementById('new-role').value,
    })
  });
  const data = await res.json();
  btn.disabled = false; btn.textContent = 'Criar';

  if (!res.ok) {
    document.getElementById('create-error').textContent = data.error;
    document.getElementById('create-error').classList.remove('hidden');
    return;
  }
  closeCreate();
  showPass(data.name, data.temp_password);
});

let pendingReload = false;
function showPass(name, pass) {
  document.getElementById('pass-title').textContent = 'Usuário criado: ' + name;
  document.getElementById('pass-value').textContent = pass;
  document.getElementById('modal-pass').classList.replace('hidden','flex');
  pendingReload = true;
}
function closePass() {
  document.getElementById('modal-pass').classList.replace('flex','hidden');
  if (pendingReload) location.reload();
}
function copyPass() {
  navigator.clipboard.writeText(document.getElementById('pass-value').textContent).then(() => {
    const btn = document.getElementById('copy-btn');
    btn.textContent = 'Copiado!';
    setTimeout(() => btn.textContent = 'Copiar senha', 2000);
  });
}

async function resetPassword(id, name) {
  if (!confirm(`Resetar a senha de ${name}?`)) return;
  const res  = await fetch('/api/users.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'reset_password', id})
  });
  const data = await res.json();
  if (!res.ok) { alert(data.error ?? 'Erro ao resetar senha.'); return; }
  pendingReload = false;
  showPass(name, data.temp_password);
}

async function toggleActive(id, isActive, btn) {
  if (!confirm(isActive ? 'Desativar este usuário?' : 'Ativar este usuário?')) return;
  btn.disabled = true;
  const res  = await fetch('/api/users.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'toggle_active', id})
  });
  const data = await res.json();
  if (!res.ok) { alert(data.error ?? 'Erro.'); btn.disabled = false; return; }
  location.reload();
}

document.getElementById('modal-create').addEventListener('click', function(e) {
  if (e.target === this) closeCreate();
});

function openEdit(u) {
  document.getElementById('edit-id').value    = u.id;
  document.getElementById('edit-name').value  = u.name;
  document.getElementById('edit-email').value = u.email;
  document.getElementById('edit-role').value  = u.role;
  document.getElementById('edit-error').classList.add('hidden');
  document.getElementById('modal-edit').classList.replace('hidden','flex');
}
function closeEdit() {
  document.getElementById('modal-edit').classList.replace('flex','hidden');
}
document.getElementById('edit-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('edit-btn');
  btn.disabled = true; btn.textContent = 'Salvando…';

  const res  = await fetch('/api/users.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      action: 'update',
      id:     document.getElementById('edit-id').value,
      name:   document.getElementById('edit-name').value,
      email:  document.getElementById('edit-email').value,
      role:   document.getElementById('edit-role').value,
    })
  });
  const data = await res.json();
  btn.disabled = false; btn.textContent = 'Salvar';
  if (!res.ok) {
    document.getElementById('edit-error').textContent = data.error;
    document.getElementById('edit-error').classList.remove('hidden');
    return;
  }
  location.reload();
});
document.getElementById('modal-edit').addEventListener('click', function(e) {
  if (e.target === this) closeEdit();
});
</script>
</body></html>
