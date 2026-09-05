<?php
/**
 * ATUALIZAÇÃO DE ELENCO POR TERCEIROS
 *
 * Um GM pode preencher skills e estatísticas do time de OUTRO GM da mesma
 * liga, e recebe moedas por isso. Existe porque tem time parado há semanas
 * sem stats nem skills, e o dono não vai voltar — quem quiser ajudar agora
 * tem um caminho, e é pago.
 *
 * AS REGRAS, e por que cada uma:
 *
 *   Só a própria liga. Quem joga a ELITE conhece os elencos da ELITE; quem
 *   não conhece não deveria estar preenchendo número de ninguém.
 *
 *   Terceiro só uma vez por time. Sem isso vira máquina de moeda: sobe o
 *   CSV, ganha, sobe de novo, ganha de novo. O DONO continua atualizando
 *   quantas vezes quiser — a página dele é outra e não passa por aqui.
 *
 *   Só por CSV. O caminho de foto usa IA paga e é do dono. Aqui o terceiro
 *   monta o CSV como quiser (inclusive com IA por conta dele) e sobe.
 *
 *   Guarda o ANTES. É o que torna o admin capaz de reverter de verdade —
 *   não só destravar o time, mas devolver os valores que estavam lá.
 */

require_once __DIR__ . '/helpers.php';

/** Quanto cada tipo de atualização paga, em moedas. */
const ATUALIZACAO_MOEDAS = ['skills' => 100, 'stats' => 80];

/** As dez skills, na ordem em que aparecem no CSV. */
const ATUALIZACAO_SKILLS = [
    'skill_in' => 'IN', 'skill_mid' => 'MID', 'skill_3pt' => '3PT',
    'skill_post_d' => 'POST D', 'skill_per_d' => 'PER D', 'skill_play' => 'PLAY',
    'skill_reb' => 'REB', 'skill_athl' => 'ATHL', 'skill_iq' => 'IQ', 'skill_pot' => 'POT',
];

/** Notas aceitas. Qualquer coisa fora disso é recusada, não convertida. */
const ATUALIZACAO_NOTAS = ['A+','A','A-','B+','B','B-','C+','C','C-','D+','D','D-','F'];

/** Colunas de estatística e o teto plausível de cada uma. */
const ATUALIZACAO_STATS = [
    'games'  => ['rot' => 'Jogos', 'max' => 120],
    'min_pg' => ['rot' => 'MIN',   'max' => 48],
    'pts_pg' => ['rot' => 'PTS',   'max' => 60],
    'reb_pg' => ['rot' => 'REB',   'max' => 30],
    'ast_pg' => ['rot' => 'AST',   'max' => 25],
    /* ROU e TOC saem das colunas STL e BLK do print — NÃO da coluna TO, que é
       turnover (bolas perdidas) e não é lançada em lugar nenhum. TO e TOC são
       quase a mesma sigla, e é assim que armador aparece com 8,8 tocos.
       Os tetos são o que separa engano de dado: 12 aceitava o turnover de
       qualquer um, 5 e 6 recusam. O recorde da NBA é 3,7 roubos e 5,6 tocos —
       sobra folga pra jogador real. */
    'stl_pg' => ['rot' => 'ROU',   'max' => 5],
    'blk_pg' => ['rot' => 'TOC',   'max' => 6],
];

function ensureAtualizacaoTables(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto || $pdo->inTransaction()) return;
    $pronto = true;

    try {
        // O histórico. Guarda o ANTES em JSON: é o que permite reverter de
        // verdade, e não só liberar o time pra outra tentativa.
        $pdo->exec("CREATE TABLE IF NOT EXISTS atualizacoes_terceiros (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team_id INT NOT NULL,
            league VARCHAR(20) NOT NULL,
            user_id INT NOT NULL,
            tipo VARCHAR(10) NOT NULL,
            jogadores INT NOT NULL DEFAULT 0,
            moedas INT NOT NULL DEFAULT 0,
            antes LONGTEXT NULL,
            csv LONGTEXT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            revertido_em DATETIME NULL,
            revertido_por INT NULL,
            INDEX idx_at_time (team_id, revertido_em),
            INDEX idx_at_user (user_id, revertido_em),
            INDEX idx_at_liga (league, revertido_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // A trava vive no time: uma consulta a menos em toda listagem.
        $cols = $pdo->query("SHOW COLUMNS FROM teams")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('atualizado_terceiro_por', $cols, true)) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN atualizado_terceiro_por INT NULL");
            $pdo->exec("ALTER TABLE teams ADD COLUMN atualizado_terceiro_em DATETIME NULL");
        }
    } catch (Throwable $e) {
        error_log('[atualizacoes] tabelas: ' . $e->getMessage());
    }
}

