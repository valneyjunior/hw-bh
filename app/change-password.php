<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireLogin();
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass    = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    if (strlen($pass) < 8)       $error = 'A senha deve ter pelo menos 8 caracteres.';
    elseif ($pass !== $confirm)  $error = 'As senhas não coincidem.';
    else {
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        getDb()->prepare("UPDATE users SET password_hash = ?, must_change_pass = 0 WHERE id = ?")
               ->execute([$hash, $user['id']]);
        $_SESSION['user']['must_change_pass'] = false;
        header('Location: ' . ($user['role'] === 'admin' ? '/admin.php' : '/dashboard.php'));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Definir senha</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="hw-login-bg flex items-center justify-center p-4">
<div class="w-full max-w-sm">
  <div class="hw-login-card">

    <div class="hw-login-header">
      <div class="inline-flex items-center justify-center w-12 h-12 bg-white/20 rounded-xl mb-3">
        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
      </div>
      <h1 class="text-xl font-bold text-white">Definir nova senha</h1>
      <p class="text-sm text-white/75 mt-1">Primeiro acesso — crie sua senha pessoal</p>
    </div>

    <div class="p-8">
      <form method="POST" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Nova senha</label>
          <input type="password" name="password" required minlength="8"
            class="hw-input px-3 py-2.5 text-sm" placeholder="Mínimo 8 caracteres">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar senha</label>
          <input type="password" name="confirm" required
            class="hw-input px-3 py-2.5 text-sm" placeholder="Repita a senha">
        </div>
        <?php if ($error): ?>
          <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"><?= e($error) ?></div>
        <?php endif; ?>
        <button type="submit" class="hw-btn w-full py-2.5 text-sm justify-center">
          Salvar senha
        </button>
      </form>
    </div>

  </div>
</div>
</body></html>
