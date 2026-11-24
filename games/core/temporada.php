<?php
/**
 * MINHA TEMPORADA — o jogo.
 *
 * Você escolhe um time da FBA e simula a temporada dele: os 82 jogos, um a
 * um, com box score; os outros times você só acompanha na tabela. No fim,
 * play-in, playoffs e as corridas de MVP, MIP, 6H, DPOY e ROY. Cada etapa
 * vencida paga moeda, e paga mais quanto pior for o elenco que você pegou.
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

/**
 * O JOGO INTEIRO PRA ASSISTIR, lance a lance.
 *
 * O motor já guarda o play-by-play de cada partida simulada (games.pbp): cada
 * lance com o quarto, o relógio, o placar daquele instante e o texto. Então a
 * transmissão não precisa de um simulador ao vivo no servidor — a partida é
 * simulada de uma vez, em menos de dois décimos, e a tela REPRODUZ o que
 * aconteceu, com o relógio andando e o placar subindo.
 *
 * É mais honesto que um "ao vivo" de verdade, inclusive: o resultado não muda
 * se a pessoa fechar a aba no meio.
 *
 * @return array{jogo:array,pbp:array,box:array}|null
 */
function temporadaJogoAoVivo(int $gameId): ?array
{
    $g = League::game($gameId);
    if (!$g || empty($g['played'])) return null;

    $pbp = $g['pbp'] ? json_decode((string) $g['pbp'], true) : [];
    if (!is_array($pbp)) $pbp = [];

    $cores = Database::conn()->prepare(
        "SELECT id, abbr, city, name, primary_color, logo_url FROM teams WHERE id IN (?, ?)");
    $cores->execute([(int) $g['home_id'], (int) $g['away_id']]);
    $times = [];
    foreach ($cores->fetchAll() as $t) $times[(int) $t['id']] = $t;

    return [
        'jogo'  => $g,
        'times' => $times,
        'pbp'   => $pbp,
        'box'   => League::boxScore($gameId),
    ];
}

/**
 * O chaveamento, pronto pra desenhar: rodada → conferência → séries.
 *
 * Inclui o play-in, que não vira `playoff_series` nenhuma — ele é disputado em
 * jogo único e o motor guarda só o resultado. Sem tratá-lo, a tela ficava
 * VAZIA justo no dia em que a temporada regular acaba e a liga quer ver quem
 * passou.
 */
