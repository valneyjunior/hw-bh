"""telefone + setores_coordenados

Revision ID: 0004
Revises: 0003
Create Date: 2026-06-01 12:00:00.000000

"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = "0004"
down_revision: Union[str, None] = "0003"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column("bh_usuarios", sa.Column("telefone", sa.String(length=20), nullable=True))
    op.add_column(
        "bh_usuarios",
        sa.Column(
            "setores_coordenados",
            postgresql.ARRAY(sa.Integer()),
            nullable=False,
            server_default="{}",
        ),
    )


def downgrade() -> None:
    op.drop_column("bh_usuarios", "setores_coordenados")
    op.drop_column("bh_usuarios", "telefone")
