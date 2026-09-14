<?php
require_once __DIR__ . '/Database.php';

/**
 * Contas + saves (multi-save). Cada jogador pode ter até 2 saves.
 *
 * O simulador é um jogo do FBA Games: quem entra é quem está logado no site
 * (mesma sessão, mesmo cookie). A conta local só existe para ligar os saves a
 * esse usuário (users.main_user_id); não há login nem senha próprios.
 * As chaves do jogo na sessão têm prefixo sim_ para nunca pisar nas do site.
 *
 * Arquitetura de dados:
 *  - Contas (users, saves): MySQL em produção, SQLite em dev sem MySQL
 *  - Dados do jogo por save: SQLite isolado em storage/saves/save_{id}.sqlite
 *    (acesso via Database::useSavePath() — separado da conexão de contas)
 */
class Accounts
{
    public const MAX_SAVES = 2;

    private static ?PDO $acc = null;

    public static function savesDir(): string { return dirname(__DIR__) . '/storage/saves'; }
    public static function savePath(int $saveId): string { return self::savesDir() . '/save_' . $saveId . '.sqlite'; }

    /** Conexão (e schema) do banco de contas. MySQL em produção, SQLite em dev. */
    public static function conn(): PDO
    {
        if (self::$acc === null) {
            $cfg = Database::config();
            if (($cfg['driver'] ?? 'sqlite') === 'mysql') {
                $m   = $cfg['mysql'];
                $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['database']};charset={$m['charset']}";
                self::$acc = new PDO($dsn, $m['user'], $m['pass']);
                self::$acc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$acc->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$acc->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

                self::$acc->exec("CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(100) UNIQUE NOT NULL,
                    email VARCHAR(255),
                    pass_hash VARCHAR(255) NOT NULL,
                    main_user_id INT NULL,
                    created_at VARCHAR(50)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                try {
                    $chkCol = self::$acc->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'main_user_id'"
                    );
                    $chkCol->execute();
                    if ((int) $chkCol->fetchColumn() === 0) {
                        self::$acc->exec("ALTER TABLE users ADD COLUMN main_user_id INT NULL");
                    }
                } catch (Throwable $e) { /* silencioso */ }

                self::$acc->exec("CREATE TABLE IF NOT EXISTS saves (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    slot INT NOT NULL,
                    name VARCHAR(255),
                    gm_name VARCHAR(255),
                    team_abbr VARCHAR(20),
                    era VARCHAR(50) DEFAULT 'modern',
                    era_name VARCHAR(255),
                    coach_style VARCHAR(50) DEFAULT 'equilibrado',
                    difficulty VARCHAR(50) DEFAULT 'normal',
                    potential_type VARCHAR(50) DEFAULT 'real',
                    created_at VARCHAR(50),
                    updated_at VARCHAR(50)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                // Migrações MySQL: adiciona colunas que faltam em tabelas antigas
                $newCols = ['era' => "VARCHAR(50) DEFAULT 'modern'", 'era_name' => 'VARCHAR(255)',
                            'coach_style' => "VARCHAR(50) DEFAULT 'equilibrado'",
                            'difficulty' => "VARCHAR(50) DEFAULT 'normal'",
                            'potential_type' => "VARCHAR(50) DEFAULT 'real'"];
                foreach ($newCols as $col => $def) {
                    try {
                        $chk = self::$acc->prepare(
                            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saves' AND COLUMN_NAME = ?"
                        );
                        $chk->execute([$col]);
                        if ((int) $chk->fetchColumn() === 0) {
                            self::$acc->exec("ALTER TABLE saves ADD COLUMN $col $def");
                        }
                    } catch (Throwable $e) { /* silencioso */ }
                }
            } else {
                // SQLite — desenvolvimento local
                $path = dirname(__DIR__) . '/storage/accounts.sqlite';
                $dir  = dirname($path);
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                self::$acc = new PDO('sqlite:' . $path);
                self::$acc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$acc->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$acc->exec("CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT UNIQUE NOT NULL,
                    email TEXT,
                    pass_hash TEXT NOT NULL,
                    main_user_id INTEGER,
                    created_at TEXT
                )");
                self::$acc->exec("CREATE TABLE IF NOT EXISTS saves (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    slot INTEGER NOT NULL,
                    name TEXT,
                    gm_name TEXT,
                    team_abbr TEXT,
                    era TEXT DEFAULT 'modern',
                    era_name TEXT,
                    coach_style TEXT DEFAULT 'equilibrado',
                    difficulty TEXT DEFAULT 'normal',
                    potential_type TEXT DEFAULT 'real',
                    created_at TEXT,
                    updated_at TEXT
                )");
                // migração: adiciona colunas em bancos antigos
                $cols = array_column(self::$acc->query("PRAGMA table_info(saves)")->fetchAll(), 'name');
                if (!in_array('era', $cols))            self::$acc->exec("ALTER TABLE saves ADD COLUMN era TEXT DEFAULT 'modern'");
                if (!in_array('era_name', $cols))       self::$acc->exec("ALTER TABLE saves ADD COLUMN era_name TEXT");
                if (!in_array('coach_style', $cols))    self::$acc->exec("ALTER TABLE saves ADD COLUMN coach_style TEXT DEFAULT 'equilibrado'");
                if (!in_array('difficulty', $cols))     self::$acc->exec("ALTER TABLE saves ADD COLUMN difficulty TEXT DEFAULT 'normal'");
                if (!in_array('potential_type', $cols)) self::$acc->exec("ALTER TABLE saves ADD COLUMN potential_type TEXT DEFAULT 'real'");

                $userCols = array_column(self::$acc->query("PRAGMA table_info(users)")->fetchAll(), 'name');
                if (!in_array('main_user_id', $userCols)) self::$acc->exec("ALTER TABLE users ADD COLUMN main_user_id INTEGER");
            }
        }
        return self::$acc;
    }

