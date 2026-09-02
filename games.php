<?php
/**
 * games.php — Games e Apostas dentro do fbabrasil.com.br.
 *
 * Depois da fusão o antigo subdomínio deixou de existir: o catálogo de
 * minigames e as apostas vivem aqui, no mesmo banco e na mesma sessão do
 * site. Os jogos em si continuam sendo servidos de /games/, que agora usa
 * o backend/auth.php daqui.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/atualizacoes.php';   // ranking de quem atualiza elenco alheio
require_once __DIR__ . '/backend/apostas.php';       // a conta das porcentagens, igual no site e no bot
requireAuth();

$user = getUserSession();
$pdo  = db();
$userId = (int) $user['id'];

// O cartão do time no topo da sidebar só aparece se $team existir.
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$userId]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC) ?: null;
if ($team) {
    $team['photo_url'] = getTeamPhoto($team['photo_url'] ?? null);
}

$nowBrt    = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$nowBrtStr = $nowBrt->format('Y-m-d H:i:s');

// Perfil de jogo — a linha nasce no primeiro acesso, igual ao core/conexao.php
$perfil = ['pontos' => 0, 'fba_points' => 0, 'acertos_eventos' => 0];
try {
    $st = $pdo->prepare("SELECT pontos, fba_points, acertos_eventos FROM games_usuarios WHERE id = ?");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->prepare("
            INSERT IGNORE INTO games_usuarios (id, nome, email, league, is_admin)
            SELECT id, name, email, COALESCE(league,'ROOKIE'), ? FROM users WHERE id = ?
        ")->execute([(($user['user_type'] ?? '') === 'admin') ? 1 : 0, $userId]);
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }
    if ($row) $perfil = $row;
} catch (Throwable $e) {
    error_log('[games.php] perfil: ' . $e->getMessage());
}

// ── Registrar palpite ───────────────────────────────────────────────────────
$apostaMsg = null;
$apostaErro = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['opcao_id'])) {
    try {
        $opcaoId = (int) $_POST['opcao_id'];
        if ($opcaoId <= 0) throw new Exception('Escolha inválida.');

        $pdo->beginTransaction();
        $stC = $pdo->prepare("
            SELECT e.id AS evento_id, e.status, e.data_limite
            FROM opcoes o JOIN eventos e ON o.evento_id = e.id
            WHERE o.id = ?
        ");
        $stC->execute([$opcaoId]);
        $ev = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$ev) throw new Exception('Opção inválida.');
        if ($ev['status'] !== 'aberta') throw new Exception('Esse evento já encerrou.');
        if (new DateTime($ev['data_limite'], new DateTimeZone('America/Sao_Paulo')) < $nowBrt) {
            throw new Exception('O prazo desse evento já passou.');
        }

        $stD = $pdo->prepare("
            SELECT p.id FROM palpites p JOIN opcoes o ON p.opcao_id = o.id
            WHERE p.id_usuario = ? AND o.evento_id = ? LIMIT 1
        ");
        $stD->execute([$userId, $ev['evento_id']]);
        $existente = $stD->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            $pdo->prepare("UPDATE palpites SET opcao_id = ?, valor = 1, odd_registrada = 1, data_palpite = NOW() WHERE id = ?")
                ->execute([$opcaoId, $existente['id']]);
            $apostaMsg = 'Palpite atualizado.';
        } else {
            $pdo->prepare("INSERT INTO palpites (id_usuario, opcao_id, valor, odd_registrada, data_palpite) VALUES (?, ?, 1, 1, NOW())")
                ->execute([$userId, $opcaoId]);
            $apostaMsg = 'Palpite registrado.';
        }
        $pdo->commit();

        // AS PORCENTAGENS DEPOIS DO CLIQUE, pra tela se atualizar sem
        // recarregar. Recontar aqui é o que permite o botão responder na
        // hora — e recontar DEPOIS do commit é o que faz o número já incluir
        // o palpite que acabou de entrar.
        $apostaEvento = (int)$ev['evento_id'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $apostaErro = $e->getMessage();
    }

    // RESPOSTA CURTA PRO JS, e a página inteira pra quem não tem JS.
    //
    // O formulário continua um <form> de verdade: sem JS, o clique recarrega
    // a página e funciona como sempre funcionou. Com JS, o mesmo endpoint
    // devolve só os números e a tela se conserta sozinha — era o recarregar
    // que fazia a aba parecer travada, porque cada palpite remontava a
    // página inteira, com ranking, minigames e tudo.
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        if ($apostaErro) {
            echo json_encode(['ok' => false, 'erro' => $apostaErro]);
            exit;
        }
        $opcoes = [];
        try {
            $stR = $pdo->prepare("
                SELECT o.id, COUNT(p.id) AS n
                  FROM opcoes o LEFT JOIN palpites p ON p.opcao_id = o.id
                 WHERE o.evento_id = ?
                 GROUP BY o.id ORDER BY o.id ASC
            ");
            $stR->execute([$apostaEvento]);
            $linhas = $stR->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Mesmo maior-resto do desenho inicial — e agora literalmente a
            // mesma função (backend/apostas.php). Se as duas contas não
            // fossem iguais, a soma daria 100 ao carregar e 101 depois de
            // clicar, que é o tipo de coisa que parece bug do clique.
            $contagem = [];
            foreach ($linhas as $l) $contagem[(int)$l['id']] = (int)$l['n'];
            $total = array_sum($contagem);
            $pct   = apostasPercentuais($contagem);
            foreach ($linhas as $i => $l) {
                $id = (int)$l['id'];
                $opcoes[$i] = ['id' => $id, 'pct' => $pct[$id] ?? 0, 'n' => (int)$l['n']];
            }
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => 'Não deu pra recontar os palpites.']);
            exit;
        }
        echo json_encode(['ok' => true, 'msg' => $apostaMsg, 'evento' => $apostaEvento,
                          'escolhida' => $opcaoId, 'total' => $total,
                          'opcoes' => array_values($opcoes)]);
        exit;
    }
}

// ── Loja: comprar, usar e converter ─────────────────────────────────────────
require_once __DIR__ . '/backend/loja.php';
$lojaMsg = null;
$lojaErro = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['loja_acao'])) {
    $acao = (string)$_POST['loja_acao'];
    if ($acao === 'comprar') {
        $r = lojaComprar($pdo, $userId, (string)($_POST['item'] ?? ''));
        // O que o sistema aplica sozinho não vai pra "Meus itens": dizer que
        // está lá mandaria o GM procurar uma coisa que já valeu.
        if ($r['ok']) {
            $lojaMsg = !empty($r['aplicado'])
                ? 'Comprado e já aplicado no seu time — não precisa resgatar nem esperar aprovação.'
                : 'Comprado! O item está em Meus itens.';
        } else { $lojaErro = $r['erro']; }
    } elseif ($acao === 'usar') {
        $r = lojaUsar($pdo, $userId, (int)($_POST['inventario_id'] ?? 0));
        if ($r['ok']) { $lojaMsg = 'Resgatado. A organização foi avisada e vai aplicar.'; }
        else          { $lojaErro = $r['erro']; }
    } elseif ($acao === 'converter') {
        $r = lojaConverter($pdo, $userId, (int)($_POST['moedas'] ?? 0));
        if ($r['ok']) { $lojaMsg = "Trocou {$r['convertidas']} moedas por {$r['ganhos']} FBA Points."; }
        else          { $lojaErro = $r['erro']; }
    } elseif ($acao === 'slot_tela') {
        require_once __DIR__ . '/backend/slots_tela.php';
        $r = slotsTelaComprar(
            $pdo, $userId,
            (int)($_POST['team_id'] ?? 0),
            (string)($_POST['liga'] ?? '')
        );
        if ($r['ok']) {
            $lojaMsg = 'Slot comprado! Seu time aparece na tela da live'
                     . ($r['restam'] > 0 ? ' — sobraram ' . $r['restam'] . '.' : ' — era o último.');
        } else { $lojaErro = $r['erro']; }
    } elseif ($acao === 'leilao_semana') {
        require_once __DIR__ . '/backend/leilao_semana.php';
        $r = leilaoSemanaOfertar(
            $pdo, $userId,
            (int)($_POST['team_id'] ?? 0),
            (string)($_POST['liga'] ?? ''),
            (int)($_POST['valor'] ?? 0)
        );
        if ($r['ok']) { $lojaMsg = 'Lance registrado! Enquanto você estiver entre os dois maiores, os FBA Points ficam com a liga.'; }
        else          { $lojaErro = $r['erro']; }
    }
    // O saldo do topo tem que refletir a compra que acabou de acontecer.
    try {
        $st = $pdo->prepare("SELECT pontos, fba_points, acertos_eventos FROM games_usuarios WHERE id = ?");
        $st->execute([$userId]);
        if ($linha = $st->fetch(PDO::FETCH_ASSOC)) $perfil = $linha;
    } catch (Throwable $e) {}
}
$lojaItens   = lojaInventario($pdo, $userId);
$lojaFila    = lojaPedidos($pdo, $userId);
$lojaCat     = lojaCatalogo();
// Quanto ainda cabe de cada item limitado. Vem do servidor junto com o resto
// da aba: sem isso o card diria "Comprar" pra quem já estourou o limite, e o
// erro só apareceria depois do clique.
$lojaLimites = lojaLimites($pdo, $userId);

// ── Eventos abertos ─────────────────────────────────────────────────────────
$eventos = [];
try {
    $stE = $pdo->prepare("SELECT id, nome, data_limite FROM eventos WHERE status = 'aberta' AND data_limite > ? ORDER BY data_limite ASC LIMIT 50");
    $stE->execute([$nowBrtStr]);
    $eventos = $stE->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($eventos as &$ev) {
        $stO = $pdo->prepare("SELECT id, descricao, img_url FROM opcoes WHERE evento_id = ? ORDER BY id ASC");
        $stO->execute([$ev['id']]);
        $ev['opcoes'] = $stO->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // QUANTOS PALPITARAM EM CADA OPÇÃO.
        //
        // A conta sai daqui e não de um COUNT por opção dentro do laço de
        // desenho: são até 50 eventos na tela, e com seis opções cada isso
        // seriam trezentas consultas pra montar uma página.
        $stQ = $pdo->prepare("
            SELECT p.opcao_id, COUNT(*) AS n
              FROM palpites p JOIN opcoes o ON p.opcao_id = o.id
             WHERE o.evento_id = ?
             GROUP BY p.opcao_id
        ");
        $stQ->execute([$ev['id']]);
        $porOpcao = [];
        foreach ($stQ->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
            $porOpcao[(int)$l['opcao_id']] = (int)$l['n'];
        }

        // A soma tem que fechar em 100, e a conta que faz isso mora em
        // backend/apostas.php — a mesma que o recontar do clique e o /apostas
        // do bot usam. Ela estava escrita aqui e no bloco do clique, e as duas
        // precisavam concordar pra soma não mudar de 100 pra 101 no meio.
        $contagem = [];
        foreach ($ev['opcoes'] as $op) $contagem[(int)$op['id']] = $porOpcao[(int)$op['id']] ?? 0;
        $ev['total_palpites'] = array_sum($contagem);
        $pct = apostasPercentuais($contagem);
        foreach ($ev['opcoes'] as &$op) {
            $id = (int)$op['id'];
            $op['palpites'] = $contagem[$id];
            $op['pct']      = $pct[$id] ?? 0;
        }
        unset($op);

        $stM = $pdo->prepare("
            SELECT p.opcao_id FROM palpites p JOIN opcoes o ON p.opcao_id = o.id
            WHERE p.id_usuario = ? AND o.evento_id = ? LIMIT 1
        ");
        $stM->execute([$userId, $ev['id']]);
        $ev['meu_palpite'] = (int) ($stM->fetchColumn() ?: 0);

        // Prazo em linguagem de quem lê ("faltam 3h"), e marca urgência quando
        // falta menos de um dia — é o que decide se a pessoa age agora.
        $limite = new DateTime($ev['data_limite'], new DateTimeZone('America/Sao_Paulo'));
        $faltam = $nowBrt->diff($limite);
        $horas  = ($faltam->days * 24) + $faltam->h;
        $ev['urgente'] = $horas < 24;
        // "faltam 1 dia" nao existe: o verbo concorda com o numero. Estava assim
        // desde sempre, e a mesma frase acabou de ser copiada pro /apostas
        // do bot — dava pra levar o erro junto.
        if ($faltam->days > 0)      $ev['prazo_txt'] = ($faltam->days === 1 ? 'falta 1 dia' : "faltam {$faltam->days} dias");
        elseif ($faltam->h > 0)     $ev['prazo_txt'] = "faltam {$faltam->h}h";
        elseif ($faltam->i > 0)     $ev['prazo_txt'] = "faltam {$faltam->i} min";
        else                        $ev['prazo_txt'] = 'encerrando';
    }
    unset($ev);
} catch (Throwable $e) {
    $eventos = [];
}

// ── A cara de cada opção ────────────────────────────────────────────────────
//
// As opções de aposta são gente e time da própria liga — "Kobe Bryant",
// "Lakers" —, então a foto já existe no banco e só não estava sendo usada.
// Sem ela, seis retângulos de texto se parecem todos; com ela, a pessoa
// reconhece quem é antes de ler.
//
// A ORDEM DE PREFERÊNCIA importa: a img_url gravada na opção ganha de tudo,
// porque é escolha explícita de quem criou o evento e pode ser justamente uma
// exceção ("Algum Legend" com uma arte). Só quando ela não existe é que o
// nome é procurado no elenco.
//
// Duas consultas no total, e não uma por opção: são até 50 eventos com seis
// opções cada, e trezentas consultas pra desenhar uma página é o tipo de
// coisa que a gente só descobre quando a liga cresce.
$caraDaOpcao = [];   // nome em minúsculas => ['url' => ..., 'tipo' => 'jogador'|'time']
try {
    $nomes = [];
    foreach ($eventos as $ev) {
        foreach ($ev['opcoes'] as $op) {
            $n = trim((string)$op['descricao']);
            if ($n !== '') $nomes[mb_strtolower($n)] = $n;
        }
    }
    if ($nomes) {
        $lista  = array_values($nomes);
        $marcas = implode(',', array_fill(0, count($lista), '?'));

        // Time primeiro, jogador depois: se um nome existir nos dois, o
        // jogador ganha — é o caso comum numa pergunta de aposta.
        $stT = $pdo->prepare("SELECT name, photo_url FROM teams WHERE name IN ($marcas)");
        $stT->execute($lista);
        foreach ($stT->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
            if (!empty($t['photo_url'])) {
                $caraDaOpcao[mb_strtolower($t['name'])] = ['url' => $t['photo_url'], 'tipo' => 'time'];
            }
        }

        $stP = $pdo->prepare("SELECT name, nba_player_id, foto_adicional FROM players WHERE name IN ($marcas)");
        $stP->execute($lista);
        foreach ($stP->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $url = null;
            $fa = trim((string)($p['foto_adicional'] ?? ''));
            if ($fa !== '') {
                $url = preg_match('#^(https?://|data:image/)#', $fa) ? $fa : '/' . ltrim($fa, '/');
            } elseif (!empty($p['nba_player_id'])) {
                $url = "https://cdn.nba.com/headshots/nba/latest/260x190/{$p['nba_player_id']}.png";
            }
            if ($url) $caraDaOpcao[mb_strtolower($p['name'])] = ['url' => $url, 'tipo' => 'jogador'];
        }
    }
} catch (Throwable $e) {
    // Sem cara nenhuma a tela continua inteira — a foto é enfeite, não dado.
    $caraDaOpcao = [];
}

// ── Meus palpites ───────────────────────────────────────────────────────────
$historico = [];
try {
    // Sem LIMIT 40. A busca e o filtro trabalham em cima da lista inteira —
    // filtrar quarenta linhas e chamar isso de busca seria mentir, porque o
    // que a pessoa procura costuma ser justamente o palpite velho. O teto de
    // 500 existe só como freio: quem passar disso tem uma tela lenta, não
    // uma tela errada, e ninguém na liga chegou perto.
    $stH = $pdo->prepare("
        SELECT p.data_palpite, o.descricao AS escolha, e.nome AS evento,
               e.status AS evento_status, e.vencedor_opcao_id, p.opcao_id
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        WHERE p.id_usuario = ?
        ORDER BY p.data_palpite DESC LIMIT 500
    ");
    $stH->execute([$userId]);
    $historico = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $historico = [];
}

// ── O placar dos palpites ───────────────────────────────────────────────────
//
// Sai do histórico já carregado, e não de mais um punhado de consultas: a
// lista inteira já está aqui, e contar em PHP o que cabe na memória é mais
// barato que voltar ao banco cinco vezes pra perguntar coisas sobre ela.
//
// O resultado de cada palpite é decidido num lugar só, aqui, porque a tabela
// abaixo precisava da mesma regra e ter as duas cópias era garantir que uma
// ia divergir da outra no dia em que "encerrada" virasse outra coisa.
$placar = ['total' => 0, 'acertos' => 0, 'erros' => 0, 'abertos' => 0,
           'aproveitamento' => 0, 'sequencia' => 0, 'melhor_sequencia' => 0];
foreach ($historico as &$h) {
    $decidido = ($h['evento_status'] === 'encerrada' && $h['vencedor_opcao_id'] !== null);
    $h['resultado'] = !$decidido ? 'aberto'
        : (((int)$h['vencedor_opcao_id'] === (int)$h['opcao_id']) ? 'acertou' : 'errou');
    $placar['total']++;
    if ($h['resultado'] === 'acertou')      $placar['acertos']++;
    elseif ($h['resultado'] === 'errou')    $placar['erros']++;
    else                                    $placar['abertos']++;
}
unset($h);

$decididos = $placar['acertos'] + $placar['erros'];
$placar['aproveitamento'] = $decididos > 0
    ? (int)round($placar['acertos'] * 100 / $decididos) : 0;

// A SEQUÊNCIA vai do mais antigo pro mais novo, e por isso o array é lido de
// trás pra frente: $historico vem em ordem decrescente de data, e contar
// "quantos acertos seguidos" na ordem errada dá a sequência mais ANTIGA em
// vez da atual. Palpite ainda em aberto não corta nem soma — não se sabe
// ainda o que ele é.
$corrente = 0;
foreach (array_reverse($historico) as $h) {
    if ($h['resultado'] === 'aberto') continue;
    if ($h['resultado'] === 'acertou') {
        $corrente++;
        $placar['melhor_sequencia'] = max($placar['melhor_sequencia'], $corrente);
    } else {
        $corrente = 0;
    }
}
$placar['sequencia'] = $corrente;

// ── Estado dos jogos diários ────────────────────────────────────────────────
// O que mais falta na página é saber, de relance, o que já foi jogado hoje —
// sem isso a pessoa precisa entrar em cada um pra descobrir. Cada jogo guarda
// isso na sua própria tabela, então é uma consulta por jogo (todas por índice,
// baratas). Falha em qualquer uma não derruba a página: fica "não sei".
$hoje = $nowBrt->format('Y-m-d');

/** Retorna true/false se der pra saber, null se a tabela não responder. */
function jogouHoje(PDO $pdo, string $sql, array $params): ?bool {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

$statusDiario = [
    'termo'     => jogouHoje($pdo, "SELECT 1 FROM termo_historico WHERE id_usuario=? AND data_jogo=? LIMIT 1", [$userId, $hoje]),
    'memoria'   => jogouHoje($pdo, "SELECT 1 FROM memoria_historico WHERE id_usuario=? AND data_jogo=? AND status<>'jogando' LIMIT 1", [$userId, $hoje]),
    'bomba'     => jogouHoje($pdo, "SELECT 1 FROM bomba_historico WHERE id_usuario=? AND data_jogo=? AND status<>'jogando' LIMIT 1", [$userId, $hoje]),
    // concluido_em é gravado tanto no acerto quanto ao esgotar as 8 tentativas
    'quemsoueu' => jogouHoje($pdo, "SELECT 1 FROM quemsoueu_partidas WHERE id_usuario=? AND data_jogo=? AND concluido_em IS NOT NULL LIMIT 1", [$userId, $hoje]),
    // O quiz não tem tabela de partida: jogar é ter votado na pergunta do dia.
    'quizdodia' => jogouHoje($pdo, "SELECT 1 FROM quiz_votos v JOIN quiz_perguntas p ON p.id = v.pergunta_id
                                    WHERE v.id_usuario=? AND p.data_uso=? LIMIT 1", [$userId, $hoje]),
];

// Sequências: só valem se a última partida foi hoje ou ontem.
$ontem = (clone $nowBrt)->modify('-1 day')->format('Y-m-d');
$streaks = ['termo' => 0, 'memoria' => 0];
foreach (['termo' => 'termo_historico', 'memoria' => 'memoria_historico'] as $jogo => $tabela) {
    try {
        $st = $pdo->prepare("SELECT data_jogo, streak_count FROM {$tabela} WHERE id_usuario=? ORDER BY data_jogo DESC LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['data_jogo'], [$hoje, $ontem], true)) {
            $streaks[$jogo] = (int)($row['streak_count'] ?? 0);
        }
    } catch (Throwable $e) {}
}

// O denominador é o número de jogos diários do catálogo, sempre. As tabelas
// de histórico são criadas na primeira jogada, e o null de "tabela ainda não
// existe" tirava o jogo da conta — a página mostrava 0/4 com 5 jogos na tela.
$diariosFeitos = count(array_filter($statusDiario, fn($v) => $v === true));
$diariosTotal  = count($statusDiario);

// ── Ranking ─────────────────────────────────────────────────────────────────
// Um SELECT só traz todo mundo; a separação Geral / por liga é feita em PHP,
// porque são os mesmos dados vistos de ângulos diferentes.
// A porcentagem sai de acertos ÷ palpites em eventos que já encerraram —
// palpite de evento aberto ainda não é acerto nem erro.
$rankingBase = [];
try {
    $stmtRk = $pdo->query("
        SELECT g.id, g.pontos, g.fba_points, g.acertos_eventos,
               u.name AS gm, u.photo_url, COALESCE(u.league, 'ROOKIE') AS league,
               (
                   SELECT COUNT(*)
                   FROM palpites p
                   JOIN opcoes o ON o.id = p.opcao_id
                   JOIN eventos e ON e.id = o.evento_id
                   WHERE p.id_usuario = g.id
                     AND e.status = 'encerrada'
                     AND e.vencedor_opcao_id IS NOT NULL
               ) AS palpites_resolvidos
        FROM games_usuarios g
        JOIN users u ON u.id = g.id
    ");
    foreach ($stmtRk->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $resolvidos = (int)$r['palpites_resolvidos'];
        $acertos    = (int)$r['acertos_eventos'];
        $rankingBase[] = [
            'gm'         => $r['gm'],
            'foto'       => getUserPhoto($r['photo_url'] ?? null),
            'league'     => $r['league'],
            'sou_eu'     => (int)$r['id'] === $userId,
            'pontos'     => (int)$r['pontos'],
            'fba_points' => (int)$r['fba_points'],
            'acertos'    => $acertos,
            'resolvidos' => $resolvidos,
            'pct'        => $resolvidos > 0 ? round($acertos * 100 / $resolvidos, 1) : null,
        ];
    }
} catch (Throwable $e) {
    error_log('[games.php] ranking: ' . $e->getMessage());
}

$rankingLigas = ['GERAL' => 'Geral', 'ELITE' => 'ELITE', 'NEXT' => 'NEXT', 'RISE' => 'RISE', 'ROOKIE' => 'ROOKIE'];

/**
 * Ordena por um critério e devolve o top N MAIS a linha de quem está vendo.
 *
 * O top sozinho é um ranking de outras pessoas. Quem está em 23º abria a aba
 * e não se via em lugar nenhum — nem a posição, nem o quanto falta pro
 * próximo. Agora a lista continua sendo o top, e a sua linha vem junto,
 * separada, quando você não está nele.
 *
 * @return array{lista:array, eu:?array, total:int}
 */
function rankingComigo(array $base, string $liga, string $criterio, int $limite = 15): array {
    $ordenada = rankingOrdenar($base, $liga, $criterio);
    $lista = array_slice($ordenada, 0, $limite);

    // A posição é achada na lista JÁ ORDENADA, e não contada à parte: se as
    // duas contas divergissem um dia, a pessoa apareceria em "12º" logo
    // abaixo de quem está em 15º.
    $eu = null;
    foreach ($ordenada as $i => $r) {
        if (empty($r['sou_eu'])) continue;
        if ($i >= $limite) $eu = $r + ['pos' => $i + 1];
        break;
    }
    return ['lista' => $lista, 'eu' => $eu, 'total' => count($ordenada)];
}

/** A ordenação, sozinha. Fica separada porque o rankingComigo precisa da
    lista INTEIRA pra achar a posição de quem está fora do top. */
function rankingOrdenar(array $base, string $liga, string $criterio): array {
    $lista = $liga === 'GERAL' ? $base : array_values(array_filter($base, fn($r) => $r['league'] === $liga));
    usort($lista, function ($a, $b) use ($criterio) {
        if ($criterio === 'pct') {
            // Sem palpite resolvido não entra na disputa de porcentagem.
            if ($a['pct'] === null && $b['pct'] === null) return 0;
            if ($a['pct'] === null) return 1;
            if ($b['pct'] === null) return -1;
            if ($a['pct'] === $b['pct']) return $b['acertos'] <=> $a['acertos'];
            return $b['pct'] <=> $a['pct'];
        }
        return $b[$criterio] <=> $a[$criterio];
    });
    if ($criterio === 'pct') {
        $lista = array_values(array_filter($lista, fn($r) => $r['pct'] !== null));
    }
    return $lista;
}

$rankingCriterios = [
    'pontos'     => ['label' => 'Moedas',     'icone' => 'bi-coin',       'cor' => '#f59e0b', 'sufixo' => ''],
    'fba_points' => ['label' => 'FBA Points', 'icone' => 'bi-star-fill',  'cor' => '#fc0025', 'sufixo' => ''],
    'acertos'    => ['label' => 'Acertos',    'icone' => 'bi-trophy-fill','cor' => '#22c55e', 'sufixo' => ''],
    'pct'        => ['label' => '% de acerto','icone' => 'bi-percent',    'cor' => '#3b82f6', 'sufixo' => '%'],
];

// ── Catálogo de minigames ───────────────────────────────────────────────────
$jogosDiarios = [
    ['key' => 'termo',     'nome' => 'Termo',       'sub' => 'Adivinhe a palavra',  'icone' => 'bi-fonts',            'cor' => '#22c55e'],
    ['key' => 'memoria',   'nome' => 'Memória',     'sub' => 'Ache os pares',       'icone' => 'bi-grid-3x3-gap-fill','cor' => '#a855f7'],
    ['key' => 'bomba',     'nome' => 'Bomba',       'sub' => 'Ache os diamantes',   'icone' => 'bi-gem',              'cor' => '#ef4444'],
    ['key' => 'quemsoueu', 'nome' => 'Quem Sou Eu?','sub' => 'Descubra pelas dicas','icone' => 'bi-question-circle',  'cor' => '#3b82f6'],
    ['key' => 'quizdodia', 'nome' => 'Quiz do Dia', 'sub' => 'Vote com a maioria', 'icone' => 'bi-chat-square-quote','cor' => '#eab308'],
];
// O Copero é o primeiro da lista porque é o mais fundo dos daqui — uma
// carreira que continua de onde parou, meses depois — e porque a seção
// "Carreira" só pra ele era um título com um card debaixo, o que fazia a
// aba parecer mais cheia de divisórias do que de jogos.
//
// Ele traz `href` em vez de `key`: é uma página inteira em /games/games/ e
// não passa pelo carregador index.php?game=. O laço lá embaixo aceita os
// dois, e é isso que permite os dois tipos morarem na mesma grade.
//
// The Journey não está aqui: ele ainda está sendo ajustado e não entra no
// lançamento. A página continua de pé em /games/games/thejourney.php pra quem
// tem o link (o /caminho.php antigo redireciona pra lá).
$jogosLivres = [
    ['href' => '/games/games/copero.php', 'nome' => 'Copero',
     'sub'  => 'Uma carreira no futebol', 'icone' => 'bi-trophy-fill', 'cor' => '#22c55e'],
    // A Copa é do momento: enquanto tem uma rolando, ela é o que a galera vem
    // fazer. Fica logo no começo por isso, e não por ser nova.
    ['href' => '/games/games/copamundo.php', 'nome' => 'Copa do Mundo',
     'sub'  => 'Vote e decida o campeão', 'icone' => 'bi-diagram-3-fill', 'cor' => '#f59e0b'],
    ['key' => 'buildplayer','nome' => 'Build-A-Player','sub' => 'Monte a lenda perfeita','icone' => 'bi-tools',      'cor' => '#f97316'],
    ['key' => 'dreamteam', 'nome' => 'Starting5x5', 'sub' => 'Monte o time e dispute','icone' => 'bi-people-fill',  'cor' => '#6366f1'],
    ['key' => 'flappy',    'nome' => 'Flappy Bird', 'sub' => 'Desvie dos canos',  'icone' => 'bi-airplane',       'cor' => '#f43f5e'],
    ['key' => 'pinguim',   'nome' => 'Pinguim Run', 'sub' => 'Corra e ganhe',     'icone' => 'bi-snow',           'cor' => '#38bdf8'],
    ['key' => 'acerteacesta', 'nome' => 'Lance Livre', 'sub' => 'Solte no verde',  'icone' => 'bi-basket2-fill',  'cor' => '#f5c542'],
    ['key' => 'blackjack', 'nome' => 'Blackjack',   'sub' => 'Chegue a 21',       'icone' => 'bi-suit-heart-fill','cor' => '#ef4444'],
    ['key' => 'roleta',    'nome' => 'Roleta',      'sub' => 'Cassino europeu',   'icone' => 'bi-record-circle',  'cor' => '#22c55e'],
];

// A aba Eventos é de todos: quem CRIA é só o admin geral (a API barra), mas
// apostar é da liga inteira — sem isso os eventos ficavam sem apostador,
// porque quem cria não pode apostar no próprio.
$ehAdminGeral = ($user['user_type'] ?? '') === 'admin';
$abasValidas = ['games', 'apostas', 'loja', 'ranking', 'eventos'];
$abaInicial = 'games';
if (isset($_GET['aba'])) {
    // `banca` era o nome antigo da aba. Continua abrindo, porque link já
    // mandado no grupo não deve virar uma página em branco.
    $pedida = $_GET['aba'] === 'banca' ? 'eventos' : $_GET['aba'];
    if (in_array($pedida, $abasValidas, true)) $abaInicial = $pedida;
}
if ($apostaMsg || $apostaErro) $abaInicial = 'apostas';
// Depois de comprar ou usar, a pagina volta na LOJA e nao na aba de
// origem — senao a compra some da vista e parece que nao aconteceu.
if ($lojaMsg || $lojaErro) $abaInicial = 'loja';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#fc0025">
<title>Games - FBA Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<style>
    :root {
        --red:#fc0025; --red-soft:rgba(252,0,37,.10); --border-red:rgba(252,0,37,.22);
        --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
        --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
        --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
        --amber:#f59e0b; --green:#22c55e; --blue:#3b82f6;
        --sidebar-w:260px; --font:'Montserrat',sans-serif;
        --radius:14px; --radius-sm:10px; --radius-xs:6px;
        --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
    }
    :root[data-theme="light"] {
        --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
        --border:#e3e6ee; --border-md:#d7dbe6; --text:#111217;
        --text-2:#5b6270; --text-3:#657080;
    }
    *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
    html,body { height:100%; }
    body { font-family:var(--font); background:var(--bg); color:var(--text); -webkit-font-smoothing:antialiased; }
    .app { display:flex; min-height:100vh; }
        .sidebar { position:fixed; top:0; left:0; width:260px; height:100vh; background:var(--panel); border-right:1px solid var(--border); display:flex; flex-direction:column; z-index:300; overflow-y:auto; scrollbar-width:none; transition:transform var(--t) var(--ease); }
        .sidebar::-webkit-scrollbar { display:none; }
        .sb-brand { padding:22px 18px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:12px; flex-shrink:0; }
        .sb-logo { width:34px; height:34px; border-radius:9px; background:var(--red); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; color:#fff; flex-shrink:0; }
        .sb-brand-text { font-weight:700; font-size:15px; line-height:1.1; }
        .sb-brand-text span { display:block; font-size:11px; font-weight:400; color:var(--text-2); }
        .sb-team { margin:14px 14px 0; background:var(--panel-2); border:1px solid var(--border); border-radius:var(--radius-sm); padding:14px; display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .sb-team img { width:40px; height:40px; border-radius:9px; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
        .sb-team-name { font-size:13px; font-weight:600; color:var(--text); line-height:1.2; }
        .sb-team-league { font-size:11px; color:var(--red); font-weight:600; }
        .sb-nav { flex:1; padding:12px 10px 8px; }
        .sb-section { font-size:10px; font-weight:600; letter-spacing:1.2px; text-transform:uppercase; color:var(--text-3); padding:12px 10px 6px; }
        .sb-nav a { font-family:'Inter',sans-serif; display:flex; align-items:center; gap:10px; padding:10px 10px; border-radius:var(--radius-sm); color:var(--text-2); font-size:13px; font-weight:500; text-decoration:none; margin-bottom:2px; transition:all var(--t) var(--ease); }
        .sb-nav a i { font-size:15px; width:18px; text-align:center; flex-shrink:0; }
        .sb-nav a:hover { background:var(--panel-2); color:var(--text); }
        .sb-nav a.active { background:var(--red-soft); color:var(--red); font-weight:600; }
        .sb-nav a.active i { color:var(--red); }
        .sb-footer { padding:12px 14px; border-top:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .sb-avatar { width:30px; height:30px; border-radius:50%; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
        .sb-username { font-size:12px; font-weight:500; color:var(--text); flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sb-logout { width:26px; height:26px; border-radius:7px; background:transparent; border:1px solid var(--border); color:var(--text-2); display:flex; align-items:center; justify-content:center; font-size:12px; cursor:pointer; transition:all var(--t) var(--ease); text-decoration:none; flex-shrink:0; }
        .sb-logout:hover { background:var(--red-soft); border-color:var(--red); color:var(--red); }
        .sb-theme-toggle { margin:0 14px 12px; padding:8px 10px; border-radius:10px; border:1px solid var(--border); background:var(--panel-2); color:var(--text); display:flex; align-items:center; justify-content:center; gap:8px; font-size:12px; font-weight:600; cursor:pointer; transition:all var(--t) var(--ease); }
        .sb-theme-toggle:hover { border-color:var(--border-red); color:var(--red); }
    .main { flex:1; margin-left:var(--sidebar-w); display:flex; flex-direction:column; min-width:0; }

    .page-hero { padding:28px 32px 0; }
    .page-eyebrow { font-size:11px; font-weight:600; letter-spacing:1.4px; text-transform:uppercase; color:var(--red); margin-bottom:4px; }
    .page-title { font-size:26px; font-weight:800; line-height:1.1; display:flex; align-items:center; gap:10px; }
    .page-title i { color:var(--red); }
    .page-sub { font-size:13px; color:var(--text-2); margin-top:4px; }
    .content { padding:24px 32px 56px; flex:1; }

    .topbar { display:none; position:fixed; top:0; left:0; right:0; height:54px; background:var(--panel); border-bottom:1px solid var(--border); align-items:center; padding:0 16px; gap:12px; z-index:260; }
    .topbar-title { font-weight:700; font-size:15px; flex:1; }
    .topbar-title em { color:var(--red); font-style:normal; }
    .menu-btn { background:none; border:none; color:var(--text); font-size:22px; cursor:pointer; }
    .sb-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:270; }
    .sb-overlay.open { display:block; }

    /* saldos */
    .saldos { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:22px; }
    .saldo { display:flex; align-items:center; gap:9px; background:var(--panel); border:1px solid var(--border-md);
             border-radius:var(--radius-sm); padding:10px 16px; }
    .saldo i { font-size:17px; }
    .saldo-val { font-size:17px; font-weight:800; line-height:1; }
    .saldo-lbl { font-size:10.5px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--text-3); margin-top:2px; }

    /* abas */
    /* Com seis abas elas não cabem num celular. Rolam dentro da própria fita
       em vez de empurrar a página inteira pro lado. */
    .g-tabs { display:flex; border-bottom:1px solid var(--border); margin-bottom:26px;
              overflow-x:auto; scrollbar-width:none; }
    .g-tabs::-webkit-scrollbar { display:none; }
    .g-tabs .g-tab { flex:0 0 auto; }
    .g-tab { display:flex; align-items:center; gap:8px; padding:12px 24px; font-size:13px; font-weight:600;
             color:var(--text-2); cursor:pointer; border:none; background:none; position:relative;
             font-family:var(--font); transition:color var(--t) var(--ease); }
    .g-tab::after { content:''; position:absolute; bottom:-1px; left:0; right:0; height:2px; background:transparent;
                    transition:background var(--t) var(--ease); }
    .g-tab.active { color:var(--text); }
    .g-tab.active::after { background:var(--red); }
    .g-pane { display:none; }
    .g-pane.active { display:block; }

    /* AS CINCO ABAS CABEM NO CELULAR, sem arrastar pro lado.
       Com ícone e texto lado a lado elas somavam mais que a largura da tela e
       a fita virava um carrossel: as últimas — Ranking e Eventos — ficavam
       fora do campo de visão, e quem não soubesse que elas existiam não ia
       arrastar pra descobrir. Empilhando o ícone sobre o texto cada aba passa
       a caber em um quinto da tela, e nenhuma fica escondida. */
    @media (max-width:560px) {
      .g-tabs { overflow-x:visible; }
      .g-tabs .g-tab { flex:1 1 0; min-width:0; }
      .g-tab { flex-direction:column; gap:3px; padding:9px 2px; font-size:10.5px;
               line-height:1.2; text-align:center; }
      .g-tab i { font-size:15px; }
      /* O selo sai do fluxo pra não empurrar o texto e desalinhar essa aba
         em relação às outras quatro. */
      .g-tab { position:relative; }
      .g-tab-selo { position:absolute; top:4px; right:calc(50% - 20px); margin-left:0;
                    font-size:9px; padding:0 4px; }
    }

    /* catálogo */
    .sec-label { font-size:11px; font-weight:800; letter-spacing:1.2px; text-transform:uppercase;
                 width:100%;
                 color:var(--text-3); margin:0 0 14px; display:flex; align-items:center; gap:8px; }
    .sec-label i { color:var(--red); font-size:13px; }
    .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(178px,1fr)); gap:14px; margin-bottom:34px; }
    .card-jogo { display:flex; flex-direction:column; align-items:flex-start; gap:3px; text-decoration:none;
                 background:var(--panel); border:1px solid var(--border); border-radius:var(--radius);
                 padding:18px 16px; transition:all var(--t) var(--ease); position:relative; }
    .card-jogo:hover { border-color:var(--border-md); transform:translateY(-2px); }
    .card-jogo:active { transform:translateY(0); }
    .card-topo { display:flex; align-items:flex-start; justify-content:space-between;
                 width:100%; margin-bottom:9px; }
    .card-jogo .ico { width:42px; height:42px; border-radius:11px; display:flex; align-items:center;
                      justify-content:center; font-size:20px; }
    .card-jogo .nome { font-size:14px; font-weight:700; color:var(--text); }
    .card-jogo .sub { font-size:11.5px; color:var(--text-3); display:flex; align-items:center; gap:7px; }

    /* Já jogado hoje: o card recua um pouco, sem sumir — ainda dá pra rejogar */
    .card-jogo.feito { border-color:rgba(34,197,94,.22); }
    .card-jogo.feito .ico { opacity:.55; }
    .card-jogo.feito .nome { color:var(--text-2); }
    .card-jogo.feito .sub { color:var(--green); }

    .selo { display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .selo.ok { width:20px; height:20px; border-radius:50%; background:rgba(34,197,94,.15);
               color:var(--green); font-size:11px; }
    .selo.pendente { width:7px; height:7px; border-radius:50%; background:var(--red); margin-top:6px;
                     box-shadow:0 0 0 3px color-mix(in srgb, var(--red) 18%, transparent); }

    .streak { display:inline-flex; align-items:center; gap:3px; font-size:10.5px; font-weight:800;
              color:var(--amber); background:rgba(245,158,11,.12); border-radius:20px; padding:1px 7px; }

    .sec-contador { margin-left:auto; font-size:10.5px; font-weight:800; letter-spacing:.3px;
                    color:var(--text-3); background:var(--panel-2); border:1px solid var(--border);
                    border-radius:20px; padding:3px 10px; text-transform:none; }
    .sec-contador.completo { color:var(--green); border-color:rgba(34,197,94,.3);
                             background:rgba(34,197,94,.10); }

    /* apostas */
    .card { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); margin-bottom:16px; }
    .card-head { padding:14px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center;
                 justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .card-head-left { display:flex; align-items:center; gap:9px; font-weight:700; font-size:14px; }
    .card-head-left i { color:var(--red); }
    .prazo { font-size:11.5px; color:var(--text-3); display:flex; align-items:center; gap:5px;
             background:var(--panel-2); border:1px solid var(--border); border-radius:20px; padding:3px 10px; }
    .prazo.urgente { color:var(--amber); border-color:rgba(245,158,11,.3); background:rgba(245,158,11,.10); }
    .card-body { padding:16px 18px; }
    .opcoes { display:flex; gap:10px; flex-wrap:wrap; }
    .card-head-dir { display:flex; align-items:center; gap:7px; flex-wrap:wrap; }
    .op-btn { flex:1; min-width:150px; background:var(--panel-2); border:1px solid var(--border-md); color:var(--text);
              border-radius:var(--radius-sm); padding:12px 16px; font-family:var(--font); font-size:13px;
              font-weight:600; cursor:pointer; transition:all var(--t) var(--ease); text-align:left;
              position:relative; overflow:hidden; display:flex; align-items:center; gap:10px; }
    .op-btn:hover { border-color:var(--border-red); color:var(--red); }
    .op-btn.escolhida { border-color:var(--green); color:var(--green); background:rgba(34,197,94,.08); }
    .op-btn.escolhida .op-txt::before { content:'\F26E'; font-family:'bootstrap-icons'; margin-right:7px; }

    /* A BARRA ATRÁS DO TEXTO, e não um número solto no canto: a proporção
       entre as opções se lê sem passar por leitura de dígito nenhum — quem
       está na frente é a barra mais comprida. O número fica junto pra quem
       quer a precisão.

       Ela é irmã do texto e não um ::before porque o botão já usa ::before
       pro visto do "escolhida", e porque largura em porcentagem precisa de
       um elemento de verdade pra animar. */
    .op-barra { position:absolute; left:0; top:0; bottom:0; z-index:0;
                background:color-mix(in srgb, var(--text) 7%, transparent);
                transition:width 420ms var(--ease); pointer-events:none; }
    .op-btn.escolhida .op-barra { background:rgba(34,197,94,.16); }
    /* O rosto é redondo e o escudo não: rosto cortado em círculo é retrato,
       escudo cortado em círculo é escudo estragado. Por isso o time usa
       contain e fundo nenhum. */
    .op-cara { position:relative; z-index:1; flex:none; width:30px; height:30px;
               border-radius:50%; object-fit:cover; object-position:top center;
               background:var(--panel-3); }
    .op-cara.time { border-radius:6px; object-fit:contain; background:transparent; }
    .op-txt { position:relative; z-index:1; flex:1; min-width:0; }
    .op-pct { position:relative; z-index:1; flex:none; font-size:12px; font-weight:800;
              color:var(--text-3); font-variant-numeric:tabular-nums; }
    .op-btn.escolhida .op-pct { color:var(--green); }
    @media (prefers-reduced-motion: reduce) { .op-barra { transition:none; } }

    .alerta { border-radius:var(--radius-sm); padding:11px 15px; font-size:13px; margin-bottom:16px; }
    .alerta.ok { background:rgba(34,197,94,.10); border:1px solid rgba(34,197,94,.30); color:var(--green); }
    .alerta.err { background:rgba(239,68,68,.10); border:1px solid rgba(239,68,68,.30); color:#ef4444; }

    .vazio { text-align:center; padding:44px 20px; color:var(--text-3); }
    .vazio i { font-size:30px; display:block; margin-bottom:10px; opacity:.5; }
    .vazio p { font-size:13px; }

    table.hist { width:100%; border-collapse:collapse; }
    table.hist th { text-align:left; font-size:10.5px; font-weight:800; letter-spacing:.6px; text-transform:uppercase;
                    color:var(--text-3); padding:0 12px 10px; }
    table.hist td { padding:11px 12px; border-top:1px solid var(--border); font-size:13px; }
    .pill { font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .pill.aberta { background:rgba(59,130,246,.12); color:var(--blue); }
    .pill.acertou { background:rgba(34,197,94,.12); color:var(--green); }
    .pill.errou { background:rgba(239,68,68,.12); color:#ef4444; }
    .tbl-wrap { overflow-x:auto; }

    /* O separador da sua linha: um traço com a palavra no meio, pra não
       restar dúvida de que aquilo não é a colocação seguinte. */
    .rk-eu-sep { display:flex; align-items:center; gap:8px; margin:9px 4px 5px;
                 font-size:9.5px; font-weight:800; letter-spacing:1px;
                 text-transform:uppercase; color:var(--text-3); }
    .rk-eu-sep::before, .rk-eu-sep::after {
        content:''; flex:1; border-top:1px dashed var(--border-md); }

    /* ── A LOJINHA ──────────────────────────────────────────────────── */
    .g-tab-selo { background:var(--red); color:#fff; font-size:10px; font-weight:800;
                  border-radius:999px; padding:1px 6px; margin-left:5px; }
    .lj-taxa { font-size:12px; font-weight:700; color:var(--text-2); margin-bottom:11px; }
    /* O conversor virou um card na grade, então o formulário empilha em vez
       de esticar na linha: dentro de um item da loja não há a largura que
       ele tinha como seção. */
    .lj-item-conv .lj-conv { flex-direction:column; align-items:stretch; gap:7px; margin-top:auto; }
    .lj-item-conv .lj-conv input { width:100%; }
    .lj-item-conv .lj-vira { text-align:center; }
    .lj-conv { display:flex; gap:9px; align-items:center; flex-wrap:wrap; }
    .lj-conv input { width:150px; background:var(--panel-2); border:1px solid var(--border-md);
        border-radius:var(--radius-sm); color:var(--text); font-family:var(--font);
        font-size:13px; padding:9px 12px; }
    .lj-conv input:focus { outline:none; border-color:var(--border-red); }
    .lj-vira { font-size:13px; font-weight:800; color:var(--red); font-variant-numeric:tabular-nums; }
    .lj-nota { font-size:11.5px; color:var(--text-3); margin-top:11px; }
    .lj-nota b { color:var(--text-2); }
    .lj-btn { background:var(--red); border:0; color:#fff; font-family:var(--font); font-size:12.5px;
        font-weight:700; border-radius:var(--radius-sm); padding:9px 16px; cursor:pointer;
        transition:filter var(--t) var(--ease); }
    .lj-btn:hover:not(:disabled) { filter:brightness(1.12); }
    .lj-btn:disabled { background:var(--panel-3); color:var(--text-3); cursor:not-allowed; }
    .lj-btn.usar { background:var(--green); }
    /* O margin-bottom fecha a seção. Sem ele, o título da seção seguinte
       encostava no último card — as outras seções disfarçavam com um
       margin-top inline, mas o buraco era aqui: uma grade que não termina. */
    .lj-grade { display:grid; gap:11px; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr));
        margin-bottom:30px; }
    .lj-item { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius-sm);
        padding:15px; display:flex; flex-direction:column; gap:7px; }
    /* Sem saldo o card nao some nem fica ilegivel: ele continua sendo o
       objetivo, e a meta e o proprio botao dizendo quanto falta. */
    .lj-item.sem-saldo { opacity:.62; }
    .lj-item.tenho { border-color:color-mix(in srgb, var(--green) 30%, transparent);
                     background:color-mix(in srgb, var(--green) 5%, var(--panel)); }
    .lj-ico { width:38px; height:38px; border-radius:10px; display:flex; align-items:center;
              justify-content:center; font-size:18px; }
    .lj-nome { font-size:13.5px; font-weight:700; }
    .lj-desc { font-size:11.5px; color:var(--text-3); line-height:1.4; flex:1; }
    /* A regra do item, na vitrine. Discreta quando ainda cabe, vermelha
       quando acabou — a diferenca entre informacao e impedimento. */
    .lj-limite { font-size:10.5px; font-weight:700; color:var(--text-3);
                 display:flex; align-items:center; gap:5px; }
    .lj-limite.fim { color:var(--red); }
    .lj-limite i { font-size:10px; }
    .lj-preco { font-size:14px; font-weight:800; color:var(--amber); display:flex;
                align-items:center; gap:5px; font-variant-numeric:tabular-nums; }
    .lj-preco i { font-size:11px; }
    .lj-item form { display:flex; }
    .lj-item .lj-btn { width:100%; }

    /* A dica de trocar a escolha, agora com classe porque o JS precisa
       saber se ela já existe pra não colocar duas. */
    .op-dica { font-size:11.5px; color:var(--text-3); margin-top:11px; }

    /* Enquanto o palpite vai e volta, o card recusa cliques novos — sem
       isso dois cliques rápidos viram duas gravações e a segunda resposta
       pode chegar antes da primeira, deixando a tela com o palpite errado. */
    .card.enviando .opcoes { pointer-events:none; opacity:.75; }

    /* ── O PLACAR DOS PALPITES ──────────────────────────────────────────
       Números grandes e rótulo pequeno: a leitura é o número, o rótulo só
       diz do que ele é. Em grade que se ajusta sozinha, porque são seis
       cards e nenhuma largura de tela cabe seis do mesmo jeito. */
    .pl-cards { display:grid; gap:10px; margin-bottom:14px;
                grid-template-columns:repeat(auto-fit, minmax(112px, 1fr)); }
    .pl-card { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius-sm);
               padding:13px 14px; display:flex; flex-direction:column; gap:2px; }
    .pl-card b { font-size:22px; font-weight:800; line-height:1.05; font-variant-numeric:tabular-nums; }
    .pl-card span { font-size:11px; color:var(--text-3); font-weight:600; }
    .pl-card span i { display:block; font-style:normal; font-size:10px; opacity:.75; margin-top:1px; }
    .pl-card.verde b { color:var(--green); }
    .pl-card.vermelho b { color:#ef4444; }
    .pl-card.azul b { color:var(--blue); }
    .pl-card.ambar { border-color:rgba(245,158,11,.30); background:rgba(245,158,11,.07); }
    .pl-card.ambar b { color:var(--amber); }

    /* Busca e filtro na mesma faixa, acima da tabela. */
    .pl-barra { display:flex; gap:10px; align-items:center; flex-wrap:wrap;
                padding:13px 18px; border-bottom:1px solid var(--border); }
    .pl-busca { position:relative; flex:1; min-width:190px; display:flex; align-items:center; }
    .pl-busca i { position:absolute; left:11px; color:var(--text-3); font-size:13px; pointer-events:none; }
    .pl-busca input { width:100%; background:var(--panel-2); border:1px solid var(--border-md);
                      border-radius:var(--radius-sm); color:var(--text); font-family:var(--font);
                      font-size:13px; padding:8px 12px 8px 32px; }
    .pl-busca input:focus { outline:none; border-color:var(--border-red); }
    .pl-busca input::placeholder { color:var(--text-3); }
    .pl-filtros { display:flex; gap:6px; flex-wrap:wrap; }
    .pl-f { background:var(--panel-2); border:1px solid var(--border-md); color:var(--text-2);
            font-family:var(--font); font-size:12px; font-weight:700; border-radius:20px;
            padding:6px 13px; cursor:pointer; transition:all var(--t) var(--ease); }
    .pl-f:hover { color:var(--text); }
    .pl-f.active { background:var(--red-soft); border-color:var(--border-red); color:var(--red); }

    /* ranking */
    .rk-ligas { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
    .rk-liga { background:var(--panel); border:1px solid var(--border-md); color:var(--text-2);
        font-family:var(--font); font-size:12.5px; font-weight:700; border-radius:20px;
        padding:7px 16px; cursor:pointer; transition:all var(--t) var(--ease); }
    .rk-liga:hover { color:var(--text); }
    .rk-liga.active { background:var(--red-soft); border-color:var(--border-red); color:var(--red); }
    .rk-bloco { display:none; }
    .rk-bloco.active { display:block; }
    .rk-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:16px; }
    .rk-linha { display:flex; align-items:center; gap:11px; padding:8px 8px; border-radius:var(--radius-sm); }
    .rk-linha:hover { background:var(--panel-2); }
    .rk-pos { width:22px; text-align:center; font-size:12px; font-weight:800; color:var(--text-3); flex-shrink:0; }
    /* Pódio: ouro, prata e bronze dão a leitura das 3 primeiras posições sem
       precisar contar as linhas. */
    .rk-linha:nth-child(1) .rk-pos { color:#f5c542; }
    .rk-linha:nth-child(2) .rk-pos { color:#c9ced6; }
    .rk-linha:nth-child(3) .rk-pos { color:#cd8032; }
    .rk-linha:nth-child(1) .rk-foto { border-color:#f5c542; }
    .rk-linha:nth-child(2) .rk-foto { border-color:#c9ced6; }
    .rk-linha:nth-child(3) .rk-foto { border-color:#cd8032; }
    .rk-linha:nth-child(1) .rk-nome { font-weight:700; }
    /* Destaca a linha do próprio GM, pra ele se achar na lista */
    .rk-linha.eu { background:var(--red-soft); }
    .rk-linha.eu .rk-nome { color:var(--red); font-weight:700; }
    .rk-foto { width:30px; height:30px; border-radius:50%; object-fit:cover;
        border:1px solid var(--border-md); flex-shrink:0; }
    .rk-nome { flex:1; min-width:0; font-size:13px; font-weight:600;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .rk-val { font-size:13.5px; font-weight:800; flex-shrink:0;
        font-variant-numeric:tabular-nums; }

    /* ── Leilão do jogo da semana ───────────────────────────────────────
       A minha liga primeiro e destacada: é a única em que dá pra dar lance.
       As outras ficam iguais entre si, pra acompanhar. */
    .lj-intro { font-size:12.5px; color:var(--text-2); line-height:1.6; margin:-4px 0 14px;
        max-width:75ch; }
    /* O mesmo 30 da grade da loja: as seções da aba têm que respirar igual,
       senão uma parece mais colada que a outra sem motivo. */
    .lj-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
        gap:14px; margin-bottom:30px; }
    .lj-grid.atualizando { opacity:.55; transition:opacity .12s; }

    /* O botão fica no fim da linha do título: o leilão muda enquanto a
       pessoa olha, e ela precisa de um jeito de conferir sem perder a aba. */
    .lj-refresh { margin-left:auto; flex:none; display:inline-flex; align-items:center;
        justify-content:center; width:26px; height:26px; padding:0; cursor:pointer;
        background:var(--panel-2); border:1px solid var(--border); border-radius:8px;
        color:var(--text-2); }
    .lj-refresh:hover { color:var(--red); border-color:var(--border-red); }
    .lj-refresh i { font-size:13px; color:inherit; }
    .lj-refresh[disabled] { cursor:default; opacity:.6; }
    .lj-refresh.girando i { animation:lj-girar .7s linear infinite; }
    @keyframes lj-girar { to { transform:rotate(360deg); } }
    /* No dedo, 26px é alvo pequeno demais pra um botão que a pessoa vai
       apertar várias vezes seguidas enquanto a disputa está quente. */
    @media (max-width:768px) { .lj-refresh { width:34px; height:34px; }
                               .lj-refresh i { font-size:15px; } }
    @media (prefers-reduced-motion:reduce) { .lj-refresh.girando i { animation:none; } }
    .lj-card .card-head { display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .lj-minha { border-color:var(--border-red); }
    .lj-liga-logo { width:20px; height:20px; object-fit:contain; margin-right:6px; vertical-align:middle; }
    .lj-sua { font-size:9px; font-weight:800; letter-spacing:.6px; text-transform:uppercase;
        color:var(--red); background:var(--red-soft); border:1px solid var(--border-red);
        border-radius:999px; padding:2px 7px; margin-left:7px; }
    .lj-temp { font-size:10px; font-weight:700; color:var(--text-3); }

    /* O confronto. As duas logos com o × no meio são o que se lê primeiro —
       o valor vem depois, menor, porque a pergunta é "qual jogo", não
       "quanto custou". */
    .lj-jogo { display:flex; align-items:flex-start; justify-content:center; gap:10px; }
    .lj-lado { flex:1; min-width:0; display:flex; flex-direction:column; align-items:center;
        gap:5px; text-align:center; }
    .lj-lado img, .lj-vaga { width:52px; height:52px; border-radius:12px; object-fit:contain;
        background:var(--panel-2); border:1px solid var(--border); }
    .lj-vaga { display:flex; align-items:center; justify-content:center; font-size:20px;
        font-weight:900; color:var(--text-3); border-style:dashed; }
    .lj-nome { font-size:11.5px; font-weight:700; line-height:1.25; }
    .lj-aberta { color:var(--text-3); font-weight:600; }
    .lj-valor { font-size:13px; font-weight:900; color:var(--red);
        font-variant-numeric:tabular-nums; }
    .lj-x { font-size:15px; font-weight:900; color:var(--text-3); padding-top:18px; flex:none; }

    .lj-meu { margin-top:12px; font-size:11.5px; line-height:1.5; border-radius:9px;
        padding:8px 10px; }
    .lj-meu.ok   { background:rgba(37,198,119,.09); border:1px solid rgba(37,198,119,.28); color:#4ade80; }
    .lj-meu.fora { background:var(--panel-2); border:1px solid var(--border-md); color:var(--text-2); }

    .lj-form { display:flex; gap:7px; margin-top:12px; }
    .lj-form input { flex:1; min-width:0; background:var(--panel-2); color:var(--text);
        border:1px solid var(--border-md); border-radius:9px; padding:9px 11px;
        font-family:inherit; font-size:13px; font-variant-numeric:tabular-nums; }
    .lj-form input:focus { outline:none; border-color:var(--red); }
    .lj-bt { flex:none; background:var(--red); border:0; color:#fff; border-radius:9px;
        padding:9px 14px; font-family:inherit; font-size:12.5px; font-weight:800; cursor:pointer;
        display:inline-flex; align-items:center; gap:6px; }
    .lj-bt:hover { filter:brightness(1.08); }
    .lj-min { font-size:11px; color:var(--text-3); margin-top:7px; line-height:1.5; }
    .lj-so-olhar { margin-top:12px; font-size:11.5px; color:var(--text-3);
        display:flex; align-items:center; gap:6px; }
    .lj-vazio { font-size:12.5px; color:var(--text-3); text-align:center; padding:18px 8px;
        line-height:1.6; }

    /* ── Slot de tela da live ──────────────────────────────────────────
       As oito vagas viram oito quadradinhos: cheio mostra o escudo, vazio
       fica pontilhado. É a informação que a pessoa vem ver — quantos
       sobraram — sem precisar contar nomes numa lista. */
    /* O respiro fica AQUI, e não num margin-top no título do leilão: o
       título já aparece em outras situações (sem o card de slot, o que vem
       acima é a grade de itens, que tem o espaçamento dela). Assim o ajuste
       vale só quando este card existe. 26px é o mesmo intervalo que a loja
       usa entre "Meus itens" e o que vem antes. */
    .st-card { margin-bottom:26px }
    .st-topo { display:flex; align-items:center; justify-content:space-between;
        gap:12px; flex-wrap:wrap; margin-bottom:10px }
    .st-quando { font-size:15px; font-weight:700 }
    .st-quando b { color:var(--amber) }
    .st-sub { font-size:11.5px; color:var(--text-3); margin-top:2px }
    .st-vagas { display:flex; gap:5px; flex-wrap:wrap }
    .st-copiar {
        display:inline-flex; align-items:center; gap:6px;
        font-size:11px; font-weight:700; padding:5px 10px; border-radius:8px;
        border:1px solid var(--border); background:var(--panel-3);
        color:var(--text-2); cursor:pointer; white-space:nowrap;
    }
    .st-copiar:hover { border-color:var(--amber); color:var(--amber) }
    .st-copiar.ok { border-color:var(--green); color:var(--green) }
    .st-vaga { width:30px; height:30px; border-radius:8px; flex:none;
        border:1px dashed var(--border); display:flex; align-items:center;
        justify-content:center; background:color-mix(in srgb, var(--text-3) 6%, transparent) }
    .st-vaga.cheia { border-style:solid;
        border-color:color-mix(in srgb, var(--amber) 35%, transparent);
        background:color-mix(in srgb, var(--amber) 8%, transparent) }
    .st-vaga img { width:22px; height:22px; object-fit:contain }
    .st-txt { font-size:11.5px; color:var(--text-3); line-height:1.6; margin:0 0 10px }
    .st-form .lj-btn { width:100% }
    .st-aviso { display:flex; align-items:center; justify-content:center; gap:7px;
        font-size:12px; color:var(--text-3); padding:9px 10px; border-radius:8px;
        background:color-mix(in srgb, var(--text-3) 8%, transparent); text-align:center }
    /* Leilão encerrado: o card fica sendo o placar do jogo definido, e o
       aviso ocupa o lugar onde antes ficava o campo de lance. */
    .lj-fechado { margin-top:12px; font-size:11.5px; color:var(--text-3);
        display:flex; align-items:center; justify-content:center; gap:6px;
        flex-wrap:wrap; text-align:center;
        padding:8px 10px; border-radius:8px;
        background:color-mix(in srgb, var(--text-3) 8%, transparent); }
    .lj-fechado i { color:var(--amber); }
    .lj-fechado span { opacity:.75; }

    /* ── Maiores sequências ─────────────────────────────────────────────
       Um título de seção, e não mais um card no meio da grade: o que vem
       abaixo mede outra coisa (constância, não volume), e sem a divisória
       os cinco cards pareceriam mais critérios do mesmo ranking. */
    .rk-seq-tit { display:flex; align-items:center; gap:9px; margin:26px 0 14px;
        font-size:13px; font-weight:800; color:var(--text); flex-wrap:wrap; }
    .rk-seq-tit i { color:#f97316; font-size:15px; }
    .rk-seq-tit span { font-size:11px; font-weight:600; color:var(--text-3);
        text-transform:uppercase; letter-spacing:.6px; }
    .rk-seq-tit::after { content:""; flex:1; height:1px; background:var(--border);
        min-width:24px; }
    /* Em tela estreita o título e o "dias seguidos acertando" disputavam a
       mesma linha e os dois quebravam no meio. Aqui o subtítulo desce
       inteiro pra linha de baixo, e a régua some — ela não tem o que
       separar quando já há uma quebra de linha fazendo isso. */
    @media (max-width:520px) {
        .rk-seq-tit { gap:4px 8px; }
        .rk-seq-tit span { flex-basis:100%; }
        .rk-seq-tit::after { display:none; }
    }
    /* A chama fica colada no nome e não encolhe junto com ele: o nome tem
       ellipsis, e um emoji cortado pela metade não se lê como nada. */
    .rk-viva { flex-shrink:0; font-size:11px; margin-left:3px; }

    /* ── O HISTÓRICO VIRA CARD NO CELULAR ───────────────────────────────
       Quatro colunas em 375px é uma tabela que rola de lado com o resultado
       cortado do lado de fora — e resultado é a coluna que a pessoa veio
       ver. Empilhado, cada palpite vira um bloco: o nome do evento em cima,
       e embaixo os três campos com o rótulo do lado.

       O rótulo sai do data-rot da célula e não de um segundo HTML só pra
       celular: duas marcações pro mesmo dado é a receita de uma delas ficar
       pra trás. */
    @media (max-width:640px) {
        table.hist, table.hist tbody, table.hist tr, table.hist td { display:block; width:100%; }
        table.hist thead { display:none; }
        table.hist tr { background:var(--panel-2); border:1px solid var(--border);
                        border-radius:var(--radius-sm); padding:11px 13px; margin-bottom:9px; }
        table.hist td { border-top:0; padding:3px 0; display:flex; align-items:center;
                        justify-content:space-between; gap:12px; }
        table.hist td[data-rot="Evento"] { font-weight:700; font-size:13.5px;
                                           display:block; padding-bottom:7px; }
        table.hist td[data-rot]:not([data-rot="Evento"])::before {
            content:attr(data-rot); font-size:11px; font-weight:600; color:var(--text-3);
            flex:none;
        }
        .tbl-wrap { overflow-x:visible; }
    }

    @media (max-width:992px) {
        :root { --sidebar-w: 0px; }
        .main { margin-left:0; padding-top:54px; }
        .topbar { display:flex; }
            .sidebar { transform:translateX(-260px); }
            .sidebar.open { transform:translateX(0); }
        .page-hero { padding:20px 18px 0; }
        .content { padding:18px 18px 44px; }
        .g-tab { flex:1; justify-content:center; padding:12px 10px; }
    }
<?php include __DIR__ . '/includes/accent-color.php'; ?>
    @media (prefers-reduced-motion: reduce) { *,*::before,*::after { transition-duration:.01ms !important; } }
</style>
</head>
<body>
<div class="app">

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Manager</em></div>
</header>

<main class="main">
    <div class="page-hero">
        <div class="page-eyebrow">Diversão da liga</div>
        <h1 class="page-title"><i class="bi bi-controller"></i> Games</h1>
        <p class="page-sub">Minigames diários pra ganhar moedas e as apostas dos eventos da FBA.</p>
    </div>

    <div class="content">

        <div class="saldos">
            <div class="saldo">
                <i class="bi bi-coin" style="color:var(--amber)"></i>
                <div>
                    <div class="saldo-val"><?= number_format((int)$perfil['pontos'], 0, ',', '.') ?></div>
                    <div class="saldo-lbl">Moedas</div>
                </div>
            </div>
            <div class="saldo">
                <i class="bi bi-star-fill" style="color:var(--red)"></i>
                <div>
                    <div class="saldo-val"><?= number_format((int)$perfil['fba_points'], 0, ',', '.') ?></div>
                    <div class="saldo-lbl">FBA Points</div>
                </div>
            </div>
            <div class="saldo">
                <i class="bi bi-trophy-fill" style="color:var(--green)"></i>
                <div>
                    <div class="saldo-val"><?= number_format((int)$perfil['acertos_eventos'], 0, ',', '.') ?></div>
                    <div class="saldo-lbl">Acertos</div>
                </div>
            </div>
        </div>

        <div class="g-tabs">
            <button class="g-tab <?= $abaInicial === 'games' ? 'active' : '' ?>" data-aba="games" onclick="trocarAba('games')">
                <i class="bi bi-joystick"></i> Games
            </button>
            <button class="g-tab <?= $abaInicial === 'apostas' ? 'active' : '' ?>" data-aba="apostas" onclick="trocarAba('apostas')">
                <i class="bi bi-graph-up-arrow"></i> Apostas
            </button>
            <button class="g-tab <?= $abaInicial === 'loja' ? 'active' : '' ?>" data-aba="loja" onclick="trocarAba('loja')">
                <i class="bi bi-bag-fill"></i> Loja
                <?php if ($lojaItens): ?><span class="g-tab-selo"><?= count($lojaItens) ?></span><?php endif; ?>
            </button>
            <button class="g-tab <?= $abaInicial === 'ranking' ? 'active' : '' ?>" data-aba="ranking" onclick="trocarAba('ranking')">
                <i class="bi bi-bar-chart-fill"></i> Ranking
            </button>
            <button class="g-tab <?= $abaInicial === 'eventos' ? 'active' : '' ?>" data-aba="eventos" onclick="trocarAba('eventos')">
                <i class="bi bi-calendar-event-fill"></i> Eventos
            </button>
        </div>

        <!-- ── Aba Games ─────────────────────────────────────────────── -->
        <div class="g-pane <?= $abaInicial === 'games' ? 'active' : '' ?>" id="pane-games">
            <div class="sec-label">
                <i class="bi bi-calendar-check-fill"></i> Minigames diários
                <?php if ($diariosTotal > 0): ?>
                <span class="sec-contador <?= $diariosFeitos === $diariosTotal ? 'completo' : '' ?>">
                    <?= $diariosFeitos ?>/<?= $diariosTotal ?> hoje
                </span>
                <?php endif; ?>
            </div>
            <div class="grid">
                <?php foreach ($jogosDiarios as $j):
                    $feito  = $statusDiario[$j['key']] ?? null;
                    $streak = $streaks[$j['key']] ?? 0;
                ?>
                <a class="card-jogo <?= $feito === true ? 'feito' : '' ?>" href="/games/games/index.php?game=<?= urlencode($j['key']) ?>">
                    <div class="card-topo">
                        <div class="ico" style="background:<?= $j['cor'] ?>1f;color:<?= $j['cor'] ?>"><i class="bi <?= $j['icone'] ?>"></i></div>
                        <?php if ($feito === true): ?>
                            <span class="selo ok" title="Você já jogou hoje"><i class="bi bi-check-lg"></i></span>
                        <?php elseif ($feito === false): ?>
                            <span class="selo pendente" title="Ainda não jogou hoje"></span>
                        <?php endif; ?>
                    </div>
                    <div class="nome"><?= htmlspecialchars($j['nome']) ?></div>
                    <div class="sub">
                        <?php if ($feito === true): ?>
                            Jogado hoje
                        <?php else: ?>
                            <?= htmlspecialchars($j['sub']) ?>
                        <?php endif; ?>
                        <?php if ($streak > 1): ?>
                            <span class="streak" title="Sequência de dias"><i class="bi bi-fire"></i><?= $streak ?></span>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="sec-label"><i class="bi bi-joystick"></i> Minigames</div>
            <div class="grid">
                <?php foreach ($jogosLivres as $j):
                    // Quem tem `href` é página própria; o resto passa pelo
                    // carregador. Sem esse ou-ou, o Copero viraria um link pra
                    // index.php?game= vazio e a grade toda pareceria certa.
                    $destino = $j['href'] ?? ('/games/games/index.php?game=' . urlencode($j['key']));
                ?>
                <a class="card-jogo" href="<?= htmlspecialchars($destino) ?>">
                    <div class="ico" style="background:<?= $j['cor'] ?>1f;color:<?= $j['cor'] ?>"><i class="bi <?= $j['icone'] ?>"></i></div>
                    <div class="nome"><?= htmlspecialchars($j['nome']) ?></div>
                    <div class="sub"><?= htmlspecialchars($j['sub']) ?></div>
                </a>
                <?php endforeach; ?>
            </div>

        </div>

        <!-- ── Aba Apostas ───────────────────────────────────────────── -->
        <div class="g-pane <?= $abaInicial === 'apostas' ? 'active' : '' ?>" id="pane-apostas">
            <?php if ($apostaMsg): ?>
                <div class="alerta ok"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($apostaMsg) ?></div>
            <?php endif; ?>
            <?php if ($apostaErro): ?>
                <div class="alerta err"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($apostaErro) ?></div>
            <?php endif; ?>

            <div class="sec-label"><i class="bi bi-lightning-charge-fill"></i> Eventos abertos</div>
            <?php if (empty($eventos)): ?>
                <div class="card"><div class="vazio">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Nenhum evento aberto agora. Assim que a organização abrir um, ele aparece aqui.</p>
                </div></div>
            <?php else: ?>
                <?php foreach ($eventos as $ev): ?>
                <div class="card" data-evento="<?= (int)$ev['id'] ?>">
                    <div class="card-head">
                        <div class="card-head-left"><i class="bi bi-flag-fill"></i> <?= htmlspecialchars($ev['nome']) ?></div>
                        <div class="card-head-dir">
                            <?php if (!empty($ev['total_palpites'])): ?>
                            <!-- O total dá tamanho à porcentagem: 60% de cinco
                                 pessoas e 60% de duzentas são a mesma barra e
                                 coisas bem diferentes. -->
                            <div class="prazo ev-total" title="Total de palpites neste evento">
                                <i class="bi bi-people-fill"></i>
                                <span><?= (int)$ev['total_palpites'] ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="prazo <?= !empty($ev['urgente']) ? 'urgente' : '' ?>"
                                 title="Fecha em <?= date('d/m/Y \à\s H:i', strtotime($ev['data_limite'])) ?>">
                                <i class="bi bi-clock"></i>
                                <?= htmlspecialchars($ev['prazo_txt']) ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="opcoes">
                            <?php foreach ($ev['opcoes'] as $op): ?>
                            <form method="POST" style="flex:1;min-width:150px;display:flex">
                                <input type="hidden" name="opcao_id" value="<?= (int)$op['id'] ?>">
                                <input type="hidden" name="ajax" value="1" disabled data-ajax-flag>
                                <button type="submit" class="op-btn <?= (int)$ev['meu_palpite'] === (int)$op['id'] ? 'escolhida' : '' ?>"
                                        title="<?= (int)$op['palpites'] ?> <?= (int)$op['palpites'] === 1 ? 'palpite' : 'palpites' ?>">
                                    <span class="op-barra" style="width:<?= (int)$op['pct'] ?>%"></span>
                                    <?php
                                        $cara = null;
                                        if (!empty($op['img_url'])) {
                                            $cara = ['url' => $op['img_url'], 'tipo' => 'jogador'];
                                        } else {
                                            $cara = $caraDaOpcao[mb_strtolower(trim((string)$op['descricao']))] ?? null;
                                        }
                                    ?>
                                    <?php if ($cara): ?>
                                    <!-- onerror escondendo o próprio elemento: foto que
                                         não carrega tem que sumir, não virar um quadrado
                                         de imagem quebrada dentro do botão. -->
                                    <img class="op-cara <?= $cara['tipo'] === 'time' ? 'time' : '' ?>"
                                         src="<?= htmlspecialchars($cara['url']) ?>" alt="" loading="lazy"
                                         onerror="this.style.display='none'">
                                    <?php endif; ?>
                                    <span class="op-txt"><?= htmlspecialchars($op['descricao']) ?></span>
                                    <span class="op-pct"><?= (int)$op['pct'] ?>%</span>
                                </button>
                            </form>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($ev['meu_palpite']): ?>
                        <div class="op-dica">
                            <i class="bi bi-info-circle"></i> Dá pra trocar sua escolha até o prazo acabar.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="sec-label" style="margin-top:30px"><i class="bi bi-clock-history"></i> Meus palpites</div>

            <?php if (empty($historico)): ?>
                <div class="card"><div class="vazio">
                    <i class="bi bi-inbox"></i><p>Você ainda não deu nenhum palpite.</p>
                </div></div>
            <?php else: ?>

            <!-- O PLACAR. Vem antes da lista porque é o que a pessoa quer
                 saber ao abrir: quanto acertei. A lista é pra procurar um
                 palpite específico, e isso vem depois. -->
            <div class="pl-cards">
                <?php
                  // "1 acertos" é o tipo de coisa que ninguém escreveria e todo
                  // template deixa passar. Uma função, e o número decide.
                  $plural = fn(int $n, string $um, string $muitos) => $n === 1 ? $um : $muitos;
                ?>
                <div class="pl-card">
                    <b><?= (int)$placar['total'] ?></b>
                    <span><?= $plural((int)$placar['total'], 'palpite', 'palpites') ?></span>
                </div>
                <div class="pl-card verde">
                    <b><?= (int)$placar['acertos'] ?></b>
                    <span><?= $plural((int)$placar['acertos'], 'acerto', 'acertos') ?></span>
                </div>
                <div class="pl-card vermelho">
                    <b><?= (int)$placar['erros'] ?></b>
                    <span><?= $plural((int)$placar['erros'], 'erro', 'erros') ?></span>
                </div>
                <div class="pl-card azul">
                    <b><?= (int)$placar['abertos'] ?></b>
                    <span>em aberto</span>
                </div>
                <div class="pl-card">
                    <b><?= (int)$placar['aproveitamento'] ?>%</b>
                    <!-- "dos decididos" e não "do total": contar os que ainda
                         não saíram como erro derrubaria o número de quem
                         acabou de palpitar. -->
                    <span>aproveitamento<i>dos já decididos</i></span>
                </div>
                <div class="pl-card <?= $placar['sequencia'] >= 3 ? 'ambar' : '' ?>">
                    <b><?= (int)$placar['sequencia'] ?></b>
                    <span>sequência<i>melhor: <?= (int)$placar['melhor_sequencia'] ?></i></span>
                </div>
            </div>

            <div class="card">
                <div class="pl-barra">
                    <div class="pl-busca">
                        <i class="bi bi-search"></i>
                        <input type="search" id="plBusca" placeholder="Buscar evento ou escolha…"
                               autocomplete="off" spellcheck="false">
                    </div>
                    <div class="pl-filtros" id="plFiltros">
                        <button type="button" class="pl-f active" data-f="todos">Todos</button>
                        <button type="button" class="pl-f" data-f="acertou">Acertei</button>
                        <button type="button" class="pl-f" data-f="errou">Errei</button>
                        <button type="button" class="pl-f" data-f="aberto">Em aberto</button>
                    </div>
                </div>
                <div class="card-body tbl-wrap">
                    <table class="hist" id="plTabela">
                        <thead><tr><th>Evento</th><th>Sua escolha</th><th>Quando</th><th>Resultado</th></tr></thead>
                        <tbody>
                        <?php foreach ($historico as $h): ?>
                            <tr data-res="<?= htmlspecialchars($h['resultado']) ?>"
                                data-txt="<?= htmlspecialchars(mb_strtolower($h['evento'] . ' ' . $h['escolha'])) ?>">
                                <td data-rot="Evento"><?= htmlspecialchars($h['evento']) ?></td>
                                <td data-rot="Sua escolha" style="color:var(--text-2)"><?= htmlspecialchars($h['escolha']) ?></td>
                                <td data-rot="Quando" style="color:var(--text-3);font-size:12px"><?= date('d/m/Y', strtotime($h['data_palpite'])) ?></td>
                                <td data-rot="Resultado">
                                    <?php if ($h['resultado'] === 'aberto'): ?>
                                        <span class="pill aberta">Em aberto</span>
                                    <?php elseif ($h['resultado'] === 'acertou'): ?>
                                        <span class="pill acertou">Acertou</span>
                                    <?php else: ?>
                                        <span class="pill errou">Errou</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="vazio" id="plVazio" style="display:none">
                        <i class="bi bi-search"></i><p>Nenhum palpite com esse filtro.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Aba Loja ──────────────────────────────────────────────── -->
        <div class="g-pane <?= $abaInicial === 'loja' ? 'active' : '' ?>" id="pane-loja">
            <?php if ($lojaMsg): ?>
                <div class="alerta ok"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($lojaMsg) ?></div>
            <?php endif; ?>
            <?php if ($lojaErro): ?>
                <div class="alerta err"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($lojaErro) ?></div>
            <?php endif; ?>

            <div class="sec-label"><i class="bi bi-bag-fill"></i> Loja</div>
            <div class="lj-grade">
                <?php /* O CONVERSOR É UM ITEM DA LOJA, e não uma seção acima
                         dela. Trocar moeda por FBA Point é a mesma coisa que
                         as outras: você gasta o que tem pra levar algo. Como
                         seção separada, ele empurrava a loja inteira pra
                         baixo e parecia um passo obrigatório antes de
                         comprar. Aqui é o primeiro card porque costuma ser o
                         primeiro passo — mas agora é uma escolha, não um
                         pedágio. */ ?>
                <?php
                  // O teto é o maior MÚLTIPLO que cabe no saldo, e não o
                  // saldo cru: quem tem 2505 moedas só converte 2500, e
                  // deixar o max em 2505 põe no campo um número que o
                  // próprio campo vai arredondar na frente da pessoa.
                  $ljTeto = intdiv((int)$perfil['pontos'], LOJA_MOEDAS_POR_PONTO) * LOJA_MOEDAS_POR_PONTO;
                  $podeConverter = (int)$perfil['pontos'] >= LOJA_MOEDAS_POR_PONTO;
                ?>
                <div class="lj-item lj-item-conv <?= $podeConverter ? '' : 'sem-saldo' ?>">
                    <div class="lj-ico" style="color:#22c55e;background:color-mix(in srgb,#22c55e 12%,transparent)">
                        <i class="bi bi-arrow-left-right"></i>
                    </div>
                    <div class="lj-nome">Trocar moedas</div>
                    <div class="lj-desc">
                        <?= LOJA_MOEDAS_POR_PONTO ?> moedas viram 1 FBA Point. A troca é só de ida.
                    </div>
                    <div class="lj-limite">
                        <i class="bi bi-coin"></i>
                        você tem <?= number_format((int)$perfil['pontos'], 0, ',', '.') ?> moedas
                    </div>
                    <form method="POST" class="lj-conv">
                        <input type="hidden" name="loja_acao" value="converter">
                        <!-- step=10 pras setas andarem de 10 em 10. O step
                             sozinho já custou caro uma vez: o navegador
                             RECUSAVA 2505 em silêncio, sem dizer por quê. Por
                             isso ele não vem sozinho — o JS abaixo ARREDONDA
                             pra baixo em vez de recusar, então digitar 12
                             vira 10 na tela e não um formulário travado. -->
                        <input type="number" name="moedas" id="ljMoedas" min="<?= LOJA_MOEDAS_POR_PONTO ?>"
                               step="<?= LOJA_MOEDAS_POR_PONTO ?>" max="<?= $ljTeto ?>"
                               data-taxa="<?= LOJA_MOEDAS_POR_PONTO ?>"
                               placeholder="múltiplos de <?= LOJA_MOEDAS_POR_PONTO ?>" autocomplete="off">
                        <span class="lj-vira" id="ljVira">= 0 FBA Points</span>
                        <button type="submit" class="lj-btn" <?= $podeConverter ? '' : 'disabled' ?>>
                            <?= $podeConverter ? 'Trocar' : 'Sem moedas' ?>
                        </button>
                    </form>
                </div>

                <?php foreach ($lojaCat as $chave => $it):
                    $lim = $lojaLimites[$chave] ?? null;
                    $esgotou = $lim && $lim['esgotou'];
                    // O limite vem ANTES do saldo: dizer "Faltam 3.500" pra
                    // quem já usou as duas badges do mês manda juntar pontos
                    // pra uma compra que não vai acontecer.
                    $temSaldo = (int)$perfil['fba_points'] >= (int)$it['preco'];
                    $podeComprar = $temSaldo && !$esgotou; ?>
                <div class="lj-item <?= $podeComprar ? '' : 'sem-saldo' ?>">
                    <div class="lj-ico" style="color:<?= $it['cor'] ?>;background:color-mix(in srgb, <?= $it['cor'] ?> 12%, transparent)">
                        <i class="bi <?= $it['icone'] ?>"></i>
                    </div>
                    <div class="lj-nome"><?= htmlspecialchars($it['nome']) ?></div>
                    <div class="lj-desc"><?= htmlspecialchars($it['desc']) ?></div>
                    <?php if ($lim): ?>
                    <div class="lj-limite <?= $esgotou ? 'fim' : '' ?>">
                        <i class="bi <?= $esgotou ? 'bi-lock-fill' : 'bi-info-circle' ?>"></i>
                        <?php
                        // A conta só aparece no limite mensal. Em "compra
                        // única · resta 1" as duas metades dizem a mesma
                        // coisa, e a segunda ainda sugere que exista uma
                        // segunda compra em algum lugar.
                        $mostraConta = !$esgotou && $lim['por'] === 'mes';
                        ?>
                        <?= htmlspecialchars($lim['texto']) ?><?= $mostraConta ? ' · resta' . ($lim['restam'] > 1 ? 'm ' : ' ') . $lim['restam'] : '' ?>
                    </div>
                    <?php endif; ?>
                    <div class="lj-preco"><i class="bi bi-star-fill"></i> <?= number_format((int)$it['preco'], 0, ',', '.') ?></div>
                    <form method="POST">
                        <input type="hidden" name="loja_acao" value="comprar">
                        <input type="hidden" name="item" value="<?= htmlspecialchars($chave) ?>">
                        <button type="submit" class="lj-btn" <?= $podeComprar ? '' : 'disabled' ?>>
                            <?php if ($esgotou): ?>
                                <?= $lim['texto'] === 'compra única' ? 'Já é seu' : 'Limite do mês' ?>
                            <?php elseif (!$temSaldo): ?>
                                Faltam <?= number_format((int)$it['preco'] - (int)$perfil['fba_points'], 0, ',', '.') ?>
                            <?php else: ?>
                                Comprar
                            <?php endif; ?>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>

            <?php
              /* ── Slot de tela da live ────────────────────────────────
                 Fica FORA da grade de itens de propósito: os outros ficam
                 lá parados esperando alguém juntar pontos, e este só existe
                 numa janela de uma hora por semana. Misturado com eles, o
                 card estaria cinza quase o tempo todo e ninguém entenderia
                 por quê. */
              require_once __DIR__ . '/backend/slots_tela.php';
              $minhaLiga = strtoupper((string)($team['league'] ?? ''));
              $slots = $minhaLiga !== ''
                  ? slotsTelaEstado($pdo, $minhaLiga, (int)($team['id'] ?? 0))
                  : null;
            ?>
            <?php if ($slots && $slots['live']): ?>
            <div class="sec-label"><i class="bi bi-tv"></i> Slot de tela · live da <?= htmlspecialchars($minhaLiga) ?></div>
            <?php
              $horaLive = slotsTelaHora($slots['live']['inicio']);
              $horaAbre = slotsTelaHora($slots['abre_em']);
              $diaLive  = date('d/m', strtotime($slots['live']['inicio']));
              $hoje     = date('Y-m-d') === $slots['live']['data'];
            ?>
            <div class="card st-card">
              <div class="card-body" style="padding:14px">
                <div class="st-topo">
                  <div>
                    <div class="st-quando">
                      <?= $hoje ? 'Hoje' : $diaLive ?> às <b><?= htmlspecialchars($horaLive) ?></b>
                    </div>
                    <div class="st-sub">
                      <?= (int)$slots['restam'] ?> de <?= (int)$slots['total'] ?> slots livres
                    </div>
                  </div>
                  <?php if ($slots['lista']): ?>
                  <button type="button" class="st-copiar"
                          data-copiar="<?= htmlspecialchars(slotsTelaTextoCopiar($slots['lista'])) ?>">
                    <i class="bi bi-clipboard"></i> Copiar os times
                  </button>
                  <?php endif; ?>
                  <div class="st-vagas">
                    <?php for ($i = 0; $i < (int)$slots['total']; $i++):
                      $ocupado = $slots['lista'][$i] ?? null; ?>
                      <span class="st-vaga <?= $ocupado ? 'cheia' : '' ?>"
                            title="<?= $ocupado ? htmlspecialchars(trim(($ocupado['time_cidade'] ?? '') . ' ' . ($ocupado['time_nome'] ?? ''))) : 'livre' ?>">
                        <?php if ($ocupado): ?>
                          <img src="<?= htmlspecialchars(getTeamPhoto($ocupado['logo'] ?? null)) ?>" alt=""
                               onerror="this.src='/img/default-team.png'">
                        <?php endif; ?>
                      </span>
                    <?php endfor; ?>
                  </div>
                </div>

                <p class="st-txt">
                  Oito times aparecem na tela durante a live. Quem compra primeiro leva —
                  a venda abre <b>uma hora antes</b> e fecha quando a live começa ou quando
                  os oito acabam.
                </p>

                <?php if ($slots['aberta']): ?>
                  <form method="post" class="st-form">
                    <input type="hidden" name="loja_acao" value="slot_tela">
                    <input type="hidden" name="liga" value="<?= htmlspecialchars($minhaLiga) ?>">
                    <input type="hidden" name="team_id" value="<?= (int)($team['id'] ?? 0) ?>">
                    <button class="lj-btn" <?= (int)$perfil['fba_points'] >= (int)$slots['preco'] ? '' : 'disabled' ?>>
                      <i class="bi bi-tv me-1"></i>
                      <?php if ((int)$perfil['fba_points'] >= (int)$slots['preco']): ?>
                        Comprar slot · <?= (int)$slots['preco'] ?> FBA Points
                      <?php else: ?>
                        Faltam <?= (int)$slots['preco'] - (int)$perfil['fba_points'] ?> FBA Points
                      <?php endif; ?>
                    </button>
                  </form>
                <?php else: ?>
                  <div class="st-aviso">
                    <?php if ($slots['motivo'] === 'ja_tenho'): ?>
                      <i class="bi bi-check-circle-fill" style="color:var(--green)"></i>
                      Seu time já está na tela desta live.
                    <?php elseif ($slots['motivo'] === 'esgotado'): ?>
                      <i class="bi bi-lock-fill"></i> Os oito slots já foram.
                    <?php elseif ($slots['motivo'] === 'comecou'): ?>
                      <i class="bi bi-broadcast"></i> A live já começou — a venda fechou.
                    <?php else: ?>
                      <?php
                        /* QUANTO FALTA PRA ABRIR, EM SEGUNDOS, CONTADO NO
                           SERVIDOR. O relógio do computador de quem está
                           olhando não serve de referência: quem está com a
                           hora adiantada veria a venda "abrir" antes, tentaria
                           comprar e levaria recusa. Aqui vai a diferença, e o
                           navegador só faz a contagem regressiva dela. */
                        $faltam = max(0, strtotime($slots['abre_em']) - time());
                      ?>
                      <i class="bi bi-clock"></i>
                      <span id="stEspera" data-faltam="<?= $faltam ?>">
                        A venda abre <?= $hoje ? 'hoje' : 'dia ' . $diaLive ?>
                        às <b><?= htmlspecialchars($horaAbre) ?></b>.
                      </span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php
              // ── Leilão do jogo da semana ────────────────────────────
              require_once __DIR__ . '/backend/leilao_semana.php';
              $minhaLigaLeilao = strtoupper((string)($team['league'] ?? ''));
              $leiloes = [];
              foreach (['ELITE','NEXT','RISE','ROOKIE'] as $lg) {
                  $leiloes[$lg] = leilaoSemanaDaLiga($pdo, $lg, $userId);
              }
              // A minha liga primeiro: é a única em que dá pra dar lance, e é
              // o card que a pessoa veio ver. As outras ficam abaixo, pra
              // acompanhar sem poder mexer.
              uksort($leiloes, fn($a, $b) => ($b === $minhaLigaLeilao) <=> ($a === $minhaLigaLeilao));
            ?>
            <div class="sec-label">
              <i class="bi bi-broadcast"></i> Leilão do jogo da semana
              <button type="button" class="lj-refresh" id="lj-refresh"
                      title="Ver se cobriram o seu lance" aria-label="Atualizar o leilão">
                <i class="bi bi-arrow-clockwise"></i>
              </button>
            </div>
            <p class="lj-intro">
              Os dois times que mais oferecem têm o jogo deles escolhido como o
              <b>jogo da semana</b>. Os FBA Points ficam retidos só enquanto
              você estiver entre os dois maiores — saiu do pódio, o valor volta
              na hora. Cada liga tem o seu, e o leilão zera a cada temporada.
            </p>

            <?php
              // Em que pé eu estou no leilão da MINHA liga. É isso que o botão
              // de atualizar compara antes e depois, pra saber se avisa "te
              // cobriram" em vez de só trocar os números em silêncio.
              $meuLeilao = $leiloes[$minhaLigaLeilao] ?? null;
              $meuEstado = !$meuLeilao || !$meuLeilao['meu']
                  ? 'sem'
                  : ($meuLeilao['meu']['no_podio'] ? 'podio' : 'fora');
            ?>
            <div class="lj-grid" id="lj-grid" data-meu="<?= $meuEstado ?>">
              <?php foreach ($leiloes as $lg => $d):
                $ehMinha = $lg === $minhaLigaLeilao;
                $a = $d['podio'][0] ?? null;
                $b = $d['podio'][1] ?? null;
              ?>
              <div class="card lj-card <?= $ehMinha ? 'lj-minha' : '' ?>">
                <div class="card-head">
                  <div class="card-head-left">
                    <img class="lj-liga-logo" src="/img/logo-<?= strtolower($lg) ?>.png" alt=""
                         onerror="this.style.display='none'">
                    <?= $lg ?>
                    <?php if ($ehMinha): ?><span class="lj-sua">sua liga</span><?php endif; ?>
                  </div>
                  <?php if ($d['temporada'] > 0): ?>
                  <span class="lj-temp">Temporada <?= (int)$d['temporada'] ?></span>
                  <?php endif; ?>
                </div>

                <div class="card-body" style="padding:14px">
                  <?php
                    // Fechado: o jogo está DEFINIDO. Os lances já foram
                    // apagados no fechamento, então sem este ramo o card cairia
                    // no "ninguém deu lance ainda" logo depois de dois times
                    // terem pagado — e ainda ofereceria o campo de lance.
                    $f = $d['fechado'] ?? null;
                  ?>
                  <?php if ($d['temporada'] <= 0): ?>
                    <div class="lj-vazio">Sem temporada em andamento.</div>

                  <?php elseif ($f): ?>
                    <div class="lj-jogo">
                      <div class="lj-lado">
                        <img src="<?= htmlspecialchars(getTeamPhoto($f['time1_logo'] ?? null)) ?>" alt=""
                             onerror="this.src='/img/default-team.png'">
                        <span class="lj-nome"><?= htmlspecialchars((string)$f['time1_nome']) ?></span>
                        <span class="lj-valor"><?= number_format((int)$f['valor1'], 0, ',', '.') ?></span>
                      </div>
                      <span class="lj-x">×</span>
                      <div class="lj-lado">
                        <?php if (!empty($f['time2_nome'])): ?>
                          <img src="<?= htmlspecialchars(getTeamPhoto($f['time2_logo'] ?? null)) ?>" alt=""
                               onerror="this.src='/img/default-team.png'">
                          <span class="lj-nome"><?= htmlspecialchars((string)$f['time2_nome']) ?></span>
                          <span class="lj-valor"><?= number_format((int)$f['valor2'], 0, ',', '.') ?></span>
                        <?php else: ?>
                          <div class="lj-vaga">?</div>
                          <span class="lj-nome lj-aberta">vaga não preenchida</span>
                          <span class="lj-valor">—</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="lj-fechado">
                      <i class="bi bi-lock-fill"></i> Jogo definido — leilão encerrado.
                      <?php if ($ehMinha): ?>
                        <span>O próximo abre na temporada que vem.</span>
                      <?php endif; ?>
                    </div>

                  <?php elseif (!$a): ?>
                    <div class="lj-vazio">
                      Ninguém deu lance ainda.<br>
                      <?= $ehMinha ? 'O primeiro lance define o jogo da semana.' : '' ?>
                    </div>

                  <?php else: ?>
                    <?php /* O confronto: logo x logo. É o que a pessoa vem
                             ver — qual jogo está ganhando a semana. */ ?>
                    <div class="lj-jogo">
                      <div class="lj-lado">
                        <img src="<?= htmlspecialchars(getTeamPhoto($a['logo'] ?? null)) ?>" alt=""
                             onerror="this.src='/img/default-team.png'">
                        <span class="lj-nome"><?= htmlspecialchars($a['time_nome']) ?></span>
                        <span class="lj-valor"><?= number_format((int)$a['valor'], 0, ',', '.') ?></span>
                      </div>
                      <span class="lj-x">×</span>
                      <div class="lj-lado">
                        <?php if ($b): ?>
                          <img src="<?= htmlspecialchars(getTeamPhoto($b['logo'] ?? null)) ?>" alt=""
                               onerror="this.src='/img/default-team.png'">
                          <span class="lj-nome"><?= htmlspecialchars($b['time_nome']) ?></span>
                          <span class="lj-valor"><?= number_format((int)$b['valor'], 0, ',', '.') ?></span>
                        <?php else: ?>
                          <div class="lj-vaga">?</div>
                          <span class="lj-nome lj-aberta">vaga aberta</span>
                          <span class="lj-valor">—</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endif; ?>

                  <?php /* A fila de quem ficou de fora sai daqui de propósito:
                           o card é sobre QUAL é o jogo da semana. Quem quiser
                           a lista inteira pede /jogosemana no grupo. */ ?>

                  <?php if ($ehMinha && $d['temporada'] > 0 && $team && !$f): ?>
                    <?php if ($d['meu']): ?>
                    <div class="lj-meu <?= $d['meu']['no_podio'] ? 'ok' : 'fora' ?>">
                      <?php if ($d['meu']['no_podio']): ?>
                        Você está no jogo da semana com
                        <b><?= number_format((int)$d['meu']['valor'], 0, ',', '.') ?></b> FBA Points retidos.
                      <?php else: ?>
                        Seu lance de <b><?= number_format((int)$d['meu']['valor'], 0, ',', '.') ?></b>
                        está em <?= (int)$d['meu']['posicao'] ?>º — fora do pódio, e o valor já voltou pra você.
                      <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <form method="post" class="lj-form">
                      <input type="hidden" name="loja_acao" value="leilao_semana">
                      <input type="hidden" name="liga" value="<?= $lg ?>">
                      <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                      <input type="number" name="valor" min="<?= (int)$d['minimo'] ?>" step="1"
                             placeholder="mín. <?= number_format((int)$d['minimo'], 0, ',', '.') ?>"
                             value="<?= (int)$d['minimo'] ?>" required>
                      <button class="lj-bt"><i class="bi bi-hammer"></i> Dar lance</button>
                    </form>
                    <div class="lj-min">
                      Mínimo agora: <b><?= number_format((int)$d['minimo'], 0, ',', '.') ?></b> FBA Points
                      <?php if ($d['meu'] && $d['meu']['no_podio']): ?>
                        · você já tem <?= number_format((int)$d['meu']['valor'], 0, ',', '.') ?> e paga só a diferença
                      <?php endif; ?>
                    </div>
                  <?php elseif (!$ehMinha): ?>
                    <div class="lj-so-olhar"><i class="bi bi-eye"></i> Você só dá lance na sua liga.</div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>

            <div class="sec-label" style="margin-top:26px"><i class="bi bi-box-seam"></i> Meus itens</div>
            <?php if (empty($lojaItens)): ?>
                <div class="card"><div class="vazio">
                    <i class="bi bi-box"></i>
                    <p>Nada guardado ainda. O que você comprar aparece aqui até usar.</p>
                </div></div>
            <?php else: ?>
            <div class="lj-grade">
                <?php foreach ($lojaItens as $inv): $it = $lojaCat[$inv['item_key']] ?? null; if (!$it) continue; ?>
                <div class="lj-item tenho">
                    <div class="lj-ico" style="color:<?= $it['cor'] ?>;background:color-mix(in srgb, <?= $it['cor'] ?> 12%, transparent)">
                        <i class="bi <?= $it['icone'] ?>"></i>
                    </div>
                    <div class="lj-nome"><?= htmlspecialchars($it['nome']) ?></div>
                    <div class="lj-desc">comprado em <?= date('d/m/Y', strtotime($inv['comprado_em'])) ?></div>
                    <?php if ($inv['item_key'] === 'badge'): ?>
                        <?php /* A BADGE NÃO TEM BOTÃO "USAR" AQUI.
                                 Ela é pedida em Meu Elenco, onde o GM diz EM QUEM — e a API
                                 já recusava o botão genérico. Só que o botão continuava na
                                 tela, e um GM clicou nele achando que era assim que se
                                 pedia: a badge saiu do inventário sem virar pedido nenhum,
                                 e ele ficou sem as 3.500 moedas e sem a badge. Botão que
                                 sempre recusa é armadilha, não proteção. */ ?>
                        <a href="/my-roster.php" class="lj-btn usar" style="text-decoration:none">
                            <i class="bi bi-person-badge"></i> Pedir em Meu Elenco
                        </a>
                        <div class="lj-desc" style="margin-top:6px">É lá que você escolhe o jogador.</div>
                    <?php else: ?>
                    <form method="POST" data-confirmar="Usar seu <?= htmlspecialchars($it['nome']) ?>? Ele sai do inventário e a organização é avisada pra aplicar.">
                        <input type="hidden" name="loja_acao" value="usar">
                        <input type="hidden" name="inventario_id" value="<?= (int)$inv['id'] ?>">
                        <button type="submit" class="lj-btn usar"><i class="bi bi-box-arrow-up"></i> Usar</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($lojaFila)): ?>
            <div class="sec-label" style="margin-top:26px"><i class="bi bi-hourglass-split"></i> Já resgatados</div>
            <div class="card">
                <div class="card-body tbl-wrap">
                    <table class="hist">
                        <thead><tr><th>Item</th><th>Quando</th><th>Situação</th></tr></thead>
                        <tbody>
                        <?php foreach ($lojaFila as $p): $it = $lojaCat[$p['item_key']] ?? null; ?>
                            <tr>
                                <td data-rot="Item"><?= htmlspecialchars($it['nome'] ?? $p['item_key']) ?></td>
                                <td data-rot="Quando" style="color:var(--text-3);font-size:12px"><?= date('d/m/Y', strtotime($p['usado_em'])) ?></td>
                                <td data-rot="Situação">
                                    <?php if ($p['atendido_em']): ?>
                                        <span class="pill acertou">Aplicado</span>
                                    <?php else: ?>
                                        <span class="pill aberta">Na fila</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Aba Ranking ───────────────────────────────────────────── -->
        <div class="g-pane <?= $abaInicial === 'ranking' ? 'active' : '' ?>" id="pane-ranking">
            <div class="rk-ligas">
                <?php foreach ($rankingLigas as $chave => $rotulo): ?>
                <button type="button" class="rk-liga <?= $chave === 'GERAL' ? 'active' : '' ?>"
                        data-liga="<?= $chave ?>" onclick="trocarLigaRanking('<?= $chave ?>')">
                    <?= htmlspecialchars($rotulo) ?>
                </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($rankingLigas as $chave => $rotulo): ?>
            <div class="rk-bloco <?= $chave === 'GERAL' ? 'active' : '' ?>" id="rk-<?= $chave ?>">
                <div class="rk-grid">
                    <?php foreach ($rankingCriterios as $crit => $meta):
                        $rk = rankingComigo($rankingBase, $chave, $crit);
                        $lista = $rk['lista'];
                    ?>
                    <div class="card">
                        <div class="card-head">
                            <div class="card-head-left">
                                <i class="bi <?= $meta['icone'] ?>" style="color:<?= $meta['cor'] ?>"></i>
                                <?= htmlspecialchars($meta['label']) ?>
                            </div>
                        </div>
                        <div class="card-body" style="padding:8px 10px 12px">
                            <?php if (empty($lista)): ?>
                                <div class="vazio" style="padding:26px 12px"><p>Sem dados ainda.</p></div>
                            <?php else: ?>
                                <?php foreach ($lista as $i => $r): ?>
                                <div class="rk-linha <?= !empty($r['sou_eu']) ? 'eu' : '' ?>">
                                    <div class="rk-pos"><?= $i + 1 ?></div>
                                    <img class="rk-foto" src="<?= htmlspecialchars($r['foto']) ?>" alt="">
                                    <div class="rk-nome"><?= htmlspecialchars($r['gm']) ?></div>
                                    <div class="rk-val" style="color:<?= $meta['cor'] ?>">
                                        <?= $crit === 'pct'
                                            ? number_format($r['pct'], 1, ',', '.')
                                            : number_format($r[$crit], 0, ',', '.') ?><?= $meta['sufixo'] ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <?php if ($rk['eu']): $r = $rk['eu']; ?>
                                <!-- A SUA LINHA, quando você não está no top.
                                     Separada por uma linha tracejada e não
                                     colada no fim da lista: sem isso ela
                                     pareceria a 16ª colocação, e o número do
                                     lado diria outra coisa. -->
                                <div class="rk-eu-sep"><span>você</span></div>
                                <div class="rk-linha eu">
                                    <div class="rk-pos"><?= (int)$r['pos'] ?></div>
                                    <img class="rk-foto" src="<?= htmlspecialchars($r['foto']) ?>" alt="">
                                    <div class="rk-nome"><?= htmlspecialchars($r['gm']) ?></div>
                                    <div class="rk-val" style="color:<?= $meta['cor'] ?>">
                                        <?= $crit === 'pct'
                                            ? number_format($r['pct'], 1, ',', '.')
                                            : number_format($r[$crit], 0, ',', '.') ?><?= $meta['sufixo'] ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php
                // ── Sequências dos jogos diários ────────────────────────
                //
                // Fora do bloco por liga de propósito: sequência é recorde de
                // constância, e recorde é da casa toda. Filtrar por liga
                // partiria o quadro em quatro listas curtas, e a graça é
                // saber quem está com a maior sequência da FBA inteira.
                require_once __DIR__ . '/games/core/sequencias.php';
                $seqs = seqTodos($pdo, 5);
                $temSeq = false;
                foreach ($seqs as $s) if ($s['top']) { $temSeq = true; break; }
            ?>
            <?php if ($temSeq): ?>
            <div class="rk-seq-tit">
                <i class="bi bi-fire"></i> Maiores sequências
                <span>dias seguidos acertando</span>
            </div>
            <div class="rk-grid">
                <?php foreach ($seqs as $chave => $s):
                    if (!$s['top']) continue;
                    $meta = $s['meta'];
                ?>
                <div class="card">
                    <div class="card-head">
                        <div class="card-head-left">
                            <i class="bi <?= $meta['icone'] ?>" style="color:<?= $meta['cor'] ?>"></i>
                            <?= htmlspecialchars($meta['nome']) ?>
                        </div>
                    </div>
                    <div class="card-body" style="padding:8px 10px 12px">
                        <?php foreach ($s['top'] as $i => $r): ?>
                        <div class="rk-linha <?= (int)$r['user_id'] === (int)$user['id'] ? 'eu' : '' ?>">
                            <div class="rk-pos"><?= $i + 1 ?></div>
                            <img class="rk-foto" src="<?= htmlspecialchars($r['foto']) ?>" alt=""
                                 onerror="this.src='/img/default-avatar.png'">
                            <div class="rk-nome"><?= htmlspecialchars($r['nome']) ?></div>
                            <?php /* A chama fica FORA do .rk-nome, que trunca com
                                     ellipsis — dentro, ela seria a primeira coisa
                                     cortada num nome comprido. E só aparece na
                                     sequência VIVA: sem isso o recorde de quem
                                     parou há meses pareceria estar correndo. */ ?>
                            <?php if ($r['atual'] > 0): ?>
                            <span class="rk-viva" title="<?= (int)$r['atual'] ?> dia(s) seguidos, ainda valendo">🔥</span>
                            <?php endif; ?>
                            <div class="rk-val" style="color:<?= $meta['cor'] ?>"><?= (int)$r['melhor'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php
                // Quem mais ajudou a manter os elencos em dia. Fica no fim do
                // ranking, e não numa aba própria, porque é o mesmo assunto:
                // placar de gente. Só a liga de quem está olhando — o ranking
                // de atualização é da comunidade dela.
                $ligaRk = strtoupper((string)($team['league'] ?? ''));
                $rkAtualiza = $ligaRk ? atualizacaoRanking($pdo, $ligaRk, 12) : [];
            ?>
            <?php if ($rkAtualiza): ?>
            <div class="card" style="margin-top:16px">
                <div class="card-head">
                    <div class="card-head-left">
                        <i class="bi bi-pencil-square" style="color:#22c55e"></i>
                        Quem mais atualizou elencos · <?= htmlspecialchars($ligaRk) ?>
                    </div>
                </div>
                <div class="card-body" style="padding:8px 10px 12px">
                    <?php foreach ($rkAtualiza as $i => $r): ?>
                    <div class="rk-linha <?= (int)$r['user_id'] === (int)$userId ? 'eu' : '' ?>">
                        <div class="rk-pos"><?= $i + 1 ?></div>
                        <div class="rk-nome"><?= htmlspecialchars($r['gm']) ?></div>
                        <div class="rk-val" style="color:#22c55e">
                            <?= (int)$r['times'] ?> time<?= (int)$r['times'] === 1 ? '' : 's' ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <p style="font-size:11.5px;color:var(--text-3,#8a8a99);margin:10px 4px 0;line-height:1.5">
                        Time parado sem skills ou estatísticas pode ser preenchido por qualquer GM da liga,
                        uma vez cada, em <a href="/teams.php" style="color:inherit;text-decoration:underline">Times</a>.
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Aba Banca (enquetes) ──────────────────────────────────────
             O painel inteiro mora em core/enquetes_painel.php, com as classes
             prefixadas em `eq-` pra não brigar com o CSS desta página. -->
        <div class="g-pane <?= $abaInicial === 'eventos' ? 'active' : '' ?>" id="pane-eventos">
            <?php include __DIR__ . '/games/core/enquetes_painel.php'; ?>
        </div>

    </div>
</main>
</div>

<script>
/**
 * Atualizar o leilão sem recarregar a página.
 *
 * O leilão anda enquanto a pessoa está com a aba aberta, e o que ela quer
 * saber é uma coisa só: cobriram o meu lance? Recarregar a página inteira
 * resolveria, mas jogaria ela de volta pra aba de games e perderia o scroll.
 * Então busco a mesma página e troco só a grade do leilão.
 */
(function () {
    var bt = document.getElementById('lj-refresh');
    var grade = document.getElementById('lj-grid');
    if (!bt || !grade) return;

    var ocupado = false;

    bt.addEventListener('click', async function () {
        if (ocupado) return;
        ocupado = true;
        bt.disabled = true;
        bt.classList.add('girando');
        grade.classList.add('atualizando');

        var antes = grade.dataset.meu;

        // O aviso é sobre ESTE clique. Deixar o anterior na tela faria a
        // pessoa ler "cobriram seu lance" de novo num clique em que nada
        // mudou, e ela iria conferir um susto que já tinha acontecido.
        var velho = document.getElementById('lj-aviso');
        if (velho) velho.remove();

        try {
            var r = await fetch('games.php?aba=loja', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!r.ok) throw new Error(r.status);

            var doc = new DOMParser().parseFromString(await r.text(), 'text/html');
            var nova = doc.getElementById('lj-grid');
            // Sem a grade no HTML que voltou (sessão caiu, por exemplo) não
            // troco nada: deixar o bloco vazio seria pior que ficar velho.
            if (!nova) throw new Error('sem grade');

            grade.replaceWith(nova);
            grade = nova;
            bt.classList.remove('girando');

            // Só falo quando a MINHA situação mudou. Um aviso a cada clique
            // viraria ruído e a pessoa pararia de ler justamente o aviso que
            // importa.
            var agora = nova.dataset.meu;
            if (antes === 'podio' && agora === 'fora') {
                ljAviso('Cobriram o seu lance — você saiu do jogo da semana.', 'ruim');
            } else if (antes === 'fora' && agora === 'podio') {
                ljAviso('Você voltou pro jogo da semana.', 'bom');
            }
        } catch (e) {
            bt.classList.remove('girando');
            ljAviso('Não deu pra atualizar agora. Tente de novo.', 'ruim');
        } finally {
            grade.classList.remove('atualizando');
            bt.classList.remove('girando');
            bt.disabled = false;
            ocupado = false;
        }
    });

    function ljAviso(texto, tom) {
        var velho = document.getElementById('lj-aviso');
        if (velho) velho.remove();
        var d = document.createElement('div');
        d.id = 'lj-aviso';
        d.className = 'lj-meu ' + (tom === 'bom' ? 'ok' : 'fora');
        d.style.margin = '0 0 12px';
        d.textContent = texto;
        grade.parentNode.insertBefore(d, grade);
    }
})();

function trocarAba(aba) {
    document.querySelectorAll('.g-tab').forEach(t => t.classList.toggle('active', t.dataset.aba === aba));
    document.querySelectorAll('.g-pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + aba));
    try { sessionStorage.setItem('gamesAba', aba); } catch (e) {}
    history.replaceState(null, '', aba === 'games' ? location.pathname : '?aba=' + aba);
}

function trocarLigaRanking(liga) {
    document.querySelectorAll('.rk-liga').forEach(b => b.classList.toggle('active', b.dataset.liga === liga));
    document.querySelectorAll('.rk-bloco').forEach(b => b.classList.toggle('active', b.id === 'rk-' + liga));
}


/* ── PALPITAR SEM RECARREGAR ────────────────────────────────────────────
 *
 * Era isso que fazia a aba parecer travada: cada clique era um POST comum,
 * e a resposta remontava a página INTEIRA — perfil, minigames, ranking, os
 * cinquenta eventos — pra mudar uma borda de botão. Agora o mesmo endpoint
 * responde só os números e a tela se conserta no lugar.
 *
 * O <form> continua um form de verdade: quem estiver sem JS clica e a
 * página recarrega, como sempre funcionou. O campo que liga o modo JSON
 * nasce DESABILITADO no HTML e só é ligado aqui — assim ele não é enviado
 * por engano num envio sem JS.
 */
(function () {
    const pane = document.getElementById('pane-apostas');
    if (!pane || !window.fetch) return;

    pane.querySelectorAll('[data-ajax-flag]').forEach(i => { i.disabled = false; });

    const aviso = (texto, erro) => {
        let cx = document.getElementById('apostaAviso');
        if (!cx) {
            cx = document.createElement('div');
            cx.id = 'apostaAviso';
            pane.insertBefore(cx, pane.firstChild);
        }
        cx.className = 'alerta ' + (erro ? 'err' : 'ok');
        cx.innerHTML = '<i class="bi bi-' + (erro ? 'exclamation-triangle-fill' : 'check-circle-fill') + '"></i> ' + texto;
        clearTimeout(cx._t);
        cx._t = setTimeout(() => cx.remove(), 3200);
    };

    pane.addEventListener('submit', async (ev) => {
        const form = ev.target.closest('form');
        if (!form || !form.querySelector('[name="opcao_id"]')) return;
        ev.preventDefault();

        const card = form.closest('.card');
        if (!card || card.dataset.enviando === '1') return;
        card.dataset.enviando = '1';
        card.classList.add('enviando');

        // A ESCOLHA APARECE ANTES DA RESPOSTA. A ida ao servidor leva uns
        // duzentos milésimos e é ela que a pessoa sentia como travamento;
        // marcar na hora e corrigir depois, se der erro, é o que faz o
        // botão parecer instantâneo. As porcentagens NÃO são adivinhadas
        // aqui — chutar número pra corrigir meio segundo depois é pior que
        // esperar, porque a barra pularia duas vezes.
        const escolhido = form.querySelector('.op-btn');
        const antes = card.querySelector('.op-btn.escolhida');
        card.querySelectorAll('.op-btn').forEach(b => b.classList.remove('escolhida'));
        escolhido?.classList.add('escolhida');

        try {
            const r = await fetch(location.pathname, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body: new FormData(form),
            });
            const d = await r.json();
            if (!d.ok) throw new Error(d.erro || 'Não deu pra registrar.');

            const porId = {};
            (d.opcoes || []).forEach(o => { porId[o.id] = o; });
            card.querySelectorAll('form').forEach(f => {
                const id = Number(f.querySelector('[name="opcao_id"]')?.value);
                const dados = porId[id];
                if (!dados) return;
                const btn = f.querySelector('.op-btn');
                const barra = btn?.querySelector('.op-barra');
                const pct = btn?.querySelector('.op-pct');
                if (barra) barra.style.width = dados.pct + '%';
                if (pct) pct.textContent = dados.pct + '%';
                if (btn) btn.title = dados.n + (dados.n === 1 ? ' palpite' : ' palpites');
            });

            // O total no cabeçalho: ele pode não existir ainda, quando este
            // era o primeiro palpite do evento.
            let cxTotal = card.querySelector('.ev-total');
            if (!cxTotal && d.total > 0) {
                cxTotal = document.createElement('div');
                cxTotal.className = 'prazo ev-total';
                cxTotal.title = 'Total de palpites neste evento';
                cxTotal.innerHTML = '<i class="bi bi-people-fill"></i> <span></span>';
                card.querySelector('.card-head-dir')?.prepend(cxTotal);
            }
            if (cxTotal) cxTotal.querySelector('span').textContent = d.total;

            // A dica de "dá pra trocar" só aparece depois do primeiro palpite.
            if (!card.querySelector('.op-dica')) {
                const dica = document.createElement('div');
                dica.className = 'op-dica';
                dica.innerHTML = '<i class="bi bi-info-circle"></i> Dá pra trocar sua escolha até o prazo acabar.';
                card.querySelector('.card-body')?.appendChild(dica);
            }
            aviso(d.msg || 'Palpite registrado.', false);
        } catch (e) {
            // Desfaz a marca otimista: o servidor não aceitou, então a tela
            // não pode ficar dizendo que aceitou.
            card.querySelectorAll('.op-btn').forEach(b => b.classList.remove('escolhida'));
            antes?.classList.add('escolhida');
            aviso(e.message || 'Não deu pra registrar seu palpite.', true);
        } finally {
            card.dataset.enviando = '0';
            card.classList.remove('enviando');
        }
    });
})();

/* ── A CONTA DA TROCA, ENQUANTO DIGITA ──────────────────────────────────
 *
 * Dez por um e conta fácil, mas ninguém quer fazer conta pra saber se vale.
 * E o arredondamento aparece aqui: digitar 1005 mostra 100 pontos, e não
 * 100,5 — assim a pessoa vê que as cinco sobrando não entram ANTES de
 * clicar, e não depois de perder elas.
 */
(function () {
    const campo = document.getElementById('ljMoedas');
    const saida = document.getElementById('ljVira');
    if (!campo || !saida) return;
    const taxa = Number(campo.dataset.taxa) || 10;
    const teto = Number(campo.max) || 0;

    // Enquanto digita: so mostra a conta. Arredondar a cada tecla impediria
    // de escrever 100 — o 1 viraria 0 antes do segundo digito chegar.
    campo.addEventListener('input', () => {
        const n = Math.max(0, Math.floor(Number(campo.value) || 0));
        const pts = Math.floor(n / taxa);
        saida.textContent = '= ' + pts.toLocaleString('pt-BR') + ' FBA Points';
    });

    // Ao sair do campo: ARREDONDA PRA BAIXO no multiplo. 12 vira 10, 2505 vira
    // 2500. Arredondar em vez de recusar e o ponto todo: com o step sozinho, o
    // navegador so travava o envio e nao dizia o motivo — a pessoa clicava em
    // Trocar e nada acontecia.
    const ajustar = () => {
        const bruto = Math.floor(Number(campo.value) || 0);
        // Saldo menor que a taxa: o botão já nasce desabilitado, e o piso lá
        // embaixo poria um 10 no campo de quem não tem 10 moedas.
        if (bruto <= 0 || teto <= 0) { campo.value = ''; saida.textContent = '= 0 FBA Points'; return; }
        let n = Math.floor(bruto / taxa) * taxa;
        if (teto > 0 && n > teto) n = teto;   // nunca oferece mais do que cabe no saldo
        if (n < taxa) n = taxa;               // 4 moedas viram o minimo, nao zero
        campo.value = n;
        campo.dispatchEvent(new Event('input'));
    };
    campo.addEventListener('change', ajustar);
    campo.addEventListener('blur', ajustar);

    // O ajuste tem que acontecer ANTES da validação do navegador, e não no
    // submit: com step=10 e o campo em 12, o navegador barra o envio, mostra
    // "os dois valores válidos mais próximos são 10 e 20" e o evento submit
    // NUNCA chega — que é exatamente o balão sem explicação que fez o step
    // ser removido daqui da primeira vez.
    //
    // O clique no botão e o Enter no campo rodam antes dessa validação, então
    // é neles que o 12 vira 10 — e aí o formulário sai válido.
    campo.form?.querySelector('button[type="submit"]')?.addEventListener('click', ajustar);
    campo.addEventListener('keydown', (e) => { if (e.key === 'Enter') ajustar(); });
})();

/* ── BUSCA E FILTRO DOS MEUS PALPITES ───────────────────────────────────
 *
 * Tudo em cima do que já está na tela: a lista inteira veio do servidor de
 * uma vez, então procurar é esconder linha, não ir ao banco. Cada linha
 * carrega o texto já em minúsculas num data-attribute — normalizar a cada
 * tecla digitada seria refazer o mesmo trabalho quinhentas vezes por letra.
 */
(function () {
    const busca = document.getElementById('plBusca');
    const filtros = document.getElementById('plFiltros');
    const tabela = document.getElementById('plTabela');
    const vazio = document.getElementById('plVazio');
    if (!tabela) return;

    const linhas = [...tabela.querySelectorAll('tbody tr')];
    let filtro = 'todos';

    function aplicar() {
        const termo = (busca?.value || '').trim().toLowerCase();
        let vistas = 0;
        linhas.forEach(tr => {
            const passaFiltro = filtro === 'todos' || tr.dataset.res === filtro;
            const passaBusca = !termo || (tr.dataset.txt || '').includes(termo);
            const mostra = passaFiltro && passaBusca;
            tr.style.display = mostra ? '' : 'none';
            if (mostra) vistas++;
        });
        tabela.style.display = vistas ? '' : 'none';
        if (vazio) vazio.style.display = vistas ? 'none' : '';
    }

    busca?.addEventListener('input', aplicar);
    filtros?.addEventListener('click', (e) => {
        const b = e.target.closest('.pl-f');
        if (!b) return;
        filtro = b.dataset.f;
        filtros.querySelectorAll('.pl-f').forEach(x => x.classList.toggle('active', x === b));
        aplicar();
    });
})();

(function () {
    // Só restaura a aba salva quando a URL não pediu uma explicitamente.
    if (!location.search.includes('aba=')) {
        try {
            const salva = sessionStorage.getItem('gamesAba');
            if (salva && salva !== 'games') trocarAba(salva);
        } catch (e) {}
    }
    const menuBtn = document.getElementById('menuBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sbOverlay');
    menuBtn?.addEventListener('click', () => { sidebar?.classList.add('open'); overlay?.classList.add('open'); });
    overlay?.addEventListener('click', () => { sidebar?.classList.remove('open'); overlay?.classList.remove('open'); });

    /* ── Slot de tela: copiar a lista, e abrir sozinho na hora ────────── */

    document.querySelectorAll('[data-copiar]').forEach(bt => {
        bt.addEventListener('click', async () => {
            const txt = bt.dataset.copiar || '';
            let ok = false;
            try {
                await navigator.clipboard.writeText(txt);
                ok = true;
            } catch (e) {
                // clipboard exige contexto seguro, e a liga abre o site por
                // http de vez em quando. O caminho velho ainda funciona lá.
                try {
                    const ta = document.createElement('textarea');
                    ta.value = txt;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    ok = document.execCommand('copy');
                    ta.remove();
                } catch (e2) { ok = false; }
            }
            const antes = bt.innerHTML;
            bt.innerHTML = ok
                ? '<i class="bi bi-check2"></i> Copiado'
                : '<i class="bi bi-x"></i> Não deu';
            bt.classList.toggle('ok', ok);
            setTimeout(() => { bt.innerHTML = antes; bt.classList.remove('ok'); }, 1800);
        });
    });

    /* A VENDA ABRE SOZINHA. Quem deixa a aba aberta esperando a hora não
       precisa mais adivinhar quando atualizar: o aviso vira contagem
       regressiva e, no zero, a página recarrega e o botão aparece.

       Os segundos vêm do SERVIDOR (data-faltam) e não de uma comparação com
       o relógio local — quem estiver com a hora adiantada recarregaria cedo,
       veria o botão e levaria recusa na compra. */
    /* ENQUANTO A VENDA ESTÁ ABERTA, O CARD SE ATUALIZA SOZINHO.
       São oito vagas e quem chega primeiro leva: a página parada mente. Quem
       abriu a loja com seis livres e voltou dez minutos depois via seis,
       clicava, e só então descobria que tinha acabado — a recusa do servidor
       estava certa, mas a pessoa gastou a decisão olhando um número velho.

       A cada dez segundos o card relê o estado. O servidor continua sendo
       quem decide a compra; isto aqui é só o retrato ficar honesto. Quando
       esgota, o formulário sai da tela na hora. */
    const card = document.querySelector('.st-card');
    if (card && card.querySelector('form.st-form')) {
        const vagas = card.querySelector('.st-vagas');
        const sub   = card.querySelector('.st-sub');
        let parado  = false;

        const desenhar = (d) => {
            if (sub) sub.textContent = d.restam + ' de ' + d.total + ' slots livres';

            if (vagas) {
                vagas.innerHTML = '';
                for (let i = 0; i < d.total; i++) {
                    const it = d.lista[i];
                    const s = document.createElement('span');
                    s.className = 'st-vaga' + (it ? ' cheia' : '');
                    s.title = it ? it.cheio : 'livre';
                    if (it) {
                        const img = document.createElement('img');
                        img.src = it.logo;
                        img.alt = '';
                        img.onerror = function () { this.src = '/img/default-team.png'; };
                        s.appendChild(img);
                    }
                    vagas.appendChild(s);
                }
            }

            const bt = card.querySelector('.st-copiar');
            if (bt && d.copiar) bt.dataset.copiar = d.copiar;

            // Acabou a vaga (ou o meu time entrou por outra aba): o botão de
            // comprar não pode continuar de pé oferecendo o que não existe.
            if (!d.aberta) {
                const form = card.querySelector('form.st-form');
                if (form) {
                    const aviso = document.createElement('div');
                    aviso.className = 'st-aviso';
                    aviso.innerHTML = d.motivo === 'esgotado'
                        ? '<i class="bi bi-lock-fill"></i> Os oito slots já foram.'
                        : d.motivo === 'ja_tenho'
                            ? '<i class="bi bi-check-circle-fill" style="color:var(--green)"></i> Seu time já está na tela desta live.'
                            : '<i class="bi bi-broadcast"></i> A live já começou — a venda fechou.';
                    form.replaceWith(aviso);
                }
                parado = true;
            }
        };

        const olhar = async () => {
            if (parado) return;
            try {
                const r = await fetch('/api/slots-tela.php', { cache: 'no-store' });
                const d = await r.json();
                if (d && d.ok && d.live) desenhar(d);
            } catch (e) { /* rede caiu: o card fica como está, e tenta de novo */ }
            if (!parado) setTimeout(olhar, 10000);
        };
        setTimeout(olhar, 10000);
    }

    const espera = document.getElementById('stEspera');
    if (espera) {
        let faltam = parseInt(espera.dataset.faltam || '0', 10);
        const original = espera.innerHTML;
        if (faltam > 0) {
            const tick = () => {
                faltam--;
                if (faltam <= 0) {
                    espera.textContent = 'Abrindo a venda…';
                    location.reload();
                    return;
                }
                // Só vira cronômetro na reta final. Faltando três horas, um
                // relógio correndo é ruído; faltando dois minutos, é a
                // informação que importa.
                if (faltam <= 600) {
                    const m = Math.floor(faltam / 60), s = faltam % 60;
                    espera.innerHTML = 'A venda abre em <b>' + m + ':' +
                        String(s).padStart(2, '0') + '</b>.';
                } else {
                    espera.innerHTML = original;
                }
                setTimeout(tick, 1000);
            };
            setTimeout(tick, 1000);
        }
    }
})();
</script>
</body>
</html>
