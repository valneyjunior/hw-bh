"""bh_base

Revision ID: 0001
Revises:
Create Date: 2026-01-01 00:00:00.000000

"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0001"
down_revision: Union[str, None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # bh_setores
    op.create_table(
        "bh_setores",
        sa.Column("id", sa.Integer(), autoincrement=True, nullable=False),
        sa.Column("nome", sa.String(length=120), nullable=False),
        sa.PrimaryKeyConstraint("id"),
        sa.UniqueConstraint("nome"),
    )

    # bh_usuarios
    op.create_table(
        "bh_usuarios",
        sa.Column("id", sa.Integer(), autoincrement=True, nullable=False),
        sa.Column("nome", sa.String(length=200), nullable=False),
        sa.Column("email", sa.String(length=255), nullable=False),
        sa.Column("senha_hash", sa.String(length=255), nullable=False),
        sa.Column("tipo", sa.String(length=20), nullable=False),
        sa.Column("grupo_id", sa.Integer(), nullable=True),
        sa.Column("grupo_nome", sa.String(length=120), nullable=True),
        sa.Column("must_change_password", sa.Boolean(), nullable=False, server_default="false"),
        sa.Column("ativo", sa.Boolean(), nullable=False, server_default="true"),
        sa.Column(
            "criado_em",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
        sa.ForeignKeyConstraint(["grupo_id"], ["bh_setores.id"], ondelete="SET NULL"),
        sa.PrimaryKeyConstraint("id"),
        sa.UniqueConstraint("email"),
    )
    op.create_index("ix_bh_usuarios_email", "bh_usuarios", ["email"])

    # bh_config_usuario
    op.create_table(
        "bh_config_usuario",
        sa.Column("usuario_id", sa.Integer(), nullable=False),
        sa.Column("salario_bruto", sa.Numeric(precision=12, scale=2), nullable=False, server_default="0"),
        sa.Column("work_start", sa.Time(), nullable=False, server_default="08:00:00"),
        sa.Column("work_end", sa.Time(), nullable=False, server_default="18:00:00"),
        sa.Column("lunch_start", sa.Time(), nullable=False, server_default="12:00:00"),
        sa.Column("lunch_minutes", sa.Integer(), nullable=False, server_default="60"),
        sa.ForeignKeyConstraint(["usuario_id"], ["bh_usuarios.id"], ondelete="CASCADE"),
        sa.PrimaryKeyConstraint("usuario_id"),
    )

    # bh_lancamentos
    op.create_table(
        "bh_lancamentos",
        sa.Column("id", sa.Integer(), autoincrement=True, nullable=False),
        sa.Column("usuario_id", sa.Integer(), nullable=False),
        sa.Column("data_acionamento", sa.Date(), nullable=False),
        sa.Column("hora_inicio", sa.Time(), nullable=False),
        sa.Column("hora_fim", sa.Time(), nullable=False),
        sa.Column("total_minutos", sa.Integer(), nullable=True),
        sa.Column("chamado", sa.String(length=100), nullable=False),
        sa.Column("motivo", sa.Text(), nullable=False),
        sa.Column("feriado", sa.Boolean(), nullable=False, server_default="false"),
        sa.Column("descricao_feriado", sa.String(length=200), nullable=True),
        sa.Column("status", sa.String(length=20), nullable=False, server_default="pendente"),
        sa.Column("nota_revisao", sa.Text(), nullable=True),
        sa.Column("valor_calculado", sa.Numeric(precision=12, scale=2), nullable=True),
        sa.Column("revisado_por", sa.Integer(), nullable=True),
        sa.Column("revisado_em", sa.DateTime(timezone=True), nullable=True),
        sa.Column(
            "criado_em",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
        sa.ForeignKeyConstraint(["revisado_por"], ["bh_usuarios.id"], ondelete="SET NULL"),
        sa.ForeignKeyConstraint(["usuario_id"], ["bh_usuarios.id"], ondelete="CASCADE"),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index("ix_bh_lancamentos_usuario_id", "bh_lancamentos", ["usuario_id"])
    op.create_index("ix_bh_lancamentos_status", "bh_lancamentos", ["status"])


def downgrade() -> None:
    op.drop_table("bh_lancamentos")
    op.drop_table("bh_config_usuario")
    op.drop_table("bh_usuarios")
    op.drop_table("bh_setores")
