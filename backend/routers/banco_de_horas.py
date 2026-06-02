"""
Router: /v1/banco-de-horas
Lógica CLT de cálculo por slot horário portada do PHP.
"""
from __future__ import annotations

import math
from datetime import date, datetime, time, timedelta, timezone
from decimal import Decimal, ROUND_HALF_UP
from typing import Optional

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict, field_validator
from sqlalchemy import and_, func, or_, select, text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from auth import get_current_user, require_acionamento, require_permissao_coordenador, require_tipo
from database import get_db
from models import BhConfigUsuario, BhEscala, BhFolga, BhLancamento, BhSetor, Usuario

router = APIRouter(prefix="/v1/banco-de-horas", tags=["banco-de-horas"])


# ══════════════════════════════════════════════════════════════════════════════
# Schemas
# ══════════════════════════════════════════════════════════════════════════════

class SetorOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    nome: str
    total_usuarios: int = 0


class UsuarioOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    nome: str
    email: str
    tipo: str
    grupo_id: Optional[int]
    grupo_nome: Optional[str]
    ativo: bool
    arquivado: bool = False
    must_change_password: bool = False
    perfis: list[str] = []
    telefone: Optional[str] = None
    setores_coordenados: list[int] = []


class ConfigOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    usuario_id: int
    salario_bruto: Decimal
    work_start: time
    work_end: time
    lunch_start: time
    lunch_minutes: int
    adicional_atrativo: Decimal = Decimal("0.00")


class UsuarioComConfigOut(UsuarioOut):
    config: Optional[ConfigOut] = None


class ConfigIn(BaseModel):
    salario_bruto: Decimal
    work_start: time
    work_end: time
    lunch_start: time
    lunch_minutes: int
    adicional_atrativo: Decimal = Decimal("0.00")


class UsuarioCriarIn(BaseModel):
    nome: str
    email: str
    senha: str
    tipo: str = "analista"
    grupo_id: Optional[int] = None
    must_change_password: bool = True
    perfis: Optional[list[str]] = None
    telefone: Optional[str] = None
    setores_coordenados: Optional[list[int]] = None
    # Configuração CLT (criada junto com o usuário)
    salario_bruto: Optional[Decimal] = None
    work_start: time = time(8, 0)
    work_end: time = time(18, 0)
    lunch_start: time = time(12, 0)
    lunch_minutes: int = 60
    adicional_atrativo: Decimal = Decimal("0.00")
    # Envio de e-mail de boas-vindas
    enviar_email: bool = False


class LancamentoOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    usuario_id: int
    data_acionamento: date
    hora_inicio: time
    hora_fim: time
    total_minutos: Optional[int]
    chamado: str
    motivo: str
    feriado: bool
    descricao_feriado: Optional[str]
    status: str
    nota_revisao: Optional[str]
    valor_calculado: Optional[Decimal]
    revisado_por: Optional[int]
    revisado_em: Optional[datetime]
    criado_em: datetime
    usuario_nome: Optional[str] = None
    usuario_email: Optional[str] = None
    # True quando o autor é coordenador → aprovação cabe ao diretor (admin), não a outro coordenador
    requer_aprovacao_diretor: bool = False

    @classmethod
    def from_orm_with_usuario(cls, lanc: BhLancamento) -> "LancamentoOut":
        obj = cls.model_validate(lanc)
        if lanc.usuario:
            obj.usuario_nome = lanc.usuario.nome
            obj.usuario_email = lanc.usuario.email
            perfis = lanc.usuario.perfis or []
            obj.requer_aprovacao_diretor = "coordenador" in perfis and "admin" not in perfis
        return obj


class LancamentoCriarIn(BaseModel):
    data_acionamento: date
    hora_inicio: time
    hora_fim: time
    chamado: str
    motivo: str
    feriado: bool = False
    descricao_feriado: Optional[str] = None

    @field_validator("hora_fim")
    @classmethod
    def fim_apos_inicio(cls, v: time, info) -> time:
        inicio = info.data.get("hora_inicio")
        if inicio and v <= inicio:
            raise ValueError("hora_fim deve ser posterior a hora_inicio")
        return v


class LancamentoEditarIn(BaseModel):
    data_acionamento: Optional[date] = None
    hora_inicio: Optional[time] = None
    hora_fim: Optional[time] = None
    chamado: Optional[str] = None
    motivo: Optional[str] = None
    feriado: Optional[bool] = None
    descricao_feriado: Optional[str] = None


class SaldoOut(BaseModel):
    saldo_minutos: int
    aprovados: int
    pendentes: int
    recusados: int


class RecusarIn(BaseModel):
    nota_revisao: str


class RelatorioKpiOut(BaseModel):
    total_acionamentos: int
    horas_aprovadas: float
    custo_clt_total: Decimal
    media_por_acionamento: float
    por_tipo: dict[str, dict]
    por_mes: list[dict]
    colaboradores: list[dict]


class RelatorioColaboradorOut(BaseModel):
    usuario: UsuarioOut
    config: Optional[ConfigOut]
    kpis: dict
    por_mes: list[dict]
    lancamentos: list[LancamentoOut]


# ══════════════════════════════════════════════════════════════════════════════
# Lógica CLT — cálculo por slot de 1h
# ══════════════════════════════════════════════════════════════════════════════

def _tipo_from_perfis(perfis: list[str]) -> str:
    if "admin" in perfis:
        return "admin"
    if "coordenador" in perfis:
        return "coordenador"
    if "atendimento" in perfis:
        return "atendimento"
    return "analista"


def _autor_requer_diretor(lanc: BhLancamento) -> bool:
    """True se o autor do lançamento é coordenador (não-admin) → só o diretor (admin) aprova."""
    perfis = (lanc.usuario.perfis or []) if lanc.usuario else []
    return "coordenador" in perfis and "admin" not in perfis


def _setores_coordenados(user: Usuario) -> list[int]:
    """
    Setores que o coordenador coordena/valida. Usa `setores_coordenados`; se vazio,
    faz fallback para o setor de lotação (`grupo_id`) por retrocompatibilidade.
    """
    setores = list(user.setores_coordenados or [])
    if not setores and user.grupo_id is not None:
        setores = [user.grupo_id]
    return setores


def _time_to_minutes(t: time) -> int:
    return t.hour * 60 + t.minute


def _is_fim_de_semana_ou_feriado(d: date, feriado: bool) -> bool:
    return d.weekday() == 6 or feriado  # 6 = domingo


def _is_sabado(d: date) -> bool:
    return d.weekday() == 5


ADICIONAL_NOTURNO = 1.20  # +20% sobre a hora (CLT Art. 73)


def _multiplicador_slot(slot_inicio_min: int, data: date, feriado: bool) -> float:
    """
    Retorna o multiplicador CLT para um slot de 1h a partir de slot_inicio_min.
    Horários:
      - Diurno: 05h (300min) a 22h (1320min)
      - Noturno: < 300min ou >= 1320min
    Regra: o adicional noturno (+20%) é aplicado de forma MULTIPLICATIVA sobre o
    multiplicador-base da hora extra, de forma coerente em todos os dias:
      - Diurno seg-sáb:          1.50   | Noturno: 1.50 × 1.20 = 1.80
      - Diurno domingo/feriado:  2.00   | Noturno: 2.00 × 1.20 = 2.40
    Sábado é tratado como dia útil (DSR legal é o domingo).
    """
    diurno = 300 <= slot_inicio_min < 1320  # 05h–22h
    fim_de_semana = _is_fim_de_semana_ou_feriado(data, feriado)

    base = 2.00 if fim_de_semana else 1.50
    return base if diurno else round(base * ADICIONAL_NOTURNO, 4)


