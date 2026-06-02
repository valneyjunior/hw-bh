import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { authAlterarSenha } from '../services/api'
import type { AxiosError } from 'axios'
import type { UserInfo } from '../types/bh'

export default function AlterarSenha() {
  const navigate = useNavigate()
  const [senhaAtual, setSenhaAtual] = useState('')
  const [novaSenha, setNovaSenha] = useState('')
  const [confirmacao, setConfirmacao] = useState('')
  const [erro, setErro] = useState('')
  const [sucesso, setSucesso] = useState('')
  const [loading, setLoading] = useState(false)

  const user: UserInfo | null = (() => {
    try {
      const raw = localStorage.getItem('bh_user')
      return raw ? JSON.parse(raw) : null
    } catch {
      return null
    }
  })()

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setErro('')
    setSucesso('')
    if (novaSenha !== confirmacao) {
      setErro('A nova senha e a confirmação não conferem.')
      return
    }
    if (novaSenha.length < 6) {
      setErro('A nova senha deve ter pelo menos 6 caracteres.')
      return
    }
    setLoading(true)
    try {
      await authAlterarSenha(senhaAtual, novaSenha)
      // Atualiza flag no storage
      if (user) {
        user.must_change_password = false
        localStorage.setItem('bh_user', JSON.stringify(user))
      }
      setSucesso('Senha alterada com sucesso! Redirecionando...')
      setTimeout(() => navigate('/'), 1500)
    } catch (err) {
      const ax = err as AxiosError<{ detail: string }>
      setErro(ax.response?.data?.detail ?? 'Erro ao alterar senha.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen bg-slate-50 flex items-center justify-center px-4">
      <div className="w-full max-w-md">
        <div className="bg-white rounded-2xl shadow-xl p-8">
          <div className="flex items-center gap-2 mb-8">
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

          <h2 className="text-2xl font-bold text-slate-900 mb-2">Alterar Senha</h2>
          {user?.must_change_password && (
            <p className="text-sm text-amber-600 bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-6">
              Você precisa alterar sua senha antes de continuar.
            </p>
          )}

          <form onSubmit={handleSubmit} className="space-y-5">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1.5">Senha atual</label>
              <input
                type="password"
                required
                value={senhaAtual}
                onChange={(e) => setSenhaAtual(e.target.value)}
                className="w-full border border-slate-200 rounded-lg px-4 py-2.5 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500"
                placeholder="••••••••"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1.5">Nova senha</label>
              <input
                type="password"
                required
                value={novaSenha}
                onChange={(e) => setNovaSenha(e.target.value)}
                className="w-full border border-slate-200 rounded-lg px-4 py-2.5 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500"
                placeholder="••••••••"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1.5">Confirmar nova senha</label>
              <input
                type="password"
                required
                value={confirmacao}
                onChange={(e) => setConfirmacao(e.target.value)}
                className="w-full border border-slate-200 rounded-lg px-4 py-2.5 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500"
                placeholder="••••••••"
              />
            </div>

            {erro && (
              <div className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-4 py-3">
                {erro}
              </div>
            )}
            {sucesso && (
              <div className="text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-3">
                {sucesso}
              </div>
            )}

            <button
              type="submit"
              disabled={loading}
              className="w-full text-white font-semibold py-3 rounded-lg transition-opacity disabled:opacity-60 text-sm"
              style={{ backgroundColor: '#E8001C' }}
            >
              {loading ? 'Salvando...' : 'Alterar Senha'}
            </button>
          </form>
        </div>
      </div>
    </div>
  )
}