function temporadaChaveamento(int $gmId): array
{
    $db = Database::conn();
    $fase = League::phase();

    $series = $db->query(
        "SELECT s.round, s.conf, s.high_seed, s.low_seed, s.high_wins, s.low_wins,
                s.winner_id, s.high_seed_id, s.low_seed_id, s.best_of,
                h.abbr ha, h.city hc, h.name hn, h.primary_color hcor, h.logo_url hlogo,
                a.abbr aa, a.city ac, a.name an, a.primary_color acor, a.logo_url alogo
           FROM playoff_series s
           JOIN teams h ON h.id = s.high_seed_id
           JOIN teams a ON a.id = s.low_seed_id
          ORDER BY s.round, s.conf, s.high_seed")->fetchAll();

    $porRodada = [];
    foreach ($series as $s) {
        $s['eu'] = in_array($gmId, [(int) $s['high_seed_id'], (int) $s['low_seed_id']], true);
        $porRodada[(int) $s['round']][] = $s;
    }

    /* O PLAY-IN NÃO É SÉRIE. São jogos avulsos marcados com stage 'playin';
       enquanto ele rola, `playoff_series` está vazia e não há chaveamento pra
       mostrar. Aqui eles entram como "rodada 0", pra tela ter o que dizer. */
    $playin = [];
    if (in_array($fase, ['playin', 'playoffs', 'offseason'], true)) {
        $st = $db->query(
            "SELECT g.id, g.played, g.home_pts, g.away_pts, g.home_id, g.away_id,
                    h.conf AS conf,
                    h.abbr ha, h.city hc, h.name hn, h.logo_url hlogo, h.primary_color hcor,
                    a.abbr aa, a.city ac, a.name an, a.logo_url alogo, a.primary_color acor
               FROM games g
               JOIN teams h ON h.id = g.home_id
               JOIN teams a ON a.id = g.away_id
              WHERE g.stage = 'playin' ORDER BY h.conf, g.id");
        $playin = $st->fetchAll();
    }

    return ['playin' => $playin, 'rodadas' => $porRodada, 'fase' => $fase];
}

/**
 * O FECHAMENTO DA TEMPORADA, num lugar só.
 *
 * Campeão, vice, até onde o seu time foi, quem levou cada prêmio e quanto a
 * campanha pagou. É o que a tela mostra no pop-up quando a última série
 * acaba — e é a única hora em que isso faz sentido junto: durante a
 * temporada são cinco perguntas separadas, no fim são uma só.
 *
 * OS PRÊMIOS SAEM DA CORRIDA, não da tabela `awards`. Ela é preenchida pela
 * entressafra do simulador (League::nextSeason), que este jogo nunca roda —
 * aqui a temporada é uma só. E não faz falta: quem lidera a corrida no fim
 * da temporada regular É o vencedor, pela mesma conta.
 *
 * @return array|null null enquanto a temporada não acabou
 */
function temporadaResumo(int $gmId): ?array
{
    if (League::phase() !== 'offseason') return null;

    $db = Database::conn();

    // O título: quem venceu a série da quarta rodada.
    $final = $db->query(
        "SELECT s.winner_id, s.high_seed_id, s.low_seed_id, s.high_wins, s.low_wins,
                h.city hc, h.name hn, h.abbr ha, h.logo_url hlogo, h.primary_color hcor,
                a.city ac, a.name an, a.abbr aa, a.logo_url alogo, a.primary_color acor
           FROM playoff_series s
           JOIN teams h ON h.id = s.high_seed_id
           JOIN teams a ON a.id = s.low_seed_id
          WHERE s.round = 4 LIMIT 1")->fetch();

    $campeao = $vice = null;
    if ($final) {
        $altoVenceu = (int) $final['winner_id'] === (int) $final['high_seed_id'];
        $campeao = [
            'id'   => (int) $final['winner_id'],
            'nome' => trim($altoVenceu ? $final['hc'] . ' ' . $final['hn'] : $final['ac'] . ' ' . $final['an']),
            'logo' => $altoVenceu ? $final['hlogo'] : $final['alogo'],
            'cor'  => $altoVenceu ? $final['hcor'] : $final['acor'],
            'serie' => max((int) $final['high_wins'], (int) $final['low_wins']) . '–'
                     . min((int) $final['high_wins'], (int) $final['low_wins']),
        ];
        $vice = [
            'id'   => $altoVenceu ? (int) $final['low_seed_id'] : (int) $final['high_seed_id'],
            'nome' => trim($altoVenceu ? $final['ac'] . ' ' . $final['an'] : $final['hc'] . ' ' . $final['hn']),
            'logo' => $altoVenceu ? $final['alogo'] : $final['hlogo'],
            'cor'  => $altoVenceu ? $final['acor'] : $final['hcor'],
        ];
    }

    /* ATÉ ONDE EU FUI. A rodada mais fundo em que o time apareceu diz mais que
       "campeão ou não": perder a final de conferência é uma temporada, cair na
       primeira rodada é outra. */
    $conq = TemporadaPremios::conquistadas();
    $meuFim = 'Fora dos playoffs';
    if ($campeao && (int) $campeao['id'] === $gmId) $meuFim = 'CAMPEÃO DA FBA';
    elseif ($vice && (int) $vice['id'] === $gmId)   $meuFim = 'Vice-campeão';
    elseif (!empty($conq[3])) $meuFim = 'Campeão da conferência';
    elseif (!empty($conq[2])) $meuFim = 'Eliminado nas finais de conferência';
    elseif (!empty($conq[1])) $meuFim = 'Eliminado nas semifinais';
    elseif (!empty($conq[0])) $meuFim = 'Eliminado na primeira rodada';

    // Os prêmios: quem terminou na frente de cada corrida.
    $race = League::awardRace();
    $logos = temporadaLogos();
    $premios = [];
    // Os apelidos, como a liga fala: MVP, MIP, 6H, DPOY, ROY.
    foreach (['mvp' => 'MVP', 'mip' => 'MIP', 'sexto' => '6H',
              'dpoy' => 'DPOY', 'roy' => 'ROY'] as $k => $rot) {
        $p = $race[$k][0] ?? null;
        if (!$p) continue;
        $premios[] = [
            'premio' => $rot,
            'nome'   => $p['name'],
            'time'   => $p['abbr'],
            'logo'   => $logos[$p['abbr']]['logo'] ?? '',
            'cor'    => $logos[$p['abbr']]['cor'] ?? '',
            'linha'  => $k === 'dpoy'
                ? number_format((float) $p['bpg'], 1) . ' tocos · ' . number_format((float) $p['spg'], 1) . ' roubos'
                : ($k === 'mip'
                    ? '+' . number_format((float) $p['score'], 0) . '% acima do que o OVR previa'
                    : number_format((float) ($p['ppg'] ?? 0), 1) . ' pts · '
                      . number_format((float) ($p['rpg'] ?? 0), 1) . ' reb · '
                      . number_format((float) ($p['apg'] ?? 0), 1) . ' ast'),
            'meu'    => false,
        ];
    }

    // Quanto a temporada pagou, etapa por etapa.
    $pagas = TemporadaPremios::pagas();
    $moedas = [];
    $total = 0;
    foreach (TemporadaPremios::tabela() as $k => $i) {
        $ganhou = in_array($k, $pagas, true);
        if ($ganhou) $total += $i['moedas'];
        $moedas[] = ['rotulo' => $i['rotulo'], 'valor' => $i['moedas'], 'ganhou' => $ganhou];
    }

    $meu = League::team($gmId);

    return [
        'campeao' => $campeao,
        'vice'    => $vice,
        'meu'     => $meu,
        'meu_fim' => $meuFim,
        'eu_campeao' => $campeao && (int) $campeao['id'] === $gmId,
        'premios' => $premios,
        'moedas'  => $moedas,
        'total'   => $total,
    ];
}

/** O pop-up de fim de temporada já foi visto? Ele abre sozinho uma vez só. */
function temporadaResumoVisto(): bool
{
    return Database::meta('resumo_visto') === '1';
}

function temporadaMarcarResumoVisto(): void
{
    Database::setMeta('resumo_visto', '1');
}

/**
 * Escudo e cor de cada time, pela sigla.
 *
 * As corridas de prêmio e o box score falam de jogadores e só carregam a
 * sigla do time. Com este mapa elas mostram o escudo, que é como a liga
 * reconhece um time — a sigla sozinha é um enigma de três letras.
 */
function temporadaLogos(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    foreach (Database::conn()->query("SELECT abbr, logo_url, primary_color FROM teams")->fetchAll() as $t) {
        $cache[$t['abbr']] = ['logo' => $t['logo_url'], 'cor' => $t['primary_color']];
    }
    return $cache;
}

/**
 * O ENDEREÇO DA MINIATURA do escudo, no tamanho que a tela vai desenhar.
 *
 * Os escudos são as fotos que os GMs subiram, do jeito que subiram — há
 * arquivos de 7,7 MB, e a tela os desenha em 23 a 82 pixels. Passar por
 * games/escudo.php troca isso por um WebP de poucos kilobytes, gerado uma
 * vez e cacheado.
 *
 * Escudo de fora (a ROOKIE usa o cdn da NBA) passa direto: redimensionar
 * imagem de terceiro no nosso servidor seria baixar o que mandarem, e o
 * cdn deles já entrega arquivo pequeno.
 *
 * @param int $lado o tamanho em que a tela desenha, em pixels de CSS
 */
function temporadaEscudo(?string $url, int $lado = 96): string
{
    $url = trim((string) $url);
    if ($url === '') return '';

    // Qualquer coisa que não seja um arquivo nosso de /img/teams fica como está.
    if (!preg_match('~^/img/teams/([A-Za-z0-9._-]+)$~', $url, $m)) return $url;

    // 2x pra telas retina: um escudo de 46px vira uma imagem de 92.
    $px = max(24, min(256, $lado * 2));
    return '/games/escudo.php?f=' . rawurlencode($m[1]) . '&s=' . $px;
}

/** Nome cheio de um time, sem o espaço sobrando de quem não tem cidade. */
function temporadaNome(?array $t, string $prefixo = ''): string
{
    if (!$t) return '—';
    return trim(($t[$prefixo . 'city'] ?? '') . ' ' . ($t[$prefixo . 'name'] ?? ''));
}
