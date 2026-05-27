<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireLogin();
$uid  = $user['id'];
$db   = getDb();

// Mês atual e próximo
$mesAtual   = new DateTime('first day of this month');
$mesProximo = new DateTime('first day of next month');

// Carrega disponibilidades dos dois meses
$stmt = $db->prepare("
    SELECT data_disponivel::text AS data_disponivel, turno, observacao
    FROM escala_voluntaria
    WHERE usuario_id = ?
      AND data_disponivel >= ?
      AND data_disponivel <= ?
");
$stmt->execute([$uid, $mesAtual->format('Y-m-01'), $mesProximo->format('Y-m-t')]);
$disponibilidades = [];
foreach ($stmt->fetchAll() as $d) {
    $disponibilidades[$d['data_disponivel']] = $d;
}

function buildCalendar(DateTime $firstDay, array $disponibilidades): array {
    $days    = [];
    $numDays = (int)$firstDay->format('t');
    for ($i = 1; $i <= $numDays; $i++) {
        $date = $firstDay->format('Y-m-') . str_pad($i, 2, '0', STR_PAD_LEFT);
        $days[] = [
            'date'   => $date,
            'day'    => $i,
            'dow'    => (new DateTime($date))->format('w'), // 0=domingo
            'disp'   => $disponibilidades[$date] ?? null,
        ];
    }
    return $days;
}

$diasAtual   = buildCalendar($mesAtual,   $disponibilidades);
$diasProximo = buildCalendar($mesProximo, $disponibilidades);

$mesesPT = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
$hoje    = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Minha Escala</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-3xl mx-auto px-4 py-6 space-y-6">

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Minha Disponibilidade</h1>
      <p class="text-sm text-gray-500 mt-0.5">Marque os dias em que você estará disponível para ser acionado voluntariamente.</p>
    </div>
  </div>

  <div class="hw-alert-info text-sm">
    Sua disponibilidade é <strong>voluntária</strong> e não gera obrigação de atendimento.
    Os coordenadores poderão entrar em contato nos dias que você marcar.
  </div>

  <?php foreach ([
      [$mesAtual, $diasAtual],
      [$mesProximo, $diasProximo],
  ] as [$mes, $dias]): ?>

  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between" style="background:linear-gradient(135deg,rgba(232,0,28,.04),rgba(107,15,168,.04))">
      <h2 class="font-semibold text-gray-900">
        <?= $mesesPT[(int)$mes->format('n')] . ' ' . $mes->format('Y') ?>
      </h2>
      <span id="count-<?= $mes->format('Y-m') ?>" class="text-xs text-gray-500">
        <?php
        $cnt = count(array_filter($dias, fn($d) => $d['disp'] !== null));
        echo $cnt > 0 ? "$cnt dia(s) marcado(s)" : '';
        ?>
      </span>
    </div>

    <!-- Cabeçalho dias da semana -->
    <div class="grid grid-cols-7 gap-1 px-4 pt-3 pb-1 text-center">
      <?php foreach (['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'] as $dow): ?>
        <div class="text-xs font-semibold text-gray-400"><?= $dow ?></div>
      <?php endforeach; ?>
    </div>

    <!-- Grade do calendário -->
    <div class="grid grid-cols-7 gap-1 px-4 pb-4">
      <?php
      $firstDow = (int)(new DateTime($mes->format('Y-m-01')))->format('w');
      for ($i = 0; $i < $firstDow; $i++) echo '<div></div>';
      foreach ($dias as $d):
        $isWeekend = in_array($d['dow'], [0, 6]);
        $isToday   = $d['date'] === $hoje;
        $isMarked  = $d['disp'] !== null;
        $cls = 'escala-day';
        if ($isMarked) $cls .= ' selected';
        if ($isToday)  $cls .= ' today';
      ?>
        <div class="<?= $cls ?> relative"
             data-date="<?= $d['date'] ?>"
             data-mes="<?= $mes->format('Y-m') ?>"
             onclick="toggleDay('<?= $d['date'] ?>', '<?= $mes->format('Y-m') ?>', this)"
        data-date="<?= $d['date'] ?>">
          <span class="<?= $isWeekend ? 'font-bold' : '' ?>"><?= $d['day'] ?></span>
          <?php if ($isWeekend): ?>
            <span class="text-[9px] leading-none mt-0.5 opacity-60"><?= (int)$d['dow'] === 0 ? 'Dom' : 'Sáb' ?></span>
          <?php endif; ?>
          <?php if ($isMarked): ?>
            <span class="absolute bottom-1 left-1/2 -translate-x-1/2 w-1.5 h-1.5 bg-white/80 rounded-full"></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Histórico de disponibilidade marcada -->
  <?php if (!empty($disponibilidades)): ?>
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
      <h2 class="text-sm font-semibold text-gray-700">Dias marcados</h2>
    </div>
    <div class="divide-y divide-gray-100 max-h-64 overflow-y-auto">
      <?php
      $sorted = $disponibilidades;
      ksort($sorted);
      foreach ($sorted as $date => $d):
        $turnos = ['manha'=>'Manhã','tarde'=>'Tarde','noite'=>'Noite','dia_todo'=>'Dia todo'];
      ?>
        <div class="px-5 py-2.5 flex items-center gap-3">
          <span class="text-sm font-medium text-gray-800 w-24 shrink-0"><?= fmtDate($date) ?></span>
          <span class="text-xs hw-setor-badge"><?= $turnos[$d['turno']] ?? $d['turno'] ?></span>
          <?php if ($d['observacao']): ?>
            <span class="text-xs text-gray-500 truncate"><?= e($d['observacao']) ?></span>
          <?php endif; ?>
          <button onclick="removeDay('<?= $date ?>', this)"
            class="ml-auto text-xs text-red-400 hover:text-red-600">✕</button>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</main>

<!-- Modal: detalhes do dia -->
<div id="modal-dia" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
    <div class="px-5 py-4 border-b flex items-center justify-between" style="background:var(--hw-gradient)">
      <h2 id="modal-dia-title" class="font-semibold text-white text-sm"></h2>
      <button onclick="closeDayModal()" class="text-white/70 hover:text-white text-xl leading-none">&times;</button>
    </div>
    <div class="p-5 space-y-4">
      <input type="hidden" id="modal-dia-date">
      <input type="hidden" id="modal-dia-mes">

      <div>
        <label class="hw-label">Turno preferencial</label>
        <select id="modal-turno" class="hw-input">
          <option value="dia_todo">Disponível o dia todo</option>
          <option value="manha">Manhã</option>
          <option value="tarde">Tarde</option>
          <option value="noite">Noite</option>
        </select>
      </div>
      <div>
        <label class="hw-label">Observação (opcional)</label>
        <textarea id="modal-obs" rows="2" placeholder="Ex: só a partir das 14h" class="hw-input resize-none"></textarea>
      </div>
      <div class="flex gap-2">
        <button onclick="closeDayModal()" class="hw-btn-secondary flex-1 text-sm">Cancelar</button>
        <button onclick="saveDay()" class="hw-btn flex-1 py-2 text-sm justify-center">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<script>
let pendingDate = null, pendingMes = null, pendingEl = null;

function toggleDay(date, mes, el) {
  if (el.classList.contains('selected')) {
    removeDay(date, el);
  } else {
    pendingDate = date;
    pendingMes  = mes;
    pendingEl   = el;
    document.getElementById('modal-dia-date').value  = date;
    document.getElementById('modal-dia-mes').value   = mes;
    document.getElementById('modal-dia-title').textContent = 'Disponibilidade — ' + formatDate(date);
    document.getElementById('modal-turno').value = 'dia_todo';
    document.getElementById('modal-obs').value   = '';
    document.getElementById('modal-dia').classList.replace('hidden','flex');
  }
}

function closeDayModal() {
  document.getElementById('modal-dia').classList.replace('flex','hidden');
}

async function saveDay() {
  const data  = document.getElementById('modal-dia-date').value;
  const turno = document.getElementById('modal-turno').value;
  const obs   = document.getElementById('modal-obs').value.trim();

  try {
    const res = await fetch('/api/escala.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'save', data, turno, observacao: obs})
    });
    const json = await res.json();
    if (res.ok) { closeDayModal(); location.reload(); }
    else alert(json.error ?? 'Erro ao salvar.');
  } catch { alert('Erro de comunicação. Tente novamente.'); }
}

async function removeDay(data, btnOrEl) {
  try {
    const res = await fetch('/api/escala.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'remove', data})
    });
    if (res.ok) location.reload();
    else alert('Erro ao remover.');
  } catch { alert('Erro de comunicação.'); }
}

function formatDate(iso) {
  const [y,m,d] = iso.split('-');
  return `${d}/${m}/${y}`;
}

</script>
</body></html>
