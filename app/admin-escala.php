<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireCoordenador();
$db   = getDb();

$setorFilter = isAdmin() ? null : $user['setor_id'];

// Mês selecionado (default: atual)
$mesParam = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');
[$ano, $mes] = explode('-', $mesParam);
$firstDay  = new DateTime("$ano-$mes-01");
$lastDay   = new DateTime($firstDay->format('Y-m-t'));
$numDays   = (int)$firstDay->format('t');

$mesesPT = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
$mesLabel = $mesesPT[(int)$mes] . ' ' . $ano;

// Disponibilidades do mês
$sql = "
    SELECT e.data_disponivel::text AS data_disponivel, e.turno, e.observacao,
           u.nome AS user_nome, u.id AS user_id, s.nome AS setor_nome
    FROM escala_voluntaria e
    JOIN usuarios u ON u.id = e.usuario_id
    LEFT JOIN setores s ON s.id = u.setor_id
    WHERE e.data_disponivel BETWEEN ? AND ?
      AND u.status = 'ativo'
";
$params = [$firstDay->format('Y-m-d'), $lastDay->format('Y-m-d')];
if ($setorFilter) { $sql .= " AND u.setor_id = ?"; $params[] = $setorFilter; }
$sql .= " ORDER BY e.data_disponivel, u.nome";

$stmtE = $db->prepare($sql);
$stmtE->execute($params);
$escalas = $stmtE->fetchAll();

// Agrupa por data
$byDate = [];
foreach ($escalas as $e) {
    $byDate[$e['data_disponivel']][] = $e;
}

// Resumo por colaborador para relatório de voluntários
$byUser = [];
foreach ($escalas as $e) {
    $uid = $e['user_id'];
    $byUser[$uid] ??= [
        'nome'   => $e['user_nome'],
        'setor'  => $e['setor_nome'],
        'dias'   => 0,
        'turnos' => ['manha'=>0,'tarde'=>0,'noite'=>0,'dia_todo'=>0],
    ];
    $byUser[$uid]['dias']++;
    $byUser[$uid]['turnos'][$e['turno']]++;
}
uasort($byUser, fn($a,$b) => $b['dias'] <=> $a['dias']);

$turnoPT = ['manha'=>'Manhã','tarde'=>'Tarde','noite'=>'Noite','dia_todo'=>'Dia todo'];
$hoje = date('Y-m-d');

// Mês anterior e próximo para navegação
$prevMes = (new DateTime("$ano-$mes-01"))->modify('-1 month')->format('Y-m');
$nextMes = (new DateTime("$ano-$mes-01"))->modify('+1 month')->format('Y-m');

// Agrupa por data→setor para exibição no calendário
$byDateSetor = [];
foreach ($byDate as $date => $entries) {
    foreach ($entries as $e) {
        $setor = $e['setor_nome'] ?? 'Sem setor';
        $byDateSetor[$date][$setor][] = $e;
    }
}

// Exportações — devem rodar ANTES de qualquer HTML
$exportType = $_GET['export'] ?? '';

if ($exportType === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="escala-' . $mesParam . '.csv"');
    echo "\xEF\xBB\xBF";
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Data','Colaborador','Setor','Turno','Observação'], ';');
    foreach ($escalas as $e) {
        fputcsv($f, [fmtDate($e['data_disponivel']), $e['user_nome'], $e['setor_nome'], $turnoPT[$e['turno']] ?? $e['turno'], $e['observacao'] ?? ''], ';');
    }
    fclose($f); exit;
}

