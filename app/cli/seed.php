<?php
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$email    = getenv('ADMIN_EMAIL');
$password = getenv('ADMIN_PASSWORD');
$name     = getenv('ADMIN_NAME');

if (!$email || !$password || !$name) {
    echo "Variáveis ADMIN_* não definidas — seed ignorado.\n"; exit(0);
}

$db = null;
for ($i = 0; $i < 12; $i++) {
    try { $db = getDb(); break; }
    catch (Exception $e) { echo "Aguardando banco... (tentativa $i)\n"; sleep(3); }
}
if (!$db) { echo "Falha ao conectar.\n"; exit(1); }

$stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) { echo "Admin $email já existe.\n"; exit(0); }

$id   = generateId();
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$db->prepare("INSERT INTO users (id, name, email, password_hash, role, must_change_pass) VALUES (?,?,?,?,'admin',0)")
   ->execute([$id, $name, $email, $hash]);

echo "Admin criado: $email\n";
