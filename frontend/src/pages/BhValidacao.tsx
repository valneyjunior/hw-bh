import { useEffect, useState, useCallback } from 'react'
import {
  getAdminLancamentos, aprovarLancamento, contestarLancamento,
  getAdminFolgas, aprovarFolga, recusarFolga,
} from '../services/api'
import type { Lancamento, Folga } from '../types/bh'
import Paginacao from '../components/Paginacao'

function fmtData(d: string): string {
  const [y, mo, day] = d.split('-')
  return `${day}/${mo}/${y}`
}

function fmtMin(min: number): string {
  const h = Math.floor(Math.abs(min) / 60)
  const m = Math.abs(min) % 60
  return `${h}h${m > 0 ? ` ${m}m` : ''}`
}

function horas48(criado_em: string): boolean {
  return (new Date().getTime() - new Date(criado_em).getTime()) > 48 * 60 * 60 * 1000
}

const TIPO_FOLGA: Record<string, string> = {
  dia_inteiro:   'Dia inteiro',
  meio_manha:    'Meio período — Manhã',
  meio_tarde:    'Meio período — Tarde',
  personalizado: 'Personalizado',
}

// ── Aba Lançamentos ───────────────────────────────────────────────────────────

function ehAdmin(): boolean {
  try {
    const u = JSON.parse(localStorage.getItem('bh_user') ?? '{}') as { tipo?: string; perfis?: string[] }
    const perfis = u.perfis?.length ? u.perfis : [u.tipo ?? '']
    return perfis.includes('admin')
  } catch { return false }
}

