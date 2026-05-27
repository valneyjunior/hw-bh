<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireCoordenador();
$db   = getDb();

$isCoordenadorOnly = isCoordenador() && !isAdmin();
$setorFilter       = $isCoordenadorOnly ? (int)$user['setor_id'] : null;

// Coordenador não vê a aba ex-colaboradores
$tab = $_GET['tab'] ?? 'ativos';
if ($isCoordenadorOnly && $tab === 'ex') $tab = 'ativos';

// Setores para o select
if ($isCoordenadorOnly) {
    $stmtS = $db->prepare("SELECT id, nome FROM setores WHERE id = ?");
    $stmtS->execute([$setorFilter]);
    $setores = $stmtS->fetchAll();
} else {
    $setores = $db->query("SELECT id, nome FROM setores ORDER BY nome")->fetchAll();
}

// Perfis para checkboxes (coordenador não pode criar admins)
$perfis = $db->query("SELECT id, nome FROM perfis ORDER BY id")->fetchAll();

// Usuários — filtrado por setor quando coordenador
$statusVal = $tab === 'ex' ? 'ex-colaborador' : ($tab === 'inativos' ? 'inativo' : 'ativo');
$sqlUsers = "
    SELECT u.*,
           s.nome AS setor_nome,
           COALESCE(ARRAY_AGG(p.nome ORDER BY p.nome) FILTER (WHERE p.nome IS NOT NULL), '{}') AS roles_arr
    FROM usuarios u
    LEFT JOIN setores s ON s.id = u.setor_id
    LEFT JOIN usuario_perfis up ON up.usuario_id = u.id
    LEFT JOIN perfis p ON p.id = up.perfil_id
    WHERE u.status = ?
";
$sqlParams = [$statusVal];
if ($setorFilter) { $sqlUsers .= " AND u.setor_id = ?"; $sqlParams[] = $setorFilter; }
$sqlUsers .= " GROUP BY u.id, s.nome ORDER BY u.nome";

$stmtU = $db->prepare($sqlUsers);
$stmtU->execute($sqlParams);
$users = $stmtU->fetchAll();

function parseRoles(mixed $raw): array {
    if (is_array($raw)) return array_filter($raw);
    $clean = trim((string)$raw, '{}');
    return $clean !== '' ? array_map('trim', explode(',', $clean)) : [];
}
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

