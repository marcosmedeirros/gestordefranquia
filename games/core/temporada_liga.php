<?php
require_once __DIR__ . '/temporada_motor.php';


/**
 * A FBA DE VERDADE DENTRO DO MOTOR.
 *
 * O simulador nasceu com a NBA: 30 franquias e elencos escritos à mão em
 * data/players.php. Este instalador faz o mesmo trabalho lendo o banco do
 * site — os times e os elencos ATUAIS da liga escolhida, do jeito que estão
 * agora no fbabrasil.com.br.
 *
 * O resto do jogo não muda. Depois que as tabelas `teams` e `players` do save
 * estão preenchidas, SimEngine, League, playoffs, líderes e prêmios funcionam
 * exatamente como funcionam na NBA — é tudo em cima dessas duas tabelas.
 *
 * ── AS LETRINHAS VIRAM ATRIBUTOS ──────────────────────────────────────
 * A ficha da FBA é em letra (A+ até F); o motor pensa em número de 0 a 99. A
 * régua é a mesma do site (SISTEMA_NOTA_LETRA, em backend/sistema_proficiencia.php)
 * e está copiada aqui de propósito: o simulador é uma aplicação separada, com
 * a sua própria conexão e o seu próprio config, e não enxerga o backend/ do
 * site. Se a régua de lá mudar, esta tem que mudar junto.
 *
 * Quem não tem ficha preenchida (uns 3% do elenco) não fica zerado: cai no
 * próprio OVR, que é o número que a liga já usa pra ele.
 */
class TemporadaLiga
{
    /** A régua do site. @see backend/sistema_proficiencia.php */
    private const LETRA = [
        'A+' => 97, 'A' => 92, 'A-' => 87,
        'B+' => 82, 'B' => 77, 'B-' => 72,
        'C+' => 67, 'C' => 62, 'C-' => 57,
        'D+' => 52, 'D' => 47, 'D-' => 42,
        'F'  => 35,
    ];

    public const LIGAS = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

    /** Cores de reserva pra time que ainda não escolheu as dele. */
    private const CORES_PADRAO = [
        ['#1d4ed8', '#e2e8f0'], ['#b91c1c', '#fde68a'], ['#047857', '#d1fae5'],
        ['#6d28d9', '#ede9fe'], ['#b45309', '#fef3c7'], ['#0f766e', '#ccfbf1'],
        ['#be123c', '#ffe4e6'], ['#1e293b', '#cbd5e1'],
    ];

    /**
     * Monta o save inteiro a partir de uma liga da FBA.
     *
     * @return array{teams:int,players:int,games:int,days:int,league:string}
     */
    public static function run(string $liga): array
    {
        $liga = strtoupper(trim($liga));
        if (!in_array($liga, self::LIGAS, true)) $liga = 'ELITE';

        Database::createSchema();
        self::garantirColunas();
        $db = Database::conn();

        $fba = self::fbaConn();

        // ── times ────────────────────────────────────────────────────────
        $st = $fba->prepare(
            "SELECT id, city, name, conference, division_id, photo_url,
                    public_primary_color, public_secondary_color
               FROM teams WHERE league = ? ORDER BY conference, city, name"
        );
        $st->execute([$liga]);
        $times = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($times) < 4) {
            throw new RuntimeException("A $liga não tem times suficientes pra montar uma temporada.");
        }

