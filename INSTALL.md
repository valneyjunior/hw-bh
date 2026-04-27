# Manual de Instalação — BH Tracker
**Distribuição: Ubuntu 24.04 LTS (Noble Numbat)**
Versão recomendada para novos servidores. Suporte até Abril de 2029.

---

## Pré-requisitos

| Requisito | Mínimo | Recomendado |
|:----------|:-------|:------------|
| CPU | 1 vCPU | 2 vCPUs |
| RAM | 1 GB | 2 GB |
| Disco | 20 GB SSD | 40 GB SSD |
| OS | Ubuntu 24.04 LTS | Ubuntu 24.04 LTS |
| Acesso | SSH root ou sudo | SSH root ou sudo |
| Domínio | Opcional (dev) | Obrigatório (prod) |

---

## 1. Atualizar o sistema

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y curl git unzip ufw
```

---

## 2. Instalar Docker Engine

```bash
# Remover versões antigas
sudo apt remove -y docker docker.io containerd runc 2>/dev/null || true

# Instalar dependências
sudo apt install -y ca-certificates gnupg lsb-release

# Adicionar repositório oficial Docker
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Instalar Docker + Compose
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Verificar instalação
docker --version
docker compose version

# Permitir uso sem sudo (opcional, requer logout/login)
sudo usermod -aG docker $USER
```

---

## 3. Configurar Firewall

```bash
# Regras básicas
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow 80/tcp    # HTTP (Let's Encrypt + redirect)
sudo ufw allow 443/tcp   # HTTPS

# Em desenvolvimento local (sem NGINX), liberar porta 3000
# sudo ufw allow 3000/tcp

sudo ufw enable
sudo ufw status
```

> **Produção:** Nunca expor a porta 3306 (MySQL) para o mundo. O banco deve ser acessível apenas pelo container `app` via rede Docker interna.

---

## 4. Instalar NGINX (proxy reverso)

```bash
sudo apt install -y nginx
sudo systemctl enable nginx
sudo systemctl start nginx
```

---

## 5. Instalar Certbot (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# Emitir certificado (substituir pelo seu domínio)
sudo certbot --nginx -d bh.suaempresa.com.br

# Verificar renovação automática
sudo certbot renew --dry-run
```

---

## 6. Obter o projeto

### Opção A — via Git

```bash
cd /opt
sudo git clone https://github.com/sua-org/bh-tracker-php.git bh-tracker
sudo chown -R $USER:$USER /opt/bh-tracker
cd /opt/bh-tracker
```

### Opção B — via arquivo .zip

```bash
cd /opt
sudo unzip /caminho/para/bh-tracker.zip -d bh-tracker
sudo chown -R $USER:$USER /opt/bh-tracker
cd /opt/bh-tracker
```

---

## 7. Configurar variáveis de ambiente

```bash
cp .env.example .env
nano .env
```

Preencha **todas** as variáveis:

```dotenv
# Banco de dados
DB_PASSWORD=SenhaForteAqui123!        # Mínimo 16 chars, letras+números+especiais
DB_ROOT_PASSWORD=SenhaRootForte456!   # Diferente do DB_PASSWORD

# Administrador inicial (criado no primeiro boot)
ADMIN_EMAIL=coordenador@suaempresa.com.br
ADMIN_NAME=Nome do Coordenador
ADMIN_PASSWORD=SenhaAdminForte789!

# URL pública da aplicação
APP_URL=https://bh.suaempresa.com.br

# E-mail (SMTP) — opcional mas recomendado
MAIL_HOST=smtp.suaempresa.com.br
MAIL_PORT=587
MAIL_USER=noreply@suaempresa.com.br
MAIL_PASS=SenhaSMTP
MAIL_FROM=noreply@suaempresa.com.br
MAIL_FROM_NAME=BH Tecnologia
```

```bash
# Proteger o arquivo .env
chmod 600 .env
```

---

## 8. Configurar NGINX como proxy reverso

Criar o arquivo de configuração:

```bash
sudo nano /etc/nginx/sites-available/bh-tracker
```

