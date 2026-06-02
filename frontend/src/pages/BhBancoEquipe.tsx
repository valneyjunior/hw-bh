import { useEffect, useState, useCallback } from 'react'
import { getAdminLancamentos, getAdminUsuarios } from '../services/api'
import type { Lancamento, UsuarioComConfig } from '../types/bh'
import Paginacao, { usePaginacao } from '../components/Paginacao'

function fmtData(d: string): string {
  const [y, mo, day] = d.split('-')
  return `${day}/${mo}/${y}`
}

function fmtMin(min: number): string {
  const h = Math.floor(Math.abs(min) / 60)
  const m = Math.abs(min) % 60
  return `${h}h${m > 0 ? ` ${m}m` : ''}`
}

function BadgeStatus({ status }: { status: string }) {
  const map: Record<string, string> = {
    pendente:   'bg-amber-100 text-amber-700',
    aprovado:   'bg-green-100 text-green-700',
    recusado:   'bg-red-100 text-red-700',
    contestado: 'bg-orange-100 text-orange-700',
  }
  const labels: Record<string, string> = {
    pendente:   'Pendente',
    aprovado:   'Aprovado',
    recusado:   'Recusado',
    contestado: 'Contestado',
  }
  return (
    <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium ${map[status] ?? 'bg-slate-100 text-slate-600'}`}>
      {labels[status] ?? status}
    </span>
  )
}

function horas48(criado_em: string): boolean {
  return (new Date().getTime() - new Date(criado_em).getTime()) > 48 * 60 * 60 * 1000
}

export default function BhBancoEquipe() {
  const [lancamentos, setLancamentos] = useState<Lancamento[]>([])
  const [usuarios, setUsuarios] = useState<UsuarioComConfig[]>([])
  const [loading, setLoading] = useState(true)
  const [filtroStatus, setFiltroStatus] = useState('')
  const [filtroFrom, setFiltroFrom] = useState('')
  const [filtroTo, setFiltroTo] = useState('')
  const [filtroUsuario, setFiltroUsuario] = useState('')

  const carregar = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, string> = {}
      if (filtroStatus) params.status = filtroStatus
      if (filtroFrom) params.from = filtroFrom
      if (filtroTo) params.to = filtroTo
      if (filtroUsuario) params.usuario_id = filtroUsuario

      const [lancRes, usuRes] = await Promise.all([
        getAdminLancamentos(params),
        getAdminUsuarios(),
      ])
      setLancamentos((lancRes.data as { items: Lancamento[] }).items)
      setUsuarios(usuRes.data as UsuarioComConfig[])
    } finally {
      setLoading(false)
    }
  }, [filtroStatus, filtroFrom, filtroTo, filtroUsuario])

  useEffect(() => { carregar() }, [carregar])

  const pendentes48 = lancamentos.filter(
    (l) => l.status === 'pendente' && horas48(l.criado_em)
  ).length

  const pag = usePaginacao(lancamentos, 20)

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-900">Banco de Horas da Equipe</h1>
        <p className="text-slate-500 text-sm mt-0.5">Todos os lançamentos do setor</p>
      </div>

      {pendentes48 > 0 && (
        <div className="flex items-center gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
          <span className="text-amber-600 text-lg">⏰</span>
          <p className="text-sm text-amber-700 font-medium">
            {pendentes48} lançamento{pendentes48 > 1 ? 's' : ''} pendente{pendentes48 > 1 ? 's' : ''} há mais de 48h — acesse <strong>Validação</strong> para revisar.
          </p>
        </div>
      )}

      {/* Filtros */}
      <div className="bg-white rounded-xl border border-gray-200 p-4">
        <div className="flex flex-wrap gap-3 items-end">
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Colaborador</label>
            <select
              value={filtroUsuario}
              onChange={(e) => setFiltroUsuario(e.target.value)}
              className="border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500"
            >
              <option value="">Todos</option>
              {usuarios.map((u) => (
                <option key={u.id} value={String(u.id)}>{u.nome}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Status</label>
            <select
              value={filtroStatus}
              onChange={(e) => setFiltroStatus(e.target.value)}
              className="border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500"
            >
              <option value="">Todos</option>
              <option value="pendente">Pendente</option>
              <option value="aprovado">Aprovado</option>
              <option value="contestado">Contestado</option>
              <option value="recusado">Recusado</option>
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">De</label>
            <input type="date" value={filtroFrom} onChange={(e) => setFiltroFrom(e.target.value)}
              className="border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Até</label>
            <input type="date" value={filtroTo} onChange={(e) => setFiltroTo(e.target.value)}
              className="border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500"
            />
          </div>
          <button
            onClick={() => { setFiltroStatus(''); setFiltroFrom(''); setFiltroTo(''); setFiltroUsuario('') }}
            className="text-sm text-slate-500 hover:text-slate-700 py-2 px-3 rounded-lg hover:bg-slate-50 border border-slate-200"
          >
            Limpar
          </button>
        </div>
      </div>

      {/* Tabela */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-slate-400 text-sm">Carregando...</div>
        ) : lancamentos.length === 0 ? (
          <div className="p-12 text-center text-slate-400 text-sm">Nenhum lançamento encontrado.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50">
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Colaborador</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Data</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Horário</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Chamado</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Duração</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Valor CLT</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {pag.itensPagina.map((l) => {
                  const atrasado = l.status === 'pendente' && horas48(l.criado_em)
                  return (
                    <tr key={l.id} className={`hover:bg-slate-50 transition-colors ${atrasado ? 'bg-amber-50/40' : ''}`}>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          <span className="font-medium text-slate-800">{l.usuario_nome ?? `#${l.usuario_id}`}</span>
                          {atrasado && (
                            <span className="text-[10px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full font-medium">+48h</span>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-slate-700">{fmtData(l.data_acionamento)}</td>
                      <td className="px-4 py-3 text-slate-600">{l.hora_inicio.slice(0, 5)} – {l.hora_fim.slice(0, 5)}</td>
                      <td className="px-4 py-3 text-slate-600">{l.chamado}</td>
                      <td className="px-4 py-3 text-slate-600">{l.total_minutos != null ? fmtMin(l.total_minutos) : '-'}</td>
                      <td className="px-4 py-3"><BadgeStatus status={l.status} /></td>
                      <td className="px-4 py-3 text-slate-700">
                        {l.valor_calculado != null
                          ? `R$ ${Number(l.valor_calculado).toFixed(2).replace('.', ',')}`
                          : '-'}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
            <Paginacao
              pagina={pag.pagina}
              totalPaginas={pag.totalPaginas}
              porPagina={pag.porPagina}
              total={pag.total}
              onPagina={pag.setPagina}
              onPorPagina={pag.mudarPorPagina}
            />
          </div>
        )}
      </div>
    </div>
  )
}
