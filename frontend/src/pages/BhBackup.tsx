import { useState } from 'react'
import { baixarBackup, restaurarBackup } from '../services/api'

function hojeISO(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export default function BhBackup() {
  const [baixando, setBaixando] = useState(false)
  const [arquivo, setArquivo] = useState<File | null>(null)
  const [restaurando, setRestaurando] = useState(false)
  const [msg, setMsg] = useState<{ tipo: 'ok' | 'erro'; texto: string } | null>(null)

  async function handleBaixar() {
    setBaixando(true)
    setMsg(null)
    try {
      const res = await baixarBackup()
      const conteudo = JSON.stringify(res.data, null, 2)
      const blob = new Blob([conteudo], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `bh-pulse-backup-${hojeISO()}.json`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
      setMsg({ tipo: 'ok', texto: 'Backup baixado com sucesso.' })
    } catch {
      setMsg({ tipo: 'erro', texto: 'Erro ao gerar o backup.' })
    } finally {
      setBaixando(false)
    }
  }

  async function handleRestaurar() {
    if (!arquivo) return
    const confirmacao = window.prompt(
      'ATENÇÃO: o restore SUBSTITUI todos os dados atuais pelos do backup.\n\n' +
      'Esta ação é irreversível. Digite RESTAURAR para confirmar:'
    )
    if (confirmacao !== 'RESTAURAR') {
      setMsg({ tipo: 'erro', texto: 'Restore cancelado.' })
      return
    }

    setRestaurando(true)
    setMsg(null)
    try {
      const texto = await arquivo.text()
      const dados = JSON.parse(texto)
      if (!dados.tabelas) {
        setMsg({ tipo: 'erro', texto: 'Arquivo inválido: não parece um backup do BH Pulse.' })
        setRestaurando(false)
        return
      }
      const res = await restaurarBackup(dados)
      const c = (res.data as { contagem?: Record<string, number> }).contagem
      const resumo = c ? Object.entries(c).map(([k, v]) => `${k}: ${v}`).join(' · ') : ''
      setMsg({ tipo: 'ok', texto: `Backup restaurado com sucesso. ${resumo}` })
      setArquivo(null)
    } catch (err) {
      const detail = (err as { response?: { data?: { detail?: string } } })?.response?.data?.detail
      setMsg({ tipo: 'erro', texto: detail ?? 'Erro ao restaurar o backup. Verifique o arquivo.' })
    } finally {
      setRestaurando(false)
    }
  }

  return (
    <div className="p-6 max-w-3xl mx-auto space-y-6">
      <div>
        <h1 className="text-xl font-bold text-gray-900">Backup &amp; Restauração</h1>
        <p className="text-sm text-gray-500 mt-0.5">Exporte e restaure todos os dados do sistema</p>
      </div>

      {/* Aviso LGPD */}
      <div className="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
        <span className="text-amber-600 text-lg mt-0.5">⚠️</span>
        <p className="text-sm text-amber-800">
          O arquivo de backup contém <strong>dados pessoais e sensíveis</strong> (nomes, e-mails,
          salários e credenciais). Trate-o como confidencial: guarde em local seguro, não envie por
          canais abertos e não o versione em repositórios.
        </p>
      </div>

      {msg && (
        <div className={`rounded-xl px-4 py-3 text-sm ${
          msg.tipo === 'ok' ? 'bg-green-50 border border-green-200 text-green-700'
                            : 'bg-red-50 border border-red-200 text-red-700'
        }`}>
          {msg.texto}
        </div>
      )}

      {/* Baixar backup */}
      <div className="bg-white rounded-xl border border-gray-200 p-5">
        <h2 className="text-base font-semibold text-gray-900 mb-1">Baixar backup</h2>
        <p className="text-sm text-gray-500 mb-4">
          Gera um arquivo <code>.json</code> com todos os setores, usuários, configurações,
          lançamentos, folgas e escalas.
        </p>
        <button
          onClick={handleBaixar}
          disabled={baixando}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-white text-sm font-medium disabled:opacity-60"
          style={{ backgroundColor: '#E8001C' }}
        >
          ⬇ {baixando ? 'Gerando...' : 'Baixar backup'}
        </button>
      </div>

      {/* Restaurar backup */}
      <div className="bg-white rounded-xl border border-gray-200 p-5">
        <h2 className="text-base font-semibold text-gray-900 mb-1">Restaurar backup</h2>
        <p className="text-sm text-gray-500 mb-4">
          Importa um arquivo de backup. <strong className="text-red-600">Substitui todos os dados
          atuais</strong> — use ao migrar para um novo ambiente ou recuperar de uma perda.
        </p>
        <div className="flex flex-col sm:flex-row gap-3 sm:items-center">
          <input
            type="file"
            accept="application/json,.json"
            onChange={(e) => { setArquivo(e.target.files?.[0] ?? null); setMsg(null) }}
            className="text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border file:border-gray-300 file:bg-gray-50 file:text-gray-700 file:text-sm file:font-medium hover:file:bg-gray-100"
          />
          <button
            onClick={handleRestaurar}
            disabled={!arquivo || restaurando}
            className="px-4 py-2 rounded-lg text-sm font-medium border border-red-300 text-red-600 hover:bg-red-50 disabled:opacity-40 disabled:cursor-not-allowed whitespace-nowrap"
          >
            {restaurando ? 'Restaurando...' : 'Restaurar backup'}
          </button>
        </div>
        {arquivo && (
          <p className="text-xs text-gray-400 mt-2">Selecionado: {arquivo.name}</p>
        )}
      </div>
    </div>
  )
}
