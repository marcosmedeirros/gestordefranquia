<?php
/**
 * Conexão PDO (MySQL ou SQLite) dirigida por config + criação do schema.
 */
class Database
{
    private static ?PDO $pdo = null;
    private static ?array $cfg = null;
    private static ?string $saveOverridePath = null;

    /**
     * Aponta a conexão para o arquivo SQLite de um save específico (modo multi-save).
     * Cada save é um banco SQLite isolado, independente do config (MySQL/SQLite).
     */
    public static function useSavePath(string $path): void
    {
        self::$saveOverridePath = $path;
        self::$pdo = null; // reabre na próxima conexão
    }

    public static function activeSavePath(): ?string { return self::$saveOverridePath; }

    public static function config(): array
    {
        if (self::$cfg === null) {
            $base = require dirname(__DIR__) . '/config/config.php';
            $local = dirname(__DIR__) . '/config/config.local.php';
            if (file_exists($local)) {
                $over = require $local;
                $base = array_replace_recursive($base, $over);
            }
            self::$cfg = $base;
        }
        return self::$cfg;
    }

    public static function driver(): string
    {
        if (self::$saveOverridePath !== null) return 'sqlite';
        return self::config()['driver'] ?? 'sqlite';
    }

    public static function path(): string
    {
        return self::config()['sqlite']['path'] ?? (dirname(__DIR__) . '/storage/game.sqlite');
    }