<main class="max-w-5xl mx-auto px-4 py-6">

  <div class="flex items-center justify-between mb-4">
    <h1 class="text-xl font-bold text-gray-900">Usuários</h1>
    <button onclick="openCreate()" class="hw-btn text-sm px-4 py-2">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Novo usuário
    </button>
  </div>

  <!-- Tabs -->
  <div class="flex gap-1 border-b border-gray-200 mb-5">
    <?php
    $tabs = ['ativos' => 'Ativos', 'inativos' => 'Inativos'];
    if (!$isCoordenadorOnly) $tabs['ex'] = 'Ex-Colaboradores';
    foreach ($tabs as $k => $lbl):
    ?>
      <a href="?tab=<?= $k ?>" class="px-5 py-2.5 text-sm font-medium rounded-t-lg transition
        <?= $tab===$k ? 'bg-white border border-b-white border-gray-200 -mb-px font-semibold' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100' ?>"
        <?= $tab===$k ? 'style="color:var(--hw-red)"' : '' ?>><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>

  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <table class="w-full text-sm">
      <thead class="hw-table-head">
        <tr>
          <th class="px-5 py-3 text-left">Nome</th>
          <th class="px-5 py-3 text-left">E-mail</th>
          <th class="px-5 py-3 text-left">Setor</th>
          <th class="px-5 py-3 text-left">Perfis</th>
          <th class="px-5 py-3"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100" id="user-table">
        <?php foreach ($users as $u):
          $roles = parseRoles($u['roles_arr']);
          $perfilLabels = ['analista'=>'Analista','coordenador'=>'Coordenador','administrador'=>'Admin'];
        ?>
          <tr id="urow-<?= $u['id'] ?>" class="hw-table-row">
            <td class="px-5 py-3 font-medium text-gray-800">
              <div class="flex items-center gap-2">
                <div class="hw-avatar w-7 h-7 text-xs shrink-0">
                  <?= strtoupper(mb_substr($u['nome'],0,1)) ?>
                </div>
                <?= e($u['nome']) ?>
                <?php if ($u['must_change_pass']): ?>
                  <span class="ml-1 text-xs bg-amber-100 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded-full">1º acesso</span>
                <?php endif; ?>
              </div>
            </td>
            <td class="px-5 py-3 text-gray-600"><?= e($u['email']) ?></td>
            <td class="px-5 py-3">
              <?php if ($u['setor_nome']): ?>
                <span class="hw-setor-badge"><?= e($u['setor_nome']) ?></span>
              <?php else: ?><span class="text-gray-400 text-xs">—</span><?php endif; ?>
            </td>
            <td class="px-5 py-3">
              <div class="flex flex-wrap gap-1">
                <?php foreach ($roles as $role): ?>
                  <span class="text-xs font-medium px-2 py-0.5 rounded-full <?= $role==='administrador' ? 'bg-purple-100 text-purple-700 border border-purple-200' : ($role==='coordenador' ? 'bg-blue-100 text-blue-700 border border-blue-200' : 'bg-gray-100 text-gray-600 border border-gray-200') ?>">
                    <?= $perfilLabels[$role] ?? $role ?>
                  </span>
                <?php endforeach; ?>
              </div>
            </td>
            <td class="px-5 py-3">
              <div class="flex gap-3 justify-end text-xs">
                <button data-user="<?= htmlspecialchars(json_encode([
                    'id'              => $u['id'],
                    'nome'            => $u['nome'],
                    'email'           => $u['email'],
                    'setor_id'        => $u['setor_id'],
                    'salario_bruto'   => $u['salario_bruto'],
                    'adicional_atrativo' => $u['adicional_atrativo'],
                    'adicional_valor' => $u['adicional_valor'],
                    'work_start'      => substr($u['work_start']??'08:00',0,5),
                    'work_end'        => substr($u['work_end']??'18:00',0,5),
                    'lunch_start'     => substr($u['lunch_start']??'12:00',0,5),
                    'lunch_minutes'   => (int)($u['lunch_minutes']??60),
                    'roles'           => $roles,
                ]), ENT_QUOTES|ENT_HTML5, 'UTF-8') ?>"
                  class="edit-user-btn font-medium hover:underline" style="color:var(--hw-purple)">Editar</button>
                <button onclick="resetPassword(<?= $u['id'] ?>, '<?= e($u['nome']) ?>')" class="hover:underline" style="color:var(--hw-red)">Resetar senha</button>
                <?php if ($u['id'] !== $user['id'] && !$isCoordenadorOnly): ?>
                  <?php if ($tab === 'ativos'): ?>
                    <button onclick="changeStatus(<?= $u['id'] ?>, 'inativo', this)" class="text-gray-500 hover:underline">Desativar</button>
                    <button onclick="changeStatus(<?= $u['id'] ?>, 'ex-colaborador', this)" class="text-amber-600 hover:underline">Arquivar</button>
                  <?php elseif ($tab === 'inativos'): ?>
                    <button onclick="changeStatus(<?= $u['id'] ?>, 'ativo', this)" class="text-green-600 hover:underline">Ativar</button>
                    <button onclick="changeStatus(<?= $u['id'] ?>, 'ex-colaborador', this)" class="text-amber-600 hover:underline">Arquivar</button>
                  <?php elseif ($tab === 'ex'): ?>
                    <button onclick="changeStatus(<?= $u['id'] ?>, 'ativo', this)" class="text-green-600 hover:underline">Reativar</button>
                    <button onclick="deleteUser(<?= $u['id'] ?>, '<?= e($u['nome']) ?>', this)" class="text-red-600 hover:underline font-medium">Excluir permanentemente</button>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
          <tr><td colspan="5" class="px-5 py-10 text-center text-gray-400 text-sm">Nenhum usuário encontrado.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

