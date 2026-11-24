<?php
/**
 * MINHA TEMPORADA — o jogo.
 *
 * Você escolhe um time da FBA e simula a temporada dele: os 82 jogos, um a
 * um, com box score; os outros times você só acompanha na tabela. No fim,
 * play-in, playoffs e as corridas de MVP, Evolução, 6º Homem, Defensor e
 * Novato. Cada etapa vencida paga moeda, e paga mais quanto pior for o
 * elenco que você pegou.
 *
 * ── UM SAVE POR PESSOA ───────────────────────────────────────────────
 * Não há slots. A temporada é uma aposta: você escolhe o time sabendo o que
 * ele paga, e segue com ele até o fim. Dois saves em paralelo seriam duas
 * apostas na mesma rodada, e aí o azarão deixa de custar alguma coisa. Pra
 * recomeçar, apaga a que está em curso — e perde o que ela já tinha pago.
 *
 * O save é um SQLite isolado em games/storage/temporada/, com o schema do
 * motor do simulador. @see temporada_motor.php
 */

require_once __DIR__ . '/temporada_motor.php';
require_once __DIR__ . '/temporada_liga.php';
require_once __DIR__ . '/temporada_premios.php';

/** Onde os saves moram. Fora do git: é dado de jogador, não código. */
function temporadaPasta(): string
{
    $dir = dirname(__DIR__) . '/storage/temporada';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function temporadaArquivo(int $uid): string
{
    return temporadaPasta() . '/t_' . $uid . '.sqlite';
}

function temporadaExiste(int $uid): bool
{
    return $uid > 0 && is_file(temporadaArquivo($uid));
}

/**
 * Aponta o motor pro save desta pessoa.
 *
 * Devolve false quando não há temporada em curso — a tela então mostra a
 * escolha de time em vez de quebrar procurando uma tabela vazia.
 */
function temporadaAbrir(int $uid): bool
{
    if (!temporadaExiste($uid)) return false;
    Database::useSavePath(temporadaArquivo($uid));
    return true;
}

/**
 * Começa uma temporada: monta a liga, escolhe o time e congela a força.
 *
 * @return array{ok:bool,erro?:string,time?:string}
 */
function temporadaCriar(int $uid, string $liga, int $fbaTeamId, string $gmNome): array
{
    if ($uid <= 0) return ['ok' => false, 'erro' => 'Faça login pra jogar.'];
    if (temporadaExiste($uid)) return ['ok' => false, 'erro' => 'Você já tem uma temporada em andamento.'];

    $liga = strtoupper(trim($liga));
    if (!in_array($liga, TemporadaLiga::LIGAS, true)) return ['ok' => false, 'erro' => 'Liga inválida.'];
    if ($fbaTeamId <= 0) return ['ok' => false, 'erro' => 'Escolha um time.'];

    $arquivo = temporadaArquivo($uid);
    try {
        Database::useSavePath($arquivo);
        TemporadaLiga::run($liga);

        $st = Database::conn()->prepare("SELECT * FROM teams WHERE fba_team_id = ?");
        $st->execute([$fbaTeamId]);
        $time = $st->fetch();
        if (!$time) throw new RuntimeException('Esse time não está na ' . $liga . '.');

        League::setGmTeam((int) $time['id']);
        Database::setMeta('gm_name', trim($gmNome) !== '' ? trim($gmNome) : 'GM');
        Database::setMeta('save_name', 'Minha Temporada · ' . $liga);
        Database::setMeta('difficulty', 'normal');
        Database::setMeta('phase', 'regular');

        /* A FORÇA É MEDIDA AGORA E CONGELADA: é ela que decide quanto cada
           etapa paga. Medir depois deixaria a conta à mercê de uma troca
           feita no site no meio da temporada. */
        TemporadaPremios::calibrar((int) $time['id']);

        return ['ok' => true, 'time' => trim($time['city'] . ' ' . $time['name'])];
    } catch (Throwable $e) {
        // Save pela metade é pior que save nenhum: ele abriria quebrado.
        @unlink($arquivo);
        error_log('[temporada] criar: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra montar a temporada: ' . $e->getMessage()];
    }
}

function temporadaApagar(int $uid): void
{
    if ($uid > 0) @unlink(temporadaArquivo($uid));
}

/**
 * ANDA ATÉ O SEU PRÓXIMO JOGO E SIMULA ELE.
 *
 * É o botão principal do jogo. `advanceDay` resolve todos os jogos do dia e
 * PARA quando chega num do time do GM, devolvendo o id — é esse que a gente
 * simula aqui. Quem decide o que acontece nos outros 15 jogos da noite é a
 * própria liga, e eles só aparecem na tabela, como ele pediu.
 *
 * O guarda de 15 voltas existe porque um time não joga todo dia: entre um
 * jogo seu e o próximo pode haver dois ou três dias de calendário.
 *
 * @return array{jogo:?int,msg:string,fim:bool}
 */
function temporadaProximoJogo(): array
{
    for ($volta = 0; $volta < 15; $volta++) {
        $fase = League::phase();
        if ($fase === 'offseason') return ['jogo' => null, 'msg' => 'A temporada acabou.', 'fim' => true];

        $r = $fase === 'playin'   ? League::advancePlayInDay(false)
           : ($fase === 'playoffs' ? League::advancePlayoffDay(false)
           : League::advanceDay(false));

        if (!empty($r['gm_game_pending'])) {
            $id = (int) $r['game_id'];
            League::simulateGame($id);
            return ['jogo' => $id, 'msg' => '', 'fim' => false];
        }
        if (!empty($r['blocked'])) return ['jogo' => null, 'msg' => (string) ($r['msg'] ?? ''), 'fim' => false];
    }
    return ['jogo' => null, 'msg' => 'Sem jogo seu por enquanto — avance mais um pouco.', 'fim' => false];
}

/**
 * Vários jogos seus de uma vez.
 *
 * O teto de 82 é o da temporada inteira: "simular até o fim" é um clique só,
 * e ele não pode virar um laço infinito se o calendário acabar antes.
 */
function temporadaSimularVarios(int $quantos): array
{
    $quantos = max(1, min(82, $quantos));
    $ultimo = null;
    for ($i = 0; $i < $quantos; $i++) {
        $r = temporadaProximoJogo();
        if ($r['fim']) break;
        if ($r['jogo']) $ultimo = $r['jogo'];
        if ($r['msg'] !== '' && !$r['jogo']) break;
    }
    return ['jogo' => $ultimo];
}

/** Tudo que a tela precisa saber, numa consulta só de cada coisa. */
function temporadaEstado(): array
{
    $gmId = (int) Database::meta('gm_team', '0');
    $time = $gmId ? League::team($gmId) : null;
    $fase = League::phase();

    // A posição na conferência: é o que diz se a vaga nos playoffs está perto.
    $pos = 0;
    $conf = $time['conf'] ?? null;
    if ($conf) {
        foreach (League::standings($conf) as $i => $t) {
            if ((int) $t['id'] === $gmId) { $pos = $i + 1; break; }
        }
    }

    $jogados = $time ? (int) $time['wins'] + (int) $time['losses'] : 0;

    return [
        'gm_id'     => $gmId,
        'time'      => $time,
        'fase'      => $fase,
        'liga'      => (string) Database::meta('fba_liga', ''),
        'gm_nome'   => (string) Database::meta('gm_name', 'GM'),
        'dia'       => League::currentDay(),
        'total_dias' => League::totalDays(),
        'jogados'   => $jogados,
        'posicao'   => $pos,
        'forca'     => (int) Database::meta('fba_forca', '50'),
        'rank'      => (int) Database::meta('fba_rank', '0'),
        'times'     => (int) Database::meta('fba_times', '0'),
    ];
}

/** Os últimos jogos do time do GM, com placar e o id pro box score. */
function temporadaMeusJogos(int $gmId, int $limite = 10): array
{
    $st = Database::conn()->prepare(
        "SELECT g.id, g.day, g.stage, g.home_id, g.away_id, g.home_pts, g.away_pts,
                h.abbr AS home_abbr, h.city AS home_city, h.name AS home_name,
                h.primary_color AS home_cor, h.logo_url AS home_logo,
                a.abbr AS away_abbr, a.city AS away_city, a.name AS away_name,
                a.primary_color AS away_cor, a.logo_url AS away_logo
           FROM games g
           JOIN teams h ON h.id = g.home_id
           JOIN teams a ON a.id = g.away_id
          WHERE g.played = 1 AND (g.home_id = ? OR g.away_id = ?)
          ORDER BY g.day DESC, g.id DESC LIMIT " . max(1, $limite)
    );
    $st->execute([$gmId, $gmId]);
    return $st->fetchAll();
}

/** O próximo adversário, se ainda houver jogo marcado. */
function temporadaProximoAdversario(int $gmId): ?array
{
    $st = Database::conn()->prepare(
        "SELECT g.id, g.day, g.home_id, g.away_id,
                h.abbr AS home_abbr, h.city AS home_city, h.name AS home_name,
                h.wins AS home_v, h.losses AS home_d, h.logo_url AS home_logo, h.primary_color AS home_cor,
                a.abbr AS away_abbr, a.city AS away_city, a.name AS away_name,
                a.wins AS away_v, a.losses AS away_d, a.logo_url AS away_logo, a.primary_color AS away_cor
           FROM games g
           JOIN teams h ON h.id = g.home_id
           JOIN teams a ON a.id = g.away_id
          WHERE g.played = 0 AND (g.home_id = ? OR g.away_id = ?)
          ORDER BY g.day ASC, g.id ASC LIMIT 1"
    );
    $st->execute([$gmId, $gmId]);
    return $st->fetch() ?: null;
}

/** Nome cheio de um time, sem o espaço sobrando de quem não tem cidade. */
function temporadaNome(?array $t, string $prefixo = ''): string
{
    if (!$t) return '—';
    return trim(($t[$prefixo . 'city'] ?? '') . ' ' . ($t[$prefixo . 'name'] ?? ''));
}