    /**
     * Busca (ou cria) a conta local ligada a um usuário do site. O vínculo é por
     * main_user_id (estável mesmo se o e-mail mudar lá); casa por e-mail só na
     * primeira vez, pra não duplicar conta de quem jogou antes desse campo existir.
     * As tabelas do simulador continuam isoladas; só a identidade é compartilhada.
     */
    private static function linkedLocalUser(array $mainUser): array
    {
        $db = self::conn();
        $mainId = (int) $mainUser['id'];
        $email = strtolower(trim((string) ($mainUser['email'] ?? '')));

        $st = $db->prepare("SELECT * FROM users WHERE main_user_id=?");
        $st->execute([$mainId]);
        $u = $st->fetch();
        if ($u) return $u;

        // Conta local antiga (de antes do vínculo por id) com o mesmo e-mail: adota o vínculo.
        if ($email !== '') {
            $st = $db->prepare("SELECT * FROM users WHERE LOWER(email)=? AND main_user_id IS NULL");
            $st->execute([$email]);
            $u = $st->fetch();
            if ($u) {
                $db->prepare("UPDATE users SET main_user_id=? WHERE id=?")->execute([$mainId, (int) $u['id']]);
                $u['main_user_id'] = $mainId;
                return $u;
            }
        }

        // Gera um username local único a partir do e-mail do site.
        $base = preg_replace('/[^A-Za-z0-9_]/', '', explode('@', $email)[0]) ?: 'gm';
        $base = substr($base, 0, 16) ?: 'gm';
        $username = $base;
        $suffix = 1;
        while (true) {
            $chk = $db->prepare("SELECT 1 FROM users WHERE username=?");
            $chk->execute([$username]);
            if (!$chk->fetch()) break;
            $username = substr($base, 0, 16 - strlen((string) $suffix)) . $suffix;
            $suffix++;
        }

        // Sem senha local utilizável (o acesso é sempre pela sessão do site);
        // guarda um hash aleatório só para satisfazer a coluna NOT NULL.
        $randomHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users(username,email,pass_hash,main_user_id,created_at) VALUES(?,?,?,?,?)")
           ->execute([$username, $email, $randomHash, $mainId, date('c')]);
        $id = (int) $db->lastInsertId();
        $st = $db->prepare("SELECT * FROM users WHERE id=?");
        $st->execute([$id]);
        return $st->fetch();
    }

    // ---------- Sessão: a do site ----------

