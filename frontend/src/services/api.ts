import axios, { AxiosError } from 'axios'

const BASE_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8001'

const api = axios.create({
  baseURL: BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
})

// Request: injeta o token Bearer
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('bh_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Response: 401 → limpa storage e redireciona
api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('bh_token')
      localStorage.removeItem('bh_user')
      sessionStorage.clear()
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

export default api

// ── Auth ─────────────────────────────────────────────────────────────────────

export const authLogin = (email: string, senha: string) =>
  api.post('/v1/auth/login', { email, senha })

export const authAlterarSenha = (senha_atual: string, nova_senha: string) =>
  api.post('/v1/auth/alterar-senha', { senha_atual, nova_senha })

// ── Analista ─────────────────────────────────────────────────────────────────

export const getMeusLancamentos = (params?: Record<string, string>) =>
  api.get('/v1/banco-de-horas/meus-lancamentos', { params })

export const getMeuSaldo = () =>
  api.get('/v1/banco-de-horas/saldo')

export const criarLancamento = (data: object) =>
  api.post('/v1/banco-de-horas/lancamentos', data)

export const editarLancamento = (id: number, data: object) =>
  api.put(`/v1/banco-de-horas/lancamentos/${id}`, data)

export const cancelarLancamento = (id: number) =>
  api.delete(`/v1/banco-de-horas/lancamentos/${id}`)

// ── Coordenador / Admin ───────────────────────────────────────────────────────

export const getAdminLancamentos = (params?: Record<string, string>) =>
  api.get('/v1/banco-de-horas/admin/lancamentos', { params })

export const aprovarLancamento = (id: number) =>
  api.post(`/v1/banco-de-horas/admin/lancamentos/${id}/aprovar`)

export const recusarLancamento = (id: number, nota_revisao: string) =>
  api.post(`/v1/banco-de-horas/admin/lancamentos/${id}/recusar`, { nota_revisao })

export const contestarLancamento = (id: number, nota_revisao: string) =>
  api.post(`/v1/banco-de-horas/admin/lancamentos/${id}/contestar`, { nota_revisao })

export const getAdminRelatorio = (params?: Record<string, string>) =>
  api.get('/v1/banco-de-horas/admin/relatorio', { params })

export const getRelatorioColaborador = (usuario_id: number, params?: Record<string, string>) =>
  api.get(`/v1/banco-de-horas/admin/relatorio/${usuario_id}`, { params })

// ── Admin ────────────────────────────────────────────────────────────────────

export const getAdminUsuarios = (params?: Record<string, string>) =>
  api.get('/v1/banco-de-horas/admin/usuarios', { params })

export const salvarConfigUsuario = (usuario_id: number, data: object) =>
  api.put(`/v1/banco-de-horas/admin/usuarios/${usuario_id}/config`, data)

export const criarUsuario = (data: object) =>
  api.post('/v1/banco-de-horas/admin/usuarios', data)

export const getAdminSetores = () =>
  api.get('/v1/banco-de-horas/admin/setores')

export const criarSetor = (nome: string) =>
  api.post('/v1/banco-de-horas/admin/setores', { nome })

export const editarSetor = (id: number, nome: string) =>
  api.put(`/v1/banco-de-horas/admin/setores/${id}`, { nome })

export const deletarSetor = (id: number) =>
  api.delete(`/v1/banco-de-horas/admin/setores/${id}`)

export const editarUsuario = (id: number, data: object) =>
  api.put(`/v1/banco-de-horas/admin/usuarios/${id}`, data)

export const resetarSenhaUsuario = (id: number, nova_senha: string, enviar_email = false) =>
  api.post(`/v1/banco-de-horas/admin/usuarios/${id}/resetar-senha`, { nova_senha, enviar_email })

export const desativarUsuario = (id: number) =>
  api.post(`/v1/banco-de-horas/admin/usuarios/${id}/desativar`)

export const arquivarUsuario = (id: number) =>
  api.post(`/v1/banco-de-horas/admin/usuarios/${id}/arquivar`)

export const restaurarUsuario = (id: number) =>
  api.post(`/v1/banco-de-horas/admin/usuarios/${id}/restaurar`)

// ── Escala do setor (coordenador/admin) ──────────────────────────────────────

export const getAdminEscala = (params?: Record<string, string>) =>
  api.get('/v1/banco-de-horas/admin/escala', { params })

// ── Backup / Restore (admin) ──────────────────────────────────────────────────

export const baixarBackup = () =>
  api.get('/v1/banco-de-horas/admin/backup')

export const restaurarBackup = (dados: object) =>
  api.post('/v1/banco-de-horas/admin/restore', dados)

// ── Acionamento (atendimento corporativo) ────────────────────────────────────

export const getDisponiveis = (params?: Record<string, string>) =>
  api.get('/v1/banco-de-horas/acionamento/disponiveis', { params })

// ── Escala (analista) ────────────────────────────────────────────────────────

export const getEscala = (params?: Record<string, string>) =>
  api.get('/v1/banco-de-horas/escala', { params })

export const criarEscala = (data: object) =>
  api.post('/v1/banco-de-horas/escala', data)

export const deletarEscala = (id: number) =>
  api.delete(`/v1/banco-de-horas/escala/${id}`)


// ── Folgas (analista) ─────────────────────────────────────────────────────────

export const getSaldoCompleto = () =>
  api.get('/v1/banco-de-horas/saldo-completo')

export const getMinhasFolgas = () =>
  api.get('/v1/banco-de-horas/folgas')

export const criarFolga = (data: object) =>
  api.post('/v1/banco-de-horas/folgas', data)

export const cancelarFolga = (id: number) =>
  api.delete(`/v1/banco-de-horas/folgas/${id}`)

// ── Folgas (admin/coordenador) ────────────────────────────────────────────────

export const getAdminFolgas = (params?: Record<string, string>) =>
  api.get('/v1/banco-de-horas/admin/folgas', { params })

export const aprovarFolga = (id: number) =>
  api.post(`/v1/banco-de-horas/admin/folgas/${id}/aprovar`)

export const recusarFolga = (id: number, nota_revisao: string) =>
  api.post(`/v1/banco-de-horas/admin/folgas/${id}/recusar`, { nota_revisao })
