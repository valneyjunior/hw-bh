export type Perfil = 'admin' | 'coordenador' | 'analista' | 'atendimento'

export interface NavItem {
  label: string
  path: string
  icon: string
  tipos: Perfil[]
}

export const NAV_ITEMS: NavItem[] = [
  // Analista (visão pessoal — termos de posse)
  { label: 'Meus Registros',     path: '/meus-registros', icon: 'clock',        tipos: ['analista'] },
  { label: 'Meu Banco de Horas', path: '/folgas',         icon: 'credit-card',  tipos: ['analista'] },
  { label: 'Minha Escala',       path: '/escala',         icon: 'calendar',     tipos: ['analista'] },
  // Atendimento Corporativo
  { label: 'Acionamento',        path: '/acionamento',    icon: 'phone',        tipos: ['atendimento', 'admin'] },
  // Coordenador + Admin (visão de equipe)
  { label: 'Validação',          path: '/validacao',      icon: 'check-circle', tipos: ['admin', 'coordenador'] },
  { label: 'BH da Equipe',       path: '/banco-horas',    icon: 'wallet',       tipos: ['admin', 'coordenador'] },
  { label: 'Escala da Equipe',   path: '/escala-setor',   icon: 'calendar-team', tipos: ['admin', 'coordenador'] },
  { label: 'Relatórios',         path: '/relatorios',     icon: 'bar-chart',    tipos: ['admin', 'coordenador'] },
  // Admin only
  { label: 'Usuários',           path: '/usuarios',       icon: 'users',        tipos: ['admin'] },
  { label: 'Setores',            path: '/setores',        icon: 'layers',       tipos: ['admin'] },
  { label: 'Auditoria',          path: '/auditoria',      icon: 'shield',       tipos: ['admin'] },
  { label: 'Backup',             path: '/backup',         icon: 'database',     tipos: ['admin'] },
]
