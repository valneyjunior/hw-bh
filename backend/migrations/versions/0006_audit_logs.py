"""audit logs (trilha tamper-evident)

Revision ID: 0006
Revises: 0005
Create Date: 2026-06-02 18:00:00.000000

"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0006"
down_revision: Union[str, None] = "0005"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "bh_audit_logs",
        sa.Column("id", sa.Integer(), autoincrement=True, nullable=False),
        sa.Column("usuario_id", sa.Integer(), nullable=True),
        sa.Column("usuario_nome", sa.String(length=200), nullable=True),
        sa.Column("usuario_email", sa.String(length=255), nullable=True),
        sa.Column("acao", sa.String(length=80), nullable=False),
        sa.Column("recurso", sa.String(length=50), nullable=False),
        sa.Column("recurso_id", sa.Integer(), nullable=True),
        sa.Column("ip", sa.String(length=45), nullable=True),
        sa.Column("detalhes", sa.JSON(), nullable=True),
        sa.Column("hash_anterior", sa.String(length=64), nullable=False),
        sa.Column("hash_registro", sa.String(length=64), nullable=False),
        sa.Column("criado_em", sa.DateTime(timezone=True), nullable=False),
        sa.ForeignKeyConstraint(["usuario_id"], ["bh_usuarios.id"], ondelete="SET NULL"),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index("ix_bh_audit_logs_usuario_id", "bh_audit_logs", ["usuario_id"])
    op.create_index("ix_bh_audit_logs_acao", "bh_audit_logs", ["acao"])


def downgrade() -> None:
    op.drop_index("ix_bh_audit_logs_acao", table_name="bh_audit_logs")
    op.drop_index("ix_bh_audit_logs_usuario_id", table_name="bh_audit_logs")
    op.drop_table("bh_audit_logs")
