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
for ($i = 0; $i < 15; $i++) {
    try { $db = getDb(); break; }
    catch (Exception $e) { echo "Aguardando banco... (tentativa $i)\n"; sleep(3); }
}
if (!$db) { echo "Falha ao conectar ao PostgreSQL.\n"; exit(1); }

// Verifica se admin já existe
$stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) { echo "Admin $email já existe.\n"; exit(0); }

// Busca ID do perfil administrador
$perfil = $db->query("SELECT id FROM perfis WHERE nome = 'administrador'")->fetch();
if (!$perfil) { echo "Perfil 'administrador' não encontrado. Verifique o init_postgres.sql.\n"; exit(1); }

// Busca ID do setor padrão (Serviços)
$setor = $db->query("SELECT id FROM setores WHERE nome = 'Serviços'")->fetch();
$setorId = $setor ? $setor['id'] : null;

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$db->prepare(
    "INSERT INTO usuarios (nome, email, senha_hash, setor_id, must_change_pass) VALUES (?, ?, ?, ?, FALSE)"
)->execute([$name, strtolower($email), $hash, $setorId]);

$userId = $db->lastInsertId('usuarios_id_seq');

// Vincula perfil administrador
$db->prepare(
    "INSERT INTO usuario_perfis (usuario_id, perfil_id) VALUES (?, ?)"
)->execute([$userId, $perfil['id']]);

// Também vincula como analista e coordenador para ter acesso completo
$outros = $db->query("SELECT id FROM perfis WHERE nome IN ('analista','coordenador')")->fetchAll();
foreach ($outros as $p) {
    $db->prepare("INSERT INTO usuario_perfis (usuario_id, perfil_id) VALUES (?, ?)")
       ->execute([$userId, $p['id']]);
}

echo "Admin criado: $email (ID: $userId)\n";
