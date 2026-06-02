import { useEffect, useState, useCallback } from 'react'
import { HoraInput } from '../components/HoraInput'
import {
  getAdminUsuarios, criarUsuario, editarUsuario, getAdminSetores,
  salvarConfigUsuario, resetarSenhaUsuario, desativarUsuario,
  arquivarUsuario, restaurarUsuario,
} from '../services/api'
import type { UsuarioComConfig, Setor } from '../types/bh'
import Paginacao, { usePaginacao } from '../components/Paginacao'

// ── Helpers ───────────────────────────────────────────────────────────────────

const TIPO_LABEL: Record<string, string> = {
  admin: 'Admin',
  coordenador: 'Coordenador',
  analista: 'Analista',
  atendimento: 'Atendimento',
}

const TIPO_COR: Record<string, string> = {
  admin: 'bg-red-100 text-red-700',
  coordenador: 'bg-blue-100 text-blue-700',
  analista: 'bg-green-100 text-green-700',
  atendimento: 'bg-cyan-100 text-cyan-700',
}

const AVATAR_CORES = [
  '#7C3AED', '#DB2777', '#2563EB', '#059669', '#D97706', '#DC2626',
]

function avatarCor(nome: string): string {
  let hash = 0
  for (let i = 0; i < nome.length; i++) hash = nome.charCodeAt(i) + ((hash << 5) - hash)
  return AVATAR_CORES[Math.abs(hash) % AVATAR_CORES.length]
}

// Paleta determinística por setor — mesma usada na Escala do Setor (consistência visual)
const CORES_SETOR = ['#3B82F6', '#10B981', '#8B5CF6', '#F59E0B', '#EC4899', '#06B6D4', '#EF4444', '#F97316']

function corSetor(nome: string): string {
  if (!nome) return '#94a3b8'
  let hash = 0
  for (let i = 0; i < nome.length; i++) hash = nome.charCodeAt(i) + ((hash << 5) - hash)
  return CORES_SETOR[Math.abs(hash) % CORES_SETOR.length]
}

function iniciais(nome: string): string {
  const partes = nome.trim().split(' ')
  if (partes.length === 1) return partes[0][0].toUpperCase()
  return (partes[0][0] + partes[partes.length - 1][0]).toUpperCase()
}

// ── Modal: Editar / Criar ─────────────────────────────────────────────────────

interface ModalEditarProps {
  usuario: UsuarioComConfig | null
  setores: Setor[]
  onClose: () => void
  onSalvo: () => void
}

const TODOS_PERFIS = ['analista', 'coordenador', 'atendimento', 'admin'] as const

function tipoFromPerfis(perfis: string[]): string {
  if (perfis.includes('admin')) return 'admin'
  if (perfis.includes('coordenador')) return 'coordenador'
  if (perfis.includes('atendimento')) return 'atendimento'
  return 'analista'
}

function parseMoeda(s: string): number {
  if (!s) return 0
  // Suporta formato BR (1.234,56) e ISO (1234.56)
  if (s.includes(',')) return Number(s.replace(/\./g, '').replace(',', '.')) || 0
  return Number(s) || 0
}

function gerarSenha(): string {
  const maiusc = 'ABCDEFGHJKMNPQRSTUVWXYZ'
  const minusc = 'abcdefghjkmnpqrstuvwxyz'
  const nums   = '23456789'
  const spec   = '@#$!&'
  const todos  = maiusc + minusc + nums + spec
  const arr    = [
    maiusc[Math.floor(Math.random() * maiusc.length)],
    nums[Math.floor(Math.random() * nums.length)],
    spec[Math.floor(Math.random() * spec.length)],
    ...Array.from({ length: 7 }, () => todos[Math.floor(Math.random() * todos.length)]),
  ]
  return arr.sort(() => Math.random() - 0.5).join('')
}

const INPUT_CLS = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500'

