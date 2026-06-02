import { useState, useMemo, useEffect } from 'react'

export const OPCOES_POR_PAGINA = [10, 20, 50, 100] as const

/**
 * Hook de paginação client-side reutilizável.
 * Retorna a fatia atual e os controles. Reseta para a página 1 quando a lista
 * encolhe abaixo da página corrente (ex.: após filtro).
 */
export function usePaginacao<T>(itens: T[], inicial = 10) {
  const [pagina, setPagina] = useState(1)
  const [porPagina, setPorPagina] = useState<number>(inicial)

  const total = itens.length
  const totalPaginas = Math.max(1, Math.ceil(total / porPagina))

  useEffect(() => {
    if (pagina > totalPaginas) setPagina(1)
  }, [pagina, totalPaginas])

  const itensPagina = useMemo(() => {
    const ini = (pagina - 1) * porPagina
    return itens.slice(ini, ini + porPagina)
  }, [itens, pagina, porPagina])

  function mudarPorPagina(n: number) {
    setPorPagina(n)
    setPagina(1)
  }

  return { pagina, setPagina, porPagina, mudarPorPagina, total, totalPaginas, itensPagina }
}

interface Props {
  pagina: number
  totalPaginas: number
  porPagina: number
  total: number
  onPagina: (p: number) => void
  onPorPagina: (n: number) => void
}

export default function Paginacao({ pagina, totalPaginas, porPagina, total, onPagina, onPorPagina }: Props) {
  if (total === 0) return null

  const ini = (pagina - 1) * porPagina + 1
  const fim = Math.min(pagina * porPagina, total)

  return (
    <div className="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-100 text-sm no-print">
      <div className="flex items-center gap-2 text-slate-500">
        <span>Exibir</span>
        <select
          value={porPagina}
          onChange={(e) => onPorPagina(Number(e.target.value))}
          className="border border-slate-200 rounded-lg px-2 py-1 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-red-500"
        >
          {OPCOES_POR_PAGINA.map((n) => (
            <option key={n} value={n}>{n}</option>
          ))}
        </select>
        <span>por página</span>
      </div>

      <div className="flex items-center gap-3">
        <span className="text-slate-400">
          {ini}–{fim} de {total}
        </span>
        <div className="flex items-center gap-1">
          <button
            onClick={() => onPagina(pagina - 1)}
            disabled={pagina <= 1}
            className="px-2.5 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
          >
            ‹
          </button>
          <span className="px-2 text-slate-600 font-medium">
            {pagina} / {totalPaginas}
          </span>
          <button
            onClick={() => onPagina(pagina + 1)}
            disabled={pagina >= totalPaginas}
            className="px-2.5 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
          >
            ›
          </button>
        </div>
      </div>
    </div>
  )
}
