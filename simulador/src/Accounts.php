<?php
require_once __DIR__ . '/Database.php';

/**
 * Contas de usuário + saves (multi-save). Cada usuário pode ter até 2 saves.
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
    private static ?PDO $main = null;
    private static bool $mainTried = false;

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
     * Conexão somente-leitura ao banco do site principal (fbabrasil.com.br),
     * usada para permitir login no /simulador com a conta de lá. Retorna null
     * se 'main_mysql' não estiver configurado (recurso desativado) ou se a
     * conexão falhar — nesses casos o login cai de volta para a conta local.
     */
    private static function mainConn(): ?PDO
    {
        if (self::$mainTried) return self::$main;
        self::$mainTried = true;

        $cfg = Database::config();
        $m = $cfg['main_mysql'] ?? null;
        if (($cfg['driver'] ?? 'sqlite') !== 'mysql' || !is_array($m) || empty($m['database'])) {
            return null;
        }
        try {
            $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['database']};charset={$m['charset']}";
            self::$main = new PDO($dsn, $m['user'], $m['pass']);
            self::$main->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$main->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            self::$main = null;
        }
        return self::$main;
    }

    /**
     * Busca (ou cria) a conta local do /simulador vinculada a um usuário do
     * site principal (fbabrasil.com.br). Vínculo é feito por main_user_id
     * (estável mesmo se o e-mail mudar lá); casa por e-mail só na primeira
     * vez, pra não duplicar conta de quem já logou antes desse campo existir.
     * Necessário porque saves.user_id referencia a tabela users local, não a
     * do site principal — as tabelas do simulador continuam isoladas, só o
     * vínculo de identidade é compartilhado.
     */
    private static function linkedLocalUser(array $mainUser): array
    {
        $db = self::conn();
        $mainId = (int) $mainUser['id'];
        $email = trim($mainUser['email']);

        $st = $db->prepare("SELECT * FROM users WHERE main_user_id=?");
        $st->execute([$mainId]);
        $u = $st->fetch();
        if ($u) return $u;

        // Conta local pré-existente (criada antes do vínculo por id) com o mesmo e-mail: adota o vínculo.
        $st = $db->prepare("SELECT * FROM users WHERE email=? AND main_user_id IS NULL");
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u) {
            $db->prepare("UPDATE users SET main_user_id=? WHERE id=?")->execute([$mainId, (int) $u['id']]);
            $u['main_user_id'] = $mainId;
            return $u;
        }

        // Gera um username local único a partir do nome/e-mail do site principal.
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

        // Sem senha local utilizável (login sempre passa pela conta principal);
        // guarda um hash aleatório só para satisfazer a coluna NOT NULL.
        $randomHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users(username,email,pass_hash,main_user_id,created_at) VALUES(?,?,?,?,?)")
           ->execute([$username, $email, $randomHash, $mainId, date('c')]);
        $id = (int) $db->lastInsertId();
        $st = $db->prepare("SELECT * FROM users WHERE id=?");
        $st->execute([$id]);
        return $st->fetch();
    }

    // ---------- Sessão / autenticação ----------

    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('fba_sim');
            session_set_cookie_params(['path' => '/simulador/']);
            session_start();
        }
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function user(): ?array
    {
        $id = self::userId();
        if (!$id) return null;
        $st = self::conn()->prepare("SELECT id, username, email FROM users WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function register(string $username, string $email, string $pass): array
    {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) {
            return ['error' => 'Usuário deve ter 3–20 letras, números ou _.'];
        }
        if (strlen($pass) < 4) return ['error' => 'Senha muito curta (mínimo 4 caracteres).'];
        $db = self::conn();
        $ex = $db->prepare("SELECT 1 FROM users WHERE username=?");
        $ex->execute([$username]);
        if ($ex->fetch()) return ['error' => 'Este nome de usuário já existe.'];
        $db->prepare("INSERT INTO users(username,email,pass_hash,created_at) VALUES(?,?,?,?)")
           ->execute([$username, trim($email), password_hash($pass, PASSWORD_DEFAULT), date('c')]);
        $id = (int) $db->lastInsertId();
        $_SESSION['user_id'] = $id;
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Login primário: conta do site principal (fbabrasil.com.br), por e-mail.
     * Se validado lá, garante uma conta local vinculada (main_user_id) — as
     * tabelas/saves do simulador continuam só no banco do simulador.
     * Fallback (main_mysql indisponível, ex.: dev local): conta local por
     * e-mail e, por compatibilidade com contas antigas, também por username.
     */
    public static function login(string $emailOrUsername, string $pass): array
    {
        $identifier = strtolower(trim($emailOrUsername));

        $main = self::mainConn();
        if ($main) {
            $stm = $main->prepare("SELECT id, name, email, password_hash FROM users WHERE email=? LIMIT 1");
            $stm->execute([$identifier]);
            $mu = $stm->fetch();
            if ($mu && password_verify($pass, $mu['password_hash'])) {
                $local = self::linkedLocalUser($mu);
                $_SESSION['user_id'] = (int) $local['id'];
                return ['ok' => true, 'id' => (int) $local['id']];
            }
        }

        // Fallback local: por e-mail, e por username (contas antigas/dev sem main_mysql).
        $st = self::conn()->prepare("SELECT * FROM users WHERE email=? OR username=?");
        $st->execute([$identifier, trim($emailOrUsername)]);
        $u = $st->fetch();
        if ($u && password_verify($pass, $u['pass_hash'])) {
            $_SESSION['user_id'] = (int) $u['id'];
            return ['ok' => true, 'id' => (int) $u['id']];
        }

        return ['error' => 'E-mail ou senha inválidos.'];
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['save_id']);
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

        $_SESSION['save_id'] = $saveId;
        return ['ok' => true, 'save_id' => $saveId];
    }

    /** Ativa um save: valida posse, aponta o Database para o arquivo do save. */
    public static function activate(int $saveId): array
    {
        $s = self::save($saveId);
        if (!$s || (int) $s['user_id'] !== self::userId()) return ['error' => 'Save não encontrado.'];
        if (!is_file(self::savePath($saveId))) return ['error' => 'Arquivo do save ausente.'];
        $_SESSION['save_id'] = $saveId;
        Database::useSavePath(self::savePath($saveId));
        return ['ok' => true];
    }

    /** Save ativo na sessão (id), se houver. */
    public static function activeSaveId(): ?int
    {
        return isset($_SESSION['save_id']) ? (int) $_SESSION['save_id'] : null;
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
        if (self::activeSaveId() === $saveId) unset($_SESSION['save_id']);
        return ['ok' => true];
    }
}