def calc_valor_lancamento(lanc: BhLancamento, salario_bruto: float) -> float:
    """
    Calcula valor a pagar por lançamento conforme CLT.
    Itera slot a slot de 1h para precisão em cruzamentos de período.
    Base horária: salario_bruto / 220.0

    Cruzamento de meia-noite: cada slot é avaliado com a DATA REAL (a data avança
    um dia ao passar de 24h), de modo que a virada para domingo/feriado paga o
    multiplicador correto. O flag `feriado` do lançamento aplica-se ao dia de
    início; dias seguintes são avaliados pelo dia da semana da data real.
    """
    if salario_bruto <= 0:
        return 0.0

    hora_base = salario_bruto / 220.0

    inicio_min = _time_to_minutes(lanc.hora_inicio)
    fim_min = _time_to_minutes(lanc.hora_fim)

    # Suporte a turnos que cruzam meia-noite
    if fim_min <= inicio_min:
        fim_min += 24 * 60

    total_valor = 0.0
    cursor = inicio_min

    while cursor < fim_min:
        proximo = min(cursor + 60, fim_min)
        fracao = (proximo - cursor) / 60.0  # fração da hora

        # Data real do slot: avança o dia a cada 24h ultrapassadas
        dias_offset = cursor // (24 * 60)
        data_slot = lanc.data_acionamento + timedelta(days=dias_offset)
        # O flag feriado só vale para o dia de início (offset 0)
        feriado_slot = lanc.feriado if dias_offset == 0 else False

        slot_no_dia = cursor % (24 * 60)
        mult = _multiplicador_slot(slot_no_dia, data_slot, feriado_slot)

        total_valor += hora_base * mult * fracao
        cursor = proximo

    return round(total_valor, 2)


def calc_total_minutos(hora_inicio: time, hora_fim: time) -> int:
    ini = _time_to_minutes(hora_inicio)
    fim = _time_to_minutes(hora_fim)
    if fim <= ini:
        fim += 24 * 60
    return fim - ini


def _tipo_lancamento(lanc: BhLancamento) -> str:
    ini = _time_to_minutes(lanc.hora_inicio)
    fim = _time_to_minutes(lanc.hora_fim)
    if fim <= ini:
        fim += 24 * 60

    if _is_fim_de_semana_ou_feriado(lanc.data_acionamento, lanc.feriado):
        if lanc.feriado:
            return "feriado"
        return "domingo"

    if _is_sabado(lanc.data_acionamento):
        return "sabado"

    # Verificar se tem parte noturna (antes 05h ou após 22h)
    noturno = ini < 300 or fim > 1320
    return "noturno" if noturno else "diurno"


# ══════════════════════════════════════════════════════════════════════════════
# Helpers de query
# ══════════════════════════════════════════════════════════════════════════════

async def _get_config(db: AsyncSession, usuario_id: int) -> Optional[BhConfigUsuario]:
    result = await db.execute(
        select(BhConfigUsuario).where(BhConfigUsuario.usuario_id == usuario_id)
    )
    return result.scalar_one_or_none()


# ══════════════════════════════════════════════════════════════════════════════
# Endpoints — Analista
# ══════════════════════════════════════════════════════════════════════════════