/**
 * O que este time já recebeu de terceiros: ['skills' => bool, 'stats' => bool].
 *
 * A trava é POR TIPO, não por time. Quem preenche as skills e não tem as
 * estatísticas em mãos deixava o time trancado pela metade — e ninguém mais
 * podia completar. Envio revertido não conta: reverter existe pra liberar.
 *
 * Ponto único de quem responde "já foi feito?", pra tela, API e regra não
 * divergirem.
 */
function atualizacaoTiposFeitos(PDO $pdo, int $teamId): array
{
    $feito = ['skills' => false, 'stats' => false];
    try {
        $st = $pdo->prepare("SELECT DISTINCT tipo FROM atualizacoes_terceiros
                             WHERE team_id = ? AND revertido_em IS NULL");
        $st->execute([$teamId]);
        foreach ($st as $r) {
            if (isset($feito[$r['tipo']])) $feito[$r['tipo']] = true;
        }
    } catch (Throwable $e) {
        error_log('[atualizacoes] tipos feitos: ' . $e->getMessage());
    }
    return $feito;
}

/** Os tipos que ainda faltam num time. Vazio = não há mais o que fazer. */
function atualizacaoTiposQueFaltam(PDO $pdo, int $teamId): array
{
    return array_keys(array_filter(atualizacaoTiposFeitos($pdo, $teamId), fn($f) => !$f));
}

/**
 * O mesmo pra uma liga inteira, numa consulta só: [team_id => ['skills'=>bool,
 * 'stats'=>bool]]. Existe pra lista de times e pra tela de times não fazerem
 * uma ida ao banco por linha.
 */
function atualizacaoTiposFeitosDaLiga(PDO $pdo, string $liga): array
{
    $mapa = [];
    try {
        $st = $pdo->prepare("SELECT DISTINCT team_id, tipo FROM atualizacoes_terceiros
                             WHERE league = ? AND revertido_em IS NULL");
        $st->execute([strtoupper($liga)]);
        foreach ($st as $r) {
            $id = (int)$r['team_id'];
            if (!isset($mapa[$id])) $mapa[$id] = ['skills' => false, 'stats' => false];
            if (isset($mapa[$id][$r['tipo']])) $mapa[$id][$r['tipo']] = true;
        }
    } catch (Throwable $e) {
        error_log('[atualizacoes] tipos da liga: ' . $e->getMessage());
    }
    return $mapa;
}

/**
 * Este usuário pode atualizar este time?
 *
 * Devolve ['pode' => bool, 'motivo' => string, 'time' => array|null,
 * 'faltam' => string[]]. O motivo é texto de tela: quem não pode merece
 * saber por quê.
 */
function atualizacaoPodeAtualizar(PDO $pdo, int $userId, int $teamId, string $ligaDoUsuario): array
{
    $st = $pdo->prepare("SELECT t.id, t.city, t.name, t.league, t.user_id,
                                t.atualizado_terceiro_por, t.atualizado_terceiro_em,
                                u.name AS dono
                         FROM teams t LEFT JOIN users u ON u.id = t.user_id
                         WHERE t.id = ? LIMIT 1");
    $st->execute([$teamId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return ['pode' => false, 'motivo' => 'Time não encontrado.', 'time' => null];

    if (strtoupper((string)$t['league']) !== strtoupper($ligaDoUsuario)) {
        return ['pode' => false, 'motivo' => 'Esse time é de outra liga.', 'time' => $t];
    }
    if ((int)$t['user_id'] === $userId) {
        return ['pode' => false, 'motivo' => 'Esse time é seu — use "Atualizar elenco" no seu painel.', 'time' => $t];
    }
    $faltam = atualizacaoTiposQueFaltam($pdo, $teamId);
    if (!$faltam) {
        return ['pode' => false, 'motivo' => 'Este time já teve skills e estatísticas atualizadas.',
                'time' => $t, 'faltam' => []];
    }
    return ['pode' => true, 'motivo' => '', 'time' => $t, 'faltam' => $faltam];
}

/**
 * Foto dos valores atuais, pra poder desfazer depois.
 *
 * Guarda skills E stats sempre, mesmo quando só um dos dois vai mudar: o
 * custo é um SELECT e alguns KB, e a alternativa é descobrir na hora de
 * reverter que o outro lado não foi salvo.
 */
function atualizacaoFoto(PDO $pdo, int $teamId): array
{
    $colsSkill = implode(', ', array_keys(ATUALIZACAO_SKILLS));
    $foto = ['skills' => [], 'stats' => []];

    $st = $pdo->prepare("SELECT id, name, ovr, age, {$colsSkill} FROM players WHERE team_id = ?");
    $st->execute([$teamId]);
    $foto['skills'] = $st->fetchAll(PDO::FETCH_ASSOC);

    try {
        $colsStat = implode(', ', array_keys(ATUALIZACAO_STATS));
        $st = $pdo->prepare("SELECT id, player_id, season_id, {$colsStat}
                             FROM player_season_stats WHERE team_id = ?");
        $st->execute([$teamId]);
        $foto['stats'] = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Sem stats da temporada ainda: a foto do lado de skills já basta.
        error_log('[atualizacoes] foto stats: ' . $e->getMessage());
    }
    return $foto;
}

/**
 * Valida uma linha de skills. Devolve [válido, valores, erro].
 *
 * Recusa em vez de corrigir: nota fora da tabela é sinal de CSV montado
 * errado, e adivinhar o que a pessoa quis dizer é o caminho pra gravar
 * número inventado no elenco de outro GM.
 */
function atualizacaoValidarSkills(array $linha): array
{
    $vals = [];
    foreach (array_keys(ATUALIZACAO_SKILLS) as $col) {
        $v = trim((string)($linha[$col] ?? ''));
        if ($v === '') continue;                       // em branco = não mexe
        $v = strtoupper($v);
        if (!in_array($v, ATUALIZACAO_NOTAS, true)) {
            return [false, [], "nota inválida em {$col}: {$v}"];
        }
        $vals[$col] = $v;
    }
    /* OVR e idade NÃO entram, mesmo quando vêm no CSV.
       O CSV é baixado num momento e enviado em outro: no meio, o dono do time
       sobe o OVR dos titulares na mão. Quando o envio gravava esses campos, o
       número velho do arquivo voltava por cima do novo e o GM via os bonecos
       "voltando pro over anterior" sem ter mexido em nada. Coluna que vem no
       arquivo é ignorada em silêncio — recusar a linha inteira faria o CSV
       antigo de todo mundo parar de funcionar. Quem muda OVR e idade é o dono
       do elenco, pela edição do jogador. */
    return [true, $vals, ''];
}

/** Valida uma linha de estatística. Mesma postura: recusa, não conserta. */
function atualizacaoValidarStats(array $linha): array
{
    $vals = [];
    foreach (ATUALIZACAO_STATS as $col => $regra) {
        $v = $linha[$col] ?? '';
        if ($v === '' || $v === null) { $vals[$col] = 0; continue; }
        $n = (float)str_replace(',', '.', (string)$v);
        if ($n < 0 || $n > $regra['max']) {
            return [false, [], "{$regra['rot']} fora da faixa (0–{$regra['max']}): {$n}"];
        }
        $vals[$col] = $col === 'games' ? (int)$n : round($n, 1);
    }
    // Linha vazia não vale envio nem moeda.
    if (($vals['games'] ?? 0) <= 0 && ($vals['pts_pg'] ?? 0) <= 0) {
        return [false, [], 'linha sem jogos e sem pontos'];
    }
    return [true, $vals, ''];
}

/** Quanto este usuário já ganhou atualizando time dos outros. */
function atualizacaoResumoDoUsuario(PDO $pdo, int $userId): array
{
    try {
        $st = $pdo->prepare("SELECT COUNT(DISTINCT team_id) AS times, COUNT(*) AS envios,
                                    COALESCE(SUM(moedas), 0) AS moedas
                             FROM atualizacoes_terceiros
                             WHERE user_id = ? AND revertido_em IS NULL");
        $st->execute([$userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['times' => 0, 'envios' => 0, 'moedas' => 0];
    } catch (Throwable $e) {
        return ['times' => 0, 'envios' => 0, 'moedas' => 0];
    }
}

/** Ranking de quem mais atualizou, de uma liga. */
function atualizacaoRanking(PDO $pdo, string $liga, int $limite = 20): array
{
    try {
        $st = $pdo->prepare("SELECT a.user_id, u.name AS gm,
                                    COUNT(DISTINCT a.team_id) AS times,
                                    COALESCE(SUM(a.moedas), 0) AS moedas,
                                    MAX(a.criado_em) AS ultimo
                             FROM atualizacoes_terceiros a
                             JOIN users u ON u.id = a.user_id
                             WHERE a.league = ? AND a.revertido_em IS NULL
                             GROUP BY a.user_id, u.name
                             ORDER BY times DESC, moedas DESC, ultimo ASC
                             LIMIT {$limite}");
        $st->execute([strtoupper($liga)]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[atualizacoes] ranking: ' . $e->getMessage());
        return [];
    }
}
