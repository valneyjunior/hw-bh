import { useEffect, useState, useMemo } from 'react'
import { getAdminEscala } from '../services/api'
import type { EscalaAdminItem } from '../types/bh'

const TURNOS: Record<string, string> = {
  manha: 'Manhã',
  tarde: 'Tarde',
  noite: 'Noite',
  dia_todo: 'Dia todo',
}

// Turno pode ser CSV ("manha,tarde"); formata para exibição.
function fmtTurno(turno: string): string {
  if (turno === 'dia_todo') return 'Dia todo'
  return turno.split(',').map((t) => TURNOS[t] ?? t).join(' + ')
}

const NOMES_DIA = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb']
const NOMES_DIA_COMPLETO = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado']
const NOMES_MES = [
  'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
  'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
]

const CORES_SETOR = ['#3B82F6', '#10B981', '#8B5CF6', '#F59E0B', '#EC4899', '#06B6D4', '#EF4444', '#F97316']

function corSetor(nome: string): string {
  if (!nome) return '#94a3b8'
  let hash = 0
  for (let i = 0; i < nome.length; i++) hash = nome.charCodeAt(i) + ((hash << 5) - hash)
  return CORES_SETOR[Math.abs(hash) % CORES_SETOR.length]
}

function formatarData(ano: number, mes: number, dia: number): string {
  return `${ano}-${String(mes + 1).padStart(2, '0')}-${String(dia).padStart(2, '0')}`
}

function formatarDataBR(iso: string): string {
  const [y, m, d] = iso.split('-')
  return `${d}/${m}/${y}`
}

function IconDownload() {
  return (
    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
      <polyline points="7 10 12 15 17 10" />
      <line x1="12" y1="15" x2="12" y2="3" />
    </svg>
  )
}

