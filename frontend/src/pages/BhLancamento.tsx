import { useState, type FormEvent } from 'react'
import { criarLancamento, editarLancamento } from '../services/api'
import { HoraInput } from '../components/HoraInput'
import { detectarFeriado } from '../utils/feriados'
import type { Lancamento, LancamentoCriarPayload } from '../types/bh'
import type { AxiosError } from 'axios'

interface Props {
  onClose: () => void
  onSalvo: () => void
  lancamentoEditar?: Lancamento | null
}

export default function BhLancamento({ onClose, onSalvo, lancamentoEditar }: Props) {
  const hoje = new Date().toISOString().split('T')[0]

  const [data, setData] = useState(lancamentoEditar?.data_acionamento ?? hoje)
  const [horaInicio, setHoraInicio] = useState(lancamentoEditar?.hora_inicio?.slice(0, 5) ?? '')
  const [horaFim, setHoraFim] = useState(lancamentoEditar?.hora_fim?.slice(0, 5) ?? '')
  const [chamado, setChamado] = useState(lancamentoEditar?.chamado ?? '')
  const [motivo, setMotivo] = useState(lancamentoEditar?.motivo ?? '')
  const [feriado, setFeriado] = useState(lancamentoEditar?.feriado ?? false)
  const [descricaoFeriado, setDescricaoFeriado] = useState(lancamentoEditar?.descricao_feriado ?? '')

  function handleDataChange(iso: string) {
    setData(iso)
    if (!lancamentoEditar) {
      const det = detectarFeriado(iso)
      if (det.feriado) {
        setFeriado(true)
        setDescricaoFeriado(det.descricao)
      } else {
        setFeriado(false)
        setDescricaoFeriado('')
      }
    }
  }
  const [erro, setErro] = useState('')
  const [loading, setLoading] = useState(false)

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setErro('')

    // Validação client-side
    if (horaFim <= horaInicio) {
      setErro('Hora de fim deve ser posterior à hora de início.')
      return
    }

    const payload: LancamentoCriarPayload = {
      data_acionamento: data,
      hora_inicio: horaInicio,
      hora_fim: horaFim,
      chamado,
      motivo,
      feriado,
      descricao_feriado: feriado ? descricaoFeriado : undefined,
    }

    setLoading(true)
    try {
      if (lancamentoEditar) {
        await editarLancamento(lancamentoEditar.id, payload)
      } else {
        await criarLancamento(payload)
      }
      onSalvo()
    } catch (err) {
      const ax = err as AxiosError<{ detail: string }>
      setErro(ax.response?.data?.detail ?? 'Erro ao salvar lançamento.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg">
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <h3 className="text-lg font-semibold text-slate-900">
            {lancamentoEditar ? 'Editar Lançamento' : 'Novo Lançamento'}
          </h3>
          <button
            onClick={onClose}
            className="text-slate-400 hover:text-slate-600 text-xl leading-none"
          >
            ×
          </button>
        </div>

        <form onSubmit={handleSubmit} className="px-6 py-5 space-y-4">
          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Data</label>
              <input
                type="date"
                required
                value={data}
                onChange={(e) => handleDataChange(e.target.value)}
                className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500"
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Início</label>
              <HoraInput
                required
                value={horaInicio}
                onChange={setHoraInicio}
                className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500"
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-slate-600 mb-1">Fim</label>
              <HoraInput
                required
                value={horaFim}
                onChange={setHoraFim}
                className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Chamado</label>
            <input
              type="text"
              required
              maxLength={100}
              value={chamado}
              onChange={(e) => setChamado(e.target.value)}
              placeholder="Ex: CHD-1234"
              className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500"
            />
          </div>

          <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">Motivo</label>
            <textarea
              required
              rows={3}
              value={motivo}
              onChange={(e) => setMotivo(e.target.value)}
              placeholder="Descreva o motivo do acionamento..."
              className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-red-500 resize-none"
            />
          </div>

          {feriado && descricaoFeriado && (
            <div className="flex items-center gap-2 px-3 py-2 bg-purple-50 border border-purple-200 rounded-lg">
              <span className="text-purple-600 text-sm">🗓</span>
              <span className="text-sm text-purple-700 font-medium">Feriado detectado:</span>
              <span className="text-sm text-purple-600">{descricaoFeriado}</span>
            </div>
          )}

          {erro && (
            <div className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-4 py-3">
              {erro}
            </div>
          )}

          <div className="flex gap-3 pt-2">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 border border-slate-200 text-slate-700 font-medium py-2.5 rounded-lg text-sm hover:bg-slate-50 transition-colors"
            >
              Cancelar
            </button>
            <button
              type="submit"
              disabled={loading}
              className="flex-1 text-white font-medium py-2.5 rounded-lg text-sm transition-opacity disabled:opacity-60"
              style={{ backgroundColor: '#E8001C' }}
            >
              {loading ? 'Salvando...' : 'Salvar'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
