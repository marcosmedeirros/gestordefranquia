-- ---------------------------------------------------------------------------
-- Fusão do games.fbabrasil.com.br para dentro do fbabrasil.com.br
--
-- Cria, no banco PRINCIPAL, apenas as tabelas que o código do games NÃO cria
-- sozinho. As outras ~43 nascem no primeiro acesso via CREATE TABLE IF NOT
-- EXISTS espalhado pelas próprias páginas — não precisam estar aqui.
--
-- Ficaram de fora de propósito:
--   * corrida_*, crypto_*, mario_*, uno_*  -> jogos sem código no repositório
--   * tigrinho_historico                   -> tigrinho não vem na fusão
--
-- COLLATE é omitido de propósito: as tabelas herdam a do banco, então o mesmo
-- arquivo roda em produção (utf8mb4_uca1400_ai_ci) e no MySQL local.
-- ---------------------------------------------------------------------------

-- ── Perfil de jogo do usuário ───────────────────────────────────────────────
-- Substitui a antiga tabela `usuarios` do banco do games. A identidade agora é
-- única: `id` é o próprio `users.id` do site (sem AUTO_INCREMENT), e a linha
-- morre junto com o usuário. Login, senha e recuperação saem daqui — quem
-- cuida disso é o backend/auth.php do site.
--
-- `nome` e `email` continuam existindo como espelho de users.name/users.email
-- porque o código do games lê essas colunas em rankings e listagens.
CREATE TABLE IF NOT EXISTS `games_usuarios` (
  `id` INT(11) NOT NULL,
  `nome` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `pontos` INT(11) DEFAULT 50,
  `fba_points` INT(11) NOT NULL DEFAULT 0,
  `acertos_eventos` INT(11) NOT NULL DEFAULT 0,
  `criado_em` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `is_admin` TINYINT(1) DEFAULT 0,
  `cafes_feitos` INT(11) DEFAULT 0,
  `cafes_pagos` INT(11) DEFAULT 0,
  `cafes_comprados` INT(11) DEFAULT 0,
  `cafes_comprados_pagos` INT(11) DEFAULT 0,
  `skin_equipada` VARCHAR(50) DEFAULT 'default',
  `flappy_skin_equipada` VARCHAR(50) DEFAULT 'default',
  `memoria_streak` INT(11) DEFAULT 0,
  `memoria_last` DATE DEFAULT NULL,
  `termo_streak` INT(11) DEFAULT 0,
  `termo_last` DATE DEFAULT NULL,
  `league` ENUM('ELITE','RISE','NEXT','ROOKIE') DEFAULT 'ROOKIE',
  `copa26_pago` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_gu_email` (`email`),
  CONSTRAINT `fk_games_usuarios_user`
    FOREIGN KEY (`id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Apostas ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `eventos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(255) NOT NULL,
  `data_limite` DATETIME NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'aberta',
  `vencedor_opcao_id` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `opcoes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `evento_id` INT(11) NOT NULL,
  `descricao` VARCHAR(100) NOT NULL,
  `odd` DECIMAL(10,2) NOT NULL,
  `odd_inicial` DECIMAL(10,2) DEFAULT NULL,
  `img_url` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `evento_id` (`evento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `palpites` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` INT(11) NOT NULL,
  `opcao_id` INT(11) NOT NULL,
  `valor` DECIMAL(10,2) NOT NULL,
  `data_palpite` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `odd_registrada` DECIMAL(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_usuario` (`id_usuario`),
  KEY `opcao_id` (`opcao_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Blackjack ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bj_salas` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `status` VARCHAR(20) DEFAULT 'lobby',
  `deck` TEXT DEFAULT NULL,
  `dealer_hand` TEXT DEFAULT NULL,
  `turno_posicao` INT(11) DEFAULT 0,
  `data_criacao` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `ultimo_update` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `timer_inicio` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bj_jogadores` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_sala` INT(11) DEFAULT NULL,
  `id_usuario` INT(11) DEFAULT NULL,
  `nome` VARCHAR(50) DEFAULT NULL,
  `maos` TEXT DEFAULT NULL,
  `bet_inicial` INT(11) DEFAULT 0,
  `status` VARCHAR(20) DEFAULT 'aguardando',
  `posicao` INT(11) DEFAULT NULL,
  `premio_coletado` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_bj_sala` (`id_sala`),
  KEY `idx_bj_usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Poker ───────────────────────────────────────────────────────────────────
-- (poker_salas e poker_jogadores o próprio código cria)
CREATE TABLE IF NOT EXISTS `poker_mesas` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(50) DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'esperando',
  `pot_total` INT(11) DEFAULT 0,
  `cartas_mesa` VARCHAR(50) DEFAULT '',
  `turno_posicao` INT(11) DEFAULT 0,
  `dealer_posicao` INT(11) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Xadrez ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `xadrez_partidas` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_desafiante` INT(11) NOT NULL,
  `id_desafiado` INT(11) NOT NULL,
  `valor_aposta` INT(11) NOT NULL,
  `fen` VARCHAR(150) DEFAULT 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
  `status` ENUM('pendente','recusada','andamento','finalizada','empate') DEFAULT 'pendente',
  `vez_de` INT(11) NOT NULL,
  `vencedor` INT(11) DEFAULT NULL,
  `data_criacao` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `data_ultimo_movimento` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `tempo_brancas` INT(11) DEFAULT 600,
  `tempo_pretas` INT(11) DEFAULT 600,
  `ultimo_movimento` BIGINT(20) DEFAULT NULL,
  `pgn` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_xadrez_desafiante` (`id_desafiante`),
  KEY `idx_xadrez_desafiado` (`id_desafiado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Históricos diários que o código não cria ────────────────────────────────
CREATE TABLE IF NOT EXISTS `dino_historico` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` INT(11) NOT NULL,
  `data_jogo` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `pontuacao_final` INT(11) NOT NULL,
  `pontos_ganhos` INT(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_dino_usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `memoria_historico` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` INT(11) NOT NULL,
  `data_jogo` DATE NOT NULL,
  `tempo_segundos` INT(11) NOT NULL,
  `movimentos` INT(11) NOT NULL,
  `pontos_ganhos` INT(11) DEFAULT 0,
  `streak_count` INT(11) DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `status` VARCHAR(20) DEFAULT 'jogando',
  `estado_jogo` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_memoria_dia` (`id_usuario`,`data_jogo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `termo_historico` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` INT(11) NOT NULL,
  `data_jogo` DATE NOT NULL,
  `ganhou` TINYINT(1) DEFAULT 0,
  `tentativas` INT(11) DEFAULT 0,
  `pontos_ganhos` INT(11) DEFAULT 0,
  `streak_count` INT(11) DEFAULT 0,
  `palavras_tentadas` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_termo_dia` (`id_usuario`,`data_jogo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
