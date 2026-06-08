"""reconciliar schema (colunas/tabela adicionadas fora do Alembic)

Revision ID: 0007
Revises: 0006
Create Date: 2026-06-08 00:00:00.000000

Concilia o drift entre o modelo e as migrations: as colunas `arquivado` e
`perfis` de `bh_usuarios` e a tabela `bh_folgas` haviam sido criadas direto no
banco original, sem migration — então não existiam em bancos novos (produção).
Tudo idempotente (IF NOT EXISTS) para rodar com segurança em qualquer ambiente.
"""
from typing import Sequence, Union

from alembic import op

revision: str = "0007"
down_revision: Union[str, None] = "0006"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # Colunas de bh_usuarios adicionadas fora do Alembic
    op.execute("ALTER TABLE bh_usuarios ADD COLUMN IF NOT EXISTS arquivado BOOLEAN NOT NULL DEFAULT false")
    op.execute("ALTER TABLE bh_usuarios ADD COLUMN IF NOT EXISTS perfis VARCHAR[] NOT NULL DEFAULT '{}'")

    # Tabela bh_folgas (nunca teve migration)
    op.execute(
        """
        CREATE TABLE IF NOT EXISTS bh_folgas (
            id                SERIAL PRIMARY KEY,
            usuario_id        INTEGER NOT NULL REFERENCES bh_usuarios(id) ON DELETE CASCADE,
            data_folga        DATE NOT NULL,
            tipo              VARCHAR(30) NOT NULL,
            hora_inicio       TIME NULL,
            hora_fim          TIME NULL,
            minutos_deduzidos INTEGER NOT NULL DEFAULT 0,
            motivo            TEXT NOT NULL,
            status            VARCHAR(20) NOT NULL DEFAULT 'pendente',
            nota_revisao      TEXT NULL,
            revisado_por      INTEGER NULL REFERENCES bh_usuarios(id) ON DELETE SET NULL,
            revisado_em       TIMESTAMPTZ NULL,
            criado_em         TIMESTAMPTZ NOT NULL DEFAULT now()
        )
        """
    )
    op.execute("CREATE INDEX IF NOT EXISTS ix_bh_folgas_usuario_id ON bh_folgas (usuario_id)")
    op.execute("CREATE INDEX IF NOT EXISTS ix_bh_folgas_status ON bh_folgas (status)")


def downgrade() -> None:
    # Migration de reconciliação — sem reversão automática (evita perda de dados).
    pass
