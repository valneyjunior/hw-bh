-- Migration v2 — rodar no banco existente (MySQL 8.0)
-- Execute: docker exec -i <container_db> mysql -ubh_user -p bh_tracker < migration_v2.sql

-- Adiciona rejected_at/by/reason na tabela records (se não existir)
SET @cnt = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='records' AND COLUMN_NAME='rejected_at');
SET @sql = IF(@cnt=0,'ALTER TABLE records ADD COLUMN rejected_at DATETIME NULL, ADD COLUMN rejected_by CHAR(36) NULL, ADD COLUMN reject_reason TEXT NULL','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Adiciona work_start/work_end na tabela collaborator_salary (se não existir)
SET @cnt = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collaborator_salary' AND COLUMN_NAME='work_start');
SET @sql = IF(@cnt=0,'ALTER TABLE collaborator_salary ADD COLUMN work_start TIME NOT NULL DEFAULT "08:00:00", ADD COLUMN work_end TIME NOT NULL DEFAULT "18:00:00"','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Adiciona request_date/period_type na tabela bh_requests (se não existir)
SET @cnt = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bh_requests' AND COLUMN_NAME='request_date');
SET @sql = IF(@cnt=0,'ALTER TABLE bh_requests ADD COLUMN request_date DATE NULL, ADD COLUMN period_type VARCHAR(30) NULL','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Adiciona request_date_end na tabela bh_requests (se não existir)
SET @cnt = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bh_requests' AND COLUMN_NAME='request_date_end');
SET @sql = IF(@cnt=0,'ALTER TABLE bh_requests ADD COLUMN request_date_end DATE NULL AFTER request_date','SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
