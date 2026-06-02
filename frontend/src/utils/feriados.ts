function calcularPascoa(year: number): Date {
  const a = year % 19
  const b = Math.floor(year / 100)
  const c = year % 100
  const d = Math.floor(b / 4)
  const e = b % 4
  const f = Math.floor((b + 8) / 25)
  const g = Math.floor((b - f + 1) / 3)
  const h = (19 * a + b - d - g + 15) % 30
  const i = Math.floor(c / 4)
  const k = c % 4
  const l = (32 + 2 * e + 2 * i - h - k) % 7
  const m = Math.floor((a + 11 * h + 22 * l) / 451)
  const month = Math.floor((h + l - 7 * m + 114) / 31)
  const day = ((h + l - 7 * m + 114) % 31) + 1
  return new Date(year, month - 1, day)
}

function addDias(d: Date, n: number): Date {
  const r = new Date(d)
  r.setDate(r.getDate() + n)
  return r
}

function toISO(d: Date): string {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

/**
 * Feriados NACIONAIS de fato (Lei 662/1949, 6.802/1980, 14.759/2023).
 * Carnaval e Corpus Christi NÃO entram: são pontos facultativos, não feriados
 * nacionais — só viram feriado por lei municipal/estadual ou convenção coletiva.
 * Páscoa também não entra: cai sempre em domingo (já remunerado como domingo).
 * Feriados municipais/estaduais devem ser marcados manualmente pelo coordenador.
 */
export function getFeriadosBrasil(year: number): Record<string, string> {
  const pascoa = calcularPascoa(year)
  return {
    [`${year}-01-01`]: 'Confraternização Universal',
    [toISO(addDias(pascoa, -2))]: 'Sexta-feira Santa',
    [`${year}-04-21`]: 'Tiradentes',
    [`${year}-05-01`]: 'Dia do Trabalho',
    [`${year}-09-07`]: 'Independência do Brasil',
    [`${year}-10-12`]: 'Nossa Senhora Aparecida',
    [`${year}-11-02`]: 'Finados',
    [`${year}-11-15`]: 'Proclamação da República',
    [`${year}-11-20`]: 'Consciência Negra',
    [`${year}-12-25`]: 'Natal',
  }
}

export function detectarFeriado(dataISO: string): { feriado: boolean; descricao: string } {
  if (!dataISO || dataISO.length !== 10) return { feriado: false, descricao: '' }
  const year = parseInt(dataISO.slice(0, 4), 10)
  const feriados = getFeriadosBrasil(year)
  const nome = feriados[dataISO]
  return { feriado: !!nome, descricao: nome ?? '' }
}
