"""dedup escala + unique(usuario_id, data_disponivel)

Revision ID: 0005
Revises: 0004
Create Date: 2026-06-01 13:00:00.000000

"""
from typing import Sequence, Union

from alembic import op

revision: str = "0005"
down_revision: Union[str, None] = "0004"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # Remove duplicatas mantendo o menor id por (usuario_id, data_disponivel)
    op.execute(
        """
        DELETE FROM bh_escala a
        USING bh_escala b
        WHERE a.usuario_id = b.usuario_id
          AND a.data_disponivel = b.data_disponivel
          AND a.id > b.id;
        """
    )
    op.create_unique_constraint(
        "uq_bh_escala_usuario_data", "bh_escala", ["usuario_id", "data_disponivel"]
    )


def downgrade() -> None:
    op.drop_constraint("uq_bh_escala_usuario_data", "bh_escala", type_="unique")