<!-- Modal: Criar / Editar usuário -->
<div id="modal-user" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden max-h-[90vh] overflow-y-auto">
    <div class="px-6 py-4 flex items-center justify-between sticky top-0" style="background:var(--hw-gradient)">
      <h2 id="modal-user-title" class="font-semibold text-white">Novo usuário</h2>
      <button onclick="closeUserModal()" class="text-white/70 hover:text-white text-xl leading-none">&times;</button>
    </div>
    <form id="user-form" class="p-6 space-y-4">
      <input type="hidden" id="u-id">

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="hw-label">Nome completo</label>
          <input type="text" id="u-nome" required class="hw-input">
        </div>
        <div>
          <label class="hw-label">E-mail</label>
          <input type="email" id="u-email" required class="hw-input">
        </div>
      </div>

      <div>
        <label class="hw-label">Setor</label>
        <?php if ($isCoordenadorOnly): ?>
          <input type="text" value="<?= e($setores[0]['nome'] ?? '') ?>" readonly class="hw-input bg-gray-50 cursor-not-allowed text-gray-500">
          <input type="hidden" id="u-setor" value="<?= $setorFilter ?>">
        <?php else: ?>
          <select id="u-setor" required class="hw-input">
            <option value="">Selecione um setor</option>
            <?php foreach ($setores as $s): ?>
              <option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>

      <div>
        <label class="hw-label mb-2">Perfis <span class="text-red-500">*</span></label>
        <div class="flex flex-wrap gap-3">
          <?php foreach ($perfis as $p):
            if ($isCoordenadorOnly && $p['nome'] === 'administrador') continue;
          ?>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="perfil" value="<?= $p['id'] ?>" data-nome="<?= $p['nome'] ?>"
              class="w-4 h-4 rounded accent-purple-600">
            <span class="text-sm text-gray-700 capitalize"><?= $p['nome'] ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="hw-label">Salário bruto (R$)</label>
          <input type="number" id="u-salario" step="0.01" min="0" placeholder="0,00" class="hw-input">
        </div>
        <div id="adicional-section">
          <label class="hw-label">Adicional Atrativo</label>
          <label class="flex items-center gap-2 mb-2 cursor-pointer">
            <input type="checkbox" id="u-adicional" onchange="toggleAdicional()" class="w-4 h-4 accent-purple-600">
            <span class="text-sm text-gray-700">Sim</span>
          </label>
          <input type="number" id="u-valor-adicional" step="0.01" min="0" placeholder="Valor em R$"
            class="hw-input hidden">
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="hw-label">Início da jornada</label>
          <input type="text" id="u-work-start" class="hw-input font-mono" maxlength="5" placeholder="HH:MM" oninput="applyTimeMask(this)">
        </div>
        <div>
          <label class="hw-label">Fim da jornada</label>
          <input type="text" id="u-work-end" class="hw-input font-mono" maxlength="5" placeholder="HH:MM" oninput="applyTimeMask(this)">
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="hw-label">Almoço — início</label>
          <input type="text" id="u-lunch-start" class="hw-input font-mono" maxlength="5" placeholder="HH:MM" oninput="applyTimeMask(this)">
        </div>
        <div>
          <label class="hw-label">Duração do almoço</label>
          <select id="u-lunch-minutes" class="hw-input">
            <option value="30">30 min</option>
            <option value="60">60 min (1h)</option>
            <option value="90">90 min (1h30)</option>
            <option value="120">120 min (2h)</option>
          </select>
        </div>
      </div>

      <div id="user-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>
      <div class="flex gap-2 justify-end pt-1">
        <button type="button" onclick="closeUserModal()" class="hw-btn-secondary text-sm">Cancelar</button>
        <button type="submit" id="u-btn" class="hw-btn px-4 py-2 text-sm">Salvar</button>
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
    <p class="text-sm text-gray-500 mb-4">Compartilhe com o usuário:</p>
    <div class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 mb-4">
      <p id="pass-value" class="font-mono text-xl font-bold text-gray-900 tracking-widest"></p>
    </div>
    <p class="text-xs text-gray-400 mb-5">O usuário deverá redefinir a senha no primeiro acesso.</p>
    <button onclick="copyPass()" id="copy-btn" class="hw-btn w-full py-2.5 text-sm justify-center mb-2">Copiar senha</button>
    <button onclick="closePass()" class="w-full border border-gray-300 text-sm text-gray-600 hover:bg-gray-50 py-2.5 rounded-lg transition">Fechar</button>
  </div>
</div>

<script>
function applyTimeMask(el) {
  let v = el.value.replace(/\D/g, '').slice(0, 4);
  if (v.length >= 3) v = v.slice(0, 2) + ':' + v.slice(2);
  el.value = v;
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.edit-user-btn').forEach(btn => {
    btn.addEventListener('click', () => openEdit(JSON.parse(btn.dataset.user)));
  });
});

let pendingReload = false;

function openCreate() {
  document.getElementById('modal-user-title').textContent = 'Novo usuário';
  document.getElementById('u-id').value = '';
  document.getElementById('user-form').reset();
  document.getElementById('u-work-start').value    = '08:00';
  document.getElementById('u-work-end').value      = '18:00';
  document.getElementById('u-lunch-start').value   = '12:00';
  document.getElementById('u-lunch-minutes').value = '60';
  document.getElementById('u-valor-adicional').classList.add('hidden');
  document.getElementById('user-error').classList.add('hidden');
  document.getElementById('modal-user').classList.replace('hidden','flex');
}

function openEdit(u) {
  document.getElementById('modal-user-title').textContent = 'Editar colaborador';
  document.getElementById('u-id').value          = u.id;
  document.getElementById('u-nome').value         = u.nome;
  document.getElementById('u-email').value        = u.email;
  document.getElementById('u-setor').value        = u.setor_id ?? '';
  document.getElementById('u-salario').value      = u.salario_bruto ?? 0;
  document.getElementById('u-adicional').checked  = !!u.adicional_atrativo;
  document.getElementById('u-valor-adicional').value = u.adicional_valor ?? '';
  document.getElementById('u-valor-adicional').classList.toggle('hidden', !u.adicional_atrativo);
  document.getElementById('u-work-start').value    = u.work_start    ?? '08:00';
  document.getElementById('u-work-end').value      = u.work_end      ?? '18:00';
  document.getElementById('u-lunch-start').value   = u.lunch_start   ?? '12:00';
  document.getElementById('u-lunch-minutes').value = u.lunch_minutes ?? 60;

  document.querySelectorAll('input[name="perfil"]').forEach(cb => {
    cb.checked = (u.roles ?? []).includes(cb.dataset.nome);
  });

  document.getElementById('user-error').classList.add('hidden');
  document.getElementById('modal-user').classList.replace('hidden','flex');
}