Conteúdo:

```nginx
# Redirecionar HTTP → HTTPS
server {
    listen 80;
    server_name bh.suaempresa.com.br;
    return 301 https://$server_name$request_uri;
}

# HTTPS
server {
    listen 443 ssl http2;
    server_name bh.suaempresa.com.br;

    # Certificados Let's Encrypt (Certbot preenche automaticamente)
    ssl_certificate     /etc/letsencrypt/live/bh.suaempresa.com.br/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/bh.suaempresa.com.br/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;

    # Headers de segurança
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
    add_header X-Frame-Options DENY always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    # Tamanho máximo de upload
    client_max_body_size 10M;

    # Proxy para o container Docker
    location / {
        proxy_pass         http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
    }

    # Bloquear acesso a arquivos sensíveis
    location ~ /\.(env|git|htaccess|sql) {
        deny all;
        return 404;
    }
}
```

```bash
# Ativar configuração
sudo ln -s /etc/nginx/sites-available/bh-tracker /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 9. Ajustar porta do docker-compose para produção

Em produção, o container deve escutar apenas em `127.0.0.1` (não exposto para o mundo):

```bash
nano docker-compose.yml
```

Alterar:
```yaml
# Antes (acessível externamente)
ports:
  - "3000:80"

# Depois (apenas localhost — NGINX faz o proxy)
ports:
  - "127.0.0.1:3000:80"
```

---

## 10. Subir os containers

```bash
cd /opt/bh-tracker

# Build e iniciar em background
docker compose up -d --build

# Acompanhar logs
docker compose logs -f

# Verificar status
docker compose ps
```

Aguarde todos os serviços ficarem `healthy` (pode levar 30-60 segundos no primeiro boot).

---

## 11. Verificar criação do banco

O arquivo `app/sql/init.sql` é executado automaticamente pelo MySQL na primeira inicialização via `docker-entrypoint-initdb.d`. Verifique:

```bash
docker compose exec db mysql -ubh_user -p"$(grep DB_PASSWORD .env | cut -d= -f2)" bh_tracker \
  -e "SHOW TABLES;"
```

Saída esperada:
```
+----------------------+
| Tables_in_bh_tracker |
+----------------------+
| audit_logs           |
| bh_requests          |
| collaborator_salary  |
| password_resets      |
| records              |
| users                |
+----------------------+
```

---

## 12. Aplicar migrations em banco existente

Se o banco já existia (upgrade de versão):

```bash
DB_PASS=$(grep ^DB_PASSWORD .env | cut -d= -f2)
docker compose exec -T db mysql -ubh_user -p"$DB_PASS" bh_tracker \
  < app/sql/migration_v2.sql

echo "Migration aplicada com sucesso"
```

---

## 13. Verificar a aplicação

Abra no browser: `https://bh.suaempresa.com.br`

Login inicial:
- **E-mail:** valor definido em `ADMIN_EMAIL` no `.env`
- **Senha:** valor definido em `ADMIN_PASSWORD` no `.env`

> Troque a senha do admin imediatamente após o primeiro login.

---

## 14. Configurar reinicialização automática

Os containers já têm `restart: always` no `docker-compose.yml`. Para garantir que subam com o sistema:

```bash
sudo systemctl enable docker
```

Criar serviço systemd para gerenciar o Compose:

```bash
sudo nano /etc/systemd/system/bh-tracker.service
```

```ini
[Unit]
Description=BH Tracker
Requires=docker.service
After=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/opt/bh-tracker
ExecStart=/usr/bin/docker compose up -d --build
ExecStop=/usr/bin/docker compose down
TimeoutStartSec=300

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable bh-tracker
sudo systemctl start bh-tracker
```

---

## 15. Backup automático do banco de dados

Criar script de backup:

```bash
sudo nano /opt/bh-tracker/backup.sh
```

