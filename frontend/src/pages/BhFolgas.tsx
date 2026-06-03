import { useEffect, useState, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { getSaldoCompleto, getMinhasFolgas, criarFolga, cancelarFolga } from '../services/api'
import { HoraInput } from '../components/HoraInput'
import type { Folga, SaldoCompleto } from '../types/bh'

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmtHHMM(min: number): string {
  const abs = Math.abs(min)
  const h = Math.floor(abs / 60)
  const m = abs % 60
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
}

function fmtData(iso: string): string {
  const [y, mo, d] = iso.split('-')
  return `${d}/${mo}/${y}`
}

function fmtDatetime(iso: string): string {
  const dt = new Date(iso)
  return dt.toLocaleDateString('pt-BR') + ' ' + dt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

const TIPO_LABEL: Record<string, string> = {
  dia_inteiro:  'Dia inteiro',
  meio_manha:   'Meio período — Manhã',
  meio_tarde:   'Meio período — Tarde',
  personalizado: 'Personalizado',
}

const STATUS_STYLE: Record<string, string> = {
  pendente:  'bg-amber-100 text-amber-700',
  aprovado:  'bg-green-100 text-green-700',
  recusado:  'bg-red-100 text-red-700',
}

function calcMinutos(tipo: string, horaInicio: string, horaFim: string, s: SaldoCompleto): number {
  const toMin = (t: string) => {
    const [h, m] = t.split(':').map(Number)
    return h * 60 + (m || 0)
  }
  const ws = toMin(s.work_start.slice(0, 5))
  const we = toMin(s.work_end.slice(0, 5))
  const ls = toMin(s.lunch_start.slice(0, 5))
  const lm = s.lunch_minutes
  if (tipo === 'dia_inteiro') return we - ws - lm
  if (tipo === 'meio_manha') return ls - ws
  if (tipo === 'meio_tarde') return we - (ls + lm)
  if (tipo === 'personalizado' && horaInicio && horaFim) return toMin(horaFim) - toMin(horaInicio)
  return 0
}

// ── Ícones ────────────────────────────────────────────────────────────────────

function IconCalFull() {
  return (
    <svg className="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
      <rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" />
      <rect x="7" y="14" width="10" height="4" rx="1" fill="currentColor" opacity=".4" />
    </svg>
  )
}

function IconCalHalf() {
  return (
    <svg className="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
      <circle cx="12" cy="12" r="9" /><line x1="12" y1="3" x2="12" y2="12" /><polyline points="12 12 16.5 7.5" />
    </svg>
  )
}

function IconCalCustom() {
  return (
    <svg className="w-6 h-6 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
      <rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" />
      <line x1="8" y1="15" x2="16" y2="15" /><line x1="8" y1="18" x2="13" y2="18" />
    </svg>
  )
}

// ── Página ────────────────────────────────────────────────────────────────────

export default function BhFolgas() {
  const [saldo, setSaldo] = useState<SaldoCompleto | null>(null)
  const [folgas, setFolgas] = useState<Folga[]>([])
  const [carregando, setCarregando] = useState(true)

  // Form
  const [tipo, setTipo] = useState<'dia_inteiro' | 'meio_manha' | 'meio_tarde' | 'personalizado'>('dia_inteiro')
  const [subTipo, setSubTipo] = useState<'manha' | 'tarde'>('manha')
  const [dataFolga, setDataFolga] = useState('')
  const [horaInicio, setHoraInicio] = useState('')
  const [horaFim, setHoraFim] = useState('')
  const [motivo, setMotivo] = useState('')
  const [enviando, setEnviando] = useState(false)
  const [erro, setErro] = useState('')
  const [sucesso, setSucesso] = useState('')

  const carregar = useCallback(async () => {
    setCarregando(true)
    try {
      const [sRes, fRes] = await Promise.all([getSaldoCompleto(), getMinhasFolgas()])
      setSaldo(sRes.data as SaldoCompleto)
      setFolgas(fRes.data as Folga[])
    } finally {
      setCarregando(false)
    }
  }, [])

  useEffect(() => { carregar() }, [carregar])

  // Tipo efetivo (meio_manha / meio_tarde é selecionado via subTipo)
  const tipoEfetivo = tipo === 'dia_inteiro' ? 'dia_inteiro'
    : tipo === 'personalizado' ? 'personalizado'
    : subTipo === 'manha' ? 'meio_manha' : 'meio_tarde'

  const minutosPreview = saldo ? calcMinutos(tipoEfetivo, horaInicio, horaFim, saldo) : 0
  const saldoApos = saldo ? saldo.saldo_disponivel - minutosPreview : 0

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setErro('')
    setSucesso('')
    if (!dataFolga) { setErro('Selecione a data da folga'); return }
    if (!motivo.trim()) { setErro('Informe o motivo'); return }
    if (tipo === 'personalizado' && (!horaInicio || !horaFim)) {
      setErro('Informe os horários para período personalizado'); return
    }

    setEnviando(true)
    try {
      await criarFolga({
        data_folga: dataFolga,
        tipo: tipoEfetivo,
        hora_inicio: tipo === 'personalizado' ? horaInicio + ':00' : undefined,
        hora_fim: tipo === 'personalizado' ? horaFim + ':00' : undefined,
        motivo: motivo.trim(),
      })
      setSucesso('Solicitação enviada com sucesso!')
      setDataFolga('')
      setMotivo('')
      setHoraInicio('')
      setHoraFim('')
      await carregar()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { detail?: string } } })?.response?.data?.detail
      setErro(msg ?? 'Erro ao enviar solicitação')
    } finally {
      setEnviando(false)
    }
  }

  async function handleCancelar(id: number) {
    if (!confirm('Cancelar esta solicitação?')) return
    await cancelarFolga(id)
    await carregar()
  }

  const jornada = saldo
    ? `${saldo.work_start.slice(0, 5)} — ${saldo.work_end.slice(0, 5)}`
    : '—'

  return (
    <div className="p-6 max-w-3xl mx-auto">
      {/* Cabeçalho */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Meu Banco de Horas</h1>
          <p className="text-sm text-gray-500 mt-0.5">Seu saldo e solicitações de uso do banco de horas</p>
        </div>
        <Link to="/" className="text-sm font-medium hover:underline" style={{ color: '#8B5CF6' }}>
          ← Meus registros
        </Link>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-3 gap-4 mb-6">
        <div className="bg-white rounded-xl border border-gray-200 p-4">
          <p className="text-xs font-semibold text-teal-600 uppercase tracking-wide mb-1">Total Aprovado</p>
          <p className="text-2xl font-bold text-teal-600">{saldo ? fmtHHMM(saldo.banco_minutos) : '—'}</p>
        </div>
        <div className="bg-white rounded-xl border border-gray-200 p-4">
          <p className="text-xs font-semibold text-red-500 uppercase tracking-wide mb-1">Deduções (Folgas)</p>
          <p className="text-2xl font-bold text-red-500">
            {saldo ? (saldo.deducoes_minutos > 0 ? '-' : '') + fmtHHMM(saldo.deducoes_minutos) : '—'}
          </p>
        </div>
        <div className="bg-white rounded-xl border border-gray-200 p-4">
          <p className="text-xs font-semibold text-green-600 uppercase tracking-wide mb-1">Saldo Disponível</p>
          <p className="text-2xl font-bold text-green-600">{saldo ? fmtHHMM(saldo.saldo_disponivel) : '—'}</p>
        </div>
      </div>

      {/* Formulário */}
      <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 className="text-base font-semibold text-gray-900 mb-0.5">Solicitar uso de banco de horas</h2>
        <p className="text-xs text-gray-400 mb-5">
          Saldo disponível: {saldo ? fmtHHMM(saldo.saldo_disponivel) : '—'} · Jornada: {jornada}
        </p>

        <form onSubmit={handleSubmit} className="space-y-5">
          {/* Tipo de período */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Tipo de período</label>
            <div className="grid grid-cols-3 gap-3">
              {(
                [
                  { key: 'dia_inteiro', label: 'Dia inteiro', icon: <IconCalFull /> },
                  { key: 'meio', label: 'Meio período', icon: <IconCalHalf /> },
                  { key: 'personalizado', label: 'Personalizado', icon: <IconCalCustom /> },
                ] as const
              ).map(({ key, label, icon }) => {
                const ativo = tipo === key || (key === 'meio' && (tipo === 'meio_manha' || tipo === 'meio_tarde'))
                return (
                  <button
                    key={key}
                    type="button"
                    onClick={() => {
                      if (key === 'meio') setTipo('meio_manha')
                      else setTipo(key as typeof tipo)
                    }}
                    className={`flex flex-col items-center justify-center py-4 px-3 rounded-xl border-2 text-sm font-medium transition-all ${
                      ativo
                        ? 'border-purple-500 text-purple-600 bg-purple-50'
                        : 'border-gray-200 text-gray-500 hover:border-gray-300 hover:bg-gray-50'
                    }`}
                  >
                    {icon}
                    {label}
                  </button>
                )
              })}
            </div>

            {/* Sub-opções de meio período */}
            {(tipo === 'meio_manha' || tipo === 'meio_tarde') && (
              <div className="flex gap-2 mt-3">
                <button type="button"
                  onClick={() => setTipo('meio_manha')}
                  className={`px-4 py-1.5 rounded-lg text-sm border font-medium transition-colors ${
                    tipo === 'meio_manha' ? 'bg-purple-600 text-white border-purple-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'
                  }`}>
                  Manhã
                </button>
                <button type="button"
                  onClick={() => setTipo('meio_tarde')}
                  className={`px-4 py-1.5 rounded-lg text-sm border font-medium transition-colors ${
                    tipo === 'meio_tarde' ? 'bg-purple-600 text-white border-purple-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'
                  }`}>
                  Tarde
                </button>
              </div>
            )}

            {/* Horários personalizados */}
            {tipo === 'personalizado' && (
              <div className="flex gap-3 mt-3">
                <div className="flex-1">
                  <label className="block text-xs text-gray-500 mb-1">Hora início</label>
                  <HoraInput value={horaInicio} onChange={setHoraInicio} required
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
                </div>
                <div className="flex-1">
                  <label className="block text-xs text-gray-500 mb-1">Hora fim</label>
                  <HoraInput value={horaFim} onChange={setHoraFim} required
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" />
                </div>
              </div>
            )}

            {/* Preview de dedução */}
            {minutosPreview > 0 && (
              <p className="text-xs text-gray-400 mt-2">
                Deduzirá <span className="font-semibold text-gray-600">{fmtHHMM(minutosPreview)}</span> do banco
                {' — '}saldo após: <span className={`font-semibold ${saldoApos < 0 ? 'text-red-500' : 'text-green-600'}`}>{fmtHHMM(saldoApos)}</span>
              </p>
            )}
          </div>

          {/* Data */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Data da folga</label>
            <input type="date" value={dataFolga} onChange={(e) => setDataFolga(e.target.value)}
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500" required />
          </div>

          {/* Motivo */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Motivo <span className="text-red-500">*</span>
            </label>
            <textarea rows={3} value={motivo} onChange={(e) => setMotivo(e.target.value)}
              placeholder="Ex: Compensação de acionamento em março"
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 resize-none"
              required />
          </div>

          {erro && <p className="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{erro}</p>}
          {sucesso && <p className="text-sm text-green-700 bg-green-50 rounded-lg px-3 py-2">{sucesso}</p>}

          <button type="submit" disabled={enviando || carregando}
            className="w-full py-3 rounded-xl text-white font-semibold text-sm disabled:opacity-60 transition-opacity"
            style={{ background: 'linear-gradient(to right, #E8001C, #8B5CF6)' }}>
            {enviando ? 'Enviando...' : 'Confirmar solicitação'}
          </button>
        </form>
      </div>

      {/* Histórico */}
      <div>
        <h2 className="text-base font-semibold text-gray-900 mb-3">Histórico de solicitações</h2>
        {carregando ? (
          <div className="py-10 text-center text-sm text-gray-400">Carregando...</div>
        ) : folgas.length === 0 ? (
          <div className="bg-white rounded-xl border border-gray-200 py-10 text-center text-sm text-gray-400">
            Nenhuma solicitação encontrada.
          </div>
        ) : (
          <div className="space-y-3">
            {folgas.map((f) => (
              <div key={f.id} className="bg-white rounded-xl border border-gray-200 px-5 py-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex-1 min-w-0 space-y-1.5">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="text-base font-bold text-gray-900">{fmtHHMM(f.minutos_deduzidos)} h</span>
                      <span className={`text-xs px-2 py-0.5 rounded-full font-medium capitalize ${STATUS_STYLE[f.status]}`}>
                        {f.status.charAt(0).toUpperCase() + f.status.slice(1)}
                      </span>
                      <span className="text-sm text-gray-500">{fmtData(f.data_folga)}</span>
                      <span className="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full font-medium">
                        {TIPO_LABEL[f.tipo] ?? f.tipo}
                      </span>
                      <span className="text-xs text-gray-400">{fmtDatetime(f.criado_em)}</span>
                    </div>
                    <p className="text-sm text-gray-600">{f.motivo}</p>
                    {f.nota_revisao && (
                      <p className="text-xs text-red-500 italic">Nota: {f.nota_revisao}</p>
                    )}
                  </div>
                  {f.status === 'pendente' && (
                    <button onClick={() => handleCancelar(f.id)}
                      className="text-xs text-red-400 hover:text-red-600 hover:underline flex-shrink-0 mt-0.5">
                      Cancelar
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