function closeUserModal() { document.getElementById('modal-user').classList.replace('flex','hidden'); }

function toggleAdicional() {
  const checked = document.getElementById('u-adicional').checked;
  document.getElementById('u-valor-adicional').classList.toggle('hidden', !checked);
}

document.getElementById('user-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const id    = document.getElementById('u-id').value;
  const btn   = document.getElementById('u-btn');
  btn.disabled = true; btn.textContent = id ? 'Salvando…' : 'Criando…';

  const perfis = [...document.querySelectorAll('input[name="perfil"]:checked')].map(cb => cb.dataset.nome);
  if (perfis.length === 0) {
    const errEl = document.getElementById('user-error');
    errEl.textContent = 'Selecione ao menos um perfil.'; errEl.classList.remove('hidden');
    btn.disabled = false; btn.textContent = id ? 'Salvar' : 'Criar'; return;
  }

  const body = {
    action:                    id ? 'update' : 'create',
    id,
    nome:                      document.getElementById('u-nome').value,
    email:                     document.getElementById('u-email').value,
    setor_id:                  document.getElementById('u-setor').value,
    perfis,
    salario_bruto:             parseFloat(document.getElementById('u-salario').value) || 0,
    adicional_atrativo: document.getElementById('u-adicional').checked,
    adicional_valor:    document.getElementById('u-adicional').checked
                        ? parseFloat(document.getElementById('u-valor-adicional').value) || 0 : 0,
    work_start:                document.getElementById('u-work-start').value,
    work_end:                  document.getElementById('u-work-end').value,
    lunch_start:               document.getElementById('u-lunch-start').value,
    lunch_minutes:             parseInt(document.getElementById('u-lunch-minutes').value) || 60,
  };

  const res  = await fetch('/api/users.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
  const data = await res.json();
  btn.disabled = false; btn.textContent = id ? 'Salvar' : 'Criar';

  if (!res.ok) {
    document.getElementById('user-error').textContent = data.error;
    document.getElementById('user-error').classList.remove('hidden'); return;
  }
  closeUserModal();
  if (data.temp_password) { pendingReload = true; showPass(data.nome, data.temp_password); }
  else location.reload();
});

function showPass(nome, pass) {
  document.getElementById('pass-title').textContent = 'Usuário: ' + nome;
  document.getElementById('pass-value').textContent = pass;
  document.getElementById('modal-pass').classList.replace('hidden','flex');
}
function closePass() {
  document.getElementById('modal-pass').classList.replace('flex','hidden');
  if (pendingReload) location.reload();
}
function copyPass() {
  navigator.clipboard.writeText(document.getElementById('pass-value').textContent).then(() => {
    const btn = document.getElementById('copy-btn');
    const orig = btn.textContent; btn.textContent = 'Copiado!';
    setTimeout(() => btn.textContent = orig, 2000);
  });
}

async function resetPassword(id, nome) {
  if (!confirm(`Resetar a senha de ${nome}?`)) return;
  const res  = await fetch('/api/users.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'reset_password',id})});
  const data = await res.json();
  if (!res.ok) { alert(data.error ?? 'Erro.'); return; }
  pendingReload = false; showPass(nome, data.temp_password);
}

async function changeStatus(id, status, btn) {
  const msgs = {inativo:'Desativar este usuário?','ex-colaborador':'Arquivar como ex-colaborador?',ativo:'Reativar este usuário?'};
  if (!confirm(msgs[status] ?? 'Confirmar?')) return;
  btn.disabled = true;
  const res = await fetch('/api/users.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'change_status',id,status})});
  if (res.ok) location.reload(); else { alert('Erro.'); btn.disabled=false; }
}

async function deleteUser(id, nome, btn) {
  if (!confirm(`ATENÇÃO: Isso removerá permanentemente ${nome} e todos os seus dados (lançamentos, solicitações, histórico). Esta ação não pode ser desfeita.\n\nDeseja continuar?`)) return;
  if (!confirm(`Confirme novamente: excluir permanentemente ${nome}?`)) return;
  btn.disabled = true;
  const res = await fetch('/api/users.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_permanent',id})});
  if (res.ok) location.reload(); else { const d=await res.json(); alert(d.error??'Erro.'); btn.disabled=false; }
}

</script>
</body></html>
