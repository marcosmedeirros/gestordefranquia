-- Atualizar trades dos times da ROOKIE para 4/10
-- Execute este script no phpMyAdmin ou via linha de comando

UPDATE teams 
SET trades_made = 4, 
    max_trades = 10 
WHERE league = 'ROOKIE';

-- Verificar a atualização
SELECT id, city, name, league, trades_made, max_trades 
FROM teams 
WHERE league = 'ROOKIE'
ORDER BY city, name;
