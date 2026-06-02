"""
BH Pulse — Backend FastAPI
Ponto de entrada principal.
"""
import logging
from datetime import datetime, timezone

from fastapi import Depends, FastAPI, HTTPException, Request, status
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from slowapi import Limiter, _rate_limit_exceeded_handler
from slowapi.errors import RateLimitExceeded
from slowapi.util import get_remote_address
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from auth import (
    create_access_token,
    get_current_user,
    hash_password,
    validar_forca_senha,
    verify_password,
)
from config import get_settings
from database import get_db
from models import Usuario
from routers.banco_de_horas import router as bh_router

logger = logging.getLogger("uvicorn.error")
settings = get_settings()

# V-005 — rate limiting (chave = IP de origem)
limiter = Limiter(key_func=get_remote_address)

app = FastAPI(
    title="BH Pulse API",
    version="1.0.0",
    description="Sistema de Banco de Horas — Hostweb",
)
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)


@app.on_event("startup")
async def _avisos_seguranca():
    # V-001 — não deixar subir silenciosamente com segredo padrão
    if settings.jwt_secret_inseguro:
        logger.warning(
            "⚠️  JWT_SECRET está no valor PADRÃO inseguro — defina um segredo forte no .env "
            "antes de ir para produção (risco de forja de tokens)."
        )


# V-008 — cabeçalhos de segurança em todas as respostas
@app.middleware("http")
async def security_headers(request: Request, call_next):
    resp = await call_next(request)
    resp.headers["X-Content-Type-Options"] = "nosniff"
    resp.headers["X-Frame-Options"] = "DENY"
    resp.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
    resp.headers["Permissions-Policy"] = "camera=(), microphone=(), geolocation=()"
    return resp


# V-009 — CORS com origens configuráveis por ambiente
app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_origins_list,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(bh_router)


# ── Schemas de auth ───────────────────────────────────────────────────────────

class LoginIn(BaseModel):
    email: str
    senha: str


class UserInfo(BaseModel):
    id: int
    nome: str
    tipo: str
    grupo_id: int | None
    grupo_nome: str | None
    must_change_password: bool
    perfis: list[str] = []
    telefone: str | None = None


class LoginOut(BaseModel):
    access_token: str
    token_type: str = "bearer"
    user: UserInfo


class AlterarSenhaIn(BaseModel):
    senha_atual: str
    nova_senha: str


class MsgOut(BaseModel):
    detail: str


# ── Endpoints de auth ─────────────────────────────────────────────────────────

@app.post("/v1/auth/login", response_model=LoginOut)
@limiter.limit("5/minute")
async def login(request: Request, payload: LoginIn, db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Usuario).where(Usuario.email == payload.email, Usuario.ativo == True)
    )
    user = result.scalar_one_or_none()
    if not user or not verify_password(payload.senha, user.senha_hash):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Credenciais inválidas",
        )

    token_data = {
        "sub": user.email,
        "tipo": user.tipo,
        "grupo_id": user.grupo_id,
        "grupo_nome": user.grupo_nome,
    }
    token = create_access_token(token_data)

    return LoginOut(
        access_token=token,
        user=UserInfo(
            id=user.id,
            nome=user.nome,
            tipo=user.tipo,
            grupo_id=user.grupo_id,
            grupo_nome=user.grupo_nome,
            must_change_password=user.must_change_password,
            perfis=user.perfis or [user.tipo],
            telefone=user.telefone,
        ),
    )


@app.post("/v1/auth/alterar-senha", response_model=MsgOut)
async def alterar_senha(
    payload: AlterarSenhaIn,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    if not verify_password(payload.senha_atual, current_user.senha_hash):
        raise HTTPException(status_code=400, detail="Senha atual incorreta")
    erro = validar_forca_senha(payload.nova_senha)
    if erro:
        raise HTTPException(status_code=400, detail=erro)

    current_user.senha_hash = hash_password(payload.nova_senha)
    current_user.must_change_password = False
    await db.commit()
    return MsgOut(detail="Senha alterada com sucesso")


@app.get("/health")
async def health():
    return {"status": "ok", "timestamp": datetime.now(timezone.utc).isoformat()}
