# Backup & Migração — BH Pulse

Há dois caminhos para preservar/migrar os dados. Use o que melhor se encaixa.

---

## 1. Backup pela interface (rotina do dia a dia)

Acesse **Backup** no menu (perfil Admin):

- **Baixar backup** → gera `bh-pulse-backup-AAAA-MM-DD.json` com todos os dados.
- **Restaurar backup** → importa um arquivo e **substitui todos os dados atuais** (pede confirmação digitando `RESTAURAR`).

> ⚠️ O arquivo contém dados sensíveis (nomes, e-mails, salários, credenciais). Guarde com segurança.

Ideal para backups frequentes e para restaurar num Docker novo pela própria tela.

---

## 2. Migração definitiva entre ambientes (pg_dump — recomendado)

O `pg_dump` é o método mais robusto para virar de ambiente, pois preserva tipos,
sequências e constraints exatamente.

### Gerar o dump (máquina ATUAL)

```bash
# Gera um dump compactado do banco inteiro
docker exec bh-pulse-db pg_dump -U bh_user -d bh_pulse -Fc -f /tmp/bh_pulse.dump

# Copia o arquivo do container para o host
docker cp bh-pulse-db:/tmp/bh_pulse.dump ./bh_pulse.dump
```

> Guarde o `bh_pulse.dump` em local seguro. É o backup completo.

### Restaurar no ambiente NOVO

```bash
# 1) Suba a stack no novo ambiente (cria o banco vazio + migrations)
docker compose up -d
docker exec bh-pulse-backend alembic upgrade head

# 2) Copie o dump para o container do banco
docker cp ./bh_pulse.dump bh-pulse-db:/tmp/bh_pulse.dump

# 3) Restaure (--clean remove objetos existentes antes de recriar)
docker exec bh-pulse-db pg_restore -U bh_user -d bh_pulse --clean --if-exists /tmp/bh_pulse.dump
```

### Alternativa em SQL puro (texto legível)

```bash
# Dump em SQL
docker exec bh-pulse-db pg_dump -U bh_user -d bh_pulse -f /tmp/bh_pulse.sql
docker cp bh-pulse-db:/tmp/bh_pulse.sql ./bh_pulse.sql

# Restore em SQL
docker cp ./bh_pulse.sql bh-pulse-db:/tmp/bh_pulse.sql
docker exec -i bh-pulse-db psql -U bh_user -d bh_pulse -f /tmp/bh_pulse.sql
```

---

## Sobre persistência local

O banco usa um **volume Docker nomeado** (`bh_pg_data`). Os dados sobrevivem a
`docker compose restart`/`down` e a reinícios do PC. São perdidos apenas em:

- `docker compose down -v` (remove volumes), ou
- troca de máquina/ambiente (o volume fica na máquina antiga).

Por isso, antes de migrar, **sempre gere um backup** (interface ou `pg_dump`).