    /**
     * Abre a sessão do fbabrasil.com.br (backend/auth.php: mesmo cookie, mesma
     * validade) e descobre a conta local de quem está logado. Fecha a escrita
     * logo em seguida: uma simulação longa não pode travar a sessão do site nas
     * outras abas. Gravações depois disso passam por remember().
     */
    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $auth = dirname(__DIR__, 2) . '/backend/auth.php';
            if (is_file($auth)) require_once $auth;
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        }
        self::resolveUser();
        session_write_close();
    }

    private static function resolveUser(): void
    {
        $mainId = (int) ($_SESSION['user_id'] ?? 0);
        if ($mainId > 0) {
            if ((int) ($_SESSION['sim_main_id'] ?? 0) !== $mainId || empty($_SESSION['sim_user_id'])) {
                $local = self::linkedLocalUser([
                    'id' => $mainId,
                    'email' => (string) ($_SESSION['user_email'] ?? ''),
                    'name' => (string) ($_SESSION['user_name'] ?? ''),
                ]);
                if ((int) ($_SESSION['sim_main_id'] ?? 0) !== $mainId) unset($_SESSION['sim_save_id']);
                $_SESSION['sim_main_id'] = $mainId;
                $_SESSION['sim_user_id'] = (int) $local['id'];
            }
            return;
        }
        unset($_SESSION['sim_user_id'], $_SESSION['sim_main_id'], $_SESSION['sim_save_id']);
        // php -S local, sem o site rodando: entra com a primeira conta local.
        if (PHP_SAPI === 'cli-server') {
            $u = self::conn()->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetch();
            if ($u) $_SESSION['sim_user_id'] = (int) $u['id'];
        }
    }

    /** Grava uma chave do jogo na sessão do site, reabrindo só pelo tempo da escrita. */
    private static function remember(string $key, $value): void
    {
        $reopen = session_status() !== PHP_SESSION_ACTIVE && !headers_sent();
        if ($reopen) @session_start();
        if ($value === null) unset($_SESSION[$key]);
        else $_SESSION[$key] = $value;
        if ($reopen) session_write_close();
    }

    public static function userId(): ?int
    {
        return !empty($_SESSION['sim_user_id']) ? (int) $_SESSION['sim_user_id'] : null;
    }

    /** Conta local de quem joga, com o nome que ele usa no site. */
    public static function user(): ?array
    {
        $id = self::userId();
        if (!$id) return null;
        $st = self::conn()->prepare("SELECT id, username, email FROM users WHERE id=?");
        $st->execute([$id]);
        $u = $st->fetch() ?: null;
        if ($u) $u['name'] = (string) ($_SESSION['user_name'] ?? $u['username']);
        return $u;
    }

    // ---------- Saves ----------

    public static function saves(int $userId): array
    {
        $st = self::conn()->prepare("SELECT * FROM saves WHERE user_id=? ORDER BY slot");
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    public static function save(int $saveId): ?array
    {
        $st = self::conn()->prepare("SELECT * FROM saves WHERE id=?");
        $st->execute([$saveId]);
        return $st->fetch() ?: null;
    }

    /** Slots livres (1..MAX_SAVES) para um usuário. */
    public static function freeSlots(int $userId): array
    {
        $used = array_map(fn($s) => (int) $s['slot'], self::saves($userId));
        $free = [];
        for ($i = 1; $i <= self::MAX_SAVES; $i++) if (!in_array($i, $used)) $free[] = $i;
        return $free;
    }

    /**
     * Cria um save: registra na conta, instala uma liga nova no SQLite do save,
     * define a franquia do GM e o nome do GM. Retorna o id do save.
     */
    public static function createSave(
        int $userId, int $slot, string $name, string $gmName, string $teamAbbr,
        string $eraKey = 'modern', string $coachStyle = 'equilibrado',
        string $difficulty = 'normal', string $potentialType = 'real'
    ): array {
        require_once __DIR__ . '/Installer.php';
        require_once __DIR__ . '/League.php';

        if (!in_array($slot, range(1, self::MAX_SAVES), true)) return ['error' => 'Slot inválido.'];
        if (!in_array($slot, self::freeSlots($userId), true)) return ['error' => 'Esse slot já está em uso.'];
        $name = trim($name) ?: ('Save ' . $slot);
        $gmName = trim($gmName) ?: 'GM';
        $eras = require dirname(__DIR__) . '/data/eras.php';
        if (!isset($eras[$eraKey])) $eraKey = 'modern';
        $eraName = $eras[$eraKey]['name'] ?? 'Era Atual';
        // sanitize
        $coachStyle    = in_array($coachStyle,    ['ofensivo','defensivo','equilibrado','desenvolvimento','vencedor'], true) ? $coachStyle : 'equilibrado';
        $difficulty    = in_array($difficulty,    ['facil','normal','dificil'], true) ? $difficulty : 'normal';
        $potentialType = in_array($potentialType, ['real','aleatorio'], true) ? $potentialType : 'real';

        $db = self::conn();
        $db->prepare("INSERT INTO saves(user_id,slot,name,gm_name,team_abbr,era,era_name,coach_style,difficulty,potential_type,created_at,updated_at)
                      VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$userId, $slot, $name, $gmName, $teamAbbr, $eraKey, $eraName,
                      $coachStyle, $difficulty, $potentialType, date('c'), date('c')]);
        $saveId = (int) $db->lastInsertId();

        // instala a liga (da era escolhida) dentro do arquivo do save
        Database::useSavePath(self::savePath($saveId));
        Installer::run($eraKey);
        $team = League::teamByAbbr($teamAbbr);
        if (!$team) { // fallback defensivo
            $team = League::allTeams()[0];
        }
        League::setGmTeam((int) $team['id']);
        Database::setMeta('gm_name', $gmName);
        Database::setMeta('save_name', $name);
        Database::setMeta('coach_style', $coachStyle);
        Database::setMeta('difficulty', $difficulty);
        Database::setMeta('potential_type', $potentialType);

        // Cria o técnico do GM com atributos baseados no estilo escolhido
        $coachAttrs = Database::coachAttrsForStyle($coachStyle);
        Database::conn()->prepare(
            "INSERT INTO coaches(team_id,name,style,ofensivo,defensivo,desenvolvimento,gestao,intensidade)
             VALUES(?,?,?,?,?,?,?,?)"
        )->execute([(int)$team['id'], $gmName, $coachStyle,
                    $coachAttrs['ofensivo'], $coachAttrs['defensivo'], $coachAttrs['desenvolvimento'],
                    $coachAttrs['gestao'], $coachAttrs['intensidade']]);

        // potencial aleatório: redistribui potenciais de todos os jogadores
        if ($potentialType === 'aleatorio') {
            Installer::randomizePotentials();
        }

        League::ensurePicksWindow(League::season(), League::PICK_WINDOW); // picks dos próximos 5 anos

        // Abre a janela de PRÉ-TEMPORADA (estilo 2K): trocas + free agency + eventos
        // na caixa de entrada antes do início da temporada regular.
        League::startPreseason();

        $db->prepare("UPDATE saves SET team_abbr=? WHERE id=?")->execute([$team['abbr'], $saveId]);

        self::remember('sim_save_id', $saveId);
        return ['ok' => true, 'save_id' => $saveId];
    }

    /** Ativa um save: valida posse, aponta o Database para o arquivo do save. */
    public static function activate(int $saveId): array
    {
        $s = self::save($saveId);
        if (!$s || (int) $s['user_id'] !== self::userId()) return ['error' => 'Save não encontrado.'];
        if (!is_file(self::savePath($saveId))) return ['error' => 'Arquivo do save ausente.'];
        if ((int) ($_SESSION['sim_save_id'] ?? 0) !== $saveId) self::remember('sim_save_id', $saveId);
        Database::useSavePath(self::savePath($saveId));
        return ['ok' => true];
    }

    /** Save ativo na sessão (id), se houver. */
    public static function activeSaveId(): ?int
    {
        return !empty($_SESSION['sim_save_id']) ? (int) $_SESSION['sim_save_id'] : null;
    }

    /** Esquece o save ativo (arquivo sumiu, save excluído). */
    public static function forgetActiveSave(): void
    {
        self::remember('sim_save_id', null);
    }

    /** Atualiza o "updated_at" do save ativo (chamado após avançar a temporada). */
    public static function touch(int $saveId): void
    {
        self::conn()->prepare("UPDATE saves SET updated_at=? WHERE id=?")->execute([date('c'), $saveId]);
    }

    public static function deleteSave(int $saveId): array
    {
        $s = self::save($saveId);
        if (!$s || (int) $s['user_id'] !== self::userId()) return ['error' => 'Save não encontrado.'];
        self::conn()->prepare("DELETE FROM saves WHERE id=?")->execute([$saveId]);
        $path = self::savePath($saveId);
        if (is_file($path)) @unlink($path);
        if (self::activeSaveId() === $saveId) self::remember('sim_save_id', null);
        return ['ok' => true];
    }
}
