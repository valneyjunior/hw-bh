import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import Layout from './components/Layout'
import PrivateRoute from './components/PrivateRoute'
import Login from './pages/Login'
import AlterarSenha from './pages/AlterarSenha'
import BancoDeHoras from './pages/BancoDeHoras'
import BhBancoEquipe from './pages/BhBancoEquipe'
import BhValidacao from './pages/BhValidacao'
import BhRelatorios from './pages/BhRelatorios'
import BhRelatorioColaborador from './pages/BhRelatorioColaborador'
import BhUsuarios from './pages/BhUsuarios'
import BhSetores from './pages/BhSetores'
import BhEscala from './pages/BhEscala'
import BhEscalaSetor from './pages/BhEscalaSetor'
import BhFolgas from './pages/BhFolgas'
import BhAcionamento from './pages/BhAcionamento'
import BhBackup from './pages/BhBackup'
import BhAuditoria from './pages/BhAuditoria'

function RootRedirect() {
  try {
    const raw = localStorage.getItem('bh_user')
    if (raw) {
      const user = JSON.parse(raw) as { tipo: string; perfis?: string[] }
      const perfis = user.perfis?.length ? user.perfis : [user.tipo]
      if (perfis.includes('admin') || perfis.includes('coordenador')) {
        return <Navigate to="/validacao" replace />
      }
      // Atendimento puro (sem perfil de analista) vai direto ao acionamento
      if (perfis.includes('atendimento') && !perfis.includes('analista')) {
        return <Navigate to="/acionamento" replace />
      }
    }
  } catch {
    // ignora erro de parse
  }
  return <BancoDeHoras />
}

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route
          path="/alterar-senha"
          element={
            <PrivateRoute>
              <AlterarSenha />
            </PrivateRoute>
          }
        />
        <Route
          element={
            <PrivateRoute>
              <Layout />
            </PrivateRoute>
          }
        >
          <Route path="/" element={<RootRedirect />} />
          <Route path="/meus-registros" element={<BancoDeHoras />} />
          <Route path="/folgas" element={<BhFolgas />} />
          <Route path="/escala" element={<BhEscala />} />
          <Route
            path="/acionamento"
            element={
              <PrivateRoute apenasAtendimento>
                <BhAcionamento />
              </PrivateRoute>
            }
          />
          <Route
            path="/banco-horas"
            element={
              <PrivateRoute apenasCoordenador>
                <BhBancoEquipe />
              </PrivateRoute>
            }
          />
          <Route
            path="/escala-setor"
            element={
              <PrivateRoute apenasCoordenador>
                <BhEscalaSetor />
              </PrivateRoute>
            }
          />
          <Route
            path="/validacao"
            element={
              <PrivateRoute apenasCoordenador>
                <BhValidacao />
              </PrivateRoute>
            }
          />
          <Route
            path="/relatorios"
            element={
              <PrivateRoute apenasCoordenador>
                <BhRelatorios />
              </PrivateRoute>
            }
          />
          <Route
            path="/relatorios/:id"
            element={
              <PrivateRoute apenasCoordenador>
                <BhRelatorioColaborador />
              </PrivateRoute>
            }
          />
          <Route
            path="/usuarios"
            element={
              <PrivateRoute apenasAdmin>
                <BhUsuarios />
              </PrivateRoute>
            }
          />
          <Route
            path="/setores"
            element={
              <PrivateRoute apenasAdmin>
                <BhSetores />
              </PrivateRoute>
            }
          />
          <Route
            path="/auditoria"
            element={
              <PrivateRoute apenasAdmin>
                <BhAuditoria />
              </PrivateRoute>
            }
          />
          <Route
            path="/backup"
            element={
              <PrivateRoute apenasAdmin>
                <BhBackup />
              </PrivateRoute>
            }
          />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  )
}
