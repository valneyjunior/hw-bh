<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

session_start();

$token = trim($_GET['token'] ?? '');
$error = $success = '';

// Valida o token
$db   = getDb();
$stmt = $db->prepare("
    SELECT ts.*, u.nome, u.email
    FROM tokens_senha ts
    JOIN usuarios u ON u.id = ts.usuario_id
    WHERE ts.token = ? AND ts.usado = FALSE AND ts.expira_em > NOW()
");
$stmt->execute([$token]);
$reset = $stmt->fetch();

if (!$token || !$reset) {
    $error = 'Link inválido ou expirado. Solicite um novo link de recuperação.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    $pass    = $_POST['password'] ?? '';
    $confirm = $_POST['confirm']  ?? '';

    if (strlen($pass) < 8)      $error = 'A senha deve ter pelo menos 8 caracteres.';
    elseif ($pass !== $confirm)  $error = 'As senhas não coincidem.';
    else {
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("UPDATE usuarios SET senha_hash=?, must_change_pass=FALSE WHERE id=?")
           ->execute([$hash, $reset['usuario_id']]);
        $db->prepare("UPDATE tokens_senha SET usado=TRUE WHERE token=?")
           ->execute([$token]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Nova senha</title>
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
      <h1 class="text-xl font-bold text-white">Nova senha</h1>
      <?php if ($reset && !$success): ?>
        <p class="text-sm text-white/75 mt-1">Olá, <strong><?= e($reset['nome']) ?></strong></p>
      <?php endif; ?>
    </div>

    <div class="p-8">
      <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl p-4 text-center mb-5">
          Senha redefinida com sucesso!
        </div>
        <a href="/index.php" class="hw-btn w-full py-2.5 text-sm justify-center block text-center">
          Ir para o login
        </a>

      <?php elseif ($error && !$reset): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl p-4 text-center mb-5">
          <?= e($error) ?>
        </div>
        <a href="/forgot-password.php"
          class="block text-center border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium py-2.5 rounded-lg transition">
          Solicitar novo link
        </a>

      <?php else: ?>
        <form method="POST" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nova senha</label>
            <input type="password" name="password" required minlength="8" autofocus
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
            Salvar nova senha
          </button>
        </form>
      <?php endif; ?>
    </div>

  </div>
</div>
</body></html>
