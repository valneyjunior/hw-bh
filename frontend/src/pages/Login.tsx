import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { authLogin } from '../services/api'
import type { UserInfo } from '../types/bh'
import type { AxiosError } from 'axios'

export default function Login() {
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [senha, setSenha] = useState('')
  const [erro, setErro] = useState('')
  const [loading, setLoading] = useState(false)

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setErro('')
    setLoading(true)
    try {
      const res = await authLogin(email, senha)
      const { access_token, user } = res.data as { access_token: string; user: UserInfo }
      localStorage.setItem('bh_token', access_token)
      localStorage.setItem('bh_user', JSON.stringify(user))
      if (user.must_change_password) {
        navigate('/alterar-senha')
      } else {
        navigate('/')
      }
    } catch (err) {
      const ax = err as AxiosError<{ detail: string }>
      setErro(ax.response?.data?.detail ?? 'Erro ao fazer login. Verifique suas credenciais.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen flex" style={{ backgroundColor: '#0f172a' }}>
      {/* Painel esquerdo */}
      <div className="hidden lg:flex lg:w-1/2 flex-col justify-center px-16" style={{ backgroundColor: '#0f172a' }}>
        <div className="max-w-md">
          <div className="flex items-center gap-3 mb-8">
            <div
              className="w-12 h-12 rounded-xl flex items-center justify-center font-bold text-white text-xl"
              style={{ backgroundColor: '#E8001C' }}
            >
              H
            </div>
            <div>
              <p className="text-white font-bold text-xl tracking-wide uppercase">hostweb</p>
              <p className="text-slate-400 text-sm">Banco de Horas</p>
            </div>
          </div>
          <h1 className="text-4xl font-bold text-white mb-4 leading-tight">
            Gerencie seu<br />
            <span style={{ color: '#E8001C' }}>Banco de Horas</span>
          </h1>
          <p className="text-slate-400 text-base leading-relaxed">
            Registre acionamentos, acompanhe saldos e visualize relatórios com cálculo CLT automático.
          </p>
        </div>
      </div>

      {/* Painel direito */}
      <div className="flex-1 flex items-center justify-center px-6 py-12 bg-slate-50">
        <div className="w-full max-w-md">
          <div className="bg-white rounded-2xl shadow-xl p-8">
            {/* Logo mobile */}
            <div className="flex items-center gap-2 mb-8 lg:hidden">
              <div
                className="w-9 h-9 rounded-lg flex items-center justify-center font-bold text-white"
                style={{ backgroundColor: '#E8001C' }}
              >
                H
              </div>
              <div>
                <p className="font-bold text-slate-900">hostweb</p>
                <p className="text-xs text-slate-500">Banco de Horas</p>
              </div>
            </div>

            <h2 className="text-2xl font-bold text-slate-900 mb-2">Entrar</h2>
            <p className="text-slate-500 text-sm mb-8">Acesse com suas credenciais corporativas</p>

            <form onSubmit={handleSubmit} className="space-y-5">
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  E-mail
                </label>
                <input
                  type="email"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full border border-slate-200 rounded-lg px-4 py-2.5 text-slate-900 text-sm focus:outline-none focus:ring-2 focus:border-transparent transition"
                  style={{ '--tw-ring-color': '#E8001C' } as React.CSSProperties}
                  placeholder="seu@hostweb.cloud"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1.5">
                  Senha
                </label>
                <input
                  type="password"
                  required
                  value={senha}
                  onChange={(e) => setSenha(e.target.value)}
                  className="w-full border border-slate-200 rounded-lg px-4 py-2.5 text-slate-900 text-sm focus:outline-none focus:ring-2 focus:border-transparent transition"
                  placeholder="••••••••"
                />
              </div>

              {erro && (
                <div className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-4 py-3">
                  {erro}
                </div>
              )}

              <button
                type="submit"
                disabled={loading}
                className="w-full text-white font-semibold py-3 rounded-lg transition-opacity disabled:opacity-60 text-sm"
                style={{ backgroundColor: '#E8001C' }}
              >
                {loading ? 'Entrando...' : 'Entrar'}
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  )
}
