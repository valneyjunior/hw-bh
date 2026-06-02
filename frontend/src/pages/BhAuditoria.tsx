import { useEffect, useState, useCallback, Fragment } from 'react'
import { getAuditoria, verificarAuditoria } from '../services/api'
import type { AuditLog } from '../types/bh'
import Paginacao from '../components/Paginacao'

// Rótulos amigáveis das ações
const ACAO_LABEL: Record<string, string> = {
  'auth.login':            'Login',
  'auth.login_falha':      'Login (falha)',
  'auth.alterar_senha':    'Alterou a própria senha',
  'lancamento.aprovar':    'Aprovou lançamento',
  'lancamento.recusar':    'Recusou lançamento',
  'lancamento.contestar':  'Contestou lançamento',
  'folga.aprovar':         'Aprovou folga',
  'folga.recusar':         'Recusou folga',
  'usuario.criar':         'Criou usuário',
  'usuario.editar':        'Editou usuário',
  'usuario.config':        'Alterou config/salário',
  'usuario.resetar_senha': 'Resetou senha',
  'usuario.desativar':     'Desativou usuário',
  'usuario.ativar':        'Reativou usuário',
  'usuario.arquivar':      'Arquivou usuário',
  'usuario.restaurar':     'Restaurou usuário',
  'backup.baixar':         'Baixou backup',
  'backup.restaurar':      'Restaurou backup',
}

const ACAO_COR: Record<string, string> = {
  'auth.login_falha':   'bg-red-100 text-red-700',
  'backup.restaurar':   'bg-red-100 text-red-700',
  'backup.baixar':      'bg-amber-100 text-amber-700',
  'usuario.config':     'bg-amber-100 text-amber-700',
  'usuario.resetar_senha': 'bg-amber-100 text-amber-700',
  'lancamento.aprovar': 'bg-green-100 text-green-700',
  'folga.aprovar':      'bg-green-100 text-green-700',
}

function fmtDatetime(iso: string): string {
  const dt = new Date(iso)
  return dt.toLocaleDateString('pt-BR') + ' ' + dt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

export default function BhAuditoria() {
  const [logs, setLogs] = useState<AuditLog[]>([])
  const [total, setTotal] = useState(0)
  const [pagina, setPagina] = useState(1)
  const [porPagina, setPorPagina] = useState(20)
  const [loading, setLoading] = useState(true)
  const [integridade, setIntegridade] = useState<{ integro: boolean; total: number; quebrado_no_id: number | null } | null>(null)
  const [verificando, setVerificando] = useState(false)
  const [expandido, setExpandido] = useState<number | null>(null)

  const carregar = useCallback(async () => {
    setLoading(true)
    try {
      const res = await getAuditoria({ page: String(pagina), per_page: String(porPagina) })
      const data = res.data as { items: AuditLog[]; total: number }
      setLogs(data.items)
      setTotal(data.total)
    } finally {
      setLoading(false)
    }
  }, [pagina, porPagina])

  useEffect(() => { carregar() }, [carregar])

  async function handleVerificar() {
    setVerificando(true)
    try {
      const res = await verificarAuditoria()
      setIntegridade(res.data as { integro: boolean; total: number; quebrado_no_id: number | null })
    } finally {
      setVerificando(false)
    }
  }

  const totalPaginas = Math.max(1, Math.ceil(total / porPagina))

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Auditoria</h1>
          <p className="text-sm text-gray-500 mt-0.5">
            Trilha de eventos sensíveis com encadeamento por hash (não-repúdio)
          </p>
        </div>
        <button
          onClick={handleVerificar}
          disabled={verificando}
          className="px-4 py-2 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60 whitespace-nowrap"
        >
          {verificando ? 'Verificando...' : '🔒 Verificar integridade'}
        </button>
      </div>

      {/* Resultado da verificação */}
      {integridade && (
        <div className={`rounded-xl px-4 py-3 text-sm border ${
          integridade.integro
            ? 'bg-green-50 border-green-200 text-green-700'
            : 'bg-red-50 border-red-200 text-red-700'
        }`}>
          {integridade.integro
            ? `✅ Trilha íntegra — ${integridade.total} registro(s) verificado(s). Nenhuma adulteração detectada.`
            : `🚨 ADULTERAÇÃO DETECTADA — a cadeia quebra no registro #${integridade.quebrado_no_id}. Os dados foram alterados fora do sistema.`}
        </div>
      )}

      {/* Tabela */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        {loading ? (
          <div className="py-16 text-center text-sm text-gray-400">Carregando...</div>
        ) : logs.length === 0 ? (
          <div className="py-16 text-center text-sm text-gray-400">Nenhum evento registrado.</div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="bg-gray-50 border-b border-gray-200">
                    <th className="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Data/Hora</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Autor</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Ação</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Recurso</th>
                    <th className="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">IP</th>
                    <th className="px-4 py-3" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {logs.map((l) => (
                    <Fragment key={l.id}>
                      <tr className="hover:bg-gray-50 transition-colors">
                        <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{fmtDatetime(l.criado_em)}</td>
                        <td className="px-4 py-3">
                          <span className="text-gray-800">{l.usuario_nome ?? '—'}</span>
                          {l.usuario_email && <div className="text-xs text-gray-400">{l.usuario_email}</div>}
                        </td>
                        <td className="px-4 py-3">
                          <span className={`text-xs px-2.5 py-1 rounded-full font-medium ${ACAO_COR[l.acao] ?? 'bg-gray-100 text-gray-600'}`}>
                            {ACAO_LABEL[l.acao] ?? l.acao}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-gray-600">
                          {l.recurso}{l.recurso_id != null ? ` #${l.recurso_id}` : ''}
                        </td>
                        <td className="px-4 py-3 text-gray-500 font-mono text-xs">{l.ip ?? '—'}</td>
                        <td className="px-4 py-3 text-right">
                          {l.detalhes && Object.keys(l.detalhes).length > 0 && (
                            <button
                              onClick={() => setExpandido(expandido === l.id ? null : l.id)}
                              className="text-xs text-blue-600 hover:underline"
                            >
                              {expandido === l.id ? 'ocultar' : 'detalhes'}
                            </button>
                          )}
                        </td>
                      </tr>
                      {expandido === l.id && l.detalhes && (
                        <tr className="bg-slate-50">
                          <td colSpan={6} className="px-4 py-3">
                            <div className="text-xs text-slate-600 font-mono break-all">
                              {JSON.stringify(l.detalhes)}
                            </div>
                            <div className="text-[11px] text-slate-400 mt-1">hash: {l.hash_registro}</div>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  ))}
                </tbody>
              </table>
            </div>
            <Paginacao
              pagina={pagina}
              totalPaginas={totalPaginas}
              porPagina={porPagina}
              total={total}
              onPagina={setPagina}
              onPorPagina={(n) => { setPorPagina(n); setPagina(1) }}
            />
          </>
        )}
      </div>
    </div>
  )
}
