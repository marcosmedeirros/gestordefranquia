<?php
/**
 * STOP — o motor.
 *
 * Uma sala de 4 a 10 pessoas, 5 rodadas, 10 temas por rodada e uma letra
 * sorteada. Quem termina primeiro aperta STOP e trava a rodada pra todo mundo;
 * aí as respostas aparecem, cada um denuncia o que achar que não vale, e a
 * maioria decide. Soma das 5 rodadas dá o vencedor.
 *
 * ── Por que tudo mora aqui ─────────────────────────────────────────────────
 *
 * A regra do jogo é o servidor. O cliente manda o que o jogador digitou e o
 * que ele denunciou; quem sorteia a letra, decide quando a rodada acaba, conta
 * denúncia e faz ponto é este arquivo. Num jogo com moeda em cima, qualquer
 * decisão no cliente é uma decisão de quem abrir o DevTools.
 *
 * ── Sem WebSocket ──────────────────────────────────────────────────────────
 *
 * O projeto é PHP atrás de Apache, então não há push: a tela pergunta o estado
 * a cada 1,5s. Isso molda o desenho — não existe "servidor avisa que a rodada
 * acabou". As passagens de fase acontecem na LEITURA do estado: quem chega
 * primeiro depois do tempo esgotado é quem dispara a virada (stopAvancarPrazos).
 * Sem isso, uma sala em que todo mundo fecha a aba ficaria travada pra sempre.
 *
 * ── O que o jogador pode ver ───────────────────────────────────────────────
 *
 * Durante a escrita, ninguém vê a resposta de ninguém — nem por engano no JSON
 * do estado. As respostas alheias só entram no payload quando a rodada vira
 * votação. Mandar tudo e esconder no CSS seria entregar o jogo.
 */

require_once __DIR__ . '/../../backend/db.php';

/** Quantos cabem numa sala. Menos de 4 não dá votação de maioria decente. */
const STOP_MIN_JOGADORES = 4;
const STOP_MAX_JOGADORES = 10;

const STOP_RODADAS        = 5;
const STOP_TEMAS_RODADA   = 10;

/** Pontos, no padrão que todo mundo já conhece de jogo de Stop. */
const STOP_PONTO_UNICO    = 100;
const STOP_PONTO_REPETIDO = 50;

/**
 * Prazos, em segundos.
 *
 * Existem pra sala não morrer de pé: se quem estava jogando fecha a aba no meio
 * da rodada, o tempo estoura e o jogo segue sozinho. O da escrita é generoso
 * porque o normal é alguém apertar STOP bem antes; o da votação é curto porque
 * é só marcar o que não vale.
 */
const STOP_SEG_ESCRITA  = 240;
const STOP_SEG_VOTACAO  = 120;

/** Sala parada há mais que isto sem ninguém dar sinal é considerada abandonada. */
const STOP_SEG_ABANDONO = 1800;

/**
 * As letras que podem sair.
 *
 * K, W, X e Y ficam de fora: em português quase todo tema fica impossível, e
 * uma rodada em que todo mundo faz zero não é desafio, é rodada perdida.
 */
const STOP_LETRAS = ['A','B','C','D','E','F','G','H','I','J','L','M','N','O','P','Q','R','S','T','U','V','Z'];

/** Os 50 temas padrão. */
function stopTemas(): array
{
    return [
        'Nome', 'Sobrenome', 'Animal', 'Fruta', 'Cor', 'Objeto', 'Profissão',
        'País', 'Cidade', 'Marca', 'Comida', 'Bebida', 'Filme', 'Série',
        'Desenho animado', 'Cantor ou banda', 'Música', 'Instrumento musical',
        'Esporte', 'Time de futebol', 'Jogador de futebol', 'Jogador de basquete',
        'Time da NBA', 'Personagem de novela', 'Super-herói', 'Vilão',
        'Jogo (videogame ou tabuleiro)', 'Aplicativo ou site', 'Parte do corpo',
        'Peça de roupa', 'Calçado', 'Meio de transporte', 'Móvel',
        'Eletrodoméstico', 'Ferramenta', 'Flor ou planta', 'Árvore',
        'Coisa gelada', 'Coisa que voa', 'Coisa que faz barulho',
        'Palavra em inglês', 'Adjetivo', 'Verbo', 'Doce ou sobremesa',
        'Tempero ou condimento', 'Matéria escolar', 'Lugar da casa',
        'Lugar público', 'Personagem de desenho', 'Marca de carro',
    ];
}