if ($exportType === 'relatorio') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="voluntarios-' . $mesParam . '.csv"');
    echo "\xEF\xBB\xBF";
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Colaborador','Setor','Dias disponíveis','Manhã','Tarde','Noite','Dia todo'], ';');
    foreach ($byUser as $u) {
        fputcsv($f, [
            $u['nome'], $u['setor'] ?? '', $u['dias'],
            $u['turnos']['manha'], $u['turnos']['tarde'],
            $u['turnos']['noite'], $u['turnos']['dia_todo'],
        ], ';');
    }
    fclose($f); exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Escala do Setor</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-5xl mx-auto px-4 py-6 space-y-6">

  <div class="flex flex-wrap items-center justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Escala Voluntária — <?= $mesLabel ?></h1>
      <p class="text-sm text-gray-500"><?= $setorFilter ? e($user['setor_nome'] ?? '') : 'Todos os setores' ?></p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <a href="?mes=<?= $prevMes ?>" class="hw-btn-secondary text-sm px-3 py-2">← Anterior</a>
      <a href="?mes=<?= $nextMes ?>" class="hw-btn-secondary text-sm px-3 py-2">Próximo →</a>
      <button onclick="copyLink()" class="hw-btn-secondary text-sm px-3 py-2 flex items-center gap-1.5" title="Copiar link desta visualização">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
        <span id="copy-link-txt">Copiar link</span>
      </button>
      <a href="?mes=<?= $mesParam ?>&export=csv" class="hw-btn-secondary text-sm px-3 py-2 flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
        Exportar CSV
      </a>
      <a href="?mes=<?= $mesParam ?>&export=relatorio" class="hw-btn text-sm px-4 py-2 flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Relatório Voluntários
      </a>
    </div>
  </div>

  <!-- Grade do calendário -->
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <!-- Cabeçalho -->
    <div class="grid grid-cols-7 border-b border-gray-100 hw-table-head">
      <?php foreach (['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'] as $d): ?>
        <div class="px-2 py-3 text-center text-xs font-semibold text-gray-500"><?= $d ?></div>
      <?php endforeach; ?>
    </div>

    <!-- Células -->
    <div class="grid grid-cols-7 divide-x divide-y divide-gray-100">
      <?php
      $firstDow = (int)$firstDay->format('w');
      for ($i = 0; $i < $firstDow; $i++) echo '<div class="p-2 min-h-[90px] bg-gray-50/50"></div>';

      for ($day = 1; $day <= $numDays; $day++):
        $date       = sprintf('%s-%02d', $firstDay->format('Y-m'), $day);
        $dow        = (int)(new DateTime($date))->format('w');
        $isWkd      = in_array($dow, [0, 6]);
        $isToday    = $date === $hoje;
        $entries    = $byDate[$date] ?? [];
        $setores    = $byDateSetor[$date] ?? [];
        $noCoverage = empty($entries);
        $hasData    = !empty($entries);
      ?>
        <div class="p-2 min-h-[90px] <?= $isWkd ? 'bg-blue-50/30' : '' ?> <?= $isToday ? 'ring-2 ring-inset ring-purple-400' : '' ?> <?= $hasData ? 'cursor-pointer hover:bg-gray-50 transition-colors' : '' ?>"
             <?= $hasData ? "onclick=\"openDay('$date', " . htmlspecialchars(json_encode([
                 'label'   => fmtDate($date),
                 'total'   => count($entries),
                 'setores' => array_map(fn($s, $list) => [
                     'nome'   => $s,
                     'pessoas'=> array_map(fn($e) => [
                         'nome'   => $e['user_nome'],
                         'turno'  => $turnoPT[$e['turno']] ?? $e['turno'],
                         'obs'    => $e['observacao'] ?? '',
                     ], $list),
                 ], array_keys($setores), $setores),
             ]), ENT_QUOTES) . ')\"' : '' ?>>
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs font-semibold <?= $isToday ? 'text-purple-700' : ($isWkd ? 'text-blue-600' : 'text-gray-700') ?>"><?= $day ?></span>
            <?php if ($noCoverage): ?>
              <span class="w-2 h-2 rounded-full bg-red-400 shrink-0" title="Sem voluntários"></span>
            <?php else: ?>
              <span class="text-xs font-medium text-gray-500"><?= count($entries) ?>p</span>
            <?php endif; ?>
          </div>
          <?php if ($hasData):
            $setorNames = array_keys($setores);
            $shown = array_slice($setorNames, 0, 2);
            $extra = count($setorNames) - count($shown);
          ?>
          <div class="space-y-0.5 mt-1">
            <?php foreach ($shown as $s): ?>
              <div class="text-[10px] leading-tight font-medium rounded px-1.5 py-0.5 truncate"
                   style="background:rgba(107,15,168,.1);color:var(--hw-purple)">
                <?= e($s) ?> <span class="opacity-60">(<?= count($setores[$s]) ?>)</span>
              </div>
            <?php endforeach; ?>
            <?php if ($extra > 0): ?>
              <div class="text-[10px] text-gray-400 pl-1">+<?= $extra ?> setor(es)</div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
  </div>

  <!-- Legenda: dias sem cobertura -->
  <div class="flex items-center gap-2 text-xs text-gray-500">
    <span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span> Dias sem voluntários
    <span class="w-2 h-2 rounded-full bg-blue-100 border border-blue-300 inline-block ml-3"></span> Final de semana
  </div>

  <!-- Listagem detalhada do mês -->
  <?php if (!empty($byDate)): ?>
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-5 py-3 border-b bg-gray-50">
      <h2 class="text-sm font-semibold text-gray-700">Disponibilidade detalhada — <?= $mesLabel ?></h2>
    </div>
    <div class="divide-y divide-gray-100">
      <?php foreach ($byDate as $date => $entries): ?>
        <div class="px-5 py-3">
          <div class="flex items-center gap-3 mb-2">
            <span class="text-sm font-semibold text-gray-800 w-28 shrink-0"><?= fmtDate($date) ?></span>
            <span class="text-xs text-gray-400"><?= count($entries) ?> voluntário(s)</span>
          </div>
          <div class="flex flex-wrap gap-2 pl-28">
            <?php foreach ($entries as $e): ?>
              <div class="flex items-center gap-1.5 bg-gray-50 border border-gray-200 rounded-lg px-3 py-1.5">
                <div class="hw-avatar w-5 h-5 text-xs shrink-0"><?= strtoupper(mb_substr($e['user_nome'],0,1)) ?></div>
                <div>
                  <span class="text-xs font-medium text-gray-800"><?= e($e['user_nome']) ?></span>
                  <?php if ($e['setor_nome']): ?><span class="ml-1 hw-setor-badge"><?= e($e['setor_nome']) ?></span><?php endif; ?>
                  <span class="ml-1 text-xs text-gray-500">· <?= $turnoPT[$e['turno']] ?></span>
                  <?php if ($e['observacao']): ?><span class="block text-xs text-gray-400 italic"><?= e($e['observacao']) ?></span><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php else: ?>
  <div class="text-center py-12 text-gray-400 text-sm">Nenhum colaborador marcou disponibilidade neste mês.</div>
  <?php endif; ?>

  <!-- Relatório de voluntários por colaborador -->
  <?php if (!empty($byUser)): ?>
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-5 py-3 border-b bg-gray-50 flex items-center justify-between">
      <h2 class="text-sm font-semibold text-gray-700">Resumo por colaborador — <?= $mesLabel ?></h2>
      <span class="text-xs text-gray-400"><?= count($byUser) ?> voluntário(s)</span>
    </div>
    <table class="w-full text-sm">
      <thead class="hw-table-head">
        <tr>
          <th class="px-5 py-2.5 text-left">Colaborador</th>
          <?php if (!$setorFilter): ?><th class="px-5 py-2.5 text-left">Setor</th><?php endif; ?>
          <th class="px-5 py-2.5 text-center">Dias marcados</th>
          <th class="px-5 py-2.5 text-left">Distribuição de turnos</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php foreach ($byUser as $u): ?>
          <tr class="hw-table-row">
            <td class="px-5 py-2.5 font-medium text-gray-800">
              <div class="flex items-center gap-2">
                <div class="hw-avatar w-6 h-6 text-xs shrink-0"><?= strtoupper(mb_substr($u['nome'],0,1)) ?></div>
                <?= e($u['nome']) ?>
              </div>
            </td>
            <?php if (!$setorFilter): ?>
              <td class="px-5 py-2.5">
                <?php if ($u['setor']): ?><span class="hw-setor-badge"><?= e($u['setor']) ?></span><?php else: ?><span class="text-gray-400 text-xs">—</span><?php endif; ?>
              </td>
            <?php endif; ?>
            <td class="px-5 py-2.5 text-center">
              <span class="text-sm font-bold" style="color:var(--hw-purple)"><?= $u['dias'] ?></span>
            </td>
            <td class="px-5 py-2.5">
              <div class="flex flex-wrap gap-1 text-xs">
                <?php foreach (['manha'=>'Manhã','tarde'=>'Tarde','noite'=>'Noite','dia_todo'=>'Dia todo'] as $k=>$l): ?>
                  <?php if ($u['turnos'][$k] > 0): ?>
                    <span class="bg-blue-50 text-blue-700 border border-blue-200 px-2 py-0.5 rounded-full">
                      <?= $l ?>: <?= $u['turnos'][$k] ?>
                    </span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</main>

