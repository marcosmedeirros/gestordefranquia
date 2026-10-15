<?php
/**
 * AS TENDÊNCIAS DOS JOGADORES — as regras.
 *
 * Tendência é o que o jogo usa pra decidir o que o jogador TENTA fazer em
 * quadra: o quanto ele chuta, o quanto pede a bola, de onde arremessa. São
 * dezenas de números de 0 a 100, e eles não são atributo nem estatística —
 * por isso não cabiam em nenhuma das telas que já existiam.
 *
 * ── POR QUE O ESQUEMA É LIVRE ────────────────────────────────────────
 *
 * A tela do jogo mostra umas doze colunas por vez e rola pro lado; são bem
 * mais no total, e o nome de cada uma é do jogo (T/SHOT, T/SCLOSE, T/SUSMID).
 * Fixar uma lista aqui significaria adivinhar o resto e, na primeira coluna
 * nova, mexer no código outra vez.
 *
 * Então QUEM MANDA É O CSV: cada linha vira um JSON com as colunas que
 * vieram, e a tela mostra o que existir. Subiu um CSV com dez colunas, a
 * tabela tem dez; subiu outro com cinquenta, passa a ter cinquenta. A ordem
 * das colunas na tela é a ordem em que elas aparecem no arquivo.
 *
 * ── O QUE NÃO É LIVRE ────────────────────────────────────────────────
 *
 * O valor. Tendência é número de 0 a 100 — qualquer outra coisa é recusada
 * com o motivo, em vez de entrar no banco e virar gráfico torto depois.
 * E o jogador: o id tem que existir e ser da liga pedida.
 *
 * @see tendencys-elite.php  a tela
 * @see js/elenco-csv.js     o modelo e o prompt pra IA
 */

/** As colunas que o print do jogo mostra na primeira tela, na ordem dele. */
const TEND_COLUNAS_SEMENTE = [
    'T/SHOT', 'T/TOUCH', 'T/SCLOSE', 'T/SUNDER', 'T/SCL', 'T/SCM', 'T/SCR',
    'T/SMID', 'T/SUSMID',
];

