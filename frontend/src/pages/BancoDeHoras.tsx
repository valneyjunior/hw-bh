import { useEffect, useState, useCallback } from 'react'
import { getMeusLancamentos, getMeuSaldo, cancelarLancamento } from '../services/api'
import type { Lancamento, SaldoBH } from '../types/bh'
import BhLancamento from './BhLancamento'
import Paginacao, { usePaginacao } from '../components/Paginacao'

function fmtMin(min: number): string {
  const h = Math.floor(Math.abs(min) / 60)
  const m = Math.abs(min) % 60
  const sinal = min < 0 ? '-' : ''
  return `${sinal}${h}h${m > 0 ? ` ${m}m` : ''}`
}

function fmtData(d: string): string {
  const [y, mo, day] = d.split('-')
  return `${day}/${mo}/${y}`
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

function KpiCard({
  label,
  valor,
  cor,
}: {
  label: string
  valor: string
  cor?: string
}) {
  return (
    <div className="bg-white rounded-xl border border-gray-200 p-4">
      <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">{label}</p>
      <p className="text-2xl font-bold" style={{ color: cor ?? '#0f172a' }}>
        {valor}
      </p>
    </div>
  )
}

export default function BancoDeHoras() {
  const [saldo, setSaldo] = useState<SaldoBH | null>(null)
  const [lancamentos, setLancamentos] = useState<Lancamento[]>([])
  const [loading, setLoading] = useState(true)
  const [modalAberto, setModalAberto] = useState(false)
  const [lancamentoEditar, setLancamentoEditar] = useState<Lancamento | null>(null)
  const [filtroStatus, setFiltroStatus] = useState('')
  const [filtroFrom, setFiltroFrom] = useState('')
  const [filtroTo, setFiltroTo] = useState('')

  const carregar = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, string> = {}
      if (filtroStatus) params.status = filtroStatus
      if (filtroFrom) params.from = filtroFrom
      if (filtroTo) params.to = filtroTo

      const [saldoRes, lancRes] = await Promise.all([
        getMeuSaldo(),
        getMeusLancamentos(params),
      ])
      setSaldo(saldoRes.data as SaldoBH)
      setLancamentos(lancRes.data as Lancamento[])
    } finally {
      setLoading(false)
    }
  }, [filtroStatus, filtroFrom, filtroTo])

  useEffect(() => { carregar() }, [carregar])

  const handleCancelar = async (id: number) => {
    if (!confirm('Cancelar este lançamento?')) return
    await cancelarLancamento(id)
    carregar()
  }

  const temPendentesAntigos = lancamentos.some(
    (l) => l.status === 'pendente' && (new Date().getTime() - new Date(l.criado_em).getTime()) > 48 * 60 * 60 * 1000
  )

  const pag = usePaginacao(lancamentos, 10)

  return (
    <div className="p-6 space-y-6">
      {/* Banner amigável 48h */}
      {temPendentesAntigos && (
        <div className="flex items-start gap-3 bg-blue-50 border border-blue-200 rounded-xl px-4 py-3">
          <span className="text-blue-500 text-lg mt-0.5">💡</span>
          <p className="text-sm text-blue-700">
            Registre suas horas extras logo após o acionamento para facilitar a validação pelo coordenador e evitar acúmulo de lançamentos pendentes.
          </p>
        </div>
      )}

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Meu Banco de Horas</h1>
          <p className="text-slate-500 text-sm mt-0.5">Acompanhe seus acionamentos e saldo</p>
        </div>
        <button
          onClick={() => { setLancamentoEditar(null); setModalAberto(true) }}
          className="px-4 py-2.5 text-white text-sm font-medium rounded-lg transition-opacity hover:opacity-90"
          style={{ backgroundColor: '#E8001C' }}
        >
          + Novo Lançamento
        </button>
      </div>

      {/* KPIs */}
      {saldo && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <KpiCard label="Saldo BH" valor={fmtMin(saldo.saldo_minutos)} cor="#E8001C" />
          <KpiCard label="Aprovados" valor={String(saldo.aprovados)} cor="#10B981" />
          <KpiCard label="Pendentes" valor={String(saldo.pendentes)} cor="#F59E0B" />
          <KpiCard label="Recusados" valor={String(saldo.recusados)} cor="#EF4444" />
        </div>
      )}

      {/* Filtros */}
      <div className="bg-white rounded-xl border border-gray-200 p-4">
        <div className="flex flex-wrap gap-3 items-end">
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
              <option value="recusado">Recusado</option>
            </select>
          </div>
          <button
            onClick={() => { setFiltroFrom(''); setFiltroTo(''); setFiltroStatus('') }}
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
          <div className="p-12 text-center text-slate-400 text-sm">
            Nenhum lançamento encontrado.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50">
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Data</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Horário</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Chamado</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Duração</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {pag.itensPagina.map((l) => (
                  <tr key={l.id} className="hover:bg-slate-50 transition-colors">
                    <td className="px-4 py-3 font-medium text-slate-800">{fmtData(l.data_acionamento)}</td>
                    <td className="px-4 py-3 text-slate-600">
                      {l.hora_inicio.slice(0, 5)} – {l.hora_fim.slice(0, 5)}
                    </td>
                    <td className="px-4 py-3 text-slate-600">{l.chamado}</td>
                    <td className="px-4 py-3 text-slate-600">
                      {l.total_minutos != null ? fmtMin(l.total_minutos) : '-'}
                    </td>
                    <td className="px-4 py-3">
                      <BadgeStatus status={l.status} />
                    </td>
                    <td className="px-4 py-3">
                      {(l.status === 'pendente' || l.status === 'contestado') && (
                        <div className="flex items-center gap-2">
                          <button
                            onClick={() => { setLancamentoEditar(l); setModalAberto(true) }}
                            className="text-xs text-blue-600 hover:underline"
                          >
                            Editar
                          </button>
                          {l.status === 'pendente' && (
                            <button
                              onClick={() => handleCancelar(l.id)}
                              className="text-xs text-red-600 hover:underline"
                            >
                              Cancelar
                            </button>
                          )}
                        </div>
                      )}
                      {l.status === 'contestado' && l.nota_revisao && (
                        <div className="mt-1 text-xs text-orange-600 bg-orange-50 border border-orange-200 rounded px-2 py-1 max-w-xs">
                          <span className="font-semibold">Solicitação do coordenador:</span> {l.nota_revisao}
                        </div>
                      )}
                      {l.status === 'recusado' && l.nota_revisao && (
                        <span className="text-xs text-slate-400" title={l.nota_revisao}>
                          Ver nota
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
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

      {/* Modal */}
      {modalAberto && (
        <BhLancamento
          lancamentoEditar={lancamentoEditar}
          onClose={() => setModalAberto(false)}
          onSalvo={() => { setModalAberto(false); carregar() }}
        />
      )}
    </div>
  )
}