@router.get("/meus-lancamentos", response_model=list[LancamentoOut])
async def meus_lancamentos(
    from_date: Optional[date] = Query(None, alias="from"),
    to_date: Optional[date] = Query(None, alias="to"),
    status_filter: Optional[str] = Query(None, alias="status"),
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    q = select(BhLancamento).options(selectinload(BhLancamento.usuario)).where(
        BhLancamento.usuario_id == current_user.id
    )
    if from_date:
        q = q.where(BhLancamento.data_acionamento >= from_date)
    if to_date:
        q = q.where(BhLancamento.data_acionamento <= to_date)
    if status_filter:
        q = q.where(BhLancamento.status == status_filter)
    q = q.order_by(BhLancamento.data_acionamento.desc(), BhLancamento.hora_inicio.desc())
    result = await db.execute(q)
    return [LancamentoOut.from_orm_with_usuario(r) for r in result.scalars().all()]


@router.get("/saldo", response_model=SaldoOut)
async def meu_saldo(
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    result = await db.execute(
        select(BhLancamento.status, func.sum(BhLancamento.total_minutos))
        .where(BhLancamento.usuario_id == current_user.id)
        .group_by(BhLancamento.status)
    )
    rows = result.all()
    saldo = 0
    aprovados = 0
    pendentes = 0
    recusados = 0
    for st, mins in rows:
        m = int(mins or 0)
        if st == "aprovado":
            saldo += m
            aprovados += 1
        elif st == "pendente":
            pendentes += 1
        elif st == "recusado":
            recusados += 1

    # Contar aprovados corretamente
    count_result = await db.execute(
        select(BhLancamento.status, func.count(BhLancamento.id))
        .where(BhLancamento.usuario_id == current_user.id)
        .group_by(BhLancamento.status)
    )
    counts = {r[0]: r[1] for r in count_result.all()}
    return SaldoOut(
        saldo_minutos=saldo,
        aprovados=counts.get("aprovado", 0),
        pendentes=counts.get("pendente", 0),
        recusados=counts.get("recusado", 0),
    )


@router.post("/lancamentos", response_model=LancamentoOut, status_code=status.HTTP_201_CREATED)
async def criar_lancamento(
    payload: LancamentoCriarIn,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    total = calc_total_minutos(payload.hora_inicio, payload.hora_fim)
    lanc = BhLancamento(
        usuario_id=current_user.id,
        data_acionamento=payload.data_acionamento,
        hora_inicio=payload.hora_inicio,
        hora_fim=payload.hora_fim,
        total_minutos=total,
        chamado=payload.chamado,
        motivo=payload.motivo,
        feriado=payload.feriado,
        descricao_feriado=payload.descricao_feriado,
        status="pendente",
    )
    db.add(lanc)
    await db.commit()
    await db.refresh(lanc)
    await db.refresh(lanc, ["usuario"])
    return LancamentoOut.from_orm_with_usuario(lanc)


@router.put("/lancamentos/{lancamento_id}", response_model=LancamentoOut)
async def editar_lancamento(
    lancamento_id: int,
    payload: LancamentoEditarIn,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    result = await db.execute(
        select(BhLancamento).options(selectinload(BhLancamento.usuario))
        .where(BhLancamento.id == lancamento_id, BhLancamento.usuario_id == current_user.id)
    )
    lanc = result.scalar_one_or_none()
    if not lanc:
        raise HTTPException(status_code=404, detail="Lançamento não encontrado")
    if lanc.status not in ("pendente", "contestado"):
        raise HTTPException(status_code=400, detail="Apenas lançamentos pendentes ou contestados podem ser editados")

    era_contestado = lanc.status == "contestado"

    data = payload.model_dump(exclude_none=True)
    for k, v in data.items():
        setattr(lanc, k, v)

    # Recalcular minutos se horários foram alterados
    if "hora_inicio" in data or "hora_fim" in data:
        lanc.total_minutos = calc_total_minutos(lanc.hora_inicio, lanc.hora_fim)

    # Se estava contestado, volta para pendente e limpa a nota
    if era_contestado:
        lanc.status = "pendente"
        lanc.nota_revisao = None
        lanc.revisado_por = None
        lanc.revisado_em = None

    await db.commit()
    await db.refresh(lanc)
    return LancamentoOut.from_orm_with_usuario(lanc)


@router.delete("/lancamentos/{lancamento_id}", status_code=status.HTTP_204_NO_CONTENT)
async def cancelar_lancamento(
    lancamento_id: int,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    result = await db.execute(
        select(BhLancamento).where(
            BhLancamento.id == lancamento_id, BhLancamento.usuario_id == current_user.id
        )
    )
    lanc = result.scalar_one_or_none()
    if not lanc:
        raise HTTPException(status_code=404, detail="Lançamento não encontrado")
    if lanc.status != "pendente":
        raise HTTPException(status_code=400, detail="Apenas lançamentos pendentes podem ser cancelados")
    await db.delete(lanc)
    await db.commit()


# ══════════════════════════════════════════════════════════════════════════════
# Endpoints — Coordenador / Admin
# ══════════════════════════════════════════════════════════════════════════════

class LancamentosPage(BaseModel):
    items: list[LancamentoOut]
    total: int
    page: int
    per_page: int


@router.get("/admin/lancamentos", response_model=LancamentosPage)
async def admin_lancamentos(
    from_date: Optional[date] = Query(None, alias="from"),
    to_date: Optional[date] = Query(None, alias="to"),
    status_filter: Optional[str] = Query(None, alias="status"),
    usuario_id: Optional[int] = None,
    page: Optional[int] = Query(None, ge=1),
    per_page: int = Query(20, ge=1, le=100),
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_permissao_coordenador()),
):
    """
    Retorna um envelope paginado {items, total, page, per_page}.
    Se `page` for omitido, retorna TODOS os itens (total = contagem) — usado por telas
    que paginam no cliente ou precisam do conjunto completo.
    """
    q = select(BhLancamento).options(selectinload(BhLancamento.usuario))

    if current_user.tipo == "coordenador":
        # Coordenador vê os colaboradores dos setores que coordena
        result = await db.execute(
            select(Usuario.id).where(Usuario.grupo_id.in_(_setores_coordenados(current_user)))
        )
        ids_grupo = [r[0] for r in result.all()]
        q = q.where(BhLancamento.usuario_id.in_(ids_grupo))

    if usuario_id:
        q = q.where(BhLancamento.usuario_id == usuario_id)
    if from_date:
        q = q.where(BhLancamento.data_acionamento >= from_date)
    if to_date:
        q = q.where(BhLancamento.data_acionamento <= to_date)
    if status_filter:
        q = q.where(BhLancamento.status == status_filter)

    total = (await db.execute(select(func.count()).select_from(q.subquery()))).scalar_one()

    q = q.order_by(BhLancamento.data_acionamento.desc(), BhLancamento.hora_inicio.desc())
    if page is not None:
        q = q.offset((page - 1) * per_page).limit(per_page)

    result = await db.execute(q)
    items = [LancamentoOut.from_orm_with_usuario(r) for r in result.scalars().all()]
    return LancamentosPage(items=items, total=total, page=page or 1, per_page=per_page)


@router.post("/admin/lancamentos/{lancamento_id}/aprovar", response_model=LancamentoOut)
async def aprovar_lancamento(
    lancamento_id: int,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_permissao_coordenador()),
):
    result = await db.execute(
        select(BhLancamento).options(selectinload(BhLancamento.usuario))
        .where(BhLancamento.id == lancamento_id)
    )
    lanc = result.scalar_one_or_none()
    if not lanc:
        raise HTTPException(status_code=404, detail="Lançamento não encontrado")
    if lanc.status != "pendente":
        raise HTTPException(status_code=400, detail="Lançamento não está pendente")

    # Verificar permissão de coordenador sobre o usuário
    if current_user.tipo == "coordenador":
        user_result = await db.execute(select(Usuario).where(Usuario.id == lanc.usuario_id))
        owner = user_result.scalar_one_or_none()
        if not owner or owner.grupo_id not in _setores_coordenados(current_user):
            raise HTTPException(status_code=403, detail="Sem permissão sobre este lançamento")
        if _autor_requer_diretor(lanc):
            raise HTTPException(status_code=403, detail="Lançamento de coordenador requer aprovação do diretor (admin)")

    # Calcular valor CLT
    config = await _get_config(db, lanc.usuario_id)
    salario = float(config.salario_bruto) if config else 0.0
    valor = calc_valor_lancamento(lanc, salario)

    lanc.status = "aprovado"
    lanc.valor_calculado = Decimal(str(valor))
    lanc.revisado_por = current_user.id
    lanc.revisado_em = datetime.now(timezone.utc)
    lanc.total_minutos = calc_total_minutos(lanc.hora_inicio, lanc.hora_fim)

    await db.commit()
    await db.refresh(lanc)
    return LancamentoOut.from_orm_with_usuario(lanc)


@router.post("/admin/lancamentos/{lancamento_id}/recusar", response_model=LancamentoOut)
async def recusar_lancamento(
    lancamento_id: int,
    payload: RecusarIn,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_permissao_coordenador()),
):
    result = await db.execute(
        select(BhLancamento).options(selectinload(BhLancamento.usuario))
        .where(BhLancamento.id == lancamento_id)
    )
    lanc = result.scalar_one_or_none()
    if not lanc:
        raise HTTPException(status_code=404, detail="Lançamento não encontrado")
    if lanc.status != "pendente":
        raise HTTPException(status_code=400, detail="Lançamento não está pendente")

    if current_user.tipo == "coordenador":
        user_result = await db.execute(select(Usuario).where(Usuario.id == lanc.usuario_id))
        owner = user_result.scalar_one_or_none()
        if not owner or owner.grupo_id not in _setores_coordenados(current_user):
            raise HTTPException(status_code=403, detail="Sem permissão sobre este lançamento")
        if _autor_requer_diretor(lanc):
            raise HTTPException(status_code=403, detail="Lançamento de coordenador requer aprovação do diretor (admin)")

    lanc.status = "recusado"
    lanc.nota_revisao = payload.nota_revisao
    lanc.revisado_por = current_user.id
    lanc.revisado_em = datetime.now(timezone.utc)

    await db.commit()
    await db.refresh(lanc)
    return LancamentoOut.from_orm_with_usuario(lanc)


@router.post("/admin/lancamentos/{lancamento_id}/contestar", response_model=LancamentoOut)
async def contestar_lancamento(
    lancamento_id: int,
    payload: RecusarIn,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_permissao_coordenador()),
):
    result = await db.execute(
        select(BhLancamento).options(selectinload(BhLancamento.usuario))
        .where(BhLancamento.id == lancamento_id)
    )
    lanc = result.scalar_one_or_none()
    if not lanc:
        raise HTTPException(status_code=404, detail="Lançamento não encontrado")
    if lanc.status != "pendente":
        raise HTTPException(status_code=400, detail="Lançamento não está pendente")

    if current_user.tipo == "coordenador":
        user_result = await db.execute(select(Usuario).where(Usuario.id == lanc.usuario_id))
        owner = user_result.scalar_one_or_none()
        if not owner or owner.grupo_id not in _setores_coordenados(current_user):
            raise HTTPException(status_code=403, detail="Sem permissão sobre este lançamento")
        if _autor_requer_diretor(lanc):
            raise HTTPException(status_code=403, detail="Lançamento de coordenador requer aprovação do diretor (admin)")

    lanc.status = "contestado"
    lanc.nota_revisao = payload.nota_revisao
    lanc.revisado_por = current_user.id
    lanc.revisado_em = datetime.now(timezone.utc)

    await db.commit()
    await db.refresh(lanc)
    return LancamentoOut.from_orm_with_usuario(lanc)


@router.get("/admin/relatorio", response_model=RelatorioKpiOut)
async def admin_relatorio(
    from_date: Optional[date] = Query(None, alias="from"),
    to_date: Optional[date] = Query(None, alias="to"),
    grupo_id: Optional[int] = None,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_permissao_coordenador()),
):
    # Filtrar usuários
    user_q = select(Usuario).options(selectinload(Usuario.config))
    if current_user.tipo == "coordenador":
        user_q = user_q.where(Usuario.grupo_id.in_(_setores_coordenados(current_user)))
    elif grupo_id:
        user_q = user_q.where(Usuario.grupo_id == grupo_id)
    user_result = await db.execute(user_q)
    usuarios = user_result.scalars().all()
    user_ids = [u.id for u in usuarios]
    user_map = {u.id: u for u in usuarios}

    # Buscar lançamentos aprovados
    lanc_q = (
        select(BhLancamento)
        .options(selectinload(BhLancamento.usuario))
        .where(BhLancamento.usuario_id.in_(user_ids), BhLancamento.status == "aprovado")
    )
    if from_date:
        lanc_q = lanc_q.where(BhLancamento.data_acionamento >= from_date)
    if to_date:
        lanc_q = lanc_q.where(BhLancamento.data_acionamento <= to_date)
    lanc_result = await db.execute(lanc_q)
    lancamentos = lanc_result.scalars().all()

    total_acionamentos = len(lancamentos)
    total_minutos = sum(l.total_minutos or 0 for l in lancamentos)
    horas_aprovadas = total_minutos / 60.0
    custo_total = sum(float(l.valor_calculado or 0) for l in lancamentos)
    media = (total_minutos / 60.0 / total_acionamentos) if total_acionamentos else 0.0

    # Por tipo
    tipos = {"diurno": 0, "noturno": 0, "sabado": 0, "domingo": 0, "feriado": 0}
    tipos_valor: dict[str, float] = {k: 0.0 for k in tipos}
    for l in lancamentos:
        t = _tipo_lancamento(l)
        tipos[t] = tipos.get(t, 0) + (l.total_minutos or 0)
        tipos_valor[t] = tipos_valor.get(t, 0.0) + float(l.valor_calculado or 0)

    por_tipo = {
        k: {"minutos": v, "horas": round(v / 60, 2), "valor": round(tipos_valor[k], 2)}
        for k, v in tipos.items()
    }

    # Por mês
    meses: dict[str, dict] = {}
    for l in lancamentos:
        mes_key = l.data_acionamento.strftime("%Y-%m")
        if mes_key not in meses:
            meses[mes_key] = {"mes": mes_key, "minutos": 0, "valor": 0.0, "acionamentos": 0}
        meses[mes_key]["minutos"] += l.total_minutos or 0
        meses[mes_key]["valor"] += float(l.valor_calculado or 0)
        meses[mes_key]["acionamentos"] += 1
    por_mes = sorted(meses.values(), key=lambda x: x["mes"])
    for m in por_mes:
        m["horas"] = round(m["minutos"] / 60, 2)
        m["valor"] = round(m["valor"], 2)

    # Por colaborador
    colab: dict[int, dict] = {}
    for l in lancamentos:
        uid = l.usuario_id
        u = user_map.get(uid)
        if uid not in colab:
            colab[uid] = {
                "usuario_id": uid,
                "nome": u.nome if u else str(uid),
                "grupo_nome": u.grupo_nome if u else None,
                "minutos": 0,
                "valor": 0.0,
                "acionamentos": 0,
            }
        colab[uid]["minutos"] += l.total_minutos or 0
        colab[uid]["valor"] += float(l.valor_calculado or 0)
        colab[uid]["acionamentos"] += 1
    colaboradores = sorted(colab.values(), key=lambda x: x["minutos"], reverse=True)
    for c in colaboradores:
        c["horas"] = round(c["minutos"] / 60, 2)
        c["valor"] = round(c["valor"], 2)

    return RelatorioKpiOut(
        total_acionamentos=total_acionamentos,
        horas_aprovadas=round(horas_aprovadas, 2),
        custo_clt_total=Decimal(str(round(custo_total, 2))),
        media_por_acionamento=round(media, 2),
        por_tipo=por_tipo,
        por_mes=por_mes,
        colaboradores=colaboradores,
    )