/** Cria a tabela na primeira vez. Uma linha por jogador. */
function tendTabela(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS player_tendencies (
        player_id INT NOT NULL PRIMARY KEY,
        dados TEXT NOT NULL,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $feito = true;
}

/**
 * Os jogadores da liga com as tendências que já tiverem.
 *
 * Vem TODO MUNDO, inclusive quem nunca foi lançado — é a tela de quem vai
 * preencher, e esconder os vazios esconderia justamente o trabalho que falta.
 *
 * @return array{jogadores:array,colunas:array}
 */
function tendJogadores(PDO $pdo, string $liga): array
{
    tendTabela($pdo);
    $st = $pdo->prepare("SELECT p.id, p.name, p.position, p.secondary_position, p.age, p.ovr,
                                CONCAT(t.city, ' ', t.name) AS time, t.id AS time_id,
                                tt.dados, tt.atualizado_em
                           FROM players p
                           JOIN teams t ON t.id = p.team_id
                      LEFT JOIN player_tendencies tt ON tt.player_id = p.id
                          WHERE t.league = ?
                       ORDER BY p.ovr DESC, p.name");
    $st->execute([$liga]);

    $jogadores = [];
    $colunas = [];      // na ordem em que aparecem; é a ordem do CSV que gravou
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $j) {
        $t = [];
        if (!empty($j['dados'])) {
            $d = json_decode((string)$j['dados'], true);
            if (is_array($d)) $t = $d;
        }
        foreach (array_keys($t) as $c) if (!in_array($c, $colunas, true)) $colunas[] = $c;
        $j['tend'] = $t;
        unset($j['dados']);
        $jogadores[] = $j;
    }
    /* Ninguém lançou nada ainda: a tela precisa de alguma coluna pra desenhar
       o cabeçalho, e o modelo precisa de alguma pra ser um modelo. */
    if (!$colunas) $colunas = TEND_COLUNAS_SEMENTE;

    return ['jogadores' => $jogadores, 'colunas' => $colunas];
}

/**
 * Grava o que veio do CSV.
 *
 * O CSV manda nas colunas, mas NÃO manda em quem é o jogador: o id tem que
 * ser de alguém da liga pedida. Linha com id de outra liga é recusada com o
 * motivo — passar batido seria pior, porque a pessoa fecharia a tela achando
 * que lançou.
 *
 * COLUNA VAZIA NÃO APAGA. Vale a regra que as outras telas já usam: em branco
 * quer dizer "não mexi nessa", e o valor que estava continua. Para zerar de
 * verdade, escreve-se 0.
 *
 * @param array $linhas  a primeira é o cabeçalho
 * @return array{gravados:int,ignorados:int,erros:array,colunas:array}
 */
function tendImportar(PDO $pdo, string $liga, array $linhas): array
{
    tendTabela($pdo);
    $erros = [];
    if (count($linhas) < 2) return ['gravados' => 0, 'ignorados' => 0,
                                    'erros' => ['O arquivo não tem nenhuma linha de jogador.'],
                                    'colunas' => []];

    $cab = array_map(fn($c) => trim((string)$c), array_shift($linhas));
    $iId = array_search('id', array_map('strtolower', $cab), true);
    if ($iId === false) {
        return ['gravados' => 0, 'ignorados' => 0, 'colunas' => [],
                'erros' => ['Falta a coluna "id". Baixe o modelo desta tela e não apague essa coluna.']];
    }

    /* As colunas de tendência são todas as que não são identificação. O nome
       vai pro banco como veio do jogo, em maiúsculas, pra "t/shot" e "T/SHOT"
       não virarem duas colunas diferentes na tela. */
    $fora = ['id', 'jogador', 'nome', 'time', 'pos', 'posicao', 'idade', 'age', 'ovr', 'rating'];
    $colunas = [];
    foreach ($cab as $i => $c) {
        if ($c === '' || in_array(strtolower($c), $fora, true)) continue;
        $colunas[$i] = strtoupper($c);
    }
    if (!$colunas) {
        return ['gravados' => 0, 'ignorados' => 0, 'colunas' => [],
                'erros' => ['O arquivo só tem as colunas de identificação — nenhuma tendência pra gravar.']];
    }

    // De quem é cada id, pra recusar jogador de outra liga sem ir ao banco toda hora.
    $st = $pdo->prepare("SELECT p.id, p.name FROM players p JOIN teams t ON t.id = p.team_id
                          WHERE t.league = ?");
    $st->execute([$liga]);
    $daLiga = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $daLiga[(int)$p['id']] = $p['name'];

    $antes = $pdo->prepare('SELECT dados FROM player_tendencies WHERE player_id = ?');
    $grava = $pdo->prepare("INSERT INTO player_tendencies (player_id, dados) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE dados = VALUES(dados),
                                                    atualizado_em = CURRENT_TIMESTAMP");
    $gravados = 0; $ignorados = 0;

    foreach ($linhas as $n => $l) {
        $linhaNum = $n + 2;                    // +1 do cabeçalho, +1 porque humano conta do 1
        $id = (int)trim((string)($l[$iId] ?? ''));
        if (!$id) { $ignorados++; continue; }   // linha em branco no fim do arquivo
        if (!isset($daLiga[$id])) {
            $erros[] = "Linha {$linhaNum}: o jogador de id {$id} não é da {$liga}.";
            $ignorados++;
            continue;
        }

        $antes->execute([$id]);
        $atual = json_decode((string)($antes->fetchColumn() ?: '{}'), true);
        if (!is_array($atual)) $atual = [];

        $mudou = false;
        foreach ($colunas as $i => $nome) {
            $v = trim((string)($l[$i] ?? ''));
            if ($v === '') continue;            // em branco = não mexi
            if (!preg_match('/^\d{1,3}$/', $v) || (int)$v > 100) {
                $erros[] = "Linha {$linhaNum} ({$daLiga[$id]}), {$nome}: \"{$v}\" não é um número de 0 a 100.";
                continue;
            }
            $atual[$nome] = (int)$v;
            $mudou = true;
        }
        if (!$mudou) { $ignorados++; continue; }

        $grava->execute([$id, json_encode($atual, JSON_UNESCAPED_UNICODE)]);
        $gravados++;
    }

    return ['gravados' => $gravados, 'ignorados' => $ignorados,
            'erros' => $erros, 'colunas' => array_values($colunas)];
}
