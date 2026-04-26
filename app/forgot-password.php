<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/mailer.php';

session_start();

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $db   = getDb();
        $stmt = $db->prepare("SELECT id, name FROM users WHERE email = ? AND active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Invalida tokens anteriores
            $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
               ->execute([$user['id']]);

            // Gera novo token
            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $id        = generateId();

            $db->prepare("INSERT INTO password_resets (id, user_id, token, expires_at) VALUES (?,?,?,?)")
               ->execute([$id, $user['id'], $token, $expiresAt]);

            $appUrl   = rtrim(getenv('APP_URL') ?: 'http://localhost:3000', '/');
            $resetUrl = $appUrl . '/reset-password.php?token=' . $token;

            mailPasswordReset($email, $user['name'], $resetUrl);
        }
    }

    // Sempre exibe mensagem neutra (não revela se e-mail existe)
    $sent = true;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Recuperar senha</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="hw-login-bg flex items-center justify-center p-4">
<div class="w-full max-w-sm">
  <div class="hw-login-card">

    <div class="hw-login-header">
      <div class="inline-flex items-center justify-center w-12 h-12 bg-white/20 rounded-xl mb-3">
        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
        </svg>
      </div>
      <h1 class="text-xl font-bold text-white">Recuperar senha</h1>
      <p class="text-sm text-white/75 mt-1">BH Tecnologia · Hostweb</p>
    </div>

    <div class="p-8">
      <?php if ($sent): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl p-4 text-center mb-5">
          Se esse e-mail estiver cadastrado, você receberá as instruções em breve.
        </div>
        <a href="/index.php"
          class="hw-btn w-full py-2.5 text-sm justify-center block text-center">
          Voltar ao login
        </a>
      <?php else: ?>
        <p class="text-sm text-gray-500 mb-5 text-center">Informe seu e-mail e enviaremos um link para redefinir sua senha.</p>
        <form method="POST" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
            <input type="email" name="email" required autofocus
              value="<?= e($_POST['email'] ?? '') ?>"
              class="hw-input px-3 py-2.5 text-sm" placeholder="seu@email.com">
          </div>
          <button type="submit" class="hw-btn w-full py-2.5 text-sm justify-center">
            Enviar link de recuperação
          </button>
        </form>
        <div class="text-center mt-4">
          <a href="/index.php" class="text-xs text-gray-400 hover:text-gray-600">← Voltar ao login</a>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>
</body></html>
