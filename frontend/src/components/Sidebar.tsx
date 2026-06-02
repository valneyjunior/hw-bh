import { NavLink, useNavigate } from 'react-router-dom'
import type { UserInfo } from '../types/bh'
import { NAV_ITEMS } from '../config/navegacao'

interface Props {
  user: UserInfo
}

function IconClock() {
  return (
    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
      <circle cx="12" cy="12" r="10" />
      <polyline points="12 6 12 12 16 14" />
    </svg>
  )
}

function IconCheckCircle() {
  return (
    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4" />
      <circle cx="12" cy="12" r="10" />
    </svg>
  )
}

function IconBarChart() {
  return (
    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
      <rect x="3" y="12" width="4" height="9" rx="1" />
      <rect x="10" y="7" width="4" height="14" rx="1" />
      <rect x="17" y="3" width="4" height="18" rx="1" />
    </svg>
  )
}

function IconCalendar() {
  return (
    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
      <rect x="3" y="4" width="18" height="18" rx="2" />
      <line x1="16" y1="2" x2="16" y2="6" />
      <line x1="8" y1="2" x2="8" y2="6" />
      <line x1="3" y1="10" x2="21" y2="10" />
    </svg>
  )
}


function IconUsers() {
  return (
    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
      <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="4" />
      <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
      <path d="M16 3.13a4 4 0 0 1 0 7.75" />
    </svg>
  )
}

function IconLayers() {
  return (
    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
      <polygon points="12 2 2 7 12 12 22 7 12 2" />
      <polyline points="2 17 12 22 22 17" />
      <polyline points="2 12 12 17 22 12" />
    </svg>
  )
}

function IconCreditCard() {
  return (
    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
      <rect x="1" y="4" width="22" height="16" rx="2" />
      <line x1="1" y1="10" x2="23" y2="10" />
    </svg>
  )
}

function IconDatabase() {
  return (
    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
      <ellipse cx="12" cy="5" rx="9" ry="3" />
      <path d="M3 5v6c0 1.66 4 3 9 3s9-1.34 9-3V5" />
      <path d="M3 11v6c0 1.66 4 3 9 3s9-1.34 9-3v-6" />
    </svg>
  )
}

function IconPhone() {
  return (
    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
      <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" />
    </svg>
  )
}

function NavIcon({ icon }: { icon: string }) {
  switch (icon) {
    case 'clock': return <IconClock />
    case 'check-circle': return <IconCheckCircle />
    case 'bar-chart': return <IconBarChart />
    case 'calendar': return <IconCalendar />
    case 'users': return <IconUsers />
    case 'layers': return <IconLayers />
    case 'credit-card': return <IconCreditCard />
    case 'phone': return <IconPhone />
    case 'database': return <IconDatabase />
    default: return null
  }
}

export default function Sidebar({ user }: Props) {
  const navigate = useNavigate()

  const handleSair = () => {
    localStorage.removeItem('bh_token')
    localStorage.removeItem('bh_user')
    sessionStorage.clear()
    navigate('/login')
  }

  const iniciais = user.nome
    .split(' ')
    .slice(0, 2)
    .map((p) => p[0])
    .join('')
    .toUpperCase()

  // Filtra por perfis cumulativos (fallback para o tipo principal).
  const perfisUsuario = user.perfis?.length ? user.perfis : [user.tipo]
  const itemsVisiveis = NAV_ITEMS.filter((item) =>
    item.tipos.some((t) => perfisUsuario.includes(t))
  )

  return (
    <aside
      className="flex flex-col h-screen w-64 flex-shrink-0 text-white"
      style={{ backgroundColor: '#0f172a' }}
    >
      {/* Logo */}
      <div className="px-6 py-5 border-b border-white/10">
        <div className="flex items-center gap-2">
          <div
            className="w-8 h-8 rounded-lg flex items-center justify-center font-bold text-white text-sm"
            style={{ backgroundColor: '#E8001C' }}
          >
            H
          </div>
          <div>
            <p className="text-white font-bold text-sm leading-none tracking-wide">hostweb</p>
            <p className="text-xs mt-0.5" style={{ color: '#94a3b8' }}>
              Banco de Horas
            </p>
          </div>
        </div>
      </div>

      {/* Nav */}
      <nav className="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
        {itemsVisiveis.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            end={item.path === '/'}
            className={({ isActive }) =>
              [
                'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors',
                isActive
                  ? 'text-white'
                  : 'text-slate-400 hover:text-white hover:bg-white/5',
              ].join(' ')
            }
            style={({ isActive }) =>
              isActive ? { backgroundColor: '#E8001C' } : {}
            }
          >
            <NavIcon icon={item.icon} />
            {item.label}
          </NavLink>
        ))}
      </nav>

      {/* Rodapé */}
      <div className="px-4 py-4 border-t border-white/10">
        <div className="flex items-center gap-3 mb-3">
          <div
            className="w-9 h-9 rounded-full flex items-center justify-center text-white text-sm font-bold flex-shrink-0"
            style={{ backgroundColor: '#E8001C' }}
          >
            {iniciais}
          </div>
          <div className="min-w-0">
            <p className="text-sm font-medium text-white truncate">{user.nome}</p>
            <p className="text-xs truncate" style={{ color: '#94a3b8' }}>
              {user.grupo_nome ?? 'Administrador'}
            </p>
          </div>
        </div>
        <button
          onClick={handleSair}
          className="w-full text-left text-xs px-3 py-2 rounded-lg transition-colors hover:bg-white/10"
          style={{ color: '#94a3b8' }}
        >
          → Sair
        </button>
      </div>
    </aside>
  )
}
