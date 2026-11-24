<?php
/**
 * O HISTÓRICO DE TEMPORADAS, e os rankings que saem dele.
 *
 * O save é um arquivo por pessoa e morre quando ela recomeça — então ele não
 * serve de memória da liga. Quem guarda o que aconteceu é esta tabela, no
 * banco dos Games: uma linha por temporada começada, fechada quando a última
 * série acaba.
 *
 * É daqui que saem as quatro listas da tela inicial: os times mais
 * escolhidos, os mais campeões, e os GMs com mais temporadas e mais títulos.
 */

require_once __DIR__ . '/temporada_liga.php';

function temporadaHistGarantir(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto) return;
    $pronto = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS temporada_historico (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            liga VARCHAR(10) NOT NULL,
            fba_team_id INT NOT NULL,
            vitorias INT NOT NULL DEFAULT 0,
            derrotas INT NOT NULL DEFAULT 0,
            campeao TINYINT(1) NOT NULL DEFAULT 0,
            fim VARCHAR(60) NULL,
            comecou_em DATETIME NOT NULL,
            fechou_em DATETIME NULL,
            INDEX idx_th_user (user_id),
            INDEX idx_th_time (fba_team_id),
            INDEX idx_th_fechou (fechou_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[temporada-hist] criar tabela: ' . $e->getMessage());
    }
}

/**
 * Anota que uma temporada começou, e devolve o id da linha.
 *
 * O id fica guardado no próprio save (`hist_id`): é assim que o fechamento
 * sabe qual linha atualizar sem precisar adivinhar pela data.
 */
function temporadaHistAbrir(int $uid, string $liga, int $fbaTeamId): void
{
    if ($uid <= 0 || $fbaTeamId <= 0) return;
    try {
        $pdo = TemporadaLiga::fbaConn();
        temporadaHistGarantir($pdo);
        $pdo->prepare("INSERT INTO temporada_historico
                        (user_id, liga, fba_team_id, comecou_em) VALUES (?, ?, ?, NOW())")
            ->execute([$uid, strtoupper($liga), $fbaTeamId]);
        Database::setMeta('hist_id', (string) $pdo->lastInsertId());
    } catch (Throwable $e) {
        error_log('[temporada-hist] abrir: ' . $e->getMessage());
    }
}

/**
 * Fecha a linha com o desfecho. Idempotente: roda em todo carregamento de
 * tela depois que a temporada acaba, e só escreve na primeira vez — o
 * `fechou_em IS NULL` no WHERE é o que garante isso, mesmo se duas abas
 * chamarem junto.
 */
function temporadaHistFechar(int $uid, array $resumo, array $est): void
{
    $histId = (int) Database::meta('hist_id', '0');
    if ($uid <= 0 || $histId <= 0) return;
    if (Database::meta('hist_fechado') === '1') return;

    try {
        $pdo = TemporadaLiga::fbaConn();
        temporadaHistGarantir($pdo);
        $st = $pdo->prepare("UPDATE temporada_historico
                                SET vitorias = ?, derrotas = ?, campeao = ?, fim = ?, fechou_em = NOW()
                              WHERE id = ? AND user_id = ? AND fechou_em IS NULL");
        $st->execute([
            (int) ($resumo['meu']['wins'] ?? 0),
            (int) ($resumo['meu']['losses'] ?? 0),
            !empty($resumo['eu_campeao']) ? 1 : 0,
            mb_substr((string) ($resumo['meu_fim'] ?? ''), 0, 60),
            $histId, $uid,
        ]);
        Database::setMeta('hist_fechado', '1');
    } catch (Throwable $e) {
        error_log('[temporada-hist] fechar: ' . $e->getMessage());
    }
}

/**
 * As quatro listas da tela inicial.
 *
 * Só temporadas FECHADAS contam campeão — uma em andamento ainda não tem
 * desfecho. Mas "vezes escolhido" conta todas, inclusive as em curso: a
 * escolha já aconteceu no instante em que a pessoa clicou.
 *
 * @return array{times_campeoes:array,times_jogados:array,gms_titulos:array,gms_temporadas:array}
 */
function temporadaRanking(int $limite = 5): array
{
    $vazio = ['times_campeoes' => [], 'times_jogados' => [],
              'gms_titulos' => [], 'gms_temporadas' => []];
    try {
        $pdo = TemporadaLiga::fbaConn();
        temporadaHistGarantir($pdo);
        $lim = max(1, min(20, $limite));

        /* O nome e o escudo vêm por JOIN, não guardados na linha: time que
           muda de nome no site muda aqui junto, e o ranking não fica
           contando um Mesa Eagles que já virou outra coisa. */
        $timesCampeoes = $pdo->query(
            "SELECT t.id, t.city, t.name, t.photo_url, t.public_primary_color AS cor, t.league,
                    COUNT(*) AS n
               FROM temporada_historico h JOIN teams t ON t.id = h.fba_team_id
              WHERE h.campeao = 1
              GROUP BY t.id, t.city, t.name, t.photo_url, t.public_primary_color, t.league
              ORDER BY n DESC, t.city ASC LIMIT $lim")->fetchAll();

        $timesJogados = $pdo->query(
            "SELECT t.id, t.city, t.name, t.photo_url, t.public_primary_color AS cor, t.league,
                    COUNT(*) AS n, SUM(h.campeao) AS titulos
               FROM temporada_historico h JOIN teams t ON t.id = h.fba_team_id
              GROUP BY t.id, t.city, t.name, t.photo_url, t.public_primary_color, t.league
              ORDER BY n DESC, titulos DESC LIMIT $lim")->fetchAll();

        $gmsTitulos = $pdo->query(
            "SELECT u.id, u.name, COUNT(*) AS n
               FROM temporada_historico h JOIN users u ON u.id = h.user_id
              WHERE h.campeao = 1
              GROUP BY u.id, u.name ORDER BY n DESC, u.name ASC LIMIT $lim")->fetchAll();

        $gmsTemporadas = $pdo->query(
            "SELECT u.id, u.name, COUNT(*) AS n, SUM(h.campeao) AS titulos
               FROM temporada_historico h JOIN users u ON u.id = h.user_id
              GROUP BY u.id, u.name ORDER BY n DESC, titulos DESC LIMIT $lim")->fetchAll();

        return ['times_campeoes' => $timesCampeoes, 'times_jogados' => $timesJogados,
                'gms_titulos' => $gmsTitulos, 'gms_temporadas' => $gmsTemporadas];
    } catch (Throwable $e) {
        error_log('[temporada-hist] ranking: ' . $e->getMessage());
        return $vazio;
    }
}

/** Quantas temporadas já foram jogadas no total — pra tela saber se vale mostrar o ranking. */
function temporadaRankingTotal(): int
{
    try {
        $pdo = TemporadaLiga::fbaConn();
        temporadaHistGarantir($pdo);
        return (int) $pdo->query("SELECT COUNT(*) FROM temporada_historico")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
