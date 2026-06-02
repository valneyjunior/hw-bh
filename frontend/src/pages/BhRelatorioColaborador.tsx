import { useEffect, useState, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts'
import { getRelatorioColaborador } from '../services/api'
import type { RelatorioColaborador, Lancamento } from '../types/bh'
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

function fmtData(d: string): string {
  const [y, mo, day] = d.split('-')
  return `${day}/${mo}/${y}`
}

function fmtMin(min: number): string {
  const h = Math.floor(min / 60)
  const m = min % 60
  return `${h}h${m > 0 ? ` ${m}m` : ''}`
}

function BadgeStatus({ status }: { status: string }) {
  const map: Record<string, string> = {
    pendente: 'bg-amber-100 text-amber-700',
    aprovado: 'bg-green-100 text-green-700',
    recusado: 'bg-red-100 text-red-700',
  }
  return (
    <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium ${map[status] ?? 'bg-slate-100 text-slate-600'}`}>
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </span>
  )
}

export default function BhRelatorioColaborador() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [dados, setDados] = useState<RelatorioColaborador | null>(null)
  const [loading, setLoading] = useState(true)
  const [filtroFrom, setFiltroFrom] = useState('')
  const [filtroTo, setFiltroTo] = useState('')

  const carregar = useCallback(() => {
    if (!id) return
    setLoading(true)
    const params: Record<string, string> = {}
    if (filtroFrom) params.from = filtroFrom
    if (filtroTo) params.to = filtroTo
    getRelatorioColaborador(Number(id), params)
      .then((res) => setDados(res.data as RelatorioColaborador))
      .finally(() => setLoading(false))
  }, [id, filtroFrom, filtroTo])

  useEffect(() => { carregar() }, [carregar])

  const dadosMes = (dados?.por_mes ?? []).map((m) => ({
    mes: m.mes.slice(5) + '/' + m.mes.slice(0, 4),
    horas: m.horas,
    valor: m.valor,
  }))

  // Hooks antes de qualquer return condicional
  const lancList = (dados?.lancamentos ?? []) as Lancamento[]
  const lancPag = usePaginacao(lancList, 10)

  function exportarExcel() {
    if (!dados) return
    const linhas: (string | number)[][] = []
    linhas.push([`RELATÓRIO INDIVIDUAL — ${dados.usuario.nome}`])
    linhas.push([dados.usuario.email, dados.usuario.grupo_nome ?? 'Sem setor'])
    if (filtroFrom || filtroTo) linhas.push([`Período: ${filtroFrom || '...'} a ${filtroTo || '...'}`])
    linhas.push([])
    linhas.push(['Indicador', 'Valor'])
    linhas.push(['Acionamentos', dados.kpis.total_acionamentos])
    linhas.push(['Horas aprovadas', `${dados.kpis.horas_aprovadas.toFixed(1)}h`])
    linhas.push(['Custo CLT total', `R$ ${Number(dados.kpis.custo_clt_total).toFixed(2)}`])
    linhas.push([])
    linhas.push(['Data', 'Início', 'Fim', 'Chamado', 'Duração (min)', 'Status', 'Valor CLT (R$)'])
    for (const l of lancList) {
      linhas.push([
        fmtData(l.data_acionamento),
        l.hora_inicio.slice(0, 5),
        l.hora_fim.slice(0, 5),
        l.chamado,
        l.total_minutos ?? 0,
        l.status,
        Number(l.valor_calculado ?? 0).toFixed(2),
      ])
    }
    baixarCSV(`relatorio-${dados.usuario.nome.replace(/\s+/g, '-').toLowerCase()}.csv`, linhas)
  }

  if (loading) {
    return <div className="p-6 text-sm text-slate-400">Carregando...</div>
  }

  if (!dados) {
    return <div className="p-6 text-sm text-red-500">Colaborador não encontrado.</div>
  }

  const { usuario, config, kpis } = dados

  return (
    <div className="p-6 space-y-6 print-area">
      {/* Header */}
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-center gap-4">
          <button
            onClick={() => navigate('/relatorios')}
            className="text-slate-400 hover:text-slate-700 text-sm no-print"
          >
            ← Voltar
          </button>
          <div>
            <h1 className="text-2xl font-bold text-slate-900">{usuario.nome}</h1>
            <p className="text-slate-500 text-sm mt-0.5">
              {usuario.grupo_nome ?? 'Sem setor'} · {usuario.tipo} · {usuario.email}
            </p>
          </div>
        </div>
        <div className="flex gap-2 no-print">
          <button
            onClick={exportarExcel}
            className="flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            ⬇ Excel
          </button>
          <button
            onClick={() => window.print()}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-white text-sm font-medium"
            style={{ backgroundColor: '#E8001C' }}
          >
            🖨 PDF
          </button>
        </div>
      </div>

      {/* Filtro de período */}
      <div className="bg-white rounded-xl border border-gray-200 p-4 no-print">
        <div className="flex flex-wrap gap-3 items-end">
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">De</label>
            <input type="date" value={filtroFrom} onChange={(e) => setFiltroFrom(e.target.value)}
              className="border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500" />
          </div>
          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Até</label>
            <input type="date" value={filtroTo} onChange={(e) => setFiltroTo(e.target.value)}
              className="border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500" />
          </div>
          <button
            onClick={() => { setFiltroFrom(''); setFiltroTo('') }}
            className="text-sm text-slate-500 hover:text-slate-700 py-2 px-3 rounded-lg hover:bg-slate-50 border border-slate-200"
          >
            Limpar
          </button>
        </div>
      </div>

      {/* Config salarial */}
      {config && (
        <div className="bg-white rounded-xl border border-gray-200 p-4">
          <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Configuração CLT</p>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
              <p className="text-slate-400 text-xs">Salário bruto</p>
              <p className="font-semibold text-slate-800">
                R$ {Number(config.salario_bruto).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
              </p>
            </div>
            <div>
              <p className="text-slate-400 text-xs">Base horária (÷220)</p>
              <p className="font-semibold text-slate-800">
                R$ {(Number(config.salario_bruto) / 220).toFixed(2).replace('.', ',')}
              </p>
            </div>
            <div>
              <p className="text-slate-400 text-xs">Jornada</p>
              <p className="font-semibold text-slate-800">
                {config.work_start.slice(0, 5)} – {config.work_end.slice(0, 5)}
              </p>
            </div>
            <div>
              <p className="text-slate-400 text-xs">Almoço</p>
              <p className="font-semibold text-slate-800">{config.lunch_minutes} min</p>
            </div>
          </div>
        </div>
      )}

      {/* KPIs */}
      <div className="grid grid-cols-3 gap-4">
        <div className="bg-white rounded-xl border border-gray-200 p-4">
          <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Acionamentos</p>
          <p className="text-2xl font-bold text-slate-900">{kpis.total_acionamentos}</p>
        </div>
        <div className="bg-white rounded-xl border border-gray-200 p-4">
          <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Horas Aprovadas</p>
          <p className="text-2xl font-bold" style={{ color: '#3B82F6' }}>
            {kpis.horas_aprovadas.toFixed(1)}h
          </p>
        </div>
        <div className="bg-white rounded-xl border border-gray-200 p-4">
          <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Custo CLT Total</p>
          <p className="text-2xl font-bold" style={{ color: '#E8001C' }}>
            R$ {Number(kpis.custo_clt_total).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
          </p>
        </div>
      </div>

      {/* Gráfico mensal */}
      <div className="bg-white rounded-xl border border-gray-200 p-5">
        <h3 className="text-sm font-semibold text-slate-700 mb-4">Evolução Mensal de Horas</h3>
        {dadosMes.length === 0 ? (
          <p className="text-sm text-slate-400 text-center py-8">Sem dados no período</p>
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
              <Bar dataKey="horas" name="horas" fill="#E8001C" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        )}
      </div>

      {/* Tabela de lançamentos */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div className="px-5 py-4 border-b border-slate-100">
          <h3 className="text-sm font-semibold text-slate-700">Lançamentos Aprovados</h3>
        </div>
        {lancList.length === 0 ? (
          <p className="text-sm text-slate-400 text-center py-8">Sem lançamentos</p>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 bg-slate-50">
                    <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Data</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Horário</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Chamado</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Duração</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Valor CLT</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                  {lancPag.itensPagina.map((l) => (
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
                      <td className="px-4 py-3 text-right font-semibold" style={{ color: '#E8001C' }}>
                        {l.valor_calculado != null
                          ? `R$ ${Number(l.valor_calculado).toFixed(2).replace('.', ',')}`
                          : '-'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Paginacao
              pagina={lancPag.pagina}
              totalPaginas={lancPag.totalPaginas}
              porPagina={lancPag.porPagina}
              total={lancPag.total}
              onPagina={lancPag.setPagina}
              onPorPagina={lancPag.mudarPorPagina}
            />
          </>
        )}
      </div>
    </div>
  )
}
