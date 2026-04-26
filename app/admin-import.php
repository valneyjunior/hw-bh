<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$admin = requireAdmin();
$db    = getDb();

// ── Download template ────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'template') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bh-tracker-template.csv"');
    echo "\xEF\xBB\xBF";
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Colaborador','Email','Chamado','Inicio','Fim','Descricao','Validado'], ';');
    fputcsv($f, ['Hanny Santos','hanny@hostweb.cloud','0426-000129','01/11/2025 22:00','02/11/2025 00:30','Cliente X - Servidor fora do ar','Sim'], ';');
    fputcsv($f, ['Danilo Ferreira','danilo@hostweb.cloud','0426-000215','15/11/2025 03:00','15/11/2025 04:15','Cliente Y - Queda de link','Nao'], ';');
    fclose($f);
    exit;
}

// ── Process upload ───────────────────────────────────────────────────────────
$results = null;

function parseDateBR(string $s): ?string {
    $s = trim($s);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2})$/', $s, $m))
        return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:00";
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/', $s))
        return $s;
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csvfile'])) {
    $file = $_FILES['csvfile'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $results = ['error' => 'Falha no upload do arquivo.'];
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $firstLine = fgets($handle);
        rewind($handle);
        if ($bom === "\xEF\xBB\xBF") fread($handle, 3);
        $sep = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        $header = fgetcsv($handle, 0, $sep);

        $now         = date('Y-m-d H:i:s');
        $defaultHash = password_hash('Hostweb@2025', PASSWORD_BCRYPT, ['cost' => 12]);

        $stats = ['users_created'=>0,'users_existing'=>0,'records_ok'=>0,'records_skipped'=>0,'errors'=>[]];
        $userCache = [];

        $row = 1;
        while (($cols = fgetcsv($handle, 0, $sep)) !== false) {
            $row++;
            if (count($cols) < 6) continue;

            [$name, $email, $ticket, $startRaw, $endRaw, $desc] = array_map('trim', $cols);
            $validado = strtolower(trim($cols[6] ?? 'nao'));
            $isValidated = in_array($validado, ['sim','s','yes','1','true']);
            $email = strtolower($email);

            if (!$name || !$email || !$ticket || !$startRaw || !$endRaw) {
                $stats['errors'][] = "Linha $row: campos obrigatórios ausentes.";
                $stats['records_skipped']++;
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stats['errors'][] = "Linha $row: e-mail inválido ($email).";
                $stats['records_skipped']++;
                continue;
            }

            $startAt = parseDateBR($startRaw);
            $endAt   = parseDateBR($endRaw);
            if (!$startAt || !$endAt) {
                $stats['errors'][] = "Linha $row: data inválida. Use dd/mm/aaaa HH:MM.";
                $stats['records_skipped']++;
                continue;
            }
            if ($endAt <= $startAt) {
                $stats['errors'][] = "Linha $row: fim deve ser posterior ao início.";
                $stats['records_skipped']++;
                continue;
            }

            if (!isset($userCache[$email])) {
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $userCache[$email] = $existing['id'];
                    $stats['users_existing']++;
                } else {
                    $uid = generateId();
                    $db->prepare("INSERT INTO users (id,name,email,password_hash,role,must_change_pass) VALUES (?,?,?,?,'collaborator',1)")
                       ->execute([$uid, $name, $email, $defaultHash]);
                    $userCache[$email] = $uid;
                    $stats['users_created']++;
                }
            }

            $uid = $userCache[$email];
            $rid = generateId();
            $vat = $isValidated ? $now  : null;
            $vby = $isValidated ? $admin['id'] : null;

            $db->prepare("INSERT INTO records (id,user_id,started_at,ended_at,ticket,description,validated_at,validated_by) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$rid, $uid, $startAt, $endAt, $ticket, $desc, $vat, $vby]);
            $stats['records_ok']++;
        }

        fclose($handle);
        $results = ['stats' => $stats];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Importar</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-2xl mx-auto px-4 py-10">

  <?php if ($results && isset($results['error'])): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-5 mb-6">
      <?= e($results['error']) ?>
    </div>
  <?php endif; ?>

  <?php if ($results && isset($results['stats'])): ?>
    <?php $s = $results['stats']; ?>
    <div class="bg-white rounded-2xl p-6 mb-6" style="box-shadow:var(--hw-shadow)">
      <div class="flex items-center gap-3 mb-5">
        <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
          <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
          </svg>
        </div>
        <h2 class="font-semibold text-gray-900">Importação concluída</h2>
      </div>
      <div class="grid grid-cols-2 gap-3 mb-4">
        <div class="rounded-xl p-3 text-center" style="background:linear-gradient(135deg,rgba(232,0,28,.06),rgba(107,15,168,.06));border:1px solid rgba(107,15,168,.15)">
          <p class="text-2xl font-bold hw-stat"><?= $s['records_ok'] ?></p>
          <p class="text-xs text-gray-500 mt-0.5">Registros importados</p>
        </div>
        <div class="bg-green-50 border border-green-100 rounded-xl p-3 text-center">
          <p class="text-2xl font-bold text-green-600"><?= $s['users_created'] ?></p>
          <p class="text-xs text-gray-500 mt-0.5">Usuários criados</p>
        </div>
        <div class="bg-gray-50 border border-gray-100 rounded-xl p-3 text-center">
          <p class="text-2xl font-bold text-gray-600"><?= $s['users_existing'] ?></p>
          <p class="text-xs text-gray-500 mt-0.5">Usuários já existentes</p>
        </div>
        <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 text-center">
          <p class="text-2xl font-bold text-amber-600"><?= $s['records_skipped'] ?></p>
          <p class="text-xs text-gray-500 mt-0.5">Linhas ignoradas</p>
        </div>
      </div>

      <?php if (!empty($s['errors'])): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
          <p class="text-xs font-semibold text-amber-700 mb-2">Avisos (<?= count($s['errors']) ?>):</p>
          <ul class="text-xs text-amber-700 space-y-0.5 list-disc list-inside">
            <?php foreach (array_slice($s['errors'], 0, 10) as $err): ?>
              <li><?= e($err) ?></li>
            <?php endforeach; ?>
            <?php if (count($s['errors']) > 10): ?>
              <li>… e mais <?= count($s['errors']) - 10 ?> avisos.</li>
            <?php endif; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($s['users_created'] > 0): ?>
        <div class="mt-4 rounded-xl p-3 text-xs" style="background:linear-gradient(135deg,rgba(232,0,28,.04),rgba(107,15,168,.04));border:1px solid rgba(107,15,168,.12);color:var(--hw-purple)">
          Senha padrão dos novos colaboradores: <strong>Hostweb@2025</strong><br>
          Eles deverão redefinir no primeiro acesso.
        </div>
      <?php endif; ?>

      <div class="flex gap-2 mt-5">
        <a href="/admin.php" class="hw-btn flex-1 py-2.5 text-sm justify-center">
          Ir para validação
        </a>
        <a href="/admin-import.php" class="flex-1 text-center border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium py-2.5 rounded-lg transition">
          Nova importação
        </a>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$results): ?>
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-6 py-5 border-b border-gray-100" style="background:linear-gradient(135deg,rgba(232,0,28,.04),rgba(107,15,168,.04))">
      <h1 class="font-semibold text-gray-900">Importar registros via CSV</h1>
      <p class="text-sm text-gray-500 mt-0.5">Use o template abaixo, preencha e importe.</p>
    </div>

    <!-- Step 1: Template -->
    <div class="px-6 py-5 border-b border-gray-100">
      <div class="flex items-start gap-4">
        <div class="hw-avatar w-8 h-8 text-sm shrink-0" style="background:var(--hw-gradient);border-radius:50%">1</div>
        <div class="flex-1">
          <p class="text-sm font-medium text-gray-800 mb-1">Baixe o template CSV</p>
          <p class="text-xs text-gray-500 mb-3">Abra no Excel, preencha os dados e salve como CSV (separado por ponto e vírgula).</p>
          <a href="?action=template"
            class="inline-flex items-center gap-2 bg-gray-800 hover:bg-gray-900 text-white text-xs font-medium px-4 py-2 rounded-lg transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Baixar template.csv
          </a>
        </div>
      </div>
    </div>

    <!-- Column guide -->
    <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
      <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Colunas do CSV</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
        <?php
        $cols = [
          ['Colaborador',  'Nome completo do colaborador', true],
          ['Email',        'E-mail (usado para vincular ou criar conta)', true],
          ['Chamado',      'Número do ticket (ex: 0426-000129)', true],
          ['Inicio',       'dd/mm/aaaa HH:MM', true],
          ['Fim',          'dd/mm/aaaa HH:MM', true],
          ['Descricao',    'Cliente + descrição do problema', true],
          ['Validado',     '"Sim" ou "Nao"', false],
        ];
        foreach ($cols as [$col, $desc, $req]): ?>
          <div class="flex items-start gap-2 text-xs">
            <code class="bg-white border border-gray-200 px-1.5 py-0.5 rounded text-gray-700 shrink-0"><?= $col ?></code>
            <span class="text-gray-500"><?= $desc ?>
              <?php if ($req): ?><span class="text-red-400 font-medium">*</span><?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="text-xs text-gray-400 mt-3">* Obrigatório · Separador: ponto e vírgula (;)</p>
    </div>

    <!-- Step 2: Upload -->
    <div class="px-6 py-5">
      <div class="flex items-start gap-4">
        <div class="hw-avatar w-8 h-8 text-sm shrink-0" style="background:var(--hw-gradient);border-radius:50%">2</div>
        <div class="flex-1">
          <p class="text-sm font-medium text-gray-800 mb-3">Envie o CSV preenchido</p>
          <form method="POST" enctype="multipart/form-data">
            <label id="drop-zone"
              class="flex flex-col items-center justify-center w-full h-36 border-2 border-dashed border-gray-300 rounded-xl cursor-pointer hover:bg-gray-50 transition"
              style="transition: border-color .2s">
              <svg class="w-8 h-8 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
              </svg>
              <p id="drop-label" class="text-sm text-gray-500">Clique ou arraste o arquivo CSV aqui</p>
              <p class="text-xs text-gray-400 mt-1">Apenas arquivos .csv</p>
              <input type="file" name="csvfile" id="csvfile" accept=".csv,text/csv" class="hidden" required>
            </label>
            <button type="submit" class="hw-btn mt-4 w-full py-2.5 text-sm justify-center">
              Importar
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</main>

<script>
const input = document.getElementById('csvfile');
const label = document.getElementById('drop-label');
const zone  = document.getElementById('drop-zone');
if (input) {
  input.addEventListener('change', () => {
    label.textContent = input.files[0]?.name ?? 'Clique ou arraste o arquivo CSV aqui';
  });
  zone.addEventListener('dragover', e => {
    e.preventDefault();
    zone.style.borderColor = 'var(--hw-red)';
    zone.style.background  = 'rgba(232,0,28,.04)';
  });
  zone.addEventListener('dragleave', () => {
    zone.style.borderColor = '';
    zone.style.background  = '';
  });
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.style.borderColor = '';
    zone.style.background  = '';
    const file = e.dataTransfer.files[0];
    if (file) { input.files = e.dataTransfer.files; label.textContent = file.name; }
  });
}
</script>
</body></html>
