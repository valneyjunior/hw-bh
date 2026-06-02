from datetime import datetime, date, time
from decimal import Decimal
from typing import Optional

from sqlalchemy import (
    ARRAY, Boolean, DateTime, Date, ForeignKey, Integer, Numeric,
    String, Text, Time, func, UniqueConstraint,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from database import Base


class BhSetor(Base):
    __tablename__ = "bh_setores"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    nome: Mapped[str] = mapped_column(String(120), nullable=False, unique=True)

    usuarios: Mapped[list["Usuario"]] = relationship("Usuario", back_populates="setor")


class Usuario(Base):
    __tablename__ = "bh_usuarios"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    nome: Mapped[str] = mapped_column(String(200), nullable=False)
    email: Mapped[str] = mapped_column(String(255), nullable=False, unique=True, index=True)
    senha_hash: Mapped[str] = mapped_column(String(255), nullable=False)
    tipo: Mapped[str] = mapped_column(String(20), nullable=False, default="analista")
    grupo_id: Mapped[Optional[int]] = mapped_column(
        Integer, ForeignKey("bh_setores.id", ondelete="SET NULL"), nullable=True
    )
    grupo_nome: Mapped[Optional[str]] = mapped_column(String(120), nullable=True)
    telefone: Mapped[Optional[str]] = mapped_column(String(20), nullable=True)
    must_change_password: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    ativo: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    arquivado: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    perfis: Mapped[list[str]] = mapped_column(ARRAY(String), nullable=False, server_default="{}", default=list)
    # Setores que um coordenador coordena/valida (além do setor de lotação `grupo_id`).
    setores_coordenados: Mapped[list[int]] = mapped_column(ARRAY(Integer), nullable=False, server_default="{}", default=list)
    criado_em: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )

    setor: Mapped[Optional["BhSetor"]] = relationship("BhSetor", back_populates="usuarios")
    config: Mapped[Optional["BhConfigUsuario"]] = relationship(
        "BhConfigUsuario", back_populates="usuario", uselist=False, cascade="all, delete-orphan"
    )
    lancamentos: Mapped[list["BhLancamento"]] = relationship(
        "BhLancamento", foreign_keys="BhLancamento.usuario_id", back_populates="usuario",
        cascade="all, delete-orphan",
    )
    revisoes: Mapped[list["BhLancamento"]] = relationship(
        "BhLancamento", foreign_keys="BhLancamento.revisado_por", back_populates="revisor"
    )


class BhConfigUsuario(Base):
    __tablename__ = "bh_config_usuario"

    usuario_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("bh_usuarios.id", ondelete="CASCADE"), primary_key=True
    )
    salario_bruto: Mapped[Decimal] = mapped_column(Numeric(12, 2), default=Decimal("0.00"), nullable=False)
    work_start: Mapped[time] = mapped_column(Time, default=time(8, 0), nullable=False)
    work_end: Mapped[time] = mapped_column(Time, default=time(18, 0), nullable=False)
    lunch_start: Mapped[time] = mapped_column(Time, default=time(12, 0), nullable=False)
    lunch_minutes: Mapped[int] = mapped_column(Integer, default=60, nullable=False)
    adicional_atrativo: Mapped[Decimal] = mapped_column(Numeric(12, 2), default=Decimal("0.00"), nullable=False)

    usuario: Mapped["Usuario"] = relationship("Usuario", back_populates="config")


class BhLancamento(Base):
    __tablename__ = "bh_lancamentos"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    usuario_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("bh_usuarios.id", ondelete="CASCADE"), nullable=False, index=True
    )
    data_acionamento: Mapped[date] = mapped_column(Date, nullable=False)
    hora_inicio: Mapped[time] = mapped_column(Time, nullable=False)
    hora_fim: Mapped[time] = mapped_column(Time, nullable=False)
    total_minutos: Mapped[Optional[int]] = mapped_column(Integer, nullable=True)
    chamado: Mapped[str] = mapped_column(String(100), nullable=False)
    motivo: Mapped[str] = mapped_column(Text, nullable=False)
    feriado: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    descricao_feriado: Mapped[Optional[str]] = mapped_column(String(200), nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="pendente", nullable=False, index=True)
    nota_revisao: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    valor_calculado: Mapped[Optional[Decimal]] = mapped_column(Numeric(12, 2), nullable=True)
    revisado_por: Mapped[Optional[int]] = mapped_column(
        Integer, ForeignKey("bh_usuarios.id", ondelete="SET NULL"), nullable=True
    )
    revisado_em: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), nullable=True)
    criado_em: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )

    usuario: Mapped["Usuario"] = relationship(
        "Usuario", foreign_keys=[usuario_id], back_populates="lancamentos"
    )
    revisor: Mapped[Optional["Usuario"]] = relationship(
        "Usuario", foreign_keys=[revisado_por], back_populates="revisoes"
    )


class BhFolga(Base):
    __tablename__ = "bh_folgas"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    usuario_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("bh_usuarios.id", ondelete="CASCADE"), nullable=False, index=True
    )
    data_folga: Mapped[date] = mapped_column(Date, nullable=False)
    tipo: Mapped[str] = mapped_column(String(30), nullable=False)
    hora_inicio: Mapped[Optional[time]] = mapped_column(Time, nullable=True)
    hora_fim: Mapped[Optional[time]] = mapped_column(Time, nullable=True)
    minutos_deduzidos: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    motivo: Mapped[str] = mapped_column(Text, nullable=False)
    status: Mapped[str] = mapped_column(String(20), default="pendente", nullable=False, index=True)
    nota_revisao: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    revisado_por: Mapped[Optional[int]] = mapped_column(
        Integer, ForeignKey("bh_usuarios.id", ondelete="SET NULL"), nullable=True
    )
    revisado_em: Mapped[Optional[datetime]] = mapped_column(DateTime(timezone=True), nullable=True)
    criado_em: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )

    usuario: Mapped["Usuario"] = relationship("Usuario", foreign_keys=[usuario_id])
    revisor: Mapped[Optional["Usuario"]] = relationship("Usuario", foreign_keys=[revisado_por])


class BhEscala(Base):
    __tablename__ = "bh_escala"
    __table_args__ = (
        UniqueConstraint("usuario_id", "data_disponivel", name="uq_bh_escala_usuario_data"),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    usuario_id: Mapped[int] = mapped_column(
        Integer, ForeignKey("bh_usuarios.id", ondelete="CASCADE"), nullable=False, index=True
    )
    data_disponivel: Mapped[date] = mapped_column(Date, nullable=False)
    turno: Mapped[str] = mapped_column(String(20), nullable=False, default="dia_todo")
    observacao: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    criado_em: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )

    usuario: Mapped["Usuario"] = relationship("Usuario", foreign_keys=[usuario_id])