<!-- Modal: detalhe do dia -->
<div id="modal-dia" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
    <div class="px-5 py-4 border-b flex items-center justify-between shrink-0" style="background:var(--hw-gradient)">
      <div>
        <h2 id="modal-dia-titulo" class="font-semibold text-white text-base"></h2>
        <p id="modal-dia-sub" class="text-xs text-white/70 mt-0.5"></p>
      </div>
      <button onclick="closeDia()" class="text-white/70 hover:text-white text-2xl leading-none">&times;</button>
    </div>
    <div id="modal-dia-body" class="overflow-y-auto flex-1 divide-y divide-gray-100 px-1"></div>
  </div>
</div>

<script>
function openDay(date, data) {
  document.getElementById('modal-dia-titulo').textContent = data.label;
  document.getElementById('modal-dia-sub').textContent = data.total + ' voluntário(s)';

  const body = document.getElementById('modal-dia-body');
  body.innerHTML = '';

  data.setores.forEach(setor => {
    const section = document.createElement('div');
    section.className = 'px-5 py-3';

    const header = document.createElement('div');
    header.className = 'flex items-center gap-2 mb-2';
    header.innerHTML = `
      <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--hw-purple)">${setor.nome}</span>
      <span class="text-xs text-gray-400">${setor.pessoas.length} pessoa(s)</span>
    `;
    section.appendChild(header);

    const list = document.createElement('div');
    list.className = 'space-y-1.5';
    setor.pessoas.forEach(p => {
      const row = document.createElement('div');
      row.className = 'flex items-start gap-2';
      row.innerHTML = `
        <div class="hw-avatar w-7 h-7 text-xs shrink-0 mt-0.5">${p.nome.charAt(0).toUpperCase()}</div>
        <div class="flex-1 min-w-0">
          <span class="text-sm font-medium text-gray-800">${p.nome}</span>
          <span class="ml-2 text-xs text-gray-500">· ${p.turno}</span>
          ${p.obs ? `<p class="text-xs text-gray-400 italic mt-0.5">${p.obs}</p>` : ''}
        </div>
      `;
      list.appendChild(row);
    });
    section.appendChild(list);
    body.appendChild(section);
  });

  document.getElementById('modal-dia').classList.replace('hidden', 'flex');
}

function closeDia() {
  document.getElementById('modal-dia').classList.replace('flex', 'hidden');
}

function copyLink() {
  navigator.clipboard.writeText(window.location.href).then(() => {
    const el = document.getElementById('copy-link-txt');
    el.textContent = 'Link copiado!';
    setTimeout(() => el.textContent = 'Copiar link', 2000);
  });
}
</script>
</body></html>