function ModalEditar({ usuario, setores, onClose, onSalvo }: ModalEditarProps) {
  const editando = usuario !== null
  const [aba, setAba] = useState<'dados' | 'config'>('dados')
  const [nome, setNome] = useState(usuario?.nome ?? '')
  const [email, setEmail] = useState(usuario?.email ?? '')
  const [perfis, setPerfis] = useState<string[]>(
    usuario?.perfis?.length ? usuario.perfis : [usuario?.tipo ?? 'analista']
  )
  const [grupoId, setGrupoId] = useState<number | ''>(usuario?.grupo_id ?? '')
  const [telefone, setTelefone] = useState(usuario?.telefone ?? '')
  const [setoresCoord, setSetoresCoord] = useState<number[]>(usuario?.setores_coordenados ?? [])
  const [ativo, setAtivo] = useState(usuario?.ativo ?? true)
  const [senha, setSenha] = useState('')
  const [mostrarSenha, setMostrarSenha] = useState(true)
  const [mustChange, setMustChange] = useState(true)
  const [enviarEmail, setEnviarEmail] = useState(false)
  const [salario, setSalario] = useState(
    usuario?.config?.salario_bruto != null ? String(usuario.config.salario_bruto) : ''
  )
  const [adicionalAtrativo, setAdicionalAtrativo] = useState(
    usuario?.config?.adicional_atrativo != null ? String(usuario.config.adicional_atrativo) : ''
  )
  const [workStart, setWorkStart] = useState(usuario?.config?.work_start?.slice(0, 5) ?? '08:00')
  const [workEnd, setWorkEnd]     = useState(usuario?.config?.work_end?.slice(0, 5) ?? '18:00')
  const [lunchStart, setLunchStart] = useState(usuario?.config?.lunch_start?.slice(0, 5) ?? '12:00')
  const [lunchMins, setLunchMins]   = useState(usuario?.config?.lunch_minutes ?? 60)
  const [salvando, setSalvando] = useState(false)
  const [erro, setErro] = useState('')

  function togglePerfil(p: string) {
    setPerfis(prev => {
      if (prev.includes(p)) {
        if (prev.length === 1) return prev
        return prev.filter(x => x !== p)
      }
      return [...prev, p]
    })
  }

  function toggleSetorCoord(id: number) {
    setSetoresCoord(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id])
  }

  const ehCoordenador = perfis.includes('coordenador')

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setErro('')
    setSalvando(true)
    const tipo = tipoFromPerfis(perfis)
    const setoresCoordFinal = ehCoordenador ? setoresCoord : []
    try {
      if (editando) {
        await editarUsuario(usuario.id, {
          nome, email, tipo, perfis,
          grupo_id: grupoId === '' ? null : Number(grupoId),
          ativo,
          telefone: telefone.trim() || null,
          setores_coordenados: setoresCoordFinal,
        })
        await salvarConfigUsuario(usuario.id, {
          salario_bruto: parseMoeda(salario),
          work_start: workStart + ':00',
          work_end: workEnd + ':00',
          lunch_start: lunchStart + ':00',
          lunch_minutes: lunchMins,
          adicional_atrativo: parseMoeda(adicionalAtrativo),
        })
      } else {
        if (!senha) { setErro('Senha obrigatória'); setSalvando(false); return }
        await criarUsuario({
          nome, email, senha, tipo, perfis,
          grupo_id: grupoId === '' ? undefined : Number(grupoId),
          must_change_password: mustChange,
          telefone: telefone.trim() || undefined,
          setores_coordenados: setoresCoordFinal,
          salario_bruto: salario ? parseMoeda(salario) : undefined,
          work_start: workStart + ':00',
          work_end: workEnd + ':00',
          lunch_start: lunchStart + ':00',
          lunch_minutes: lunchMins,
          adicional_atrativo: parseMoeda(adicionalAtrativo),
          enviar_email: enviarEmail,
        })
      }
      onSalvo()
    } catch (err: unknown) {
      const resp = (err as { response?: { data?: unknown } })?.response
      const detail = (resp?.data as { detail?: unknown })?.detail
      const msg = typeof detail === 'string' ? detail : Array.isArray(detail) ? JSON.stringify(detail) : null
      setErro(msg ?? `Erro ao salvar (HTTP ${(err as { response?: { status?: number } })?.response?.status ?? 'sem resposta'})`)
    } finally {
      setSalvando(false)
    }
  }

  const configFields = (
    <>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Salário bruto (R$)</label>
          <input type="text" inputMode="decimal" placeholder="0,00" className={INPUT_CLS}
            value={salario} onChange={(e) => setSalario(e.target.value)} />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Adicional atrativo (R$)</label>
          <input type="text" inputMode="decimal" placeholder="0,00" className={INPUT_CLS}
            value={adicionalAtrativo} onChange={(e) => setAdicionalAtrativo(e.target.value)} />
        </div>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Início da jornada</label>
          <HoraInput className={INPUT_CLS} value={workStart} onChange={setWorkStart} />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Fim da jornada</label>
          <HoraInput className={INPUT_CLS} value={workEnd} onChange={setWorkEnd} />
        </div>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Almoço — início</label>
          <HoraInput className={INPUT_CLS} value={lunchStart} onChange={setLunchStart} />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Duração do almoço</label>
          <select className={INPUT_CLS} value={lunchMins} onChange={(e) => setLunchMins(Number(e.target.value))}>
            <option value={30}>30 min</option>
            <option value={60}>60 min (1h)</option>
            <option value={90}>90 min (1h30)</option>
            <option value={120}>120 min (2h)</option>
          </select>
        </div>
      </div>
    </>
  )

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 py-6 overflow-y-auto">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 my-auto">
        <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 className="text-base font-semibold text-gray-900">
            {editando ? 'Editar usuário' : 'Novo usuário'}
          </h2>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl leading-none">×</button>
        </div>

        {editando && (
          <div className="flex border-b border-gray-200 px-6">
            {(['dados', 'config'] as const).map((a) => (
              <button key={a} type="button" onClick={() => setAba(a)}
                className={`text-sm py-3 mr-5 border-b-2 font-medium capitalize transition-colors ${aba === a ? 'border-red-600 text-red-600' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>
                {a === 'dados' ? 'Dados' : 'Configuração CLT'}
              </button>
            ))}
          </div>
        )}

        <form onSubmit={handleSubmit} className="px-6 py-5 space-y-4">
          {erro && <p className="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{erro}</p>}

          {/* ── Aba Dados (editando) ou todos os campos (criando) ── */}
          {(!editando || aba === 'dados') && (
            <>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Nome</label>
                <input className={INPUT_CLS} value={nome} onChange={(e) => setNome(e.target.value)} required />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
                <input type="email" className={INPUT_CLS} value={email} onChange={(e) => setEmail(e.target.value)} required />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Perfis de acesso</label>
                <div className="flex gap-3">
                  {TODOS_PERFIS.map((p) => (
                    <label key={p} className={`flex items-center gap-2 px-3 py-2 rounded-lg border cursor-pointer text-sm transition-colors select-none ${
                      perfis.includes(p) ? 'border-red-400 bg-red-50 text-red-700 font-medium' : 'border-gray-200 text-gray-500 hover:border-gray-300'
                    }`}>
                      <input type="checkbox" className="w-3.5 h-3.5 accent-red-600"
                        checked={perfis.includes(p)} onChange={() => togglePerfil(p)} />
                      {TIPO_LABEL[p]}
                    </label>
                  ))}
                </div>
                {perfis.length > 1 && (
                  <p className="text-xs text-gray-400 mt-1.5">
                    Tipo principal: <span className="font-medium text-gray-600">{TIPO_LABEL[tipoFromPerfis(perfis)]}</span>
                  </p>
                )}
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Setor de lotação</label>
                  <select className={INPUT_CLS} value={grupoId}
                    onChange={(e) => setGrupoId(e.target.value === '' ? '' : Number(e.target.value))}>
                    <option value="">— nenhum —</option>
                    {setores.map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    Telefone <span className="text-gray-400 font-normal">(opcional)</span>
                  </label>
                  <input className={INPUT_CLS} value={telefone} onChange={(e) => setTelefone(e.target.value)}
                    placeholder="(92) 99999-9999" inputMode="tel" />
                </div>
              </div>

              {/* Setores coordenados — só quando o perfil Coordenador está marcado */}
              {ehCoordenador && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1.5">
                    Setores que coordena
                    <span className="ml-1 text-gray-400 font-normal text-xs">(valida lançamentos/folgas destes setores)</span>
                  </label>
                  {setores.length === 0 ? (
                    <p className="text-xs text-gray-400">Nenhum setor cadastrado.</p>
                  ) : (
                    <div className="flex flex-wrap gap-2">
                      {setores.map((s) => (
                        <label key={s.id} className={`flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border cursor-pointer text-xs transition-colors select-none ${
                          setoresCoord.includes(s.id) ? 'border-blue-400 bg-blue-50 text-blue-700 font-medium' : 'border-gray-200 text-gray-500 hover:border-gray-300'
                        }`}>
                          <input type="checkbox" className="w-3.5 h-3.5 accent-blue-600"
                            checked={setoresCoord.includes(s.id)} onChange={() => toggleSetorCoord(s.id)} />
                          {s.nome}
                        </label>
                      ))}
                    </div>
                  )}
                  <p className="text-[11px] text-gray-400 mt-1.5">Se nenhum for marcado, usa o setor de lotação como padrão.</p>
                </div>
              )}

              {editando && (
                <div className="flex items-center gap-2">
                  <input type="checkbox" id="ativo" checked={ativo} onChange={(e) => setAtivo(e.target.checked)} className="w-4 h-4 accent-red-600" />
                  <label htmlFor="ativo" className="text-sm text-gray-700">Usuário ativo</label>
                </div>
              )}

              {/* Campos apenas na criação */}
              {!editando && (
                <>
                  {/* Divisor CLT */}
                  <div className="pt-1">
                    <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-3">Jornada & CLT</p>
                    {configFields}
                  </div>

                  {/* Senha */}
                  <div className="pt-1">
                    <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-3">Acesso</p>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">Senha inicial</label>
                      <div className="flex gap-2">
                        <div className="relative flex-1">
                          <input
                            type={mostrarSenha ? 'text' : 'password'}
                            className={INPUT_CLS + ' pr-10'}
                            value={senha}
                            onChange={(e) => setSenha(e.target.value)}
                            required
                          />
                          <button type="button" onClick={() => setMostrarSenha(!mostrarSenha)}
                            className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            {mostrarSenha
                              ? <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                              : <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            }
                          </button>
                        </div>
                        <button type="button"
                          onClick={() => { setSenha(gerarSenha()); setMostrarSenha(true) }}
                          className="px-3 py-2 text-xs rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 whitespace-nowrap font-medium">
                          Gerar
                        </button>
                      </div>
                    </div>
                    <div className="flex items-center gap-2 mt-3">
                      <input type="checkbox" id="mustChange" checked={mustChange}
                        onChange={(e) => setMustChange(e.target.checked)} className="w-4 h-4 accent-red-600" />
                      <label htmlFor="mustChange" className="text-sm text-gray-700">Exigir troca de senha no primeiro acesso</label>
                    </div>
                    <div className="flex items-center gap-2 mt-2">
                      <input type="checkbox" id="enviarEmail" checked={enviarEmail}
                        onChange={(e) => setEnviarEmail(e.target.checked)} className="w-4 h-4 accent-red-600" />
                      <label htmlFor="enviarEmail" className="text-sm text-gray-700">
                        Enviar acesso por e-mail
                        <span className="ml-1 text-gray-400 text-xs">(via Microsoft 365)</span>
                      </label>
                    </div>
                  </div>
                </>
              )}
            </>
          )}

          {/* ── Aba Config CLT (editando) ── */}
          {editando && aba === 'config' && configFields}

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose}
              className="px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">
              Cancelar
            </button>
            <button type="submit" disabled={salvando}
              className="px-4 py-2 text-sm rounded-lg text-white font-medium disabled:opacity-60"
              style={{ backgroundColor: '#E8001C' }}>
              {salvando ? 'Salvando...' : editando ? 'Salvar alterações' : 'Criar usuário'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

// ── Modal: Resetar senha ──────────────────────────────────────────────────────

interface ModalResetarProps {
  usuario: UsuarioComConfig
  onClose: () => void
  onSalvo: () => void
}

function ModalResetarSenha({ usuario, onClose, onSalvo }: ModalResetarProps) {
  const [nova, setNova] = useState('')
  const [confirmar, setConfirmar] = useState('')
  const [mostrar, setMostrar] = useState(false)
  const [enviarEmail, setEnviarEmail] = useState(false)
  const [salvando, setSalvando] = useState(false)
  const [erro, setErro] = useState('')

  function gerar() {
    const s = gerarSenha()
    setNova(s)
    setConfirmar(s)
    setMostrar(true)
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (nova.length < 6) { setErro('Senha deve ter ao menos 6 caracteres'); return }
    if (nova !== confirmar) { setErro('As senhas não coincidem'); return }
    setErro('')
    setSalvando(true)
    try {
      await resetarSenhaUsuario(usuario.id, nova, enviarEmail)
      onSalvo()
    } catch {
      setErro('Erro ao redefinir senha')
    } finally {
      setSalvando(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4">
        <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h2 className="text-base font-semibold text-gray-900">Resetar senha — {usuario.nome}</h2>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl leading-none">×</button>
        </div>
        <form onSubmit={handleSubmit} className="px-6 py-5 space-y-4">
          {erro && <p className="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{erro}</p>}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Nova senha</label>
            <div className="flex gap-2">
              <div className="relative flex-1">
                <input
                  type={mostrar ? 'text' : 'password'}
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 pr-10 text-sm focus:outline-none focus:ring-2 focus:ring-red-500"
                  value={nova} onChange={(e) => setNova(e.target.value)} required autoFocus
                />
                <button type="button" onClick={() => setMostrar(!mostrar)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                  {mostrar
                    ? <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    : <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  }
                </button>
              </div>
              <button type="button" onClick={gerar}
                className="px-3 py-2 text-xs rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 whitespace-nowrap font-medium">
                Gerar
              </button>
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Confirmar senha</label>
            <input type={mostrar ? 'text' : 'password'} className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500"
              value={confirmar} onChange={(e) => setConfirmar(e.target.value)} required />
          </div>
          <div className="flex items-center gap-2">
            <input type="checkbox" id="resetEnviarEmail" checked={enviarEmail}
              onChange={(e) => setEnviarEmail(e.target.checked)} className="w-4 h-4 accent-red-600" />
            <label htmlFor="resetEnviarEmail" className="text-sm text-gray-700">
              Enviar nova senha por e-mail
              <span className="ml-1 text-gray-400 text-xs">({usuario.email})</span>
            </label>
          </div>
          <p className="text-xs text-gray-400">O usuário será obrigado a trocar a senha no próximo acesso.</p>
          <div className="flex justify-end gap-2 pt-1">
            <button type="button" onClick={onClose}
              className="px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">
              Cancelar
            </button>
            <button type="submit" disabled={salvando}
              className="px-4 py-2 text-sm rounded-lg text-white font-medium disabled:opacity-60"
              style={{ backgroundColor: '#E8001C' }}>
              {salvando ? 'Salvando...' : 'Redefinir senha'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

type AbaFiltro = 'ativos' | 'inativos' | 'arquivados'
type Modal = { tipo: 'editar'; usuario: UsuarioComConfig | null } | { tipo: 'resetar'; usuario: UsuarioComConfig } | null

export default function BhUsuarios() {
  const [aba, setAba] = useState<AbaFiltro>('ativos')
  const [usuarios, setUsuarios] = useState<UsuarioComConfig[]>([])
  const [setores, setSetores] = useState<Setor[]>([])
  const [carregando, setCarregando] = useState(true)
  const [modal, setModal] = useState<Modal>(null)

  // ID do usuário logado (para não mostrar Desativar/Arquivar em si mesmo)
  const euId = (() => {
    try { return (JSON.parse(localStorage.getItem('bh_user') ?? '') as { id: number }).id } catch { return -1 }
  })()

  const carregar = useCallback(async () => {
    setCarregando(true)
    try {
      const [resU, resS] = await Promise.allSettled([
        getAdminUsuarios({ filtro: aba } as unknown as Record<string, string>),
        getAdminSetores(),
      ])
      if (resU.status === 'fulfilled') setUsuarios(resU.value.data as UsuarioComConfig[])
      if (resS.status === 'fulfilled') setSetores(resS.value.data as Setor[])
    } finally {
      setCarregando(false)
    }
  }, [aba])

  useEffect(() => { carregar() }, [carregar])

  const [filtroSetor, setFiltroSetor] = useState<string | null>(null)

  // Setores presentes na lista atual (para as pills de filtro)
  const setoresPresentes = [...new Set(usuarios.map((u) => u.grupo_nome).filter(Boolean) as string[])].sort()

  const usuariosFiltrados = filtroSetor
    ? usuarios.filter((u) => u.grupo_nome === filtroSetor)
    : usuarios

  const pag = usePaginacao(usuariosFiltrados, 20)

  async function handleDesativar(u: UsuarioComConfig) {
    const acao = u.ativo ? 'desativar' : 'reativar'
    if (!confirm(`${acao.charAt(0).toUpperCase() + acao.slice(1)} "${u.nome}"?`)) return
    await desativarUsuario(u.id)
    carregar()
  }

  async function handleArquivar(u: UsuarioComConfig) {
    if (!confirm(`Arquivar "${u.nome}" como ex-colaborador? O acesso será revogado.`)) return
    await arquivarUsuario(u.id)
    carregar()
  }

  async function handleRestaurar(u: UsuarioComConfig) {
    if (!confirm(`Restaurar "${u.nome}" para colaborador ativo?`)) return
    await restaurarUsuario(u.id)
    carregar()
  }

  const ABAS: { key: AbaFiltro; label: string }[] = [
    { key: 'ativos', label: 'Ativos' },
    { key: 'inativos', label: 'Inativos' },
    { key: 'arquivados', label: 'Ex-Colaboradores' },
  ]

  return (
    <div className="p-6 max-w-5xl mx-auto">
      {/* Cabeçalho */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Usuários</h1>
          <p className="text-sm text-gray-500 mt-0.5">Gestão de colaboradores e perfis de acesso</p>
        </div>
        <button
          onClick={() => setModal({ tipo: 'editar', usuario: null })}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-white text-sm font-medium"
          style={{ backgroundColor: '#E8001C' }}
        >
          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" />
          </svg>
          Novo Usuário
        </button>
      </div>

      {/* Abas */}
      <div className="flex border-b border-gray-200 mb-5">
        {ABAS.map((a) => (
          <button key={a.key} onClick={() => setAba(a.key)}
            className={`text-sm py-2.5 px-4 border-b-2 font-medium transition-colors ${aba === a.key ? 'border-red-600 text-red-600' : 'border-transparent text-gray-500 hover:text-gray-700'}`}>
            {a.label}
          </button>
        ))}
      </div>

      {/* Filtro por setor */}
      {setoresPresentes.length > 1 && (
        <div className="flex items-center gap-2 mb-5 flex-wrap">
          <span className="text-xs text-gray-500 font-medium">Filtrar por setor:</span>
          <button
            onClick={() => setFiltroSetor(null)}
            className={`px-3 py-1 rounded-full text-xs font-medium border transition-colors ${
              filtroSetor === null
                ? 'bg-gray-800 text-white border-gray-800'
                : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'
            }`}
          >
            Todos
          </button>
          {setoresPresentes.map((s) => (
            <button
              key={s}
              onClick={() => setFiltroSetor(filtroSetor === s ? null : s)}
              className={`px-3 py-1 rounded-full text-xs font-medium border transition-colors ${
                filtroSetor === s ? 'text-white border-transparent' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'
              }`}
              style={filtroSetor === s ? { backgroundColor: corSetor(s), borderColor: corSetor(s) } : {}}
            >
              <span
                className="inline-block w-1.5 h-1.5 rounded-full mr-1.5 align-middle"
                style={{ backgroundColor: filtroSetor === s ? 'white' : corSetor(s) }}
              />
              {s}
            </button>
          ))}
        </div>
      )}

      {/* Lista */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        {carregando ? (
          <div className="py-16 text-center text-sm text-gray-400">Carregando...</div>
        ) : usuariosFiltrados.length === 0 ? (
          <div className="py-16 text-center text-sm text-gray-400">
            {filtroSetor ? `Nenhum usuário no setor "${filtroSetor}".` : 'Nenhum usuário nesta categoria.'}
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50 border-b border-gray-200">
                <th className="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Nome</th>
                <th className="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">E-mail</th>
                <th className="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Setor</th>
                <th className="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Perfis</th>
                <th className="px-5 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {pag.itensPagina.map((u) => {
                const cor = avatarCor(u.nome)
                const isSelf = u.id === euId
                return (
                  <tr key={u.id} className="hover:bg-gray-50 transition-colors">
                    {/* Nome + avatar */}
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-full flex items-center justify-center text-white text-sm font-bold flex-shrink-0"
                          style={{ backgroundColor: cor }}>
                          {iniciais(u.nome)}
                        </div>
                        <div>
                          <p className="font-medium text-gray-900">{u.nome}</p>
                          {u.must_change_password && (
                            <span className="text-[10px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full font-semibold">1º acesso</span>
                          )}
                        </div>
                      </div>
                    </td>
                    {/* E-mail */}
                    <td className="px-5 py-3.5 text-gray-500">{u.email}</td>
                    {/* Setor */}
                    <td className="px-5 py-3.5">
                      {u.grupo_nome ? (
                        <span
                          className="text-xs px-2.5 py-1 rounded-full font-medium"
                          style={{ backgroundColor: corSetor(u.grupo_nome) + '1A', color: corSetor(u.grupo_nome) }}
                        >
                          {u.grupo_nome}
                        </span>
                      ) : (
                        <span className="text-gray-300">—</span>
                      )}
                    </td>
                    {/* Perfis */}
                    <td className="px-5 py-3.5">
                      <div className="flex flex-wrap gap-1">
                        {(u.perfis?.length ? u.perfis : [u.tipo]).map((p) => (
                          <span key={p} className={`text-xs px-2.5 py-1 rounded-full font-medium ${TIPO_COR[p] ?? 'bg-gray-100 text-gray-600'}`}>
                            {TIPO_LABEL[p] ?? p}
                          </span>
                        ))}
                      </div>
                    </td>
                    {/* Ações */}
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-3 justify-end flex-wrap">
                        {aba === 'arquivados' ? (
                          <button onClick={() => handleRestaurar(u)}
                            className="text-sm text-blue-600 hover:underline font-medium">
                            Restaurar
                          </button>
                        ) : (
                          <>
                            <button onClick={() => setModal({ tipo: 'editar', usuario: u })}
                              className="text-sm text-gray-700 hover:underline font-medium">
                              Editar
                            </button>
                            <button onClick={() => setModal({ tipo: 'resetar', usuario: u })}
                              className="text-sm font-medium hover:underline"
                              style={{ color: '#E8001C' }}>
                              Resetar senha
                            </button>
                            {!isSelf && (
                              <>
                                <button onClick={() => handleDesativar(u)}
                                  className="text-sm text-amber-600 hover:underline font-medium">
                                  {u.ativo ? 'Desativar' : 'Reativar'}
                                </button>
                                <button onClick={() => handleArquivar(u)}
                                  className="text-sm text-purple-600 hover:underline font-medium">
                                  Arquivar
                                </button>
                              </>
                            )}
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
        {!carregando && usuariosFiltrados.length > 0 && (
          <Paginacao
            pagina={pag.pagina}
            totalPaginas={pag.totalPaginas}
            porPagina={pag.porPagina}
            total={pag.total}
            onPagina={pag.setPagina}
            onPorPagina={pag.mudarPorPagina}
          />
        )}
      </div>

      {/* Modais */}
      {modal?.tipo === 'editar' && (
        <ModalEditar
          usuario={modal.usuario}
          setores={setores}
          onClose={() => setModal(null)}
          onSalvo={() => { setModal(null); carregar() }}
        />
      )}
      {modal?.tipo === 'resetar' && (
        <ModalResetarSenha
          usuario={modal.usuario}
          onClose={() => setModal(null)}
          onSalvo={() => { setModal(null); carregar() }}
        />
      )}
    </div>
  )
}
