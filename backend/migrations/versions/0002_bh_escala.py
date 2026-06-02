"""bh_escala

Revision ID: 0002
Revises: 0001
Create Date: 2026-05-29 00:00:00.000000

"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0002"
down_revision: Union[str, None] = "0001"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "bh_escala",
        sa.Column("id", sa.Integer(), autoincrement=True, nullable=False),
        sa.Column("usuario_id", sa.Integer(), nullable=False),
        sa.Column("data_disponivel", sa.Date(), nullable=False),
        sa.Column("turno", sa.String(length=20), nullable=False, server_default="dia_todo"),
        sa.Column("observacao", sa.Text(), nullable=True),
        sa.Column(
            "criado_em",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
        sa.ForeignKeyConstraint(
            ["usuario_id"],
            ["bh_usuarios.id"],
            ondelete="CASCADE",
        ),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index("ix_bh_escala_usuario_id", "bh_escala", ["usuario_id"])


def downgrade() -> None:
    op.drop_index("ix_bh_escala_usuario_id", table_name="bh_escala")
    op.drop_table("bh_escala")
