from datetime import datetime, timedelta, timezone
from typing import Optional

from fastapi import Depends, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer
import bcrypt
from jose import JWTError, jwt
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from config import get_settings
from database import get_db
from models import Usuario

settings = get_settings()

bearer_scheme = HTTPBearer(auto_error=True)


# ─── Senha ───────────────────────────────────────────────────────────────────

def hash_password(plain: str) -> str:
    return bcrypt.hashpw(plain.encode(), bcrypt.gensalt(rounds=12)).decode()


def validar_forca_senha(senha: str) -> Optional[str]:
    """
    V-007 — Política de senha. Retorna mensagem de erro se fraca, ou None se OK.
    Mínimo de 8 caracteres com pelo menos uma letra e um número.
    (Alinhado ao gerador automático de senhas do sistema.)
    """
    if len(senha) < 8:
        return "A senha deve ter ao menos 8 caracteres."
    if not any(c.isalpha() for c in senha):
        return "A senha deve conter ao menos uma letra."
    if not any(c.isdigit() for c in senha):
        return "A senha deve conter ao menos um número."
    return None


def verify_password(plain: str, hashed: str) -> bool:
    try:
        return bcrypt.checkpw(plain.encode(), hashed.encode())
    except Exception:
        return False


# ─── JWT ─────────────────────────────────────────────────────────────────────

def create_access_token(data: dict) -> str:
    payload = data.copy()
    expire = datetime.now(timezone.utc) + timedelta(hours=settings.JWT_EXPIRE_HOURS)
    payload.update({"exp": expire})
    return jwt.encode(payload, settings.JWT_SECRET, algorithm="HS256")


def decode_token(token: str) -> dict:
    try:
        return jwt.decode(token, settings.JWT_SECRET, algorithms=["HS256"])
    except JWTError:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Token inválido ou expirado",
            headers={"WWW-Authenticate": "Bearer"},
        )


# ─── Dependências ─────────────────────────────────────────────────────────────

async def get_current_user(
    credentials: HTTPAuthorizationCredentials = Depends(bearer_scheme),
    db: AsyncSession = Depends(get_db),
) -> Usuario:
    payload = decode_token(credentials.credentials)
    email: Optional[str] = payload.get("sub")
    if not email:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Token sem sub")

    result = await db.execute(select(Usuario).where(Usuario.email == email, Usuario.ativo == True))
    user = result.scalar_one_or_none()
    if not user:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Usuário não encontrado")
    return user


def require_tipo(*tipos: str):
    """Dependency factory — exige que o usuário seja de um dos tipos informados."""
    async def _inner(current_user: Usuario = Depends(get_current_user)) -> Usuario:
        if current_user.tipo not in tipos:
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail=f"Acesso negado. Requer tipo: {', '.join(tipos)}",
            )
        return current_user
    return _inner


def require_permissao_coordenador():
    """admin ou coordenador."""
    return require_tipo("admin", "coordenador")


def require_perfil(*perfis: str):
    """Exige que o usuário tenha PELO MENOS UM dos perfis informados (lista cumulativa)."""
    async def _inner(current_user: Usuario = Depends(get_current_user)) -> Usuario:
        usuario_perfis = current_user.perfis or [current_user.tipo]
        if not any(p in usuario_perfis for p in perfis):
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail=f"Acesso negado. Requer perfil: {', '.join(perfis)}",
            )
        return current_user
    return _inner


def require_acionamento():
    """admin ou atendimento corporativo."""
    return require_perfil("admin", "atendimento")