    public static function conn(): PDO
    {
        if (self::$pdo === null) {
            // modo multi-save: SQLite no arquivo do save ativo
            if (self::$saveOverridePath !== null) {
                $dir = dirname(self::$saveOverridePath);
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                self::$pdo = new PDO('sqlite:' . self::$saveOverridePath);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                self::migrateGameDb(self::$pdo);
                return self::$pdo;
            }
            $cfg = self::config();
            if (self::driver() === 'mysql') {
                $m = $cfg['mysql'];
                $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['database']};charset={$m['charset']}";
                self::$pdo = new PDO($dsn, $m['user'], $m['pass']);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            } else {
                $dir = dirname(self::path());
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                self::$pdo = new PDO('sqlite:' . self::path());
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
        return self::$pdo;
    }

    /** Migrações automáticas em saves existentes (adiciona colunas que faltam). */
    private static function migrateGameDb(PDO $db): void
    {
        // Só migra bancos JÁ instalados. Em arquivo novo/vazio (sem a tabela meta)
        // não há o que migrar — o Installer::createSchema() vai criar tudo logo em
        // seguida. Rodar migração aqui criaria tabelas (ex.: coaches) que colidiriam
        // com o CREATE TABLE do schema.
        try {
            $hasMeta = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='meta'")->fetchColumn();
            if (!$hasMeta) return;
        } catch (Throwable $e) { return; }

        try {
            // ─── players: nba_id ───────────────────────────────────────────
            $cols = array_column($db->query('PRAGMA table_info(players)')->fetchAll(), 'name');
            if (!in_array('nba_id', $cols)) {
                $db->exec('ALTER TABLE players ADD COLUMN nba_id INTEGER DEFAULT 0');
                $nbaIds = require dirname(__DIR__) . '/data/nba_ids.php';
                $upd = $db->prepare('UPDATE players SET nba_id = ? WHERE name = ? AND nba_id = 0');
                foreach ($nbaIds as $name => $id) {
                    $upd->execute([$id, $name]);
                }
            }
        } catch (Throwable $e) { /* silencioso */ }

        try {
            // ─── coaches table ─────────────────────────────────────────────
            $tables = array_column($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
            if (!in_array('coaches', $tables)) {
                $db->exec("CREATE TABLE IF NOT EXISTS coaches (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    team_id INTEGER,
                    name TEXT,
                    style TEXT DEFAULT 'equilibrado',
                    ofensivo INTEGER DEFAULT 70,
                    defensivo INTEGER DEFAULT 70,
                    desenvolvimento INTEGER DEFAULT 70,
                    gestao INTEGER DEFAULT 70,
                    intensidade INTEGER DEFAULT 70,
                    seasons INTEGER DEFAULT 0,
                    wins INTEGER DEFAULT 0,
                    losses INTEGER DEFAULT 0
                )");
                // Cria técnico para a equipe do GM a partir dos metadados
                $gmTeam = $db->query("SELECT v FROM meta WHERE k='gm_team'")->fetchColumn();
                $gmName = $db->query("SELECT v FROM meta WHERE k='gm_name'")->fetchColumn();
                $style  = $db->query("SELECT v FROM meta WHERE k='coach_style'")->fetchColumn() ?: 'equilibrado';
                if ($gmTeam && $gmName) {
                    $attrs = self::coachAttrsForStyle($style);
                    $db->prepare("INSERT INTO coaches(team_id,name,style,ofensivo,defensivo,desenvolvimento,gestao,intensidade)
                                  VALUES(?,?,?,?,?,?,?,?)")
                       ->execute([$gmTeam, $gmName, $style,
                                  $attrs['ofensivo'], $attrs['defensivo'], $attrs['desenvolvimento'],
                                  $attrs['gestao'], $attrs['intensidade']]);
                }
            }
        } catch (Throwable $e) { /* silencioso */ }

        try {
            // ─── players: salary + contract_years (sistema de contratos) ────
            $cols = array_column($db->query('PRAGMA table_info(players)')->fetchAll(), 'name');
            $needsBackfill = false;
            if (!in_array('salary', $cols)) {
                $db->exec('ALTER TABLE players ADD COLUMN salary INTEGER DEFAULT 0');
                $needsBackfill = true;
            }
            if (!in_array('contract_years', $cols)) {
                $db->exec('ALTER TABLE players ADD COLUMN contract_years INTEGER DEFAULT 0');
                $needsBackfill = true;
            }
            if ($needsBackfill) {
                // Backfill determinístico (por id) para saves antigos, só onde ainda está zerado.
                $rows = $db->query("SELECT id, ovr, age FROM players WHERE retired=0 AND (salary=0 OR contract_years=0)")->fetchAll();
                $upd = $db->prepare("UPDATE players SET salary=?, contract_years=? WHERE id=?");
                foreach ($rows as $p) {
                    $sal  = self::salaryForOvr((int)$p['ovr'], (int)$p['age']);
                    $yrs  = 1 + ((((int)$p['id']) * 3 + (int)$p['ovr']) % 4); // 1..4 estável
                    $upd->execute([$sal, $yrs, (int)$p['id']]);
                }
            }
        } catch (Throwable $e) { /* silencioso */ }

        try {
            // ─── SALARY CAP da ELITE: colunas de draft/bônus + teto do save ────
            // Save antigo ganha as colunas, tem os salários regravados pela tabela
            // de OVR e o teto calibrado uma única vez (meta cap_elite).
            $cols = array_column($db->query('PRAGMA table_info(players)')->fetchAll(), 'name');
            foreach (['drafted_by' => 'INTEGER DEFAULT 0', 'draft_round' => 'INTEGER DEFAULT 0',
                      'draft_pos' => 'INTEGER DEFAULT 0', 'award_bonus' => 'INTEGER DEFAULT 0',
                      'dev_focus' => 'INTEGER DEFAULT 0'] as $c => $ddl) {
                if (!in_array($c, $cols)) $db->exec("ALTER TABLE players ADD COLUMN $c $ddl");
            }
            $flag = $db->query("SELECT v FROM meta WHERE k='cap_elite'")->fetchColumn();
            if (!$flag) {
                self::$pdo = $db; // Cap usa Database::conn() — já aponta pra este banco
                require_once __DIR__ . '/Cap.php';
                // calouros já no elenco (seasons_pro=0) entram como 2ª rodada: rookie scale mínima
                $db->exec("UPDATE players SET draft_round=2 WHERE retired=0 AND seasons_pro=0 AND draft_round=0");
                Cap::refreshSalaries();
                $cal = Cap::calibrate();
                $db->prepare("INSERT INTO meta(k,v) VALUES('cap_elite','1') ON CONFLICT(k) DO UPDATE SET v='1'")->execute();
                // Save antigo montado sem teto: o GM ganha a temporada corrente (ou a próxima,
                // se está na entressafra) de carência antes de a liga travar o calendário.
                $gm = (int) $db->query("SELECT v FROM meta WHERE k='gm_team'")->fetchColumn();
                $season = (int) $db->query("SELECT v FROM meta WHERE k='season'")->fetchColumn();
                $phase = (string) $db->query("SELECT v FROM meta WHERE k='phase'")->fetchColumn();
                $grace = in_array($phase, ['offseason', 'lottery', 'draft', 'freeagency'], true) ? $season + 1 : $season;
                $db->prepare("INSERT INTO meta(k,v) VALUES('cap_grace_season',?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([(string) $grace]);
                if ($gm) {
                    $pay = (int) $db->query("SELECT COALESCE(SUM(salary),0) FROM players WHERE team_id=$gm AND retired=0")->fetchColumn();
                    $capM = $cal['cap'];
                    $txt = "A liga passou a usar o Salary Cap da FBA ELITE: salário só pelo OVR, teto de {$capM}M e piso de {$cal['floor']}M, regra dos 120% nas trocas e Trade Deadline. "
                         . "Sua folha hoje é " . number_format($pay / 1000000, 1) . "M. "
                         . "Você tem CARÊNCIA até o fim da temporada {$grace}: nesse período a liga não trava o calendário nem pune pelo piso. Use esse tempo para se adequar. Veja tudo em Folha e teto.";
                    try {
                        $db->prepare("INSERT INTO inbox(season,day,kind,icon,sender,title,body,link,ref_id,is_read,urgent,created_at)
                                      VALUES(?,?,?,?,?,?,?,?,0,0,1,?)")
                           ->execute([$season, (int) $db->query("SELECT v FROM meta WHERE k='current_day'")->fetchColumn(), 'cap', '💰', 'Liga',
                                      '💰 Novo Salary Cap da liga — carência até o fim da temporada ' . $grace, $txt, 'index.php?p=cap', date('c')]);
                    } catch (Throwable $e) { /* inbox pode não existir num save muito antigo */ }
                }
            }
        } catch (Throwable $e) { /* silencioso */ }

        try {
            // ─── tabela inbox (caixa de mensagens FM) ──────────────────────
            $tables = array_column($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
            if (!in_array('inbox', $tables)) {
                $db->exec("CREATE TABLE IF NOT EXISTS inbox (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    season INTEGER, day INTEGER,
                    kind TEXT, icon TEXT, sender TEXT, title TEXT, body TEXT, link TEXT,
                    ref_id INTEGER DEFAULT 0,
                    is_read INTEGER DEFAULT 0, urgent INTEGER DEFAULT 0, created_at TEXT
                )");
            } else {
                $icols = array_column($db->query("PRAGMA table_info(inbox)")->fetchAll(), 'name');
                if (!in_array('ref_id', $icols)) $db->exec("ALTER TABLE inbox ADD COLUMN ref_id INTEGER DEFAULT 0");
            }
        } catch (Throwable $e) { /* silencioso */ }
    }

    // ===================== SISTEMA FINANCEIRO / CONTRATOS =====================
    // O salário segue a tabela por OVR da FBA ELITE (ver Cap.php). Estas duas
    // funções ficaram como atalho para quem já as chamava; a idade não conta mais.
    public const MIN_SALARY = 2000000;   // mínimo de veterano ($2M, OVR 77 ou menos)
    public const MAX_SALARY = 60000000;  // OVR 99

    /** Salário anual pela tabela de OVR da ELITE, em dólares. */
    public static function salaryForOvr(int $ovr, int $age = 25): int
    {
        return Cap::ovrSalary($ovr) * Cap::M;
    }

    /** Salário de calouro pela posição no draft (rookie scale da ELITE), em dólares. */
    public static function rookieSalary(int $pickNo, int $round = 0): int
    {
        if ($round <= 0) $round = $pickNo <= 30 ? 1 : 2;
        return Cap::rookieScale($round, $round === 1 ? $pickNo : 0) * Cap::M;
    }

    /** Anos de contrato típicos para um jogador recém-gerado (estrelas assinam mais longo). */
    public static function contractYearsFor(int $ovr): int
    {
        $base = 1 + intdiv(max(0, $ovr - 70), 7); // 70→1, 77→2, 84→3, 91→4
        return max(1, min(4, $base + random_int(0, 1)));
    }

    /** Atributos base de cada estilo de técnico. */
    public static function coachAttrsForStyle(string $style): array
    {
        return match ($style) {
            'ofensivo'       => ['ofensivo'=>88,'defensivo'=>58,'desenvolvimento'=>62,'gestao'=>68,'intensidade'=>76],
            'defensivo'      => ['ofensivo'=>60,'defensivo'=>88,'desenvolvimento'=>62,'gestao'=>68,'intensidade'=>82],
            'desenvolvimento'=> ['ofensivo'=>65,'defensivo'=>62,'desenvolvimento'=>92,'gestao'=>72,'intensidade'=>60],
            'vencedor'       => ['ofensivo'=>80,'defensivo'=>78,'desenvolvimento'=>55,'gestao'=>85,'intensidade'=>90],
            default          => ['ofensivo'=>72,'defensivo'=>72,'desenvolvimento'=>72,'gestao'=>72,'intensidade'=>72],
        };
    }

    public static function isInstalled(): bool
    {
        try {
            $r = self::conn()->query("SELECT v FROM meta WHERE k='season'");
            return (bool) $r->fetch();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Substitui tokens portáveis pelo dialeto do driver atual. */
    private static function ddl(string $sql): string
    {
        $mysql = self::driver() === 'mysql';
        $map = $mysql ? [
            '{PK}'      => 'INT AUTO_INCREMENT PRIMARY KEY',
            '{INT}'     => 'INT',
            '{REAL}'    => 'DOUBLE',
            '{STR}'     => 'VARCHAR(255)',
            '{TEXT}'    => 'TEXT',
            '{PBP}'     => 'MEDIUMTEXT',
            '{ENGINE}'  => ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ] : [
            '{PK}'      => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            '{INT}'     => 'INTEGER',
            '{REAL}'    => 'REAL',
            '{STR}'     => 'TEXT',
            '{TEXT}'    => 'TEXT',
            '{PBP}'     => 'TEXT',
            '{ENGINE}'  => '',
        ];
        return strtr($sql, $map);
    }

    public static function createSchema(): void
    {
        $db = self::conn();
        $mysql = self::driver() === 'mysql';

        if ($mysql) $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        else $db->exec("PRAGMA foreign_keys = OFF");

        foreach (['box_scores','season_stats','playoff_series','transactions','draft_prospects','draft_picks',
                  'awards','champions','player_seasons','headlines','season_history','decisions',
                  'games','players','teams','meta','coaches','inbox'] as $tbl) {
            $db->exec("DROP TABLE IF EXISTS $tbl");
        }

        $db->exec(self::ddl("CREATE TABLE meta (
            k {STR} PRIMARY KEY,
            v {PBP}
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE teams (
            id {PK},
            abbr {STR},
            city {STR},
            name {STR},
            conf {STR},
            `div` {STR},
            primary_color {STR},
            secondary_color {STR},
            scheme_off {STR},
            scheme_def {STR},
            chemistry {INT} DEFAULT 70,
            titles {INT} DEFAULT 0,
            streak {INT} DEFAULT 0,
            wins {INT} DEFAULT 0,
            losses {INT} DEFAULT 0,
            active {INT} DEFAULT 1
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE players (
            id {PK},
            team_id {INT},
            name {STR},
            pos {STR},
            age {INT},
            ht {INT},
            ovr {INT},
            ins {INT}, mid {INT}, thr {INT}, pmk {INT},
            reb {INT}, def {INT}, ath {INT}, sta {INT},
            potential {INT} DEFAULT 0,
            seasons_pro {INT} DEFAULT 1,
            injury_games {INT} DEFAULT 0,
            injury_desc {STR},
            morale {INT} DEFAULT 75,
            retired {INT} DEFAULT 0,
            is_starter {INT} DEFAULT 0,
            rotation {INT} DEFAULT 0,
            min_target {INT} DEFAULT 0,
            rest_games {INT} DEFAULT 0,
            nba_id {INT} DEFAULT 0,
            salary {INT} DEFAULT 0,
            contract_years {INT} DEFAULT 0,
            drafted_by {INT} DEFAULT 0,
            draft_round {INT} DEFAULT 0,
            draft_pos {INT} DEFAULT 0,
            award_bonus {INT} DEFAULT 0,
            dev_focus {INT} DEFAULT 0
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE season_stats (
            player_id {INT} PRIMARY KEY,
            gp {INT} DEFAULT 0,
            min {REAL} DEFAULT 0,
            pts {INT} DEFAULT 0,
            reb {INT} DEFAULT 0,
            ast {INT} DEFAULT 0,
            stl {INT} DEFAULT 0,
            blk {INT} DEFAULT 0,
            tov {INT} DEFAULT 0,
            fgm {INT} DEFAULT 0,
            fga {INT} DEFAULT 0,
            tpm {INT} DEFAULT 0,
            tpa {INT} DEFAULT 0,
            ftm {INT} DEFAULT 0,
            fta {INT} DEFAULT 0
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE games (
            id {PK},
            day {INT},
            stage {STR} DEFAULT 'regular',
            home_id {INT},
            away_id {INT},
            home_pts {INT},
            away_pts {INT},
            played {INT} DEFAULT 0,
            ot {INT} DEFAULT 0,
            pbp {PBP},
            series_id {INT}
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE box_scores (
            id {PK},
            game_id {INT},
            team_id {INT},
            player_id {INT},
            min {REAL}, pts {INT}, reb {INT}, ast {INT},
            stl {INT}, blk {INT}, tov {INT},
            fgm {INT}, fga {INT}, tpm {INT}, tpa {INT}, ftm {INT}, fta {INT}
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE playoff_series (
            id {PK},
            round {INT},
            conf {STR},
            high_seed_id {INT},
            low_seed_id {INT},
            high_seed {INT},
            low_seed {INT},
            high_wins {INT} DEFAULT 0,
            low_wins {INT} DEFAULT 0,
            best_of {INT} DEFAULT 7,
            winner_id {INT},
            label {STR}
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE transactions (
            id {PK},
            season {INT},
            day {INT},
            type {STR},
            description {TEXT}
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE draft_prospects (
            id {PK},
            season {INT},
            name {STR},
            pos {STR},
            age {INT},
            ht {INT},
            ovr {INT},
            potential {INT},
            ins {INT}, mid {INT}, thr {INT}, pmk {INT},
            reb {INT}, def {INT}, ath {INT}, sta {INT},
            picked_by {INT},
            pick_no {INT},
            drafted {INT} DEFAULT 0
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE draft_picks (
            id {PK},
            year {INT},
            round {INT},
            original_team_id {INT},
            owner_team_id {INT},
            used {INT} DEFAULT 0,
            pick_no {INT}
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE awards (
            id {PK},
            season {INT},
            type {STR},
            player_id {INT},
            team_id {INT},
            value {STR}
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE champions (
            id {PK},
            season {INT},
            team_id {INT},
            runnerup_id {INT},
            fmvp_id {INT}
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE headlines (
            id {PK},
            season {INT},
            day {INT},
            type {STR},
            team_id {INT},
            text {STR}
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE player_seasons (
            id {PK},
            season {INT},
            player_id {INT},
            team_id {INT},
            name {STR},
            age {INT},
            ovr {INT},
            gp {INT}, pts {INT}, reb {INT}, ast {INT}, stl {INT}, blk {INT}
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE season_history (
            id {PK},
            season {INT},
            team_id {INT},
            wins {INT}, losses {INT},
            seed {INT},
            made_playoffs {INT} DEFAULT 0,
            exit_round {INT} DEFAULT 0,
            champion {INT} DEFAULT 0
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE decisions (
            id {PK},
            season {INT},
            day {INT},
            type {STR},
            title {STR},
            body {TEXT},
            options {TEXT},
            payload {TEXT},
            status {STR} DEFAULT 'pending',
            choice {STR}
        ){ENGINE}"));

        $db->exec(self::ddl("CREATE TABLE coaches (
            id {PK},
            team_id {INT},
            name {STR},
            style {STR} DEFAULT 'equilibrado',
            ofensivo {INT} DEFAULT 70,
            defensivo {INT} DEFAULT 70,
            desenvolvimento {INT} DEFAULT 70,
            gestao {INT} DEFAULT 70,
            intensidade {INT} DEFAULT 70,
            seasons {INT} DEFAULT 0,
            wins {INT} DEFAULT 0,
            losses {INT} DEFAULT 0
        ){ENGINE}"));

        // Caixa de entrada (mensagens estilo FM): eventos da liga e da franquia.
        $db->exec(self::ddl("CREATE TABLE inbox (
            id {PK},
            season {INT},
            day {INT},
            kind {STR},
            icon {STR},
            sender {STR},
            title {STR},
            body {TEXT},
            link {STR},
            ref_id {INT} DEFAULT 0,
            is_read {INT} DEFAULT 0,
            urgent {INT} DEFAULT 0,
            created_at {STR}
        ){ENGINE}"));

        $db->exec("CREATE INDEX idx_box_player ON box_scores(player_id)");
        $db->exec("CREATE INDEX idx_box_game ON box_scores(game_id)");
        $db->exec("CREATE INDEX idx_games_day ON games(day)");
        $db->exec("CREATE INDEX idx_players_team ON players(team_id)");

        if ($mysql) $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        else $db->exec("PRAGMA foreign_keys = ON");
    }

    public static function meta(string $key, $default = null)
    {
        try {
            $st = self::conn()->prepare("SELECT v FROM meta WHERE k = ?");
            $st->execute([$key]);
            $row = $st->fetch();
            return $row ? $row['v'] : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }

    public static function setMeta(string $key, $value): void
    {
        if (self::driver() === 'mysql') {
            $sql = "INSERT INTO meta(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)";
        } else {
            $sql = "INSERT INTO meta(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v = excluded.v";
        }
        self::conn()->prepare($sql)->execute([$key, (string) $value]);
    }
}

require_once __DIR__ . '/Cap.php';