/** Cria as tabelas na primeira vez que alguém abre o jogo. */
function stopSchema(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS stop_salas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token CHAR(10) NOT NULL,
        criador_id INT NOT NULL,
        aposta INT NOT NULL DEFAULT 0,
        status ENUM('aguardando','escrevendo','votando','encerrada','cancelada') NOT NULL DEFAULT 'aguardando',
        rodada INT NOT NULL DEFAULT 0,
        letra CHAR(1) NULL,
        temas TEXT NULL,
        fase_expira_em DATETIME NULL,
        parou_id INT NULL,
        vencedores TEXT NULL,
        premio INT NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_stop_token (token),
        KEY idx_stop_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // A chave única (sala, user) é o que impede a mesma pessoa de entrar duas
    // vezes com dois cliques e pagar a aposta em dobro.
    $pdo->exec("CREATE TABLE IF NOT EXISTS stop_sala_jogadores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sala_id INT NOT NULL,
        user_id INT NOT NULL,
        nome VARCHAR(80) NOT NULL,
        pontos INT NOT NULL DEFAULT 0,
        pago INT NOT NULL DEFAULT 0,
        pronto TINYINT(1) NOT NULL DEFAULT 0,
        visto_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_ssj (sala_id, user_id),
        KEY idx_ssj_sala (sala_id),
        CONSTRAINT fk_ssj_sala FOREIGN KEY (sala_id) REFERENCES stop_salas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS stop_respostas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sala_id INT NOT NULL,
        rodada INT NOT NULL,
        user_id INT NOT NULL,
        tema_idx INT NOT NULL,
        texto VARCHAR(60) NOT NULL DEFAULT '',
        pontos INT NOT NULL DEFAULT 0,
        motivo VARCHAR(20) NULL,
        UNIQUE KEY uk_sr (sala_id, rodada, user_id, tema_idx),
        KEY idx_sr_rodada (sala_id, rodada),
        CONSTRAINT fk_sr_sala FOREIGN KEY (sala_id) REFERENCES stop_salas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Uma denúncia por pessoa em cada resposta — sem a única, dava pra clicar
    // dez vezes e sozinho derrubar a resposta de alguém.
    $pdo->exec("CREATE TABLE IF NOT EXISTS stop_denuncias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sala_id INT NOT NULL,
        rodada INT NOT NULL,
        tema_idx INT NOT NULL,
        alvo_id INT NOT NULL,
        autor_id INT NOT NULL,
        UNIQUE KEY uk_sd (sala_id, rodada, tema_idx, alvo_id, autor_id),
        KEY idx_sd_rodada (sala_id, rodada),
        CONSTRAINT fk_sd_sala FOREIGN KEY (sala_id) REFERENCES stop_salas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Token curto e sem ambiguidade visual — o link vai ser lido em voz alta no grupo. */
function stopToken(PDO $pdo): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem I, O, 0 e 1
    for ($tentativa = 0; $tentativa < 12; $tentativa++) {
        $t = '';
        for ($i = 0; $i < 6; $i++) $t .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        $st = $pdo->prepare("SELECT 1 FROM stop_salas WHERE token = ?");
        $st->execute([$t]);
        if (!$st->fetchColumn()) return $t;
    }
    throw new RuntimeException('Não consegui gerar um código de sala.');
}

/**
 * Normaliza uma resposta pra comparar.
 *
 * "Açaí", "acai" e " AÇAÍ " são a mesma resposta — sem isto, dois jogadores que
 * escreveram a mesma coisa levariam 100 cada um só porque um deles acentuou.
 */
function stopNormalizar(string $s): string
{
    $s = trim(mb_strtolower($s, 'UTF-8'));
    $de = ['á','à','ã','â','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','õ','ô','ö','ú','ù','û','ü','ç','ñ'];
    $pra = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'];
    $s = str_replace($de, $pra, $s);
    $s = preg_replace('/[^a-z0-9 ]/', '', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** A resposta começa com a letra da rodada? */
function stopComecaCom(string $texto, string $letra): bool
{
    $n = stopNormalizar($texto);
    if ($n === '') return false;
    return $n[0] === stopNormalizar($letra);
}

/** A sala pelo token, ou null. */
function stopSala(PDO $pdo, string $token): ?array
{
    stopSchema($pdo);
    $st = $pdo->prepare("SELECT * FROM stop_salas WHERE token = ?");
    $st->execute([strtoupper(trim($token))]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function stopJogadores(PDO $pdo, int $salaId): array
{
    $st = $pdo->prepare("SELECT * FROM stop_sala_jogadores WHERE sala_id = ? ORDER BY pontos DESC, id ASC");
    $st->execute([$salaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Cria a sala. Quem cria já entra — e já paga, como todo mundo. */
function stopCriar(PDO $pdo, int $userId, string $nome, int $aposta): array
{
    stopSchema($pdo);
    $aposta = max(0, min(5000, $aposta));

    $pdo->beginTransaction();
    try {
        $token = stopToken($pdo);
        $pdo->prepare("INSERT INTO stop_salas (token, criador_id, aposta) VALUES (?,?,?)")
            ->execute([$token, $userId, $aposta]);
        $salaId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $r = stopEntrar($pdo, $token, $userId, $nome);
    if (!$r['ok']) {
        // Não consegue pagar a própria aposta: a sala não pode ficar de pé sem
        // dono, senão vira link morto que ninguém abre.
        $pdo->prepare("DELETE FROM stop_salas WHERE id = ?")->execute([$salaId]);
        return $r;
    }
    return ['ok' => true, 'token' => $token];
}

/** Entra na sala pagando a aposta. */
function stopEntrar(PDO $pdo, string $token, int $userId, string $nome): array
{
    stopSchema($pdo);
    $falha = fn(string $e) => ['ok' => false, 'erro' => $e];

    $sala = stopSala($pdo, $token);
    if (!$sala) return $falha('Sala não encontrada. Confira o link.');

    $pdo->beginTransaction();
    try {
        // Trava a sala: dois cliques simultâneos na última vaga viravam 11
        // jogadores sem isto.
        $st = $pdo->prepare("SELECT * FROM stop_salas WHERE id = ? FOR UPDATE");
        $st->execute([(int)$sala['id']]);
        $sala = $st->fetch(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("SELECT * FROM stop_sala_jogadores WHERE sala_id = ? AND user_id = ?");
        $st->execute([(int)$sala['id'], $userId]);
        if ($st->fetch()) { $pdo->commit(); return ['ok' => true, 'token' => $sala['token']]; }

        if ($sala['status'] !== 'aguardando') {
            $pdo->rollBack();
            return $falha('Essa partida já começou.');
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM stop_sala_jogadores WHERE sala_id = ?");
        $st->execute([(int)$sala['id']]);
        if ((int)$st->fetchColumn() >= STOP_MAX_JOGADORES) {
            $pdo->rollBack();
            return $falha('A sala está cheia (' . STOP_MAX_JOGADORES . ' jogadores).');
        }

        $aposta = (int)$sala['aposta'];
        if ($aposta > 0) {
            $st = $pdo->prepare("SELECT COALESCE(pontos,0) FROM games_usuarios WHERE id = ? FOR UPDATE");
            $st->execute([$userId]);
            $saldo = (int)$st->fetchColumn();
            if ($saldo < $aposta) {
                $pdo->rollBack();
                return $falha('A entrada é ' . $aposta . ' moedas e você tem ' . $saldo . '.');
            }
            $pdo->prepare("UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ?")
                ->execute([$aposta, $userId]);
        }

        $pdo->prepare("INSERT INTO stop_sala_jogadores (sala_id, user_id, nome, pago) VALUES (?,?,?,?)")
            ->execute([(int)$sala['id'], $userId, mb_substr(trim($nome) ?: 'Jogador', 0, 80), $aposta]);
        $pdo->commit();
        return ['ok' => true, 'token' => $sala['token']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Sai da sala.
 *
 * Só devolve a aposta enquanto a partida não começou. Depois disso o dinheiro
 * já está no pote e vai pro vencedor — devolver no meio seria deixar quem está
 * perdendo sacar de volta.
 */
function stopSair(PDO $pdo, string $token, int $userId): array
{
    $sala = stopSala($pdo, $token);
    if (!$sala) return ['ok' => false, 'erro' => 'Sala não encontrada.'];

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM stop_sala_jogadores WHERE sala_id = ? AND user_id = ? FOR UPDATE");
        $st->execute([(int)$sala['id'], $userId]);
        $j = $st->fetch(PDO::FETCH_ASSOC);
        if (!$j) { $pdo->commit(); return ['ok' => true]; }

        if ($sala['status'] === 'aguardando') {
            if ((int)$j['pago'] > 0) {
                $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?")
                    ->execute([(int)$j['pago'], $userId]);
            }
            $pdo->prepare("DELETE FROM stop_sala_jogadores WHERE id = ?")->execute([(int)$j['id']]);

            // Sala sem ninguém não precisa continuar existindo.
            $st = $pdo->prepare("SELECT COUNT(*) FROM stop_sala_jogadores WHERE sala_id = ?");
            $st->execute([(int)$sala['id']]);
            if ((int)$st->fetchColumn() === 0) {
                $pdo->prepare("UPDATE stop_salas SET status = 'cancelada' WHERE id = ?")->execute([(int)$sala['id']]);
            }
        }
        $pdo->commit();
        return ['ok' => true, 'devolvido' => $sala['status'] === 'aguardando' ? (int)$j['pago'] : 0];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Sorteia letra e temas e abre a rodada. */
function stopAbrirRodada(PDO $pdo, array $sala, int $numero): void
{
    $temas = stopTemas();
    shuffle($temas);
    $daRodada = array_slice($temas, 0, STOP_TEMAS_RODADA);
    $letra = STOP_LETRAS[random_int(0, count(STOP_LETRAS) - 1)];

    $pdo->prepare("UPDATE stop_salas
                      SET status = 'escrevendo', rodada = ?, letra = ?, temas = ?,
                          parou_id = NULL, fase_expira_em = DATE_ADD(NOW(), INTERVAL ? SECOND)
                    WHERE id = ?")
        ->execute([$numero, $letra, json_encode($daRodada, JSON_UNESCAPED_UNICODE), STOP_SEG_ESCRITA, (int)$sala['id']]);

    $pdo->prepare("UPDATE stop_sala_jogadores SET pronto = 0 WHERE sala_id = ?")->execute([(int)$sala['id']]);
}

/** O criador começa a partida. */
function stopComecar(PDO $pdo, string $token, int $userId): array
{
    $sala = stopSala($pdo, $token);
    if (!$sala) return ['ok' => false, 'erro' => 'Sala não encontrada.'];
    if ((int)$sala['criador_id'] !== $userId) return ['ok' => false, 'erro' => 'Só quem criou a sala começa a partida.'];
    if ($sala['status'] !== 'aguardando') return ['ok' => false, 'erro' => 'A partida já começou.'];

    $jogadores = stopJogadores($pdo, (int)$sala['id']);
    if (count($jogadores) < STOP_MIN_JOGADORES) {
        return ['ok' => false, 'erro' => 'Precisa de pelo menos ' . STOP_MIN_JOGADORES . ' jogadores (tem ' . count($jogadores) . ').'];
    }

    // O pote é fechado AGORA, com quem está na sala. Recalcular no fim faria o
    // prêmio mudar se alguém saísse no meio.
    $premio = 0;
    foreach ($jogadores as $j) $premio += (int)$j['pago'];
    $pdo->prepare("UPDATE stop_salas SET premio = ? WHERE id = ?")->execute([$premio, (int)$sala['id']]);

    stopAbrirRodada($pdo, $sala, 1);
    return ['ok' => true];
}

/** Salva o que a pessoa digitou. Só vale enquanto a rodada está aberta. */
function stopSalvar(PDO $pdo, string $token, int $userId, array $respostas): array
{
    $sala = stopSala($pdo, $token);
    if (!$sala || $sala['status'] !== 'escrevendo') return ['ok' => false, 'erro' => 'A rodada não está aberta.'];

    $st = $pdo->prepare("INSERT INTO stop_respostas (sala_id, rodada, user_id, tema_idx, texto)
                         VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE texto = VALUES(texto)");
    foreach ($respostas as $idx => $texto) {
        $idx = (int)$idx;
        if ($idx < 0 || $idx >= STOP_TEMAS_RODADA) continue;
        $st->execute([(int)$sala['id'], (int)$sala['rodada'], $userId, $idx, mb_substr(trim((string)$texto), 0, 60)]);
    }
    $pdo->prepare("UPDATE stop_sala_jogadores SET visto_em = NOW() WHERE sala_id = ? AND user_id = ?")
        ->execute([(int)$sala['id'], $userId]);
    return ['ok' => true];
}

/** STOP: trava a rodada pra todo mundo e abre a votação. */
function stopParar(PDO $pdo, string $token, int $userId, array $respostas = []): array
{
    if ($respostas) stopSalvar($pdo, $token, $userId, $respostas);

    $sala = stopSala($pdo, $token);
    if (!$sala) return ['ok' => false, 'erro' => 'Sala não encontrada.'];

    $pdo->beginTransaction();
    try {
        // De novo dentro da transação: dois jogadores apertando STOP no mesmo
        // segundo não podem abrir duas votações.
        $st = $pdo->prepare("SELECT * FROM stop_salas WHERE id = ? FOR UPDATE");
        $st->execute([(int)$sala['id']]);
        $sala = $st->fetch(PDO::FETCH_ASSOC);
        if ($sala['status'] !== 'escrevendo') { $pdo->rollBack(); return ['ok' => true]; }

        $pdo->prepare("UPDATE stop_salas
                          SET status = 'votando', parou_id = ?,
                              fase_expira_em = DATE_ADD(NOW(), INTERVAL ? SECOND)
                        WHERE id = ?")
            ->execute([$userId, STOP_SEG_VOTACAO, (int)$sala['id']]);
        $pdo->prepare("UPDATE stop_sala_jogadores SET pronto = 0 WHERE sala_id = ?")->execute([(int)$sala['id']]);
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Denuncia (ou desfaz a denúncia de) uma resposta. */
function stopDenunciar(PDO $pdo, string $token, int $userId, int $temaIdx, int $alvoId): array
{
    $sala = stopSala($pdo, $token);
    if (!$sala || $sala['status'] !== 'votando') return ['ok' => false, 'erro' => 'A votação não está aberta.'];
    if ($alvoId === $userId) return ['ok' => false, 'erro' => 'Não dá pra denunciar a própria resposta.'];

    $st = $pdo->prepare("SELECT 1 FROM stop_sala_jogadores WHERE sala_id = ? AND user_id = ?");
    $st->execute([(int)$sala['id'], $userId]);
    if (!$st->fetchColumn()) return ['ok' => false, 'erro' => 'Você não está nesta sala.'];

    $chave = [(int)$sala['id'], (int)$sala['rodada'], $temaIdx, $alvoId, $userId];
    $st = $pdo->prepare("SELECT id FROM stop_denuncias
                          WHERE sala_id=? AND rodada=? AND tema_idx=? AND alvo_id=? AND autor_id=?");
    $st->execute($chave);
    if ($id = $st->fetchColumn()) {
        $pdo->prepare("DELETE FROM stop_denuncias WHERE id = ?")->execute([(int)$id]);
        return ['ok' => true, 'denunciado' => false];
    }
    $pdo->prepare("INSERT INTO stop_denuncias (sala_id, rodada, tema_idx, alvo_id, autor_id) VALUES (?,?,?,?,?)")
        ->execute($chave);
    return ['ok' => true, 'denunciado' => true];
}

/** Marca que a pessoa terminou de votar. Todos prontos → apura na hora. */
function stopPronto(PDO $pdo, string $token, int $userId): array
{
    $sala = stopSala($pdo, $token);
    if (!$sala || $sala['status'] !== 'votando') return ['ok' => false, 'erro' => 'A votação não está aberta.'];

    $pdo->prepare("UPDATE stop_sala_jogadores SET pronto = 1 WHERE sala_id = ? AND user_id = ?")
        ->execute([(int)$sala['id'], $userId]);

    $st = $pdo->prepare("SELECT COUNT(*) total, SUM(pronto) prontos FROM stop_sala_jogadores WHERE sala_id = ?");
    $st->execute([(int)$sala['id']]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if ((int)$c['prontos'] >= (int)$c['total']) stopApurar($pdo, (int)$sala['id']);
    return ['ok' => true];
}

/**
 * Fecha a rodada: pontua tudo e decide se abre a próxima ou encerra a partida.
 *
 * A conta, por tema:
 *   vazio, ou não começa com a letra          → 0
 *   derrubado pela maioria dos outros         → 0
 *   sobreviveu e ninguém mais escreveu igual  → 100
 *   sobreviveu e alguém escreveu igual        → 50
 *
 * A comparação de "igual" usa a resposta normalizada e só conta quem também
 * sobreviveu: uma resposta anulada não deve derrubar a de quem acertou pra 50.
 */
function stopApurar(PDO $pdo, int $salaId): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM stop_salas WHERE id = ? FOR UPDATE");
        $st->execute([$salaId]);
        $sala = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sala || $sala['status'] !== 'votando') { $pdo->rollBack(); return; }

        $rodada = (int)$sala['rodada'];
        $letra  = (string)$sala['letra'];

        $jogadores = stopJogadores($pdo, $salaId);
        $nJog = count($jogadores);

        $st = $pdo->prepare("SELECT * FROM stop_respostas WHERE sala_id = ? AND rodada = ?");
        $st->execute([$salaId, $rodada]);
        $respostas = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("SELECT tema_idx, alvo_id, COUNT(*) n FROM stop_denuncias
                              WHERE sala_id = ? AND rodada = ? GROUP BY tema_idx, alvo_id");
        $st->execute([$salaId, $rodada]);
        $denuncias = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $denuncias[(int)$d['tema_idx'] . ':' . (int)$d['alvo_id']] = (int)$d['n'];
        }

        // Maioria dos que PODIAM denunciar (todos menos o dono da resposta).
        $podemDenunciar = max(1, $nJog - 1);
        $maioria = intdiv($podemDenunciar, 2) + 1;

        // Passo 1: quem sobreviveu, e o que cada sobrevivente escreveu.
        $vivos = [];
        $resp  = [];
        foreach ($respostas as $r) {
            $idx = (int)$r['tema_idx'];
            $texto = (string)$r['texto'];
            $motivo = null;
            if (stopNormalizar($texto) === '')            $motivo = 'vazio';
            elseif (!stopComecaCom($texto, $letra))       $motivo = 'letra';
            elseif (($denuncias[$idx . ':' . (int)$r['user_id']] ?? 0) >= $maioria) $motivo = 'denunciado';

            if ($motivo === null) $vivos[$idx][] = stopNormalizar($texto);
            $r['motivo_calc'] = $motivo;
            $resp[] = $r;
        }

        // Passo 2: pontuar.
        $upd = $pdo->prepare("UPDATE stop_respostas SET pontos = ?, motivo = ? WHERE id = ?");
        $ganhos = [];
        foreach ($resp as $r) {
            $idx = (int)$r['tema_idx'];
            $motivo = $r['motivo_calc'];
            if ($motivo !== null) {
                $upd->execute([0, $motivo, (int)$r['id']]);
                continue;
            }
            $n = stopNormalizar((string)$r['texto']);
            $iguais = count(array_filter($vivos[$idx] ?? [], fn($v) => $v === $n));
            $pontos = $iguais > 1 ? STOP_PONTO_REPETIDO : STOP_PONTO_UNICO;
            $upd->execute([$pontos, $iguais > 1 ? 'repetido' : 'unico', (int)$r['id']]);
            $ganhos[(int)$r['user_id']] = ($ganhos[(int)$r['user_id']] ?? 0) + $pontos;
        }

        $somar = $pdo->prepare("UPDATE stop_sala_jogadores SET pontos = pontos + ? WHERE sala_id = ? AND user_id = ?");
        foreach ($jogadores as $j) {
            $somar->execute([$ganhos[(int)$j['user_id']] ?? 0, $salaId, (int)$j['user_id']]);
        }

        if ($rodada >= STOP_RODADAS) {
            stopEncerrar($pdo, $salaId);
        } else {
            stopAbrirRodada($pdo, $sala, $rodada + 1);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Encerra a partida e paga o prêmio.
 *
 * Empate divide o pote. O resto da divisão fica com quem entrou primeiro, em
 * vez de sumir: 101 moedas pra dois não podem virar 100 pagas e 1 evaporada.
 *
 * Não abre transação própria — é chamada de dentro da apuração, que já está
 * numa. Abrir outra aqui daria commit no meio da apuração.
 */
function stopEncerrar(PDO $pdo, int $salaId): void
{
    $jogadores = stopJogadores($pdo, $salaId);
    if (!$jogadores) return;

    $st = $pdo->prepare("SELECT premio FROM stop_salas WHERE id = ?");
    $st->execute([$salaId]);
    $premio = (int)$st->fetchColumn();

    $melhor = 0;
    foreach ($jogadores as $j) $melhor = max($melhor, (int)$j['pontos']);
    $campeoes = array_values(array_filter($jogadores, fn($j) => (int)$j['pontos'] === $melhor));

    $nomes = [];
    if ($premio > 0 && $campeoes) {
        $fatia = intdiv($premio, count($campeoes));
        $sobra = $premio - $fatia * count($campeoes);
        $pagar = $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?");
        foreach ($campeoes as $i => $c) {
            $pagar->execute([$fatia + ($i === 0 ? $sobra : 0), (int)$c['user_id']]);
        }
    }
    foreach ($campeoes as $c) $nomes[] = $c['nome'];

    $pdo->prepare("UPDATE stop_salas SET status = 'encerrada', fase_expira_em = NULL, vencedores = ? WHERE id = ?")
        ->execute([json_encode($nomes, JSON_UNESCAPED_UNICODE), $salaId]);
}

/**
 * Faz o tempo andar.
 *
 * Chamada em toda leitura de estado — é o que substitui um cron. Sem WebSocket
 * ninguém "avisa" que o prazo acabou; quem estiver com a tela aberta descobre e
 * empurra a sala pra frente.
 */
function stopAvancarPrazos(PDO $pdo, array $sala): void
{
    if (empty($sala['fase_expira_em'])) return;
    if (strtotime((string)$sala['fase_expira_em']) > time()) return;

    if ($sala['status'] === 'escrevendo') {
        stopParar($pdo, (string)$sala['token'], 0);
    } elseif ($sala['status'] === 'votando') {
        stopApurar($pdo, (int)$sala['id']);
    }
}

/**
 * O estado da sala pra uma pessoa específica.
 *
 * "Pra uma pessoa específica" é a parte importante: durante a escrita, o
 * payload leva só as respostas de quem pediu. As dos outros entram quando a
 * rodada vira votação — antes disso, mandar e esconder na tela seria o mesmo
 * que não esconder.
 */
function stopEstado(PDO $pdo, string $token, int $userId): ?array
{
    $sala = stopSala($pdo, $token);
    if (!$sala) return null;

    stopAvancarPrazos($pdo, $sala);
    $sala = stopSala($pdo, $token);

    $jogadores = stopJogadores($pdo, (int)$sala['id']);
    $souDaSala = false;
    foreach ($jogadores as $j) if ((int)$j['user_id'] === $userId) $souDaSala = true;

    if ($souDaSala) {
        $pdo->prepare("UPDATE stop_sala_jogadores SET visto_em = NOW() WHERE sala_id = ? AND user_id = ?")
            ->execute([(int)$sala['id'], $userId]);
    }

    $temas = $sala['temas'] ? (json_decode((string)$sala['temas'], true) ?: []) : [];

    $out = [
        'token'      => $sala['token'],
        'status'     => $sala['status'],
        'aposta'     => (int)$sala['aposta'],
        'premio'     => (int)$sala['premio'],
        'rodada'     => (int)$sala['rodada'],
        'rodadas'    => STOP_RODADAS,
        'letra'      => $sala['letra'],
        'temas'      => $temas,
        'sou_criador'=> (int)$sala['criador_id'] === $userId,
        'sou_da_sala'=> $souDaSala,
        'na_sala'    => count($jogadores),
        'min'        => STOP_MIN_JOGADORES,
        'max'        => STOP_MAX_JOGADORES,
        'segundos'   => $sala['fase_expira_em'] ? max(0, strtotime((string)$sala['fase_expira_em']) - time()) : null,
        'parou'      => null,
        'vencedores' => $sala['vencedores'] ? (json_decode((string)$sala['vencedores'], true) ?: []) : [],
        'jogadores'  => array_map(fn($j) => [
            'user_id' => (int)$j['user_id'],
            'nome'    => $j['nome'],
            'pontos'  => (int)$j['pontos'],
            'pronto'  => (int)$j['pronto'] === 1,
            'sou_eu'  => (int)$j['user_id'] === $userId,
        ], $jogadores),
    ];

    foreach ($jogadores as $j) {
        if ((int)$j['user_id'] === (int)$sala['parou_id']) $out['parou'] = $j['nome'];
    }

    // As minhas respostas da rodada, sempre — é o que a tela repõe quando
    // alguém atualiza a página no meio da rodada.
    $out['minhas'] = [];
    if ((int)$sala['rodada'] > 0) {
        $st = $pdo->prepare("SELECT tema_idx, texto FROM stop_respostas WHERE sala_id=? AND rodada=? AND user_id=?");
        $st->execute([(int)$sala['id'], (int)$sala['rodada'], $userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['minhas'][(int)$r['tema_idx']] = $r['texto'];
    }

    if ($sala['status'] === 'votando' || $sala['status'] === 'encerrada') {
        $rodada = (int)$sala['rodada'];
        $st = $pdo->prepare("SELECT * FROM stop_respostas WHERE sala_id=? AND rodada=?");
        $st->execute([(int)$sala['id'], $rodada]);
        $todas = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("SELECT tema_idx, alvo_id, COUNT(*) n,
                                    SUM(autor_id = ?) minha
                               FROM stop_denuncias WHERE sala_id=? AND rodada=?
                              GROUP BY tema_idx, alvo_id");
        $st->execute([$userId, (int)$sala['id'], $rodada]);
        $den = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $den[(int)$d['tema_idx'] . ':' . (int)$d['alvo_id']] = ['n' => (int)$d['n'], 'minha' => (int)$d['minha'] > 0];
        }

        $grade = [];
        foreach ($todas as $r) {
            $idx = (int)$r['tema_idx']; $uid = (int)$r['user_id'];
            $d = $den[$idx . ':' . $uid] ?? ['n' => 0, 'minha' => false];
            $grade[$idx][] = [
                'user_id'   => $uid,
                'texto'     => $r['texto'],
                'pontos'    => (int)$r['pontos'],
                'motivo'    => $r['motivo'],
                'denuncias' => $d['n'],
                'denunciei' => $d['minha'],
            ];
        }
        $out['grade'] = $grade;
        $out['maioria'] = intdiv(max(1, count($jogadores) - 1), 2) + 1;
    }

    return $out;
}
