"""
Auditoria tamper-evident (não-repúdio).

Cada evento é encadeado por hash (estilo blockchain leve): o hash de um registro
inclui o hash do registro anterior. Adulterar/remover qualquer registro antigo
quebra a cadeia, o que é detectável por `verificar_integridade`.

A escrita usa um advisory lock transacional do PostgreSQL para serializar a
gravação da cadeia e evitar corrida entre operações concorrentes.
"""
from __future__ import annotations

import hashlib
import json
from datetime import datetime, timezone
from typing import Optional

from sqlalchemy import select, text
from sqlalchemy.ext.asyncio import AsyncSession

from models import BhAuditLog

GENESIS = "GENESIS"
_LOCK_KEY = 918273645  # chave fixa do advisory lock da cadeia de auditoria


def client_ip(request) -> Optional[str]:
    """Extrai o IP de origem, respeitando proxy (X-Forwarded-For)."""
    if request is None:
        return None
    fwd = request.headers.get("x-forwarded-for")
    if fwd:
        return fwd.split(",")[0].strip()
    return request.client.host if request.client else None


def _corpo_hash(usuario_id, acao, recurso, recurso_id, ip, detalhes, criado_iso, hash_anterior) -> str:
    corpo = json.dumps(
        {
            "usuario_id": usuario_id,
            "acao": acao,
            "recurso": recurso,
            "recurso_id": recurso_id,
            "ip": ip,
            "detalhes": detalhes,
            "criado_em": criado_iso,
            "hash_anterior": hash_anterior,
        },
        sort_keys=True,
        ensure_ascii=False,
        default=str,
    )
    return hashlib.sha256(corpo.encode("utf-8")).hexdigest()


async def registrar(
    db: AsyncSession,
    *,
    usuario=None,
    acao: str,
    recurso: str,
    recurso_id: Optional[int] = None,
    ip: Optional[str] = None,
    detalhes: Optional[dict] = None,
) -> str:
    """
    Adiciona um evento à trilha (NÃO faz commit — acompanha a transação da operação,
    garantindo atomicidade entre a ação e seu registro). Retorna o hash gravado.
    """
    await db.execute(text("SELECT pg_advisory_xact_lock(:k)").bindparams(k=_LOCK_KEY))

    res = await db.execute(select(BhAuditLog.hash_registro).order_by(BhAuditLog.id.desc()).limit(1))
    hash_anterior = res.scalar_one_or_none() or GENESIS

    criado = datetime.now(timezone.utc)
    usuario_id = getattr(usuario, "id", None)
    h = _corpo_hash(usuario_id, acao, recurso, recurso_id, ip, detalhes, criado.isoformat(), hash_anterior)

    db.add(BhAuditLog(
        usuario_id=usuario_id,
        usuario_nome=getattr(usuario, "nome", None),
        usuario_email=getattr(usuario, "email", None),
        acao=acao,
        recurso=recurso,
        recurso_id=recurso_id,
        ip=ip,
        detalhes=detalhes,
        hash_anterior=hash_anterior,
        hash_registro=h,
        criado_em=criado,
    ))
    await db.flush()
    return h


async def verificar_integridade(db: AsyncSession) -> dict:
    """Recalcula a cadeia inteira e aponta o primeiro registro adulterado, se houver."""
    res = await db.execute(select(BhAuditLog).order_by(BhAuditLog.id))
    logs = res.scalars().all()

    anterior = GENESIS
    for log in logs:
        esperado = _corpo_hash(
            log.usuario_id, log.acao, log.recurso, log.recurso_id, log.ip,
            log.detalhes, log.criado_em.isoformat(), anterior,
        )
        if log.hash_anterior != anterior or log.hash_registro != esperado:
            return {"integro": False, "total": len(logs), "quebrado_no_id": log.id}
        anterior = log.hash_registro

    return {"integro": True, "total": len(logs), "quebrado_no_id": None}
