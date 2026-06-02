import { useState, useEffect } from 'react'

interface Props {
  value: string                  // "HH:MM" ou ""
  onChange: (v: string) => void  // retorna "HH:MM" válido ou "" se incompleto/inválido
  className?: string
  required?: boolean
  id?: string
}

/**
 * Input de hora em formato militar 24h (HH:MM).
 * O usuário digita apenas dígitos; o componente insere o ":" automaticamente.
 * Aceita digitação direta: "0830" → exibe "08:30".
 */
export function HoraInput({ value, onChange, className = '', required, id }: Props) {
  const [display, setDisplay] = useState(value)

  // Sincroniza quando o valor externo muda (ex: reset de form ou carregamento)
  useEffect(() => {
    setDisplay(value)
  }, [value])

  function processInput(raw: string): { formatted: string; valid: string } {
    const digits = raw.replace(/\D/g, '').slice(0, 4)
    if (!digits) return { formatted: '', valid: '' }

    const fmt =
      digits.length >= 3
        ? digits.slice(0, 2) + ':' + digits.slice(2)
        : digits

    if (digits.length === 4) {
      const h = parseInt(digits.slice(0, 2), 10)
      const m = parseInt(digits.slice(2, 4), 10)
      if (h <= 23 && m <= 59) return { formatted: fmt, valid: fmt }
      return { formatted: fmt, valid: '' }
    }

    return { formatted: fmt, valid: '' }
  }

  function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
    const { formatted, valid } = processInput(e.target.value)
    setDisplay(formatted)
    onChange(valid)
  }

  function handleBlur() {
    // Se o usuário saiu com valor incompleto, limpa
    if (display.length > 0 && display.length < 5) {
      setDisplay('')
      onChange('')
    }
  }

  return (
    <input
      id={id}
      type="text"
      inputMode="numeric"
      placeholder="HH:MM"
      value={display}
      onChange={handleChange}
      onBlur={handleBlur}
      maxLength={5}
      required={required}
      autoComplete="off"
      className={className}
    />
  )
}
