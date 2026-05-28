<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
$user = requireLogin();
$db   = getDb();
$uid  = $user['id'];

// Saldo: total aprovado - deduções aprovadas
$stmtAprov = $db->prepare("SELECT COALESCE(SUM(total_minutos),0) FROM lancamentos WHERE usuario_id = ? AND status = 'aprovado'");
$stmtAprov->execute([$uid]);
$totalAprovMin = (int)$stmtAprov->fetchColumn();

$stmtDed = $db->prepare("SELECT COALESCE(SUM(total_minutos),0) FROM solicitacoes_bh WHERE usuario_id = ? AND status = 'aprovado'");
$stmtDed->execute([$uid]);
$deducted    = (int)$stmtDed->fetchColumn();
$balanceMins = $totalAprovMin - $deducted;

// Jornada do colaborador (try/catch protege contra schema antigo sem lunch_*)
$workStart = '08:00'; $workEnd = '18:00'; $lunchStart = '12:00'; $lunchMins = 60;
try {
    $stmtU = $db->prepare("SELECT work_start, work_end, lunch_start, lunch_minutes FROM usuarios WHERE id = ?");
    $stmtU->execute([$uid]);
    $uRow      = $stmtU->fetch();
    $workStart  = $uRow ? substr($uRow['work_start'],  0, 5) : '08:00';
    $workEnd    = $uRow ? substr($uRow['work_end'],    0, 5) : '18:00';
    $lunchStart = $uRow && isset($uRow['lunch_start'])   ? substr($uRow['lunch_start'], 0, 5) : '12:00';
    $lunchMins  = $uRow && isset($uRow['lunch_minutes']) ? (int)$uRow['lunch_minutes']        : 60;
} catch (\Throwable $e) {
    $stmtU2 = $db->prepare("SELECT work_start, work_end FROM usuarios WHERE id = ?");
    $stmtU2->execute([$uid]);
    $uRow2     = $stmtU2->fetch();
    $workStart = $uRow2 ? substr($uRow2['work_start'], 0, 5) : '08:00';
    $workEnd   = $uRow2 ? substr($uRow2['work_end'],   0, 5) : '18:00';
}

