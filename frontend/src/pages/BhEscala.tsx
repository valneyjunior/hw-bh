import { useEffect, useState } from 'react'
import { getEscala, criarEscala, deletarEscala } from '../services/api'
import type { EscalaItem } from '../types/bh'

const TURNOS = [
  { valor: 'manha', label: 'Manhã' },
  { valor: 'tarde', label: 'Tarde' },
  { valor: 'noite', label: 'Noite' },
  { valor: 'dia_todo', label: 'Dia todo' },
]

const PARCIAIS = ['manha', 'tarde', 'noite'] as const

// Formata um turno (que pode ser CSV: "manha,tarde") para exibição amigável.
function formatarTurno(turno: string): string {
  if (turno === 'dia_todo') return 'Dia todo'
  const partes = turno.split(',').map((t) => TURNOS.find((x) => x.valor === t)?.label ?? t)
  return partes.join(' + ')
}

// Versão curta para a célula do calendário.
function formatarTurnoCurto(turno: string): string {
  if (turno === 'dia_todo') return 'Dia'
  return turno.split(',').map((t) => TURNOS.find((x) => x.valor === t)?.label?.slice(0, 3) ?? t).join('+')
}

const NOMES_DIA = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb']
const NOMES_MES = [
  'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
  'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
]

function formatarData(ano: number, mes: number, dia: number): string {
  return `${ano}-${String(mes + 1).padStart(2, '0')}-${String(dia).padStart(2, '0')}`
}

// ── Modal de turno ────────────────────────────────────────────────────────────

interface ModalTurnoProps {
  data: string
  turnoAtual: string | null
  onConfirmar: (turno: string) => void
  onRemover: () => void
  onFechar: () => void
}

