<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

if (currentUser()) {
    header('Location: ' . homeUrl()); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';

    $stmt = getDb()->prepare("
        SELECT u.*, s.nome AS setor_nome,
               ARRAY_AGG(p.nome ORDER BY p.nome) AS roles_arr
        FROM usuarios u
        LEFT JOIN setores s ON s.id = u.setor_id
        LEFT JOIN usuario_perfis up ON up.usuario_id = u.id
        LEFT JOIN perfis p ON p.id = up.perfil_id
        WHERE u.email = ? AND u.status = 'ativo'
        GROUP BY u.id, s.nome
    ");
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if ($row && password_verify($pass, $row['senha_hash'])) {
        // PostgreSQL retorna array como string: {analista,coordenador}
        $rolesRaw = $row['roles_arr'] ?? '{}';
        $roles = [];
        if (is_string($rolesRaw)) {
            $clean = trim($rolesRaw, '{}');
            $roles = $clean !== '' ? array_map('trim', explode(',', $clean)) : [];
        } elseif (is_array($rolesRaw)) {
            $roles = $rolesRaw;
        }

        loginUser($row, $roles);
        header('Location: ' . ($row['must_change_pass'] ? '/change-password.php' : homeUrl()));
        exit;
    }
    $error = 'E-mail ou senha incorretos.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Login</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="hw-login-bg flex items-center justify-center p-4">
<div class="w-full max-w-sm">
  <div class="hw-login-card">

    <div class="hw-login-header">
      <div class="inline-flex items-center justify-center w-14 h-14 bg-white/20 rounded-2xl mb-4">
        <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
      </div>
      <h1 class="text-2xl font-bold text-white">BH Tecnologia</h1>
      <p class="text-sm text-white/75 mt-1">Hostweb · Controle de Banco de Horas</p>
    </div>

    <div class="p-8">
      <form method="POST" class="space-y-4">
        <div>
          <label class="hw-label">E-mail</label>
          <input type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>"
            class="hw-input" placeholder="seu@hostweb.cloud">
        </div>
        <div>
          <label class="hw-label">Senha</label>
          <input type="password" name="password" required
            class="hw-input" placeholder="••••••••">
        </div>
        <?php if ($error): ?>
          <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"><?= e($error) ?></div>
        <?php endif; ?>
        <button type="submit" class="hw-btn w-full py-2.5 text-sm justify-center mt-2">
          Entrar
        </button>
        <div class="text-center mt-3">
          <a href="/forgot-password.php" class="text-xs hover:underline" style="color:var(--hw-purple)">Esqueci minha senha</a>
        </div>
      </form>
    </div>

  </div>
  <p class="text-center text-xs text-gray-400 mt-6">© <?= date('Y') ?> Hostweb · Todos os direitos reservados</p>
</div>
</body></html>