function AbaLancamentos() {
  const isAdmin = ehAdmin()
  const [lancamentos, setLancamentos] = useState<Lancamento[]>([])
  const [loading, setLoading] = useState(true)
  const [notaMap, setNotaMap] = useState<Record<number, string>>({})
  const [contestandoId, setContestandoId] = useState<number | null>(null)
  const [processando, setProcessando] = useState<number | null>(null)
  const [pagina, setPagina] = useState(1)
  const [porPagina, setPorPagina] = useState(10)
  const [total, setTotal] = useState(0)

  const carregar = useCallback(async () => {
    setLoading(true)
    try {
      const res = await getAdminLancamentos({
        status: 'pendente', page: String(pagina), per_page: String(porPagina),
      })
      const data = res.data as { items: Lancamento[]; total: number }
      setLancamentos(data.items)
      setTotal(data.total)
    } finally {
      setLoading(false)
    }
  }, [pagina, porPagina])

  useEffect(() => { carregar() }, [carregar])

  const totalPaginas = Math.max(1, Math.ceil(total / porPagina))

  // Após remover um item, se a página esvaziar, recua uma página; senão recarrega.
  function recarregarAposAcao() {
    if (lancamentos.length === 1 && pagina > 1) setPagina((p) => p - 1)
    else carregar()
  }

  const handleAprovar = async (id: number) => {
    setProcessando(id)
    try { await aprovarLancamento(id); recarregarAposAcao() } finally { setProcessando(null) }
  }

  const handleContestar = async (id: number) => {
    const nota = notaMap[id]?.trim()
    if (!nota) { alert('Descreva a solicitação de correção antes de confirmar.'); return }
    setProcessando(id)
    try { await contestarLancamento(id, nota); recarregarAposAcao() }
    finally { setProcessando(null); setContestandoId(null) }
  }

  return (
    <div className="space-y-4">
      {loading ? (
        <div className="text-center text-slate-400 py-20 text-sm">Carregando...</div>
      ) : lancamentos.length === 0 ? (
        <div className="bg-white rounded-xl border border-gray-200 p-12 text-center text-slate-400 text-sm">
          Nenhum lançamento pendente.
        </div>
      ) : (
        <>
        {lancamentos.map((l) => {
          const atrasado = horas48(l.criado_em)
          return (
            <div key={l.id} className={`rounded-xl border p-5 ${atrasado ? 'bg-amber-50 border-amber-200' : 'bg-white border-gray-200'}`}>
              <div className="flex items-start justify-between gap-4">
                <div className="flex-1 space-y-1">
                  <div className="flex items-center gap-3 flex-wrap">
                    <span className="font-semibold text-slate-900">{l.usuario_nome ?? `Usuário #${l.usuario_id}`}</span>
                    {atrasado && (
                      <span className="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-medium">+48h</span>
                    )}
                    {l.feriado && (
                      <span className="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full font-medium">Feriado</span>
                    )}
                    {l.requer_aprovacao_diretor && (
                      <span className="text-xs bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full font-medium">Coordenador</span>
                    )}
                  </div>
                  <div className="text-sm text-slate-600">
                    <span className="font-medium">{fmtData(l.data_acionamento)}</span>
                    {' — '}{l.hora_inicio.slice(0, 5)} às {l.hora_fim.slice(0, 5)}
                    {l.total_minutos != null && (
                      <span className="ml-2 text-slate-400">({fmtMin(l.total_minutos)})</span>
                    )}
                  </div>
                  <div className="text-sm text-slate-500">
                    <span className="font-medium text-slate-700">{l.chamado}</span>{' — '}{l.motivo}
                  </div>
                  {l.descricao_feriado && (
                    <div className="text-xs text-purple-600">Feriado: {l.descricao_feriado}</div>
                  )}
                </div>
                <div className="flex items-center gap-2 flex-shrink-0">
                  {l.requer_aprovacao_diretor && !isAdmin ? (
                    <span className="px-3 py-2 text-xs font-medium rounded-lg bg-indigo-50 text-indigo-600 border border-indigo-200 whitespace-nowrap">
                      ⏳ Aguardando diretor
                    </span>
                  ) : (
                    <>
                      <button onClick={() => handleAprovar(l.id)} disabled={processando === l.id}
                        className="px-4 py-2 text-sm font-medium text-white rounded-lg disabled:opacity-50"
                        style={{ backgroundColor: '#10B981' }}>
                        {processando === l.id ? '...' : 'Aprovar'}
                      </button>
                      <button onClick={() => setContestandoId(contestandoId === l.id ? null : l.id)}
                        disabled={processando === l.id}
                        className="px-4 py-2 text-sm font-medium rounded-lg border border-orange-200 text-orange-600 hover:bg-orange-50 disabled:opacity-50">
                        Contestar
                      </button>
                    </>
                  )}
                </div>
              </div>
              {contestandoId === l.id && (
                <div className="mt-4 pt-4 border-t border-slate-100 space-y-3">
                  <p className="text-sm font-medium text-slate-700">Solicitar correção</p>
                  <textarea rows={2}
                    value={notaMap[l.id] ?? ''}
                    onChange={(e) => setNotaMap((prev) => ({ ...prev, [l.id]: e.target.value }))}
                    placeholder="Descreva o que precisa ser corrigido ou esclarecido..."
                    className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400 resize-none"
                  />
                  <div className="flex gap-2">
                    <button onClick={() => handleContestar(l.id)} disabled={processando === l.id}
                      className="px-4 py-2 text-sm font-medium text-white rounded-lg disabled:opacity-50"
                      style={{ backgroundColor: '#F97316' }}>
                      {processando === l.id ? '...' : 'Enviar solicitação'}
                    </button>
                    <button onClick={() => setContestandoId(null)}
                      className="px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 rounded-lg border border-slate-200">
                      Cancelar
                    </button>
                  </div>
                </div>
              )}
            </div>
          )
        })}
        <div className="bg-white rounded-xl border border-gray-200">
          <Paginacao
            pagina={pagina}
            totalPaginas={totalPaginas}
            porPagina={porPagina}
            total={total}
            onPagina={setPagina}
            onPorPagina={(n) => { setPorPagina(n); setPagina(1) }}
          />
        </div>
        </>
      )}
    </div>
  )
}

// ── Aba Folgas ────────────────────────────────────────────────────────────────