export default function BhEscalaSetor() {
  const hoje = new Date()
  const [ano, setAno] = useState(hoje.getFullYear())
  const [mes, setMes] = useState(hoje.getMonth())
  const [escala, setEscala] = useState<EscalaAdminItem[]>([])
  const [carregando, setCarregando] = useState(true)
  const [diaDetalhe, setDiaDetalhe] = useState<string | null>(null)
  const [setorFiltro, setSetorFiltro] = useState<string | null>(null)

  async function carregar(a: number, m: number) {
    setCarregando(true)
    try {
      const from = formatarData(a, m, 1)
      const to = formatarData(a, m, new Date(a, m + 1, 0).getDate())
      const res = await getAdminEscala({ from, to })
      setEscala(res.data as EscalaAdminItem[])
    } catch {
      setEscala([])
    } finally {
      setCarregando(false)
    }
  }

  useEffect(() => { carregar(ano, mes) }, [ano, mes])

  function navegarMes(delta: number) {
    let novoMes = mes + delta
    let novoAno = ano
    if (novoMes < 0) { novoMes = 11; novoAno-- }
    if (novoMes > 11) { novoMes = 0; novoAno++ }
    setMes(novoMes)
    setAno(novoAno)
    setDiaDetalhe(null)
  }

  // Setores únicos presentes na escala deste mês
  const setores = useMemo(() => {
    const set = new Set(escala.map((e) => e.grupo_nome ?? 'Sem setor'))
    return [...set].sort()
  }, [escala])

  // Escala filtrada pelo setor selecionado
  const escalafiltrada = useMemo(() =>
    setorFiltro ? escala.filter((e) => (e.grupo_nome ?? 'Sem setor') === setorFiltro) : escala,
    [escala, setorFiltro]
  )

  const escalaByData = useMemo(() => {
    const map = new Map<string, EscalaAdminItem[]>()
    for (const e of escalafiltrada) {
      if (!map.has(e.data_disponivel)) map.set(e.data_disponivel, [])
      map.get(e.data_disponivel)!.push(e)
    }
    return map
  }, [escalafiltrada])

  function gerarDias() {
    const primeiroDia = new Date(ano, mes, 1).getDay()
    const totalDias = new Date(ano, mes + 1, 0).getDate()
    const cells: Array<{ dia: number; data: string } | null> = []
    for (let i = 0; i < primeiroDia; i++) cells.push(null)
    for (let d = 1; d <= totalDias; d++) cells.push({ dia: d, data: formatarData(ano, mes, d) })
    return cells
  }

  const dias = gerarDias()
  const detalheDia = diaDetalhe ? (escalaByData.get(diaDetalhe) ?? []) : []

  // Agrupar por setor para resumo
  const resumoPorSetor = useMemo(() => {
    const map = new Map<string, { usuarios: Map<number, { nome: string; dias: number }> }>()
    for (const e of escalafiltrada) {
      const setor = e.grupo_nome ?? 'Sem setor'
      if (!map.has(setor)) map.set(setor, { usuarios: new Map() })
      const u = map.get(setor)!.usuarios
      if (!u.has(e.usuario_id)) u.set(e.usuario_id, { nome: e.usuario_nome, dias: 0 })
      u.get(e.usuario_id)!.dias++
    }
    return map
  }, [escalafiltrada])

  function exportarCSV() {
    const linhas = [['Data', 'Dia da Semana', 'Colaborador', 'Setor', 'Turno']]
    const ordenado = [...escala].sort((a, b) => a.data_disponivel.localeCompare(b.data_disponivel))
    for (const e of ordenado) {
      const [y, m2, d] = e.data_disponivel.split('-').map(Number)
      const diaSem = NOMES_DIA_COMPLETO[new Date(y, m2 - 1, d).getDay()]
      linhas.push([
        formatarDataBR(e.data_disponivel),
        diaSem,
        e.usuario_nome,
        e.grupo_nome ?? 'Sem setor',
        fmtTurno(e.turno),
      ])
    }
    const csv = '﻿' + linhas.map((l) => l.join(';')).join('\r\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `escala-${NOMES_MES[mes].toLowerCase()}-${ano}.csv`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  }

  return (
    <div className="p-6 max-w-6xl mx-auto">
      {/* Cabeçalho */}
      <div className="flex items-start justify-between mb-5">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Escala do Setor</h1>
          <p className="text-sm text-gray-500 mt-0.5">
            Disponibilidade voluntária dos colaboradores — {NOMES_MES[mes]} {ano}
          </p>
        </div>
        <button
          onClick={exportarCSV}
          disabled={escala.length === 0}
          className="flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        >
          <IconDownload />
          Exportar mês
        </button>
      </div>

      {/* Filtro por setor */}
      {setores.length > 1 && (
        <div className="flex items-center gap-2 mb-5 flex-wrap">
          <span className="text-xs text-gray-500 font-medium">Filtrar por setor:</span>
          <button
            onClick={() => setSetorFiltro(null)}
            className={`px-3 py-1 rounded-full text-xs font-medium border transition-colors ${
              setorFiltro === null
                ? 'bg-gray-800 text-white border-gray-800'
                : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'
            }`}
          >
            Todos
          </button>
          {setores.map((s) => (
            <button
              key={s}
              onClick={() => setSetorFiltro(setorFiltro === s ? null : s)}
              className={`px-3 py-1 rounded-full text-xs font-medium border transition-colors ${
                setorFiltro === s
                  ? 'text-white border-transparent'
                  : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'
              }`}
              style={setorFiltro === s ? { backgroundColor: corSetor(s), borderColor: corSetor(s) } : {}}
            >
              <span
                className="inline-block w-1.5 h-1.5 rounded-full mr-1.5 align-middle"
                style={{ backgroundColor: setorFiltro === s ? 'white' : corSetor(s) }}
              />
              {s}
            </button>
          ))}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {/* Calendário */}
        <div className="lg:col-span-2">
          <div className="bg-white rounded-xl border border-gray-200 p-4">
            <div className="flex items-center justify-between mb-4">
              <button onClick={() => navegarMes(-1)} className="p-2 rounded-lg hover:bg-gray-100 text-gray-600 font-bold text-lg leading-none">‹</button>
              <h2 className="text-base font-semibold text-gray-900">{NOMES_MES[mes]} {ano}</h2>
              <button onClick={() => navegarMes(1)} className="p-2 rounded-lg hover:bg-gray-100 text-gray-600 font-bold text-lg leading-none">›</button>
            </div>

            <div className="grid grid-cols-7 gap-1 mb-1">
              {NOMES_DIA.map((d) => (
                <div key={d} className="text-center text-xs font-medium text-gray-400 py-1">{d}</div>
              ))}
            </div>

            {carregando ? (
              <div className="py-10 text-center text-sm text-gray-400">Carregando escala...</div>
            ) : (
              <div className="grid grid-cols-7 gap-1">
                {dias.map((cell, idx) => {
                  if (!cell) return <div key={`e-${idx}`} />
                  const itens = escalaByData.get(cell.data) ?? []
                  const ehHoje = cell.dia === hoje.getDate() && mes === hoje.getMonth() && ano === hoje.getFullYear()
                  const selecionado = diaDetalhe === cell.data

                  return (
                    <button
                      key={cell.data}
                      onClick={() => setDiaDetalhe(selecionado ? null : cell.data)}
                      className={[
                        'min-h-[64px] flex flex-col items-start justify-start p-1.5 rounded-lg text-left transition-colors border',
                        selecionado
                          ? 'border-blue-400 bg-blue-50'
                          : ehHoje
                          ? 'border-blue-200 ring-1 ring-blue-200'
                          : 'border-transparent hover:bg-gray-50',
                      ].join(' ')}
                    >
                      <span className={`text-xs font-medium mb-1 ${ehHoje ? 'text-blue-600' : 'text-gray-500'}`}>
                        {cell.dia}
                      </span>
                      <div className="flex flex-wrap gap-0.5">
                        {itens.slice(0, 4).map((e) => (
                          <span
                            key={e.id}
                            className="w-2 h-2 rounded-full inline-block flex-shrink-0"
                            style={{ backgroundColor: corSetor(e.grupo_nome ?? '') }}
                            title={`${e.usuario_nome}${e.grupo_nome ? ` (${e.grupo_nome})` : ''} — ${fmtTurno(e.turno)}`}
                          />
                        ))}
                        {itens.length > 4 && (
                          <span className="text-[9px] text-gray-400 leading-none mt-0.5">+{itens.length - 4}</span>
                        )}
                      </div>
                    </button>
                  )
                })}
              </div>
            )}
          </div>

          {/* Legenda de setores */}
          {setores.length > 0 && (
            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 px-1">
              {setores.map((s) => (
                <div key={s} className="flex items-center gap-1.5">
                  <span className="w-2.5 h-2.5 rounded-full flex-shrink-0" style={{ backgroundColor: corSetor(s) }} />
                  <span className="text-xs text-gray-600">{s}</span>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Painel lateral */}
        <div className="space-y-4">
          {/* Detalhe do dia */}
          {diaDetalhe && (
            <div className="bg-white rounded-xl border border-gray-200 p-4">
              <h3 className="text-sm font-semibold text-gray-800 mb-3">
                {formatarDataBR(diaDetalhe)}
                <span className="ml-1.5 text-xs font-normal text-gray-400">
                  {NOMES_DIA_COMPLETO[new Date(diaDetalhe).getDay()]}
                </span>
              </h3>
              {detalheDia.length === 0 ? (
                <p className="text-sm text-gray-400">Nenhuma disponibilidade registrada.</p>
              ) : (
                <div className="space-y-2.5">
                  {detalheDia.map((e) => (
                    <div key={e.id} className="flex items-start gap-2.5">
                      <span
                        className="w-2.5 h-2.5 rounded-full flex-shrink-0 mt-1"
                        style={{ backgroundColor: corSetor(e.grupo_nome ?? '') }}
                      />
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium text-gray-800 leading-tight">{e.usuario_nome}</p>
                        <div className="flex items-center gap-1.5 mt-0.5 flex-wrap">
                          {e.grupo_nome && (
                            <span
                              className="text-[10px] px-1.5 py-0.5 rounded font-medium text-white"
                              style={{ backgroundColor: corSetor(e.grupo_nome) }}
                            >
                              {e.grupo_nome}
                            </span>
                          )}
                          <span className="text-xs text-gray-400">{fmtTurno(e.turno)}</span>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Resumo por setor */}
          <div className="bg-white rounded-xl border border-gray-200 p-4">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-semibold text-gray-800">Disponíveis no Mês</h3>
              <span className="text-xs text-gray-400">{escalafiltrada.length} registros</span>
            </div>

            {resumoPorSetor.size === 0 ? (
              <p className="text-xs text-gray-400">Sem registros este mês.</p>
            ) : (
              <div className="space-y-4">
                {[...resumoPorSetor.entries()].map(([setor, { usuarios }]) => (
                  <div key={setor}>
                    <div className="flex items-center gap-1.5 mb-1.5">
                      <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ backgroundColor: corSetor(setor) }} />
                      <span className="text-xs font-semibold text-gray-600 uppercase tracking-wide">{setor}</span>
                    </div>
                    <div className="space-y-1.5 pl-3.5">
                      {[...usuarios.entries()].map(([uid, { nome, dias }]) => (
                        <div key={uid} className="flex items-center justify-between">
                          <span className="text-sm text-gray-700 truncate">{nome}</span>
                          <span className="text-xs font-semibold text-gray-400 ml-2 flex-shrink-0">{dias}d</span>
                        </div>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Legenda de turnos */}
          <div className="bg-white rounded-xl border border-gray-200 p-4">
            <h3 className="text-sm font-semibold text-gray-800 mb-2">Turnos</h3>
            <div className="space-y-1">
              {Object.entries(TURNOS).map(([, v]) => (
                <div key={v} className="text-xs text-gray-500">{v}</div>
              ))}
            </div>
            <p className="text-xs text-gray-400 mt-2.5 leading-snug">
              Cada • representa um colaborador disponível. Cor indica o setor.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