        $insTeam = $db->prepare(
            "INSERT INTO teams(abbr,city,name,conf,`div`,primary_color,secondary_color,
                               scheme_off,scheme_def,active,logo_url,fba_team_id)
             VALUES(?,?,?,?,?,?,?,'Pace and Space','Man-to-Man',1,?,?)"
        );

        $usadas = [];
        $idPorFba = [];
        $simIds = [];
        foreach ($times as $i => $t) {
            [$cidade, $nome] = self::cidadeENome($t);
            $abbr = self::abreviar($cidade, $nome, $usadas);
            [$c1, $c2] = self::cores($t, $i);
            $insTeam->execute([
                $abbr,
                $cidade,
                $nome,
                // O motor pensa em E/W; a FBA fala LESTE/OESTE.
                strtoupper((string)$t['conference']) === 'LESTE' ? 'E' : 'W',
                // Só a ELITE tem divisões de verdade. Sem elas, a conferência
                // inteira é uma divisão só — nenhuma tela quebra por isso.
                $t['division_id'] ? ('Divisão ' . (int)$t['division_id']) : 'Única',
                $c1, $c2,
                trim((string)($t['photo_url'] ?? '')) ?: null,
                (int)$t['id'],
            ]);
            $novoId = (int)$db->lastInsertId();
            $idPorFba[(int)$t['id']] = $novoId;
            $simIds[] = $novoId;
        }

        // ── elencos ──────────────────────────────────────────────────────
        $marcas = implode(',', array_fill(0, count($idPorFba), '?'));
        $sp = $fba->prepare(
            "SELECT team_id, name, position, age, seasons_in_league, ovr, nba_player_id,
                    drafted_season_number,
                    skill_in, skill_mid, skill_3pt, skill_post_d, skill_per_d,
                    skill_play, skill_reb, skill_athl, skill_iq, skill_pot,
                    player_skill_grades, lineup_slot
               FROM players
              WHERE team_id IN ($marcas)
              ORDER BY team_id, ovr DESC"
        );
        $sp->execute(array_keys($idPorFba));

        /* ── QUEM É CALOURO DE VERDADE ────────────────────────────────────
           O Novato do Ano saía errado porque "calouro" era qualquer um com
           zero temporadas na liga — e isso inclui quem chegou por
           contratação e ainda não jogou. Na RISE eram 17 dos 52.
           Calouro na FBA é quem veio na CLASSE DE DRAFT mais recente, e é
           essa a régua agora. Quem tem zero temporadas sem ter sido
           draftado nessa classe entra com uma temporada de casa — não
           disputa o ROY, que é o que se queria. */
        $stClasse = $fba->prepare(
            "SELECT MAX(p.drafted_season_number)
               FROM players p JOIN teams t ON t.id = p.team_id
              WHERE t.league = ?");
        $stClasse->execute([$liga]);
        $classeAtual = (int) ($stClasse->fetchColumn() ?: 0);

        $insPlayer = $db->prepare(
            "INSERT INTO players(team_id,name,pos,age,ht,ovr,ins,mid,thr,pmk,reb,def,ath,sta,
                                 potential,seasons_pro,morale,is_starter,rotation,nba_id,salary,contract_years)
             VALUES(:team_id,:name,:pos,:age,:ht,:ovr,:ins,:mid,:thr,:pmk,:reb,:def,:ath,:sta,
                    :potential,:seasons_pro,:morale,:is_starter,:rotation,:nba_id,:salary,:contract_years)"
        );
        $insStats = $db->prepare("INSERT INTO season_stats(player_id) VALUES(?)");

        $porTime = [];
        foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $porTime[(int)$p['team_id']][] = $p;
        }

        $db->beginTransaction();
        $quantos = 0;
        foreach ($porTime as $fbaTeamId => $elenco) {
            $simTeamId = $idPorFba[$fbaTeamId] ?? null;
            if (!$simTeamId) continue;

            /* O MOTOR JOGA COM OITO. Mandar 15 nomes não muda nada — loadTeam
               corta nos 8 melhores —, mas guardar o elenco inteiro é o que faz
               a tela de elenco ser a da FBA de verdade, e é de onde sai o
               Sexto Homem (quem está na rotação sem ser titular). */
            usort($elenco, fn($a, $b) => (int)$b['ovr'] <=> (int)$a['ovr']);

            foreach ($elenco as $i => $p) {
                $a = self::atributos($p);
                $insPlayer->execute([
                    ':team_id'  => $simTeamId,
                    ':name'     => trim((string)$p['name']),
                    ':pos'      => self::posicao((string)($p['position'] ?? '')),
                    ':age'      => max(17, (int)$p['age'] ?: 24),
                    ':ht'       => 0,       // a FBA guarda altura em texto livre
                    ':ovr'      => $a['ovr'],
                    ':ins'      => $a['ins'], ':mid' => $a['mid'], ':thr' => $a['thr'],
                    ':pmk'      => $a['pmk'], ':reb' => $a['reb'], ':def' => $a['def'],
                    ':ath'      => $a['ath'], ':sta' => $a['sta'],
                    ':potential' => $a['pot'],
                    /* seasons_pro = 0 é o que o motor chama de calouro, e é
                       assim que a corrida de Novato do Ano sabe quem concorre.
                       Zero só pra quem veio na classe de draft mais recente
                       desta liga — @see $classeAtual, lá em cima. */
                    ':seasons_pro' => ($classeAtual > 0
                            && (int)($p['drafted_season_number'] ?? 0) === $classeAtual)
                        ? 0
                        : max(1, (int)$p['seasons_in_league']),
                    ':morale'   => random_int(70, 82),
                    ':is_starter' => $i < 5 ? 1 : 0,
                    ':rotation' => $i < 9 ? 1 : 0,
                    ':nba_id'   => (int)($p['nba_player_id'] ?? 0),
                    ':salary'   => Database::salaryForOvr($a['ovr'], (int)$p['age']),
                    ':contract_years' => Database::contractYearsFor($a['ovr']),
                ]);
                $insStats->execute([(int)$db->lastInsertId()]);
                $quantos++;
            }
        }
        $db->commit();

        Database::seedAiCoaches();
        Database::setMeta('ai_coaches', '1');
        // League já veio pelo temporada_motor.php; aqui só se usa aiPickAllSchemes().
        League::aiPickAllSchemes();

        // ── calendário ───────────────────────────────────────────────────
        $games = Installer::buildSchedule($simIds);
        $insGame = $db->prepare("INSERT INTO games(day,stage,home_id,away_id) VALUES(?, 'regular', ?, ?)");
        $db->beginTransaction();
        foreach ($games as $g) $insGame->execute([$g['day'], $g['home'], $g['away']]);
        $db->commit();

        $dias = $games ? (int)max(array_column($games, 'day')) : 0;

        Database::setMeta('season', '1');
        Database::setMeta('current_day', '1');
        Database::setMeta('total_days', (string)$dias);
        Database::setMeta('phase', 'regular');
        Database::setMeta('created_at', date('c'));
        /* O MODO É O QUE AS TELAS OLHAM pra saber que isto não é a NBA: sem
           era, sem expansão, sem classe de draft — e com os nomes, escudos e
           elencos da liga. @see helpers.php, modoFBA() */
        Database::setMeta('modo', 'fba');
        Database::setMeta('fba_liga', $liga);
        Database::setMeta('era', 'fba');
        Database::setMeta('era_name', 'FBA ' . $liga);
        Database::setMeta('expansions', json_encode([]));
        Database::setMeta('draft_scripts', json_encode([]));

        return [
            'teams'   => count($times),
            'players' => $quantos,
            'games'   => count($games),
            'days'    => $dias,
            'league'  => $liga,
        ];
    }

    /**
     * Os times de uma liga, pra tela de escolha (sem montar save nenhum).
     *
     * ── A MÉDIA É DOS OITO PRIMEIROS, E ISSO IMPORTA ─────────────────
     * A primeira versão ordenava pela média do elenco INTEIRO, e ela
     * discordava do jogo: o Cincinnati aparecia como o pior elenco da ELITE
     * na escolha ("título paga o máximo") e, entrando, saía com força 52 e
     * multiplicador 1,72x. Os dois números estavam certos e mediam coisas
     * diferentes — ele tem banco fraco e titulares medianos.
     *
     * Quem decide o preço é o TemporadaPremios, e ele conta os oito
     * primeiros, que são os que o motor põe em quadra. Então a escolha passa
     * a contar os mesmos oito. Promessa feita na tela é promessa paga no fim.
     *
     * Os oito saem em PHP de propósito: `LIMIT` por grupo pede função de
     * janela, e a conta aqui é sobre quatrocentos e poucos jogadores.
     */
    public static function timesDaLiga(string $liga): array
    {
        $liga = strtoupper(trim($liga));
        if (!in_array($liga, self::LIGAS, true)) return [];

        $fba = self::fbaConn();
        $st = $fba->prepare(
            "SELECT t.id, t.city, t.name, t.conference, t.photo_url,
                    t.public_primary_color AS cor
               FROM teams t WHERE t.league = ? ORDER BY t.id"
        );
        $st->execute([$liga]);
        $times = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$times) return [];

        $ids = array_column($times, 'id');
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $sp = $fba->prepare("SELECT team_id, ovr FROM players
                              WHERE team_id IN ($marcas) ORDER BY team_id, ovr DESC");
        $sp->execute($ids);

        $topo = [];
        $total = [];
        foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $t = (int) $p['team_id'];
            $total[$t] = ($total[$t] ?? 0) + 1;
            if (count($topo[$t] ?? []) < 8) $topo[$t][] = (int) $p['ovr'];
        }

        foreach ($times as &$t) {
            $oito = $topo[(int) $t['id']] ?? [];
            $t['jogadores'] = $total[(int) $t['id']] ?? 0;
            $t['ovr_medio'] = $oito ? round(array_sum($oito) / count($oito), 1) : 0.0;
            $t['teto']      = $oito ? max($oito) : 0;
        }
        unset($t);

        usort($times, fn($a, $b) => $b['ovr_medio'] <=> $a['ovr_medio']);
        return $times;
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Conexão ao banco do site.
     *
     * Nasceu só pra ler os elencos, e é quase só isso que faz. A exceção é o
     * pagamento das moedas (@see TemporadaPremios), que precisa escrever em
     * `games_usuarios` — a carteira do Games mora lá, não aqui no save.
     */
    public static function fbaConn(): PDO
    {
        /* UMA CONEXÃO POR REQUISIÇÃO. Sem o cache, cada chamada abria uma
           conexão nova no MySQL — e o pagamento de uma etapa chama três
           vezes (o teto, o crédito e o registro). Guardar também é o que
           deixa essas três conversarem: `rowCount` e `ROW_COUNT` só fazem
           sentido na mesma conexão que rodou o comando. */
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;

        $cfg = Database::config();
        $m = $cfg['main_mysql'] ?? null;
        if (!$m) throw new RuntimeException('Sem conexão com o banco do site (main_mysql).');
        $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['database']};charset={$m['charset']}";
        $pdo = new PDO($dsn, $m['user'], $m['pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    /**
     * Colunas que só o modo FBA usa. O schema do jogo não as tem, e save
     * antigo também não — por isso entra por ALTER, como as outras migrações.
     */
    private static function garantirColunas(): void
    {
        $db = Database::conn();
        foreach (['logo_url' => 'TEXT', 'fba_team_id' => 'INTEGER DEFAULT 0'] as $col => $ddl) {
            try { $db->exec("ALTER TABLE teams ADD COLUMN $col $ddl"); }
            catch (Throwable $e) { /* já existe */ }
        }
    }

    /** A letra vira número; sem letra, vale o OVR. */
    private static function nota($letra, int $fallback): int
    {
        $l = strtoupper(trim((string)$letra));
        return self::LETRA[$l] ?? $fallback;
    }

    /**
     * A ficha da FBA traduzida pro que o motor entende.
     *
     * Duas combinações merecem explicação:
     *
     * `def` junta defesa de garrafão e de perímetro, mas não meio a meio: um
     * pivô defende perto da cesta e um armador defende na linha, e somar os
     * dois igual faria o especialista parecer mediano nos dois.
     *
     * `pmk` (armação) leva um terço de QI de jogo. Armar não é só passar — e
     * o QI é o atributo da FBA que mais fala sobre decidir com a bola.
     */
    private static function atributos(array $p): array
    {
        $ovr = max(40, min(99, (int)$p['ovr'] ?: 70));
        $g = self::letras($p);
        $n = fn(string $k) => self::nota($g[$k] ?? null, $ovr);

        $pos = self::posicao((string)($p['position'] ?? ''));
        $pertoDaCesta = in_array($pos, ['PF', 'C'], true);
        $postD = $n('post_d');
        $perD  = $n('per_d');
        $def = $pertoDaCesta
            ? (int)round($postD * 0.65 + $perD * 0.35)
            : (int)round($perD  * 0.70 + $postD * 0.30);

        $ath = $n('athl');
        $age = max(17, (int)$p['age'] ?: 24);

        return [
            'ovr' => $ovr,
            'ins' => $n('in'),
            'mid' => $n('mid'),
            'thr' => $n('3pt'),
            'pmk' => (int)round($n('play') * 0.67 + $n('iq') * 0.33),
            'reb' => $n('reb'),
            'def' => max(30, min(99, $def)),
            'ath' => $ath,
            // Mesma conta do Installer da NBA: cai com a idade, sobe com atletismo.
            'sta' => max(60, min(99, 88 - max(0, $age - 29) * 2 + (int)round(($ath - 75) * 0.2))),
            /* Potencial é TETO, não promessa: nunca abaixo do que o jogador já
               é hoje, senão o motor de evolução o puxaria pra baixo no ato. */
            'pot' => max($ovr, min(99, self::nota($g['pot'] ?? null, $ovr))),
        ];
    }

    /**
     * As letras do jogador, da coluna ou do JSON.
     *
     * Sessenta jogadores do elenco têm ficha SÓ em `player_skill_grades` —
     * foram importados por outro caminho. Ler só as colunas os deixaria sem
     * ficha nenhuma. Mesma regra do site: a coluna manda, o JSON cobre o
     * resto, e no JSON o três pontos se chama `pt3`.
     */
    private static function letras(array $p): array
    {
        $json = [];
        if (!empty($p['player_skill_grades'])) {
            $d = json_decode((string)$p['player_skill_grades'], true);
            if (is_array($d)) $json = $d;
        }
        $out = [];
        foreach (['in', 'mid', '3pt', 'post_d', 'per_d', 'play', 'reb', 'athl', 'iq', 'pot'] as $k) {
            $v = $p['skill_' . $k] ?? null;
            if ($v === null || trim((string)$v) === '' || trim((string)$v) === '-') {
                $v = $json[$k === '3pt' ? 'pt3' : $k] ?? null;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * CIDADE E APELIDO, mesmo quando o site só tem um dos dois.
     *
     * Dois times da liga estão com a cidade em branco e o nome inteiro num
     * campo só ("Villanova Valleys", "Boston Beans"). O jogo mostra cidade e
     * apelido separados em todo lugar, e a sigla sai da cidade — sem tratar
     * isto, o Villanova aparecia como " Villanova Valleys", com o espaço
     * sobrando na frente, e com a sigla XXX, que é o fundo do poço do
     * gerador. A primeira palavra é a cidade; o resto é o apelido.
     */
    private static function cidadeENome(array $t): array
    {
        $cidade = trim((string)($t['city'] ?? ''));
        $nome   = trim((string)($t['name'] ?? ''));

        if ($cidade === '' && $nome !== '' && str_contains($nome, ' ')) {
            [$cidade, $nome] = explode(' ', $nome, 2);
            $cidade = trim($cidade);
            $nome   = trim($nome);
        }
        // Nome de uma palavra só e sem cidade: ele é as duas coisas.
        if ($cidade === '') $cidade = $nome;
        if ($nome === '')   $nome = $cidade;

        return [$cidade, $nome];
    }

    /** PG/SG/SF/PF/C — quem vier diferente vira ala, que é o que menos erra. */
    private static function posicao(string $pos): string
    {
        $p = strtoupper(trim($pos));
        return in_array($p, ['PG', 'SG', 'SF', 'PF', 'C'], true) ? $p : 'SF';
    }

    /**
     * Uma sigla de três letras, única na liga.
     *
     * A FBA não guarda abreviação, mas o jogo inteiro usa a sigla como
     * identidade curta — tabela, box score, bracket. Tenta a cidade primeiro
     * (é como a liga fala: "o Oakland"), e desempata com o apelido.
     */
    private static function abreviar(string $cidade, string $nome, array &$usadas): string
    {
        $limpa = function (string $s): string {
            $s = strtoupper(iconv('UTF-8', 'ASCII//IGNORE', $s) ?: $s);
            return preg_replace('/[^A-Z]/', '', $s) ?: 'XXX';
        };
        $c = $limpa($cidade);
        $n = $limpa($nome);

        $tentativas = [substr($c, 0, 3)];
        // "New York" e "New Mexico" começam igual: as iniciais separam.
        $iniciais = '';
        foreach (preg_split('/\s+/', trim($cidade)) as $palavra) {
            $iniciais .= $limpa($palavra)[0] ?? '';
        }
        if (strlen($iniciais) >= 2) $tentativas[] = substr($iniciais . $n, 0, 3);
        $tentativas[] = substr($c, 0, 2) . ($n[0] ?? 'X');
        $tentativas[] = substr($n, 0, 3);

        foreach ($tentativas as $t) {
            $t = str_pad(substr($t, 0, 3), 3, 'X');
            if (!isset($usadas[$t])) { $usadas[$t] = true; return $t; }
        }
        // Todas batidas: numera, que é feio mas é único.
        for ($i = 1; $i < 100; $i++) {
            $t = substr($c, 0, 2) . $i;
            if (!isset($usadas[$t])) { $usadas[$t] = true; return $t; }
        }
        return 'XXX';
    }

    /** As cores do time, ou um par da paleta pra quem não escolheu as dele. */
    private static function cores(array $t, int $i): array
    {
        $ok = fn($c) => preg_match('/^#[0-9a-f]{6}$/i', trim((string)$c)) === 1;
        $p = trim((string)($t['public_primary_color'] ?? ''));
        $s = trim((string)($t['public_secondary_color'] ?? ''));
        if ($ok($p) && $ok($s)) return [$p, $s];
        $padrao = self::CORES_PADRAO[$i % count(self::CORES_PADRAO)];
        return [$ok($p) ? $p : $padrao[0], $ok($s) ? $s : $padrao[1]];
    }
}
