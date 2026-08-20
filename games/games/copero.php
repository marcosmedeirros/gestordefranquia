<?php
/**
 * COPERO — simulador de carreira de futebol.
 *
 * Você nasce aos 16 sem clube e joga até pendurar as chuteiras. Cada ano traz
 * uma decisão com o efeito e a PROBABILIDADE na cara — é isso que separa o
 * jogo de um gerador de números: a escolha é uma aposta informada.
 *
 * O motor (progressão, valor, eventos) vive em games/core/copero_motor.php e
 * o catálogo de clubes em games/core/copero_clubes.php. Aqui é só a tela e o
 * laço da carreira.
 *
 * Os nomes de clube servem pra identificar dentro da simulação. O jogo não é
 * afiliado, patrocinado nem endossado por nenhum deles, e não hospeda escudo:
 * clube sem imagem aparece como monograma.
 */

session_start();
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../core/copero_motor.php';
require_once __DIR__ . '/../core/cartao.php';   // o cartão em imagem, igual aos outros jogos

$idUsuario = (int)($_SESSION['user_id'] ?? 0);
$pdo = db();

// Moedas da conta, pra barra de cima. O jogo roda sem login — quem não está
// logado vê a barra sem a ficha de moeda, e não um zero que não é dele.
$moedasUsuario = null;
if ($idUsuario > 0) {
    try {
        $st = $pdo->prepare("SELECT pontos FROM games_usuarios WHERE id = ?");
        $st->execute([$idUsuario]);
        $v = $st->fetchColumn();
        if ($v !== false) $moedasUsuario = (int)$v;
    } catch (Throwable $e) { /* sem moeda na barra é melhor que sem jogo */ }
}

/** Guarda a carreira encerrada, pro ranking e pro histórico. */
function coperoGarantirTabela(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS copero_carreiras (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            nome VARCHAR(40) NOT NULL,
            numero TINYINT NULL,
            posicao VARCHAR(4) NOT NULL,
            pais VARCHAR(4) NOT NULL,
            pico_ovr TINYINT NOT NULL,
            pico_valor BIGINT NOT NULL,
            jogos INT NOT NULL, gols INT NOT NULL, ast INT NOT NULL,
            clubes TINYINT NOT NULL,
            temporadas TINYINT NOT NULL,
            encerrada_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_copero_pico (pico_ovr)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Colunas que vieram depois. `CREATE TABLE IF NOT EXISTS` não altera
        // tabela que já existe — quem já jogou tem a tabela velha, e sem isto
        // o hall da fama não teria de onde tirar título nem pontuação.
        foreach ([
            'titulos'   => 'SMALLINT NOT NULL DEFAULT 0',
            'pontuacao' => 'INT NOT NULL DEFAULT 0',
        ] as $col => $tipo) {
            if ($pdo->query("SHOW COLUMNS FROM copero_carreiras LIKE '{$col}'")->rowCount() === 0) {
                $pdo->exec("ALTER TABLE copero_carreiras ADD COLUMN {$col} {$tipo}");
            }
        }
        // As carreiras gravadas ANTES destas colunas ficariam todas com zero,
        // e zero empatado não é ranking. Elas recebem a pontuação possível
        // com o que se sabe delas — sem os títulos, que ninguém guardou.
        $pdo->exec("UPDATE copero_carreiras
                    SET pontuacao = ROUND(GREATEST(0, pico_ovr - 60) * 34
                                        + (gols + ast) * 1.6 + jogos * 0.4
                                        + pico_valor / 1000000 * 2)
                    WHERE pontuacao = 0");

        // O índice por último e no seu PRÓPRIO try: ele estoura toda vez
        // depois da primeira, e engolia o UPDATE acima junto.
        try { $pdo->exec("CREATE INDEX idx_copero_pontuacao ON copero_carreiras (pontuacao)"); }
        catch (Throwable $e) { /* já existe */ }
    } catch (Throwable $e) {
        // O índice duplicado cai aqui na segunda vez, e tudo bem.
        if (!str_contains($e->getMessage(), 'Duplicate key name')) {
            error_log('[copero] tabela: ' . $e->getMessage());
        }
    }
}

/**
 * A tabela que registra quais conquistas cada pessoa já levou.
 *
 * A UNIQUE é o coração disso: é ela que garante que uma conquista pague uma
 * vez só. Sem ela, repetir carreiras fáceis viraria fábrica de moeda.
 */
function coperoGarantirConquistas(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS copero_conquistas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            conquista VARCHAR(32) NOT NULL,
            nivel VARCHAR(12) NOT NULL,
            moedas SMALLINT NOT NULL DEFAULT 0,
            fba_points SMALLINT NOT NULL DEFAULT 0,
            ganha_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_copero_conq (id_usuario, conquista)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[copero] tabela conquistas: ' . $e->getMessage());
    }
}

/**
 * Registra as conquistas novas e paga por elas.
 *
 * A UNIQUE decide o que é novo: o INSERT que colidir não afeta linha nenhuma,
 * e é por `rowCount()` que sabemos se a pessoa já tinha aquilo. Fazer a
 * pergunta com SELECT antes abriria a janela pra duas abas pagarem duas vezes.
 *
 * Devolve o que foi pago e quais ids são inéditos.
 */
