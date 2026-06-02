import { Navigate } from 'react-router-dom'
import type { UserInfo } from '../types/bh'

interface Props {
  children: React.ReactNode
  apenasAdmin?: boolean
  apenasCoordenador?: boolean
  apenasAtendimento?: boolean
}

function getUser(): UserInfo | null {
  try {
    const raw = localStorage.getItem('bh_user')
    return raw ? (JSON.parse(raw) as UserInfo) : null
  } catch {
    return null
  }
}

export default function PrivateRoute({ children, apenasAdmin, apenasCoordenador, apenasAtendimento }: Props) {
  const token = localStorage.getItem('bh_token')
  const user = getUser()

  if (!token || !user) {
    return <Navigate to="/login" replace />
  }

  if (user.must_change_password && window.location.pathname !== '/alterar-senha') {
    return <Navigate to="/alterar-senha" replace />
  }

  const perfis = user.perfis?.length ? user.perfis : [user.tipo]

  if (apenasAdmin && !perfis.includes('admin')) {
    return <Navigate to="/" replace />
  }

  if (apenasCoordenador && !perfis.includes('admin') && !perfis.includes('coordenador')) {
    return <Navigate to="/" replace />
  }

  if (apenasAtendimento && !perfis.includes('admin') && !perfis.includes('atendimento')) {
    return <Navigate to="/" replace />
  }

  return <>{children}</>
}
