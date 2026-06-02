import { useEffect, useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from 'recharts'
import { getAdminRelatorio, getAdminSetores } from '../services/api'
import type { RelatorioKpi, Setor } from '../types/bh'
import Paginacao, { usePaginacao } from '../components/Paginacao'

function baixarCSV(nome: string, linhas: (string | number)[][]) {
  const csv = '﻿' + linhas.map((l) => l.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(';')).join('\r\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = nome
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

function KpiCard({ label, valor, sub }: { label: string; valor: string; sub?: string }) {
  return (
    <div className="bg-white rounded-xl border border-gray-200 p-4">
      <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">{label}</p>
      <p className="text-2xl font-bold text-slate-900">{valor}</p>
      {sub && <p className="text-xs text-slate-400 mt-1">{sub}</p>}
    </div>
  )
}

const TIPO_LABELS: Record<string, string> = {
  diurno: 'Diurno',
  noturno: 'Noturno',
  sabado: 'Sábado',
  domingo: 'Domingo',
  feriado: 'Feriado',
}

export default function BhRelatorios() {
  const navigate = useNavigate()
  const [relatorio, setRelatorio] = useState<RelatorioKpi | null>(null)
  const [setores, setSetores] = useState<Setor[]>([])
  const [loading, setLoading] = useState(true)
  const [filtroFrom, setFiltroFrom] = useState('')
  const [filtroTo, setFiltroTo] = useState('')
  const [filtroGrupo, setFiltroGrupo] = useState('')

  const carregar = useCallback(async () => {
    setLoading(true)
    try {
      const params: Record<string, string> = {}
      if (filtroFrom) params.from = filtroFrom
      if (filtroTo) params.to = filtroTo
      if (filtroGrupo) params.grupo_id = filtroGrupo

      const [relRes, setoresRes] = await Promise.all([
        getAdminRelatorio(params),
        getAdminSetores(),
      ])
      setRelatorio(relRes.data as RelatorioKpi)
      setSetores(setoresRes.data as Setor[])
    } finally {
      setLoading(false)
    }
  }, [filtroFrom, filtroTo, filtroGrupo])

  useEffect(() => { carregar() }, [carregar])

  const dadosTipo = relatorio
    ? Object.entries(relatorio.por_tipo).map(([key, val]) => ({
        tipo: TIPO_LABELS[key] ?? key,
        horas: val.horas,
        valor: val.valor,
      }))
    : []

  const dadosMes = (relatorio?.por_mes ?? []).map((m) => ({
    mes: m.mes.slice(5) + '/' + m.mes.slice(0, 4),
    horas: m.horas,
    valor: m.valor,
  }))

  // Paginação do ranking
  const rankPag = usePaginacao(relatorio?.colaboradores ?? [], 10)

  function exportarExcel() {
    if (!relatorio) return
    const linhas: (string | number)[][] = []
    linhas.push(['RELATÓRIO — BANCO DE HORAS'])
    if (filtroFrom || filtroTo) linhas.push([`Período: ${filtroFrom || '...'} a ${filtroTo || '...'}`])
    linhas.push([])
    linhas.push(['Indicador', 'Valor'])
    linhas.push(['Total de acionamentos', relatorio.total_acionamentos])
    linhas.push(['Horas aprovadas', `${relatorio.horas_aprovadas.toFixed(1)}h`])
    linhas.push(['Custo CLT total', `R$ ${Number(relatorio.custo_clt_total).toFixed(2)}`])
    linhas.push(['Média por acionamento', `${relatorio.media_por_acionamento.toFixed(1)}h`])
    linhas.push([])
    linhas.push(['Horas por tipo', 'Horas', 'Custo CLT (R$)'])
    for (const [k, v] of Object.entries(relatorio.por_tipo)) {
      linhas.push([TIPO_LABELS[k] ?? k, v.horas.toFixed(1), v.valor.toFixed(2)])
    }
    linhas.push([])
    linhas.push(['Mês', 'Horas', 'Custo CLT (R$)', 'Acionamentos'])
    for (const m of relatorio.por_mes) {
      linhas.push([m.mes, m.horas.toFixed(1), m.valor.toFixed(2), m.acionamentos])
    }
    linhas.push([])
    linhas.push(['Colaborador', 'Setor', 'Acionamentos', 'Horas', 'Custo CLT (R$)'])
    for (const c of relatorio.colaboradores) {
      linhas.push([c.nome, c.grupo_nome ?? '-', c.acionamentos, c.horas.toFixed(1), c.valor.toFixed(2)])
    }
    baixarCSV('relatorio-banco-de-horas.csv', linhas)
  }

  return (
    <div className="p-6 space-y-6 print-area">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Relatórios</h1>
          <p className="text-slate-500 text-sm mt-0.5">Visão consolidada do banco de horas</p>
        </div>
        <div className="flex gap-2 no-print">
          <button
            onClick={exportarExcel}
            disabled={!relatorio}
            className="flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40"
          >
            ⬇ Exportar Excel
          </button>
          <button
            onClick={() => window.print()}
            disabled={!relatorio}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-white text-sm font-medium disabled:opacity-40"
            style={{ backgroundColor: '#E8001C' }}
          >
            🖨 Exportar PDF
          </button>
        </div>
      </div>

      {/* Filtros */}
      <div className="bg-white rounded-xl border border-gray-200 p-4 no-print">
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
            <label className="block text-xs font-medium text-slate-600 mb-1">Setor</label>
            <select
              value={filtroGrupo}
              onChange={(e) => setFiltroGrupo(e.target.value)}
              className="border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500"
            >
              <option value="">Todos os setores</option>
              {setores.map((s) => (
                <option key={s.id} value={String(s.id)}>{s.nome}</option>
              ))}
            </select>
          </div>
          <button
            onClick={() => { setFiltroFrom(''); setFiltroTo(''); setFiltroGrupo('') }}
            className="text-sm text-slate-500 hover:text-slate-700 py-2 px-3 rounded-lg hover:bg-slate-50 border border-slate-200"
          >
            Limpar
          </button>
        </div>
      </div>

      {loading ? (
        <div className="text-center text-slate-400 py-20 text-sm">Carregando...</div>
      ) : relatorio ? (
        <>
          {/* KPIs */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <KpiCard
              label="Total Acionamentos"
              valor={String(relatorio.total_acionamentos)}
            />
            <KpiCard
              label="Horas Aprovadas"
              valor={`${relatorio.horas_aprovadas.toFixed(1)}h`}
            />
            <KpiCard
              label="Custo CLT Total"
              valor={`R$ ${Number(relatorio.custo_clt_total).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`}
              sub="estimativa"
            />
            <KpiCard
              label="Média / Acionamento"
              valor={`${relatorio.media_por_acionamento.toFixed(1)}h`}
            />
          </div>

          {/* Gráficos */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {/* Por tipo */}
            <div className="bg-white rounded-xl border border-gray-200 p-5">
              <h3 className="text-sm font-semibold text-slate-700 mb-4">Horas por Tipo</h3>
              {dadosTipo.every((d) => d.horas === 0) ? (
                <p className="text-sm text-slate-400 text-center py-8">Sem dados</p>
              ) : (
                <ResponsiveContainer width="100%" height={220}>
                  <BarChart data={dadosTipo} barCategoryGap="30%">
                    <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                    <XAxis dataKey="tipo" tick={{ fontSize: 12, fill: '#64748b' }} />
                    <YAxis tick={{ fontSize: 11, fill: '#94a3b8' }} />
                    <Tooltip
                      formatter={(v: number) => [`${v.toFixed(1)}h`, 'Horas']}
                      contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e2e8f0' }}
                    />
                    <Bar dataKey="horas" fill="#E8001C" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </div>

            {/* Tendência mensal */}
            <div className="bg-white rounded-xl border border-gray-200 p-5">
              <h3 className="text-sm font-semibold text-slate-700 mb-4">Tendência Mensal</h3>
              {dadosMes.length === 0 ? (
                <p className="text-sm text-slate-400 text-center py-8">Sem dados</p>
              ) : (
                <ResponsiveContainer width="100%" height={220}>
                  <BarChart data={dadosMes} barCategoryGap="30%">
                    <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                    <XAxis dataKey="mes" tick={{ fontSize: 11, fill: '#64748b' }} />
                    <YAxis tick={{ fontSize: 11, fill: '#94a3b8' }} />
                    <Tooltip
                      formatter={(v: number, name: string) => [
                        name === 'horas' ? `${v.toFixed(1)}h` : `R$ ${v.toFixed(2)}`,
                        name === 'horas' ? 'Horas' : 'Custo CLT',
                      ]}
                      contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e2e8f0' }}
                    />
                    <Legend wrapperStyle={{ fontSize: 12 }} />
                    <Bar dataKey="horas" name="Horas" fill="#3B82F6" radius={[4, 4, 0, 0]} />
                    <Bar dataKey="valor" name="Custo CLT (R$)" fill="#10B981" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </div>
          </div>

          {/* Colaboradores */}
          <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div className="px-5 py-4 border-b border-slate-100">
              <h3 className="text-sm font-semibold text-slate-700">Ranking por Colaborador</h3>
            </div>
            {relatorio.colaboradores.length === 0 ? (
              <p className="text-sm text-slate-400 text-center py-8">Sem dados</p>
            ) : (
              <>
                <div className="divide-y divide-slate-50">
                  {rankPag.itensPagina.map((c) => (
                    <div
                      key={c.usuario_id}
                      className="flex items-center justify-between px-5 py-3.5 hover:bg-slate-50 transition-colors"
                    >
                      <div>
                        <p className="text-sm font-medium text-slate-900">{c.nome}</p>
                        <p className="text-xs text-slate-500">{c.grupo_nome ?? '-'} · {c.acionamentos} acionamentos</p>
                      </div>
                      <div className="flex items-center gap-6 text-sm">
                        <div className="text-right">
                          <p className="font-semibold text-slate-900">{c.horas.toFixed(1)}h</p>
                          <p className="text-xs text-slate-400">horas</p>
                        </div>
                        <div className="text-right">
                          <p className="font-semibold" style={{ color: '#E8001C' }}>
                            R$ {c.valor.toFixed(2).replace('.', ',')}
                          </p>
                          <p className="text-xs text-slate-400">custo CLT</p>
                        </div>
                        <button
                          onClick={() => navigate(`/relatorios/${c.usuario_id}`)}
                          className="text-xs text-blue-600 hover:underline whitespace-nowrap no-print"
                        >
                          Ver análise →
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
                <Paginacao
                  pagina={rankPag.pagina}
                  totalPaginas={rankPag.totalPaginas}
                  porPagina={rankPag.porPagina}
                  total={rankPag.total}
                  onPagina={rankPag.setPagina}
                  onPorPagina={rankPag.mudarPorPagina}
                />
              </>
            )}
          </div>
        </>
      ) : null}
    </div>
  )
}
