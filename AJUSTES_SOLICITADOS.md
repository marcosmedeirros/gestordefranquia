# Ajustes Solicitados - 26/01/2026

## Lista de Tarefas

### ✅ 1. Anos das temporadas da SPRINT (aparecem como Zero)
**Status**: Verificar banco de dados
**Ação**: Execute no phpMyAdmin:
```sql
-- Ver sprints atuais
SELECT * FROM sprints ORDER BY league, sprint_number;

-- Se start_year estiver NULL ou 0, atualizar:
UPDATE sprints SET start_year = 2016 WHERE league = 'ELITE' AND start_year IS NULL OR start_year = 0;
UPDATE sprints SET start_year = 2017 WHERE league = 'NEXT' AND start_year IS NULL OR start_year = 0;
UPDATE sprints SET start_year = 2018 WHERE league = 'RISE' AND start_year IS NULL OR start_year = 0;
UPDATE sprints SET start_year = 2019 WHERE league = 'ROOKIE' AND start_year IS NULL OR start_year = 0;

-- Depois, atualizar os anos das temporadas:
UPDATE seasons s
JOIN sprints sp ON s.sprint_id = sp.id
SET s.year = sp.start_year + s.season_number - 1
WHERE s.year = 0 OR s.year IS NULL;
```

### ✅ 2. Resetar trades a cada 2 temporadas
**Requires**: Backend implementation
**Files to create**: `backend/reset-trades-logic.php`

### ✅ 3. Mostrar "via" nas picks trocadas
**Requires**: Frontend + API modification  
**Files**: `trades.php`, `api/trades.php`

### ✅ 4. Corrigir layout mobile da página Meu Time
**Requires**: CSS/HTML adjustment
**File**: `my-roster.php`

### ✅ 5. Adicionar busca de jogadores em Trades Gerais
**Requires**: JavaScript implementation
**File**: `trades.php`, `js/trades.js`

### ✅ 6. Adicionar busca de times
**Requires**: JavaScript implementation
**File**: `teams.php`

### ✅ 7. Garantir unicidade de picks
**Ação**: Execute no phpMyAdmin:
```sql
-- Adicionar constraint de unicidade para picks
ALTER TABLE picks ADD UNIQUE KEY unique_pick (original_team_id, season_year, round);

-- Verificar duplicatas antes:
SELECT original_team_id, season_year, round, COUNT(*) as total
FROM picks
GROUP BY original_team_id, season_year, round
HAVING total > 1;
```

### ✅ 8. Corrigir lógica de seleção de picks trocadas no draft
**Requires**: Backend logic review
**Files**: `api/draft.php`, `drafts.php`

### ✅ 9. Garantir unicidade de jogadores por liga
**Ação**: Execute no phpMyAdmin:
```sql
-- Adicionar constraint de unicidade para jogadores
ALTER TABLE players ADD UNIQUE KEY unique_player_per_team (team_id, name);

-- Para jogadores em draft_pool:
ALTER TABLE draft_pool ADD UNIQUE KEY unique_player_per_league (league, name, season_id);

-- Para free agents:
ALTER TABLE free_agents ADD UNIQUE KEY unique_fa_per_league (league, name);

-- Verificar duplicatas antes:
SELECT name, COUNT(*) FROM players WHERE team_id IN (SELECT id FROM teams WHERE league = 'ROOKIE') GROUP BY name HAVING COUNT(*) > 1;
```

### ✅ 10. Corrigir layout mobile da Free Agency
**Requires**: CSS/HTML adjustment
**File**: `free-agency.php`

## Prioridade de Execução

1. **IMEDIATO** (SQL):
   - Task #1: Corrigir anos das temporadas
   - Task #7: Garantir unicidade de picks
   - Task #9: Garantir unicidade de jogadores

2. **DESENVOLVIMENTO** (Código):
   - Task #3: Mostrar "via" nas picks
   - Task #5: Busca de jogadores em trades
   - Task #6: Busca de times
   - Task #8: Lógica de picks trocadas no draft

3. **LAYOUT** (Frontend):
   - Task #4: Mobile Meu Time
   - Task #10: Mobile Free Agency

4. **LÓGICA DE NEGÓCIO**:
   - Task #2: Reset automático de trades

