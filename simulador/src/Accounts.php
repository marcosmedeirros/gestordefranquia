<?php
require_once __DIR__ . '/Database.php';

/**
 * Contas de usuário + saves (multi-save). Cada usuário pode ter até 2 saves;
 * cada save é um banco SQLite isolado em storage/saves/save_{id}.sqlite.
 * As contas e o registro de saves ficam num SQLite próprio (storage/accounts.sqlite).
 */
class Accounts
{
    public const MAX_SAVES = 2;

    private static ?PDO $acc = null;

    public static function savesDir(): string { return dirname(__DIR__) . '/storage/saves'; }
    public static function savePath(int $saveId): string { return self::savesDir() . '/save_' . $saveId . '.sqlite'; }

    /** Conexão (e schema) do banco de contas. */
    public static function conn(): PDO
    {
        if (self::$acc === null) {
            $path = dirname(__DIR__) . '/storage/accounts.sqlite';
            $dir = dirname($path);
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            self::$acc = new PDO('sqlite:' . $path);
            self::$acc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$acc->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$acc->exec("CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                email TEXT,
                pass_hash TEXT NOT NULL,
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
        }
        return self::$acc;
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

    public static function login(string $username, string $pass): array
    {
        $st = self::conn()->prepare("SELECT * FROM users WHERE username=?");
        $st->execute([trim($username)]);
        $u = $st->fetch();
        if (!$u || !password_verify($pass, $u['pass_hash'])) {
            return ['error' => 'Usuário ou senha inválidos.'];
        }
        $_SESSION['user_id'] = (int) $u['id'];
        return ['ok' => true, 'id' => (int) $u['id']];
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
