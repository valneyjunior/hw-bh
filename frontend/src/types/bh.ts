export interface UserInfo {
  id: number
  nome: string
  tipo: 'admin' | 'coordenador' | 'analista' | 'atendimento'
  grupo_id: number | null
  grupo_nome: string | null
  must_change_password: boolean
  perfis?: string[]
  telefone?: string | null
}

export interface AuditLog {
  id: number
  usuario_id: number | null
  usuario_nome: string | null
  usuario_email: string | null
  acao: string
  recurso: string
  recurso_id: number | null
  ip: string | null
  detalhes: Record<string, unknown> | null
  hash_registro: string
  criado_em: string
}

export interface Disponivel {
  id: number
  usuario_id: number
  usuario_nome: string
  grupo_nome: string | null
  telefone: string | null
  data_disponivel: string
  turno: string
  observacao: string | null
}

export interface AuthState {
  token: string
  user: UserInfo
}

export interface Setor {
  id: number
  nome: string
  total_usuarios?: number
}

export interface Lancamento {
  id: number
  usuario_id: number
  data_acionamento: string
  hora_inicio: string
  hora_fim: string
  total_minutos: number | null
  chamado: string
  motivo: string
  feriado: boolean
  descricao_feriado: string | null
  status: 'pendente' | 'aprovado' | 'recusado'
  nota_revisao: string | null
  valor_calculado: number | null
  revisado_por: number | null
  revisado_em: string | null
  criado_em: string
  usuario_nome?: string
  usuario_email?: string
  requer_aprovacao_diretor?: boolean
}

export interface SaldoBH {
  saldo_minutos: number
  aprovados: number
  pendentes: number
  recusados: number
}

export interface ConfigUsuario {
  usuario_id: number
  salario_bruto: number
  work_start: string
  work_end: string
  lunch_start: string
  lunch_minutes: number
  adicional_atrativo?: number
}

export interface UsuarioComConfig {
  id: number
  nome: string
  email: string
  tipo: string
  grupo_id: number | null
  grupo_nome: string | null
  ativo: boolean
  arquivado: boolean
  must_change_password: boolean
  perfis?: string[]
  telefone?: string | null
  setores_coordenados?: number[]
  config: ConfigUsuario | null
}

export interface LancamentoCriarPayload {
  data_acionamento: string
  hora_inicio: string
  hora_fim: string
  chamado: string
  motivo: string
  feriado: boolean
  descricao_feriado?: string
}

export interface RelatorioKpi {
  total_acionamentos: number
  horas_aprovadas: number
  custo_clt_total: number
  media_por_acionamento: number
  por_tipo: Record<string, { minutos: number; horas: number; valor: number }>
  por_mes: Array<{ mes: string; minutos: number; horas: number; valor: number; acionamentos: number }>
  colaboradores: Array<{
    usuario_id: number
    nome: string
    grupo_nome: string | null
    minutos: number
    horas: number
    valor: number
    acionamentos: number
  }>
}

export interface RelatorioColaborador {
  usuario: {
    id: number
    nome: string
    email: string
    tipo: string
    grupo_id: number | null
    grupo_nome: string | null
    ativo: boolean
  }
  config: ConfigUsuario | null
  kpis: {
    total_acionamentos: number
    horas_aprovadas: number
    custo_clt_total: number
  }
  por_mes: Array<{ mes: string; minutos: number; horas: number; valor: number; acionamentos: number }>
  lancamentos: Lancamento[]
}

export interface UsuarioCriar {
  nome: string
  email: string
  senha: string
  tipo: string
  grupo_id?: number
  must_change_password: boolean
}

export interface UsuarioEditar {
  nome: string
  email: string
  tipo: string
  grupo_id?: number | null
  ativo: boolean
}

export interface EscalaItem {
  id: number
  data_disponivel: string
  turno: string
  observacao?: string
}

export interface EscalaAdminItem {
  id: number
  usuario_id: number
  usuario_nome: string
  grupo_nome?: string
  data_disponivel: string
  turno: string
  observacao?: string
}

export interface Folga {
  id: number
  usuario_id: number
  data_folga: string
  tipo: string
  hora_inicio?: string
  hora_fim?: string
  minutos_deduzidos: number
  motivo: string
  status: 'pendente' | 'aprovado' | 'recusado'
  nota_revisao?: string
  revisado_por?: number
  revisado_em?: string
  criado_em: string
  usuario_nome?: string
}

export interface SaldoCompleto {
  banco_minutos: number
  deducoes_minutos: number
  saldo_disponivel: number
  work_start: string
  work_end: string
  lunch_start: string
  lunch_minutes: number
}
