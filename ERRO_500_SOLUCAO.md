# 🔧 Guia de Solução do Erro 500

## Problema Identificado
O erro 500 está ocorrendo porque o schema do banco de dados está desatualizado. As tabelas `users`, `teams`, `divisions` e `drafts` não possuem a coluna `league` que é necessária para o sistema de múltiplas ligas.

## Solução Rápida

### Opção 1: Script de Migração Automática (RECOMENDADO)
Acesse a URL abaixo no seu navegador:

```
https://marcosmedeiros.page/backend/migrate.php
```

Este script irá:
- ✅ Criar a tabela `leagues` se não existir
- ✅ Inserir as 4 ligas padrão (ELITE, PRIME, RISE, ROOKIE)
- ✅ Adicionar a coluna `league` nas tabelas users, teams, divisions e drafts
- ✅ Criar índices para melhorar a performance

**Importante:** Após executar a migração com sucesso, você pode deletar o arquivo `backend/migrate.php` por segurança.

### Opção 2: Migração Manual via phpMyAdmin
Se preferir executar manualmente, acesse o phpMyAdmin do Hostinger e execute este SQL:

```sql
-- 1. Criar tabela de ligas
CREATE TABLE IF NOT EXISTS leagues (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name ENUM('ELITE', 'PRIME', 'RISE', 'ROOKIE') NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Inserir ligas padrão
INSERT IGNORE INTO leagues (name, description) VALUES
('ELITE', 'Liga Elite - Nível mais alto'),
('PRIME', 'Liga Prime - Nível intermediário superior'),
('RISE', 'Liga Rise - Nível intermediário'),
('ROOKIE', 'Liga Rookie - Nível inicial');

-- 3. Adicionar coluna league à tabela users
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS league ENUM('ELITE', 'PRIME', 'RISE', 'ROOKIE') NOT NULL DEFAULT 'ROOKIE' AFTER user_type,
ADD INDEX IF NOT EXISTS idx_users_league (league);

-- 4. Adicionar coluna league à tabela teams
ALTER TABLE teams 
ADD COLUMN IF NOT EXISTS league ENUM('ELITE', 'PRIME', 'RISE', 'ROOKIE') NOT NULL DEFAULT 'ROOKIE' AFTER user_id,
ADD INDEX IF NOT EXISTS idx_teams_league (league);

-- 5. Adicionar coluna league à tabela divisions
ALTER TABLE divisions 
ADD COLUMN IF NOT EXISTS league ENUM('ELITE', 'PRIME', 'RISE', 'ROOKIE') NOT NULL DEFAULT 'ROOKIE' AFTER name,
ADD INDEX IF NOT EXISTS idx_divisions_league (league);

-- 6. Adicionar coluna league à tabela drafts
ALTER TABLE drafts 
ADD COLUMN IF NOT EXISTS league ENUM('ELITE', 'PRIME', 'RISE', 'ROOKIE') NOT NULL DEFAULT 'ROOKIE' AFTER year,
ADD INDEX IF NOT EXISTS idx_drafts_league (league);
```

### Opção 3: Recriar o Banco do Zero
Se você não tem dados importantes e quer começar limpo:

1. Acesse o phpMyAdmin
2. Selecione o banco `u289267434_gmfba`
3. Clique em "Operações" → "Remover o banco de dados"
4. Na próxima vez que acessar o site, o schema será criado automaticamente com as colunas corretas

## Verificação
Após executar qualquer uma das opções acima, acesse:
```
https://marcosmedeiros.page/
```

Você deverá ver a página de login normalmente. 

## Sistema de Múltiplas Ligas
Agora o sistema suporta 4 ligas independentes:
- 🏆 **ELITE** - Liga Elite (nível mais alto)
- 💎 **PRIME** - Liga Prime (nível intermediário superior)  
- 🌟 **RISE** - Liga Rise (nível intermediário)
- 🌱 **ROOKIE** - Liga Rookie (nível inicial)

Cada usuário pertence a uma liga e só vê/gerencia dados da sua própria liga.

## Logs de Erro
Se ainda houver problemas, verifique os logs:
- Hostinger: Painel → Arquivos → Logs
- Navegador: Console (F12)

## Suporte
Se o problema persistir após a migração, entre em contato com o desenvolvedor.
