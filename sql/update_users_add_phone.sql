-- Adiciona coluna phone à tabela users caso ainda não exista
ALTER TABLE users
ADD COLUMN phone VARCHAR(30) NULL AFTER photo_url,
ADD INDEX idx_user_phone (phone);
