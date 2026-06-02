import { Outlet } from 'react-router-dom'
import Sidebar from './Sidebar'
import type { UserInfo } from '../types/bh'

function getUser(): UserInfo | null {
  try {
    const raw = localStorage.getItem('bh_user')
    return raw ? (JSON.parse(raw) as UserInfo) : null
  } catch {
    return null
  }
}

export default function Layout() {
  const user = getUser()
  if (!user) return null

  return (
    <div className="flex h-screen overflow-hidden">
      <Sidebar user={user} />
      <main className="flex-1 overflow-auto bg-slate-50">
        <Outlet />
      </main>
    </div>
  )
}
