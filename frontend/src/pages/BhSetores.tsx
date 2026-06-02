import { useEffect, useState } from 'react'
import { getAdminSetores, criarSetor, editarSetor, deletarSetor } from '../services/api'
import type { Setor } from '../types/bh'

function IconPlus() {
  return (
    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
      <line x1="12" y1="5" x2="12" y2="19" />
      <line x1="5" y1="12" x2="19" y2="12" />
    </svg>
  )
}

function IconCheck() {
  return (
    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
      <polyline points="20 6 9 17 4 12" />
    </svg>
  )
}

function IconX() {
  return (
    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
      <line x1="18" y1="6" x2="6" y2="18" />
      <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
  )
}

function IconPencil() {
  return (
    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
      <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
      <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
    </svg>
  )
}

function IconTrash() {
  return (
    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
      <polyline points="3 6 5 6 21 6" />
      <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
      <path d="M10 11v6M14 11v6" />
      <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
    </svg>
  )
}

export default function BhSetores() {
  const [setores, setSetores] = useState<Setor[]>([])
  const [carregando, setCarregando] = useState(true)
  const [novoNome, setNovoNome] = useState('')
  const [adicionando, setAdicionando] = useState(false)
  const [salvandoNovo, setSalvandoNovo] = useState(false)
  const [editandoId, setEditandoId] = useState<number | null>(null)
  const [editandoNome, setEditandoNome] = useState('')
  const [salvandoEdicao, setSalvandoEdicao] = useState(false)
  const [excluindoId, setExcluindoId] = useState<number | null>(null)
  const [erro, setErro] = useState('')

  async function carregar() {
    setCarregando(true)
    try {
      const res = await getAdminSetores()
      setSetores(res.data as Setor[])
    } finally {
      setCarregando(false)
    }
  }

  useEffect(() => { carregar() }, [])

  async function handleCriar() {
    if (!novoNome.trim()) return
    setErro('')
    setSalvandoNovo(true)
    try {
      await criarSetor(novoNome.trim())
      setNovoNome('')
      setAdicionando(false)
      await carregar()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { detail?: string } } })?.response?.data?.detail
      setErro(msg ?? 'Erro ao criar setor')
    } finally {
      setSalvandoNovo(false)
    }
  }

  function iniciarEdicao(s: Setor) {
    setEditandoId(s.id)
    setEditandoNome(s.nome)
    setErro('')
  }

  function cancelarEdicao() {
    setEditandoId(null)
    setEditandoNome('')
  }

  async function handleSalvarEdicao(id: number) {
    if (!editandoNome.trim()) return
    setErro('')
    setSalvandoEdicao(true)
    try {
      await editarSetor(id, editandoNome.trim())
      setEditandoId(null)
      await carregar()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { detail?: string } } })?.response?.data?.detail
      setErro(msg ?? 'Erro ao renomear setor')
    } finally {
      setSalvandoEdicao(false)
    }
  }

  async function handleExcluir(s: Setor) {
    if (!confirm(`Excluir o setor "${s.nome}"? Esta ação não pode ser desfeita.`)) return
    setErro('')
    setExcluindoId(s.id)
    try {
      await deletarSetor(s.id)
      await carregar()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { detail?: string } } })?.response?.data?.detail
      setErro(msg ?? 'Erro ao excluir setor')
    } finally {
      setExcluindoId(null)
    }
  }

  return (
    <div className="p-6 max-w-2xl mx-auto">
      {/* Cabeçalho */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Setores</h1>
          <p className="text-sm text-gray-500 mt-0.5">Grupos organizacionais dos colaboradores</p>
        </div>
        {!adicionando && (
          <button
            onClick={() => { setAdicionando(true); setErro('') }}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-white text-sm font-medium"
            style={{ backgroundColor: '#E8001C' }}
          >
            <IconPlus />
            Novo Setor
          </button>
        )}
      </div>

      {erro && (
        <p className="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2 mb-4">{erro}</p>
      )}

      {/* Formulário de novo setor */}
      {adicionando && (
        <div className="bg-white rounded-xl border border-gray-200 px-4 py-3 mb-4 flex items-center gap-3">
          <input
            autoFocus
            type="text"
            placeholder="Nome do setor"
            className="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500"
            value={novoNome}
            onChange={(e) => setNovoNome(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') handleCriar() }}
          />
          <button
            onClick={handleCriar}
            disabled={salvandoNovo || !novoNome.trim()}
            className="p-2 rounded-lg text-white disabled:opacity-50"
            style={{ backgroundColor: '#E8001C' }}
            title="Confirmar"
          >
            <IconCheck />
          </button>
          <button
            onClick={() => { setAdicionando(false); setNovoNome(''); setErro('') }}
            className="p-2 rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-50"
            title="Cancelar"
          >
            <IconX />
          </button>
        </div>
      )}

      {/* Tabela */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        {carregando ? (
          <div className="py-12 text-center text-sm text-gray-400">Carregando setores...</div>
        ) : setores.length === 0 ? (
          <div className="py-12 text-center text-sm text-gray-400">Nenhum setor cadastrado.</div>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50 border-b border-gray-200">
                <th className="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Nome do Setor</th>
                <th className="text-center px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Colaboradores</th>
                <th className="px-4 py-3 w-28" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {setores.map((s) => {
                const temVinculo = (s.total_usuarios ?? 0) > 0
                return (
                  <tr key={s.id} className="hover:bg-gray-50 transition-colors">
                    {/* Nome */}
                    <td className="px-5 py-3.5">
                      {editandoId === s.id ? (
                        <div className="flex items-center gap-2">
                          <input
                            autoFocus
                            type="text"
                            className="flex-1 border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-red-500"
                            value={editandoNome}
                            onChange={(e) => setEditandoNome(e.target.value)}
                            onKeyDown={(e) => {
                              if (e.key === 'Enter') handleSalvarEdicao(s.id)
                              if (e.key === 'Escape') cancelarEdicao()
                            }}
                          />
                          <button
                            onClick={() => handleSalvarEdicao(s.id)}
                            disabled={salvandoEdicao || !editandoNome.trim()}
                            className="p-1.5 rounded-lg text-white disabled:opacity-50"
                            style={{ backgroundColor: '#E8001C' }}
                            title="Salvar"
                          >
                            <IconCheck />
                          </button>
                          <button
                            onClick={cancelarEdicao}
                            className="p-1.5 rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-100"
                            title="Cancelar"
                          >
                            <IconX />
                          </button>
                        </div>
                      ) : (
                        <div className="flex items-center gap-2.5">
                          <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ backgroundColor: '#E8001C' }} />
                          <span className="font-medium text-gray-800">{s.nome}</span>
                        </div>
                      )}
                    </td>

                    {/* Contagem */}
                    <td className="px-4 py-3.5 text-center">
                      {editandoId !== s.id && (
                        <span className={`inline-flex items-center justify-center min-w-[1.75rem] px-2 py-0.5 rounded-full text-xs font-semibold ${
                          temVinculo ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-400'
                        }`}>
                          {s.total_usuarios ?? 0}
                        </span>
                      )}
                    </td>

                    {/* Ações */}
                    <td className="px-4 py-3.5">
                      {editandoId !== s.id && (
                        <div className="flex items-center gap-1 justify-end">
                          <button
                            onClick={() => iniciarEdicao(s)}
                            className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                            title="Renomear"
                          >
                            <IconPencil />
                          </button>
                          <button
                            onClick={() => !temVinculo && handleExcluir(s)}
                            disabled={temVinculo || excluindoId === s.id}
                            className={`p-1.5 rounded-lg transition-colors ${
                              temVinculo
                                ? 'text-gray-200 cursor-not-allowed'
                                : 'text-gray-400 hover:text-red-600 hover:bg-red-50'
                            }`}
                            title={temVinculo ? `Possui ${s.total_usuarios} colaborador(es) vinculado(s)` : 'Excluir setor'}
                          >
                            <IconTrash />
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>

      {/* Legenda */}
      {setores.length > 0 && !carregando && (
        <p className="text-xs text-gray-400 mt-3 text-right">
          Setores com colaboradores vinculados não podem ser excluídos.
        </p>
      )}
    </div>
  )
}