function ModalTurno({ data, turnoAtual, onConfirmar, onRemover, onFechar }: ModalTurnoProps) {
  // Estado interno: conjunto de turnos parciais selecionados.
  // "dia_todo" é representado internamente como os 3 parciais marcados.
  const [sel, setSel] = useState<Set<string>>(() => {
    if (!turnoAtual) return new Set()
    if (turnoAtual === 'dia_todo') return new Set(PARCIAIS)
    return new Set(turnoAtual.split(','))
  })

  const todosMarcados = PARCIAIS.every((p) => sel.has(p))

  function toggleParcial(p: string) {
    setSel((prev) => {
      const next = new Set(prev)
      if (next.has(p)) next.delete(p)
      else next.add(p)
      return next
    })
  }

  function toggleDiaTodo() {
    setSel((prev) => (PARCIAIS.every((p) => prev.has(p)) ? new Set() : new Set(PARCIAIS)))
  }

  function confirmar() {
    if (sel.size === 0) { onRemover(); return }
    // Os 3 parciais → colapsa para "dia_todo"
    const turno = todosMarcados ? 'dia_todo' : PARCIAIS.filter((p) => sel.has(p)).join(',')
    onConfirmar(turno)
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-xs mx-4">
        <div className="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
          <h3 className="text-sm font-semibold text-gray-900">
            Disponibilidade — {data.split('-').reverse().join('/')}
          </h3>
          <button onClick={onFechar} className="text-gray-400 hover:text-gray-600 text-xl leading-none">×</button>
        </div>
        <div className="px-5 py-4 space-y-2">
          {PARCIAIS.map((p) => {
            const label = TURNOS.find((t) => t.valor === p)!.label
            return (
              <label key={p} className="flex items-center gap-2 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={sel.has(p)}
                  onChange={() => toggleParcial(p)}
                  className="w-4 h-4 accent-blue-600"
                />
                <span className="text-sm text-gray-700">{label}</span>
              </label>
            )
          })}

          <div className="pt-2 mt-1 border-t border-gray-100">
            <label className="flex items-center gap-2 cursor-pointer select-none">
              <input
                type="checkbox"
                checked={todosMarcados}
                onChange={toggleDiaTodo}
                className="w-4 h-4 accent-blue-600"
              />
              <span className="text-sm font-medium text-gray-800">Dia todo</span>
            </label>
            <p className="text-[11px] text-gray-400 mt-1 ml-6">Marca manhã, tarde e noite.</p>
          </div>
        </div>
        <div className="px-5 py-4 border-t border-gray-100 flex justify-between">
          {turnoAtual ? (
            <button
              onClick={onRemover}
              className="text-sm text-red-600 hover:underline"
            >
              Remover
            </button>
          ) : <span />}
          <div className="flex gap-2">
            <button
              onClick={onFechar}
              className="px-3 py-1.5 text-sm border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50"
            >
              Cancelar
            </button>
            <button
              onClick={confirmar}
              className="px-3 py-1.5 text-sm rounded-lg text-white font-medium bg-blue-600 hover:bg-blue-700"
            >
              Confirmar
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

export default function BhEscala() {
  const hoje = new Date()
  const [ano, setAno] = useState(hoje.getFullYear())
  const [mes, setMes] = useState(hoje.getMonth())
  const [escala, setEscala] = useState<EscalaItem[]>([])
  const [carregando, setCarregando] = useState(true)
  const [diaSelecionado, setDiaSelecionado] = useState<string | null>(null)
  const [processando, setProcessando] = useState(false)

  async function carregar(a: number, m: number) {
    setCarregando(true)
    try {
      const from = formatarData(a, m, 1)
      const ultimoDia = new Date(a, m + 1, 0).getDate()
      const to = formatarData(a, m, ultimoDia)
      const res = await getEscala({ from, to })
      setEscala(res.data as EscalaItem[])
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
  }

  const escalaMap = new Map(escala.map((e) => [e.data_disponivel, e]))

  function gerarDias(): Array<{ dia: number; data: string } | null> {
    const primeiroDia = new Date(ano, mes, 1).getDay()
    const totalDias = new Date(ano, mes + 1, 0).getDate()
    const cells: Array<{ dia: number; data: string } | null> = []
    for (let i = 0; i < primeiroDia; i++) cells.push(null)
    for (let d = 1; d <= totalDias; d++) {
      cells.push({ dia: d, data: formatarData(ano, mes, d) })
    }
    return cells
  }

  async function handleConfirmarTurno(turno: string) {
    if (!diaSelecionado || processando) return
    setProcessando(true)
    try {
      const existente = escalaMap.get(diaSelecionado)
      if (existente) {
        await deletarEscala(existente.id)
      }
      await criarEscala({ data_disponivel: diaSelecionado, turno })
      await carregar(ano, mes)
    } finally {
      setProcessando(false)
      setDiaSelecionado(null)
    }
  }

  async function handleRemover() {
    if (!diaSelecionado || processando) return
    const existente = escalaMap.get(diaSelecionado)
    if (!existente) { setDiaSelecionado(null); return }
    setProcessando(true)
    try {
      await deletarEscala(existente.id)
      await carregar(ano, mes)
    } finally {
      setProcessando(false)
      setDiaSelecionado(null)
    }
  }

  const dias = gerarDias()

  return (
    <div className="p-6 max-w-3xl mx-auto">
      {/* Cabeçalho */}
      <div className="mb-6">
        <h1 className="text-xl font-bold text-gray-900">Escala de Disponibilidade</h1>
        <p className="text-sm text-gray-500 mt-1">
          Sua disponibilidade é voluntária e não gera obrigação de atendimento.
        </p>
      </div>

      {/* Navegação do mês */}
      <div className="bg-white rounded-xl border border-gray-200 p-4 mb-4">
        <div className="flex items-center justify-between mb-4">
          <button
            onClick={() => navegarMes(-1)}
            className="p-2 rounded-lg hover:bg-gray-100 text-gray-600 font-bold"
          >
            ‹
          </button>
          <h2 className="text-base font-semibold text-gray-900">
            {NOMES_MES[mes]} {ano}
          </h2>
          <button
            onClick={() => navegarMes(1)}
            className="p-2 rounded-lg hover:bg-gray-100 text-gray-600 font-bold"
          >
            ›
          </button>
        </div>

        {/* Grade de dias */}
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
              if (!cell) {
                return <div key={`empty-${idx}`} />
              }
              const marcado = escalaMap.get(cell.data)
              const ehHoje =
                cell.dia === hoje.getDate() &&
                mes === hoje.getMonth() &&
                ano === hoje.getFullYear()

              return (
                <button
                  key={cell.data}
                  onClick={() => setDiaSelecionado(cell.data)}
                  className={[
                    'aspect-square flex flex-col items-center justify-center rounded-lg text-sm transition-colors',
                    marcado
                      ? 'text-white font-semibold'
                      : ehHoje
                      ? 'ring-2 ring-blue-400 text-blue-700 font-semibold hover:bg-blue-50'
                      : 'text-gray-700 hover:bg-gray-100',
                  ].join(' ')}
                  style={marcado ? { backgroundColor: '#3B82F6' } : {}}
                  title={marcado ? `Disponível — ${formatarTurno(marcado.turno)}` : ''}
                >
                  <span>{cell.dia}</span>
                  {marcado && (
                    <span className="text-[9px] leading-none opacity-80 mt-0.5">
                      {formatarTurnoCurto(marcado.turno)}
                    </span>
                  )}
                </button>
              )
            })}
          </div>
        )}
      </div>

      {/* Legenda */}
      <div className="flex items-center gap-4 text-xs text-gray-500">
        <div className="flex items-center gap-1.5">
          <div className="w-4 h-4 rounded bg-blue-500" />
          <span>Disponível</span>
        </div>
        <div className="flex items-center gap-1.5">
          <div className="w-4 h-4 rounded bg-gray-100 border border-gray-300" />
          <span>Sem marcação</span>
        </div>
      </div>

      {/* Modal de turno */}
      {diaSelecionado && (
        <ModalTurno
          data={diaSelecionado}
          turnoAtual={escalaMap.get(diaSelecionado)?.turno ?? null}
          onConfirmar={handleConfirmarTurno}
          onRemover={handleRemover}
          onFechar={() => setDiaSelecionado(null)}
        />
      )}
    </div>
  )
}