function coperoPagarConquistas(PDO $pdo, int $idUsuario, array $ganhas): array
{
    $premio = ['moedas' => 0, 'fba_points' => 0, 'novas' => []];
    if ($idUsuario <= 0 || !$ganhas) return $premio;

    try {
        coperoGarantirConquistas($pdo);
        // A conta do FBA Games nasce quando a pessoa abre o games. Quem nunca
        // abriu não tem linha, e o UPDATE acertaria zero — o mesmo buraco que
        // já deixou gente sem receber no quiz.
        $pdo->prepare("INSERT IGNORE INTO games_usuarios (id, nome, email, league)
                       SELECT id, name, email, COALESCE(league,'ROOKIE') FROM users WHERE id = ?")
            ->execute([$idUsuario]);

        $ins = $pdo->prepare("INSERT IGNORE INTO copero_conquistas
                              (id_usuario, conquista, nivel, moedas, fba_points)
                              VALUES (?,?,?,?,?)");
        foreach ($ganhas as $g) {
            $p = COPERO_PREMIO[$g['nivel']] ?? [];
            $m = (int)($p['moedas'] ?? 0);
            $f = (int)($p['fba_points'] ?? 0);
            $ins->execute([$idUsuario, $g['id'], $g['nivel'], $m, $f]);
            if ($ins->rowCount() > 0) {
                $premio['moedas'] += $m;
                $premio['fba_points'] += $f;
                $premio['novas'][] = $g['id'];
            }
        }

        if ($premio['moedas'] > 0 || $premio['fba_points'] > 0) {
            $pdo->prepare("UPDATE games_usuarios
                           SET pontos = pontos + ?, fba_points = COALESCE(fba_points,0) + ?
                           WHERE id = ?")
                ->execute([$premio['moedas'], $premio['fba_points'], $idUsuario]);
        }
    } catch (Throwable $e) {
        error_log('[copero] pagar: ' . $e->getMessage());
    }
    return $premio;
}

/**
 * A pontuação que ordena o hall da fama.
 *
 * Precisa premiar carreira COMPLETA, e não um número só: quem faz 500 gols
 * sem ganhar nada não passa na frente de quem ganhou tudo. Os títulos pesam
 * mais que qualquer estatística, o overall entra como teto do que a pessoa
 * chegou a ser, e gols e assistências entram somados porque o goleiro não
 * tem nenhum dos dois e não pode ser punido por isso.
 */
function coperoPontuacao(array $c): int
{
    return (int)round(
        ($c['titulos'] ?? 0) * 120
      + max(0, ($c['pico_ovr'] ?? 0) - 60) * 34
      + (($c['gols'] ?? 0) + ($c['ast'] ?? 0)) * 1.6
      + ($c['jogos'] ?? 0) * 0.4
      + ($c['pico_valor'] ?? 0) / 1000000 * 2
    );
}

// ── O hall da fama: as melhores carreiras de todo mundo ───────────────
//
// Público de propósito: é a única parte do jogo em que uma carreira sua é
// vista por outra pessoa, e é o que dá motivo pra caçar os desafios difíceis.
if (($_GET['acao'] ?? '') === 'hall') {
    header('Content-Type: application/json; charset=utf-8');
    $lista = [];
    try {
        coperoGarantirTabela($pdo);
        $st = $pdo->query("SELECT nome, posicao, pais, pico_ovr, gols, ast, jogos,
                                  clubes, temporadas, titulos, pontuacao, encerrada_em
                           FROM copero_carreiras ORDER BY pontuacao DESC, pico_ovr DESC LIMIT 5");
        $lista = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[copero] hall: ' . $e->getMessage());
    }
    echo json_encode(['ok' => true, 'hall' => $lista], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Encerramento: grava a carreira e devolve as conquistas ────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // Sem sessão a carreira NÃO é gravada, mas as conquistas são calculadas
    // e devolvidas do mesmo jeito. O jogo abre sem login, e recusar aqui
    // fazia quem jogasse assim terminar a carreira sem ver conquista nenhuma.
    $c = json_decode((string)($_POST['carreira'] ?? ''), true);
    if (!is_array($c)) { echo json_encode(['ok' => false, 'erro' => 'carreira inválida']); exit; }

    // Os totais são RECALCULADOS a partir das temporadas: o resumo que o
    // cliente manda é o que ele desenhou, não o que aconteceu.
    $temporadas = is_array($c['temporadas'] ?? null) ? $c['temporadas'] : [];
    $tot = ['jogos' => 0, 'gols' => 0, 'ast' => 0];
    $clubes = [];
    $picoOvr = 0; $picoValor = 0;
    $porClube = []; $continentes = [];

    // O que as conquistas precisam saber, tudo tirado das temporadas: quais
    // troféus, onde, com que clube e em que ano. O resumo que o cliente
    // manda continua não valendo como prova de nada.
    $forcaDoClube = [];
    foreach (COPERO_CLUBES as [$n, , $f, ]) $forcaDoClube[$n] = $f;

    $tit = [];              // id do troféu => quantas vezes
    $ligasVencidas = [];    // em que ligas foi campeão nacional
    $paises = [];           // países onde jogou
    $tripla = false;        // liga + copa + continental na MESMA temporada
    $menorCampeaoCont = 99; // o clube mais fraco com que ganhou o continental
    $lesoes = 0; $idadePico = 0;
    $primeiroClube = null; $primeiroNivel = 0; $subiuComOMesmo = false;
    $paisesCampeao = [];                 // em quantos países foi campeão nacional
    $cleanSheets = 0; $golsSofridos = 0; // o boletim do goleiro
    $trocasRival = 0;                    // quantas vezes trocou pelo rival
    $seqClube = null; $seq = 0; $maiorSeq = 0;   // temporadas SEGUIDAS no mesmo clube

    foreach ($temporadas as $i => $t) {
        $tot['jogos'] += max(0, (int)($t['jogos'] ?? 0));
        $tot['gols']  += max(0, (int)($t['gols']  ?? 0));
        $tot['ast']   += max(0, (int)($t['ast']   ?? 0));
        $ovr = (int)($t['ovr'] ?? 0);
        if ($ovr > $picoOvr) { $picoOvr = $ovr; $idadePico = (int)($t['idade'] ?? 0); }
        $picoValor = max($picoValor, (int)($t['valor'] ?? 0));
        if (!empty($t['lesao'])) $lesoes++;
        if (!empty($t['rival'])) $trocasRival++;
        $cleanSheets  += max(0, (int)($t['cs'] ?? 0));
        $golsSofridos += max(0, (int)($t['gs'] ?? 0));

        $nome = (string)($t['clube'] ?? '');
        $ligaId = (string)($t['liga'] ?? '');
        if ($nome === '') continue;

        $clubes[$nome] = 1;
        $porClube[$nome] = ($porClube[$nome] ?? 0) + max(0, (int)($t['jogos'] ?? 0));
        $liga = coperoLigaDoClube($ligaId);
        $continentes[$liga['continente']] = 1;
        $paises[$liga['pais']] = 1;

        // "De baixo": começar na terceira divisão e ganhar a primeira com o
        // MESMO clube. É o clube que sobe junto com você.
        if ($primeiroClube === null) { $primeiroClube = $nome; $primeiroNivel = (int)$liga['nivel']; }

        // A sequência é SEGUIDA: quem sai e volta recomeça a contagem, que é
        // o que "ídolo da casa" quer dizer.
        if ($nome === $seqClube) { $seq++; } else { $seqClube = $nome; $seq = 1; }
        $maiorSeq = max($maiorSeq, $seq);

        $daTemporada = is_array($t['titulos'] ?? null) ? $t['titulos'] : [];
        foreach ($daTemporada as $id) {
            $id = (string)$id;
            $tit[$id] = ($tit[$id] ?? 0) + 1;
            if ($id === 'liga') {
                $ligasVencidas[$ligaId] = 1;
                $paisesCampeao[$liga['pais']] = 1;
                // "Do fundo ao topo": o clube que te contratou na TERCEIRA
                // divisão é o mesmo que te deu o título da primeira. Da
                // segunda pra primeira é subida de uma divisão — acontece
                // todo ano e não é a história que a conquista conta.
                if ($nome === $primeiroClube && $primeiroNivel >= 3 && (int)$liga['nivel'] === 1) {
                    $subiuComOMesmo = true;
                }
            }
            if ($id === 'cont') $menorCampeaoCont = min($menorCampeaoCont, $forcaDoClube[$nome] ?? 99);
        }
        if (in_array('liga', $daTemporada, true) && in_array('copa', $daTemporada, true)
            && in_array('cont', $daTemporada, true)) $tripla = true;
    }
    $picoOvr = min(99, $picoOvr);

    // Conquistas: testadas no servidor, com os totais recalculados.
    // As cinco grandes ligas europeias, pra "Dono da Europa".
    $grandes = ['EN1','ES1','IT1','DE1','FR1'];
    $grandesVencidas = count(array_intersect($grandes, array_keys($ligasVencidas)));
    $coletivos = ($tit['liga'] ?? 0) + ($tit['copa'] ?? 0) + ($tit['cont'] ?? 0)
               + ($tit['cont2'] ?? 0) + ($tit['cont3'] ?? 0)
               + ($tit['mundial'] ?? 0) + ($tit['copa_mundo'] ?? 0) + ($tit['selecao_cont'] ?? 0);

    // A estante inteira: o que o time ganhou MAIS o que foi só seu. É o que
    // "O mais condecorado" conta — coletivo sozinho mede o clube, não a
    // carreira de quem esteve nele.
    $individuais = ($tit['artilheiro'] ?? 0) + ($tit['chuteira'] ?? 0)
                 + ($tit['bola_ouro'] ?? 0) + ($tit['rei_america'] ?? 0)
                 + ($tit['luva_ouro'] ?? 0);
    // Supercopas entram como troféu, não como título coletivo: são taça de
    // verdade, mas não é delas que se lembra ao contar o que o time ganhou.
    $trofeus = $coletivos + $individuais
             + ($tit['supernac'] ?? 0) + ($tit['supercont'] ?? 0);

    $ctx = [
        'jogos' => $tot['jogos'], 'gols' => $tot['gols'], 'ast' => $tot['ast'],
        'picoOvr' => $picoOvr, 'picoValor' => $picoValor,
        'clubes' => count($clubes), 'temporadas' => count($temporadas),
        'maiorNoClube' => $porClube ? max($porClube) : 0,
        'continentes' => count($continentes),
        'idadeFinal' => (int)($c['idadeFinal'] ?? 0),
        'comecouAbaixo' => !empty($c['comecouAbaixo']),
        'maiorForcaClube' => (int)($c['maiorForcaClube'] ?? 0),
        // Novos
        't' => $tit, 'coletivos' => $coletivos,
        'individuais' => $individuais, 'trofeus' => $trofeus,
        'paises' => count($paises), 'paisesSA' => count(array_intersect(
            ['BRA','ARG','URU','CHI','COL'], array_keys($paises))),
        'grandesEuropeias' => $grandesVencidas,
        'tripla' => $tripla, 'menorCampeaoCont' => $menorCampeaoCont,
        'subiuComOMesmo' => $subiuComOMesmo,
        'lesoes' => $lesoes, 'idadePico' => $idadePico,
        'maiorSequencia' => $maiorSeq, 'paisesCampeao' => count($paisesCampeao),
        'trocasRival' => $trocasRival,
        'cleanSheets' => $cleanSheets, 'golsSofridos' => $golsSofridos,
        'posicao' => (string)($c['posicao'] ?? ''),
        'pais' => (string)($c['pais'] ?? ''),
    ];
    // A carreira vai pro banco DEPOIS dos totais, porque o hall da fama
    // precisa dos títulos e da pontuação — e os dois só existem aqui.
    $registro = [
        'nome'     => mb_substr(trim((string)($c['nome'] ?? '')), 0, 40) ?: 'Sem nome',
        'numero'   => max(1, min(99, (int)($c['numero'] ?? 10))),
        'posicao'  => mb_substr((string)($c['posicao'] ?? 'MC'), 0, 4),
        'pais'     => mb_substr((string)($c['pais'] ?? ''), 0, 4),
        'pico_ovr' => $picoOvr, 'pico_valor' => $picoValor,
        'jogos'    => $tot['jogos'], 'gols' => $tot['gols'], 'ast' => $tot['ast'],
        'clubes'   => count($clubes), 'temporadas' => count($temporadas),
        'titulos'  => $coletivos,
    ];
    $registro['pontuacao'] = coperoPontuacao($registro);

    if ($idUsuario > 0) {
        coperoGarantirTabela($pdo);
        try {
            $pdo->prepare("INSERT INTO copero_carreiras
                (id_usuario, nome, numero, posicao, pais, pico_ovr, pico_valor,
                 jogos, gols, ast, clubes, temporadas, titulos, pontuacao)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $idUsuario, $registro['nome'], $registro['numero'], $registro['posicao'],
                $registro['pais'], $registro['pico_ovr'], $registro['pico_valor'],
                $registro['jogos'], $registro['gols'], $registro['ast'],
                $registro['clubes'], $registro['temporadas'],
                $registro['titulos'], $registro['pontuacao'],
            ]);
        } catch (Throwable $e) {
            error_log('[copero] gravar: ' . $e->getMessage());
        }
    }

    $ganhas = [];
    foreach (coperoConquistas() as $id => [$icone, $nome, $desc, $nivel, $teste]) {
        if ($teste($ctx)) {
            $ganhas[] = ['id' => $id, 'icone' => $icone, 'nome' => $nome,
                         'desc' => $desc, 'nivel' => $nivel];
        }
    }
    // As mais difíceis primeiro: é o que a pessoa quer ver no topo.
    $peso = ['impossivel' => 0, 'dificil' => 1, 'media' => 2, 'facil' => 3];
    usort($ganhas, fn($a, $b) => ($peso[$a['nivel']] ?? 9) <=> ($peso[$b['nivel']] ?? 9));

    // Paga o que ainda não foi pago. Quem não está logado vê as conquistas do
    // mesmo jeito — só não leva prêmio, porque não há a quem creditar.
    $premio = coperoPagarConquistas($pdo, $idUsuario, $ganhas);
    $novas = array_flip($premio['novas']);
    foreach ($ganhas as &$g) { $g['nova'] = isset($novas[$g['id']]); }
    unset($g);

    echo json_encode(['ok' => true, 'totais' => $tot, 'conquistas' => $ganhas,
                      'totalConquistas' => count(coperoConquistas()),
                      'pontuacao' => $registro['pontuacao'],
                      'premio' => ['moedas' => $premio['moedas'],
                                   'fba_points' => $premio['fba_points'],
                                   'logado' => $idUsuario > 0]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Dados que a tela precisa ──────────────────────────────────────────
$catalogo = [];
foreach (COPERO_CLUBES as [$nome, $liga, $forca, $escudo]) {
    $catalogo[] = ['nome' => $nome, 'liga' => $liga, 'forca' => $forca, 'escudo' => $escudo];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Copero — Simulador de Carreira</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0a0a0c; --panel:#131316; --panel2:#1a1a1f; --panel3:#212127;
  --borda:#26262d; --borda2:#33333c;
  --txt:#f4f4f5; --txt2:#a1a1aa; --txt3:#71717a;
  --verde:#16a34a; --verde-claro:#22c55e; --vermelho:#ef4444;
  --num:'Inter',system-ui,sans-serif;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font-family:'Inter',system-ui,-apple-system,sans-serif;
  font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
#app{max-width:1360px;margin:0 auto;padding:18px 16px 60px}
h1,h2,h3{margin:0;letter-spacing:-.4px}
button{font-family:inherit}

/* ── Topo ───────────────────────────────────────────── */
.topo{display:flex;align-items:center;gap:10px;margin-bottom:18px;flex-wrap:wrap}
.topo .marca{display:flex;align-items:center;gap:9px;font-weight:900;font-size:18px;letter-spacing:-.6px}
.topo .marca i{color:var(--verde-claro)}
.topo .espaco{flex:1}
.topo .voltar{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;
  border:1px solid var(--borda);background:transparent;color:var(--txt2);text-decoration:none;
  flex-shrink:0;transition:.2s}
.topo .voltar:hover{border-color:var(--verde);color:var(--verde-claro);background:rgba(34,197,94,.08)}
.chip-topo{display:flex;align-items:center;gap:5px;padding:5px 11px;border-radius:20px;background:var(--panel2);
  border:1px solid var(--borda);font-size:12px;font-weight:700;color:var(--txt);white-space:nowrap}
.chip-topo b{font-variant-numeric:tabular-nums;font-weight:800}
.chip-topo-btn{cursor:pointer;font-family:inherit;transition:.15s}
.chip-topo-btn:hover{border-color:var(--verde);color:var(--verde-claro)}
.chip-topo-moeda{color:#eab308}
@media (max-width:520px){
  .topo{gap:7px}
  .topo .marca{font-size:15px}
  .chip-topo{padding:4px 9px;font-size:11px}
}
.btn-topo{background:var(--panel2);border:1px solid var(--borda);color:var(--txt2);border-radius:9px;
  padding:8px 13px;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.btn-topo:hover{color:var(--txt);border-color:var(--borda2)}

/* ── Cartão genérico ────────────────────────────────── */
.caixa{background:var(--panel);border:1px solid var(--borda);border-radius:16px}

/* ── Início ─────────────────────────────────────────── */
.inicio{max-width:640px;margin:6vh auto;text-align:center}
.inicio h1{font-size:38px;font-weight:900;margin-bottom:8px}
.inicio p.lead{color:var(--txt2);font-size:15px;margin:0 0 26px}
.modos{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
.modo{background:var(--panel);border:1px solid var(--borda);border-radius:14px;padding:18px;cursor:pointer;
  text-align:left;color:var(--txt)}
.modo:hover{border-color:var(--verde)}
.modo.on{border-color:var(--verde-claro);background:rgba(34,197,94,.07)}
.modo b{display:block;font-size:16px;font-weight:800;margin-bottom:3px}
.modo small{color:var(--txt2);font-size:12px}

/* ── Identidade ─────────────────────────────────────── */
/* A barra de etapa e o botão Continuar só existem no celular — no desktop
   as três colunas cabem lado a lado e ver tudo de uma vez é melhor que
   navegar. Por isso o HTML é sempre o mesmo e quem decide é o CSS. */
.etapa-cab{display:none}
.etapa-so-celular{display:none}
.ident{display:grid;grid-template-columns:1fr 1.15fr 1fr;gap:0}
.ident-col{padding:22px 20px}
.ident-col + .ident-col{border-left:1px solid var(--borda)}
.ident-tit{text-align:center;font-size:15px;font-weight:800;margin-bottom:16px}
.ident-cab{padding:16px 20px;border-bottom:1px solid var(--borda);font-size:21px;font-weight:900;
  display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
.ident-cab .btn{font-size:13px;padding:10px 18px}
@media (max-width:560px){
  .ident-cab{font-size:18px}
  .ident-cab .btn{width:100%}
}
.ident-pe{padding:16px 22px;border-top:1px solid var(--borda);display:flex;justify-content:space-between;gap:12px}

.camisa{position:relative;width:190px;margin:0 auto 16px;aspect-ratio:1}
.camisa svg{width:100%;height:100%;display:block}
.camisa-txt{position:absolute;left:26%;right:26%;top:35%;bottom:12%;display:flex;
  flex-direction:column;align-items:center;justify-content:center;gap:8px;pointer-events:none}
.camisa-nome.vazio{opacity:.42;font-weight:700}
.camisa-nome{max-width:100%;font-size:12.5px;font-weight:800;letter-spacing:1px;color:#fff;
  text-transform:uppercase;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.2}
.camisa-num{font-size:44px;font-weight:900;color:#fff;line-height:1;letter-spacing:-2px}

.campo-rot{font-size:9.5px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--txt3);
  margin-bottom:5px;text-align:center}
.campo-linha{display:flex;gap:10px;margin-bottom:11px}
.campo-linha > div{flex:1}
.inp{width:100%;background:var(--panel2);border:1px solid var(--borda);border-radius:10px;padding:11px 13px;
  color:var(--txt);font-size:14px;font-weight:700;text-align:center;font-family:inherit}
.inp:focus{outline:none;border-color:var(--verde)}
.perna{display:flex;background:var(--panel2);border:1px solid var(--borda);border-radius:10px;padding:3px}
.perna button{flex:1;background:none;border:none;color:var(--txt3);padding:8px;border-radius:8px;
  font-size:13px;font-weight:700;cursor:pointer}
.perna button.on{background:#fff;color:#111}

.busca{position:relative;margin-bottom:12px}
.busca i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--txt3);font-size:14px}
.busca input{width:100%;background:var(--panel2);border:1px solid var(--borda);border-radius:11px;
  padding:11px 13px 11px 36px;color:var(--txt);font-size:13.5px;font-family:inherit}
.busca input:focus{outline:none;border-color:var(--verde)}
.paises{background:var(--panel2);border:1px solid var(--borda);border-radius:12px;padding:8px;
  max-height:330px;overflow-y:auto;display:grid;grid-template-columns:1fr 1fr;gap:2px}
.pais{display:flex;align-items:center;gap:9px;padding:8px 9px;border-radius:8px;cursor:pointer;
  background:none;border:1px solid transparent;color:var(--txt);font-size:13px;font-weight:600;text-align:left}
.pais:hover{background:var(--panel3)}
.pais.on{border-color:var(--verde-claro);background:rgba(34,197,94,.08)}
.pais svg{width:21px;height:14px;border-radius:2px;flex:none;box-shadow:0 0 0 1px rgba(255,255,255,.14)}

.campo{position:relative;background:linear-gradient(180deg,#14532d,#166534 55%,#14532d);
  border:1px solid var(--borda2);border-radius:12px;aspect-ratio:3/4;overflow:hidden}
.campo .risco{position:absolute;background:rgba(255,255,255,.16)}
/* min-height de 32px: com o padding sozinho o botão saía com 25px de altura,
   que no celular é alvo pequeno demais pro dedo. */
.pos{position:absolute;transform:translate(-50%,-50%);background:rgba(0,0,0,.55);color:#fff;
  border:1px solid rgba(255,255,255,.22);border-radius:999px;padding:5px 11px;font-size:11.5px;
  font-weight:800;cursor:pointer;white-space:nowrap;min-height:32px;
  display:inline-flex;align-items:center;justify-content:center}
.pos:hover{background:rgba(0,0,0,.75)}
.pos.on{background:#fff;color:#111;border-color:#fff}

.btn{background:#fff;color:#111;border:none;border-radius:11px;padding:12px 22px;font-size:14px;
  font-weight:800;cursor:pointer}
.btn:disabled{opacity:.35;cursor:default}
.btn2{background:transparent;color:var(--txt2);border:1px solid var(--borda2)}
.btn2:hover:not(:disabled){color:var(--txt)}

/* ── Carreira ───────────────────────────────────────── */
.carreira{display:grid;grid-template-columns:minmax(0,420px) minmax(0,1fr);gap:16px;align-items:start}

.ficha{padding:18px}
/* O cabeçalho é uma linha só: OVR, clube e os números. Posição, número e
   país saem numa FILEIRA PRÓPRIA embaixo — espremidos entre o nome do clube
   e a liga eles encostavam no texto e pareciam sobrepostos. */
.ficha-topo{display:flex;align-items:center;gap:14px;margin-bottom:12px}
.ovr-caixa{width:82px;height:82px;border-radius:14px;display:flex;flex-direction:column;align-items:center;
  justify-content:center;flex:none;color:#0a0a0c}
.ovr-caixa small{font-size:9px;font-weight:800;letter-spacing:1px;opacity:.7}
.ovr-caixa b{font-size:33px;font-weight:900;line-height:1;letter-spacing:-1.5px}
.ficha-info{flex:1 1 90px;min-width:0;display:flex;flex-direction:column;gap:3px}
.ficha-liga{font-size:11.5px;color:var(--txt3);font-weight:600;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ficha-tags{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:14px}
.tag{display:inline-flex;align-items:center;gap:5px;background:var(--panel3);border-radius:6px;
  padding:4px 9px;font-size:11px;font-weight:800;white-space:nowrap}
/* `.pos` é o botão de posição no campo, e ele é `position:absolute` com
   `translate(-50%,-50%)`. Qualquer elemento que use as duas classes juntas
   herda isso e sai FLUTUANDO por cima do resto — era daí que vinha a pílula
   de posição pousada em cima do nome da liga. Se uma tag de posição voltar,
   ela precisa desfazer o posicionamento, e não só trocar a cor. */
.tag.pos{background:#7f1d3a;color:#fff;position:static;transform:none;min-height:0}
.tag.sel{background:#78350f;color:#fde68a}
.tag.idolo{background:#1e3a5f;color:#bfdbfe}
/* A carta do rival tem que gritar: é a única escolha do mercado que custa. */
.carta.rival{border-color:#7f1d3a}
.av-rival{font-style:normal;color:#f472b6;font-weight:800}
.tag svg{width:17px;height:11px;border-radius:2px;flex:none;display:block}
/* Os `min-width:0` não são enfeite: sem eles o nome comprido não encolhe,
   empurra o bloco de idade/valor pra fora e a página inteira ganha barra de
   rolagem lateral no celular. */
.ficha-clube{display:flex;align-items:center;gap:9px;font-size:20px;font-weight:900;letter-spacing:-.5px;
  white-space:nowrap;overflow:hidden;min-width:0}
.ficha-clube span{overflow:hidden;text-overflow:ellipsis;min-width:0}
.ficha-num{flex:none;display:flex;gap:16px;text-align:right;font-size:10px;color:var(--txt3);
  font-weight:700;letter-spacing:.5px}
.ficha-num b{display:block;font-size:18px;color:var(--txt);letter-spacing:-.5px}

.ficha-stats{display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid var(--borda);
  border-bottom:1px solid var(--borda);padding:13px 0;margin-bottom:14px}
.ficha-stats div{text-align:center}
.ficha-stats span{display:block;font-size:9.5px;font-weight:800;letter-spacing:.8px;color:var(--txt3);
  text-transform:uppercase;margin-bottom:3px}
.ficha-stats b{font-size:20px;font-weight:900;letter-spacing:-.5px}

/* ── Evento ─────────────────────────────────────────── */
.evento h3{font-size:19px;font-weight:900;margin-bottom:4px}
.evento p{color:var(--txt2);font-size:12.5px;margin:0 0 14px;line-height:1.5}
/* A linha do ano que acabou de acontecer, e os números entrando nela. */
.ano.entrando{animation:anoEntra .45s ease-out}
@keyframes anoEntra{from{opacity:0;transform:translateY(-7px)}to{opacity:1;transform:none}}
.ano-n.contando{color:var(--txt3)}
.ano-n.pronta{animation:numCrava .3s ease-out}
@keyframes numCrava{0%{transform:scale(1.5);color:#fff}100%{transform:scale(1)}}

/* O escudo do clube como marca d'água atrás da ficha. Fica grande, cortado
   pela borda e quase apagado: dá peso ao clube sem disputar com o texto. */
.ficha-topo{position:relative;overflow:hidden}
.ficha-marca{position:absolute;right:-26px;top:50%;transform:translateY(-50%);
  width:132px;height:132px;opacity:.09;pointer-events:none;filter:grayscale(.2)}
.ficha-marca img,.ficha-marca .mono{width:132px!important;height:132px!important;border-radius:0}
.ficha-topo > *:not(.ficha-marca){position:relative;z-index:1}

/* ── AS OPÇÕES DE UMA DECISÃO ────────────────────────────
   Lado a lado, todas à vista. Já foi carrossel: ficava bonito e cobrava dois
   cliques pra ver o que cabe na tela sem clique nenhum. Quando não cabem —
   celular, ou muitas opções —, o próprio grid empilha, e aí a carta deita
   pra ocupar menos altura. */
.carr{max-width:100%}
.carr-pista{display:grid;grid-template-columns:repeat(auto-fit,minmax(104px,1fr));gap:8px;
  align-items:stretch}
.carr-pista > *{min-width:0}
/* Empilhada, a carta deita: escudo à esquerda e o texto ao lado. */
@media (max-width:560px){
  .carr-pista{grid-template-columns:1fr}
  .carr-pista .clube-op{flex-direction:row;align-items:center;text-align:left}
  .carr-pista .clube-op .txt{flex:1;min-width:0}
  .carr-pista .carta{text-align:left}
}

.cartas{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:9px}
/* Oferta de clube é lista, não grade: o que se compara é o nome e a liga,
   e três cartões grandes em coluna estreita empurravam a linha do tempo
   pra fora da tela. */
.cartas.clubes{grid-template-columns:1fr;gap:7px}
.cartas.clubes .carta{display:flex;align-items:center;gap:11px;text-align:left;padding:9px 11px}
.cartas.clubes .clube-op{flex-direction:row;align-items:center;gap:11px;width:100%}
.cartas.clubes .clube-op .escudo,.cartas.clubes .clube-op .mono{width:34px;height:34px;flex:none}
.cartas.clubes .clube-op .txt{flex:1;min-width:0}
.cartas.clubes .clube-op b{margin:0;font-size:14px;display:block;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.cartas.clubes .clube-op small{display:block;font-size:10.5px}
.carta{background:var(--panel2);border:1px solid var(--borda);border-radius:13px;padding:13px;cursor:pointer;
  text-align:center;color:var(--txt);transition:border-color .12s,opacity .12s}
.carta:hover{border-color:var(--borda2)}
.carta b{display:block;font-size:14.5px;font-weight:800;margin-bottom:9px}
.carta.escolhida{border-color:#fff}
.carta.apagada{opacity:.32}
.efeito{display:flex;align-items:center;justify-content:space-between;gap:8px;border-radius:8px;
  padding:6px 9px;font-size:11.5px;font-weight:700;margin-top:5px}
.efeito.bom{background:rgba(34,197,94,.12);color:#4ade80}
.efeito.ruim{background:rgba(239,68,68,.12);color:#f87171}
.efeito.neutro{background:var(--panel3);color:var(--txt2)}
.efeito .pct{opacity:.75;font-size:10.5px}
.efeito.sorteado{outline:1px solid currentColor}

.clube-op{display:flex;flex-direction:column;align-items:center;gap:7px}
.clube-op .escudo{width:52px;height:52px}
.clube-op small{color:var(--txt3);font-size:10.5px;font-weight:700}

/* ── Escudo / monograma ─────────────────────────────── */
.escudo{border-radius:8px;object-fit:contain;flex:none;background:var(--panel3)}
.mono{display:inline-flex;align-items:center;justify-content:center;border-radius:8px;flex:none;
  font-weight:900;color:#fff;letter-spacing:-.5px}

/* ── Linha do tempo ─────────────────────────────────── */
.linha{padding:12px 14px}
.linha-cab,.ano{display:grid;grid-template-columns:44px minmax(0,1fr) 46px 56px 52px 52px;gap:8px;
  align-items:center}
.linha-cab{font-size:9.5px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--txt3);
  padding:0 8px 7px}
/* O desenho da coluna: herda a cor do texto do cabeçalho, e some no lugar
   onde o rótulo escrito não cabia. */
.cab-ic{display:flex;justify-content:flex-end;align-items:center}
.ic{width:13px;height:13px;fill:currentColor;stroke:currentColor;stroke-width:0;opacity:.9}
.ic .ic-linha{fill:none;stroke:currentColor;stroke-width:1.3;stroke-linecap:round;stroke-linejoin:round}
.ic .ic-rede{fill:none;stroke:currentColor;stroke-width:.6;opacity:.5}
.ic .ic-corte{fill:none;stroke:currentColor;stroke-width:1}
/* O gomo da bola e o vão da rede são pintados da cor do FUNDO: é assim que
   um desenho de 13px mostra um buraco sem precisar de recorte. */
.ic .ic-neg{fill:var(--panel);stroke:var(--panel);stroke-width:1.3;stroke-linejoin:round}
.ic .ic-fundo{fill:var(--panel);stroke:var(--panel);stroke-width:2.6}
.ic .ic-punho{fill:none;stroke:currentColor;stroke-width:1.4;stroke-linecap:round}
.ano{padding:4px 8px;border-radius:8px;font-size:12.5px}
.ano + .ano{margin-top:1px}
.ano.vazio{color:var(--txt3)}
.ano.atual{background:var(--panel3)}
.ano-idade{display:inline-flex;align-items:center;justify-content:center;width:28px;height:22px;
  border-radius:6px;font-size:11.5px;font-weight:800;color:#0a0a0c}
.ano-clube{display:flex;align-items:center;gap:8px;min-width:0}
.ano-clube span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:700}
.selo{font-style:normal;font-size:9.5px;flex:none;line-height:1;font-weight:900}
.selo.sel{color:#fbbf24}
.selo.les{color:#f87171}
.selo.riv{color:#c084fc}
.selo.emp{color:#60a5fa}
.mov{font-style:normal;font-size:10px;flex:none;line-height:1}
.mov.sobe{color:#4ade80}
.mov.cai{color:#f87171}
.ano-ovr{display:inline-flex;align-items:center;justify-content:center;border-radius:6px;padding:2px 0;
  font-size:11.5px;font-weight:800;color:#0a0a0c}
/* Ícone + número na mesma célula, encostados à direita. O número em 800
   porque é o que a linha existe pra mostrar — o resto é moldura. */
.ano-n{display:flex;align-items:center;justify-content:flex-end;gap:4px;
  font-size:12.5px;color:var(--txt2);font-variant-numeric:tabular-nums}
.ano-n b{font-weight:800;color:var(--txt)}
.ano-n .ic{width:11px;height:11px;flex:none;opacity:.75}
.ano-n.contando b{color:var(--txt3)}
/* ── Sala de troféus ────────────────────────────────── */
.sala{padding:16px 18px;margin-bottom:14px}
.sala.vazia{text-align:center;color:var(--txt3);font-size:12px;font-weight:800;
  letter-spacing:1px;text-transform:uppercase;padding:22px}
.sala-cab{display:flex;align-items:center;gap:10px;font-size:9.5px;font-weight:800;letter-spacing:1px;
  color:var(--txt3);text-transform:uppercase;margin-bottom:14px}
.sala-cab b{background:var(--panel3);color:var(--txt);border-radius:6px;padding:2px 8px;font-size:12px}
/* 92px e não 104: com o padding da caixa, 104 só deixava DUAS taças por
   linha numa tela de 375, e a sala ficava uma coluna comprida. */
.sala-grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:14px 10px}
.sala-item{display:flex;flex-direction:column;align-items:center;gap:7px;text-align:center;min-width:0}
.sala-item span{font-size:10.5px;font-weight:700;color:var(--txt2);line-height:1.25}
.sala-taca{position:relative;display:inline-flex}
.sala-taca i{position:absolute;right:-8px;bottom:-2px;background:var(--panel3);border-radius:6px;
  padding:1px 5px;font-size:10px;font-weight:900;font-style:normal;color:var(--txt)}

/* ── Fim ────────────────────────────────────────────── */
.fim{text-align:center;padding:34px 20px}
.fim h2{font-size:26px;font-weight:900;margin-bottom:16px}
.acoes-fim{display:flex;flex-wrap:wrap;gap:9px;justify-content:center}
@media (max-width:480px){.acoes-fim .btn{width:100%}}
.resumo-topo{display:grid;grid-template-columns:1.6fr 1fr;gap:14px;margin-bottom:14px}
/* auto-fill deixava buraco na última linha e a grade parecia torta;
   auto-fit + justify-content centram o que sobra. */
.clubes-grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,150px));
  gap:11px;justify-content:center}
.clube-card{background:var(--panel);border:1px solid var(--borda);border-radius:13px;padding:14px;text-align:center}
.clube-card .escudo,.clube-card .mono{margin:0 auto 9px}
.clube-card b{display:block;font-size:13.5px;font-weight:800;margin-bottom:8px}
.clube-card .cc-nums{display:grid;grid-template-columns:repeat(3,1fr);gap:4px;font-size:11px}
.clube-card .cc-nums span{display:block;font-size:8.5px;color:var(--txt3);font-weight:800;letter-spacing:.5px}

.conq-grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:11px;margin-top:14px}
.conq{display:flex;gap:11px;align-items:flex-start;background:var(--panel);border:1px solid var(--borda);
  border-radius:13px;padding:13px}
.conq .ic{font-size:24px;line-height:1;flex:none}
.conq b{display:block;font-size:13.5px;font-weight:800;margin-bottom:2px}
.conq small{color:var(--txt2);font-size:11.5px;line-height:1.45}
/* A cor do nível diz de longe o que foi difícil de conseguir — sem ela, uma
   conquista impossível some no meio de seis fáceis. */
.conq{position:relative;padding-left:14px}
.conq::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;
  border-radius:4px 0 0 4px;background:var(--n,#3f3f46)}
.conq em{font-style:normal;position:absolute;right:10px;top:9px;font-size:9px;font-weight:800;
  letter-spacing:.8px;text-transform:uppercase;color:var(--n,#71717a);opacity:.9}
/* O nome cede a largura do rótulo. Sem isto o "IMPOSSÍVEL" caía POR CIMA
   dele — o rótulo é absoluto e o nome ocupava a linha inteira. */
.conq b{padding-right:74px}
.conq.n-facil{--n:#4ade80}
.conq.n-media{--n:#60a5fa}
.conq.n-dificil{--n:#c084fc}
.conq.n-impossivel{--n:#fbbf24}
.conq-conta{font-size:11px;font-weight:800;color:var(--txt3);background:var(--panel3);
  border-radius:6px;padding:3px 8px;margin-left:8px;vertical-align:middle}
/* Desafio não feito continua legível: escondê-lo não cria mistério, cria
   uma lista de cadeados que não diz o que fazer. */
.conq.travada{opacity:.5}
.conq.travada .ic{filter:grayscale(1)}
.conq.nova{box-shadow:0 0 0 1px var(--n,#fff) inset}
.conq.nova em{color:var(--n);opacity:1}

/* ── Hall da fama ───────────────────────────────────── */
.hall{margin-top:18px;border:1px solid var(--borda);border-radius:14px;overflow:hidden;text-align:left}
.hall-cab{font-size:9.5px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;
  color:var(--txt3);padding:11px 14px 0}
.hall-topo{display:flex;align-items:center;gap:11px;padding:8px 14px 12px}
.hall-medalha{width:22px;height:22px;border-radius:50%;background:#eab308;color:#0a0a0c;flex:none;
  display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:900}
.hall-nome{flex:1;min-width:0}
.hall-nome b{display:block;font-size:16px;font-weight:900;letter-spacing:-.3px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hall-nome small{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--txt3);font-weight:700}
.hall-nums{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid var(--borda);
  background:var(--panel3)}
.hall-nums div{padding:8px 4px;text-align:center}
.hall-nums span{display:block;font-size:8.5px;font-weight:800;letter-spacing:.5px;
  text-transform:uppercase;color:var(--txt3)}
.hall-nums b{font-size:15px;font-weight:900}
.hall-lista{border-top:1px solid var(--borda)}
.hall-linha{display:flex;align-items:center;gap:9px;padding:7px 14px;font-size:12px}
.hall-linha + .hall-linha{border-top:1px solid var(--borda)}
.hall-pos{width:16px;color:var(--txt3);font-weight:800;font-size:11px;flex:none}
.hall-quem{font-weight:700;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hall-det{display:flex;align-items:center;gap:5px;color:var(--txt3);font-size:11px;flex:none}

/* ── Modal dos desafios ─────────────────────────────── */
.modal-fundo{position:fixed;inset:0;background:rgba(6,6,9,.78);backdrop-filter:blur(3px);
  z-index:70;display:flex;align-items:center;justify-content:center;padding:16px}
.modal-cx{background:var(--panel);border:1px solid var(--borda);border-radius:16px;
  width:min(880px,100%);max-height:88vh;display:flex;flex-direction:column;overflow:hidden}
.modal-cab{display:flex;align-items:center;gap:10px;padding:15px 18px;
  border-bottom:1px solid var(--borda);flex:none}
.modal-cab b{font-size:17px;font-weight:900}
.modal-x{margin-left:auto;background:none;border:none;color:var(--txt3);cursor:pointer;
  font-size:15px;padding:6px 8px;border-radius:8px}
.modal-x:hover{color:var(--txt);background:var(--panel3)}
.modal-corpo{overflow-y:auto;padding:16px 18px 20px}
.d-nivel{display:flex;align-items:center;gap:9px;margin:18px 0 9px}
.d-nivel:first-child{margin-top:2px}
.d-tit{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;
  color:var(--n);border:1px solid var(--n);border-radius:6px;padding:3px 9px}
.d-nivel small{color:var(--txt3);font-size:11px;font-weight:700}
.d-vale{margin-left:auto;color:var(--n);opacity:.85}

/* O prêmio da carreira, no alto das conquistas. */
.premio-fx{display:flex;align-items:center;gap:9px;flex-wrap:wrap;background:var(--panel);
  border:1px solid var(--borda);border-left:3px solid #eab308;border-radius:12px;
  padding:13px 16px;margin-bottom:14px}
.premio-fx b{font-size:13.5px;font-weight:800}
.premio-fx small{width:100%;color:var(--txt3);font-size:11px;font-weight:600}
.premio-tag{font-size:12.5px;font-weight:900;border-radius:7px;padding:4px 10px}
.premio-tag.moeda{background:#78350f;color:#fde68a}
.premio-tag.fba{background:#3b0764;color:#e9d5ff}

.rodape{margin-top:26px;font-size:10.5px;color:var(--txt3);text-align:center;line-height:1.6}

/* ── Vitrine ────────────────────────────────────────── */
.vitrine{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px 0;
  border-top:1px solid var(--borda)}
.vitrine.vazia{color:var(--txt3);font-size:10.5px;font-weight:800;letter-spacing:1px;
  text-transform:uppercase;opacity:.55}
.vitrine.vazia .taca{opacity:.4}
.tacao{position:relative;display:inline-flex;align-items:center}
.tacao b{position:absolute;right:-4px;bottom:-2px;background:var(--panel3);border-radius:6px;
  padding:1px 5px;font-size:10px;font-weight:900}
.taca{filter:drop-shadow(0 2px 4px rgba(0,0,0,.5))}

/* ── Animações ──────────────────────────────────────── */
@keyframes ovrPulso{0%{transform:scale(1)}45%{transform:scale(1.13)}100%{transform:scale(1)}}
@keyframes subiu{0%{opacity:0;transform:translateY(6px)}18%{opacity:1;transform:translateY(-2px)}
  70%{opacity:1;transform:translateY(-14px)}100%{opacity:0;transform:translateY(-26px)}}
@keyframes tacaEntra{0%{opacity:0;transform:scale(.3) rotate(-14deg)}
  60%{opacity:1;transform:scale(1.18) rotate(4deg)}100%{opacity:1;transform:scale(1) rotate(0)}}
@keyframes sorteando{0%,100%{opacity:.35}50%{opacity:1}}
@keyframes revelado{0%{transform:scale(1)}40%{transform:scale(1.08)}100%{transform:scale(1)}}

.ovr-caixa.animando{animation:ovrPulso .8s ease}
/* O delta sobe e some ao lado do OVR: quem estava olhando a carta vê quanto
   mudou sem precisar comparar dois números de cabeça. */
.ovr-delta{position:absolute;left:50%;transform:translateX(-50%);top:-6px;
  font-size:16px;font-weight:900;pointer-events:none;animation:subiu 2.2s ease forwards}
.ovr-delta.mais{color:#4ade80}
.ovr-delta.menos{color:#f87171}
.ovr-caixa{position:relative}

/* Enquanto sorteia, UMA opção fica acesa por vez e as outras recuam — é o
   giro que faz a coisa parecer sorteio. No fim a sorteada fica acesa e a
   outra apaga de vez. */
.carta.sorteando-agora{border-color:#fff}
.carta.sorteando-agora .efeito{opacity:.28;transition:opacity .12s}
.carta.sorteando-agora .efeito.aceso{opacity:1;transform:scale(1.04)}
.efeito.apagado{opacity:.25;filter:grayscale(1)}
.efeito.sorteado{animation:revelado .45s ease;outline:1px solid currentColor}

.taca-fila{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:center;
  gap:22px 30px;max-width:min(92vw,780px);padding:0 16px}
.taca-item{display:flex;flex-direction:column;align-items:center;gap:9px;flex:0 0 auto;
  opacity:0;animation:tacaEntra .8s cubic-bezier(.2,1.5,.4,1) forwards}
.taca-item b{font-size:14px;font-weight:800;text-align:center;max-width:150px;line-height:1.25}
@media (max-width:520px){
  .taca-fila{gap:16px 20px}
  .taca-item b{font-size:12px;max-width:110px}
}

.taca-nova{position:fixed;inset:0;display:flex;flex-direction:column;align-items:center;
  justify-content:center;gap:14px;background:rgba(6,6,9,.86);z-index:60;
  backdrop-filter:blur(3px)}
.taca-nova b{font-size:22px;font-weight:900;letter-spacing:-.5px;text-align:center;padding:0 20px}
.taca-nova small{color:var(--txt2);font-size:12.5px}
@media (prefers-reduced-motion:reduce){
  /* Quem pediu menos movimento vê o resultado, não a viagem até ele. */
  .ovr-caixa.animando,.ovr-delta,.efeito.sorteado,.taca-item{animation:none;opacity:1}
  .ovr-delta{opacity:1}
}

@media (max-width:980px){
  /* Empilhado: a linha do tempo é referência, a ficha é onde se joga —
     então a ficha vem primeiro e a linha desce.

     `minmax(0,1fr)` e não `1fr`: em grid, `1fr` é `minmax(auto,1fr)`, e esse
     `auto` é o min-content — a coluna se recusava a encolher e a ficha com
     nome comprido ("Universidad de Chile") esticava a página pra 491px numa
     tela de 375. Era daí que vinha a rolagem lateral no celular. */
  .carreira{grid-template-columns:minmax(0,1fr)}
  .ident{grid-template-columns:1fr}
  .ident-col + .ident-col{border-left:none;border-top:none}

  /* Uma etapa por vez. O :nth-child casa com a ordem das colunas no HTML:
     1 identidade, 2 nacionalidade, 3 posição. */
  .ident-caixa .ident-col{display:none}
  .ident-caixa[data-etapa="1"] .ident-col:nth-child(1),
  .ident-caixa[data-etapa="2"] .ident-col:nth-child(2),
  .ident-caixa[data-etapa="3"] .ident-col:nth-child(3){display:block}

  /* O título de cada etapa já está na barra; repetir no topo da coluna
     seria a mesma palavra duas vezes na mesma tela. */
  .ident-caixa .ident-tit{display:none}

  .etapa-cab{display:block;padding:14px 20px 0}
  .etapa-tit{font-size:19px;font-weight:900;margin-bottom:10px}
  .etapa-barra{height:5px;border-radius:999px;background:var(--panel3);overflow:hidden}
  .etapa-barra i{display:block;height:100%;background:var(--verde,#22c55e);
    border-radius:999px;transition:width .25s ease}
  .etapa-so-celular{display:inline-flex}

  /* O "Confirmar identidade" do topo sai: no celular ele fica no rodapé,
     do lado da última escolha, e não a cinco rolagens de distância. */
  .ident-cab{display:none}
  .ident-pe{display:flex;gap:10px}
  .ident-pe .btn{flex:1}
  /* As colunas de número encolhem pra dar espaço ao clube. Com as medidas
     antigas sobravam 38px pro nome numa tela de 375, e "Sport Recife" — que
     precisa de 72 — saía cortado quase pela metade. Agora cabe; só nome de
     19 letras pra cima trunca, e aí com reticências, como deve ser. */
  .linha-cab,.ano{grid-template-columns:32px minmax(0,1fr) 34px 32px 32px 32px;gap:5px}
  .ano-n{gap:3px;font-size:12px}
  .ano-n .ic{width:9px;height:9px}
  .ano{padding:4px 6px;font-size:11.5px}
  .linha-cab{padding:0 6px 7px}
  .ano-clube{gap:6px}
  .ano-n{font-size:11.5px}
}
@media (max-width:440px){
  /* Em tela estreita idade e valor empilham: lado a lado eles comiam 100px
     e sobrava quase nada pro nome do clube. */
  .ficha-num{flex-direction:column;gap:2px}
  .ficha-num b{font-size:16px}
  /* 17px e não 20, mais o respiro apertado: junto com o apelido, é o que faz
     190 dos 202 clubes do catálogo caberem inteiros numa tela de 375. */
  .ficha-clube{font-size:17px;gap:7px}
  .ficha-topo{gap:10px}
  .ficha{padding:16px 14px}
  .modos{grid-template-columns:1fr}
  .resumo-topo{grid-template-columns:1fr}
  #app{padding:12px 11px 40px}
}
</style>
</head>
<body>
<div id="app"></div>

<?= cartaoScript() ?>
<script>
const CLUBES    = <?= json_encode($catalogo, JSON_UNESCAPED_UNICODE) ?>;
const LIGAS     = <?= json_encode(COPERO_LIGAS, JSON_UNESCAPED_UNICODE) ?>;
const PAISES    = <?= json_encode(COPERO_PAISES, JSON_UNESCAPED_UNICODE) ?>;
const POSICOES  = <?= json_encode(COPERO_POSICOES, JSON_UNESCAPED_UNICODE) ?>;
const MODOS     = <?= json_encode(COPERO_MODOS, JSON_UNESCAPED_UNICODE) ?>;
const FAIXAS    = <?= json_encode(COPERO_FAIXAS_OVR) ?>;
const IDADE_INI = <?= COPERO_IDADE_INICIAL ?>;
const IDADE_FIM = <?= COPERO_IDADE_FINAL ?>;

const app = () => document.getElementById('app');
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const ri  = (a,b) => Math.floor(Math.random()*(b-a+1))+a;

/* O estado inteiro da carreira. Salvo no localStorage a cada passo pra
   fechar a aba não custar uma carreira de 24 anos. */
let S = null;
const CHAVE = 'copero:carreira';
const salvar  = () => { try { localStorage.setItem(CHAVE, JSON.stringify(S)); } catch(e){} };
const carregar= () => { try { return JSON.parse(localStorage.getItem(CHAVE)||'null'); } catch(e){ return null; } };
const apagar  = () => { try { localStorage.removeItem(CHAVE); } catch(e){} };

/* ── O que sobrevive à carreira ──────────────────────────────────────
   A carreira acaba, mas as conquistas e o nome ficam. É o que dá motivo pra
   começar a próxima: a lista de desafios é de LONGO prazo, atravessa
   partidas, e sem isso as conquistas apareciam uma vez na tela final e
   sumiam pra sempre. */
const CHAVE_CONQ = 'copero:conquistas';
const CHAVE_NOME = 'copero:nome';

const conquistasFeitas = () => {
  try { const v = JSON.parse(localStorage.getItem(CHAVE_CONQ) || '[]'); return Array.isArray(v) ? v : []; }
  catch(e){ return []; }
};
const guardarConquistas = (ids) => {
  try {
    const tudo = new Set(conquistasFeitas());
    (ids || []).forEach(id => tudo.add(id));
    localStorage.setItem(CHAVE_CONQ, JSON.stringify([...tudo]));
  } catch(e){}
};
const ultimoNome = () => { try { return localStorage.getItem(CHAVE_NOME) || ''; } catch(e){ return ''; } };
const guardarNome = (n) => { try { if (n) localStorage.setItem(CHAVE_NOME, n); } catch(e){} };

/* ── Bandeiras ──────────────────────────────────────────
   Desenhadas aqui, e não em emoji: emoji de bandeira só aparece onde a
   fonte do sistema tem, e no Chrome do Windows vira o par de letras. */
const BAND = {
  BRA:`<rect width="30" height="20" fill="#009b3a"/><path d="M15 2.4 27.6 10 15 17.6 2.4 10Z" fill="#fedf00"/><circle cx="15" cy="10" r="4.3" fill="#002776"/>`,
  ARG:`<rect width="30" height="20" fill="#74acdf"/><rect y="6.67" width="30" height="6.67" fill="#fff"/><circle cx="15" cy="10" r="2.5" fill="#f6b40e"/>`,
  URU:`<rect width="30" height="20" fill="#fff"/><rect y="2.2" width="30" height="2.2" fill="#0038a8"/><rect y="6.6" width="30" height="2.2" fill="#0038a8"/><rect y="11" width="30" height="2.2" fill="#0038a8"/><rect y="15.4" width="30" height="2.2" fill="#0038a8"/><rect width="11" height="11" fill="#fff"/><circle cx="5.5" cy="5.5" r="2.6" fill="#f6b40e"/>`,
  CHI:`<rect width="30" height="20" fill="#fff"/><rect y="10" width="30" height="10" fill="#d52b1e"/><rect width="10" height="10" fill="#0039a6"/><path d="M5 2.2l.8 2.4h2.5l-2 1.5.8 2.4-2.1-1.5-2.1 1.5.8-2.4-2-1.5h2.5z" fill="#fff"/>`,
  COL:`<rect width="30" height="20" fill="#fcd116"/><rect y="10" width="30" height="5" fill="#003893"/><rect y="15" width="30" height="5" fill="#ce1126"/>`,
  ENG:`<rect width="30" height="20" fill="#fff"/><rect x="12.5" width="5" height="20" fill="#ce1124"/><rect y="7.5" width="30" height="5" fill="#ce1124"/>`,
  ESP:`<rect width="30" height="20" fill="#c60b1e"/><rect y="5" width="30" height="10" fill="#ffc400"/>`,
  ITA:`<rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#009246"/><rect x="20" width="10" height="20" fill="#ce2b37"/>`,
  GER:`<rect width="30" height="20" fill="#000"/><rect y="6.67" width="30" height="6.67" fill="#dd0000"/><rect y="13.33" width="30" height="6.67" fill="#ffce00"/>`,
  FRA:`<rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#002395"/><rect x="20" width="10" height="20" fill="#ed2939"/>`,
  POR:`<rect width="30" height="20" fill="#da291c"/><rect width="12" height="20" fill="#046a38"/><circle cx="12" cy="10" r="4" fill="#ffe900"/>`,
  NED:`<rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#ae1c28"/><rect y="13.33" width="30" height="6.67" fill="#21468b"/>`,
  BEL:`<rect width="30" height="20" fill="#fdda24"/><rect width="10" height="20" fill="#000"/><rect x="20" width="10" height="20" fill="#ef3340"/>`,
  CRO:`<rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#ff0000"/><rect y="13.33" width="30" height="6.67" fill="#171796"/>`,
  TUR:`<rect width="30" height="20" fill="#e30a17"/><circle cx="12" cy="10" r="5" fill="#fff"/><circle cx="13.6" cy="10" r="4" fill="#e30a17"/><path d="M18.2 10l3.6-1.2-2.2 3 0-3.7 2.2 3z" fill="#fff"/>`,
  RUS:`<rect width="30" height="20" fill="#fff"/><rect y="6.67" width="30" height="6.67" fill="#0039a6"/><rect y="13.33" width="30" height="6.67" fill="#d52b1e"/>`,
  SCO:`<rect width="30" height="20" fill="#0065bf"/><path d="M0 0l30 20M30 0L0 20" stroke="#fff" stroke-width="3.4"/>`,
  GRE:`<rect width="30" height="20" fill="#fff"/><rect width="30" height="2.22" fill="#0d5eaf"/><rect y="4.44" width="30" height="2.22" fill="#0d5eaf"/><rect y="8.89" width="30" height="2.22" fill="#0d5eaf"/><rect y="13.33" width="30" height="2.22" fill="#0d5eaf"/><rect y="17.78" width="30" height="2.22" fill="#0d5eaf"/><rect width="11.1" height="11.1" fill="#0d5eaf"/><path d="M0 5.55h11.1M5.55 0v11.1" stroke="#fff" stroke-width="2.1"/>`,
  USA:`<rect width="30" height="20" fill="#b22234"/><rect y="2.86" width="30" height="2.86" fill="#fff"/><rect y="8.57" width="30" height="2.86" fill="#fff"/><rect y="14.29" width="30" height="2.86" fill="#fff"/><rect width="12.6" height="11.4" fill="#3c3b6e"/>`,
  MEX:`<rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#006847"/><rect x="20" width="10" height="20" fill="#ce1126"/>`,
  KSA:`<rect width="30" height="20" fill="#006c35"/><rect x="5" y="13.5" width="20" height="1.6" fill="#fff"/><rect x="7" y="6" width="16" height="1.4" fill="#fff"/>`,
  JPN:`<rect width="30" height="20" fill="#fff"/><circle cx="15" cy="10" r="5.4" fill="#bc002d"/>`,
  KOR:`<rect width="30" height="20" fill="#fff"/><circle cx="15" cy="10" r="4.6" fill="#cd2e3a"/><path d="M10.4 10a4.6 4.6 0 0 1 9.2 0 2.3 2.3 0 0 0-4.6 0 2.3 2.3 0 0 1-4.6 0z" fill="#0047a0"/>`,
  EGY:`<rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#ce1126"/><rect y="13.33" width="30" height="6.67" fill="#000"/>`,
  MAR:`<rect width="30" height="20" fill="#c1272d"/><path d="M15 6.6l1.5 4.5h4.7l-3.8 2.8 1.4 4.5-3.8-2.8-3.8 2.8 1.4-4.5-3.8-2.8h4.7z" fill="none" stroke="#006233" stroke-width="1"/>`,
  RSA:`<rect width="30" height="20" fill="#002395"/><rect width="30" height="10" fill="#de3831"/><path d="M0 0l12 10L0 20z" fill="#007a4d"/><rect y="8.6" width="30" height="2.8" fill="#fff"/>`,
  AUS:`<rect width="30" height="20" fill="#012169"/><path d="M0 0l15 10M15 0L0 10" stroke="#fff" stroke-width="1.7"/><path d="M7.5 0v10M0 5h15" stroke="#fff" stroke-width="2.8"/><path d="M7.5 0v10M0 5h15" stroke="#e4002b" stroke-width="1.5"/><circle cx="7.5" cy="15.5" r="1.3" fill="#fff"/><circle cx="22" cy="6" r="1" fill="#fff"/><circle cx="25" cy="11" r="1" fill="#fff"/><circle cx="21" cy="13" r="1" fill="#fff"/>`,
  NGA:`<rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#008751"/><rect x="20" width="10" height="20" fill="#008751"/>`,
  SEN:`<rect width="30" height="20" fill="#fdef42"/><rect width="10" height="20" fill="#00853f"/><rect x="20" width="10" height="20" fill="#e31b23"/><path d="M15 7l1 3h3.2l-2.6 2 1 3-2.6-1.9-2.6 1.9 1-3-2.6-2H14z" fill="#00853f"/>`,
  CIV:`<rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#f77f00"/><rect x="20" width="10" height="20" fill="#009e60"/>`,
};
const bandeira = (iso, w) => BAND[iso]
  ? `<svg viewBox="0 0 30 20" width="${w||21}" role="img" aria-label="${esc(iso)}">${BAND[iso]}</svg>`
  : `<span style="font-size:10px;font-weight:800;color:var(--txt3)">${esc(iso)}</span>`;

/* ── Escudos ────────────────────────────────────────────
   Clube sem URL vira monograma. É o caso de todos hoje: o catálogo nasceu
   sem as URLs, e a tela não pode ficar com buraco por causa disso. */
const CORES_MONO = ['#1e3a8a','#7f1d1d','#14532d','#78350f','#4c1d95','#0f766e','#831843','#3f3f46'];
function monograma(nome, tam){
  let h = 0;
  for (const c of String(nome)) h = (h * 31 + c.charCodeAt(0)) >>> 0;
  const cor = CORES_MONO[h % CORES_MONO.length];
  const ini = String(nome).split(/\s+/).slice(0,2).map(p=>p[0]||'').join('').toUpperCase();
  return `<span class="mono" style="width:${tam}px;height:${tam}px;background:${cor};font-size:${Math.round(tam*.4)}px">${esc(ini)}</span>`;
}
function escudo(clube, tam){
  const t = tam || 22;
  if (!clube) return monograma('?', t);
  if (!clube.escudo) return monograma(clube.nome, t);
  return `<img class="escudo" src="${esc(clube.escudo)}" alt="${esc(clube.nome)}" style="width:${t}px;height:${t}px"
     onerror="this.outerHTML=this.dataset.reserva" data-reserva="${esc(monograma(clube.nome,t))}">`;
}

/**
 * A cor do clube, pra pintar a faixa de idade da linha do tempo.
 *
 * A pílula repetia a cor do OVR, que já está na coluna ao lado — dois
 * lugares dizendo a mesma coisa. Com a cor do clube, a coluna da esquerda
 * vira a trajetória lida de relance: seis anos de azul, quatro de vermelho,
 * e dá pra ver quando a carreira mudou de casa sem ler nome nenhum.
 */
function corDoClube(nome){
  let h = 0;
  for (const c of String(nome || '?')) h = (h * 31 + c.charCodeAt(0)) >>> 0;
  return CORES_MONO[h % CORES_MONO.length];
}

/**
 * O apelido do clube, pra onde a coluna é estreita.
 *
 * Só os dezessete nomes que não cabem na linha do tempo do celular. É o que
 * qualquer app de futebol faz: na tabela é "Dortmund", no cabeçalho é
 * "Borussia Dortmund". Nome que não está aqui aparece inteiro.
 */
const APELIDO = {
  'Bayern de Munique':'Bayern',        'Borussia Dortmund':'Dortmund',
  "Borussia M'gladbach":'M’gladbach', 'Eintracht Frankfurt':'Eintracht',
  'Manchester United':'Man. United',   'Manchester City':'Man. City',
  'Atlético de Madrid':'Atl. Madrid',  'Atlético Nacional':'Atl. Nacional',
  'Universidad de Chile':'U. de Chile','Vitória de Guimarães':'V. Guimarães',
  'Bayer Leverkusen':'Leverkusen',     'Sheffield United':'Sheffield Utd',
  'Racing Santander':'Racing Sant.',   'Barracas Central':'Barracas',
  'Kawasaki Frontale':'Kawasaki',      'Mamelodi Sundowns':'Sundowns',
  'Melbourne Victory':'Melbourne',     'Seattle Sounders':'Seattle',
};
const nomeCurto = n => APELIDO[n] || n;

/**
 * O boletim da posição: `[rótulo, chave]` das duas colunas que acompanham os
 * jogos.
 *
 * Gol e assistência não dizem nada sobre um goleiro — a temporada dele se
 * mede em gols sofridos e jogos sem sofrer. Como as três telas e o cartão
 * mostram as mesmas colunas, elas saem daqui e não de cada lugar.
 */
/**
 * Aquele clube é rival do seu?
 *
 * O índice é montado uma vez: são quarenta e poucos pares, e a pergunta é
 * feita em toda carta de oferta.
 */
// O índice é montado na PRIMEIRA pergunta, e não no carregamento: `RIVAIS` é
// declarado bem mais abaixo, e um `const` lido antes da própria declaração
// estoura em ReferenceError — o que derrubava o script inteiro, não só isto.
let _rivaisDe = null;
function ehRival(a, b){
  if (!a || !b) return false;
  if (!_rivaisDe) {
    _rivaisDe = {};
    RIVAIS.forEach(([x, y]) => {
      (_rivaisDe[x] = _rivaisDe[x] || []).push(y);
      (_rivaisDe[y] = _rivaisDe[y] || []).push(x);
    });
  }
  return (_rivaisDe[a] || []).includes(b);
}

const ehGoleiro = () => S && S.posicao === 'GOL';

/**
 * Os desenhinhos das colunas do boletim.
 *
 * A coluna é estreita e o rótulo escrito ("Gols", "Ast") ocupa mais espaço
 * que o número que ele nomeia. O desenho diz a mesma coisa em 13px e não
 * depende da fonte de emoji do aparelho — que muda de um telefone pro outro
 * e às vezes nem existe.
 */
const ICONES = {
  jogos: '<svg viewBox="0 0 16 16" class="ic"><path d="M2.2 13V4.4h11.6V13" class="ic-linha"/><path d="M1.2 13h13.6" class="ic-linha"/><path d="M5.1 4.4V13M8 4.4V13M10.9 4.4V13M2.2 7.3h11.6M2.2 10.2h11.6" class="ic-rede"/></svg>',
  gols: '<svg viewBox="0 0 16 16" class="ic"><circle cx="8" cy="8" r="6.4"/><path d="M8 4.2 10.7 6.2 9.7 9.4H6.3L5.3 6.2z" class="ic-neg"/><path d="M8 1.6v2.6M2.2 6l3.1 1.1M13.8 6l-3.1 1.1M4.6 13.3l1.7-3.9M11.4 13.3l-1.7-3.9" class="ic-corte"/></svg>',
  ast: '<svg viewBox="0 0 16 16" class="ic"><path d="M2.2 11.2c2.7.8 5.1 0 7-1.7" class="ic-linha" fill="none"/><path d="M9.3 5.9 13.6 8.9 8.8 11.6z"/><circle cx="3" cy="12.7" r="1.7"/></svg>',
  gs: '<svg viewBox="0 0 16 16" class="ic"><path d="M1.6 12.8V4.6h12.8v8.2" class="ic-linha"/><path d="M4.4 4.6v8.2M8 4.6v8.2M11.6 4.6v8.2M1.6 7.3h12.8M1.6 10h12.8" class="ic-rede"/><circle cx="10.6" cy="9.6" r="2.6" class="ic-fundo"/><circle cx="10.6" cy="9.6" r="2.6"/></svg>',
  cs: '<svg viewBox="0 0 16 16" class="ic"><path d="M4 14.2V8.1c0-.7.5-1.2 1.1-1.2.6 0 1.1.5 1.1 1.2V4.9c0-.7.5-1.2 1.1-1.2.6 0 1.1.5 1.1 1.2v2.6c0-.7.5-1.2 1.1-1.2.6 0 1.1.5 1.1 1.2v2.1c.5-.7 1.4-.8 1.9-.2.4.5.3 1.3-.2 1.9l-2 2.9z"/><path d="M3.6 14.2h8.2" class="ic-punho"/></svg>',
};
const COLUNAS_GOL   = [['GS','gs'], ['CS','cs']];
const COLUNAS_LINHA = [['Gols','gols'], ['Ast','ast']];
const colunasDoBoletim = () => ehGoleiro() ? COLUNAS_GOL : COLUNAS_LINHA;

const corDoOvr = o => (FAIXAS.find(([min]) => o >= min) || FAIXAS[FAIXAS.length-1])[2];
const acharClube = nome => CLUBES.find(c => c.nome === nome);
const dadosLiga  = id => LIGAS[id]
  ? {pais:LIGAS[id][0], cont:LIGAS[id][1], nome:LIGAS[id][2], nivel:LIGAS[id][3], media:LIGAS[id][4]}
  : null;

function moeda(v){
  if (v >= 1000000) return '€' + (v/1000000).toFixed(v >= 10000000 ? 0 : 1).replace('.', ',') + 'M';
  return '€' + Math.round(v/1000) + 'K';
}

/* ── Animações ──────────────────────────────────────────
   Elas não decoram: cada uma responde uma pergunta que o jogador faz na
   hora. "Quanto mudou?" (o delta subindo), "o que saiu?" (o sorteio) e
   "ganhei alguma coisa?" (a taça entrando). */

const dormir = ms => new Promise(r => setTimeout(r, ms));
const semAnimacao = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Mostra o OVR contando até o novo valor, com o delta subindo ao lado. */
async function animarOvr(de, para){
  const caixa = document.querySelector('.ovr-caixa');
  if (!caixa || de === para) return;
  const num = caixa.querySelector('b');
  const d = para - de;

  const delta = document.createElement('span');
  delta.className = 'ovr-delta ' + (d > 0 ? 'mais' : 'menos');
  delta.textContent = (d > 0 ? '+' : '') + d;
  caixa.appendChild(delta);
  caixa.classList.add('animando');

  if (semAnimacao()) { num.textContent = para; }
  else {
    // O número corre rápido e FREIA nos últimos: é a diferença entre um
    // contador e um resultado. Com passo fixo, chegar em 78 tinha o mesmo
    // peso de passar por 74 — e o que interessa é onde ele para.
    const passos = Math.min(Math.abs(d), 12);
    for (let i = 1; i <= passos; i++) {
      num.textContent = Math.round(de + (d * i / passos));
      caixa.style.background = corDoOvr(Number(num.textContent));
      await dormir(80 + Math.round(340 * Math.pow(i / passos, 3)));
    }
    num.textContent = para;
  }
  caixa.style.background = corDoOvr(para);
  setTimeout(() => { delta.remove(); caixa.classList.remove('animando'); }, 2200);
}

/**
 * O sorteio da carta: os efeitos piscam e um deles "para" no resultado.
 *
 * Sem isso o resultado aparecia pronto, e a aposta que a pessoa acabou de
 * fazer não tinha momento nenhum — o número já estava lá antes de ela olhar.
 */
async function animarSorteio(cartaEl, efeitoSorteado){
  const efeitos = [...cartaEl.querySelectorAll('.efeito')];
  if (efeitos.length < 2 || semAnimacao()) return;

  // Uma opção acesa por vez, girando entre elas e desacelerando — é o que
  // faz parecer sorteio. Todas piscando juntas não escolhe nada.
  cartaEl.classList.add('sorteando-agora');
  for (let i = 0, espera = 85; i < 11; i++, espera += 42) {
    efeitos.forEach((e, k) => e.classList.toggle('aceso', k === (i % efeitos.length)));
    await dormir(espera);
  }

  // Para no que saiu: ele fica aceso, os outros apagam de vez.
  const alvo = efeitos.find(e => e.textContent.includes(efeitoSorteado.texto));
  efeitos.forEach(e => { e.classList.toggle('aceso', e === alvo); e.classList.toggle('apagado', e !== alvo); });
  if (alvo) alvo.classList.add('sorteado');
  await dormir(550);
  cartaEl.classList.remove('sorteando-agora');
}

/**
 * As taças do ano, TODAS na mesma tela.
 *
 * Uma de cada vez com clique entre elas transformava um ano bom em quatro
 * interrupções — e ganhar tudo virava castigo. Aparecem juntas, entram
 * escalonadas pra dar o efeito de coleção, e somem sozinhas.
 */
async function mostrarTacas(t){
  const ids = t.titulos || [];
  if (!ids.length) return;

  const tela = document.createElement('div');
  tela.className = 'taca-nova';
  tela.innerHTML = `<div class="taca-fila">${ids.map((id, k) => `
      <span class="taca-item" style="animation-delay:${k * 220}ms">
        ${taca(id, ids.length > 2 ? 84 : 116)}
        <b>${esc(nomeDaTaca(id, t.liga))}</b>
      </span>`).join('')}</div>`;
  document.body.appendChild(tela);

  // Some sozinha; o clique só serve pra quem quiser pular.
  await new Promise(r => {
    const fim = () => { tela.remove(); r(); };
    tela.addEventListener('click', fim, {once:true});
    setTimeout(fim, semAnimacao() ? 900 : 1600 + ids.length * 700);
  });
}

/* ── Títulos ────────────────────────────────────────────
   Quem ganha o quê depende da força do clube contra a liga em que ele joga,
   e o jogador entra como tempero: um craque num time bom empurra o título,
   mas ninguém ganha Champions sozinho jogando na segunda divisão. */
window.__MOEDAS__ = <?= $moedasUsuario === null ? 'null' : $moedasUsuario ?>;
const TACAS = <?= json_encode(COPERO_TACAS, JSON_UNESCAPED_UNICODE) ?>;
const COMPETICOES = <?= json_encode(COPERO_COMPETICOES, JSON_UNESCAPED_UNICODE) ?>;
const CONTINENTAL = <?= json_encode(COPERO_CONTINENTAL, JSON_UNESCAPED_UNICODE) ?>;
const CONT2       = <?= json_encode(COPERO_CONTINENTAL2, JSON_UNESCAPED_UNICODE) ?>;
const CONT3       = <?= json_encode(COPERO_CONTINENTAL3, JSON_UNESCAPED_UNICODE) ?>;
const SUPERNAC    = <?= json_encode(COPERO_SUPERNAC, JSON_UNESCAPED_UNICODE) ?>;
const SUPERCONT   = <?= json_encode(COPERO_SUPERCONT, JSON_UNESCAPED_UNICODE) ?>;
const RIVAIS      = <?= json_encode(COPERO_RIVAIS, JSON_UNESCAPED_UNICODE) ?>;
/* O que cada nível paga, já em texto — a régua sai do servidor, e não de um
   número escrito à mão na tela que um dia diverge do que é pago de verdade. */
const PREMIO = <?= json_encode(array_map(
    fn($p) => isset($p['fba_points']) ? $p['fba_points'] . ' FBA Points' : $p['moedas'] . ' moedas',
    COPERO_PREMIO), JSON_UNESCAPED_UNICODE) ?>;
const COPAS       = <?= json_encode(COPERO_COPAS, JSON_UNESCAPED_UNICODE) ?>;
const SELECOES    = <?= json_encode(COPERO_SELECOES, JSON_UNESCAPED_UNICODE) ?>;
/* A lista INTEIRA das conquistas, e não só as ganhas: é o que deixa a tela
   inicial mostrar o que ainda falta. O teste continua rodando só no servidor —
   daqui vai apenas o texto. */
const CONQUISTAS  = <?= json_encode(array_map(
    fn($x) => ['icone' => $x[0], 'nome' => $x[1], 'desc' => $x[2], 'nivel' => $x[3]],
    coperoConquistas()), JSON_UNESCAPED_UNICODE) ?>;
const SEL_CONT    = <?= json_encode(COPERO_SELECAO_CONT, JSON_UNESCAPED_UNICODE) ?>;

/**
 * A barra de cima, igual em todas as telas.
 *
 * Existe por dois motivos práticos: sair do jogo sem o botão do navegador, e
 * ver as moedas da conta sem sair. Quem não está logado não vê a ficha de
 * moeda — mostrar zero pra quem não tem conta seria mentira.
 *
 * `extra` é o que cada tela quer no canto direito antes das fichas (o
 * "Abandonar" da carreira em andamento, por exemplo).
 */
function barraTopo(extra){
  const feitas = conquistasFeitas().length;
  return `<div class="topo">
    <a href="/games.php" class="voltar" title="Voltar aos jogos">
      <svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M8.5 3.5 4 8l4.5 4.5.9-.9L6.3 8.6H12v-1.2H6.3l3.1-3z"/></svg></a>
    <div class="marca"><i class="bi bi-trophy-fill"></i> Copero</div>
    <div class="espaco"></div>
    ${extra || ''}
    <button class="chip-topo chip-topo-btn" onclick="abrirDesafios()" title="Desafios">
      🏆 <b>${feitas}</b></button>
    ${window.__MOEDAS__ === null || window.__MOEDAS__ === undefined ? ''
      : `<div class="chip-topo chip-topo-moeda" title="Suas moedas">🪙 <b>${window.__MOEDAS__}</b></div>`}
  </div>`;
}

/** A taça desenhada, do tamanho pedido. */
function taca(id, tam){
  const t = TACAS[id];
  if (!t) return '';
  return `<svg class="taca" viewBox="0 0 64 60" width="${tam}" height="${tam}"
    style="color:${t[0]}" role="img" aria-label="${esc(id)}">${t[1]}</svg>`;
}

/** O nome que a taça leva na tela, já com o continente certo. */
/**
 * O nome do troféu, com o nome de verdade do torneio quando ele existe.
 *
 * "Campeonato Nacional" não diz nada; Bundesliga, LaLiga e DFB-Pokal dizem.
 * O campeonato pega o nome da própria liga — que já traz a divisão junto,
 * então quem foi campeão da Série B vê Série B — e a copa vem do país.
 */
function nomeDaTaca(id, ligaId){
  const l = dadosLiga(ligaId);
  // Os de seleção saem do PAÍS da pessoa, e não da liga onde ela joga: um
  // brasileiro no Bayern ganha a Copa América, não a Eurocopa.
  if (id === 'copa_mundo')   return 'Copa do Mundo';
  if (id === 'selecao_cont') return SEL_CONT[contDoPais(S ? S.pais : 'BRA')] || 'Torneio de Seleções';
  if (id === 'cont')  return (l && CONTINENTAL[l.cont]) || 'Torneio Continental';
  if (id === 'cont2') return (l && CONT2[l.cont]) || 'Segunda Continental';
  if (id === 'cont3') return (l && CONT3[l.cont]) || 'Terceira Continental';
  if (id === 'supernac')  return (l && SUPERNAC[l.pais]) || 'Supercopa Nacional';
  if (id === 'supercont') return (l && SUPERCONT[l.cont]) || 'Supercopa Continental';
  if (id === 'liga')  return (l && l.nome) || 'Campeonato Nacional';
  if (id === 'copa')  return (l && COPAS[l.pais]) || 'Copa Nacional';
  if (COMPETICOES[id]) return COMPETICOES[id][0];
  const premio = { artilheiro: 'Artilheiro da Liga', chuteira: 'Chuteira de Ouro',
                   bola_ouro: 'Bola de Ouro', rei_america: 'Rei da América',
                   luva_ouro: 'Luva de Ouro' };
  if (premio[id]) return premio[id];
  return id;
}

/**
 * O que a temporada rendeu de troféu.
 *
 * A liga nacional é a mais alcançável — basta ser o melhor do seu país. A
 * continental exige clube grande de verdade, e o mundial exige ter ganho a
 * continental. Sem esse encadeamento o jogador colecionaria mundiais jogando
 * na Série C.
 */
/**
 * O peso de um clube numa disputa.
 *
 * O expoente é o que transforma diferença de força em diferença de título.
 * Com ele alto, Peñarol e Nacional levam quase tudo no Uruguai e o quarto
 * colocado quase nunca aparece — que é como o futebol funciona de verdade.
 */
const COPERO_EXPOENTE = 31;
const pesoClube = f => Math.pow(Math.max(35, f) / 100, COPERO_EXPOENTE);

/**
 * Os adversários de verdade em cada torneio.
 *
 * O catálogo tem só os principais clubes de cada liga, então o resto do
 * campeonato entra como times VIRTUAIS, um pouco mais fracos que o pior
 * catalogado — sem eles a liga pareceria ter três times e o título ficaria
 * fácil demais.
 */
const _cacheAdv = {};
function adversarios(clube, comp){
  const l = dadosLiga(clube.liga);
  // O resultado só depende da liga e do torneio, e o catálogo não muda no
  // meio da partida. Sem o cache isto varria os 202 clubes quatro vezes por
  // temporada — quase cem varreduras numa carreira.
  const chave = clube.liga + '|' + comp + (comp === 'mundial' ? '|' + clube.nome : '');
  if (_cacheAdv[chave] !== undefined) return _cacheAdv[chave];
  let lista;

  if (comp === 'liga') {
    lista = CLUBES.filter(c => c.liga === clube.liga);
  } else if (comp === 'copa') {
    // A copa é do PAÍS inteiro: entra gente de todas as divisões, e é por
    // isso que time pequeno às vezes leva.
    lista = CLUBES.filter(c => { const d = dadosLiga(c.liga); return d && d.pais === l.pais; });
  } else if (comp === 'cont' || comp === 'cont2' || comp === 'cont3') {
    // Os três torneios continentais partem do mesmo conjunto — primeira
    // divisão do continente — mas cada um pega uma FATIA dele. É assim que
    // funciona de verdade: os grandes na Champions, os do meio na Europa
    // League, os de baixo na Conference. Um clube só disputa a sua.
    const doCont = CLUBES
      .filter(c => { const d = dadosLiga(c.liga); return d && d.cont === l.cont && d.nivel === 1; })
      .sort((a, b) => b.forca - a.forca);
    const n1 = Math.max(1, Math.round(doCont.length * 0.30));
    const n2 = Math.max(1, Math.round(doCont.length * 0.62));
    lista = comp === 'cont'  ? doCont.slice(0, n1)
          : comp === 'cont2' ? doCont.slice(n1, n2)
          : doCont.slice(n2);
  } else {
    // Mundial: um campeão por continente, o mais forte de cada um. O
    // sul-americano entra, mas encara o gigante europeu.
    const porCont = {};
    CLUBES.forEach(c => { const d = dadosLiga(c.liga);
      if (d && d.nivel === 1 && (!porCont[d.cont] || porCont[d.cont].forca < c.forca)) porCont[d.cont] = c; });
    lista = Object.values(porCont);
    if (!lista.some(c => c.nome === clube.nome)) lista = lista.concat([clube]);
  }

  let soma = lista.reduce((s, c) => s + pesoClube(c.forca), 0);
  let n = lista.length;

  // Os times que o catálogo não tem, só pra liga e pra copa.
  if (comp === 'liga' || comp === 'copa') {
    const menor = lista.reduce((m, c) => Math.min(m, c.forca), 99);
    const faltam = Math.max(0, (comp === 'liga' ? 18 : 40) - lista.length);
    soma += faltam * pesoClube(menor - 6);
    n += faltam;
  }
  // A CONTAGEM volta junto porque a fatia de zebra é dividida por ela. Com
  // um número fixo, a Champions dava 0,9% de piso a cada um dos sessenta
  // clubes europeus — 55% do torneio decidido no sorteio, e o título virava
  // rifa em vez de competição.
  const r = {soma, n: Math.max(2, n)};
  _cacheAdv[chave] = r;
  return r;
}

/**
 * O continente do seu país — a seleção joga pelo país, não pelo clube onde
 * você está. Um brasileiro no Bayern disputa a Copa América, não a Eurocopa.
 */
/** A força da seleção do país, ou 0 se ele não tem seleção no jogo. */
const forcaSelecao = pais => (SELECOES[pais] || [0])[0];
/** O continente que a SELEÇÃO disputa — a Austrália joga na Ásia. */
const contDoPais   = pais => (SELECOES[pais] || [0,'EUR'])[1];

/**
 * Você foi convocado?
 *
 * Não simulo os outros jogadores do país, então a régua é a força da própria
 * seleção: pra vestir a camisa da Argentina você precisa ser bem melhor do
 * que pra vestir a da Austrália. É o que faz nascer num país forte ser uma
 * escolha com dois lados — mais chance de título, mais dificuldade de entrar.
 */
function convocado(ovr, pais){
  const f = forcaSelecao(pais);
  return f > 0 && ovr >= f - 8;
}

/**
 * O que a seleção rendeu no ano.
 *
 * A Copa do Mundo vem de quatro em quatro anos e o torneio continental cai
 * no meio do caminho — é isso que faz "estar no auge no ano certo" virar
 * sorte de verdade, e não mais um sorteio anual.
 */
function titulosDaSelecao(ovr, ano){
  const pais = S.pais, f = forcaSelecao(pais);
  if (!f || !convocado(ovr, pais)) return [];

  const cont = contDoPais(pais);
  const ganhos = [];
  const peso = x => Math.pow(Math.max(35, x) / 100, 18);

  // O jogador pesa MAIS na seleção do que no clube: são só onze, e um craque
  // muda uma seleção de um jeito que não muda um elenco inteiro. Mas com
  // TETO: sem ele um japonês de 90 quase dobrava a força do Japão e levava
  // 80% das Copas da Ásia sozinho.
  const meu = peso(f) * Math.min(1.5, 1 + Math.max(0, ovr - f + 8) * 0.03);
  const todas = Object.values(SELECOES);

  if (ano % 4 === 2) {                        // Copa do Mundo
    const soma = todas.reduce((s, [x]) => s + peso(x), 0);
    if (Math.random() < Math.min(0.5, 0.80 * (meu / soma) + 0.20 / todas.length)) {
      ganhos.push('copa_mundo');
    }
  } else if (ano % 4 === 0) {                 // o continental de seleções
    const doCont = todas.filter(([, c]) => c === cont);
    const soma = doCont.reduce((s, [x]) => s + peso(x), 0);
    if (soma > 0 && Math.random() < Math.min(0.6, 0.82 * (meu / soma) + 0.18 / doCont.length)) {
      ganhos.push('selecao_cont');
    }
  }
  return ganhos;
}

/**
 * Qual continental ESTE clube disputa este ano.
 *
 * A fatia é a mesma de `adversarios`: os 30% mais fortes do continente vão à
 * principal, a faixa do meio à segunda, o resto à terceira — e a terceira só
 * existe na Europa, como na vida real. Devolve null pra quem não se
 * classificou pra nada.
 */
const _cacheFatia = {};
function torneioContinental(clube){
  const l = dadosLiga(clube.liga);
  if (!l || l.nivel !== 1) return null;
  if (!_cacheFatia[l.cont]) {
    const doCont = CLUBES
      .filter(c => { const d = dadosLiga(c.liga); return d && d.cont === l.cont && d.nivel === 1; })
      .sort((a, b) => b.forca - a.forca);
    _cacheFatia[l.cont] = {
      n1: doCont[Math.max(0, Math.round(doCont.length * 0.30) - 1)],
      n2: doCont[Math.max(0, Math.round(doCont.length * 0.62) - 1)],
    };
  }
  const {n1, n2} = _cacheFatia[l.cont];
  if (n1 && clube.forca >= n1.forca) return 'cont';
  if (n2 && clube.forca >= n2.forca) return CONT2[l.cont] ? 'cont2' : 'cont';
  // Só a Europa tem três torneios. Onde não há terceiro, quem ficaria de fora
  // desce pro segundo em vez de não disputar NADA — deixar catorze clubes
  // sul-americanos sem competição continental seria pior que não ter dividido.
  if (CONT3[l.cont]) return 'cont3';
  return CONT2[l.cont] ? 'cont2' : 'cont';
}

function titulosDaTemporada(clube, ovr, stats){
  const l = dadosLiga(clube.liga);
  if (!l) return [];
  const ganhos = [];

  // O jogador empurra, mas pouco: futebol é coletivo, e um 90 num time
  // ruim não ganha campeonato sozinho.
  const empurrao = 1 + Math.max(0, (ovr - clube.forca)) * 0.03;

  /**
   * A chance de levar o torneio: o peso do clube dividido pelo peso de
   * todo mundo que disputa.
   *
   * Antes isso era `forca - dificuldade`, uma régua fixa que não sabia
   * contra QUEM o clube jogava — o Peñarol tinha a mesma dificuldade de
   * ganhar o Uruguai que o Villarreal de ganhar a Espanha. Agora quem
   * decide são os adversários, e a hierarquia de cada país aparece
   * sozinha, sem ninguém escrever regra nenhuma sobre ela.
   */
  const chance = (id) => {
    const zebra = COMPETICOES[id][2];
    const {soma, n} = adversarios(clube, id);
    if (soma <= 0) return 0;
    const justo = pesoClube(clube.forca) * empurrao / soma;
    // A zebra é a fatia que ignora força: é ela que deixa o pequeno sonhar,
    // e é maior na copa, que é mata-mata.
    return Math.min(0.92, (1 - zebra) * justo + zebra / n) * 100;
  };

  // Divisão de baixo não tem torneio continental nem mundial: quem está na
  // Série C disputa a Série C.
  const principal = l.nivel === 1;

  if (Math.random() * 100 < chance('liga')) ganhos.push('liga');
  if (Math.random() * 100 < chance('copa')) ganhos.push('copa');

  // Você disputa UMA continental por ano: a do seu tamanho. É o que dá noite
  // europeia ao clube médio sem misturar quem briga por Champions com quem
  // briga por Conference.
  if (principal) {
    const qual = torneioContinental(clube);
    if (qual && Math.random() * 100 < chance(qual)) ganhos.push(qual);
  }
  // Mundial só pra quem ganhou o continente — é assim que ele funciona.
  if (ganhos.includes('cont') && Math.random() * 100 < chance('mundial')) {
    ganhos.push('mundial');
  }

  // AS SUPERCOPAS. Jogo único no começo da temporada seguinte, entre quem
  // ganhou o campeonato e quem ganhou a copa. Não têm disputa própria: quem
  // levantou taça no ano passado entra, e é jogo só — por isso a chance é
  // alta e quase não depende de força. É o título que engorda a galeria de
  // quem já ganha tudo, e o teto de títulos sobe com elas.
  const anoPassado = S.temporadas[S.temporadas.length - 1];
  const ganhouAntes = (anoPassado && anoPassado.titulos) || [];

  // A Supercopa nacional é entre o campeão da PRIMEIRA divisão e o da copa
  // do país. Campeão da Série C não disputa — e era isso que acontecia: a
  // regra só perguntava "ganhou liga?", então o Paysandu levantava a
  // Supercopa do Brasil em 72% das temporadas depois de ganhar a Série C.
  //
  // O país também vem do ano PASSADO: quem ganhou a liga na Espanha e se
  // mudou pro Brasil não disputa a Supercopa brasileira.
  const ligaDoTitulo = anoPassado ? dadosLiga(anoPassado.liga) : null;
  const paisDoTitulo = ligaDoTitulo ? ligaDoTitulo.pais : l.pais;
  const campeaoNaPrimeira = ganhouAntes.includes('liga') && ligaDoTitulo && ligaDoTitulo.nivel === 1;
  if (campeaoNaPrimeira || ganhouAntes.includes('copa')) {
    // E só se ele ainda joga no mesmo país: a Supercopa é do calendário de lá.
    if (paisDoTitulo === l.pais && SUPERNAC[l.pais] && Math.random() < 0.72) ganhos.push('supernac');
  }
  if (ganhouAntes.includes('cont') || ganhouAntes.includes('cont2')) {
    if (SUPERCONT[l.cont] && Math.random() < 0.72) ganhos.push('supercont');
  }

  // Prêmios individuais: dependem do jogador, não do clube — e sobem em
  // degrau. Artilheiro é ser o melhor do seu país; a Chuteira é um número
  // que se destaca em qualquer lugar; a Bola de Ouro exige as duas coisas
  // mais o time ganhando.
  if (stats.gols >= 22 && Math.random() < 0.45) ganhos.push('artilheiro');
  if (stats.gols >= 30 && ovr >= 84 && Math.random() < 0.40) ganhos.push('chuteira');
  // A Luva é o prêmio de quem não marca gol: sem ela o goleiro passava a
  // carreira inteira sem NENHUM prêmio individual, porque os outros três
  // dependem de artilharia.
  if (S.posicao === 'GOL' && stats.cs >= 14 && ovr >= 82 && Math.random() < 0.42) {
    ganhos.push('luva_ouro');
  }
  // A Bola de Ouro de GOLEIRO é o prêmio mais raro do jogo, e é raro de
  // propósito: na vida real aconteceu uma vez, com o Yashin em 63. Exige uma
  // temporada perfeita — quase 90 de overall, a Luva no mesmo ano, o time
  // ganhando — e ainda assim quase nunca sai.
  if (ehGoleiro()) {
    if (ovr >= 92 && ganhos.includes('luva_ouro') && ganhos.length >= 3 && Math.random() < 0.07) {
      ganhos.push('bola_ouro');
    }
  } else if (ovr >= 88 && ganhos.length >= 2 && Math.random() < 0.35) {
    ganhos.push('bola_ouro');
  }

  // Rei da América é do continente: só conta jogando na América do Sul, e
  // é o que dá um prêmio de peso pra quem faz carreira sem sair de casa.
  if (l.cont === 'SAM' && ovr >= 82 && ganhos.length >= 1 && Math.random() < 0.30) {
    ganhos.push('rei_america');
  }

  return ganhos;
}

/* ── Tela 1: modo ───────────────────────────────────── */
function telaInicio(){
  app().innerHTML = `
    ${barraTopo()}
    <div class="inicio">
      <h1>Copero</h1>
      <p class="lead">Você tem 16 anos e nenhum clube. O que vem depois é com você.</p>
      <div class="modos">
        ${Object.entries(MODOS).map(([id,m]) => `
          <button class="modo${id==='classico'?' on':''}" data-modo="${id}" onclick="escolherModo('${id}')">
            <b>${esc(m.nome)}</b><small>${esc(m.sub)}</small>
          </button>`).join('')}
      </div>
      <button class="btn" style="width:100%" onclick="telaIdentidade()">Começar carreira</button>
      ${carregar() ? `<button class="btn btn2" style="width:100%;margin-top:9px" onclick="continuar()">Continuar a carreira salva</button>` : ''}
      <button class="btn btn2" style="width:100%;margin-top:9px" onclick="abrirDesafios()">
        <i class="bi bi-trophy"></i> Desafios
        <span class="conq-conta">${conquistasFeitas().length} de ${Object.keys(CONQUISTAS).length}</span>
      </button>
      <div id="hall"></div>
    </div>`;
  carregarHall();
}

/**
 * As cinco melhores carreiras de todo mundo, do banco.
 *
 * Carrega DEPOIS da tela: se a rede falhar ou não houver ninguém ainda, o
 * bloco simplesmente não aparece e o jogo começa do mesmo jeito.
 */
async function carregarHall(){
  const alvo = document.getElementById('hall');
  if (!alvo) return;
  try {
    const r = await fetch(location.pathname + '?acao=hall');
    const d = await r.json();
    const h = (d && d.hall) || [];
    if (!h.length) return;
    const primeiro = h[0];
    alvo.innerHTML = `
      <div class="hall">
        <div class="hall-cab">Hall da fama</div>
        <div class="hall-topo">
          <span class="hall-medalha">1</span>
          <div class="hall-nome">
            <b>${esc(primeiro.nome)}</b>
            <small>${esc(POSICOES[primeiro.posicao] ? POSICOES[primeiro.posicao][0] : primeiro.posicao)}
              · ${bandeira(primeiro.pais, 15)} ${esc(PAISES[primeiro.pais] || primeiro.pais)}</small>
          </div>
          <div class="ovr-caixa" style="background:${corDoOvr(primeiro.pico_ovr)};width:52px;height:52px">
            <small style="font-size:7px">PICO</small><b style="font-size:21px">${primeiro.pico_ovr}</b>
          </div>
        </div>
        <div class="hall-nums">
          <div><span>Títulos</span><b>${primeiro.titulos}</b></div>
          <div><span>Gols</span><b>${primeiro.gols}</b></div>
          <div><span>Assist.</span><b>${primeiro.ast}</b></div>
          <div><span>Clubes</span><b>${primeiro.clubes}</b></div>
        </div>
        ${h.length > 1 ? `<div class="hall-lista">
          ${h.slice(1).map((x, i) => `
            <div class="hall-linha">
              <span class="hall-pos">${i + 2}</span>
              <span class="hall-quem">${esc(x.nome)}</span>
              <span class="hall-det">${bandeira(x.pais, 14)} ${x.pico_ovr} OVR · ${x.titulos} títulos</span>
            </div>`).join('')}
        </div>` : ''}
      </div>`;
  } catch (e) { /* sem rede, a tela inicial fica como estava */ }
}

/**
 * A lista de desafios, com o que já foi feito e o que falta.
 *
 * Fica na tela inicial porque é objetivo de LONGO prazo: atravessa carreiras,
 * e é o que responde "por que eu jogaria de novo?". O que ainda não saiu
 * aparece apagado mas com a descrição visível — desafio escondido não é
 * desafio, é surpresa.
 */
function abrirDesafios(){
  const feitas = new Set(conquistasFeitas());
  const rotulo = {facil:'Fácil', media:'Média', dificil:'Difícil', impossivel:'Impossível'};
  const ordem  = ['facil','media','dificil','impossivel'];
  const itens  = Object.entries(CONQUISTAS)
    .sort((a,b) => ordem.indexOf(a[1].nivel) - ordem.indexOf(b[1].nivel));

  const porNivel = {};
  itens.forEach(([id,c]) => { (porNivel[c.nivel] = porNivel[c.nivel] || []).push([id,c]); });

  const cx = document.createElement('div');
  cx.className = 'modal-fundo';
  cx.onclick = (e) => { if (e.target === cx) cx.remove(); };
  cx.innerHTML = `
    <div class="modal-cx">
      <div class="modal-cab">
        <b>Desafios</b>
        <span class="conq-conta">${feitas.size} de ${itens.length}</span>
        <button class="modal-x" onclick="this.closest('.modal-fundo').remove()" aria-label="Fechar">
          <i class="bi bi-x-lg"></i></button>
      </div>
      <div class="modal-corpo">
        ${ordem.filter(n => porNivel[n]).map(n => `
          <div class="d-nivel">
            <span class="d-tit n-${n}">${rotulo[n]}</span>
            <small>${porNivel[n].filter(([id]) => feitas.has(id)).length} de ${porNivel[n].length}</small>
            <small class="d-vale">${PREMIO[n] || ''}</small>
          </div>
          <div class="conq-grade">
            ${porNivel[n].map(([id,c]) => `
              <div class="conq n-${n}${feitas.has(id) ? '' : ' travada'}">
                <span class="ic">${feitas.has(id) ? c.icone : '🔒'}</span>
                <div><b>${esc(c.nome)}</b><small>${esc(c.desc)}</small></div>
              </div>`).join('')}
          </div>`).join('')}
      </div>
    </div>`;
  document.body.appendChild(cx);
}
let MODO = 'classico';
function escolherModo(id){
  MODO = id;
  document.querySelectorAll('.modo').forEach(m => m.classList.toggle('on', m.dataset.modo === id));
}
function continuar(){ S = carregar(); if (S) render(); }

/* ── Tela 2: identidade ─────────────────────────────── */
// O nome vem preenchido com o da última carreira: quem joga de novo costuma
// ser a mesma pessoa, e digitar o próprio nome toda vez é atrito à toa.
let rascunho = {nome:ultimoNome(), numero:10, perna:'Direita', pais:'', posicao:'', busca:''};

/** Anda entre as três etapas da criação (só o celular usa). */
function irParaEtapa(n){
  rascunho.etapa = Math.min(3, Math.max(1, n));
  telaIdentidade();
  // Volta pro topo: sem isso a etapa nova abre no meio, na altura em que a
  // anterior estava rolada.
  window.scrollTo({top: 0, behavior: semAnimacao() ? 'auto' : 'smooth'});
}

function telaIdentidade(){
  // Aqui e não só na criação do rascunho: assim o nome da última carreira
  // aparece mesmo quando a pessoa joga de novo sem recarregar a página.
  if (!rascunho.nome) rascunho.nome = ultimoNome();

  const lista = Object.entries(PAISES)
    .filter(([iso,nome]) => !rascunho.busca || nome.toLowerCase().includes(rascunho.busca.toLowerCase()))
    .sort((a,b) => a[1].localeCompare(b[1],'pt-BR'));

  // Onde cada posição fica no campo, em % da caixa.
  const NO_CAMPO = {
    GOL:[50,92], ZAG:[50,79], LE:[16,72], LD:[84,72], VOL:[50,65],
    MC:[50,52],  ME:[17,50],  MD:[83,50], MEI:[50,38], PE:[16,28], PD:[84,28], CA:[50,20],
  };

  // No celular a criação vira três etapas — identidade, país, posição —
  // com uma barra mostrando onde você está. As três colunas empilhadas
  // davam uma página de rolar cinco vezes, e o botão de confirmar ficava
  // lá em cima, longe da última escolha.
  //
  // No desktop nada disso aparece: lá as três cabem lado a lado e ver tudo
  // de uma vez é melhor que navegar. Quem decide é o CSS; o HTML é o mesmo.
  const etapa = Math.min(3, Math.max(1, rascunho.etapa || 1));
  const prontoParaConfirmar = rascunho.nome && rascunho.pais && rascunho.posicao;
  const ETAPAS = ['Identidade', 'Nacionalidade', 'Posição'];
  const podeAvancar = etapa === 1 ? !!rascunho.nome : etapa === 2 ? !!rascunho.pais : prontoParaConfirmar;

  app().innerHTML = `
    ${barraTopo()}
    <div class="caixa ident-caixa" data-etapa="${etapa}">
      <div class="ident-cab">
        <span>Defina sua identidade</span>
        <button class="btn" id="btnConfirmar" ${prontoParaConfirmar ? '' : 'disabled'}
          onclick="comecarCarreira()">Confirmar identidade</button>
      </div>

      <div class="etapa-cab">
        <div class="etapa-tit">${ETAPAS[etapa - 1]}</div>
        <div class="etapa-barra"><i style="width:${(etapa / 3) * 100}%"></i></div>
      </div>

      <div class="ident">
        <div class="ident-col">
          <div class="ident-tit">Identidade</div>
          <div class="camisa">
            <svg viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet">
              <path d="M50 8c-6 0-10 2-15 3L15 17 6 34l15 8 5-7v52h48V35l5 7 15-8-9-17-20-6c-5-1-9-3-15-3z"
                fill="#166534" stroke="#22c55e" stroke-width="2" stroke-linejoin="round"/>
              <path d="M35 11c4 5 11 5 15 5s11 0 15-5" fill="none" stroke="#22c55e" stroke-width="2"/>
            </svg>
            <div class="camisa-txt">
              <div class="camisa-nome${rascunho.nome ? '' : ' vazio'}">${esc(rascunho.nome || 'SEU NOME')}</div>
              <div class="camisa-num">${esc(rascunho.numero || '10')}</div>
            </div>
          </div>
          <div class="campo-linha">
            <div><div class="campo-rot">Sobrenome</div>
              <input class="inp" id="iNome" maxlength="12" value="${esc(rascunho.nome)}" placeholder="Digite"></div>
            <div style="max-width:92px"><div class="campo-rot">Número</div>
              <input class="inp" id="iNum" type="number" min="1" max="99" value="${esc(rascunho.numero)}"></div>
          </div>
          <div class="campo-rot">Perna dominante</div>
          <div class="perna">
            ${['Esquerda','Direita'].map(p => `<button class="${rascunho.perna===p?'on':''}" onclick="rascunho.perna='${p}';telaIdentidade()">${p}</button>`).join('')}
          </div>
        </div>

        <div class="ident-col">
          <div class="ident-tit">Nacionalidade</div>
          <div class="busca"><i class="bi bi-search"></i>
            <input id="iBusca" placeholder="Buscar país" value="${esc(rascunho.busca)}"></div>
          <div class="paises">
            ${lista.length ? lista.map(([iso,nome]) => `
              <button class="pais${rascunho.pais===iso?' on':''}" onclick="rascunho.pais='${iso}';telaIdentidade()">
                ${bandeira(iso)}<span>${esc(nome)}</span></button>`).join('')
              : `<div style="grid-column:1/-1;padding:18px;text-align:center;color:var(--txt3);font-size:12.5px">Nenhum país com esse nome.</div>`}
          </div>
        </div>

        <div class="ident-col">
          <div class="ident-tit">Posição</div>
          <div class="campo">
            <i class="risco" style="left:8%;right:8%;top:50%;height:1px"></i>
            <i class="risco" style="left:26%;right:26%;top:0;height:14%;border:1px solid rgba(255,255,255,.16);background:none"></i>
            <i class="risco" style="left:26%;right:26%;bottom:0;height:14%;border:1px solid rgba(255,255,255,.16);background:none"></i>
            ${Object.entries(NO_CAMPO).map(([sig,[x,y]]) => `
              <button class="pos${rascunho.posicao===sig?' on':''}" style="left:${x}%;top:${y}%"
                title="${esc(POSICOES[sig][0])}" onclick="rascunho.posicao='${sig}';telaIdentidade()">${sig}</button>`).join('')}
          </div>
        </div>
      </div>
      <div class="ident-pe">
        <button class="btn btn2" onclick="${etapa === 1 ? 'telaInicio()' : 'irParaEtapa(' + (etapa - 1) + ')'}">Voltar</button>
        ${etapa < 3
          ? `<button class="btn etapa-so-celular" ${podeAvancar ? '' : 'disabled'}
               onclick="irParaEtapa(${etapa + 1})">Continuar</button>`
          : `<button class="btn etapa-so-celular" ${prontoParaConfirmar ? '' : 'disabled'}
               onclick="comecarCarreira()">Confirmar identidade</button>`}
      </div>
    </div>
    <p class="rodape">Os nomes de clube servem para identificar dentro da simulação.
    Este jogo não é afiliado, patrocinado nem endossado por nenhum deles.</p>`;

  const iNome = document.getElementById('iNome');
  iNome.addEventListener('input', e => {
    rascunho.nome = e.target.value.toUpperCase();
    const cn = document.querySelector('.camisa-nome');
    cn.textContent = rascunho.nome || 'SEU NOME';
    cn.classList.toggle('vazio', !rascunho.nome);
    document.getElementById('btnConfirmar').disabled = !(rascunho.nome && rascunho.pais && rascunho.posicao);
  });
  document.getElementById('iNum').addEventListener('input', e => {
    rascunho.numero = Math.max(1, Math.min(99, Number(e.target.value) || 10));
    document.querySelector('.camisa-num').textContent = rascunho.numero;
  });
  const iB = document.getElementById('iBusca');
  iB.addEventListener('input', e => {
    rascunho.busca = e.target.value;
    telaIdentidade();
    const novo = document.getElementById('iBusca');
    novo.focus(); novo.setSelectionRange(novo.value.length, novo.value.length);
  });
}

/* ── Começo ─────────────────────────────────────────── */
function comecarCarreira(){
  guardarNome(rascunho.nome);
  S = {
    nome: rascunho.nome, numero: rascunho.numero, perna: rascunho.perna,
    pais: rascunho.pais, posicao: rascunho.posicao, modo: MODO,
    idade: IDADE_INI, ovr: 50, clube: null, temporadas: [],
    // Os três dados escondidos da carreira, sorteados aqui e nunca mostrados.
    // São eles que fazem a mesma escolha dar carreiras diferentes: um cresce
    // até os 31, outro estaciona aos 24; um joga bem até os 38, outro
    // desmonta aos 32.
    talento: (70 + Math.floor(Math.random() * 55)) / 100,
    pico: 24 + Math.floor(Math.random() * 8),
    durabilidade: (75 + Math.floor(Math.random() * 60)) / 100,
    picoOvr: 50, picoValor: 0, maiorForcaClube: 0, comecouAbaixo: false,
    fase: 'oferta_base', evento: null, fim: false, resultado: null,
  };
  salvar(); render();
}

/* ── O laço da carreira ─────────────────────────────── */

/**
 * Onde um jogador deste país tende a COMEÇAR.
 *
 * Brasileiro sai do Brasil, não do Nice. Marroquino sai do Marrocos ou da
 * França, que é pra onde a imigração leva — e é isso que faz a escolha de
 * nacionalidade valer alguma coisa além da bandeirinha.
 *
 * Primeiro o país da pessoa; depois os destinos naturais dela. Quem não está
 * na lista cai no próprio país e, se ele não tiver liga, no mundo todo.
 */
const DESTINOS = {
  BRA:['BRA','POR'],            ARG:['ARG','ESP','ITA'],   URU:['URU','ARG','ESP'],
  CHI:['CHI','ARG'],            COL:['COL','ARG','MEX'],   ENG:['ENG','SCO'],
  ESP:['ESP','POR'],            ITA:['ITA','ESP'],         GER:['GER','AUT'],
  FRA:['FRA','BEL'],            POR:['POR','ESP'],         NED:['NED','BEL','GER'],
  BEL:['BEL','NED','FRA'],      CRO:['CRO','ITA','GER'],   TUR:['TUR','GER'],
  RUS:['RUS'],                  SCO:['SCO','ENG'],         GRE:['GRE','ITA'],
  USA:['USA','MEX'],            MEX:['MEX','USA'],         KSA:['KSA'],
  JPN:['JPN','KOR'],            KOR:['KOR','JPN'],
  // África: quem sai, sai principalmente pra França, Bélgica e Portugal.
  EGY:['EGY','KSA'],            MAR:['MAR','FRA','ESP'],   RSA:['RSA','ENG'],
  NGA:['NGA','ENG','BEL'],      SEN:['SEN','FRA','BEL'],   CIV:['CIV','FRA','BEL'],
  AUS:['AUS','ENG'],
};

/** Os países onde um jogador desta nacionalidade pode começar. */
function paisesDeInicio(pais){
  const d = DESTINOS[pais] || [pais];
  // Só os que têm liga no catálogo — Senegal e Costa do Marfim não têm, e a
  // lista deles precisa cair na França sem virar oferta vazia.
  const comLiga = new Set(Object.values(LIGAS).map(l => l[0]));
  const bons = d.filter(p => comLiga.has(p));
  return bons.length ? bons : null;
}

/**
 * A divisão vizinha do mesmo país: 'sobe' vai pra de cima, 'cai' pra de baixo.
 *
 * Devolve null quando não existe — ninguém sobe da primeira divisão, e quem
 * está na última do país não tem pra onde cair.
 */
function ligaVizinha(ligaId, direcao){
  const l = LIGAS[ligaId];
  if (!l) return null;
  const [pais, , , nivel] = l;
  const alvo = nivel + (direcao === 'sobe' ? -1 : 1);
  if (alvo < 1) return null;
  const achada = Object.entries(LIGAS).find(([, x]) => x[0] === pais && x[3] === alvo);
  return achada ? achada[0] : null;
}

/**
 * O clube subiu ou caiu de divisão no fim da temporada?
 *
 * Quem é muito mais fraco que a própria liga cai; quem é muito mais forte
 * sobe. É o que faz um Santos de 74 na Série B voltar pra A, e um Cuiabá de
 * 72 na Série A descer — sem isso o clube ficava congelado na divisão pra
 * sempre, e a carreira do jogador não sentia o que acontece ao redor dele.
 *
 * Devolve 'sobe', 'cai' ou null.
 */
function movimentoDoClube(clube, ganhouALiga){
  const l = LIGAS[clube.liga];
  if (!l) return null;
  const media = l[4];
  const dif = clube.forca - media;

  // CAMPEÃO DE DIVISÃO DE ACESSO SOBE. Isso não é sorteio, é a regra do
  // futebol — e sem ela dava pra ganhar a Série B a carreira inteira com o
  // mesmo clube, que era o furo: o time levantava a taça todo ano e
  // continuava na Série B pra levantar de novo.
  if (ganhouALiga) {
    const acima = ligaVizinha(clube.liga, 'sobe');
    return acima ? 'sobe' : null;   // campeão nunca cai
  }

  // Quem acabou de mudar de divisão tem uma temporada de adaptação. Sem ela o
  // clube pequeno subia e caía no ano seguinte, todas as vezes — quatro
  // sobe-desce numa carreira só, o que não é ioiô de futebol, é pisca-pisca.
  if (clube.movidoAos != null && (S.idade - clube.movidoAos) < 2) return null;

  // As chances são baixas de propósito: rebaixamento todo ano viraria ruído.
  if (dif >= 6 && ligaVizinha(clube.liga, 'sobe')) {
    if (Math.random() * 100 < Math.min(35, (dif - 5) * 4)) return 'sobe';
  }
  if (dif <= -6 && ligaVizinha(clube.liga, 'cai')) {
    if (Math.random() * 100 < Math.min(35, (-dif - 5) * 4)) return 'cai';
  }
  return null;
}

/**
 * As ligas que pagam bem e valem pouco: destino de fim de carreira.
 *
 * Um garoto de 19 anos não vai do Brasil pra Arábia — quem vai é o veterano
 * atrás do último contrato. Sem isto o sorteio jogava Arábia e MLS no meio da
 * escalada, e a carreira virava aquele `BR3>AR2>UY1>SA1>ES1` sem pé nem
 * cabeça. Vale só pra ESTRANGEIRO: americano roda pela MLS a vida toda.
 */
const LIGAS_TARDIAS = new Set(['SA1', 'US1', 'JP1', 'KR1', 'AU1']);

/**
 * Quem te procura, e de onde.
 *
 * A carreira é uma ESCADA, e cada degrau tem que ser merecido. O prestígio de
 * uma liga é a força média dela (Premier 96, Série C 54) — é isso que ordena
 * o mundo e responde "isso é subida ou passo pro lado?".
 *
 * As regras, todas medidas na simulação antes de entrar:
 *
 *   · O clube cabe em você. A faixa APERTA conforme você sobe: um OVR 88 só
 *     interessa a clube de 80 pra cima, e a partir de uns 84 nenhum clube do
 *     mundo é grande demais — antes o teto era `ovr + 6`, e com isso City,
 *     Real, Bayern e Barcelona (92 a 99) eram literalmente inalcançáveis.
 *   · Você não desce de prestígio no auge. Quem está na LaLiga não recebe
 *     proposta do Chipre aos 24 — recebe aos 33.
 *   · Sair do país é PROMOÇÃO, nunca passo lateral: precisa da primeira
 *     divisão de casa (ou de já ser bom demais pra ela) e a liga de destino
 *     tem que valer mais que a sua.
 *   · Trocar de continente exige nome feito.
 *
 * As opções saem em DEGRAUS — a ambiciosa, a do seu nível, a alternativa —
 * e não sorteadas do mesmo balaio: três cartas iguais não são escolha.
 */
function ofertas(quantos, exceto, soDeCasa){
  const fora  = new Set(exceto || []);
  // Quem você trocou pelo rival não te quer de volta. É a outra metade do
  // preço do clássico: a porta fecha atrás de você.
  if (S.marcadoPor) fora.add(S.marcadoPor);
  const atual = S.clube ? dadosLiga(S.clube.liga) : null;
  const pres  = atual ? atual.media : 0;
  const veterano = S.idade >= 30;
  const declinio = S.ovr < (S.picoOvr || S.ovr) - 3;

  // A faixa de força. O termo extra é o que abre o topo do mundo pra quem
  // chegou lá: em 84 o teto encosta em 99, e o Real passa a ser possível.
  const teto = Math.min(99, Math.round(S.ovr + 4 + Math.max(0, S.ovr - 66) * 0.55));
  const piso = Math.round(Math.max(40, S.ovr - 16 + Math.max(0, S.ovr - 68) * 0.55));

  // A faixa de prestígio. Liga grande demais não te chama; liga pequena
  // demais só aparece quando a carreira já está de descida.
  //
  // O teto nunca pode ficar abaixo da liga onde você JÁ joga — senão o garoto
  // de 51 na Série B não cabia na própria Série B, a lista saía vazia e a
  // rede de segurança o mandava pra Ligue 2 aos 17 anos.
  const tetoLiga = Math.max(pres + 6, S.ovr + 12);
  const pisoLiga = (veterano || declinio) ? pres - 26 : pres - 8;
  const podeSair = !atual || atual.nivel === 1 || S.ovr >= 76;

  const cabe = (c) => {
    if (fora.has(c.nome) || c.forca > teto || c.forca < piso) return false;
    const l = dadosLiga(c.liga);
    if (!l) return false;

    if (LIGAS_TARDIAS.has(c.liga) && l.pais !== S.pais && !veterano && !declinio) return false;
    if (l.media > tetoLiga || l.media < pisoLiga) return false;
    if (!atual) return true;

    // Um degrau de divisão por vez, dentro do país.
    if (l.nivel < atual.nivel - 1) return false;

    if (l.pais !== atual.pais) {
      if (!podeSair) return false;
      if (l.media < pres && !veterano && !declinio) return false;

      // Mudar de CONTINENTE, na idade boa, é só pra ir ao topo do mundo.
      // É o que separa o sonho europeu de um passo lateral: um brasileiro do
      // Brasileirão vai pra Premier, mas um inglês do Championship não vai
      // pro Brasileirão — e era isso que estava acontecendo, porque a régua
      // só olhava prestígio e o Brasileirão vale mais que a segunda inglesa.
      if (l.cont !== atual.cont && !veterano && !declinio && l.media < 88) return false;
    }
    return true;
  };

  let elegiveis = CLUBES.filter(cabe);

  if (soDeCasa) {
    const paises = paisesDeInicio(S.pais);
    if (paises) {
      // O país da pessoa esgota primeiro; os destinos de imigração só
      // completam quando o país dela não tem clube suficiente.
      const daCasa = [];
      for (const p of paises) {
        const doPais = CLUBES
          .filter(c => { const l = dadosLiga(c.liga); return l && l.pais === p && !fora.has(c.nome); })
          .sort((a, b) => a.forca - b.forca).slice(0, 10);
        for (const c of doPais) if (!daCasa.includes(c)) daCasa.push(c);
        if (daCasa.length >= quantos) break;
      }
      if (daCasa.length >= quantos)  elegiveis = daCasa;
      else if (daCasa.length)        elegiveis = daCasa.concat(elegiveis.filter(c => !daCasa.includes(c)));

      // UM GRANDE NA MESA, de vez em quando.
      //
      // A lista de casa pega os dez clubes mais FRACOS do país, então a base
      // sempre começava num clube pequeno — e o garoto revelado no Flamengo,
      // que é de onde sai metade dos jogadores de verdade, não existia. Uma
      // em cada quatro carreiras agora tem um clube de primeira divisão na
      // oferta inicial. Escolher é outra coisa: lá dentro não vai ter espaço
      // no começo, e é aí que o empréstimo entra.
      if (Math.random() < 0.26) {
        const grandes = CLUBES.filter(c => {
          const l = dadosLiga(c.liga);
          return l && l.nivel === 1 && paises.includes(l.pais) && c.forca >= 72 && !fora.has(c.nome);
        });
        if (grandes.length) {
          const g = grandes[Math.floor(Math.random() * grandes.length)];
          elegiveis = [g].concat(elegiveis.filter(c => c.nome !== g.nome));
        }
      }
    }
  }

  // Rede de segurança em dois passos, e ela RESPEITA a regra de sair do país.
  // Uma rede frouxa não salva o jogo, esconde o defeito: era ela que estava
  // mandando o garoto pra França, e o furo só apareceu jogando.
  if (!elegiveis.length) {
    elegiveis = CLUBES.filter(c => {
      const l = dadosLiga(c.liga);
      if (!l || fora.has(c.nome) || Math.abs(c.forca - S.ovr) > 12) return false;
      if (!atual) return true;
      return l.pais === atual.pais || (podeSair && l.media >= pres);
    });
  }
  if (!elegiveis.length) {
    elegiveis = CLUBES.filter(c => {
      const l = dadosLiga(c.liga);
      if (!l || fora.has(c.nome) || Math.abs(c.forca - S.ovr) > 18) return false;
      return !atual || podeSair || l.pais === atual.pais;
    });
  }

  // Em degraus: uma de cada patamar da lista, do mais forte pro mais fraco.
  const ordem = elegiveis.slice().sort((a, b) => b.forca - a.forca);
  const n = ordem.length, saida = [];
  for (let i = 0; i < quantos && saida.length < n; i++) {
    const ini = Math.floor(n * i / quantos);
    const fim = Math.max(ini + 1, Math.floor(n * (i + 1) / quantos));
    const bloco = ordem.slice(ini, fim).filter(c => !saida.includes(c));
    if (bloco.length) saida.push(bloco[Math.floor(Math.random() * bloco.length)]);
  }
  while (saida.length < quantos && saida.length < n) {
    const c = ordem[Math.floor(Math.random() * n)];
    if (!saida.includes(c)) saida.push(c);
  }

  // O CONVITE DE FORA, e ele entra na saída FINAL — não na lista de
  // elegíveis. Jogado no balaio junto com os outros duzentos, ele quase nunca
  // era sorteado: aparecia em 1% das janelas, o que na prática é nunca.
  //
  // É a única porta para uma carreira de cinco continentes, e é estreita de
  // propósito: exige nome feito, um clube do seu tamanho, e mesmo assim só
  // aparece de vez em quando. Aceitar custa caro — quase sempre é trocar a
  // Europa por uma liga menor — e é isso que faz dos cinco continentes a
  // coisa mais rara do jogo.
  if (atual && S.ovr >= 78 && Math.random() < 0.16) {
    const visitados = new Set((S.temporadas || []).map(x => {
      const d = dadosLiga(x.liga); return d && d.cont;
    }));
    const novos = CLUBES.filter(c => {
      const l = dadosLiga(c.liga);
      return l && l.nivel === 1 && !visitados.has(l.cont)
             && !fora.has(c.nome) && c.forca >= S.ovr - 18 && c.forca <= S.ovr + 8;
    });
    if (novos.length) saida[saida.length - 1] = novos[Math.floor(Math.random() * novos.length)];
  }
  return saida;
}

/* ── EMPRÉSTIMO ─────────────────────────────────────────
 *
 * O caminho de quem foi revelado num clube grande: lá dentro não tem espaço
 * (o encaixe derruba os jogos pra 35%), e o clube manda o garoto rodar num
 * menor pra jogar de verdade. É o que acontece com quase todo jovem de
 * primeira divisão, e é uma decisão de carreira: aceitar significa jogar e
 * evoluir agora, ficar significa apostar que você fura a fila.
 *
 * Não é uma fase obrigatória — é um evento, e só aparece quando faz sentido:
 * nos primeiros quatro anos, e só se o clube for grande demais pro seu nível.
 * Quem já alcançou o time não é emprestado: tem espaço, e fica.
 */
function cabeEmprestimo(){
  if (S.emprestadoDe) return false;              // já está emprestado
  if (!S.clube) return false;
  if ((S.temporadas || []).length > 4) return false;   // só nos primeiros anos
  return S.clube.forca - S.ovr >= 10;            // não tem espaço no elenco
}

/**
 * Os clubes que topam receber o garoto emprestado.
 *
 * Menores que o dono e do tamanho de quem vai jogar — o ponto do empréstimo
 * é ter minutos, então um clube ainda maior não serve. Preferência pro mesmo
 * país; o mundo entra só se o país não tiver ninguém do tamanho.
 */
function ofertasDeEmprestimo(quantos){
  const dono = S.emprestadoDe || S.clube;
  const l = dadosLiga(dono.liga);
  const pais = l ? l.pais : null;
  const cabe = (c) => {
    if (c.nome === dono.nome) return false;
    if (S.clube && c.nome === S.clube.nome) return false;
    const d = dadosLiga(c.liga);
    if (!d) return false;
    if (c.forca > dono.forca - 6) return false;        // menor que o dono
    return c.forca >= S.ovr - 12 && c.forca <= S.ovr + 10;
  };
  let lista = CLUBES.filter(c => cabe(c) && (dadosLiga(c.liga) || {}).pais === pais);
  if (lista.length < quantos) lista = lista.concat(CLUBES.filter(c => cabe(c) && !lista.includes(c)));
  // Um de cada patamar, do mais forte pro mais fraco — mesma ideia do mercado.
  const ordem = lista.sort((a, b) => b.forca - a.forca), n = ordem.length, saida = [];
  for (let i = 0; i < quantos && saida.length < n; i++) {
    const ini = Math.floor(n * i / quantos), fim = Math.max(ini + 1, Math.floor(n * (i + 1) / quantos));
    const bloco = ordem.slice(ini, fim).filter(c => !saida.includes(c));
    if (bloco.length) saida.push(bloco[Math.floor(Math.random() * bloco.length)]);
  }
  return saida;
}

/** Vai emprestado: guarda o dono e joga o ano no clube novo. */
async function aceitarEmprestimo(i){
  const c = (S.opcoes || [])[i];
  if (!c) return;
  S.fase = 'jogando';
  // O dono é sempre o ORIGINAL: rodar por três clubes emprestado não muda
  // de quem você é.
  if (!S.emprestadoDe) S.emprestadoDe = S.clube;
  S.clube = c;
  S.emprestimos = (S.emprestimos || 0) + 1;
  await jogarAnos();
}

/** Recusa: fica no clube grande e briga por espaço. */
async function recusarEmprestimo(){
  S.recusouEmprestimo = true;
  S.fase = 'jogando';
  await jogarAnos();
}

/** Volta pro dono ao fim do empréstimo. */
async function voltarDoEmprestimo(){
  S.clube = S.emprestadoDe;
  S.emprestadoDe = null;
  S.fase = 'jogando';
  await jogarAnos();
}

/** Fica de vez no clube onde jogou emprestado. */
async function ficarNoEmprestimo(){
  S.emprestadoDe = null;
  S.fase = 'jogando';
  S.maiorForcaClube = Math.max(S.maiorForcaClube, S.clube.forca);
  await jogarAnos();
}

/**
 * O clube que te recebeu quer comprar?
 *
 * Só se você jogou no nível dele — quem passou o ano no banco lá também
 * volta pro dono sem proposta nenhuma.
 */
function emprestimoVirouCompra(){
  return !!S.emprestadoDe && S.ovr >= S.clube.forca - 4;
}

/** Um evento que caiba no momento da carreira. */
const EVENTOS = <?= json_encode(array_map(function ($e) {
    unset($e['quando']);
    return $e;
}, coperoEventos()), JSON_UNESCAPED_UNICODE) ?>;

function eventoDaVez(){
  // As condições de cada evento vivem aqui porque o servidor não consegue
  // serializar closure — a lista lá é a fonte do CONTEÚDO, esta é do momento.
  const cabe = {
    concentracao: () => S.idade >= 18,
    treino_dobro: () => S.idade >= 17 && S.idade <= 30,
    dieta:        () => S.idade >= 19,
    polemica:     () => S.idade >= 22 && S.ovr >= 70,
    fiscal:       () => S.idade >= 25 && valorAtual() >= 8000000,
    capitao:      () => S.idade >= 24 && S.ovr >= 75,
  };
  const possiveis = EVENTOS.filter(e => (cabe[e.id] || (()=>true))());
  return possiveis.length ? possiveis[Math.floor(Math.random()*possiveis.length)] : null;
}

function valorAtual(){
  const f = S.clube ? S.clube.forca : 55;
  const o = S.ovr;
  if (o < 45) return 50000;
  let base = 100000 * Math.pow(1.19, o - 50);
  if      (S.idade <= 21) base *= 0.70 + ((S.idade - 16) * 0.06);
  else if (S.idade <= 26) base *= 1.00;
  else if (S.idade <= 29) base *= 0.85;
  else if (S.idade <= 32) base *= 0.55;
  else if (S.idade <= 35) base *= 0.25;
  else                    base *= Math.max(0.04, 0.12 - ((S.idade - 35) * 0.02));
  base *= 0.88 + (f / 400);
  if (base >= 10000000) return Math.round(base/1000000)*1000000;
  if (base >= 1000000)  return Math.round(base/100000)*100000;
  return Math.round(base/10000)*10000;
}

/** Assina com um clube e joga os anos do passo do modo. */
/** Assina a oferta N da lista atual. Por índice, e não por nome: nome vai
  * pro atributo entre aspas e clube com aspas no nome quebrava o onclick. */
async function assinarOpcao(i){
  const c = (S.opcoes || [])[i];
  if (c) await assinar(c);
}

async function assinar(clube){
  // TROCAR PELO RIVAL COBRA. A primeira temporada é sob vaia: você joga
  // menos, e o clube que você deixou não te procura mais. É o que faz a
  // decisão do clássico ser uma decisão, e não mais uma linha na tabela.
  if (S.clube && clube && S.clube.nome !== clube.nome && ehRival(S.clube.nome, clube.nome)) {
    S.trocouPeloRival = (S.trocouPeloRival || 0) + 1;
    S.marcadoPor = S.clube.nome;      // o antigo não te contrata de volta
    S.pressaoRival = true;            // pesa na temporada que vem
  }

  S.clube = clube;
  S.fase = 'jogando';
  if (!S.temporadas.length) {
    const l = dadosLiga(clube.liga);
    S.comecouAbaixo = !!(l && l.nivel >= 2);
  }
  S.maiorForcaClube = Math.max(S.maiorForcaClube, clube.forca);
  await jogarAnos();
}

async function jogarAnos(){
  const passo = MODOS[S.modo] ? MODOS[S.modo].passo : 1;
  for (let i = 0; i < passo && S.idade <= IDADE_FIM - 1; i++) {
    const t = temporada();
    S.temporadas.push(t);
    S.picoOvr   = Math.max(S.picoOvr, t.ovr);
    S.picoValor = Math.max(S.picoValor, t.valor);

    // A linha aparece AGORA, antes de mexer no overall: primeiro o que
    // aconteceu no ano, depois o que isso fez com você. O render tem que
    // vir antes de S.ovr mudar, senão a caixa do overall já nasce com o
    // número novo e a animação vira um pisca sem efeito.
    S.linhaNova = t.idade;
    render();
    await preencherLinha(t.idade);
    S.linhaNova = null;

    const antes = S.ovr;
    S.ovr = evoluir();
    S.idade++;

    // O clube pode subir ou cair de divisão. Muda a liga do clube NO ESTADO,
    // não no catálogo: a queda é da carreira desta pessoa, e a próxima
    // carreira começa com o mundo no lugar.
    const mov = movimentoDoClube(S.clube, (t.titulos || []).includes('liga'));
    if (mov) {
      const nova = ligaVizinha(S.clube.liga, mov);
      if (nova) {
        t.movimento = mov;
        // Quem sobe se REFORÇA e quem cai encolhe. Sem isso o clube virava
        // ioiô: subia, era fraco demais pra divisão de cima, caía no ano
        // seguinte, ganhava a de baixo de novo — cinco títulos da Série B
        // numa carreira só. Agora ele converge pra divisão onde cabe.
        const ajuste = mov === 'sobe' ? 4 : -4;
        S.clube = Object.assign({}, S.clube, {
          liga: nova,
          forca: Math.max(45, Math.min(99, S.clube.forca + ajuste)),
          movidoAos: S.idade,
        });
      }
    }
    // A taça vem ANTES do OVR: primeiro o que aconteceu no ano, depois o
    // que isso fez com você.
    await mostrarTacas(t);
    await animarOvr(antes, S.ovr);
  }
  proximaFase();
}

/** Os números de um ano, com a mesma régua do motor no servidor. */
function temporada(){
  const p = POSICOES[S.posicao] || POSICOES.MC;
  const pesoGol = p[2], pesoAst = p[3];
  const f = S.clube.forca;

  const encaixe = Math.max(0.35, Math.min(1.15, 1 + ((S.ovr - f) / 40)));
  let jogos = Math.round(ri(26,42) * encaixe);
  jogos = Math.max(4, Math.min(52, jogos + (S.jogosBonus || 0)));
  S.jogosBonus = 0;

  // A LESÃO GRAVE. Existe pra dar cara ao que já acontecia às escondidas: o
  // overall caía num ano ruim e ninguém sabia por quê. Fica mais provável com
  // a idade, e come mais da metade da temporada.
  const risco = 0.045 + Math.max(0, S.idade - 28) * 0.008;
  const lesionado = Math.random() < risco;
  if (lesionado) jogos = Math.max(2, Math.round(jogos * (ri(25,50) / 100)));

  // A conta da traição: a temporada seguinte à troca pelo rival é sob
  // pressão, e ela custa um quarto dos jogos.
  const sobPressao = !!S.pressaoRival;
  if (sobPressao) { jogos = Math.max(4, Math.round(jogos * 0.75)); S.pressaoRival = false; }

  // A qualidade cresce mais que linear: é o que separa o artilheiro de elite
  // do bom atacante. Com a régua reta de antes, um 94 marcava 30 por ano e o
  // milésimo gol era inalcançável — e o milésimo é o número mítico do
  // futebol, tem que caber numa carreira perfeita.
  const q = Math.max(0.18, Math.pow(Math.max(0, S.ovr - 42) / 48, 2.3) * 1.32);

  // CONTRA QUEM ele joga. Faltava isto: a liga não entrava na conta, e um 90
  // fazia os mesmos 52 gols na Premier e na Série C — o mesmo jogador, contra
  // zagueiros de 96 e de 54, com o mesmo número no fim do ano.
  //
  // Agora conta a distância entre o jogador e a média da liga: quem está 36
  // acima (um 90 na Série C) encara defesa que não o acompanha e goleia; quem
  // está abaixo (um 90 na Premier) briga por cada espaço. O teto de 2,05 é o
  // que faz caber uma temporada de 100 e poucos gols sem virar rotina.
  const mediaDaLiga = (dadosLiga(S.clube.liga) || {}).media || 72;
  const folga = Math.max(0.55, Math.min(2.05, 1 + (S.ovr - mediaDaLiga) / 34));
  const t = {
    idade: S.idade, clube: S.clube.nome, liga: S.clube.liga, ovr: S.ovr,
    emprestado: !!S.emprestadoDe,
    jogos,
    gols: Math.max(0, Math.round(jogos * pesoGol * q * folga * (ri(75,130)/100))),
    ast:  Math.max(0, Math.round(jogos * pesoAst * q * folga * (ri(70,135)/100))),
    valor: valorAtual(),
  };

  // GOLEIRO tem outro boletim. Gol e assistência não dizem nada sobre ele —
  // a temporada de um goleiro se mede em gols sofridos e jogos sem sofrer.
  // Quem defende bem num time bom leva menos; os dois pesam.
  if (S.posicao === 'GOL') {
    const porJogo = Math.max(0.55, Math.min(2.1,
      1.85 - (f - 70) / 42 - (S.ovr - 70) / 55)) * (ri(85,118) / 100);
    t.gs = Math.max(0, Math.round(jogos * porJogo));
    // A chance de zerar cai junto com os gols sofridos, e nunca chega a zero:
    // até goleiro de time ruim segura um jogo de vez em quando.
    const chanceCs = Math.max(0.08, Math.min(0.5, 0.47 - (porJogo - 0.7) * 0.26));
    t.cs = Math.max(0, Math.round(jogos * chanceCs * (ri(80,120) / 100)));
    t.gols = 0; t.ast = 0;
  }
  if (lesionado) t.lesao = true;
  if (sobPressao) t.rival = true;
  t.titulos = titulosDaTemporada(S.clube, S.ovr, t);

  // A seleção joga por fora do clube: convocação e torneio dependem do país
  // e do ano, não de onde você está jogando.
  S.ano = (S.ano || 2024);
  t.ano = S.ano;
  if (convocado(S.ovr, S.pais)) t.selecao = true;
  t.titulos = t.titulos.concat(titulosDaSelecao(S.ovr, S.ano));
  S.ano++;
  return t;
}

/**
 * Quanto você cresceu no ano.
 *
 * Três coisas mandam, e as três foram medidas em simulação de 500 carreiras:
 *
 *   · O AMBIENTE: treinar num clube acima do seu nível puxa você pra cima.
 *   · Os MINUTOS: e é aqui que o atalho se paga. Ir cedo demais pro clube
 *     grande enche o banco (`temporada` já corta os jogos pelo encaixe), e
 *     sem jogo não tem evolução. Antes o bônus de ambiente vinha inteiro e de
 *     graça: dava pra assinar com o Bayern aos 19 e virar 95 sem entrar em
 *     campo. Só o ganho é freado — a queda da idade não se negocia.
 *   · O TALENTO, sorteado uma vez por carreira e nunca mostrado. É o que faz
 *     duas carreiras iguais terminarem diferente: sem ele, 100% dos jogadores
 *     passavam de 75 de overall e chegar a um gigante não valia nada.
 */
function evoluir(){
  const t   = S.temporadas[S.temporadas.length - 1];
  const min = t ? Math.max(0.45, Math.min(1.1, t.jogos / 28)) : 1;
  const amb = Math.max(-1.5, Math.min(2.2, (S.clube.forca - S.ovr) / 10));
  const tal = S.talento || 1;
  const pico = S.pico || 27;
  const dur  = S.durabilidade || 1;

  let d;
  if (S.idade < pico) {
    // Subindo, e mais rápido quanto mais longe do auge. Os números são
    // apertados de propósito: quem cresce até os 31 tem quinze temporadas
    // de ganho, e com a mão pesada TODO MUNDO terminava acima de 90.
    d = (ri(-1,2) + Math.min(3, (pico - S.idade) * 0.45) + amb) * min * tal;
    // O ESTIRÃO: aquele ano em que o garoto encaixa tudo e some da divisão
    // de baixo. Raro, e mais provável em quem tem talento.
    if (S.idade <= 23 && Math.random() < 0.11 * tal) d += ri(3,7);
  } else if (S.idade <= pico + 2) {
    d = ri(-1,2) + amb * 0.4;               // o platô do auge
  } else {
    // Caindo. A durabilidade decide se é ladeira ou tobogã — é o que separa
    // quem joga bem até os 38 de quem acaba aos 32. Suave: com a queda
    // acelerada de antes, todo jogador terminava a carreira no piso.
    d = -(ri(0,2) * 0.6 + (S.idade - pico - 2) * 0.3) / dur;
  }

  // A lesão da temporada cobra o preço aqui. Quem se machuca perde ritmo, e
  // é isso que faz uma promessa às vezes simplesmente não virar.
  if (t && t.lesao) d -= ri(2,5);

  if (S.ovr >= 88) d = Math.min(d, ri(0,2));
  if (S.ovr >= 93) d = Math.min(d, ri(0,1));
  if (S.ovr >= 96) d = Math.min(d, 0);
  return Math.max(35, Math.min(99, S.ovr + Math.round(d)));
}

/** Decide o que vem depois de jogar: evento, mercado ou fim. */
/** Quantas temporadas seguidas você já fez no clube de agora. */
function anosNoClube(){
  const t = S.temporadas || [];
  let n = 0;
  for (let i = t.length - 1; i >= 0 && t[i].clube === (S.clube || {}).nome; i--) n++;
  return n;
}

/**
 * Cinco temporadas seguidas no mesmo clube e você virou ídolo da casa.
 *
 * Vale alguma coisa: o clube não te dispensa. Sem isso, ficar era sempre a
 * pior escolha do jogo — todo ano aparecia alguém maior, e não havia motivo
 * nenhum pra construir história em lugar nenhum.
 */
function ehIdolo(){ return anosNoClube() >= 5; }

function proximaFase(){
  // Cada janela nova traz uma chance nova de pedir outras opções.
  if (S.idade >= IDADE_FIM) { S.fase = 'fim'; S.fim = true; salvar(); render(); return; }

  // Empréstimo acabou: a decisão de voltar, ficar ou rodar de novo vem antes
  // de qualquer outra coisa — o jogador está pendurado entre dois clubes e
  // isso precisa ser resolvido.
  if (S.emprestadoDe) {
    S.fase = 'fim_emprestimo';
    S.opcoes = (S.temporadas.length <= 5) ? ofertasDeEmprestimo(2) : [];
    salvar(); render(); return;
  }

  // E o empréstimo em si é um evento como os outros: só aparece quando cabe
  // (jovem, em clube grande demais pro seu nível) e nem sempre.
  if (cabeEmprestimo() && !S.recusouEmprestimo && Math.random() < 0.62) {
    S.fase = 'emprestimo';
    S.opcoes = ofertasDeEmprestimo(3);
    if (S.opcoes.length) { salvar(); render(); return; }
  }
  S.recusouEmprestimo = false;

  // Depois dos 33 o clube pode não renovar — é o que traz a aposentadoria
  // como decisão, e não como parede de idade. Ídolo da casa não é dispensado:
  // o clube segura quem virou história ali.
  if (S.idade >= 33 && !ehIdolo() && Math.random() < 0.45) {
    S.fase = 'fim_ciclo';
    S.opcoes = ofertas(2, [S.clube.nome]);
  } else if (S.idade - (S.ultimoMercado || 0) >= 2 && Math.random() < 0.62) {
    S.fase = 'mercado';
    S.ultimoMercado = S.idade;
    S.opcoes = ofertas(2, [S.clube.nome]);
  } else if (Math.random() < 0.55) {
    S.fase = 'evento';
    S.evento = eventoDaVez();
    S.resultado = null;
    if (!S.evento) { S.fase = 'mercado'; S.opcoes = ofertas(2, [S.clube.nome]); }
  } else {
    S.fase = 'mercado';
    S.ultimoMercado = S.idade;
    S.opcoes = ofertas(2, [S.clube.nome]);
  }
  salvar(); render();
}

/** Aplica a carta escolhida, mostra o que saiu e segue. */
async function escolherCarta(i){
  const carta = S.evento.cartas[i];
  const r = Math.random() * 100;
  let acc = 0, ef = carta.efeitos[carta.efeitos.length - 1];
  for (const e of carta.efeitos) { acc += e.chance; if (r <= acc) { ef = e; break; } }

  // A ANIMAÇÃO VEM ANTES do resultado entrar no estado. Antes eu gravava
  // S.resultado e chamava render(): a tela já nascia com a escolhida
  // destacada e o efeito marcado, e a animação virava um pisca inútil
  // por cima de algo já decidido.
  const cartaEl = document.querySelectorAll('.cartas .carta')[i];
  if (cartaEl) await animarSorteio(cartaEl, ef);

  S.resultado = {carta: i, efeito: ef};
  const antes = S.ovr;
  S.ovr = Math.max(35, Math.min(99, S.ovr + (ef.ovr || 0)));
  S.jogosBonus = ef.jogos || 0;
  salvar(); render();

  await animarOvr(antes, S.ovr);
  await dormir(900);
  S.evento = null; S.resultado = null;
  S.fase = 'jogando';
  await jogarAnos();
}

function aposentar(){ S.fase = 'fim'; S.fim = true; salvar(); render(); }

/**
 * Preenche a linha do ano, uma coluna por vez.
 *
 * Cada número conta de zero até o valor, e a próxima coluna só começa quando
 * a anterior parou — é a leitura natural do boletim: quantos jogos, e só
 * então quantos gols naqueles jogos.
 */
async function preencherLinha(idade){
  const linha = document.querySelector(`.ano[data-idade="${idade}"]`);
  if (!linha) return;
  const celulas = [...linha.querySelectorAll('.ano-n[data-alvo]')];
  // O número mora no <b> da célula, ao lado do ícone. Escrever na célula
  // inteira (textContent) apagaria o SVG junto no primeiro passo da contagem.
  const escrever = (c, v) => { const n = c.querySelector('b') || c; n.textContent = v; };
  if (semAnimacao()) { celulas.forEach(c => escrever(c, c.dataset.alvo)); return; }

  for (const c of celulas){
    const alvo = Number(c.dataset.alvo) || 0;
    c.classList.add('contando');
    const passos = Math.min(alvo, 9);
    for (let i = 1; i <= passos; i++){
      escrever(c, Math.round(alvo * i / passos));
      await dormir(30);
    }
    escrever(c, alvo);
    c.classList.remove('contando');
    c.classList.add('pronta');
    await dormir(70);
  }
}

/* ── Render ─────────────────────────────────────────── */
function render(){
  if (!S) return telaInicio();
  if (S.fase === 'fim') return telaFim();

  const cor = corDoOvr(S.ovr);
  const l = S.clube ? dadosLiga(S.clube.liga) : null;

  app().innerHTML = `
    ${barraTopo(`<button class="btn-topo" onclick="if(confirm('Abandonar esta carreira?')){apagar();S=null;telaInicio();}"><i class="bi bi-x-lg"></i> Abandonar</button>`)}
    <div class="carreira">
      <div>
        <div class="caixa ficha">
          <div class="ficha-topo">
            ${S.clube ? `<div class="ficha-marca" aria-hidden="true">${escudo(S.clube, 132)}</div>` : ''}
            <div class="ovr-caixa" style="background:${cor}">
              <small>OVR</small><b>${S.ovr}</b></div>
            <div class="ficha-info">
              <div class="ficha-clube" ${S.clube ? `title="${esc(S.clube.nome)}"` : ''}>
                ${S.clube ? escudo(S.clube, 26) + `<span>${esc(nomeCurto(S.clube.nome))}</span>`
                          : `<span style="color:var(--txt3)">Sem clube</span>`}
              </div>
              ${l ? `<div class="ficha-liga">${esc(l.nome)}</div>` : ''}
            </div>
            <div class="ficha-num">
              <div>IDADE<b>${S.idade}</b></div>
              <div>VALOR<b>${moeda(valorAtual())}</b></div>
            </div>
          </div>
          <div class="ficha-tags">
            <span class="tag">${bandeira(S.pais,17)} ${esc(S.pais)}</span>
            <span class="tag">${esc(POSICOES[S.posicao] ? POSICOES[S.posicao][0] : S.posicao)}</span>
            ${convocado(S.ovr, S.pais) ? `<span class="tag sel">★ Seleção</span>` : ''}
            ${ehIdolo() ? `<span class="tag idolo">Ídolo da casa</span>` : ''}
          </div>
          ${vitrine()}
          <div class="ficha-stats">
            ${[['Jogos','jogos'], ...colunasDoBoletim()].map(([r,k]) =>
              `<div><span>${r}</span><b>${S.temporadas.reduce((a,t)=>a+(t[k]||0),0)}</b></div>`).join('')}
          </div>
          ${blocoDecisao()}
        </div>
      </div>
      <div class="caixa linha">${linhaDoTempo()}</div>
    </div>
    <p class="rodape">Os nomes de clube servem para identificar dentro da simulação.
    Este jogo não é afiliado, patrocinado nem endossado por nenhum deles.</p>`;
}

/**
 * A vitrine de troféus.
 *
 * Agrupada por competição e com a contagem: quatro Champions viram uma taça
 * com ×4, não quatro desenhos iguais em fila. Vazia, diz que está vazia —
 * espaço em branco pareceria coisa que não carregou.
 */
function vitrine(){
  const conta = {};
  const ligaDe = {};
  (S.temporadas || []).forEach(t => (t.titulos || []).forEach(id => {
    conta[id] = (conta[id] || 0) + 1;
    if (!ligaDe[id]) ligaDe[id] = t.liga;
  }));
  const ids = Object.keys(conta);
  if (!ids.length) {
    return `<div class="vitrine vazia">${taca('copa', 26)}<span>Vitrine vazia</span></div>`;
  }
  const ordem = ['mundial','cont','liga','copa',
                 'bola_ouro','rei_america','chuteira','luva_ouro','artilheiro'];
  ids.sort((a,b) => ordem.indexOf(a) - ordem.indexOf(b));
  return `<div class="vitrine">${ids.map(id => `
    <span class="tacao" title="${esc(nomeDaTaca(id, ligaDe[id]))}">
      ${taca(id, 34)}${conta[id] > 1 ? `<b>×${conta[id]}</b>` : ''}
    </span>`).join('')}</div>`;
}

function blocoDecisao(){
  if (S.fase === 'oferta_base') {
    if (!S.opcoes) S.opcoes = ofertas(3, [], true);
    return `<div class="evento"><h3>Oferta de base</h3>
      <p>Três clubes querem te incluir no projeto de base. Escolha onde sua carreira começa.</p>
      ${cartasDeClube(S.opcoes)}</div>`;
  }
  if (S.fase === 'mercado') {
    return `<div class="evento"><h3>Janela de transferências</h3>
      <p>Chegaram ofertas depois do seu último trecho de carreira. Você pode aceitar uma ou ficar no clube.</p>
      ${cartasDeClube(S.opcoes, true)}</div>`;
  }
  if (S.fase === 'emprestimo') {
    const dono = S.clube;
    return `<div class="evento"><h3>Sem espaço no elenco</h3>
      <p>Você é jovem demais pro time principal do ${esc(dono.nome)} e o clube quer te
      emprestar pra jogar. Rodar significa minutos e evolução agora; ficar é apostar
      que você fura a fila aqui dentro.</p>
      ${(() => {
        const cs = (S.opcoes || []).map((c, i) => {
          const l = dadosLiga(c.liga);
          return `<button class="carta" onclick="aceitarEmprestimo(${i})">
            <div class="clube-op">${escudo(c, 34)}
              <span class="txt"><b>Emprestado ao ${esc(c.nome)}</b>
              <small>${l ? esc(l.nome) : ''}</small></span>
            </div></button>`;
        });
        cs.push(`<button class="carta" onclick="recusarEmprestimo()">
          <div class="clube-op">${escudo(dono, 34)}
            <span class="txt"><b>Ficar no ${esc(dono.nome)}</b>
            <small>Brigar por espaço no elenco principal</small></span>
          </div></button>`);
        return gradeDeOpcoes(cs.join(''));
      })()}</div>`;
  }
  if (S.fase === 'fim_emprestimo') {
    const dono = S.emprestadoDe, aqui = S.clube;
    const compra = emprestimoVirouCompra();
    return `<div class="evento"><h3>Fim do empréstimo</h3>
      <p>Seu vínculo com o ${esc(aqui.nome)} acabou.${compra
        ? ` O clube gostou do que viu e quer te comprar.`
        : ` O ${esc(dono.nome)} espera você de volta.`}</p>
      ${(() => {
        const cs = [];
        cs.push(`<button class="carta" onclick="voltarDoEmprestimo()">
          <div class="clube-op">${escudo(dono, 34)}
            <span class="txt"><b>Voltar ao ${esc(dono.nome)}</b>
            <small>${esc((dadosLiga(dono.liga)||{}).nome || '')}</small></span>
          </div></button>`);
        if (compra) cs.push(`<button class="carta" onclick="ficarNoEmprestimo()">
          <div class="clube-op">${escudo(aqui, 34)}
            <span class="txt"><b>Ficar no ${esc(aqui.nome)}</b>
            <small>Contrato de vez, onde você já joga</small></span>
          </div></button>`);
        (S.opcoes || []).forEach((c, i) => {
          const l = dadosLiga(c.liga);
          cs.push(`<button class="carta" onclick="aceitarEmprestimo(${i})">
            <div class="clube-op">${escudo(c, 34)}
              <span class="txt"><b>Novo empréstimo: ${esc(c.nome)}</b>
              <small>${l ? esc(l.nome) : ''}</small></span>
            </div></button>`);
        });
        return gradeDeOpcoes(cs.join(''));
      })()}</div>`;
  }
  if (S.fase === 'fim_ciclo') {
    return `<div class="evento"><h3>Fim de ciclo</h3>
      <p>Seu clube decidiu não renovar. Escolha o próximo passo da sua carreira.</p>
      ${cartasDeClube(S.opcoes, false, true)}</div>`;
  }
  if (S.fase === 'evento' && S.evento) {
    const res = S.resultado;
    return `<div class="evento"><h3>${esc(S.evento.titulo)}</h3><p>${esc(S.evento.texto)}</p>
      <div class="cartas">
        ${S.evento.cartas.map((c,i) => {
          const escolhida = res && res.carta === i;
          const cls = res ? (escolhida ? ' escolhida' : ' apagada') : '';
          return `<button class="carta${cls}" ${res?'disabled':''} onclick="escolherCarta(${i})">
            <b>${esc(c.rotulo)}</b>
            ${c.efeitos.map(e => {
              const tom = (e.ovr||0) > 0 ? 'bom' : ((e.ovr||0) < 0 || e.jogos ? 'ruim' : 'neutro');
              const sorteado = escolhida && res.efeito.texto === e.texto ? ' sorteado' : '';
              return `<span class="efeito ${tom}${sorteado}">
                <span>${esc(e.texto)}</span>
                ${e.chance < 100 ? `<span class="pct">${e.chance}%</span>` : ''}</span>`;
            }).join('')}
          </button>`;
        }).join('')}
      </div></div>`;
  }
  return '';
}

/**
 * Envolve um punhado de cartas num carrossel.
 *
 * As decisões do jogo são todas do mesmo tipo — escolher entre portas — e
 * mostrá-las lado a lado deixa a comparação inteira à vista. Já foi
 * carrossel: ficava bonito e cobrava dois cliques pra ver o que cabe na tela
 * sem clique nenhum. Quando não cabem, o próprio grid empilha.
 */
function gradeDeOpcoes(cartasHtml){
  return `<div class="carr"><div class="carr-pista">${cartasHtml}</div></div>`;
}

function cartasDeClube(lista, comFicar, comAposentar){
  const cartas = (lista || []).map((c,i) => {
    const l = dadosLiga(c.liga);
    // O aviso de clássico é o que transforma a oferta em decisão. Escondê-lo
    // seria pegadinha: quem vai pro rival tem que saber que está indo.
    const rival = S.clube && ehRival(S.clube.nome, c.nome);
    return `<button class="carta${rival ? ' rival' : ''}" onclick="assinarOpcao(${i})">
      <div class="clube-op">${escudo(c, 34)}
        <span class="txt"><b>${esc(c.nome)}</b>
        <small>${l ? esc(l.nome) : ''}${rival ? ` · <i class="av-rival">rival do ${esc(S.clube.nome)}</i>` : ''}</small></span>
      </div></button>`;
  });
  if (comFicar && S.clube) {
    cartas.push(`<button class="carta" onclick="assinar(S.clube)">
      <div class="clube-op">${escudo(S.clube, 34)}
        <span class="txt"><b>Ficar no ${esc(S.clube.nome)}</b>
        <small>${esc((dadosLiga(S.clube.liga)||{}).nome || '')}</small></span>
      </div></button>`);
  }
  if (comAposentar) {
    cartas.push(`<button class="carta" onclick="aposentar()">
      <div class="clube-op"><span style="font-size:28px;line-height:1;width:34px;text-align:center">🥾</span>
        <span class="txt"><b>Aposentar-se</b>
        <small>Encerrar sua carreira profissional</small></span>
      </div></button>`);
  }
  return gradeDeOpcoes(cartas.join(''));
}

/**
 * A seta de acesso ou queda do clube naquele ano.
 *
 * Fica na linha do ano, ao lado do nome — é ali que a pessoa lê a
 * trajetória, e uma nota em outro lugar exigiria cruzar as duas coisas.
 */
/**
 * Os selos do ano na linha do tempo: convocado e lesionado.
 *
 * Dois caracteres, porque a coluna é estreita. Sem eles a lesão continuava
 * invisível — os jogos caíam pela metade e o overall também, e não havia
 * nada na tela explicando por quê.
 */
function selosDoAno(t){
  let s = '';
  if (t.selecao) s += `<i class="selo sel" title="Convocado para a seleção">★</i>`;
  if (t.lesao)   s += `<i class="selo les" title="Lesão na temporada">✚</i>`;
  if (t.rival)   s += `<i class="selo riv" title="Primeira temporada depois de trocar pelo rival">⚡</i>`;
  if (t.emprestado) s += `<i class="selo emp" title="Temporada por empréstimo">⇄</i>`;
  return s;
}

function setaMov(mov){
  if (mov === 'sobe') return '<i class="mov sobe" title="O clube subiu de divisão">▲</i>';
  if (mov === 'cai')  return '<i class="mov cai" title="O clube caiu de divisão">▼</i>';
  return '';
}

function linhaDoTempo(){
  const porIdade = {};
  S.temporadas.forEach(t => { porIdade[t.idade] = t; });

  // O ícone desceu do cabeçalho pra cada linha: lá em cima ele se explica uma
  // vez e some da memória; do lado do número, a linha se lê sozinha em
  // qualquer altura da tabela, sem subir os olhos pra conferir a coluna.
  let html = `<div class="linha-cab"><span>Idade</span><span>Clube</span><span>OVR</span>
    <span>Jogos</span>
    ${colunasDoBoletim().map(([r]) => `<span>${r}</span>`).join('')}</div>`;

  for (let i = IDADE_INI; i < IDADE_FIM; i++) {
    const t = porIdade[i];
    const atual = !t && i === S.idade;
    if (t) {
      const c = acharClube(t.clube);
      const cor = corDoOvr(t.ovr);
      // A linha do ano que ACABOU de acontecer nasce sem números: quem os
      // coloca, um a um, é preencherLinha(). Sem isso a temporada inteira
      // aparecia pronta de uma vez, e não havia momento nenhum entre jogar
      // o ano e saber como ele foi.
      const nova = S.linhaNova === i;
      const vazio = (v) => nova ? '' : v;
      html += `<div class="ano${nova ? ' entrando' : ''}" data-idade="${i}">
        <span class="ano-idade" style="background:${corDoClube(t.clube)}">${i}</span>
        <span class="ano-clube" title="${esc(t.clube)}">${escudo(c, 20)}<span>${esc(nomeCurto(t.clube))}</span>${setaMov(t.movimento)}${selosDoAno(t)}</span>
        <span class="ano-ovr" style="background:${cor}">${t.ovr}</span>
        <span class="ano-n" data-alvo="${t.jogos}">${ICONES.jogos}<b>${vazio(t.jogos)}</b></span>${
          colunasDoBoletim().map(([,k]) => `<span class="ano-n" data-alvo="${t[k] || 0}">${ICONES[k] || ''}<b>${vazio(t[k] || 0)}</b></span>`).join('')}
      </div>`;
    } else if (atual) {
      html += `<div class="ano atual">
        <span class="ano-idade" style="background:${corDoOvr(S.ovr)}">${i}</span>
        <span class="ano-clube" style="color:var(--txt3)"><i class="bi bi-question-circle"></i>
          <span>${S.fase==='fim_ciclo'?'Decidindo…':'Escolhendo…'}</span></span>
        <span class="ano-ovr" style="background:${corDoOvr(S.ovr)}">${S.ovr}</span>
        <span></span><span></span><span></span></div>`;
    } else {
      html += `<div class="ano vazio"><span style="text-align:center">${i}</span>
        <span></span><span></span><span></span><span></span><span></span></div>`;
    }
  }
  return html;
}

/**
 * Manda a carreira como imagem, no mesmo cartão dos outros jogos.
 *
 * Os clubes e os títulos vão como as duas colunas: são as duas coisas que
 * contam a história de uma carreira, e nenhuma delas pode sumir do cartão.
 */
/**
 * SVG que já está na tela, virado imagem pro canvas.
 *
 * Bandeira e taça são desenhadas em SVG inline. Como data URI elas contam
 * como mesma origem e NÃO contaminam o canvas — é por isso que o escudo, que
 * vem de CDN, precisa do proxy, e estas não precisam de nada.
 */
const svgImagem = (svg) => 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);

function compartilharCarreira(botao, modo){
  const t = S.temporadas || [];
  const tot = t.reduce((a,x)=>({jogos:a.jogos+x.jogos, gols:a.gols+x.gols, ast:a.ast+x.ast,
                                gs:a.gs+(x.gs||0), cs:a.cs+(x.cs||0)}),
                       {jogos:0,gols:0,ast:0,gs:0,cs:0});

  // Os clubes ordenados por quantos jogos você fez em cada um: a trajetória
  // por onde a carreira aconteceu de verdade, não por onde passou de raspão.
  const porClube = {};
  t.forEach(x => { porClube[x.clube] = (porClube[x.clube] || 0) + x.jogos; });
  const clubes = Object.entries(porClube).sort((a,b) => b[1] - a[1]).slice(0, 6)
    .map(([nome]) => {
      const c = acharClube(nome);
      return c && c.escudo ? {img: c.escudo} : {texto: (nome.split(/\s+/)[0] || '?').slice(0, 4)};
    });

  const conta = {};
  t.forEach(x => (x.titulos || []).forEach(id => { conta[id] = (conta[id]||0)+1; }));
  const titulos = Object.entries(conta).sort((a,b) => b[1] - a[1]).slice(0, 6)
    .map(([id, n]) => {
      const d = TACAS[id];
      return {
        img: d ? svgImagem(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 60"
                width="140" height="132" style="color:${d[0]}">${d[1]}</svg>`) : '',
        contagem: n,
      };
    });

  const band = BAND[S.pais]
    ? svgImagem(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 20"
                 width="120" height="80">${BAND[S.pais]}</svg>`)
    : '';

  // Duas saídas, e só essas duas: baixar o arquivo ou copiar pra colar na
  // conversa. A folha de compartilhamento do sistema ficava de fora porque
  // no computador ela nem existe pra arquivo, e aí virava um botão que se
  // comportava de um jeito no celular e de outro no desktop.
  (modo === 'copiar' ? fbaCopiar : fbaBaixar)({
    c1: corDoOvr(S.picoOvr), c2: '#0a0a0c',
    numero: S.picoOvr, rotulo: 'OVR',
    pilulas: [
      band ? {img: band} : {texto: S.pais},
      {rotulo: 'Valor', texto: moeda(S.picoValor)},
      {texto: `#${S.numero}`},
      {texto: S.posicao},
    ],
    stats: ehGoleiro()
      ? [[tot.jogos, 'Jogos'], [tot.gs, 'Gols sofr.'], [tot.cs, 'Clean sheets'], [t.length, 'Temporadas']]
      : [[tot.jogos, 'Jogos'], [tot.gols, 'Gols'], [tot.ast, 'Assist.'], [t.length, 'Temporadas']],
    faixas: [
      {titulo: 'Trajetória', itens: clubes},
      {titulo: 'Títulos',    itens: titulos.length ? titulos : [{texto: '—', legenda: 'sem títulos'}]},
    ],
    nome: S.nome, jogo: 'COPERO',
  }, botao);
}

/* ── Fim ────────────────────────────────────────────── */
/**
 * A sala de troféus da carreira inteira.
 *
 * Agrupa pelo NOME resolvido, não pelo id: quem foi campeão na Alemanha e
 * na Espanha tem Bundesliga e LaLiga separadas, e não "Campeonato Nacional
 * ×2" — que era exatamente a informação que se perdia. Títulos de clube
 * primeiro, prêmios individuais depois; dentro de cada grupo, o mais
 * repetido na frente.
 */
function salaDeTrofeus(){
  // Seleção primeiro, que é o teto de uma carreira; depois clube, do maior
  // pro menor; prêmios individuais por último. Explícito de propósito: o que
  // não está na lista cai no fim, e não no começo por acidente.
  const ordem = ['copa_mundo','selecao_cont','mundial','cont','cont2','cont3','liga','copa',
                 'bola_ouro','chuteira','rei_america','luva_ouro','artilheiro'];
  const posOrdem = id => { const i = ordem.indexOf(id); return i < 0 ? 99 : i; };
  const grupos = {};
  (S.temporadas || []).forEach(t => (t.titulos || []).forEach(id => {
    const nome = nomeDaTaca(id, t.liga);
    const chave = id + '|' + nome;
    if (!grupos[chave]) grupos[chave] = {id, nome, n: 0};
    grupos[chave].n++;
  }));

  const lista = Object.values(grupos).sort((a, b) =>
    (posOrdem(a.id) - posOrdem(b.id)) || (b.n - a.n));

  if (!lista.length) {
    return `<div class="caixa sala vazia">Nenhum título na carreira</div>`;
  }
  const total = lista.reduce((s, g) => s + g.n, 0);
  return `<div class="caixa sala">
    <div class="sala-cab">Sala de troféus<b>${total}</b></div>
    <div class="sala-grade">
      ${lista.map(g => `<div class="sala-item">
        <div class="sala-taca">${taca(g.id, 46)}${g.n > 1 ? `<i>×${g.n}</i>` : ''}</div>
        <span>${esc(g.nome)}</span>
      </div>`).join('')}
    </div>
  </div>`;
}

async function telaFim(){
  const tot = S.temporadas.reduce((a,t)=>({jogos:a.jogos+t.jogos, gols:a.gols+t.gols,
                                           ast:a.ast+t.ast, gs:a.gs+(t.gs||0), cs:a.cs+(t.cs||0)}),
                                  {jogos:0,gols:0,ast:0,gs:0,cs:0});
  const porClube = {};
  S.temporadas.forEach(t => {
    if (!porClube[t.clube]) porClube[t.clube] = {jogos:0,gols:0,ast:0,gs:0,cs:0};
    porClube[t.clube].jogos += t.jogos; porClube[t.clube].gols += t.gols; porClube[t.clube].ast += t.ast;
    porClube[t.clube].gs += (t.gs || 0);  porClube[t.clube].cs += (t.cs || 0);
  });

  const cor = corDoOvr(S.picoOvr);
  app().innerHTML = `
    ${barraTopo()}
    <div class="caixa fim">
      <h2>Sua carreira chegou ao fim</h2>
      <div class="acoes-fim">
        <button class="btn" id="btnFoto" onclick="compartilharCarreira(this,'baixar')">
          <i class="bi bi-download"></i> Baixar imagem</button>
        <button class="btn" onclick="compartilharCarreira(this,'copiar')">
          <i class="bi bi-clipboard"></i> Copiar imagem</button>
        <button class="btn btn2" onclick="apagar();S=null;telaInicio()">Jogar novamente</button>
      </div>
    </div>

    <div class="resumo-topo" style="margin-top:14px">
      <div class="caixa" style="padding:18px">
        <div style="font-size:9.5px;font-weight:800;letter-spacing:1px;color:var(--txt3);text-transform:uppercase">Carreira finalizada</div>
        <div style="display:flex;align-items:center;gap:14px;margin:8px 0 14px">
          <div style="flex:1;min-width:0">
            <div style="font-size:27px;font-weight:900;letter-spacing:-1px">${esc(S.nome)}</div>
            <div class="ficha-tags" style="margin-top:6px">
              <span class="tag">#${esc(S.numero)}</span>
              <span class="tag pos">${esc(S.posicao)}</span>
              <span class="tag">${bandeira(S.pais,17)} ${esc(PAISES[S.pais]||S.pais)}</span>
            </div>
          </div>
          <div class="ovr-caixa" style="background:${cor}"><small>PICO</small><b>${S.picoOvr}</b></div>
        </div>
        <div class="ficha-stats" style="border-bottom:none;padding-bottom:0">
          <div><span>Jogos</span><b>${tot.jogos}</b></div>
          ${colunasDoBoletim().map(([r,k]) => `<div><span>${r}</span><b>${tot[k] || 0}</b></div>`).join('')}
        </div>
      </div>
      <div class="caixa" style="padding:18px;text-align:center">
        <div style="font-size:9.5px;font-weight:800;letter-spacing:1px;color:var(--txt3);text-transform:uppercase">Maior valor</div>
        <div style="font-size:31px;font-weight:900;letter-spacing:-1px;margin:8px 0">${moeda(S.picoValor)}</div>
        <div style="font-size:12px;color:var(--txt2)">${S.temporadas.length} temporadas · ${Object.keys(porClube).length} clubes</div>
      </div>
    </div>

    ${salaDeTrofeus()}

    <div class="clubes-grade">
      ${Object.entries(porClube).map(([nome,n]) => `
        <div class="clube-card">${escudo(acharClube(nome), 44)}<b>${esc(nome)}</b>
          <div class="cc-nums">
            <div><span>Jogos</span>${n.jogos}</div>
            ${colunasDoBoletim().map(([r,k]) => `<div><span>${r}</span>${n[k] || 0}</div>`).join('')}
          </div></div>`).join('')}
    </div>

    <div id="conquistas"></div>
    <p class="rodape">Os nomes de clube servem para identificar dentro da simulação.
    Este jogo não é afiliado, patrocinado nem endossado por nenhum deles.</p>`;

  // As conquistas são decididas no SERVIDOR, com os totais recalculados a
  // partir das temporadas — o resumo que o cliente desenha não vale como
  // prova do que aconteceu.
  try {
    const r = await fetch(location.pathname, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({carreira: JSON.stringify({
        nome:S.nome, numero:S.numero, posicao:S.posicao, pais:S.pais,
        temporadas:S.temporadas, idadeFinal:S.idade,
        comecouAbaixo:S.comecouAbaixo, maiorForcaClube:S.maiorForcaClube,
      })}),
    });
    const d = await r.json();
    if (d.ok && d.conquistas && d.conquistas.length) {
      // O que é NOVO vem do servidor pra quem está logado: lá está o registro
      // de verdade do que a pessoa já levou. Sem sessão, o localStorage é o
      // que dá pra usar.
      const novas = d.premio && d.premio.logado
        ? d.conquistas.filter(c => c.nova)
        : d.conquistas.filter(c => !conquistasFeitas().includes(c.id));
      guardarConquistas(d.conquistas.map(c => c.id));

      const pr = d.premio || {};
      const ganhou = (pr.moedas || 0) + (pr.fba_points || 0) > 0;
      const rotulo = {facil:'Fácil', media:'Média', dificil:'Difícil', impossivel:'Impossível'};
      document.getElementById('conquistas').innerHTML =
        `<h2 style="font-size:17px;margin:26px 0 10px">Conquistas da carreira
           <span class="conq-conta">${d.conquistas.length} de ${d.totalConquistas || '?'}</span></h2>
         ${ganhou ? `<div class="premio-fx">
           <b>Você ganhou</b>
           ${pr.moedas ? `<span class="premio-tag moeda">+${pr.moedas} moedas</span>` : ''}
           ${pr.fba_points ? `<span class="premio-tag fba">+${pr.fba_points} FBA Points</span>` : ''}
           <small>pelas conquistas inéditas — cada uma paga uma vez só</small>
         </div>` : ''}
         <div class="conq-grade">${d.conquistas.map(c => `
           <div class="conq n-${esc(c.nivel || 'facil')}${novas.some(n => n.id === c.id) ? ' nova' : ''}">
             <span class="ic">${c.icone}</span>
             <div><b>${esc(c.nome)}</b><small>${esc(c.desc)}</small></div>
             <em>${novas.some(n => n.id === c.id) ? 'NOVA' : esc(rotulo[c.nivel] || '')}</em></div>`).join('')}</div>`;
    }
  } catch (e) { /* sem rede, a carreira ainda aparece inteira */ }

  apagar();
}

telaInicio();
</script>
</body>
</html>