@router.get("/admin/relatorio/{usuario_id}", response_model=RelatorioColaboradorOut)
async def admin_relatorio_colaborador(
    usuario_id: int,
    from_date: Optional[date] = Query(None, alias="from"),
    to_date: Optional[date] = Query(None, alias="to"),
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_permissao_coordenador()),
):
    result = await db.execute(
        select(Usuario).options(selectinload(Usuario.config))
        .where(Usuario.id == usuario_id)
    )
    usuario = result.scalar_one_or_none()
    if not usuario:
        raise HTTPException(status_code=404, detail="Usuário não encontrado")

    if current_user.tipo == "coordenador" and usuario.grupo_id not in _setores_coordenados(current_user):
        raise HTTPException(status_code=403, detail="Sem acesso a este colaborador")

    lanc_q = (
        select(BhLancamento)
        .options(selectinload(BhLancamento.usuario))
        .where(BhLancamento.usuario_id == usuario_id, BhLancamento.status == "aprovado")
    )
    if from_date:
        lanc_q = lanc_q.where(BhLancamento.data_acionamento >= from_date)
    if to_date:
        lanc_q = lanc_q.where(BhLancamento.data_acionamento <= to_date)
    lanc_result = await db.execute(lanc_q)
    lancamentos = lanc_result.scalars().all()

    total_min = sum(l.total_minutos or 0 for l in lancamentos)
    custo = sum(float(l.valor_calculado or 0) for l in lancamentos)

    # Por mês
    meses: dict[str, dict] = {}
    for l in lancamentos:
        mk = l.data_acionamento.strftime("%Y-%m")
        if mk not in meses:
            meses[mk] = {"mes": mk, "minutos": 0, "valor": 0.0, "acionamentos": 0}
        meses[mk]["minutos"] += l.total_minutos or 0
        meses[mk]["valor"] += float(l.valor_calculado or 0)
        meses[mk]["acionamentos"] += 1
    por_mes = sorted(meses.values(), key=lambda x: x["mes"])
    for m in por_mes:
        m["horas"] = round(m["minutos"] / 60, 2)
        m["valor"] = round(m["valor"], 2)

    return RelatorioColaboradorOut(
        usuario=UsuarioOut.model_validate(usuario),
        config=ConfigOut.model_validate(usuario.config) if usuario.config else None,
        kpis={
            "total_acionamentos": len(lancamentos),
            "horas_aprovadas": round(total_min / 60, 2),
            "custo_clt_total": round(custo, 2),
        },
        por_mes=por_mes,
        lancamentos=[LancamentoOut.from_orm_with_usuario(l) for l in lancamentos],
    )


# ══════════════════════════════════════════════════════════════════════════════
# Endpoints — Admin
# ══════════════════════════════════════════════════════════════════════════════