function AbaFolgas() {
  const [folgas, setFolgas] = useState<Folga[]>([])
  const [loading, setLoading] = useState(true)
  const [notaMap, setNotaMap] = useState<Record<number, string>>({})
  const [recusandoId, setRecusandoId] = useState<number | null>(null)
  const [processando, setProcessando] = useState<number | null>(null)
  const [pagina, setPagina] = useState(1)
  const [porPagina, setPorPagina] = useState(10)
  const [total, setTotal] = useState(0)

  const carregar = useCallback(async () => {
    setLoading(true)
    try {
      const res = await getAdminFolgas({
        status: 'pendente', page: String(pagina), per_page: String(porPagina),
      })
      const data = res.data as { items: Folga[]; total: number }
      setFolgas(data.items)
      setTotal(data.total)
    } finally {
      setLoading(false)
    }
  }, [pagina, porPagina])

  useEffect(() => { carregar() }, [carregar])

  const totalPaginas = Math.max(1, Math.ceil(total / porPagina))

  function recarregarAposAcao() {
    if (folgas.length === 1 && pagina > 1) setPagina((p) => p - 1)
    else carregar()
  }

  const handleAprovar = async (id: number) => {
    setProcessando(id)
    try { await aprovarFolga(id); recarregarAposAcao() } finally { setProcessando(null) }
  }

  const handleRecusar = async (id: number) => {
    const nota = notaMap[id]?.trim()
    if (!nota) { alert('Informe o motivo da recusa.'); return }
    setProcessando(id)
    try { await recusarFolga(id, nota); recarregarAposAcao() }
    finally { setProcessando(null); setRecusandoId(null) }
  }

  return (
    <div className="space-y-4">
      {loading ? (
        <div className="text-center text-slate-400 py-20 text-sm">Carregando...</div>
      ) : folgas.length === 0 ? (
        <div className="bg-white rounded-xl border border-gray-200 p-12 text-center text-slate-400 text-sm">
          Nenhuma solicitação de folga pendente.
        </div>
      ) : (
        <>
        {folgas.map((f) => (
          <div key={f.id} className="bg-white rounded-xl border border-gray-200 p-5">
            <div className="flex items-start justify-between gap-4">
              <div className="flex-1 space-y-1.5">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="font-semibold text-slate-900">{f.usuario_nome ?? `Usuário #${f.usuario_id}`}</span>
                  <span className="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full font-medium">
                    {TIPO_FOLGA[f.tipo] ?? f.tipo}
                  </span>
                  <span className="text-xs text-slate-500 font-medium">{fmtData(f.data_folga)}</span>
                  <span className="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-medium">
                    -{fmtMin(f.minutos_deduzidos)}
                  </span>
                </div>
                <p className="text-sm text-slate-600">{f.motivo}</p>
                {f.hora_inicio && f.hora_fim && (
                  <p className="text-xs text-slate-400">
                    {f.hora_inicio.slice(0, 5)} às {f.hora_fim.slice(0, 5)}
                  </p>
                )}
              </div>
              <div className="flex items-center gap-2 flex-shrink-0">
                <button onClick={() => handleAprovar(f.id)} disabled={processando === f.id}
                  className="px-4 py-2 text-sm font-medium text-white rounded-lg disabled:opacity-50"
                  style={{ backgroundColor: '#10B981' }}>
                  {processando === f.id ? '...' : 'Aprovar'}
                </button>
                <button onClick={() => setRecusandoId(recusandoId === f.id ? null : f.id)}
                  disabled={processando === f.id}
                  className="px-4 py-2 text-sm font-medium rounded-lg border border-red-200 text-red-600 hover:bg-red-50 disabled:opacity-50">
                  Recusar
                </button>
              </div>
            </div>
            {recusandoId === f.id && (
              <div className="mt-4 pt-4 border-t border-slate-100 space-y-3">
                <textarea rows={2}
                  value={notaMap[f.id] ?? ''}
                  onChange={(e) => setNotaMap((prev) => ({ ...prev, [f.id]: e.target.value }))}
                  placeholder="Motivo da recusa..."
                  className="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500 resize-none"
                />
                <div className="flex gap-2">
                  <button onClick={() => handleRecusar(f.id)} disabled={processando === f.id}
                    className="px-4 py-2 text-sm font-medium text-white rounded-lg disabled:opacity-50"
                    style={{ backgroundColor: '#E8001C' }}>
                    Confirmar Recusa
                  </button>
                  <button onClick={() => setRecusandoId(null)}
                    className="px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 rounded-lg border border-slate-200">
                    Cancelar
                  </button>
                </div>
              </div>
            )}
          </div>
        ))}
        <div className="bg-white rounded-xl border border-gray-200">
          <Paginacao
            pagina={pagina}
            totalPaginas={totalPaginas}
            porPagina={porPagina}
            total={total}
            onPagina={setPagina}
            onPorPagina={(n) => { setPorPagina(n); setPagina(1) }}
          />
        </div>
        </>
      )}
    </div>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

export default function BhValidacao() {
  const [aba, setAba] = useState<'lancamentos' | 'folgas'>('lancamentos')

  return (
    <div className="p-6 space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-slate-900">Validação</h1>
        <p className="text-slate-500 text-sm mt-0.5">Aprove ou recuse lançamentos e solicitações de folga</p>
      </div>

      {/* Abas */}
      <div className="flex border-b border-gray-200">
        {(
          [
            { key: 'lancamentos', label: 'Lançamentos' },
            { key: 'folgas', label: 'Folgas' },
          ] as const
        ).map((a) => (
          <button key={a.key} onClick={() => setAba(a.key)}
            className={`text-sm py-2.5 px-5 border-b-2 font-medium transition-colors ${
              aba === a.key ? 'border-red-600 text-red-600' : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}>
            {a.label}
          </button>
        ))}
      </div>

      {aba === 'lancamentos' ? <AbaLancamentos /> : <AbaFolgas />}
    </div>
  )
}
