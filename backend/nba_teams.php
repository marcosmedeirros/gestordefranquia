<?php
/**
 * Os 30 times reais da NBA — usados no cadastro da liga ROOKIE, onde o GM
 * escolhe um time de verdade em vez de criar um time fictício (como nas
 * outras ligas). O logo vem direto do cdn.nba.com, mesmo padrão já usado em
 * games/games/boxnba.php e games/games/hoopgrid.php — não precisa hospedar
 * nem fazer upload de imagem nenhuma.
 */

function nbaTeams(): array
{
    return [
        ['id' => 1610612738, 'abbr' => 'BOS', 'city' => 'Boston',        'name' => 'Celtics',       'conference' => 'LESTE'],
        ['id' => 1610612751, 'abbr' => 'BKN', 'city' => 'Brooklyn',      'name' => 'Nets',          'conference' => 'LESTE'],
        ['id' => 1610612752, 'abbr' => 'NYK', 'city' => 'New York',      'name' => 'Knicks',        'conference' => 'LESTE'],
        ['id' => 1610612755, 'abbr' => 'PHI', 'city' => 'Philadelphia',  'name' => '76ers',         'conference' => 'LESTE'],
        ['id' => 1610612761, 'abbr' => 'TOR', 'city' => 'Toronto',       'name' => 'Raptors',       'conference' => 'LESTE'],
        ['id' => 1610612741, 'abbr' => 'CHI', 'city' => 'Chicago',       'name' => 'Bulls',         'conference' => 'LESTE'],
        ['id' => 1610612739, 'abbr' => 'CLE', 'city' => 'Cleveland',     'name' => 'Cavaliers',     'conference' => 'LESTE'],
        ['id' => 1610612765, 'abbr' => 'DET', 'city' => 'Detroit',       'name' => 'Pistons',       'conference' => 'LESTE'],
        ['id' => 1610612754, 'abbr' => 'IND', 'city' => 'Indiana',       'name' => 'Pacers',        'conference' => 'LESTE'],
        ['id' => 1610612749, 'abbr' => 'MIL', 'city' => 'Milwaukee',     'name' => 'Bucks',         'conference' => 'LESTE'],
        ['id' => 1610612737, 'abbr' => 'ATL', 'city' => 'Atlanta',       'name' => 'Hawks',         'conference' => 'LESTE'],
        ['id' => 1610612766, 'abbr' => 'CHA', 'city' => 'Charlotte',     'name' => 'Hornets',       'conference' => 'LESTE'],
        ['id' => 1610612748, 'abbr' => 'MIA', 'city' => 'Miami',         'name' => 'Heat',          'conference' => 'LESTE'],
        ['id' => 1610612753, 'abbr' => 'ORL', 'city' => 'Orlando',       'name' => 'Magic',         'conference' => 'LESTE'],
        ['id' => 1610612764, 'abbr' => 'WAS', 'city' => 'Washington',    'name' => 'Wizards',       'conference' => 'LESTE'],
        ['id' => 1610612743, 'abbr' => 'DEN', 'city' => 'Denver',        'name' => 'Nuggets',       'conference' => 'OESTE'],
        ['id' => 1610612750, 'abbr' => 'MIN', 'city' => 'Minnesota',     'name' => 'Timberwolves',  'conference' => 'OESTE'],
        ['id' => 1610612760, 'abbr' => 'OKC', 'city' => 'Oklahoma City', 'name' => 'Thunder',       'conference' => 'OESTE'],
        ['id' => 1610612757, 'abbr' => 'POR', 'city' => 'Portland',      'name' => 'Trail Blazers', 'conference' => 'OESTE'],
        ['id' => 1610612762, 'abbr' => 'UTA', 'city' => 'Utah',          'name' => 'Jazz',          'conference' => 'OESTE'],
        ['id' => 1610612744, 'abbr' => 'GSW', 'city' => 'Golden State',  'name' => 'Warriors',      'conference' => 'OESTE'],
        ['id' => 1610612746, 'abbr' => 'LAC', 'city' => 'LA',            'name' => 'Clippers',      'conference' => 'OESTE'],
        ['id' => 1610612747, 'abbr' => 'LAL', 'city' => 'Los Angeles',   'name' => 'Lakers',        'conference' => 'OESTE'],
        ['id' => 1610612756, 'abbr' => 'PHX', 'city' => 'Phoenix',       'name' => 'Suns',          'conference' => 'OESTE'],
        ['id' => 1610612758, 'abbr' => 'SAC', 'city' => 'Sacramento',    'name' => 'Kings',         'conference' => 'OESTE'],
        ['id' => 1610612742, 'abbr' => 'DAL', 'city' => 'Dallas',        'name' => 'Mavericks',     'conference' => 'OESTE'],
        ['id' => 1610612745, 'abbr' => 'HOU', 'city' => 'Houston',       'name' => 'Rockets',       'conference' => 'OESTE'],
        ['id' => 1610612763, 'abbr' => 'MEM', 'city' => 'Memphis',       'name' => 'Grizzlies',     'conference' => 'OESTE'],
        ['id' => 1610612740, 'abbr' => 'NOP', 'city' => 'New Orleans',   'name' => 'Pelicans',      'conference' => 'OESTE'],
        ['id' => 1610612759, 'abbr' => 'SAS', 'city' => 'San Antonio',   'name' => 'Spurs',         'conference' => 'OESTE'],
    ];
}

function nbaTeamLogoUrl(int $nbaId): string
{
    return "https://cdn.nba.com/logos/nba/{$nbaId}/global/L/logo.svg";
}

function nbaTeamById(int $id): ?array
{
    foreach (nbaTeams() as $t) {
        if ($t['id'] === $id) return $t;
    }
    return null;
}

function ensureNbaTeamColumn(PDO $pdo): void
{
    try {
        if ($pdo->query("SHOW COLUMNS FROM teams LIKE 'nba_team_id'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE teams ADD COLUMN nba_team_id INT NULL");
        }
        // Único, mas MySQL/MariaDB permite vários NULL — times fictícios das
        // outras ligas nunca preenchem essa coluna.
        $idx = $pdo->query("SHOW INDEX FROM teams WHERE Key_name = 'uniq_teams_nba_team_id'");
        if ($idx->rowCount() === 0) {
            $pdo->exec("ALTER TABLE teams ADD UNIQUE INDEX uniq_teams_nba_team_id (nba_team_id)");
        }
    } catch (Throwable $e) {
        error_log('ensureNbaTeamColumn: ' . $e->getMessage());
    }
}

/** IDs dos times da NBA já escolhidos por algum time cadastrado. */
function nbaTeamsTaken(PDO $pdo): array
{
    ensureNbaTeamColumn($pdo);
    try {
        $stmt = $pdo->query("SELECT nba_team_id FROM teams WHERE nba_team_id IS NOT NULL");
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}