@router.get("/admin/usuarios", response_model=list[UsuarioComConfigOut])
async def admin_usuarios(
    filtro: Optional[str] = Query(None),  # "ativos" | "inativos" | "arquivados"
    db: AsyncSession = Depends(get_db),
    _: Usuario = Depends(require_tipo("admin")),
):
    q = select(Usuario).options(selectinload(Usuario.config)).order_by(Usuario.nome)
    if filtro == "inativos":
        q = q.where(Usuario.ativo == False, Usuario.arquivado == False)
    elif filtro == "arquivados":
        q = q.where(Usuario.arquivado == True)
    else:
        q = q.where(Usuario.ativo == True, Usuario.arquivado == False)
    result = await db.execute(q)
    return [UsuarioComConfigOut.model_validate(u) for u in result.scalars().all()]


@router.put("/admin/usuarios/{usuario_id}/config", response_model=ConfigOut)
async def salvar_config_usuario(
    usuario_id: int,
    payload: ConfigIn,
    db: AsyncSession = Depends(get_db),
    _: Usuario = Depends(require_tipo("admin")),
):
    config = await _get_config(db, usuario_id)
    if config:
        for k, v in payload.model_dump().items():
            setattr(config, k, v)
    else:
        config = BhConfigUsuario(usuario_id=usuario_id, **payload.model_dump())
        db.add(config)
    await db.commit()
    await db.refresh(config)
    return ConfigOut.model_validate(config)


@router.post("/admin/usuarios", response_model=UsuarioOut, status_code=status.HTTP_201_CREATED)
async def criar_usuario(
    payload: UsuarioCriarIn,
    db: AsyncSession = Depends(get_db),
    _: Usuario = Depends(require_tipo("admin")),
):
    from auth import hash_password
    from email_service import enviar_acesso

    exist = await db.execute(select(Usuario).where(Usuario.email == payload.email))
    if exist.scalar_one_or_none():
        raise HTTPException(status_code=409, detail="E-mail já cadastrado")

    grupo_nome = None
    if payload.grupo_id:
        setor_result = await db.execute(select(BhSetor).where(BhSetor.id == payload.grupo_id))
        setor = setor_result.scalar_one_or_none()
        if setor:
            grupo_nome = setor.nome

    perfis = payload.perfis if payload.perfis else [payload.tipo]
    tipo = _tipo_from_perfis(perfis)
    user = Usuario(
        nome=payload.nome,
        email=payload.email,
        senha_hash=hash_password(payload.senha),
        tipo=tipo,
        grupo_id=payload.grupo_id,
        grupo_nome=grupo_nome,
        must_change_password=payload.must_change_password,
        perfis=perfis,
        telefone=payload.telefone,
        setores_coordenados=payload.setores_coordenados or [],
    )
    db.add(user)
    await db.flush()  # obtém user.id antes do commit

    # Sempre cria config com os valores fornecidos (ou defaults)
    from models import BhConfigUsuario
    config = BhConfigUsuario(
        usuario_id=user.id,
        salario_bruto=payload.salario_bruto or Decimal("0.00"),
        work_start=payload.work_start,
        work_end=payload.work_end,
        lunch_start=payload.lunch_start,
        lunch_minutes=payload.lunch_minutes,
        adicional_atrativo=payload.adicional_atrativo,
    )
    db.add(config)
    await db.commit()
    await db.refresh(user)

    if payload.enviar_email:
        await enviar_acesso(user.nome, user.email, payload.senha)

    return UsuarioOut.model_validate(user)


@router.get("/admin/setores", response_model=list[SetorOut])
async def admin_setores(
    db: AsyncSession = Depends(get_db),
    _: Usuario = Depends(require_permissao_coordenador()),
):
    subq = (
        select(Usuario.grupo_id, func.count(Usuario.id).label("total"))
        .where(Usuario.arquivado == False)
        .group_by(Usuario.grupo_id)
        .subquery()
    )
    result = await db.execute(
        select(BhSetor, func.coalesce(subq.c.total, 0).label("total_usuarios"))
        .outerjoin(subq, BhSetor.id == subq.c.grupo_id)
        .order_by(BhSetor.nome)
    )
    return [
        SetorOut(id=s.id, nome=s.nome, total_usuarios=int(total))
        for s, total in result.all()
    ]


# ── Schemas adicionais ────────────────────────────────────────────────────────

class SetorCriarIn(BaseModel):
    nome: str


class UsuarioEditarIn(BaseModel):
    nome: str
    email: str
    tipo: str
    grupo_id: Optional[int] = None
    ativo: bool
    perfis: Optional[list[str]] = None
    telefone: Optional[str] = None
    setores_coordenados: Optional[list[int]] = None


class EscalaItemOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    data_disponivel: date
    turno: str
    observacao: Optional[str] = None


class EscalaCriarIn(BaseModel):
    data_disponivel: date
    turno: str
    observacao: Optional[str] = None




# ── Setores CRUD (admin) ──────────────────────────────────────────────────────

@router.post("/admin/setores", response_model=SetorOut, status_code=status.HTTP_201_CREATED)
async def criar_setor(
    payload: SetorCriarIn,
    db: AsyncSession = Depends(get_db),
    _: Usuario = Depends(require_tipo("admin")),
):
    exist = await db.execute(select(BhSetor).where(BhSetor.nome == payload.nome))
    if exist.scalar_one_or_none():
        raise HTTPException(status_code=409, detail="Setor já existe com este nome")
    setor = BhSetor(nome=payload.nome)
    db.add(setor)
    await db.commit()
    await db.refresh(setor)
    return SetorOut.model_validate(setor)


@router.put("/admin/setores/{setor_id}", response_model=SetorOut)
async def editar_setor(
    setor_id: int,
    payload: SetorCriarIn,
    db: AsyncSession = Depends(get_db),
    _: Usuario = Depends(require_tipo("admin")),
):
    result = await db.execute(select(BhSetor).where(BhSetor.id == setor_id))
    setor = result.scalar_one_or_none()
    if not setor:
        raise HTTPException(status_code=404, detail="Setor não encontrado")
    setor.nome = payload.nome
    await db.commit()
    await db.refresh(setor)
    return SetorOut(id=setor.id, nome=setor.nome, total_usuarios=0)


@router.delete("/admin/setores/{setor_id}", status_code=status.HTTP_204_NO_CONTENT)
async def deletar_setor(
    setor_id: int,
    db: AsyncSession = Depends(get_db),
    _: Usuario = Depends(require_tipo("admin")),
):
    result = await db.execute(select(BhSetor).where(BhSetor.id == setor_id))
    setor = result.scalar_one_or_none()
    if not setor:
        raise HTTPException(status_code=404, detail="Setor não encontrado")
    count_result = await db.execute(
        select(func.count(Usuario.id)).where(Usuario.grupo_id == setor_id, Usuario.arquivado == False)
    )
    total = count_result.scalar_one()
    if total > 0:
        raise HTTPException(status_code=409, detail=f"Setor possui {total} colaborador(es) vinculado(s)")
    await db.delete(setor)
    await db.commit()


# ── Usuários CRUD (admin) ─────────────────────────────────────────────────────

@router.put("/admin/usuarios/{usuario_id}", response_model=UsuarioOut)
async def editar_usuario(
    usuario_id: int,
    payload: UsuarioEditarIn,
    db: AsyncSession = Depends(get_db),
    _: Usuario = Depends(require_tipo("admin")),
):
    result = await db.execute(select(Usuario).where(Usuario.id == usuario_id))
    usuario = result.scalar_one_or_none()
    if not usuario:
        raise HTTPException(status_code=404, detail="Usuário não encontrado")

    usuario.nome = payload.nome
    usuario.email = payload.email
    usuario.grupo_id = payload.grupo_id
    usuario.ativo = payload.ativo
    usuario.telefone = payload.telefone
    if payload.setores_coordenados is not None:
        usuario.setores_coordenados = payload.setores_coordenados

    if payload.perfis:
        usuario.perfis = payload.perfis
        usuario.tipo = _tipo_from_perfis(payload.perfis)
    else:
        usuario.tipo = payload.tipo

    if payload.grupo_id:
        setor_result = await db.execute(select(BhSetor).where(BhSetor.id == payload.grupo_id))
        setor = setor_result.scalar_one_or_none()
        usuario.grupo_nome = setor.nome if setor else None
    else:
        usuario.grupo_nome = None

    await db.commit()
    await db.refresh(usuario)
    return UsuarioOut.model_validate(usuario)


