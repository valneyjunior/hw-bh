"""
Seed script — popula o banco com dados iniciais para testes.
Executar: python seed.py
"""
import asyncio
import random
from datetime import datetime, timedelta, date
from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from auth import hash_password
from config import get_settings
from database import AsyncSessionLocal, engine
from models import Base, BhConfigUsuario, BhLancamento, BhSetor, Usuario

settings = get_settings()

SETORES = ["Redes", "Suporte", "Infraestrutura", "Comercial"]

SALARIOS = [3500, 3800, 4000, 4200, 4500, 4800, 5000, 5500, 6000]


async def seed():
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)

    async with AsyncSessionLocal() as db:
        # ── Setores ──────────────────────────────────────────────────────────
        setores: dict[str, BhSetor] = {}
        for nome in SETORES:
            result = await db.execute(select(BhSetor).where(BhSetor.nome == nome))
            setor = result.scalar_one_or_none()
            if not setor:
                setor = BhSetor(nome=nome)
                db.add(setor)
                await db.flush()
                print(f"  Setor criado: {nome}")
            setores[nome] = setor

        # ── Admin ─────────────────────────────────────────────────────────────
        result = await db.execute(select(Usuario).where(Usuario.email == settings.ADMIN_EMAIL))
        admin = result.scalar_one_or_none()
        if not admin:
            admin = Usuario(
                nome=settings.ADMIN_NAME,
                email=settings.ADMIN_EMAIL,
                senha_hash=hash_password(settings.ADMIN_PASSWORD),
                tipo="admin",
                grupo_id=None,
                grupo_nome=None,
                must_change_password=False,
            )
            db.add(admin)
            await db.flush()
            db.add(BhConfigUsuario(
                usuario_id=admin.id,
                salario_bruto=Decimal("8000.00"),
            ))
            print(f"  Admin criado: {settings.ADMIN_EMAIL}")

        # ── Coordenadores e analistas ─────────────────────────────────────────
        for nome_setor, setor in setores.items():
            # Coordenador
            coord_email = f"coord.{nome_setor.lower()}@hostweb.cloud"
            result = await db.execute(select(Usuario).where(Usuario.email == coord_email))
            coord = result.scalar_one_or_none()
            if not coord:
                coord = Usuario(
                    nome=f"Coord. {nome_setor}",
                    email=coord_email,
                    senha_hash=hash_password("Coord@123"),
                    tipo="coordenador",
                    grupo_id=setor.id,
                    grupo_nome=setor.nome,
                    must_change_password=False,
                )
                db.add(coord)
                await db.flush()
                db.add(BhConfigUsuario(
                    usuario_id=coord.id,
                    salario_bruto=Decimal(str(random.choice(SALARIOS))),
                ))
                print(f"  Coordenador criado: {coord_email}")

            # 2 analistas por setor
            for i in range(1, 3):
                anal_email = f"analista{i}.{nome_setor.lower()}@hostweb.cloud"
                result = await db.execute(select(Usuario).where(Usuario.email == anal_email))
                anal = result.scalar_one_or_none()
                if not anal:
                    anal = Usuario(
                        nome=f"Analista {i} {nome_setor}",
                        email=anal_email,
                        senha_hash=hash_password("Analista@123"),
                        tipo="analista",
                        grupo_id=setor.id,
                        grupo_nome=setor.nome,
                        must_change_password=False,
                    )
                    db.add(anal)
                    await db.flush()
                    db.add(BhConfigUsuario(
                        usuario_id=anal.id,
                        salario_bruto=Decimal(str(random.choice(SALARIOS))),
                    ))

                    # Gerar alguns lançamentos de exemplo
                    await _gerar_lancamentos(db, anal)
                    print(f"  Analista criado: {anal_email}")

        await db.commit()
        print("\nSeed concluído com sucesso!")
        print(f"\nCredenciais:")
        print(f"  Admin:        {settings.ADMIN_EMAIL} / {settings.ADMIN_PASSWORD}")
        print(f"  Coordenador:  coord.redes@hostweb.cloud / Coord@123")
        print(f"  Analista:     analista1.redes@hostweb.cloud / Analista@123")


async def _gerar_lancamentos(db: AsyncSession, user: Usuario):
    """Gera lançamentos de exemplo nos últimos 60 dias."""
    hoje = date.today()
    statuses = ["aprovado", "aprovado", "aprovado", "pendente", "recusado"]
    chamados = ["CHD-1234", "CHD-5678", "INC-9012", "REQ-3456", "CHD-7890"]
    motivos = [
        "Suporte emergencial fora do horário",
        "Manutenção preventiva de servidores",
        "Atendimento a incidente crítico",
        "Migração de dados urgente",
        "Atendimento VIP fora de horário",
    ]

    for j in range(random.randint(3, 8)):
        dias_atras = random.randint(1, 60)
        data_lanc = hoje - timedelta(days=dias_atras)
        hora_inicio_h = random.choice([7, 8, 18, 19, 20, 22])
        hora_inicio_m = random.choice([0, 30])
        duracao_h = random.randint(1, 4)
        hora_fim_h = hora_inicio_h + duracao_h
        if hora_fim_h > 23:
            hora_fim_h = 23

        from datetime import time
        lanc = BhLancamento(
            usuario_id=user.id,
            data_acionamento=data_lanc,
            hora_inicio=time(hora_inicio_h, hora_inicio_m),
            hora_fim=time(hora_fim_h, hora_inicio_m),
            total_minutos=duracao_h * 60,
            chamado=random.choice(chamados),
            motivo=random.choice(motivos),
            feriado=False,
            status=random.choice(statuses),
        )
        if lanc.status == "aprovado":
            lanc.valor_calculado = Decimal(str(round(duracao_h * 25.0, 2)))
        elif lanc.status == "recusado":
            lanc.nota_revisao = "Lançamento fora dos critérios aceitos"
        db.add(lanc)


if __name__ == "__main__":
    asyncio.run(seed())
