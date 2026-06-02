"""adicional_atrativo

Revision ID: 0003
Revises: 0002
Create Date: 2026-06-01 00:00:00.000000

"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0003"
down_revision: Union[str, None] = "0002"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column(
        "bh_config_usuario",
        sa.Column("adicional_atrativo", sa.Numeric(12, 2), nullable=False, server_default="0.00"),
    )


def downgrade() -> None:
    op.drop_column("bh_config_usuario", "adicional_atrativo")