class ResetarSenhaIn(BaseModel):
    nova_senha: str
    enviar_email: bool = False


@router.post("/admin/usuarios/{usuario_id}/resetar-senha", response_model=UsuarioOut)
async def resetar_senha_usuario(
    usuario_id: int,
    payload: ResetarSenhaIn,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_tipo("admin")),
):
    from auth import hash_password
    from email_service import enviar_reset_senha
    result = await db.execute(select(Usuario).where(Usuario.id == usuario_id))
    usuario = result.scalar_one_or_none()
    if not usuario:
        raise HTTPException(status_code=404, detail="Usuário não encontrado")
    usuario.senha_hash = hash_password(payload.nova_senha)
    usuario.must_change_password = True
    await db.commit()
    await db.refresh(usuario)

    if payload.enviar_email:
        await enviar_reset_senha(usuario.nome, usuario.email, payload.nova_senha)

    return UsuarioOut.model_validate(usuario)


@router.post("/admin/usuarios/{usuario_id}/desativar", response_model=UsuarioOut)
async def desativar_usuario(
    usuario_id: int,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_tipo("admin")),
):
    if usuario_id == current_user.id:
        raise HTTPException(status_code=400, detail="Não é possível desativar sua própria conta")
    result = await db.execute(select(Usuario).where(Usuario.id == usuario_id))
    usuario = result.scalar_one_or_none()
    if not usuario:
        raise HTTPException(status_code=404, detail="Usuário não encontrado")
    usuario.ativo = not usuario.ativo  # toggle ativar/desativar
    await db.commit()
    await db.refresh(usuario)
    return UsuarioOut.model_validate(usuario)


@router.post("/admin/usuarios/{usuario_id}/arquivar", response_model=UsuarioOut)
async def arquivar_usuario(
    usuario_id: int,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_tipo("admin")),
):
    if usuario_id == current_user.id:
        raise HTTPException(status_code=400, detail="Não é possível arquivar sua própria conta")
    result = await db.execute(select(Usuario).where(Usuario.id == usuario_id))
    usuario = result.scalar_one_or_none()
    if not usuario:
        raise HTTPException(status_code=404, detail="Usuário não encontrado")
    usuario.arquivado = True
    usuario.ativo = False
    await db.commit()
    await db.refresh(usuario)
    return UsuarioOut.model_validate(usuario)


@router.post("/admin/usuarios/{usuario_id}/restaurar", response_model=UsuarioOut)
async def restaurar_usuario(
    usuario_id: int,
    db: AsyncSession = Depends(get_db),
    _: Usuario = Depends(require_tipo("admin")),
):
    result = await db.execute(select(Usuario).where(Usuario.id == usuario_id))
    usuario = result.scalar_one_or_none()
    if not usuario:
        raise HTTPException(status_code=404, detail="Usuário não encontrado")
    usuario.arquivado = False
    usuario.ativo = True
    await db.commit()
    await db.refresh(usuario)
    return UsuarioOut.model_validate(usuario)


# ── Escala de disponibilidade (analista) ──────────────────────────────────────