```bash
#!/bin/bash
set -euo pipefail

APP_DIR="/opt/bh-tracker"
BACKUP_DIR="/opt/bh-tracker/backups"
DATE=$(date +%Y%m%d_%H%M%S)
DB_PASS=$(grep ^DB_PASSWORD "$APP_DIR/.env" | cut -d= -f2)

mkdir -p "$BACKUP_DIR"

# Dump do banco
docker compose -f "$APP_DIR/docker-compose.yml" exec -T db \
  mysqldump -ubh_user -p"$DB_PASS" --single-transaction bh_tracker \
  | gzip > "$BACKUP_DIR/bh_tracker_$DATE.sql.gz"

# Manter apenas os últimos 30 backups
ls -t "$BACKUP_DIR"/*.sql.gz | tail -n +31 | xargs -r rm

echo "[$DATE] Backup concluído: bh_tracker_$DATE.sql.gz"
```

```bash
chmod +x /opt/bh-tracker/backup.sh

# Agendar no cron: diário às 02:00
(crontab -l 2>/dev/null; echo "0 2 * * * /opt/bh-tracker/backup.sh >> /var/log/bh-backup.log 2>&1") | crontab -
```

---

## 16. Monitoramento básico

```bash
# Ver uso de recursos dos containers
docker stats

# Ver logs em tempo real
docker compose logs -f --tail=100

# Ver logs de erro do NGINX
sudo tail -f /var/log/nginx/error.log

# Verificar saúde dos containers
docker compose ps
```

---

## Comandos de Manutenção

```bash
# Atualizar a aplicação (novo deploy)
cd /opt/bh-tracker
git pull origin main
docker compose up -d --build

# Reiniciar apenas o app (sem rebuild)
docker compose restart app

# Parar todos os containers
docker compose down

# Parar e apagar volumes (CUIDADO: apaga o banco)
docker compose down -v

# Acessar o banco de dados interativamente
DB_PASS=$(grep ^DB_PASSWORD .env | cut -d= -f2)
docker compose exec db mysql -ubh_user -p"$DB_PASS" bh_tracker

# Limpar imagens antigas
docker image prune -f

# Ver espaço em disco usado pelo Docker
docker system df
```

---

## Restaurar backup

```bash
BACKUP_FILE="/opt/bh-tracker/backups/bh_tracker_20260426_020000.sql.gz"
DB_PASS=$(grep ^DB_PASSWORD /opt/bh-tracker/.env | cut -d= -f2)

zcat "$BACKUP_FILE" | docker compose exec -T db \
  mysql -ubh_user -p"$DB_PASS" bh_tracker

echo "Banco restaurado com sucesso"
```

---

## Troubleshooting

| Sintoma | Causa provável | Solução |
|:--------|:---------------|:--------|
| Container `app` não inicia | Erro de build PHP | `docker compose logs app` |
| Container `db` unhealthy | Senha errada no `.env` | Verificar `DB_PASSWORD` e `DB_ROOT_PASSWORD` |
| Página em branco | PHP fatal error | `docker compose logs app \| grep -i error` |
| NGINX 502 Bad Gateway | Container `app` desligado | `docker compose up -d app` |
| SSL expirado | Certbot não renovou | `sudo certbot renew` |
| Banco vazio após reinício | Volume apagado acidentalmente | Restaurar backup |
| "Unknown column" na tela | Migration não aplicada | Executar passo 12 |

---

## Checklist pós-instalação

- [ ] HTTPS funcionando (cadeado verde no browser)
- [ ] Redirecionamento HTTP → HTTPS ativo
- [ ] Login com admin funcional
- [ ] Senha do admin alterada no primeiro acesso
- [ ] Backup automático agendado no cron
- [ ] `display_errors = Off` (verificar em `phpinfo()` ou logs)
- [ ] Porta 3306 não acessível externamente (`nmap -p 3306 SEU_IP`)
- [ ] Firewall ativo (`sudo ufw status`)
- [ ] Arquivo `.env` com permissão 600 (`ls -la .env`)
- [ ] `.env` no `.gitignore` (`git check-ignore -v .env`)

---

*Para dúvidas ou suporte, contate a equipe de TI da Hostweb.*
