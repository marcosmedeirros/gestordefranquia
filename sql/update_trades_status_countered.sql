-- Atualiza o ENUM da coluna status para permitir "countered"
ALTER TABLE trades
MODIFY COLUMN status ENUM('pending', 'accepted', 'rejected', 'cancelled', 'countered') DEFAULT 'pending';
