<?php
/**
 * A LISTA DOS JOGOS QUE ACEITAM CONFIGURAÇÃO, num lugar só.
 *
 * Existiam duas telas de "controle de jogos" — admin-games-controle.php e
 * games/admin/controlegames.php — gravando na MESMA tabela (fba_game_controls)
 * a partir de listas diferentes:
 *
 *   uma tinha    memoria, termo, flappy, pinguim, ai
 *   a outra      termo, memoria, bomba, quemsoueu, flappy, pinguim
 *
 * O resultado é que cinco jogos que leem o multiplicador — acerteacesta,
 * boxnba, conexoes, grade e hoopgrid — não apareciam em lugar nenhum, e não
 * havia como dobrar a moeda deles nem sabendo que existiam. E `ai` aparecia
 * numa tela sem ser jogo: ligar aquele botão não fazia nada em canto nenhum.
 *
 * Esta lista é a única verdade. Foi montada a partir de quem realmente chama
 * getGamePointsMultiplier() — ou seja, de quem o multiplicador afeta de fato.
 * Jogo novo que passe a ler o multiplicador entra aqui, e aparece nas telas
 * sozinho.
 */

/**
 * @return array<string,array{label:string,desc:string,icon:string}>
 */
function gamesComDobro(): array
{
    return [
        // Diários: uma partida por dia, e o acerto vale moeda cheia.
        'termo'        => ['label' => 'Termo',          'desc' => 'Diário · acerto vale moedas',   'icon' => 'bi-fonts'],
        'memoria'      => ['label' => 'Memória',        'desc' => 'Diário · acerto vale moedas',   'icon' => 'bi-grid-3x3-gap-fill'],
        'quemsoueu'    => ['label' => 'Quem Sou Eu?',   'desc' => 'Diário · acerto vale moedas',   'icon' => 'bi-question-circle'],
        'bomba'        => ['label' => 'Bomba',          'desc' => 'Diário · diamantes achados',    'icon' => 'bi-gem'],
        'conexoes'     => ['label' => 'Conexões',       'desc' => 'Diário · grupos certos',        'icon' => 'bi-diagram-3'],
        'grade'        => ['label' => 'Grade',          'desc' => 'Diário · acertos na grade',     'icon' => 'bi-grid-3x3'],
        'hoopgrid'     => ['label' => 'HoopGrid',       'desc' => 'Diário · acertos na grade',     'icon' => 'bi-basket'],
        'boxnba'       => ['label' => 'Box NBA',        'desc' => 'Diário · placar adivinhado',    'icon' => 'bi-clipboard-data'],

        // Livres: joga quantas vezes quiser, e o prêmio sai da partida.
        'flappy'       => ['label' => 'Flappy Bird',    'desc' => 'Livre · pontuação da partida',  'icon' => 'bi-airplane'],
        'pinguim'      => ['label' => 'Pinguim Run',    'desc' => 'Livre · pontuação da partida',  'icon' => 'bi-snow'],
        'acerteacesta' => ['label' => 'Lance Livre',    'desc' => 'Livre · cestas da partida',     'icon' => 'bi-basket2-fill'],
        'buildplayer'  => ['label' => 'Build Player',   'desc' => 'Livre · top 10 da história',    'icon' => 'bi-person-gear'],
    ];
}

/** Só as chaves — pra validar o que vem do formulário. */
function gamesComDobroChaves(): array
{
    return array_keys(gamesComDobro());
}

/**
 * O que está ligado agora, chave => 0|1.
 *
 * Devolve TODAS as chaves da lista, inclusive as que nunca foram salvas: a
 * tela precisa desenhar o jogo mesmo que ninguém tenha tocado nele ainda.
 */
function gamesDobroAtual(PDO $pdo): array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_game_controls (
        game_key  VARCHAR(50) PRIMARY KEY,
        is_double TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $atual = array_fill_keys(gamesComDobroChaves(), 0);
    try {
        foreach ($pdo->query("SELECT game_key, is_double FROM fba_game_controls") as $r) {
            $k = (string)$r['game_key'];
            if (array_key_exists($k, $atual)) $atual[$k] = (int)$r['is_double'] === 1 ? 1 : 0;
        }
    } catch (Throwable $e) {
        error_log('[games_config] ler dobro: ' . $e->getMessage());
    }
    return $atual;
}

/**
 * Grava o dobro de um jogo só.
 *
 * Um por vez, e não a lista inteira de uma vez: as telas antigas salvavam
 * tudo junto, então abrir uma delas e clicar em salvar DESLIGAVA o dobro dos
 * jogos que aquela tela não conhecia — que era metade do catálogo.
 */
function gamesDobroSalvar(PDO $pdo, string $jogo, bool $ligado): bool
{
    if (!in_array($jogo, gamesComDobroChaves(), true)) return false;
    gamesDobroAtual($pdo); // garante a tabela
    $st = $pdo->prepare("INSERT INTO fba_game_controls (game_key, is_double) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE is_double = VALUES(is_double)");
    return $st->execute([$jogo, $ligado ? 1 : 0]);
}
