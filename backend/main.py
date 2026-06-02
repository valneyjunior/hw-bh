"""
BH Pulse — Backend FastAPI
Ponto de entrada principal.
"""
from datetime import datetime, timezone

from fastapi import Depends, FastAPI, HTTPException, status
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from auth import (
    create_access_token,
    get_current_user,
    hash_password,
    verify_password,
)
from database import get_db
from models import Usuario
from routers.banco_de_horas import router as bh_router

app = FastAPI(
    title="BH Pulse API",
    version="1.0.0",
    description="Sistema de Banco de Horas — Hostweb",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:3001", "http://127.0.0.1:3001"],
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
async def login(payload: LoginIn, db: AsyncSession = Depends(get_db)):
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
    if len(payload.nova_senha) < 6:
        raise HTTPException(status_code=400, detail="Nova senha deve ter ao menos 6 caracteres")

    current_user.senha_hash = hash_password(payload.nova_senha)
    current_user.must_change_password = False
    await db.commit()
    return MsgOut(detail="Senha alterada com sucesso")


@app.get("/health")
async def health():
    return {"status": "ok", "timestamp": datetime.now(timezone.utc).isoformat()}
