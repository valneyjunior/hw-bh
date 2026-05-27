<?php $u = currentUser(); $page = basename($_SERVER['PHP_SELF']); ?>

<!-- Mobile top bar -->
<div class="hw-nav md:hidden fixed top-0 left-0 right-0 z-50 px-4 flex items-center justify-between" style="height:3.25rem;box-shadow:0 2px 12px rgba(0,0,0,.25)">
  <span class="font-bold text-sm text-white">BH <span class="hw-gradient-text">Tecnologia</span></span>
  <button onclick="hwToggleSidebar()" class="text-white/70 hover:text-white p-1 transition">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
    </svg>
  </button>
</div>

<!-- Mobile overlay -->
<div id="hw-overlay" class="fixed inset-0 bg-black/50 z-30 hidden md:hidden" onclick="hwToggleSidebar()"></div>

<!-- Sidebar -->
<aside id="hw-sidebar"
  class="hw-sidebar fixed left-0 top-0 h-screen flex flex-col z-40 transition-transform duration-200"
  style="width:14rem;transform:translateX(-100%)">

  <!-- Brand -->
  <div class="px-4 py-5 shrink-0">
    <a href="<?= homeUrl() ?>" class="flex items-center gap-3 no-underline">
      <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0" style="background:var(--hw-gradient)">
        <svg class="w-4 h-4" fill="none" stroke="white" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
      </div>
      <div>
        <p class="font-bold text-sm text-white leading-none">BH Tecnologia</p>
        <p class="text-xs mt-0.5" style="color:rgba(255,255,255,.4)">Hostweb</p>
      </div>
    </a>
  </div>

  <div class="mx-4 shrink-0" style="border-top:1px solid rgba(255,255,255,.08)"></div>

  <!-- Nav links -->
  <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
    <?php
    if (isAdmin()) {
        $links = [
            ['admin.php',        'Validação',      'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['admin-bh.php',     'Banco de Horas', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['admin-escala.php', 'Escala',         'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['admin-import.php', 'Importar',       'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12'],
            ['admin-reports.php','Relatórios',     'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
            ['admin-users.php',  'Usuários',       'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
            ['admin-setores.php','Setores',        'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
        ];
    } elseif (isCoordenador()) {
        $links = [
            ['admin.php',        'Validação Setor','M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['admin-bh.php',     'BH do Setor',   'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['admin-escala.php', 'Escala do Setor','M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['admin-import.php', 'Importar',       'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12'],
            ['admin-reports.php','Relatórios',     'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
            ['admin-users.php',  'Usuários',       'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
        ];
    } else {
        $links = [
            ['dashboard.php',  'Meus Registros', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
            ['bh-request.php', 'Banco de Horas', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['escala.php',     'Minha Escala',   'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
        ];
    }
    foreach ($links as [$file, $label, $d]):
    ?>
      <a href="/<?= $file ?>" class="hw-sidebar-link <?= $page === $file ? 'active' : '' ?>">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $d ?>"/>
        </svg>
        <?= $label ?>
      </a>
    <?php endforeach; ?>

    <?php if (isAnalista() && !isCoordenador()): ?>
    <!-- Separador + links rápidos do coordenador se for analista puro -->
    <?php endif; ?>
  </nav>

  <div class="mx-4 shrink-0" style="border-top:1px solid rgba(255,255,255,.08)"></div>

  <!-- Usuário + setor + logout -->
  <div class="px-4 py-4 shrink-0">
    <div class="mb-3 min-w-0">
      <div class="flex items-center gap-2 mb-0.5">
        <div class="hw-avatar w-7 h-7 text-xs shrink-0" style="background:var(--hw-gradient)">
          <?= strtoupper(mb_substr($u['nome'], 0, 1)) ?>
        </div>
        <span class="text-xs font-medium truncate" style="color:rgba(255,255,255,.75)"><?= e($u['nome']) ?></span>
      </div>
      <?php if ($u['setor_nome']): ?>
        <p class="text-xs pl-9" style="color:rgba(255,255,255,.35)"><?= e($u['setor_nome']) ?></p>
      <?php endif; ?>
    </div>
    <form method="POST" action="/logout.php">
      <button type="submit" class="hw-sidebar-link w-full" style="color:rgba(255,255,255,.45)">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
        </svg>
        Sair
      </button>
    </form>
  </div>
</aside>

<script>
(function() {
  const sb = document.getElementById('hw-sidebar');
  const ov = document.getElementById('hw-overlay');
  const mq = window.matchMedia('(min-width: 768px)');

  function applyLayout() {
    if (mq.matches) {
      sb.style.transform = 'translateX(0)';
      if (ov) ov.classList.add('hidden');
      document.body.classList.add('hw-has-sidebar');
    } else {
      sb.style.transform = 'translateX(-100%)';
      document.body.classList.add('hw-has-sidebar');
    }
  }

  window.hwToggleSidebar = function() {
    const open = sb.style.transform === 'translateX(0px)' || sb.style.transform === 'translateX(0)';
    sb.style.transform = open ? 'translateX(-100%)' : 'translateX(0)';
    ov.classList.toggle('hidden', open);
  };

  applyLayout();
  mq.addEventListener('change', applyLayout);
})();
</script>
