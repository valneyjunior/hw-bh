-- ── BH Tecnologia · Hostweb — Schema PostgreSQL v2.0 ────────────────────────
-- Executado automaticamente pelo Docker na primeira inicialização

-- ── Setores ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS setores (
    id   SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE
);

-- ── Perfis disponíveis ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS perfis (
    id   SERIAL PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE  -- analista | coordenador | administrador
);

-- ── Usuários ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS usuarios (
    id                        SERIAL PRIMARY KEY,
    nome                      VARCHAR(255) NOT NULL,
    email                     VARCHAR(255) NOT NULL UNIQUE,
    senha_hash                VARCHAR(255) NOT NULL,
    setor_id                  INT REFERENCES setores(id) ON DELETE SET NULL,
    salario_bruto             NUMERIC(10,2) NOT NULL DEFAULT 0,
    adicional_atrativo        BOOLEAN NOT NULL DEFAULT FALSE,
    adicional_valor           NUMERIC(10,2) NOT NULL DEFAULT 0,
    work_start                TIME NOT NULL DEFAULT '08:00:00',
    work_end                  TIME NOT NULL DEFAULT '18:00:00',
    lunch_start               TIME NOT NULL DEFAULT '12:00:00',
    lunch_minutes             SMALLINT NOT NULL DEFAULT 60
                              CHECK (lunch_minutes IN (30, 60, 90, 120)),
    status                    VARCHAR(20) NOT NULL DEFAULT 'ativo'
                              CHECK (status IN ('ativo','inativo','ex-colaborador')),
    must_change_pass          BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em                 TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em             TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ── Relação usuário <-> perfis (cumulativa) ───────────────────────────────────
CREATE TABLE IF NOT EXISTS usuario_perfis (
    usuario_id INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    perfil_id  INT NOT NULL REFERENCES perfis(id),
    PRIMARY KEY (usuario_id, perfil_id)
);

-- ── Lançamentos de horas ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lancamentos (
    id               SERIAL PRIMARY KEY,
    usuario_id       INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    data_acionamento DATE NOT NULL,
    hora_inicio      TIME NOT NULL,
    hora_fim         TIME NOT NULL,
    total_minutos    INT,                        -- calculado ao salvar
    chamado          VARCHAR(100) NOT NULL,
    motivo           TEXT NOT NULL,
    feriado          BOOLEAN NOT NULL DEFAULT FALSE,
    fora_do_prazo    BOOLEAN NOT NULL DEFAULT FALSE,
    origem           VARCHAR(255),               -- NULL = lançamento manual
    status           VARCHAR(20) NOT NULL DEFAULT 'pendente'
                     CHECK (status IN ('pendente','aprovado','recusado')),
    nota_revisao       TEXT,
    descricao_feriado  VARCHAR(100),
    valor_calculado    NUMERIC(10,2),
    revisado_por       INT REFERENCES usuarios(id) ON DELETE SET NULL,
    revisado_em        TIMESTAMP,
    criado_em        TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_lanc_usuario ON lancamentos(usuario_id);
CREATE INDEX IF NOT EXISTS idx_lanc_data    ON lancamentos(data_acionamento);
CREATE INDEX IF NOT EXISTS idx_lanc_status  ON lancamentos(status);

-- ── Solicitações de banco de horas ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS solicitacoes_bh (
    id              SERIAL PRIMARY KEY,
    usuario_id      INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    tipo            VARCHAR(20) NOT NULL
                    CHECK (tipo IN ('dia_inteiro','meio_periodo_manha','meio_periodo_tarde','personalizado','deducao_admin')),
    data_inicio     DATE NOT NULL,
    data_fim        DATE,
    hora_inicio     TIME,
    hora_fim        TIME,
    motivo          TEXT NOT NULL,
    total_minutos   INT NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'pendente'
                    CHECK (status IN ('pendente','aprovado','recusado')),
    revisado_por    INT REFERENCES usuarios(id) ON DELETE SET NULL,
    revisado_em     TIMESTAMP,
    nota_revisao    TEXT,
    criado_em       TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_solbh_usuario ON solicitacoes_bh(usuario_id);
CREATE INDEX IF NOT EXISTS idx_solbh_status  ON solicitacoes_bh(status);

-- ── Tokens de recuperação de senha ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tokens_senha (
    token      CHAR(64) PRIMARY KEY,
    usuario_id INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    expira_em  TIMESTAMP NOT NULL,
    usado      BOOLEAN NOT NULL DEFAULT FALSE,
    criado_em  TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_token_usuario ON tokens_senha(usuario_id);

-- ── Escala voluntária mensal ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS escala_voluntaria (
    id               SERIAL PRIMARY KEY,
    usuario_id       INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    data_disponivel  DATE NOT NULL,
    turno            VARCHAR(20) NOT NULL DEFAULT 'dia_todo'
                     CHECK (turno IN ('manha','tarde','noite','dia_todo')),
    observacao       TEXT,
    criado_em        TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em    TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (usuario_id, data_disponivel)
);

CREATE INDEX IF NOT EXISTS idx_escala_data    ON escala_voluntaria(data_disponivel);
CREATE INDEX IF NOT EXISTS idx_escala_usuario ON escala_voluntaria(usuario_id);

-- ── Dados iniciais obrigatórios ───────────────────────────────────────────────
INSERT INTO setores (nome) VALUES
    ('Redes'),
    ('Sustentação'),
    ('Segurança'),
    ('Serviços'),
    ('Atendimento Corporativo')
ON CONFLICT (nome) DO NOTHING;

INSERT INTO perfis (nome) VALUES
    ('analista'),
    ('coordenador'),
    ('administrador')
ON CONFLICT (nome) DO NOTHING;