// Histórico de solicitações
$stmtReqs = $db->prepare("
    SELECT s.*, u.nome AS revisor_nome
    FROM solicitacoes_bh s
    LEFT JOIN usuarios u ON u.id = s.revisado_por
    WHERE s.usuario_id = ?
    ORDER BY s.criado_em DESC
");
$stmtReqs->execute([$uid]);
$myRequests = $stmtReqs->fetchAll();

$tipoLabels = [
    'dia_inteiro'          => 'Dia inteiro',
    'meio_periodo_manha'   => 'Meio período — Manhã',
    'meio_periodo_tarde'   => 'Meio período — Tarde',
    'personalizado'        => 'Personalizado',
    'deducao_admin'        => 'Dedução por atraso',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BH Tecnologia — Banco de Horas</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
.flatpickr-day.selected,.flatpickr-day.selected:hover{background:var(--hw-purple)!important;border-color:var(--hw-purple)!important;color:#fff!important}
.flatpickr-day.today{border-color:var(--hw-purple)}
.flatpickr-day:hover{background:rgba(107,15,168,.08)}
</style>
</head>
<body class="bg-[#f5f5f7] min-h-screen">
<?php include 'includes/nav.php'; ?>

<main class="max-w-3xl mx-auto px-4 py-6 space-y-6">

  <div class="flex items-center justify-between">
    <h1 class="text-xl font-bold text-gray-900">Banco de Horas</h1>
    <a href="/dashboard.php" class="text-sm hover:underline" style="color:var(--hw-purple)">← Meus registros</a>
  </div>

  <!-- Cards de saldo -->
  <div class="grid grid-cols-3 gap-4">
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-teal">Total aprovado</p>
      <p class="hw-kpi-value hw-kpi-teal"><?= minutesToHHMM($totalAprovMin) ?></p>
    </div>
    <div class="hw-kpi-card">
      <p class="hw-kpi-title hw-kpi-red">Deduções (folgas)</p>
      <p class="hw-kpi-value hw-kpi-red">-<?= minutesToHHMM($deducted) ?></p>
    </div>
    <div class="hw-kpi-card">
      <p class="hw-kpi-title <?= $balanceMins >= 0 ? 'hw-kpi-green' : 'hw-kpi-red' ?>">Saldo disponível</p>
      <p class="hw-kpi-value <?= $balanceMins >= 0 ? 'hw-kpi-green' : 'hw-kpi-red' ?>"><?= minutesToHHMM(max(0, $balanceMins)) ?></p>
    </div>
  </div>

  <!-- Formulário de solicitação -->
  <?php if ($balanceMins > 0): ?>
  <div class="bg-white rounded-2xl overflow-hidden" style="box-shadow:var(--hw-shadow)">
    <div class="px-5 py-4 border-b" style="background:linear-gradient(135deg,rgba(232,0,28,.04),rgba(107,15,168,.04))">
      <h2 class="font-semibold text-gray-900">Solicitar uso de banco de horas</h2>
      <p class="text-xs text-gray-400 mt-0.5">Saldo disponível: <?= minutesToHHMM($balanceMins) ?> · Jornada: <?= $workStart ?> — <?= $workEnd ?></p>
    </div>
    <form id="req-form" class="p-5 space-y-4">

      <!-- Tipo de período -->
      <div>
        <label class="hw-label">Tipo de período</label>
        <div class="grid grid-cols-3 gap-2">
          <?php foreach ([
            ['dia_inteiro','Dia inteiro','M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['meio','Meio período','M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['personalizado','Personalizado','M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
          ] as [$val, $lbl, $icon]): ?>
          <label id="pcard-<?= $val ?>" class="flex flex-col items-center gap-1.5 p-3 rounded-xl border-2 cursor-pointer transition text-center"
            style="<?= $val==='dia_inteiro' ? 'border-color:var(--hw-purple);background:rgba(107,15,168,.04)' : 'border-color:#e5e7eb' ?>">
            <input type="radio" name="period_type" value="<?= $val ?>" <?= $val==='dia_inteiro'?'checked':'' ?> class="sr-only">
            <svg class="w-5 h-5" style="color:<?= $val==='dia_inteiro'?'var(--hw-purple)':'#9ca3af' ?>" id="picon-<?= $val ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $icon ?>"/>
            </svg>
            <span class="text-xs font-medium" id="ptxt-<?= $val ?>" style="color:<?= $val==='dia_inteiro'?'var(--hw-purple)':'#6b7280' ?>"><?= $lbl ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Data única (dia_inteiro / meio) -->
      <div id="single-date-section">
        <label class="hw-label">Data da folga</label>
        <input type="text" id="req-date" placeholder="Selecione a data" readonly class="hw-input cursor-pointer w-44">
        <input type="hidden" id="req-date-iso">
      </div>

      <!-- Meio: manhã ou tarde -->
      <div id="half-section" class="hidden">
        <label class="hw-label mb-2">Qual período?</label>
        <div class="grid grid-cols-2 gap-2">
          <?php foreach (['manha'=>'Manhã','tarde'=>'Tarde'] as $hval=>$hlbl): ?>
          <label id="hcard-<?= $hval ?>" class="flex items-center justify-center gap-2 p-3 rounded-xl border-2 cursor-pointer transition"
            style="<?= $hval==='manha'?'border-color:var(--hw-purple);background:rgba(107,15,168,.04)':'border-color:#e5e7eb' ?>">
            <input type="radio" name="half_type" value="<?= $hval ?>" <?= $hval==='manha'?'checked':'' ?> class="sr-only">
            <span class="text-sm font-medium" id="htxt-<?= $hval ?>" style="color:<?= $hval==='manha'?'var(--hw-purple)':'#6b7280' ?>"><?= $hlbl ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Personalizado -->
      <div id="custom-section" class="hidden space-y-3">
        <div class="bg-gray-50 rounded-xl p-4 space-y-2">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Início</p>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="hw-label text-xs">Data</label>
              <input type="text" id="custom-date-start" placeholder="Selecione" readonly class="hw-input cursor-pointer">
              <input type="hidden" id="custom-date-start-iso">
            </div>
            <div>
              <label class="hw-label text-xs">Das</label>
              <input type="text" id="custom-time-start" class="hw-input font-mono" maxlength="5" placeholder="HH:MM" oninput="applyTimeMask(this);updateSummary()">
            </div>
          </div>
        </div>
        <div class="bg-gray-50 rounded-xl p-4 space-y-2">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Término</p>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="hw-label text-xs">Data</label>
              <input type="text" id="custom-date-end" placeholder="Selecione" readonly class="hw-input cursor-pointer">
              <input type="hidden" id="custom-date-end-iso">
            </div>
            <div>
              <label class="hw-label text-xs">Às</label>
              <input type="text" id="custom-time-end" class="hw-input font-mono" maxlength="5" placeholder="HH:MM" oninput="applyTimeMask(this);updateSummary()">
            </div>
          </div>
        </div>
      </div>

      <!-- Resumo dinâmico -->
      <div id="req-summary" class="hidden rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-4 py-2 bg-gray-50 border-b"><p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Resumo</p></div>
        <div class="px-4 py-3 space-y-1.5 text-sm">
          <div class="flex justify-between"><span class="text-gray-500">Data</span><span id="sum-date" class="font-medium">—</span></div>
          <div id="sum-days-row" class="hidden flex justify-between"><span class="text-gray-500">Dias</span><span id="sum-days" class="font-medium">—</span></div>
          <div class="flex justify-between"><span class="text-gray-500">Período</span><span id="sum-period" class="font-medium">—</span></div>
          <div class="flex justify-between border-t pt-1.5 mt-0.5"><span class="font-medium text-gray-600">Total a deduzir</span><span id="sum-hours" class="font-bold" style="color:var(--hw-red)">—</span></div>
        </div>
      </div>

      <div>
        <label class="hw-label">Motivo <span class="text-red-500">*</span></label>
        <textarea id="req-reason" rows="2" placeholder="Ex: Compensação de acionamento em março" class="hw-input resize-none"></textarea>
      </div>

      <div id="req-error" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2"></div>
      <button type="submit" id="req-btn" class="hw-btn w-full py-2.5 text-sm justify-center">Confirmar solicitação</button>
    </form>
  </div>
  <?php else: ?>
  <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 text-sm text-amber-700 text-center">
    Sem saldo disponível para solicitar folga no momento.
  </div>
  <?php endif; ?>

  <!-- Histórico -->
  <?php if (!empty($myRequests)): ?>
  <section>
    <h2 class="text-sm font-semibold text-gray-700 mb-3">Histórico de solicitações</h2>
    <div class="space-y-2">
      <?php foreach ($myRequests as $r):
        $statusBadge = match($r['status']) {
            'aprovado' => 'hw-badge-aprovado',
            'recusado' => 'hw-badge-recusado',
            default    => 'hw-badge-pendente',
        };
        $statusLabel = match($r['status']) {
            'aprovado' => 'Aprovado',
            'recusado' => 'Rejeitado',
            default    => 'Aguardando aprovação',
        };
        $isAdmin = ($r['tipo'] === 'deducao_admin');
        $dateLabel = fmtDate($r['data_inicio']);
        if ($r['data_fim'] && $r['data_fim'] !== $r['data_inicio']) $dateLabel .= ' a ' . fmtDate($r['data_fim']);
      ?>
        <div class="border rounded-xl p-4 <?= $isAdmin ? 'bg-red-50 border-red-200' : 'bg-white border-gray-200' ?>" style="box-shadow:0 2px 8px rgba(0,0,0,.06)">
          <div class="flex items-start justify-between gap-3">
            <div class="flex-1">
              <div class="flex flex-wrap items-center gap-2 mb-1">
                <span class="font-semibold <?= $isAdmin ? 'text-red-700' : 'text-gray-800' ?>">
                  <?= $isAdmin ? '-' : '' ?><?= minutesToHHMM((int)$r['total_minutos']) ?> h
                </span>
                <?php if (!$isAdmin): ?>
                  <span class="<?= $statusBadge ?>"><?= $statusLabel ?></span>
                <?php endif; ?>
                <span class="text-xs text-gray-500"><?= $dateLabel ?></span>
                <?php if ($tipoLabels[$r['tipo']] ?? null): ?>
                  <span class="text-xs hw-setor-badge"><?= $tipoLabels[$r['tipo']] ?></span>
                <?php endif; ?>
                <span class="text-xs text-gray-400"><?= fmtDt($r['criado_em']) ?></span>
              </div>
              <?php if ($r['motivo']): ?><p class="text-sm text-gray-600"><?= e($r['motivo']) ?></p><?php endif; ?>
              <?php if ($r['nota_revisao']): ?><p class="text-xs text-gray-400 mt-0.5 italic">Resposta: <?= e($r['nota_revisao']) ?></p><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
const workStart   = '<?= $workStart ?>';
const workEnd     = '<?= $workEnd ?>';
const lunchStart  = '<?= $lunchStart ?>';
const lunchMins   = <?= $lunchMins ?>;
const balanceMins = <?= (int)$balanceMins ?>;

function applyTimeMask(el) {
  let v = el.value.replace(/\D/g, '').slice(0, 4);
  if (v.length >= 3) v = v.slice(0, 2) + ':' + v.slice(2);
  el.value = v;
}
function isValidTime(t) { return /^([01]\d|2[0-3]):[0-5]\d$/.test(t); }
function timeToMins(t) { const [h,m]=t.split(':').map(Number); return h*60+m; }
function minsToHHMM(m) { return String(Math.floor(m/60)).padStart(2,'0')+':'+String(m%60).padStart(2,'0'); }
function dateToISO(d) { return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
function formatDate(iso) { const [y,m,d]=iso.split('-'); return `${d}/${m}/${y}`; }

const ptLocale = {
  months:{longhand:['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'],shorthand:['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez']},
  weekdays:{longhand:['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'],shorthand:['Dom','Seg','Ter','Qua','Qui','Sex','Sáb']},
  firstDayOfWeek:0
};
const fpBase = {dateFormat:'Y-m-d',altInput:true,altFormat:'d/m/Y',locale:ptLocale,disableMobile:false};

const fpSingle = flatpickr('#req-date',{...fpBase,onChange:([date])=>{document.getElementById('req-date-iso').value=date?dateToISO(date):'';updateSummary();}});
let fpEnd;
const fpStart = flatpickr('#custom-date-start',{...fpBase,onChange:([date])=>{const iso=date?dateToISO(date):'';document.getElementById('custom-date-start-iso').value=iso;if(fpEnd&&iso)fpEnd.set('minDate',iso);updateSummary();}});
fpEnd = flatpickr('#custom-date-end',{...fpBase,onChange:([date])=>{document.getElementById('custom-date-end-iso').value=date?dateToISO(date):'';updateSummary();}});

function getPeriodInfo() {
  const pt=document.querySelector('input[name="period_type"]:checked')?.value;
  const wsM=timeToMins(workStart),weM=timeToMins(workEnd);
  const LUNCH=lunchMins, workMins=weM-wsM-LUNCH, halfM=Math.floor(workMins/2);
  const morningEnd=minsToHHMM(wsM+halfM), afternoonSt=minsToHHMM(wsM+halfM+LUNCH);

  if(pt==='dia_inteiro'){
    const dateIso=document.getElementById('req-date-iso').value;
    if(!dateIso)return null;
    const lunchLabel=lunchMins>=60?`−${lunchMins/60}h almoço`:`−${lunchMins}min almoço`;
    return{dateLabel:formatDate(dateIso),periodLabel:`Dia inteiro · ${workStart} — ${workEnd} (${lunchLabel})`,mins:workMins,days:1,tipo:'dia_inteiro',data_inicio:dateIso};
  }
  if(pt==='meio'){
    const dateIso=document.getElementById('req-date-iso').value;
    if(!dateIso)return null;
    const ht=document.querySelector('input[name="half_type"]:checked')?.value;
    if(ht==='manha') return{dateLabel:formatDate(dateIso),periodLabel:`Manhã · ${workStart} — ${morningEnd}`,mins:halfM,days:1,tipo:'meio_periodo_manha',data_inicio:dateIso};
    return{dateLabel:formatDate(dateIso),periodLabel:`Tarde · ${afternoonSt} — ${workEnd}`,mins:workMins-halfM,days:1,tipo:'meio_periodo_tarde',data_inicio:dateIso};
  }
  if(pt==='personalizado'){
    const si=document.getElementById('custom-date-start-iso').value,ei=document.getElementById('custom-date-end-iso').value;
    const st=document.getElementById('custom-time-start').value,et=document.getElementById('custom-time-end').value;
    if(!si||!ei||!isValidTime(st)||!isValidTime(et))return null;
    const csM=timeToMins(st),ceM=timeToMins(et);
    if(ceM<=csM)return null;
    const minsPerDay=ceM-csM;
    const days=Math.round((new Date(ei+'T00:00:00')-new Date(si+'T00:00:00'))/86400000)+1;
    return{dateLabel:si===ei?formatDate(si):`${formatDate(si)} a ${formatDate(ei)}`,periodLabel:`${st} — ${et}`,mins:days*minsPerDay,days,tipo:'personalizado',data_inicio:si,data_fim:si!==ei?ei:null,hora_inicio:st,hora_fim:et};
  }
  return null;
}

function updateSummary(){
  const el=document.getElementById('req-summary');
  const info=getPeriodInfo();
  if(!info){el.classList.add('hidden');return;}
  document.getElementById('sum-date').textContent=info.dateLabel;
  document.getElementById('sum-period').textContent=info.periodLabel;
  document.getElementById('sum-hours').textContent=minsToHHMM(info.mins)+' h';
  const dr=document.getElementById('sum-days-row');
  if(info.days>1){document.getElementById('sum-days').textContent=`${info.days} dias × ${minsToHHMM(info.mins/info.days)}`;dr.classList.remove('hidden');}
  else dr.classList.add('hidden');
  el.classList.remove('hidden');
}

const ptypes=['dia_inteiro','meio','personalizado'];
function setPeriodCard(val){
  ptypes.forEach(v=>{
    const active=v===val;
    document.getElementById('pcard-'+v).style.borderColor=active?'var(--hw-purple)':'#e5e7eb';
    document.getElementById('pcard-'+v).style.background=active?'rgba(107,15,168,.04)':'';
    document.getElementById('picon-'+v).style.color=active?'var(--hw-purple)':'#9ca3af';
    document.getElementById('ptxt-'+v).style.color=active?'var(--hw-purple)':'#6b7280';
  });
  document.getElementById('single-date-section').classList.toggle('hidden',val==='personalizado');
  document.getElementById('half-section').classList.toggle('hidden',val!=='meio');
  document.getElementById('custom-section').classList.toggle('hidden',val!=='personalizado');
  updateSummary();
}
document.querySelectorAll('input[name="period_type"]').forEach(r=>r.addEventListener('change',()=>setPeriodCard(r.value)));

function setHalfCard(val){
  ['manha','tarde'].forEach(v=>{
    const active=v===val;
    document.getElementById('hcard-'+v).style.borderColor=active?'var(--hw-purple)':'#e5e7eb';
    document.getElementById('hcard-'+v).style.background=active?'rgba(107,15,168,.04)':'';
    document.getElementById('htxt-'+v).style.color=active?'var(--hw-purple)':'#6b7280';
  });
  updateSummary();
}
document.querySelectorAll('input[name="half_type"]').forEach(r=>r.addEventListener('change',()=>setHalfCard(r.value)));
// text inputs use oninput attribute directly; no extra listener needed

document.getElementById('req-form')?.addEventListener('submit',async function(e){
  e.preventDefault();
  const errEl=document.getElementById('req-error'); errEl.classList.add('hidden');
  const info=getPeriodInfo();
  if(!info){errEl.textContent='Selecione data e período corretamente.';errEl.classList.remove('hidden');return;}
  const reason=document.getElementById('req-reason').value.trim();
  if(!reason){errEl.textContent='O motivo é obrigatório.';errEl.classList.remove('hidden');return;}
  if(info.mins>balanceMins){errEl.textContent=`Saldo insuficiente. Disponível: ${minsToHHMM(balanceMins)}.`;errEl.classList.remove('hidden');return;}
  const btn=document.getElementById('req-btn'); btn.disabled=true; btn.textContent='Enviando…';
  try{
    const res=await fetch('/api/bh-requests.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'create',...info,motivo:reason})});
    let data={};try{data=await res.json();}catch(_){}
    if(!res.ok){errEl.textContent=data.error??`Erro ${res.status}.`;errEl.classList.remove('hidden');btn.disabled=false;btn.textContent='Confirmar solicitação';return;}
    location.reload();
  }catch(err){errEl.textContent='Erro de conexão.';errEl.classList.remove('hidden');btn.disabled=false;btn.textContent='Confirmar solicitação';}
});
</script>
</body></html>
