export type Perfil = 'admin' | 'coordenador' | 'analista' | 'atendimento'

export interface NavItem {
  label: string
  path: string
  icon: string
  tipos: Perfil[]
}

export const NAV_ITEMS: NavItem[] = [
  // Analista
  { label: 'Meus Registros',    path: '/meus-registros', icon: 'clock',        tipos: ['analista'] },
  { label: 'Banco de Horas',    path: '/folgas',        icon: 'credit-card',  tipos: ['analista'] },
  { label: 'Escala',            path: '/escala',        icon: 'calendar',     tipos: ['analista'] },
  // Atendimento Corporativo
  { label: 'Acionamento',        path: '/acionamento',    icon: 'phone',        tipos: ['atendimento', 'admin'] },
  // Coordenador + Admin
  { label: 'Validação',          path: '/validacao',      icon: 'check-circle', tipos: ['admin', 'coordenador'] },
  { label: 'Banco de Horas',     path: '/banco-horas',    icon: 'clock',        tipos: ['admin', 'coordenador'] },
  { label: 'Escala',             path: '/escala-setor',   icon: 'calendar',     tipos: ['admin', 'coordenador'] },
  { label: 'Relatórios',         path: '/relatorios',     icon: 'bar-chart',    tipos: ['admin', 'coordenador'] },
  // Admin only
  { label: 'Usuários',           path: '/usuarios',       icon: 'users',        tipos: ['admin'] },
  { label: 'Setores',            path: '/setores',        icon: 'layers',       tipos: ['admin'] },
  { label: 'Auditoria',          path: '/auditoria',      icon: 'shield',       tipos: ['admin'] },
  { label: 'Backup',             path: '/backup',         icon: 'database',     tipos: ['admin'] },
]