@router.get("/escala", response_model=list[EscalaItemOut])
async def listar_escala(
    from_date: Optional[date] = Query(None, alias="from"),
    to_date: Optional[date] = Query(None, alias="to"),
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    from models import BhEscala as BhEscalaModel
    q = select(BhEscalaModel).where(BhEscalaModel.usuario_id == current_user.id)
    if from_date:
        q = q.where(BhEscalaModel.data_disponivel >= from_date)
    if to_date:
        q = q.where(BhEscalaModel.data_disponivel <= to_date)
    q = q.order_by(BhEscalaModel.data_disponivel)
    result = await db.execute(q)
    return [EscalaItemOut.model_validate(e) for e in result.scalars().all()]


@router.post("/escala", response_model=EscalaItemOut, status_code=status.HTTP_201_CREATED)
async def criar_escala(
    payload: EscalaCriarIn,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    from models import BhEscala as BhEscalaModel
    # Remover entrada existente na mesma data para upsert simples
    exist_result = await db.execute(
        select(BhEscalaModel).where(
            BhEscalaModel.usuario_id == current_user.id,
            BhEscalaModel.data_disponivel == payload.data_disponivel,
        )
    )
    exist = exist_result.scalar_one_or_none()
    if exist:
        await db.delete(exist)
        await db.flush()

    escala = BhEscalaModel(
        usuario_id=current_user.id,
        data_disponivel=payload.data_disponivel,
        turno=payload.turno,
        observacao=payload.observacao,
    )
    db.add(escala)
    await db.commit()
    await db.refresh(escala)
    return EscalaItemOut.model_validate(escala)


@router.delete("/escala/{escala_id}", status_code=status.HTTP_204_NO_CONTENT)
async def deletar_escala(
    escala_id: int,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    from models import BhEscala as BhEscalaModel
    result = await db.execute(
        select(BhEscalaModel).where(
            BhEscalaModel.id == escala_id,
            BhEscalaModel.usuario_id == current_user.id,
        )
    )
    escala = result.scalar_one_or_none()
    if not escala:
        raise HTTPException(status_code=404, detail="Registro de escala não encontrado")
    await db.delete(escala)
    await db.commit()


# ── Escala do setor (coordenador/admin) ──────────────────────────────────────

class EscalaAdminItemOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    usuario_id: int
    usuario_nome: str
    grupo_nome: Optional[str] = None
    data_disponivel: date
    turno: str
    observacao: Optional[str] = None


@router.get("/admin/escala", response_model=list[EscalaAdminItemOut])
async def admin_escala(
    from_date: Optional[date] = Query(None, alias="from"),
    to_date: Optional[date] = Query(None, alias="to"),
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_permissao_coordenador()),
):
    from models import BhEscala as BhEscalaModel
    q = select(BhEscalaModel).options(selectinload(BhEscalaModel.usuario))

    if current_user.tipo == "coordenador":
        result = await db.execute(
            select(Usuario.id).where(Usuario.grupo_id.in_(_setores_coordenados(current_user)))
        )
        ids_grupo = [r[0] for r in result.all()]
        q = q.where(BhEscalaModel.usuario_id.in_(ids_grupo))

    if from_date:
        q = q.where(BhEscalaModel.data_disponivel >= from_date)
    if to_date:
        q = q.where(BhEscalaModel.data_disponivel <= to_date)
    q = q.order_by(BhEscalaModel.data_disponivel, BhEscalaModel.usuario_id)
    result = await db.execute(q)
    items = result.scalars().all()

    out = []
    for e in items:
        nome = e.usuario.nome if e.usuario else f"Usuário #{e.usuario_id}"
        grupo = e.usuario.grupo_nome if e.usuario else None
        out.append(EscalaAdminItemOut(
            id=e.id,
            usuario_id=e.usuario_id,
            usuario_nome=nome,
            grupo_nome=grupo,
            data_disponivel=e.data_disponivel,
            turno=e.turno,
            observacao=e.observacao,
        ))
    return out


# ══════════════════════════════════════════════════════════════════════════════
# Acionamento — visão do time de Atendimento Corporativo
# ══════════════════════════════════════════════════════════════════════════════

class DisponivelOut(BaseModel):
    id: int                       # id do registro de escala
    usuario_id: int
    usuario_nome: str
    grupo_nome: Optional[str] = None
    telefone: Optional[str] = None
    data_disponivel: date
    turno: str
    observacao: Optional[str] = None


@router.get("/acionamento/disponiveis", response_model=list[DisponivelOut])
async def acionamento_disponiveis(
    from_date: Optional[date] = Query(None, alias="from"),
    to_date: Optional[date] = Query(None, alias="to"),
    db: AsyncSession = Depends(get_db),
    _: Usuario = Depends(require_acionamento()),
):
    """Lista voluntários disponíveis (escala) de TODOS os setores, com telefone para acionamento."""
    from models import BhEscala as BhEscalaModel
    q = select(BhEscalaModel).options(selectinload(BhEscalaModel.usuario))
    if from_date:
        q = q.where(BhEscalaModel.data_disponivel >= from_date)
    if to_date:
        q = q.where(BhEscalaModel.data_disponivel <= to_date)
    q = q.order_by(BhEscalaModel.data_disponivel, BhEscalaModel.usuario_id)
    result = await db.execute(q)

    out = []
    for e in result.scalars().all():
        u = e.usuario
        out.append(DisponivelOut(
            id=e.id,
            usuario_id=e.usuario_id,
            usuario_nome=u.nome if u else f"Usuário #{e.usuario_id}",
            grupo_nome=u.grupo_nome if u else None,
            telefone=u.telefone if u else None,
            data_disponivel=e.data_disponivel,
            turno=e.turno,
            observacao=e.observacao,
        ))
    return out


# ══════════════════════════════════════════════════════════════════════════════
# Folgas — Uso do banco de horas
# ══════════════════════════════════════════════════════════════════════════════

class FolgaOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    usuario_id: int
    data_folga: date
    tipo: str
    hora_inicio: Optional[time] = None
    hora_fim: Optional[time] = None
    minutos_deduzidos: int
    motivo: str
    status: str
    nota_revisao: Optional[str] = None
    revisado_por: Optional[int] = None
    revisado_em: Optional[datetime] = None
    criado_em: datetime
    usuario_nome: Optional[str] = None


class SaldoCompletoOut(BaseModel):
    banco_minutos: int
    deducoes_minutos: int
    saldo_disponivel: int
    work_start: time
    work_end: time
    lunch_start: time
    lunch_minutes: int


class FolgaCriarIn(BaseModel):
    data_folga: date
    tipo: str  # dia_inteiro | meio_manha | meio_tarde | personalizado
    hora_inicio: Optional[time] = None
    hora_fim: Optional[time] = None
    motivo: str

    @field_validator("tipo")
    @classmethod
    def tipo_valido(cls, v: str) -> str:
        if v not in {"dia_inteiro", "meio_manha", "meio_tarde", "personalizado"}:
            raise ValueError("tipo inválido")
        return v


def _calc_minutos_folga(
    tipo: str,
    hora_inicio: Optional[time],
    hora_fim: Optional[time],
    config: Optional[BhConfigUsuario],
) -> int:
    ws = config.work_start if config else time(8, 0)
    we = config.work_end if config else time(18, 0)
    ls = config.lunch_start if config else time(12, 0)
    lm = config.lunch_minutes if config else 60

    ws_m = ws.hour * 60 + ws.minute
    we_m = we.hour * 60 + we.minute
    ls_m = ls.hour * 60 + ls.minute

    if tipo == "dia_inteiro":
        return we_m - ws_m - lm
    elif tipo == "meio_manha":
        return ls_m - ws_m
    elif tipo == "meio_tarde":
        return we_m - (ls_m + lm)
    elif tipo == "personalizado" and hora_inicio and hora_fim:
        return (hora_fim.hour * 60 + hora_fim.minute) - (hora_inicio.hour * 60 + hora_inicio.minute)
    return 0


@router.get("/saldo-completo", response_model=SaldoCompletoOut)
async def saldo_completo(
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    banco_result = await db.execute(
        select(func.coalesce(func.sum(BhLancamento.total_minutos), 0))
        .where(BhLancamento.usuario_id == current_user.id, BhLancamento.status == "aprovado")
    )
    banco_minutos = int(banco_result.scalar_one())

    deducao_result = await db.execute(
        select(func.coalesce(func.sum(BhFolga.minutos_deduzidos), 0))
        .where(BhFolga.usuario_id == current_user.id, BhFolga.status == "aprovado")
    )
    deducoes_minutos = int(deducao_result.scalar_one())

    config = await _get_config(db, current_user.id)
    return SaldoCompletoOut(
        banco_minutos=banco_minutos,
        deducoes_minutos=deducoes_minutos,
        saldo_disponivel=banco_minutos - deducoes_minutos,
        work_start=config.work_start if config else time(8, 0),
        work_end=config.work_end if config else time(18, 0),
        lunch_start=config.lunch_start if config else time(12, 0),
        lunch_minutes=config.lunch_minutes if config else 60,
    )


@router.post("/folgas", response_model=FolgaOut, status_code=status.HTTP_201_CREATED)
async def criar_folga(
    payload: FolgaCriarIn,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    if payload.tipo == "personalizado":
        if not payload.hora_inicio or not payload.hora_fim:
            raise HTTPException(status_code=400, detail="Horários obrigatórios para tipo personalizado")
        if payload.hora_fim <= payload.hora_inicio:
            raise HTTPException(status_code=400, detail="hora_fim deve ser posterior a hora_inicio")

    config = await _get_config(db, current_user.id)
    minutos = _calc_minutos_folga(payload.tipo, payload.hora_inicio, payload.hora_fim, config)
    if minutos <= 0:
        raise HTTPException(status_code=400, detail="Duração inválida para o tipo selecionado")

    folga = BhFolga(
        usuario_id=current_user.id,
        data_folga=payload.data_folga,
        tipo=payload.tipo,
        hora_inicio=payload.hora_inicio,
        hora_fim=payload.hora_fim,
        minutos_deduzidos=minutos,
        motivo=payload.motivo,
        status="pendente",
    )
    db.add(folga)
    await db.commit()
    await db.refresh(folga)
    out = FolgaOut.model_validate(folga)
    out.usuario_nome = current_user.nome
    return out


@router.get("/folgas", response_model=list[FolgaOut])
async def minhas_folgas(
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    result = await db.execute(
        select(BhFolga)
        .where(BhFolga.usuario_id == current_user.id)
        .order_by(BhFolga.criado_em.desc())
    )
    items = result.scalars().all()
    out = []
    for f in items:
        o = FolgaOut.model_validate(f)
        o.usuario_nome = current_user.nome
        out.append(o)
    return out


@router.delete("/folgas/{folga_id}", status_code=status.HTTP_204_NO_CONTENT)
async def cancelar_folga(
    folga_id: int,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(get_current_user),
):
    result = await db.execute(
        select(BhFolga).where(BhFolga.id == folga_id, BhFolga.usuario_id == current_user.id)
    )
    folga = result.scalar_one_or_none()
    if not folga:
        raise HTTPException(status_code=404, detail="Solicitação não encontrada")
    if folga.status != "pendente":
        raise HTTPException(status_code=400, detail="Só é possível cancelar solicitações pendentes")
    await db.delete(folga)
    await db.commit()


class FolgasPage(BaseModel):
    items: list[FolgaOut]
    total: int
    page: int
    per_page: int


@router.get("/admin/folgas", response_model=FolgasPage)
async def admin_folgas(
    status_filter: Optional[str] = Query(None, alias="status"),
    page: Optional[int] = Query(None, ge=1),
    per_page: int = Query(20, ge=1, le=100),
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_permissao_coordenador()),
):
    q = select(BhFolga).options(selectinload(BhFolga.usuario))

    if current_user.tipo == "coordenador":
        ids_result = await db.execute(
            select(Usuario.id).where(Usuario.grupo_id.in_(_setores_coordenados(current_user)))
        )
        ids_grupo = [r[0] for r in ids_result.all()]
        q = q.where(BhFolga.usuario_id.in_(ids_grupo))

    if status_filter:
        q = q.where(BhFolga.status == status_filter)

    total = (await db.execute(select(func.count()).select_from(q.subquery()))).scalar_one()

    q = q.order_by(BhFolga.criado_em.desc())
    if page is not None:
        q = q.offset((page - 1) * per_page).limit(per_page)

    result = await db.execute(q)
    items = result.scalars().all()

    out = []
    for f in items:
        o = FolgaOut.model_validate(f)
        o.usuario_nome = f.usuario.nome if f.usuario else f"Usuário #{f.usuario_id}"
        out.append(o)
    return FolgasPage(items=out, total=total, page=page or 1, per_page=per_page)


@router.post("/admin/folgas/{folga_id}/aprovar", response_model=FolgaOut)
async def aprovar_folga(
    folga_id: int,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_permissao_coordenador()),
):
    result = await db.execute(
        select(BhFolga).options(selectinload(BhFolga.usuario)).where(BhFolga.id == folga_id)
    )
    folga = result.scalar_one_or_none()
    if not folga:
        raise HTTPException(status_code=404, detail="Solicitação não encontrada")
    if folga.status != "pendente":
        raise HTTPException(status_code=400, detail="Solicitação não está pendente")

    folga.status = "aprovado"
    folga.revisado_por = current_user.id
    folga.revisado_em = datetime.now(timezone.utc)
    await db.commit()
    await db.refresh(folga)
    o = FolgaOut.model_validate(folga)
    o.usuario_nome = folga.usuario.nome if folga.usuario else None
    return o


@router.post("/admin/folgas/{folga_id}/recusar", response_model=FolgaOut)
async def recusar_folga(
    folga_id: int,
    payload: RecusarIn,
    db: AsyncSession = Depends(get_db),
    current_user: Usuario = Depends(require_permissao_coordenador()),
):
    result = await db.execute(
        select(BhFolga).options(selectinload(BhFolga.usuario)).where(BhFolga.id == folga_id)
    )
    folga = result.scalar_one_or_none()
    if not folga:
        raise HTTPException(status_code=404, detail="Solicitação não encontrada")
    if folga.status != "pendente":
        raise HTTPException(status_code=400, detail="Solicitação não está pendente")

    folga.status = "recusado"
    folga.nota_revisao = payload.nota_revisao
    folga.revisado_por = current_user.id
    folga.revisado_em = datetime.now(timezone.utc)
    await db.commit()
    await db.refresh(folga)
    o = FolgaOut.model_validate(folga)
    o.usuario_nome = folga.usuario.nome if folga.usuario else None
    return o


# ══════════════════════════════════════════════════════════════════════════════
# Backup / Restore (admin) — exporta e restaura todos os dados em JSON
# ══════════════════════════════════════════════════════════════════════════════

# Ordem de dependência para inserção no restore (pais antes de filhos).
_BACKUP_TABELAS = [
    ("setores", BhSetor),
    ("usuarios", Usuario),
    ("config", BhConfigUsuario),
    ("lancamentos", BhLancamento),
    ("folgas", BhFolga),
    ("escala", BhEscala),
]

# Sequências de id (bh_config_usuario usa usuario_id como PK, sem sequência própria).
_BACKUP_SEQUENCIAS = {
    "bh_setores": "bh_setores_id_seq",
    "bh_usuarios": "bh_usuarios_id_seq",
    "bh_lancamentos": "bh_lancamentos_id_seq",
    "bh_folgas": "bh_folgas_id_seq",
    "bh_escala": "bh_escala_id_seq",
}


def _row_to_dict(obj) -> dict:
    return {c.name: getattr(obj, c.name) for c in obj.__table__.columns}


def _coerce(value, coltype):
    """Converte valor JSON (string/num) para o tipo Python da coluna no restore."""
    if value is None:
        return None
    import sqlalchemy as sa
    if isinstance(coltype, sa.Date):
        return date.fromisoformat(value)
    if isinstance(coltype, sa.Time):
        return time.fromisoformat(value)
    if isinstance(coltype, sa.DateTime):
        return datetime.fromisoformat(value)
    if isinstance(coltype, sa.Numeric):
        return Decimal(str(value))
    return value


@router.get("/admin/backup")
async def baixar_backup(
    db: AsyncSession = Depends(get_db),
    _: Usuario = Depends(require_tipo("admin")),
):
    """Exporta todas as tabelas do sistema em um único JSON (download)."""
    tabelas: dict[str, list] = {}
    for chave, modelo in _BACKUP_TABELAS:
        result = await db.execute(select(modelo))
        tabelas[chave] = [_row_to_dict(o) for o in result.scalars().all()]

    return {
        "versao": "1.0",
        "gerado_em": datetime.now(timezone.utc),
        "tabelas": tabelas,
    }


class RestoreIn(BaseModel):
    versao: Optional[str] = None
    tabelas: dict[str, list]


@router.post("/admin/restore")
async def restaurar_backup(
    payload: RestoreIn,
    db: AsyncSession = Depends(get_db),
    _: Usuario = Depends(require_tipo("admin")),
):
    """
    Restaura um backup JSON SUBSTITUINDO todos os dados atuais.
    Operação destrutiva e transacional: em caso de erro, nada é alterado.
    """
    if "usuarios" not in payload.tabelas or "setores" not in payload.tabelas:
        raise HTTPException(status_code=400, detail="Backup inválido: tabelas essenciais ausentes")

    try:
        # 1) Limpa tudo e reseta sequências (CASCADE respeita as FKs)
        await db.execute(text(
            "TRUNCATE bh_folgas, bh_escala, bh_lancamentos, bh_config_usuario, "
            "bh_usuarios, bh_setores RESTART IDENTITY CASCADE"
        ))

        # 2) Insere preservando IDs, na ordem de dependência
        contagem: dict[str, int] = {}
        for chave, modelo in _BACKUP_TABELAS:
            linhas = payload.tabelas.get(chave, [])
            cols = {c.name: c.type for c in modelo.__table__.columns}
            for row in linhas:
                dados = {k: _coerce(row.get(k), cols[k]) for k in cols if k in row}
                db.add(modelo(**dados))
            contagem[chave] = len(linhas)
            await db.flush()

        # 3) Reajusta as sequências para o maior id de cada tabela
        for tabela, seq in _BACKUP_SEQUENCIAS.items():
            await db.execute(text(
                f"SELECT setval('{seq}', (SELECT COALESCE(MAX(id), 1) FROM {tabela}))"
            ))

        await db.commit()
    except Exception as e:
        await db.rollback()
        raise HTTPException(status_code=400, detail=f"Falha ao restaurar: {e}")

    return {"detail": "Backup restaurado com sucesso", "contagem": contagem}
