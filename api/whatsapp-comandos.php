<?php
/**
 * Os comandos que o bot responde no grupo.
 *
 * Separado do webhook de propósito: aqui não há nada de HTTP, só PDO entrando e
 * texto saindo. Dá pra testar cada comando pela linha de comando sem simular
 * requisição nenhuma.
 *
 * Todo comando devolve string (a resposta) ou null (não é comando meu — fica
 * quieto, porque o grupo é de gente conversando e bot que responde "comando
 * inválido" a cada barra vira ruído).
 */

require_once __DIR__ . '/../backend/salary_cap.php';
require_once __DIR__ . '/../backend/helpers.php';   // CAP_TOP_N
require_once __DIR__ . '/../backend/pick_protection.php';   // condicao da pick
require_once __DIR__ . '/../backend/playoff_series.php';

/**
 * Nome de exibição do time: "Cidade Nome", como o resto do app monta.
 *
 * Não é cidade + mascot: são campos diferentes e, quando divergem, o mascot
 * dá o nome errado. Aceita team_name pra quando a consulta precisou apelidar
 * a coluna (JOIN com players, que também tem `name`).
 */
function wcNomeDoTime(array $t): string
{
    $nome = trim((string)($t['team_name'] ?? $t['name'] ?? ''));
    $composto = trim(trim((string)($t['city'] ?? '')) . ' ' . $nome);
    return $composto !== '' ? $composto : ($nome !== '' ? $nome : '?');
}

/**
 * As dez skills, na ordem em que fazem sentido ler: ataque, defesa, resto.
 * Rótulo curto de propósito — no WhatsApp cada linha conta.
 */
const WC_SKILLS = [
    'skill_in'     => 'Garrafão',
    'skill_mid'    => 'Média',
    'skill_3pt'    => '3 pontos',
    'skill_post_d' => 'Def. poste',
    'skill_per_d'  => 'Def. perím.',
    'skill_play'   => 'Passe',
    'skill_reb'    => 'Rebote',
    'skill_athl'   => 'Atletismo',
    'skill_iq'     => 'QI de jogo',
    'skill_pot'    => 'Potencial',
];

/** Nota em número, pra dar pra comparar letra com letra. Mesma escala do site. */
function wcNotaSkill($v): ?float
{
    if ($v === null || $v === '' || $v === '-') return null;
    if (is_numeric($v)) return (float)$v;
    $tabela = ['A+'=>95,'A'=>90,'A-'=>85,'B+'=>80,'B'=>75,'B-'=>70,
               'C+'=>65,'C'=>60,'C-'=>55,'D+'=>50,'D'=>45,'D-'=>40,'F'=>30];
    return $tabela[strtoupper(trim((string)$v))] ?? null;
}

/**
 * Skills do jogador, já resolvidas.
 *
 * A coluna skill_* manda; o JSON player_skill_grades é o retrato antigo e só
 * vale onde a coluna está vazia. Mesma regra do statsjogadores.php e do
 * player.php — se eu lesse só uma das fontes, jogador cadastrado pelo caminho
 * antigo apareceria sem skill nenhuma.
 *
 * Devolve [rótulo => valor], só com as preenchidas.
 */
function wcSkillsDoJogador(array $p): array
{
    $json = [];
    if (!empty($p['player_skill_grades'])) {
        $d = json_decode((string)$p['player_skill_grades'], true);
        if (is_array($d)) $json = $d;
    }
    // As chaves do JSON não são iguais às das colunas.
    $doJson = ['skill_in'=>'in','skill_mid'=>'mid','skill_3pt'=>'pt3','skill_post_d'=>'post_d',
               'skill_per_d'=>'per_d','skill_play'=>'play','skill_reb'=>'reb','skill_athl'=>'athl',
               'skill_iq'=>'iq','skill_pot'=>'pot'];

    $out = [];
    foreach (WC_SKILLS as $col => $rotulo) {
        $v = $p[$col] ?? null;
        if ($v === null || $v === '' || $v === '-') $v = $json[$doJson[$col]] ?? null;
        if ($v === null || $v === '' || $v === '-') continue;
        $out[$rotulo] = $v;
    }
    return $out;
}

/** Trecho do SELECT com as colunas de skill — usado em mais de uma consulta. */
function wcColunasSkill(string $alias = 'p'): string
{
    return $alias . '.player_skill_grades, '
         . implode(', ', array_map(fn($c) => $alias . '.' . $c, array_keys(WC_SKILLS)));
}

/**
 * Números da temporada mais recente em que o jogador teve lançamento.
 *
 * Não fixo na temporada "atual" de propósito: quem não jogou ainda apareceria
 * zerado, e mostrar o último ano que ele tem é mais útil que mostrar nada.
 * O retorno diz de qual temporada é, pra ninguém confundir.
 */
function wcStatsDoJogador(PDO $pdo, int $playerId): ?array
{
    try {
        $st = $pdo->prepare("
            SELECT ps.season_number, ps.games, ps.min_pg, ps.pts_pg, ps.reb_pg,
                   ps.ast_pg, ps.stl_pg, ps.blk_pg
            FROM player_season_stats ps
            WHERE ps.player_id = ? AND ps.games > 0
            ORDER BY ps.season_number DESC, ps.id DESC
            LIMIT 1
        ");
        $st->execute([$playerId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('[whatsapp-cmd] stats: ' . $e->getMessage());
        return null;
    }
}

/** Número curto pro WhatsApp: 24.1 vira "24,1" e 7.0 vira "7". */
function wcNum($v): string
{
    $n = (float)$v;
    return $n == floor($n) ? (string)(int)$n : number_format($n, 1, ',', '');
}

/** Coluna de OVR — o banco antigo usava 'overall'. */
function wcColunaOvr(PDO $pdo): string
{
    static $col = null;
    if ($col === null) {
        $st = $pdo->query("SHOW COLUMNS FROM players LIKE 'ovr'");
        $col = ($st && $st->rowCount() > 0) ? 'ovr' : 'overall';
    }
    return $col;
}

/** A liga cobra folha em dinheiro (ELITE hoje) ou soma de OVR? */
function wcLigaEmSalario(PDO $pdo, string $league): bool
{
    $st = $pdo->prepare("SELECT cap_mode FROM league_settings WHERE league = ?");
    $st->execute([$league]);
    return (($st->fetchColumn() ?: 'ovr_sum') === 'salary');
}

/**
 * CAP das ligas que não usam dinheiro (RISE, NEXT, ROOKIE): a soma dos
 * CAP_TOP_N maiores OVR do elenco contra a faixa da liga.
 *
 * O cálculo é o de backend/helpers.php, o mesmo que o dashboard mostra — não
 * uma segunda versão da regra aqui.
 */
function wcCapPorOvr(PDO $pdo, array $t): array
{
    $soma = topOvrCap($pdo, (int)$t['id']);

    $min = 0; $maxBase = 0;
    try {
        $st = $pdo->prepare('SELECT cap_min, cap_max FROM league_settings WHERE league = ?');
        $st->execute([(string)$t['league']]);
        if ($linha = $st->fetch(PDO::FETCH_ASSOC)) {
            $min = (int)($linha['cap_min'] ?? 0);
            $maxBase = (int)($linha['cap_max'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('[wcCapPorOvr] ' . $e->getMessage());
    }

    // O teto que vale é o da liga mais o bônus de jogador restrito — é o que
    // o app mostra, e faixa diferente entre bot e app é briga na certa.
    $max = $maxBase > 0 ? $maxBase + restrictedCapBonus($pdo, (int)$t['id']) : 0;

    return ['soma' => $soma, 'min' => $min, 'max' => $max];
}

/** A linha de CAP por OVR, do jeito que entra no /time. */
function wcLinhaCapOvr(array $c): string
{
    // Sem faixa configurada na liga, mostra só a soma.
    return "CAP: *{$c['soma']}*"
        . ($c['max'] > 0 ? " ({$c['min']}–{$c['max']})" : '') . "\n";
}

/**
 * Acha o time pelo que a pessoa digitou. Ela vai escrever "Lakers", não
 * "Los Angeles Lakers" — então aceito o pedaço no meio da palavra.
 *
 * Procuro no nome completo ("Cidade Nome", que é como o time aparece) e também
 * no mascot: ele não é usado pra exibir, mas alguém pode digitar por ele.
 */
function wcAcharTimes(PDO $pdo, string $termo, ?string $ligaDoGrupo = null): array
{
    $like = '%' . $termo . '%';
    $ordem = wcOrdemLiga($ligaDoGrupo);
    $st = $pdo->prepare("
        SELECT t.id, t.name, t.city, t.mascot, t.league, t.conference, u.name AS gm
        FROM teams t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.name LIKE ? OR t.city LIKE ? OR t.mascot LIKE ?
           OR CONCAT(t.city, ' ', t.name) LIKE ?
        ORDER BY {$ordem}, t.city
        LIMIT 6
    ");
    $st->execute([$like, $like, $like, $like]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Resolve o termo pra UM time. Se der ambíguo, devolve a lista pra pessoa
 * escolher em vez de chutar o primeiro — chutar é pior que perguntar.
 * Retorna [time|null, mensagemDeErro|null].
 */
function wcResolverTime(PDO $pdo, string $termo, ?string $ligaDoGrupo = null): array
{
    $times = wcAcharTimes($pdo, $termo, $ligaDoGrupo);
    if (!$times) {
        return [null, "Não achei time com \"{$termo}\"."];
    }

    // Mesmo apelido em duas divisões não é ambiguidade de verdade: no Chat Off
    // Geral vale a ELITE, no grupo de uma liga vale a liga dele.
    $times = wcSoDaLiga($times, $ligaDoGrupo);
    if (count($times) > 1) {
        // Match exato desempata: quem digitou "Heat" quer o Heat, mesmo que
        // exista um "Heaters" na lista.
        $exatos = array_values(array_filter($times, function ($t) use ($termo) {
            $alvo = mb_strtolower($termo);
            return mb_strtolower((string)$t['mascot']) === $alvo
                || mb_strtolower((string)$t['city']) === $alvo
                || mb_strtolower((string)$t['name']) === $alvo
                || mb_strtolower(wcNomeDoTime($t)) === $alvo;
        }));
        if (count($exatos) === 1) return [$exatos[0], null];

        $lista = implode("\n", array_map(fn($t) => '• ' . wcNomeDoTime($t) . ' (' . $t['league'] . ')', $times));
        return [null, "Achei mais de um com \"{$termo}\":\n{$lista}\n\nSeja mais específico."];
    }
    return [$times[0], null];
}

/** Temporada ativa da liga (a que está rolando), pra classificação. */
/**
 * A temporada corrente da liga — a da SPRINT ATIVA.
 *
 * Ordenava por season_number, e isso quebrava toda vez que uma sprint nova
 * começava: a sprint anterior tinha ido até a temporada 20, a nova começa na
 * 1, e "maior número" devolvia a 20 — uma temporada de um ciclo encerrado. O
 * bot então respondia "a ELITE ainda não tem jogos na temporada 20" enquanto
 * a liga jogava a temporada 1.
 *
 * A ordem certa é por `id`: temporada criada depois tem id maior, sempre, e
 * é assim que o admin resolve a mesma pergunta (case 'current_season'). O
 * status entra só pra preferir uma temporada em aberto quando ela existe.
 */
function wcTemporadaAtiva(PDO $pdo, string $league): ?array
{
    // 1) Em aberto, dentro da sprint ativa. É a resposta certa quase sempre.
    $st = $pdo->prepare("SELECT s.id, s.season_number, s.status, sp.sprint_number
                           FROM seasons s
                           JOIN sprints sp ON sp.id = s.sprint_id
                          WHERE s.league = ? AND sp.status = 'active'
                            AND (s.status IS NULL OR s.status <> 'completed')
                       ORDER BY s.id DESC LIMIT 1");
    $st->execute([$league]);
    if ($s = $st->fetch(PDO::FETCH_ASSOC)) return $s;

    // 2) A última da sprint ativa, mesmo já encerrada — entre o fim de uma
    //    temporada e a criação da seguinte não há nenhuma "em aberto".
    $st = $pdo->prepare("SELECT s.id, s.season_number, s.status, sp.sprint_number
                           FROM seasons s
                           JOIN sprints sp ON sp.id = s.sprint_id
                          WHERE s.league = ? AND sp.status = 'active'
                       ORDER BY s.id DESC LIMIT 1");
    $st->execute([$league]);
    if ($s = $st->fetch(PDO::FETCH_ASSOC)) return $s;

    // 3) Sem sprint ativa (liga recém-criada, ou sprint fechada e a próxima
    //    ainda não aberta): a última temporada que existe, por id.
    $st = $pdo->prepare("SELECT id, season_number, status FROM seasons
                          WHERE league = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$league]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Normaliza o nome da liga que a pessoa digitou. */
/** As ligas na ordem das divisões: ELITE é a 1ª, ROOKIE é a 4ª. */
const WC_DIVISOES = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

/**
 * A liga que desempata quando o mesmo nome existe em mais de uma.
 *
 * O grupo de cada liga já traz a sua. O Chat Off Geral não tem liga amarrada,
 * e a ROOKIE reaproveita os mesmos jogadores da ELITE — então "/jogador
 * lebron" lá respondia "Achei 2 com lebron" toda vez, e a resposta útil nunca
 * chegava. Sem liga do grupo, vale a ELITE: é a primeira divisão.
 */
function wcLigaPreferida(?string $ligaDoGrupo): string
{
    $l = strtoupper(trim((string)$ligaDoGrupo));
    return in_array($l, WC_DIVISOES, true) ? $l : 'ELITE';
}

/** ORDER BY que põe a liga preferida na frente, depois a ordem das divisões. */
function wcOrdemLiga(?string $ligaDoGrupo, string $alias = 't'): string
{
    $pref  = wcLigaPreferida($ligaDoGrupo);
    $ordem = array_merge([$pref], array_values(array_diff(WC_DIVISOES, [$pref])));
    return "FIELD({$alias}.league,'" . implode("','", $ordem) . "')";
}

/**
 * Fica só com os achados da divisão mais alta em que o nome aparece.
 *
 * A ordem é: a liga do grupo primeiro, depois ELITE, NEXT, RISE e ROOKIE —
 * que é a ordem das divisões. Então no Chat Off Geral um nome que existe na
 * ELITE e na ROOKIE resolve pra ELITE; um que existe só na NEXT e na RISE
 * resolve pra NEXT; e a ROOKIE só ganha quando é a única que tem.
 *
 * Nada é escondido de propósito: se a divisão escolhida não tem ninguém, a
 * busca continua descendo. Quem chama avisa em uma linha o que ficou de fora.
 */
function wcSoDaLiga(array $linhas, ?string $ligaDoGrupo): array
{
    $pref  = wcLigaPreferida($ligaDoGrupo);
    $ordem = array_merge([$pref], array_values(array_diff(WC_DIVISOES, [$pref])));

    foreach ($ordem as $div) {
        $daLiga = array_values(array_filter($linhas, fn($l) => ($l['league'] ?? '') === $div));
        if ($daLiga) return $daLiga;
    }
    // Nenhuma divisão conhecida: devolve como veio em vez de zerar a busca.
    return $linhas;
}
/**
 * "_também existe na ROOKIE_" — uma linha dizendo o que ficou de fora.
 *
 * Sem isto o desempate seria mudo, e quem procurava justamente o xará da
 * outra divisão acharia que o bot está errado.
 */
function wcNotaOutrasLigas(array $todos, array $usados): string
{
    $fora = array_values(array_unique(array_diff(
        array_column($todos, 'league'), array_column($usados, 'league'))));
    return $fora ? "\n\n_também existe na " . implode(', ', $fora) . "_" : '';
}

function wcNormalizarLiga(string $termo): ?string
{
    $t = mb_strtoupper(trim($termo));
    foreach (['ELITE', 'NEXT', 'RISE', 'ROOKIE'] as $liga) {
        if ($t === $liga || str_starts_with($liga, $t)) return $liga;
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────
// Comandos
// ─────────────────────────────────────────────────────────────────────────

function wcAjuda(): string
{
    return "*Comandos da FBA*\n\n"
        . "*Consulta*\n"
        . "/jogador _nome_ — time, idade, OVR e salário\n"
        . "/comparar _um_ x _outro_ — jogadores lado a lado\n"
        // /comparartime continua funcionando, mas fica FORA da lista: e de
        // nicho, e cada linha a mais aqui custa atencao de quem so quer o basico.
        . "/confronto _um_ x _outro_ — o duelo entre dois times, com palpite\n"
        . "/time _nome_ — quinteto, banco, folha e posição\n"
        . "/cap _time_ — folha e espaço no cap\n"
        . "/picks _time_ — picks que o time tem
"
        . "/tblock _time_ — quem o time pôs no trade block

"
        . "*Seu time* _(pelo seu número, sem digitar o nome)_\n"
        . "/meutime — seu elenco\n"
        . "/meucap — sua folha e o espaço no cap\n"
        . "/minhaspicks — as picks que você tem\n"
        . "/meutblock — seu trade block
"
        . "/minhastrades — suas 3 últimas trocas\n\n"
        . "*Liga*\n"
        . "/ranking _liga_ — a tabela da liga\n"
        . "/playoffs — o chaveamento, como está agora\n"
        . "/power — o power ranking da liga inteira\n"
        . "/powerc — o power ranking por conferência\n"
        . "/trocas _ou /trades_ — as últimas trocas aprovadas (aceita _time_ ou _liga_)\n"
        // Este entra no /ajuda (a escala não entrou): o jogo da semana é da
        // liga inteira e qualquer time pode disputar, então é assunto de
        // quem lê esta lista.
        . "/jogosemana — o jogo da semana e o lance pra tomar a vaga\n"
        . "/apostas — a parcial das apostas abertas\n"
        . "/apostasresultado — as últimas 10 apostas pagas\n"
        // A escala NÃO entra aqui, nem numa linha só. Ela é assunto do grupo
        // de lives, e o /ajuda é lido pela liga inteira — pra quem não
        // participa das lives, a linha só gera "o que é isso?". Quem precisa
        // recebe o /live fixado no grupo certo.

        // /aceitar e /recusar SAÍRAM desta lista, mas continuam funcionando:
        // o código vem escrito na própria mensagem da proposta, e é lá que a
        // pessoa lê a instrução. Aqui eles só ocupavam a linha mais comprida
        // do /ajuda pra quem não tem proposta nenhuma esperando.
        . "/lendas — os marcados como LENDA\n"
        . "/hall — o Hall da Fama\n"
        . "/premios — os prêmios da temporada\n"
        . "/estatisticas — recordes e curiosidades da liga
"
        . "/apostas — os eventos abertos pra palpitar, e o prazo de cada um\n"
        . "/guia — o guia do GM\n\n"
        // /quizaqui existe e continua funcionando, mas fica FORA desta lista:
        // é comando de organização, usado uma vez pra apontar onde o quiz sai.
        // Numa ajuda que a liga inteira lê, ele só gera "o que é isso?".
        . "Ex.: /comparar lebron x tatum  •  /meucap  •  /minhastrades";
}

/**
 * /apostas mora em backend/apostas.php (apostasTextoParcial).
 *
 * A versão que ficava aqui mostrava só a opção na frente, e o comentário
 * dela defendia isso: a lista inteira "viraria uma parede de trinta linhas
 * que ninguém lê". A liga pediu o contrário — quer a parcial completa, com
 * a porcentagem de cada opção e a mais votada em negrito — então a decisão
 * foi revista. O limite de eventos continua existindo pra parede não
 * acontecer de verdade.
 *
 * Foi pro backend porque a conta das porcentagens é a MESMA do site, e
 * tê-la escrita aqui era a terceira cópia dela.
 */

function wcJogador(PDO $pdo, string $termo, ?string $ligaDoGrupo = null): string
{
    if ($termo === '') return "Use assim: /jogador lebron";

    $ovr = wcColunaOvr($pdo);
    $ordem = wcOrdemLiga($ligaDoGrupo);
    $st = $pdo->prepare("
        SELECT p.id, p.name, p.age, p.position, p.secondary_position, p.{$ovr} AS ovr,
               p.seasons_in_league, p.team_id, COALESCE(p.is_lenda, 0) AS is_lenda,
               " . wcColunasSkill('p') . ",
               t.city, t.mascot, t.name AS team_name, t.league
        FROM players p JOIN teams t ON t.id = p.team_id
        WHERE p.name LIKE ?
        ORDER BY {$ordem}, p.{$ovr} DESC
        LIMIT 8
    ");
    $st->execute(['%' . $termo . '%']);
    $achados = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$achados) return "Não achei jogador com \"{$termo}\".";

    // O mesmo jogador existe na ELITE e na ROOKIE — sem desempate, toda
    // busca no Chat Off Geral virava "Achei 2 com lebron".
    $todos    = $achados;
    $achados  = wcSoDaLiga($achados, $ligaDoGrupo);
    $notaLiga = wcNotaOutrasLigas($todos, $achados);

    // Vários: lista enxuta, senão a mensagem vira parede de texto no grupo.
    if (count($achados) > 1) {
        $linhas = array_map(function ($p) {
            return '• *' . $p['name'] . '* — ' . $p['ovr'] . ' OVR, '
                . $p['position'] . ', ' . $p['age'] . ' anos — '
                . wcNomeDoTime($p) . ' (' . $p['league'] . ')';
        }, $achados);
        return "Achei " . count($achados) . " com \"{$termo}\":\n" . implode("\n", $linhas) . $notaLiga;
    }

    $p = $achados[0];
    $pos = $p['position'] . ($p['secondary_position'] ? '/' . $p['secondary_position'] : '');
    $txt = "*{$p['name']}*" . (!empty($p['is_lenda']) ? ' 👑 LENDA' : '') . "\n"
        . wcNomeDoTime($p) . " — {$p['league']}\n\n"
        . "OVR: *{$p['ovr']}*\n"
        . "Posição: {$pos}\n"
        . "Idade: {$p['age']} anos\n"
        . "Temporadas na liga: " . (int)$p['seasons_in_league'] . "\n";

    // Salário só faz sentido onde a liga cobra folha em dinheiro.
    if (wcLigaEmSalario($pdo, (string)$p['league'])) {
        $cap = getTeamCapSummary($pdo, (int)$p['team_id']);
        foreach (($cap['roster'] ?? []) as $r) {
            if ((int)$r['id'] === (int)$p['id']) {
                $txt .= "Salário: *{$r['total_salary']}M*\n";
                break;
            }
        }
    }

    if ($s = wcStatsDoJogador($pdo, (int)$p['id'])) {
        $txt .= "\n📊 *Temporada {$s['season_number']}*\n"
              . wcNum($s['pts_pg']) . ' pts · ' . wcNum($s['reb_pg']) . ' reb · ' . wcNum($s['ast_pg']) . " ast\n"
              . wcNum($s['stl_pg']) . ' rou · ' . wcNum($s['blk_pg']) . ' toc · ' . wcNum($s['min_pg']) . ' min'
              . ' em ' . (int)$s['games'] . " jogos\n";
    }

    if ($skills = wcSkillsDoJogador($p)) {
        $txt .= "\n⭐ *Skills*\n";
        // Duas por linha: dez linhas soltas viram parede de texto no celular.
        $pares = array_chunk(array_map(
            fn($k, $v) => $k . ' *' . $v . '*',
            array_keys($skills), $skills
        ), 2);
        foreach ($pares as $par) $txt .= implode('  ·  ', $par) . "\n";
    }

    return rtrim($txt) . $notaLiga;
}

/**
 * O quinteto titular, na ordem PG, SG, SF, PF, C.
 *
 * Não é "os 5 melhores": um time com três alas e nenhum pivô não joga com três
 * alas. Pra cada vaga eu procuro, nesta ordem — titular da posição, titular que
 * joga ali de segunda, qualquer um da posição, qualquer um que jogue ali de
 * segunda. Dentro de cada faixa vale o maior OVR, e ninguém ocupa duas vagas.
 *
 * Vaga sem candidato fica vazia em vez de ser preenchida com quem sobrou:
 * mostrar um armador de pivô esconderia justamente o buraco do elenco.
 *
 * Devolve [posição => jogador|null].
 */
function wcQuintetoTitular(array $elenco): array
{
    $vagas = ['PG' => null, 'SG' => null, 'SF' => null, 'PF' => null, 'C' => null];
    $usados = [];

    $pos = fn($p, $campo) => strtoupper(trim((string)($p[$campo] ?? '')));
    $ehTitular = fn($p) => ($p['role'] ?? '') === 'Titular';

    // Da preferência mais forte pra mais fraca. Só desce de faixa depois de
    // esgotar a anterior em TODAS as vagas seria pior: uma vaga sem titular
    // roubaria o titular de outra. Resolvo vaga a vaga, faixa a faixa.
    foreach (array_keys($vagas) as $vaga) {
        foreach ([
            fn($p) => $ehTitular($p) && $pos($p, 'position') === $vaga,
            fn($p) => $ehTitular($p) && $pos($p, 'secondary_position') === $vaga,
            fn($p) => $pos($p, 'position') === $vaga,
            fn($p) => $pos($p, 'secondary_position') === $vaga,
        ] as $criterio) {
            $candidatos = array_filter($elenco, fn($p) => !in_array($p['name'], $usados, true) && $criterio($p));
            if (!$candidatos) continue;
            // O elenco já vem por OVR desc, então o primeiro é o melhor.
            $escolhido = reset($candidatos);
            $vagas[$vaga] = $escolhido;
            $usados[] = $escolhido['name'];
            break;
        }
    }

    // Passe de ajuste: a busca acima é gulosa, então pode deixar uma vaga vazia
    // com jogador sobrando no elenco. É o caso do time com dois SF, um deles
    // capaz de jogar PF: o melhor pega SF, o PF fica vazio e o outro SF não
    // entra. Aqui eu tento a troca — quem está numa vaga que ele cobre de
    // segunda desce pra vaga vazia, e o que sobrou assume a dele.
    foreach ($vagas as $vaga => $ocupante) {
        if ($ocupante !== null) continue;

        foreach ($vagas as $outraVaga => $outro) {
            if ($outro === null || $pos($outro, 'secondary_position') !== $vaga) continue;

            $substituto = null;
            foreach ($elenco as $p) {
                if (in_array($p['name'], $usados, true)) continue;
                if ($pos($p, 'position') === $outraVaga || $pos($p, 'secondary_position') === $outraVaga) {
                    $substituto = $p;
                    break;   // elenco já vem por OVR desc
                }
            }
            if (!$substituto) continue;

            $vagas[$vaga] = $outro;
            $vagas[$outraVaga] = $substituto;
            $usados[] = $substituto['name'];
            break;
        }
    }

    return $vagas;
}

function wcTime(PDO $pdo, string $termo, ?array $jaResolvido = null, ?string $ligaDoGrupo = null): string
{
    if ($jaResolvido) {
        $t = $jaResolvido;
    } else {
        if ($termo === '') return "Use assim: /time lakers";
        [$t, $erro] = wcResolverTime($pdo, $termo, $ligaDoGrupo);
        if ($erro) return $erro;
    }

    $ovr = wcColunaOvr($pdo);
    $st = $pdo->prepare("SELECT id, name, position, secondary_position, role, age, {$ovr} AS ovr
                         FROM players WHERE team_id = ? ORDER BY {$ovr} DESC");
    $st->execute([(int)$t['id']]);
    $elenco = $st->fetchAll(PDO::FETCH_ASSOC);

    // Salário por jogador (só ELITE). Fora dela o mapa vem vazio e as linhas
    // saem como sempre saíram, sem 0M pendurado.
    $salarios = capSalariosDoTime($pdo, (int)$t['id'], (string)$t['league']);
    $sal = fn($p) => isset($salarios[(int)($p['id'] ?? 0)]) ? ' | ' . $salarios[(int)$p['id']] . 'M' : '';

    $txt = '*' . wcNomeDoTime($t) . "*\n"
        . $t['league'] . ($t['conference'] ? ' — ' . $t['conference'] : '') . "\n"
        . 'GM: ' . ($t['gm'] ?: 'sem dono') . "\n\n"
        . 'Elenco: ' . count($elenco) . " jogadores\n";

    if (wcLigaEmSalario($pdo, (string)$t['league'])) {
        $cap = getTeamCapSummary($pdo, (int)$t['id']);
        $espaco = (int)$cap['space'];
        $txt .= "Folha: *{$cap['payroll']}M* de {$cap['cap_max']}M"
             . ' (' . ($espaco >= 0 ? "sobra {$espaco}M" : 'estourou ' . abs($espaco) . 'M') . ")\n";
    } else {
        // RISE, NEXT e ROOKIE não têm folha em dinheiro — o CAP delas é a soma
        // de OVR, e era a única linha do /time que só a ELITE via.
        $txt .= wcLinhaCapOvr(wcCapPorOvr($pdo, $t));
    }

    // A posição na temporada corrente. Não sai campanha de vitórias e derrotas
    // porque ela não é cadastrada em lugar nenhum: o que o admin lança é a
    // ORDEM final, e "0-0" ao lado de todo time só dava a impressão errada de
    // que a temporada não tinha começado.
    $temp = wcTemporadaAtiva($pdo, (string)$t['league']);
    if ($temp) {
        $st = $pdo->prepare("SELECT position FROM season_standings
                             WHERE season_id = ? AND team_id = ?");
        $st->execute([(int)$temp['id'], (int)$t['id']]);
        $pos = $st->fetchColumn();
        if ($pos) $txt .= "Classificação: {$pos}º\n";
    }

    if ($elenco) {
        $quinteto = wcQuintetoTitular($elenco);

        $txt .= "\n*Quinteto titular:*\n";
        foreach ($quinteto as $vaga => $p) {
            $txt .= $p
                ? "{$vaga}: {$p['name']} {$p['ovr']} | {$p['age']}y" . $sal($p) . "\n"
                : "{$vaga}: _sem jogador na posição_\n";
        }

        // O banco é quem o GM marcou como banco, e só. Era "todo mundo que
        // não coube no quinteto", o que misturava reserva de verdade com
        // titular que perdeu a vaga pra um companheiro de mesma posição —
        // duas coisas diferentes na mesma lista.
        //
        // Sem ordem de posição aqui: vale o OVR, na ordem em que o elenco
        // já vem do SELECT.
        //
        // Quem está marcado como Banco mas subiu ao quinteto (acontece quando
        // a posição não tem titular) sai daqui: o quinteto já mostrou ele, e
        // repetir o mesmo nome duas vezes na mensagem parece defeito.
        $noQuinteto = [];
        foreach ($quinteto as $p) {
            if ($p) $noQuinteto[] = $p['name'];
        }
        $banco = array_values(array_filter($elenco,
            fn($p) => strcasecmp(trim((string)($p['role'] ?? '')), 'Banco') === 0
                   && !in_array($p['name'], $noQuinteto, true)));

        if ($banco) {
            $txt .= "\n*Banco:* (" . count($banco) . ")\n";
            foreach ($banco as $p) {
                $pos = strtoupper(trim((string)($p['position'] ?? ''))) ?: '--';
                $txt .= "{$pos}: {$p['name']} {$p['ovr']} | {$p['age']}y" . $sal($p) . "\n";
            }
        }
    }
    return rtrim($txt);
}

function wcCap(PDO $pdo, string $termo, ?array $jaResolvido = null, ?string $ligaDoGrupo = null): string
{
    if ($jaResolvido) {
        $t = $jaResolvido;
    } else {
        if ($termo === '') return "Use assim: /cap lakers";
        [$t, $erro] = wcResolverTime($pdo, $termo, $ligaDoGrupo);
        if ($erro) return $erro;
    }

    // NEXT/RISE/ROOKIE não têm folha em dinheiro — o CAP delas é a soma dos
    // CAP_TOP_N maiores OVR do elenco contra a faixa min/max da liga (mesma
    // conta de wcCapPorOvr/topOvrCap, a que o dashboard e o /time usam).
    if (!wcLigaEmSalario($pdo, (string)$t['league'])) {
        $c = wcCapPorOvr($pdo, $t);

        $stJog = $pdo->prepare('SELECT name, position, ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT ' . CAP_TOP_N);
        $stJog->execute([(int)$t['id']]);
        $jogadores = $stJog->fetchAll(PDO::FETCH_ASSOC);

        $txt = '*Cap — ' . wcNomeDoTime($t) . "*\n" . wcLinhaCapOvr($c);
        if ($jogadores) {
            $txt .= "\n*Quem conta (top " . CAP_TOP_N . " OVR):*\n";
            foreach ($jogadores as $j) {
                $txt .= "• {$j['name']} ({$j['position']}) — {$j['ovr']}\n";
            }
        }
        return rtrim($txt);
    }

    $cap = getTeamCapSummary($pdo, (int)$t['id']);
    $espaco = (int)$cap['space'];
    $rotulo = [
        'over_the_cap'  => '🔴 acima do teto',
        'abaixo_do_piso' => '🟡 abaixo do piso',
        'dentro_do_cap' => '🟢 dentro do cap',
    ][$cap['status']] ?? $cap['status'];

    $txt = '*Cap — ' . wcNomeDoTime($t) . "*\n{$rotulo}\n\n"
        . "Folha: *{$cap['payroll']}M*\n"
        . "Teto: {$cap['cap_max']}M (base {$cap['cap_base']}M)\n"
        . "Piso: {$cap['cap_floor']}M\n"
        . ($espaco >= 0 ? "Espaço: *{$espaco}M*\n" : "Estouro: *" . abs($espaco) . "M*\n");

    if ((int)$cap['cap_flex_total'] > 0) {
        $txt .= "Cap Flex: +{$cap['cap_flex_total']}M ({$cap['cap_flex_used_slots']} de {$cap['cap_flex_max_players']})\n";
    }
    if ((int)$cap['cap_loyalty_total'] > 0) {
        $txt .= "Lealdade: +{$cap['cap_loyalty_total']}M ({$cap['cap_loyalty_used_slots']} de {$cap['cap_loyalty_max_players']})\n";
    }

    $roster = $cap['roster'] ?? [];
    usort($roster, fn($a, $b) => (int)$b['total_salary'] <=> (int)$a['total_salary']);
    if ($roster) {
        $txt .= "\n*Maiores salários:*\n";
        foreach (array_slice($roster, 0, 6) as $r) {
            $txt .= "• {$r['name']} — {$r['total_salary']}M\n";
        }
    }
    return rtrim($txt);
}

function wcPicks(PDO $pdo, string $termo, ?array $jaResolvido = null, ?string $ligaDoGrupo = null): string
{
    if ($jaResolvido) {
        $t = $jaResolvido;
    } else {
        if ($termo === '') return "Use assim: /picks lakers";
        [$t, $erro] = wcResolverTime($pdo, $termo, $ligaDoGrupo);
        if ($erro) return $erro;
    }

    // swap_type e protection entram porque uma pick com condição não vale o
    // mesmo que uma limpa — e é justamente numa troca que /picks é usado.
    ensurePickProtectionSchema($pdo);
    $st = $pdo->prepare("
        SELECT p.season_year, p.round, p.original_team_id, p.swap_type,
               p.protection, p.protection_resultado,
               o.city AS o_city, o.name AS o_name
        FROM picks p
        LEFT JOIN teams o ON o.id = p.original_team_id
        WHERE p.team_id = ?
        ORDER BY p.season_year ASC, p.round ASC
    ");
    $st->execute([(int)$t['id']]);
    $picks = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$picks) return wcNomeDoTime($t) . ' não tem nenhuma pick.';

    // Peso da pick no casamento salarial (só ELITE): quem lê /picks está quase
    // sempre montando troca, e sem o valor a pick não dá pra somar com nada.
    $comSalario = strtoupper(trim((string)$t['league'])) === 'ELITE'
               && wcLigaEmSalario($pdo, (string)$t['league']);

    $porAno = [];
    $pesoTotal = 0;
    foreach ($picks as $p) {
        $rot = $p['round'] . 'ª';
        if ($comSalario) {
            $peso = capValorDaPickNaTroca((int)$p['round']);
            $pesoTotal += $peso;
            $rot .= " ({$peso}M)";
        }
        // Pick que veio de outro time: dizer de quem é o que importa numa troca.
        if ((int)$p['original_team_id'] !== (int)$t['id']) {
            $rot .= ' (do ' . wcNomeDoTime(['city' => $p['o_city'], 'name' => $p['o_name']]) . ')';
        }
        // Condição da pick: protegida ou em swap.
        $swap = strtoupper(trim((string)($p['swap_type'] ?? '')));
        if ($swap === 'SB' || $swap === 'SW') $rot .= ' [swap ' . $swap . ']';
        if (protecaoValida($p['protection'] ?? null)) {
            $res = $p['protection_resultado'] ?? null;
            $rot .= $res === 'passou' ? ' [passou, era ' . protecaoRotulo($p['protection']) . ']'
                  : ($res === 'rolou' ? ' [não passou, ' . protecaoRotulo($p['protection']) . ']'
                  : ' [protegida ' . protecaoRotulo($p['protection']) . ']');
        }
        $porAno[(int)$p['season_year']][] = $rot;
    }

    $txt = '*Picks — ' . wcNomeDoTime($t) . "*\n" . count($picks) . " no total\n\n";
    foreach ($porAno as $ano => $lista) {
        $txt .= "*{$ano}:* " . implode(', ', $lista) . "\n";
    }
    if ($comSalario) {
        $txt .= "\n_Peso na troca: *{$pesoTotal}M* no total (1ª = "
             . capValorDaPickNaTroca(1) . 'M, 2ª = ' . capValorDaPickNaTroca(2) . "M). Pick não entra na folha._\n";
    }
    return rtrim($txt);
}

function wcClassificacao(PDO $pdo, string $termo, ?string $ligaDoGrupo = null): string
{
    // Sem argumento, vale a liga do grupo: quem digita /classificacao no Chat
    // Off da RISE quer a RISE, não a ELITE. Só cai no padrão quando o grupo não
    // é de liga nenhuma (o principal, o Geral).
    $liga = wcNormalizarLiga($termo !== '' ? $termo : ($ligaDoGrupo ?: 'ELITE'));
    if (!$liga) return "Liga não reconhecida. Use ELITE, NEXT, RISE ou ROOKIE.";

    $temp = wcTemporadaAtiva($pdo, $liga);
    if (!$temp) return "A {$liga} ainda não tem temporada cadastrada.";

    // A classificação da FBA é uma ORDEM, não uma campanha: o admin lança os
    // times na posição final e é só isso que fica gravado. Vitórias e derrotas
    // não são cadastradas em lugar nenhum — por isso nem são lidas aqui, e a
    // ordenação é pela posição. (Com ORDER BY wins todo mundo empatava em 0 e
    // a tabela saía embaralhada, com "0-0" em cada linha.)
    //
    // A posição é DENTRO DA CONFERÊNCIA — existe um 1º no Leste e um 1º no
    // Oeste —, então a lista sai separada. Sem conferência gravada (liga que
    // não usa, ou lançamento antigo) cai numa lista só.
    $st = $pdo->prepare("
        SELECT s.position, COALESCE(s.conference, t.conference) AS conf,
               t.city, t.mascot, t.name
        FROM season_standings s JOIN teams t ON t.id = s.team_id
        WHERE s.season_id = ?
        ORDER BY s.position ASC, t.city ASC
    ");
    $st->execute([(int)$temp['id']]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$linhas) {
        return "A {$liga} ainda não tem classificação lançada na temporada {$temp['season_number']}.";
    }

    $porConf = [];
    foreach ($linhas as $l) {
        $c = strtoupper(trim((string)($l['conf'] ?? '')));
        $porConf[$c !== '' ? $c : 'UNICA'][] = $l;
    }

    $bloco = function (array $lista) {
        $t = '';
        foreach ($lista as $n => $l) {
            $pos = (int)($l['position'] ?? 0) ?: ($n + 1);
            $t .= str_pad((string)$pos, 2, ' ', STR_PAD_LEFT) . '. ' . wcNomeDoTime($l) . "\n";
        }
        return $t;
    };

    $txt = "*Classificação {$liga}* — temporada {$temp['season_number']}\n";
    if (count($porConf) === 1 && isset($porConf['UNICA'])) {
        $txt .= "\n" . $bloco($porConf['UNICA']);
    } else {
        foreach (['LESTE' => '🔵 Leste', 'OESTE' => '🔴 Oeste'] as $chave => $rotulo) {
            if (empty($porConf[$chave])) continue;
            $txt .= "\n*{$rotulo}*\n" . $bloco($porConf[$chave]);
        }
        // Conferência com nome fora do par Leste/Oeste não pode sumir da lista.
        foreach ($porConf as $chave => $lista) {
            if (in_array($chave, ['LESTE', 'OESTE'], true)) continue;
            $rotulo = $chave === 'UNICA' ? 'Sem conferência' : ucfirst(strtolower($chave));
            $txt .= "\n*{$rotulo}*\n" . $bloco($lista);
        }
    }
    return rtrim($txt);
}

/**
 * A 1ª rodada montada a partir da CLASSIFICAÇÃO — a fonte que nunca falta.
 *
 * Os confrontos de abertura não dependem de ninguém preencher nada: são as
 * seeds cruzadas, 1x8, 4x5, 2x7 e 3x6 em cada conferência. Assim que o admin
 * salva a classificação, eles existem.
 *
 * Isto é a rede de segurança do /playoffs. As outras duas fontes — o rascunho
 * e as séries registradas — dependem de uma gravação ter dado certo em outro
 * lugar; esta depende só da classificação, que é o próprio pré-requisito dos
 * playoffs. Sem ela o comando dizia "não tem playoffs ativo" com o
 * chaveamento montado na tela do admin, porque o rascunho tinha subido sem
 * ele. Aqui não há o que sincronizar.
 *
 * Vem sem vencedor e sem placar, que é a verdade: as séries ainda não foram
 * jogadas. Devolve null quando a temporada não tem classificação lançada.
 */
function wcChaveDaClassificacao(PDO $pdo, int $seasonId): ?array
{
    try {
        $st = $pdo->prepare("SELECT ss.team_id, ss.position,
                                    COALESCE(ss.conference, t.conference) AS conf
                               FROM season_standings ss
                               JOIN teams t ON t.id = ss.team_id
                              WHERE ss.season_id = ?
                           ORDER BY ss.position ASC");
        $st->execute([$seasonId]);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (!$linhas) return null;

    $porConf = ['LESTE' => [], 'OESTE' => []];
    foreach ($linhas as $r) {
        $c = strtoupper((string)($r['conf'] ?? ''));
        if (!isset($porConf[$c])) continue;      // conferência fora do par: sem chave
        $porConf[$c][(int)$r['position']] = (int)$r['team_id'];
    }

    $vazia = fn() => ['r1' => [], 'r2' => [], 'cf' => null];
    $chave = ['leste' => $vazia(), 'oeste' => $vazia(), 'final' => null];
    // A ordem dos pares é a do chaveamento, não a das seeds: é ela que faz os
    // vencedores se encontrarem certo na rodada seguinte.
    $pares = [[1, 8], [4, 5], [2, 7], [3, 6]];
    $achou = false;

    foreach (['LESTE' => 'leste', 'OESTE' => 'oeste'] as $conf => $lado) {
        foreach ($pares as [$a, $b]) {
            if (empty($porConf[$conf][$a]) || empty($porConf[$conf][$b])) continue;
            $chave[$lado]['r1'][] = [
                't1' => ['id' => $porConf[$conf][$a]], 's1' => $a,
                't2' => ['id' => $porConf[$conf][$b]], 's2' => $b,
            ];
            $achou = true;
        }
    }
    return $achou ? $chave : null;
}

/**
 * O chaveamento remontado a partir das séries JÁ REGISTRADAS.
 *
 * Depois do registro final o rascunho some, mas playoff_series guarda cada
 * série com os dois times, quem passou e em quantos jogos — dá pra reconstruir
 * a chave inteira de lá. Sem isto o /playoffs dizia "não tem playoffs ativo"
 * exatamente depois de a liga registrar os playoffs.
 *
 * As seeds saem de season_standings, que é a mesma fonte que decidiu os
 * confrontos. Devolve null quando não há série nenhuma gravada.
 */
function wcChaveDasSeries(PDO $pdo, int $seasonId): ?array
{
    try {
        $st = $pdo->prepare("SELECT fase, conferencia, team_a_id, team_b_id, winner_team_id, jogos
                               FROM playoff_series WHERE season_id = ? ORDER BY id");
        $st->execute([$seasonId]);
        $series = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (!$series) return null;

    $seed = [];
    try {
        $stS = $pdo->prepare("SELECT team_id, position FROM season_standings WHERE season_id = ?");
        $stS->execute([$seasonId]);
        foreach ($stS->fetchAll(PDO::FETCH_ASSOC) as $r) $seed[(int)$r['team_id']] = (int)$r['position'];
    } catch (Throwable $e) { /* sem seed a linha sai sem o número, e tudo bem */ }

    $vazia = fn() => ['r1' => [], 'r2' => [], 'cf' => null];
    $chave = ['leste' => $vazia(), 'oeste' => $vazia(), 'final' => null];

    foreach ($series as $s) {
        $a = (int)$s['team_a_id'];
        $b = (int)$s['team_b_id'];
        $m = [
            't1' => ['id' => $a],
            't2' => ['id' => $b],
            'w'  => (string)(int)$s['winner_team_id'],
            'g'  => (int)$s['jogos'],
        ];
        if (isset($seed[$a])) $m['s1'] = $seed[$a];
        if (isset($seed[$b])) $m['s2'] = $seed[$b];

        if ($s['fase'] === 'final') { $chave['final'] = $m; continue; }
        $conf = strtoupper((string)$s['conferencia']) === 'OESTE' ? 'oeste' : 'leste';
        if ($s['fase'] === 'cf')      $chave[$conf]['cf'] = $m;
        elseif ($s['fase'] === 'r2')  $chave[$conf]['r2'][] = $m;
        else                          $chave[$conf]['r1'][] = $m;
    }

    // A 1ª rodada sai na ORDEM DO CHAVEAMENTO — 1x8, 4x5, 2x7, 3x6 —, que é a
    // ordem em que os vencedores se encontram na rodada seguinte. Não é a
    // ordem de gravação nem a das seeds: é a mesma que o rascunho preserva,
    // e as duas fontes precisam ler igual pra ninguém achar que mudou.
    $ordemChave = [1 => 0, 4 => 1, 2 => 2, 3 => 3];
    foreach (['leste', 'oeste'] as $c) {
        usort($chave[$c]['r1'], function ($x, $y) use ($ordemChave) {
            // Seed fora do padrão cai depois, ordenada por ela mesma.
            $px = $ordemChave[$x['s1'] ?? 0] ?? (90 + ($x['s1'] ?? 9));
            $py = $ordemChave[$y['s1'] ?? 0] ?? (90 + ($y['s1'] ?? 9));
            return $px <=> $py;
        });
    }
    return $chave;
}

/**
 * O chaveamento dos playoffs, como está agora.
 *
 * A fonte é o RASCUNHO do registro de pontuação (season_registro_rascunho):
 * é lá que o admin monta o chaveamento, jogo a jogo, enquanto as séries são
 * decididas. Ele nasce quando a classificação é salva e some quando a
 * pontuação é registrada — o que dá, de graça, a resposta certa nas duas
 * pontas: antes do fim da temporada regular não há playoffs, e depois do
 * registro eles acabaram.
 *
 * Não lê playoff_results nem playoff_series de propósito: essas tabelas só
 * são preenchidas no registro final, ou seja, quando já não há nada "atual"
 * pra mostrar.
 */
function wcPlayoffs(PDO $pdo, string $termo, ?string $ligaDoGrupo = null): string
{
    $liga = wcNormalizarLiga($termo !== '' ? $termo : ($ligaDoGrupo ?: 'ELITE'));
    if (!$liga) return "Liga não reconhecida. Use ELITE, NEXT, RISE ou ROOKIE.";

    $temp = wcTemporadaAtiva($pdo, $liga);
    if (!$temp) return "A {$liga} ainda não tem temporada cadastrada.";
    $seasonId   = (int)$temp['id'];
    $numeroTemp = $temp['season_number'] ?? null;

    // O chaveamento vem de TRÊS lugares, nesta ordem — da informação mais
    // completa pra mais garantida:
    //
    //   1. O RASCUNHO do registro de pontuação: é onde ele mora enquanto o
    //      admin preenche as séries, com vencedores e placares. Vale mesmo
    //      antes de qualquer salvamento — é o que está na tela dele.
    //   2. playoff_series, onde ele fica DEPOIS do registro final — o rascunho
    //      é apagado nessa hora.
    //   3. A CLASSIFICAÇÃO, que dá os confrontos de abertura pelas seeds.
    //
    // A terceira existe porque as duas primeiras dependem de uma gravação ter
    // dado certo em outro lugar, e uma delas falhou calada: o rascunho subia
    // sem o chaveamento e o comando respondia "não tem playoffs ativo" com a
    // chave montada na tela do admin. A classificação é o próprio
    // pré-requisito dos playoffs — se ela existe, os confrontos existem.
    $chave = null;
    $encerrado = false;
    try {
        $st = $pdo->prepare("SELECT dados FROM season_registro_rascunho WHERE season_id = ?");
        $st->execute([$seasonId]);
        $bruto = $st->fetchColumn();
        // Basta o chaveamento estar lá. A `etapa` não é consultada de
        // propósito: ela é controle interno da tela do admin, e exigir que
        // estivesse em 'playoffs' só criava mais um jeito de o comando
        // recusar um chaveamento que existe e está certo.
        if ($bruto) {
            $d = json_decode((string)$bruto, true);
            if (is_array($d) && !empty($d['bracket'])) $chave = $d['bracket'];
        }
    } catch (Throwable $e) {
        // Tabela ainda não existe nesta instalação: só não há rascunho.
        $chave = null;
    }

    if (!$chave) {
        $chave = wcChaveDasSeries($pdo, $seasonId);
        $encerrado = (bool)$chave;
    }

    if (!$chave) {
        $chave = wcChaveDaClassificacao($pdo, $seasonId);
    }

    if (!$chave) {
        return "*Playoffs {$liga}*\n\nNão tem playoffs ativo agora."
             . "\nO chaveamento aparece aqui quando o admin salva a classificação da temporada regular.";
    }

    // SÓ A ALCUNHA, sem a cidade: "Alley Dogs" e não "Bed-Stuy Alley Dogs".
    // Numa chave são dezesseis nomes em confrontos de dois, e com a cidade
    // cada linha quebrava em duas na tela do celular — a lista dobrava de
    // altura pra dizer a mesma coisa. Dentro de uma liga a alcunha já
    // identifica o time sozinha.
    //
    // Os nomes vêm do banco, não do JSON: o rascunho guarda o nome como
    // estava quando o chaveamento foi montado, e time que mudou de nome no
    // meio dos playoffs apareceria com o antigo.
    $nomes = [];
    $stT = $pdo->prepare("SELECT id, city, name, mascot FROM teams WHERE league = ?");
    $stT->execute([$liga]);
    foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $curto = trim((string)($t['name'] ?? ''));
        if ($curto === '') $curto = trim((string)($t['mascot'] ?? ''));
        if ($curto === '') $curto = wcNomeDoTime($t);   // último recurso
        $nomes[(int)$t['id']] = $curto;
    }
    $nomeDe = function ($time) use ($nomes) {
        $id = (int)($time['id'] ?? 0);
        if (isset($nomes[$id])) return $nomes[$id];
        // Time que saiu da liga depois do chaveamento montado: o nome do
        // rascunho ainda serve, e é melhor que um "?" no meio da chave.
        $doRascunho = trim((string)($time['name'] ?? ''));
        return $doRascunho !== '' ? $doRascunho : '?';
    };

    // Uma série numa linha. O placar não é digitado pelo admin: numa melhor
    // de 7 o vencedor sempre faz 4, então o número de jogos já diz 4-2.
    $linha = function ($m) use ($nomeDe) {
        if (!$m || empty($m['t1']) || empty($m['t2'])) return "   _a definir_\n";
        $n1 = $nomeDe($m['t1']);
        $n2 = $nomeDe($m['t2']);
        $s1 = isset($m['s1']) ? '(' . $m['s1'] . ') ' : '';
        $s2 = isset($m['s2']) ? '(' . $m['s2'] . ') ' : '';
        $w  = isset($m['w']) ? (string)$m['w'] : '';
        if ($w === '') return "   {$s1}{$n1}  x  {$s2}{$n2}\n";

        $venceuT1 = $w === (string)($m['t1']['id'] ?? '');
        $jogos = isset($m['g']) ? (int)$m['g'] : 0;

        // Sem o número de jogos marcado não há placar pra mostrar, e manter a
        // ordem t1 x t2 faria "Boston venceu *Miami*" quando quem passou foi
        // o Miami. Aí o vencedor vem primeiro, e a frase não tem como enganar.
        if ($jogos < 4 || $jogos > 7) {
            $venc  = $venceuT1 ? "{$s1}{$n1}" : "{$s2}{$n2}";
            $perd  = $venceuT1 ? "{$s2}{$n2}" : "{$s1}{$n1}";
            return "   ✅ *{$venc}* passou por {$perd}\n";
        }

        // Com o placar, a ordem do chaveamento é mantida e o 4 fica do lado
        // de quem venceu. Numa melhor de 7 o vencedor sempre faz 4, e o
        // número de jogos dá o resto — 6 jogos é 4-2.
        $a = $venceuT1 ? "*{$n1}*" : $n1;
        $b = $venceuT1 ? $n2 : "*{$n2}*";
        $meio = $venceuT1 ? ' 4-' . ($jogos - 4) . ' ' : ' ' . ($jogos - 4) . '-4 ';
        return "   ✅ {$s1}{$a}{$meio}{$s2}{$b}\n";
    };

    // Rodada sem confronto nenhum ainda mostra "a definir": um título sozinho,
    // sem nada embaixo, parece erro de renderização e não fase por vir.
    $rodada = function ($rotulo, $jogos) use ($linha) {
        $t = "_{$rotulo}_\n";
        $jogos = is_array($jogos) ? $jogos : [];
        if (!$jogos) return $t . $linha(null);
        foreach ($jogos as $m) $t .= $linha($m);
        return $t;
    };

    $conf = function ($c, $rotulo) use ($rodada) {
        return "\n*{$rotulo}*\n"
             . $rodada('1ª rodada', $c['r1'] ?? [])
             . $rodada('Semifinal', $c['r2'] ?? [])
             . $rodada('Final de conferência', [$c['cf'] ?? null]);
    };

    $txt  = "🏆 *Playoffs {$liga}*" . ($numeroTemp ? " — temporada {$numeroTemp}" : '') . "\n";
    $txt .= $conf($chave['leste'] ?? [], '🔵 Leste');
    $txt .= $conf($chave['oeste'] ?? [], '🔴 Oeste');
    $txt .= "\n*🏆 Grande final*\n" . $linha($chave['final'] ?? null);

    $final = $chave['final'] ?? null;
    if ($final && !empty($final['w'])) {
        $campeao = ((string)$final['w'] === (string)($final['t1']['id'] ?? ''))
            ? $nomeDe($final['t1']) : $nomeDe($final['t2']);
        $txt .= "\n🏅 *Campeão: {$campeao}*";
    } elseif ($encerrado) {
        // Veio das séries registradas mas sem final: o registro está lá, só
        // não fechou a Grande Final. Dizer "em andamento" seria mentira.
        $txt .= "\n_Playoffs registrados._";
    } else {
        $txt .= "\n_Playoffs em andamento._";
    }
    return $txt;
}

/**
 * As fichas de força de uma liga, prontas pra ordenar.
 *
 * Serve aos dois power rankings — o da liga inteira e o por conferência — pra
 * que os dois deem a MESMA resposta sobre o mesmo time. A força é a do
 * /confronto (wcForcaDoTime), pelo mesmo motivo.
 *
 * Devolve null quando a liga não existe ou não tem quinteto montado; a
 * mensagem de erro fica com quem chamou, que sabe o nome do próprio comando.
 */
function wcFichasDeForca(PDO $pdo, string $liga): ?array
{
    $st = $pdo->prepare("SELECT id, city, name, mascot, conference FROM teams WHERE league = ?");
    $st->execute([$liga]);
    $times = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$times) return null;

    // Um SELECT pro elenco da liga inteira. Um por time seriam 32 idas ao
    // banco só pra montar uma mensagem.
    $ovrCol = wcColunaOvr($pdo);
    $st = $pdo->prepare("SELECT id, team_id, name, position, secondary_position, role, age, {$ovrCol} AS ovr
                         FROM players WHERE team_id IN (
                             SELECT id FROM teams WHERE league = ?
                         ) ORDER BY {$ovrCol} DESC");
    $st->execute([$liga]);
    $porTime = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $porTime[(int)$p['team_id']][] = $p;

    // O posto na tabela, quando a temporada já existe. Vitórias e derrotas
    // saíram: elas não são cadastradas, então vinham 0-0 pra liga inteira.
    $posto = [];
    if ($temp = wcTemporadaAtiva($pdo, $liga)) {
        $st = $pdo->prepare("SELECT team_id, position FROM season_standings WHERE season_id = ?");
        $st->execute([(int)$temp['id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            if ($l['position'] !== null) $posto[(int)$l['team_id']] = (int)$l['position'];
        }
    }

    $fichas = [];
    foreach ($times as $t) {
        $forca = wcForcaDoTime($porTime[(int)$t['id']] ?? []);
        if ($forca <= 0) continue;                       // sem quinteto montado
        $fichas[] = [
            'nome'       => wcNomeDoTime($t),
            'forca'      => $forca,
            'posto'      => $posto[(int)$t['id']] ?? null,
            'conferencia'=> $t['conference'] ?: null,
        ];
    }
    if (!$fichas) return null;

    usort($fichas, fn($a, $b) => $b['forca'] <=> $a['forca']);
    return $fichas;
}

/** A linha de um time no power ranking: medalha, nome e posto na tabela. */
function wcLinhaDePower(array $f, int $posicao): string
{
    $medalha = [1 => '🥇', 2 => '🥈', 3 => '🥉'][$posicao] ?? ($posicao . '.');
    // O posto da tabela ao lado da força é o ponto do comando: dá pra ver de
    // um relance quem está rendendo acima e quem está devendo.
    $cauda = $f['posto'] ? " ({$f['posto']}º na tabela)" : '';
    return "{$medalha} *{$f['nome']}*{$cauda}\n";
}

/**
 * /powerranking — os mais fortes da liga do grupo.
 *
 * A força é a mesma do /confronto (wcForcaDoTime), pra o bot não dar duas
 * opiniões diferentes sobre o mesmo time em dois comandos.
 */
function wcPowerRanking(PDO $pdo, string $termo, ?string $ligaDoGrupo = null): string
{
    $liga = wcNormalizarLiga($termo !== '' ? $termo : ($ligaDoGrupo ?: 'ELITE'));
    if (!$liga) return "Liga não reconhecida. Use ELITE, NEXT, RISE ou ROOKIE.";

    $fichas = wcFichasDeForca($pdo, $liga);
    if (!$fichas) return "Os times da {$liga} ainda não têm quinteto montado.";

    // A liga inteira, não um top 10: quem está em 24º também quer se achar
    // na lista, e é justamente quem mais procura.
    $txt = "*Power Ranking {$liga}*\n_o que a régua diz, não o que o grupo acha_\n\n";
    foreach ($fichas as $i => $f) $txt .= wcLinhaDePower($f, $i + 1);
    return rtrim($txt);
}

/**
 * /powerc — o mesmo power ranking, separado por conferência.
 *
 * Existe porque a disputa que importa é dentro da conferência: numa liga de
 * 32 times, o quinto mais forte da liga pode ser o segundo do Leste, e é esse
 * número que decide o chaveamento. O posto na tabela vem ao lado justamente
 * pra mostrar a diferença entre o que o elenco vale e onde ele chegou.
 *
 * Time sem conferência definida cai num bloco à parte em vez de sumir — é o
 * caso da ROOKIE, que ainda está se organizando.
 */
/**
 * /aceitar CODIGO e /recusar CODIGO — a decisão do dono, pelo WhatsApp.
 *
 * O código vem na própria mensagem da proposta. Sem ele o comando seria
 * ambíguo: o GM pode ter dois leilões abertos, ou responder depois que a
 * próxima proposta já chegou — e "aceitar" sem dizer o quê acertaria a
 * errada exatamente quando mais importa.
 *
 * Aceitar aqui é a MESMA escolha provisória do app: nada muda de time
 * agora, e uma proposta melhor ainda pode tomar o lugar.
 */
function wcDecidirPropostaDeLeilao(PDO $pdo, string $cmd, string $arg, string $deQuem): string
{
    require_once __DIR__ . '/../backend/leilao_bot.php';
    leilaoBotGarantirTabela($pdo);

    $codigo = mb_strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $arg)));
    if ($codigo === '') {
        return "Falta o código. Ele vem na mensagem da proposta — tipo */aceitar B47*.";
    }

    $digitos = preg_replace('/\\D+/', '', explode('@', $deQuem)[0] ?? '');
    if (strlen($digitos) < 8 || str_contains($deQuem, '@lid')) {
        return "Não consigo confirmar seu número por aqui, então não posso decidir por você. Responda no privado do bot, ou decida pelo site.";
    }

    $st = $pdo->prepare("SELECT f.id, f.proposta_id, f.leilao_id, f.dono_user_id, f.status,
                                lp.status AS status_proposta, u.phone
                         FROM leilao_bot_fila f
                         JOIN leilao_propostas lp ON lp.id = f.proposta_id
                         JOIN users u ON u.id = f.dono_user_id
                         WHERE f.codigo = ? AND f.status IN ('aguardando','na_vez')
                         ORDER BY f.id DESC LIMIT 1");
    $st->execute([$codigo]);
    $linha = $st->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        return "Não achei nenhuma proposta com o código *{$codigo}* esperando resposta. Ou já foi decidida, ou o leilão fechou.";
    }

    // O código é curto de propósito; a conferência do dono é que impede
    // alguém acertar um código no chute e decidir o leilão dos outros.
    $doDono = preg_replace('/\\D+/', '', (string)$linha['phone']);
    $bate = $doDono === $digitos
         || (strlen($doDono) >= 8 && substr($doDono, -8) === substr($digitos, -8));
    if (!$bate) {
        return "Essa proposta não é sua pra decidir.";
    }

    if (($linha['status_proposta'] ?? '') !== 'pendente') {
        leilaoBotDescartarProposta($pdo, (int)$linha['proposta_id']);
        return "Essa proposta já foi decidida (está como *{$linha['status_proposta']}*).";
    }

    // A decisão passa pelas mesmas funções do site — regra de negócio numa
    // porta só: quem decide pelo WhatsApp não escapa de nenhuma validação.
    // is_admin=true aqui porque o dono já foi conferido pelo telefone acima.
    require_once __DIR__ . '/../backend/leilao_decisao.php';
    $corpo = ['proposta_id' => (int)$linha['proposta_id']];
    $resposta = $cmd === 'aceitar'
        ? leilaoDecidirAceitar($pdo, $corpo, null, true)
        : leilaoDecidirRecusar($pdo, $corpo, null, true);

    if (empty($resposta['success'])) {
        return "Não deu pra " . ($cmd === 'aceitar' ? 'aceitar' : 'recusar') . ": "
             . ($resposta['error'] ?? 'erro inesperado') . ".";
    }

    leilaoBotDescartarProposta($pdo, (int)$linha['proposta_id']);

    $restam = (int)$pdo->query("SELECT COUNT(*) FROM leilao_bot_fila
                                WHERE dono_user_id = " . (int)$linha['dono_user_id'] . "
                                  AND status = 'aguardando'")->fetchColumn();
    $cauda = $restam > 0
        ? "\nMando a próxima em instantes — ainda tem {$restam}."
        : "\nEra a última da fila por enquanto.";

    return $cmd === 'aceitar'
        ? "✅ Escolhida. _Por enquanto_ é ela que leva o jogador — se vier proposta melhor até o fim do prazo, você troca aceitando a nova." . $cauda
        : "❌ Recusada." . $cauda;
}

function wcPowerRankingConferencia(PDO $pdo, string $termo, ?string $ligaDoGrupo = null): string
{
    $liga = wcNormalizarLiga($termo !== '' ? $termo : ($ligaDoGrupo ?: 'ELITE'));
    if (!$liga) return "Liga não reconhecida. Use ELITE, NEXT, RISE ou ROOKIE.";

    $fichas = wcFichasDeForca($pdo, $liga);
    if (!$fichas) return "Os times da {$liga} ainda não têm quinteto montado.";

    $porConf = [];
    foreach ($fichas as $f) $porConf[$f['conferencia'] ?? 'SEM'][] = $f;

    // Leste e Oeste na ordem de sempre; o resto (se houver) depois.
    $ordem = array_values(array_unique(array_merge(
        array_intersect(['LESTE', 'OESTE'], array_keys($porConf)),
        array_keys($porConf)
    )));

    $temTabela = false;
    foreach ($fichas as $f) if ($f['posto']) { $temTabela = true; break; }
    $sub = $temTabela ? 'a força do elenco, e onde ele está na tabela'
                     : 'a força do elenco — a temporada ainda não começou';
    $txt = "*Power Ranking {$liga} · por conferência*\n_{$sub}_\n";
    foreach ($ordem as $conf) {
        $lista = $porConf[$conf];
        $titulo = $conf === 'SEM' ? 'Sem conferência' : ucfirst(mb_strtolower($conf, 'UTF-8'));
        $txt .= "\n*{$titulo}*\n";
        foreach ($lista as $i => $f) $txt .= wcLinhaDePower($f, $i + 1);
    }
    return rtrim($txt);
}

/**
 * Manda o quiz do dia passar a sair NESTE grupo.
 *
 * Existe porque o caminho pela tela tem um passo a mais que ninguém adivinha:
 * o grupo precisa estar cadastrado pra aparecer no seletor, e o identificador
 * dele é um número de 18 dígitos que não aparece em lugar nenhum do WhatsApp.
 * Digitar /quizaqui dentro do grupo certo resolve os dois de uma vez — o
 * próprio comando carrega o identificador.
 *
 * NÃO responde no grupo. É configuração, não conversa: o grupo inteiro veria
 * um recado que interessa a uma pessoa só. A confirmação vai no privado de
 * quem digitou, que é quem precisa saber se pegou.
 *
 * Só admin. Sem isso, qualquer um puxaria o quiz pro grupo dele — e o silêncio
 * no grupo vale pra recusa também: quem não podia não descobre que existe.
 *
 * Devolve sempre string vazia, que é como o webhook entende "atendido, sem
 * resposta".
 */
function wcQuizAqui(PDO $pdo, string $deQuem, string $grupoJid): string
{
    $digitos = preg_replace('/\D+/', '', explode('@', $deQuem)[0] ?? '');
    $privado = strlen($digitos) >= 8 && !str_contains($deQuem, '@lid')
             ? $digitos . '@s.whatsapp.net' : null;

    /** Responde no PV, se der pra saber quem é. */
    $avisar = function (string $txt) use ($pdo, $privado) {
        if ($privado) whatsappEnfileirar($pdo, $privado, $txt, false, 'comando');
        return '';
    };

    if ($grupoJid === '' || !str_ends_with($grupoJid, '@g.us')) {
        return $avisar("O /quizaqui só funciona dentro do grupo que vai receber o quiz.");
    }
    if (!$privado) {
        // Sem telefone não dá nem pra confirmar quem é, nem pra avisar no PV.
        return '';
    }

    // Admin pelo cadastro, casando o telefone. Mesma tolerância dos comandos
    // "meus": número inteiro primeiro, depois os últimos 8 dígitos.
    $eh = false;
    try {
        $st = $pdo->query("SELECT phone, user_type FROM users WHERE phone IS NOT NULL AND phone <> ''");
        $fim = substr($digitos, -8);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $d = preg_replace('/\D+/', '', (string)$u['phone']);
            if (($d === $digitos || (strlen($d) >= 8 && substr($d, -8) === $fim))
                && ($u['user_type'] ?? '') === 'admin') { $eh = true; break; }
        }
    } catch (Throwable $e) { /* sem cadastro legível: cai no não-admin */ }

    if (!$eh) return '';   // não é admin: silêncio total, nem no PV

    require_once dirname(__DIR__) . '/backend/quiz.php';
    quizGarantirTabelas($pdo);

    // Cadastra o grupo (é o que faz o bot atender aqui) e aponta o quiz.
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_grupos_comando (
        jid VARCHAR(120) PRIMARY KEY,
        nome VARCHAR(120) NULL,
        liga ENUM('ELITE','NEXT','RISE','ROOKIE') NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare("INSERT INTO whatsapp_grupos_comando (jid, nome, ativo) VALUES (?,?,1)
                   ON DUPLICATE KEY UPDATE ativo = 1")
        ->execute([$grupoJid, 'Grupo do quiz']);
    $pdo->prepare("UPDATE whatsapp_config SET quiz_grupo = ? WHERE id = 1")->execute([$grupoJid]);

    // Relê antes de confirmar: dizer "pronto" sem ter gravado é o pior
    // desfecho possível justo no comando que existe pra consertar isso.
    if (quizGrupoDoQuiz($pdo) !== $grupoJid) {
        return $avisar("Não consegui gravar o grupo do quiz. Tente por Gestão › Quiz.");
    }
    // Vale JÁ pro que for mandado à mão, e amanhã pro automático — as duas
    // coisas passam por quizGrupoDoQuiz(). Dizer só "a partir de amanhã"
    // faria o admin achar que precisa esperar pra testar.
    return $avisar("✅ Fechado. O quiz passa a sair no grupo onde você digitou /quizaqui.\n\n"
                 . "Vale agora pro que você mandar pela tela, e às " . BOT_QUIZ_HORA
                 . " de amanhã pro automático.");
}

/**
 * Acha o time de quem mandou a mensagem, pelo número do WhatsApp.
 *
 * Voltou depois de os telefones serem padronizados: antes metade dos cadastros
 * estava sem o 55, e um comando que erra a pessoa é pior que comando nenhum.
 *
 * Compara o número inteiro primeiro. Só se não achar ninguém é que cai nos
 * últimos 8 dígitos — a rede de segurança pra cadastro antigo, sem o 9 do
 * celular ou sem o DDI. Nessa ordem, e não direto no sufixo, porque 8 dígitos
 * batem entre países diferentes: um +1 e um +55 podem terminar igual, e aí o
 * comando responderia com o time de outra pessoa.
 *
 * Se sobrar mais de um, não adivinha.
 * Retorna [time|null, mensagemDeErro|null].
 */
function wcTimeDeQuemPerguntou(PDO $pdo, string $deQuem, ?string $ligaDoGrupo): array
{
    // Vem como 5511999999999@s.whatsapp.net; interessa só o número.
    $digitos = preg_replace('/\D+/', '', explode('@', $deQuem)[0] ?? '');
    if (strlen($digitos) < 8) {
        return [null, 'Não consegui identificar seu número por aqui. Use o comando com o nome do time, tipo /cap lakers.'];
    }

    // LID é o identificador interno que o WhatsApp manda no lugar do telefone
    // em alguns grupos. Os dígitos existem, mas não são número de ninguém —
    // procurar no cadastro daria "não achei", e o GM iria conferir um telefone
    // que está certo. Melhor dizer que o problema não é dele.
    if (str_contains($deQuem, '@lid')) {
        return [null, "O WhatsApp não está me passando seu número neste grupo (manda um id interno no lugar), "
                    . "então não tenho como saber qual é o seu time. Use o comando com o nome, tipo /cap lakers."];
    }

    // A comparação é em PHP, não em SQL: REGEXP_REPLACE só existe do MySQL 8
    // pra cima e eu não controlo a versão da hospedagem. São poucas dezenas de
    // linhas (uma por GM com time), então filtrar aqui não custa nada.
    $todos = $pdo->query("
        SELECT t.id, t.name, t.city, t.mascot, t.league, t.conference,
               u.name AS gm, u.id AS user_id, u.phone
        FROM users u JOIN teams t ON t.user_id = u.id
        WHERE u.phone IS NOT NULL AND u.phone <> ''
        ORDER BY FIELD(t.league,'ELITE','NEXT','RISE','ROOKIE')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $so = fn($t) => preg_replace('/\D+/', '', (string)$t['phone']);
    $times = array_values(array_filter($todos, fn($t) => $so($t) === $digitos));
    if (!$times) {
        $fim = substr($digitos, -8);
        $times = array_values(array_filter($todos, function ($t) use ($so, $fim) {
            $d = $so($t);
            return strlen($d) >= 8 && substr($d, -8) === $fim;
        }));
    }

    if (!$times) {
        // Os últimos 4 dígitos vão junto de propósito: sem eles, "confere seu
        // número" manda o GM olhar um cadastro que pode estar certo, sem saber
        // que o WhatsApp mandou outro número. Com eles, ele compara na hora.
        return [null, "Não achei seu cadastro pelo telefone (o WhatsApp me mandou um número terminado em "
                    . substr($digitos, -4) . "). Confere se ESSE número está no seu perfil no site — "
                    . "ou use o comando com o nome do time."];
    }

    // Um GM pode ter time em mais de uma liga. No Chat Off de uma liga, é o
    // dela que interessa.
    if (count($times) > 1 && $ligaDoGrupo) {
        foreach ($times as $t) if ($t['league'] === $ligaDoGrupo) return [$t, null];
    }
    if (count($times) > 1) {
        $lista = implode(', ', array_map(fn($t) => $t['league'], $times));
        return [null, "Você tem time em mais de uma liga ({$lista}). Use o comando com o nome do time."];
    }
    return [$times[0], null];
}

/**
 * Quem o time colocou no trade block.
 *
 * A lista é a mesma que o GM marcou em "Meu Elenco" (players.available_for_trade)
 * — não é sugestão do bot nem quem "parece" negociável. Ordem por OVR: quem
 * pergunta o trade block quer saber o melhor que tem lá, não a ordem alfabética.
 */
function wcTradeBlock(PDO $pdo, array $time): string
{
    $ovr = wcColunaOvr($pdo);
    $st = $pdo->prepare("SELECT name, position, secondary_position, {$ovr} AS ovr, age
                         FROM players
                         WHERE team_id = ? AND available_for_trade = 1
                         ORDER BY {$ovr} DESC, name ASC");
    $st->execute([(int)$time['id']]);
    $jogadores = $st->fetchAll(PDO::FETCH_ASSOC);

    $nome = wcNomeDoTime($time);
    if (!$jogadores) {
        return "*Trade Block — {$nome}*\n\nNinguém no trade block.";
    }

    $txt = "*Trade Block — {$nome}*\n_" . count($jogadores) . ' jogador'
         . (count($jogadores) === 1 ? '' : 'es') . "_\n\n";
    foreach ($jogadores as $p) {
        // Secundária só quando é OUTRA posição: o banco às vezes repete a
        // principal ali, e saía "C/C".
        $sec = trim((string)($p['secondary_position'] ?? ''));
        $pos = $p['position'] . ($sec !== '' && $sec !== $p['position'] ? '/' . $sec : '');
        $txt .= "{$pos}: {$p['name']} {$p['ovr']} | {$p['age']}y\n";
    }
    return rtrim($txt);
}

/** O trade block do time de quem perguntou, pelo telefone. */
function wcMeuTradeBlock(PDO $pdo, string $deQuem, ?string $ligaDoGrupo): string
{
    [$t, $erro] = wcTimeDeQuemPerguntou($pdo, $deQuem, $ligaDoGrupo);
    return $erro ?: wcTradeBlock($pdo, $t);
}

/** O trade block de um time pelo nome. */
function wcTradeBlockDeTime(PDO $pdo, string $termo, ?string $ligaDoGrupo): string
{
    if ($termo === '') return 'Use assim: /tblock lakers';
    [$t, $erro] = wcResolverTime($pdo, $termo, $ligaDoGrupo);
    return $erro ?: wcTradeBlock($pdo, $t);
}

function wcMeuElenco(PDO $pdo, string $deQuem, ?string $ligaDoGrupo): string
{
    [$t, $erro] = wcTimeDeQuemPerguntou($pdo, $deQuem, $ligaDoGrupo);
    return $erro ?: wcTime($pdo, '', $t);
}

function wcMeuCap(PDO $pdo, string $deQuem, ?string $ligaDoGrupo): string
{
    [$t, $erro] = wcTimeDeQuemPerguntou($pdo, $deQuem, $ligaDoGrupo);
    return $erro ?: wcCap($pdo, '', $t);
}

function wcMinhasPicks(PDO $pdo, string $deQuem, ?string $ligaDoGrupo): string
{
    [$t, $erro] = wcTimeDeQuemPerguntou($pdo, $deQuem, $ligaDoGrupo);
    return $erro ?: wcPicks($pdo, '', $t);
}

/**
 * As três últimas trocas do time de quem perguntou.
 *
 * Diferente do /trocas em duas coisas: filtra pelo time (dos dois lados, que
 * quem propôs e quem recebeu trocaram igual) e conta do ponto de vista de
 * quem pergunta — "saiu" e "chegou", não "→" e "←". Na lista geral o leitor é
 * plateia; aqui ele é uma das partes, e ler a própria troca invertida é o tipo
 * de detalhe que faz duvidar do bot.
 */
function wcMinhasTrades(PDO $pdo, string $deQuem, ?string $ligaDoGrupo): string
{
    [$meu, $erro] = wcTimeDeQuemPerguntou($pdo, $deQuem, $ligaDoGrupo);
    if ($erro) return $erro;

    $st = $pdo->prepare("
        SELECT t.id, t.from_team_id, t.updated_at,
               de.city AS de_city, de.name AS de_name,
               pra.city AS pra_city, pra.name AS pra_name
        FROM trades t
        JOIN teams de  ON de.id  = t.from_team_id
        JOIN teams pra ON pra.id = t.to_team_id
        WHERE t.status = 'accepted' AND (t.from_team_id = ? OR t.to_team_id = ?)
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT 3
    ");
    $st->execute([(int)$meu['id'], (int)$meu['id']]);
    $trocas = $st->fetchAll(PDO::FETCH_ASSOC);

    $euSou = wcNomeDoTime($meu);
    if (!$trocas) return "*{$euSou}* ainda não fez nenhuma troca.";

    $txt = "*Suas últimas trocas* — {$euSou}\n";
    foreach ($trocas as $t) {
        [$doDe, $doPara] = wcItensDaTroca($pdo, (int)$t['id'], (string)($meu['league'] ?? ''));

        // Quem propôs entrega os itens marcados com from_team; quem recebeu,
        // os outros. Sem essa inversão, metade das trocas sairia trocada.
        $euPropus = (int)$t['from_team_id'] === (int)$meu['id'];
        $saiu   = $euPropus ? $doDe   : $doPara;
        $chegou = $euPropus ? $doPara : $doDe;

        $outro = $euPropus
            ? wcNomeDoTime(['city' => $t['pra_city'], 'name' => $t['pra_name']])
            : wcNomeDoTime(['city' => $t['de_city'],  'name' => $t['de_name']]);

        $quando = $t['updated_at'] ? date('d/m/Y', strtotime((string)$t['updated_at'])) : '';

        $txt .= "\n*com {$outro}*" . ($quando ? " _{$quando}_" : '') . "\n"
              . 'saiu: '   . ($saiu   ? implode(', ', $saiu)   : 'nada') . "\n"
              . 'chegou: ' . ($chegou ? implode(', ', $chegou) : 'nada') . "\n";
    }
    return rtrim($txt);
}

/**
 * O que cada lado entregou numa troca, já formatado.
 *
 * Devolve [do time "de", do time "para"]. Os itens guardam nome e OVR copiados
 * na hora da troca, então continuam certos mesmo depois de o jogador rodar por
 * mais times.
 *
 * Separado porque o /trocas e o /minhastrades leem a mesma tabela e precisam
 * do mesmo rótulo; duas cópias divergiriam no primeiro ajuste de formato.
 */
function wcItensDaTroca(PDO $pdo, int $tradeId, ?string $league = null): array
{
    // JOIN com picks pra dizer QUAL pick foi — "uma pick" não deixava ninguém
    // avaliar a troca. O ano e a rodada estão lá; era só ir buscar.
    static $st = null;
    if ($st === null) {
        $st = $pdo->prepare("SELECT ti.from_team, ti.player_name, ti.player_ovr, ti.player_age, ti.pick_id,
                                    pk.round, pk.season_year
                             FROM trade_items ti
                             LEFT JOIN picks pk ON pk.id = ti.pick_id
                             WHERE ti.trade_id = ? ORDER BY ti.id");
    }
    $st->execute([$tradeId]);

    $comSalario = strtoupper(trim((string)$league)) === 'ELITE'
               && wcLigaEmSalario($pdo, (string)$league);

    // Só o peso da pick aparece aqui. Salário de jogador, não: trade_items é
    // uma foto do dia da troca (guarda nome e OVR), e escrever o salário de
    // hoje ao lado de uma troca de duas temporadas atrás seria inventar.
    $doDe = []; $doPara = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
        if ($i['player_name']) {
            // Mesmo formato de rotuloJogadorTradeWhats() (o aviso do grupo):
            // "(OVR/IDADEy)". OVR sem idade não diz muita coisa numa troca —
            // 70 aos 21 e 70 aos 33 são negócios opostos.
            $ficha = $i['player_ovr'] ? $i['player_ovr'] . ($i['player_age'] ? '/' . $i['player_age'] . 'y' : '') : '';
            $rot = $i['player_name'] . ($ficha !== '' ? " ({$ficha})" : '');
        } elseif ($i['pick_id']) {
            $rot = $i['round']
                ? trim(($i['season_year'] ? $i['season_year'] . ' ' : '') . $i['round'] . 'ª')
                    . ($comSalario ? ' (' . capValorDaPickNaTroca((int)$i['round']) . 'M)' : '')
                : 'uma pick';
        } else {
            $rot = '?';
        }
        if (!empty($i['from_team'])) $doDe[] = $rot; else $doPara[] = $rot;
    }
    return [$doDe, $doPara];
}

/**
 * Os itens de uma troca de VÁRIOS times, agrupados por quem recebe.
 *
 * Irmã de wcItensDaTroca() — aquela é 1x1 e usa `trade_items`/`from_team`
 * (booleano); esta é N-vias e usa `multi_trade_items`/`to_team_id` (cada
 * item já sabe pra que time vai, não tem lado "de"). A coluna que liga o
 * item à troca é `trade_id` — não existe `multi_trade_id` na tabela.
 */
function wcItensMultiPorTime(PDO $pdo, int $multiTradeId): array
{
    $ovr = wcColunaOvr($pdo);
    static $st = null;
    if ($st === null) {
        $st = $pdo->prepare("
            SELECT mi.to_team_id,
                   COALESCE(p.name, mi.player_name) AS nome,
                   COALESCE(p.{$ovr}, mi.player_ovr) AS ovr,
                   mi.player_age,
                   pk.season_year, pk.round,
                   dt.city AS dc, dt.name AS dn
            FROM multi_trade_items mi
            LEFT JOIN players p ON p.id = mi.player_id
            LEFT JOIN picks pk ON pk.id = mi.pick_id
            LEFT JOIN teams dt ON dt.id = mi.to_team_id
            WHERE mi.trade_id = ?
            ORDER BY mi.id
        ");
    }
    $st->execute([$multiTradeId]);

    $porTime = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $destino = wcNomeDoTime(['city' => $i['dc'] ?? null, 'name' => $i['dn'] ?? null]) ?: 'Time';
        if (!empty($i['nome'])) {
            $ficha = $i['ovr'] ? $i['ovr'] . ($i['player_age'] ? '/' . $i['player_age'] . 'y' : '') : '';
            $porTime[$destino][] = $i['nome'] . ($ficha !== '' ? " ({$ficha})" : '');
        } elseif (!empty($i['pick_id']) || !empty($i['season_year'])) {
            $porTime[$destino][] = trim(($i['season_year'] ? $i['season_year'] . ' ' : '') . ($i['round'] ? $i['round'] . 'ª' : 'pick'));
        }
    }
    return $porTime;
}

/**
 * Quantas trocas o /trocas mostra.
 *
 * Três, e não cinco: no WhatsApp cada troca ocupa três ou quatro linhas —
 * com cinco, a mensagem passava de vinte linhas e o grupo tinha que rolar
 * pra ver o resto do que estava conversando.
 */
const WC_TROCAS_QUANTAS = 3;

/**
 * A liga de quem mandou o comando da escala.
 *
 * O grupo das lives é UM só e a chamada é por liga, então o comando
 * precisa dizer de qual. Sem argumento vale a liga do próprio GM — que é
 * o caso de quase todo mundo. Com argumento (/comentarista RISE) dá pra
 * ajudar noutra liga, que é o motivo de o campo existir.
 */
function wcEscalaLiga(PDO $pdo, string $arg, string $deQuem, ?string $ligaDoGrupo): array
{
    // Reaproveita o resolvedor dos comandos "meus", que já trata o que eu ia
    // tratar pior: número gravado em formato diferente do cadastro,
    // casamento pelos últimos dígitos, e o @lid — o id interno que o
    // WhatsApp manda no lugar do telefone em alguns grupos, e que faria a
    // busca dizer "não achei" para quem tem o cadastro certo.
    [$time, $erro] = wcTimeDeQuemPerguntou($pdo, $deQuem, $ligaDoGrupo);
    if ($erro) return [null, null, null, $erro];

    require_once __DIR__ . '/../backend/escala_live.php';

    // O argumento virou "liga [fase]": /narrador next offs. A ordem não
    // importa — quem escreve "/narrador offs next" quis a mesma coisa, e
    // recusar por causa da ordem seria implicância.
    $partes = preg_split('/\s+/', trim($arg), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $liga = $fase = null;
    $sobrou = [];
    foreach ($partes as $p) {
        if (!$fase && ($f = escalaFaseNormalizar($p))) { $fase = $f; continue; }
        if (!$liga && ($l = wcNormalizarLiga($p)))     { $liga = $l; continue; }
        $sobrou[] = $p;
    }
    if ($sobrou) {
        return [null, null, null, 'Não entendi "' . implode(' ', $sobrou)
            . '". Use assim: */narrador next* ou */narrador next offs*.'];
    }

    // Sem liga vale a do TIME de quem mandou, que é o caso de quase todo
    // mundo. Com o nome dá pra ajudar noutra liga.
    if (!$liga) $liga = strtoupper((string)($time['league'] ?? ''));
    if (!in_array($liga, CALENDARIO_LIGAS, true)) $liga = 'ELITE';

    return [(int)$time['user_id'], $liga, $fase ?: 'todas', null];
}

/** /comentarista, /narrador, /operacional, /transmissao — soma a função. */
function wcEscalaTopar(PDO $pdo, string $funcao, string $arg, string $deQuem, ?string $ligaDoGrupo): string
{
    require_once __DIR__ . '/../backend/escala_live.php';
    [$uid, $liga, $fase, $erro] = wcEscalaLiga($pdo, $arg, $deQuem, $ligaDoGrupo);
    if ($erro) return $erro;

    $r = escalaAdicionar($pdo, $uid, $liga, $funcao, null, $fase);
    if (!$r['ok']) return (string)$r['erro'];

    // UMA LINHA, e não a confirmação de três que existia antes.
    //
    // Chegou a ficar em silêncio: vinte pessoas se oferecendo viravam vinte
    // mensagens do bot enterrando a conversa. Mas sem resposta nenhuma
    // ninguém sabe se entrou, e a pessoa reenvia o comando — o que enche o
    // grupo do mesmo jeito, só que com mensagem da pessoa.
    //
    // Então confirma, curto. A fase entra porque é o que a pessoa mais tem
    // motivo pra duvidar que pegou; as outras funções dela, não — pra isso
    // existe o /verescala, e repetir a lista inteira a cada comando é
    // justamente o que fazia a mensagem crescer.
    $rot  = escalaFuncoes()[$funcao]['rotulo'];
    $ff   = escalaFaseRotulo($fase);
    $comp = $ff ? ' _(' . $ff . ')_' : '';

    return ($r['novo'] || $r['mudou'] ? '✅' : '👍')
         . " *{$rot}* · {$liga}{$comp}";
}

/** /sair — tira da chamada da semana. */
function wcEscalaSair(PDO $pdo, string $arg, string $deQuem, ?string $ligaDoGrupo): string
{
    require_once __DIR__ . '/../backend/escala_live.php';
    // O /sair aceita os MESMOS argumentos de entrar: liga e fase. Quem topa
    // a regular e o offs e só quer largar um dos dois não tinha como dizer
    // isso — o /sair tirava dos dois.
    [$uid, $liga, $fase, $erro] = wcEscalaLiga($pdo, $arg, $deQuem, $ligaDoGrupo);
    if ($erro) return $erro;

    $r = escalaSair($pdo, $uid, $liga, null, $fase);
    if (!$r['ok']) return 'Não deu pra tirar agora. Tenta de novo.';
    if (!$r['tirou'] && !$r['vagas']) {
        $qual = $fase !== 'todas' ? ' ' . escalaFaseNaFrase($fase) : '';
        return "Você já não estava na chamada da *{$liga}*{$qual} esta semana.";
    }

    $DIAS = ['dom','seg','ter','qua','qui','sex','sáb'];
    $quando = fn($d) => $DIAS[(int)date('w', strtotime($d))] . ' ' . date('d/m', strtotime($d));
    $rot = fn($f) => escalaFuncoes()[$f]['rotulo'] ?? $f;

    // A mensagem diz o que SOBROU, e não só o que saiu. Quem manda
    // "/sair elite offs" precisa ver na resposta que continua valendo pra
    // regular — senão fica na dúvida se tirou demais e manda o comando de
    // entrar de novo, por garantia.
    if ($fase === 'todas') {
        $txt = "Pronto, tirei você da chamada da *{$liga}* desta semana.";
    } else {
        $txt = "Pronto — na *{$liga}* você não entra mais " . escalaFaseNaFrase($fase) . '.';
        if ($r['restou']) {
            $sobrou = escalaFaseNaFrase($fase === 'regular' ? 'playoffs' : 'regular');
            $txt .= "\n_Continua valendo {$sobrou}: "
                  . implode(', ', array_map($rot, $r['restou'])) . '._';
        }
    }

    // Quem entrou no lugar aparece NOMEADO: sem isso a pessoa que saiu não
    // sabe se deixou buraco, e quem ficou não sabe que a vaga foi coberta.
    if ($r['substituidos']) {
        $txt .= "\n\n🔁 *Chamei da fila:*";
        foreach ($r['substituidos'] as $s) {
            $txt .= "\n• " . $quando($s['data']) . ' — ' . $rot($s['funcao']) . ': *' . $s['nome'] . '*';
        }
    }
    if ($r['orfas']) {
        $txt .= "\n\n⚠️ *Sem ninguém na fila pra:*";
        foreach ($r['orfas'] as $o) {
            $txt .= "\n• " . $quando($o['data']) . ' — ' . $rot($o['funcao']);
        }
        $txt .= "\nQuem puder cobrir, manda o comando da função.";
    }
    return $txt;
}

/** /verescala — como está a semana. */
function wcEscalaVer(PDO $pdo, string $arg, string $deQuem, ?string $ligaDoGrupo): string
{
    require_once __DIR__ . '/../backend/escala_live.php';
    $l = trim($arg) !== '' ? wcNormalizarLiga(trim($arg)) : null;
    if (!$l) {
        [, $l, , $erro] = wcEscalaLiga($pdo, '', $deQuem, $ligaDoGrupo);
        // Sem cadastro dá pra ver assim mesmo: ver não muda nada, e exigir
        // telefone certo pra LER seria barrar por nada.
        if ($erro) $l = strtoupper((string)($ligaDoGrupo ?? '')) ?: 'ELITE';
    }
    return escalaTextoVer($pdo, $l);
}

/** /escala — abre a chamada no grupo, na mão. */
function wcEscalaChamar(PDO $pdo, string $arg, string $deQuem, ?string $ligaDoGrupo): string
{
    require_once __DIR__ . '/../backend/escala_live.php';
    $l = trim($arg) !== '' ? wcNormalizarLiga(trim($arg)) : null;
    if (!$l) {
        [, $l, , $erro] = wcEscalaLiga($pdo, '', $deQuem, $ligaDoGrupo);
        if ($erro) $l = strtoupper((string)($ligaDoGrupo ?? '')) ?: 'ELITE';
    }
    return escalaTextoChamada($pdo, $l);
}

function wcTrocas(PDO $pdo, string $termo, ?string $ligaDoGrupo): string
{
    // O argumento aceita LIGA ou TIME. A liga é testada primeiro porque
    // "RISE" e "NEXT" são palavras curtas que podem casar com apelido de
    // franquia — e quem digita /trocas RISE quer a liga.
    $liga = $termo !== '' ? wcNormalizarLiga($termo) : $ligaDoGrupo;
    $time = null;

    if ($termo !== '' && !$liga) {
        [$t, $erro] = wcResolverTime($pdo, $termo, $ligaDoGrupo);
        if ($erro) return $erro;   // "Não achei time com ..." já é a resposta
        $time = $t;
        $liga = $t['league'] ?? null;
    }

    // Com time, o filtro é o time (dos dois lados — quem propôs e quem
    // topou). Sem time, é a liga.
    if ($time) {
        $filtro  = ' AND (t.from_team_id = ? OR t.to_team_id = ?)';
        $params  = [(int)$time['id'], (int)$time['id']];
    } else {
        $filtro  = $liga ? ' AND t.league = ?' : '';
        $params  = $liga ? [$liga] : [];
    }

    // SÓ A SPRINT ABERTA. Troca de sprint passada não é notícia — é
    // história, e história tem a sala de troféus e a página de trades. Aqui
    // é "o que andou acontecendo", e o que aconteceu há duas sprints não
    // andou acontecendo.
    //
    // O recorte é por DATA porque trade não tem season_id, igual às
    // estatísticas. Sem liga definida não há sprint pra olhar, e aí o
    // filtro não entra — mostrar tudo é melhor que mostrar nada.
    $inicioSprint = null;
    if ($liga) {
        try {
            $stS = $pdo->prepare("SELECT MAX(start_date) FROM sprints
                                   WHERE league = ? AND status = 'active'");
            $stS->execute([$liga]);
            $inicioSprint = $stS->fetchColumn() ?: null;
        } catch (Throwable $e) { /* sem tabela de sprint: mostra tudo */ }
    }
    if ($inicioSprint) {
        $filtro  .= ' AND t.created_at >= ?';
        $params[] = $inicioSprint;
    }

    $st = $pdo->prepare("
        SELECT t.id, t.league, t.updated_at, '1x1' AS tipo,
               de.city AS de_city, de.name AS de_name,
               pra.city AS pra_city, pra.name AS pra_name
        FROM trades t
        JOIN teams de  ON de.id  = t.from_team_id
        JOIN teams pra ON pra.id = t.to_team_id
        WHERE t.status = 'accepted' {$filtro}
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT " . WC_TROCAS_QUANTAS . "
    ");
    $st->execute($params);
    $trocas = $st->fetchAll(PDO::FETCH_ASSOC);

    // As trocas de vários times vivem numa tabela separada (mesma origem
    // do botão "copiar trades" do admin) — sem isso o /trocas do bot nunca
    // mostrava as multi, só as 1x1.
    $multis = [];
    try {
        // Na multi o time não está na linha da troca, está nos ITENS — daí o
        // EXISTS. Sem ele, /trocas <time> traria as multi de todo mundo
        // misturadas com as trocas do time pedido.
        if ($time) {
            $filtroM = ' AND EXISTS (SELECT 1 FROM multi_trade_items mi
                                      WHERE mi.trade_id = mt.id
                                        AND (mi.from_team_id = ? OR mi.to_team_id = ?))';
            $paramsM = [(int)$time['id'], (int)$time['id']];
        } else {
            $filtroM = $liga ? ' AND mt.league = ?' : '';
            $paramsM = $liga ? [$liga] : [];
        }
        if ($inicioSprint) {
            $filtroM  .= ' AND mt.created_at >= ?';
            $paramsM[] = $inicioSprint;
        }
        $stM = $pdo->prepare("
            SELECT mt.id, mt.league, mt.updated_at, 'multi' AS tipo
            FROM multi_trades mt
            WHERE mt.status = 'accepted' {$filtroM}
            ORDER BY mt.updated_at DESC, mt.id DESC
            LIMIT " . WC_TROCAS_QUANTAS . "
        ");
        $stM->execute($paramsM);
        $multis = $stM->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* liga sem multi_trades ainda: a lista vale só com as 1x1 */ }

    // As duas listas vêm com o teto cada uma, e o corte final é sobre a
    // JUNÇÃO — senão uma trade 1x1 recente ficaria de fora por causa de
    // três multi mais antigas que já encheram a cota.
    $todas = array_merge($trocas, $multis);
    usort($todas, fn($a, $b) => strtotime((string)$b['updated_at']) <=> strtotime((string)$a['updated_at']));
    $todas = array_slice($todas, 0, WC_TROCAS_QUANTAS);

    $deQuem = $time ? wcNomeDoTime($time) : ($liga ?: '');

    if (!$todas) {
        // "nesta sprint" e não "ainda": o time pode ter dez trocas na sprint
        // passada, e dizer "ainda" mandaria a pessoa procurar defeito.
        return 'Nenhuma troca aprovada' . ($deQuem ? " do {$deQuem}" : '')
             . ($inicioSprint ? ' nesta sprint.' : ' ainda.');
    }

    $txt = '*Últimas trocas' . ($deQuem ? " — {$deQuem}" : '') . "*\n";
    foreach ($todas as $t) {
        // A liga só aparece por troca quando o pedido não fixou nenhuma —
        // com time ou liga escolhida, ela já está no cabeçalho.
        $sufixoLiga = ($liga || $time) ? '' : ' _' . $t['league'] . '_';

        if ($t['tipo'] === 'multi') {
            $porTime = wcItensMultiPorTime($pdo, (int)$t['id']);
            $txt .= "\n*Troca de " . count($porTime) . " times*{$sufixoLiga}\n";
            foreach ($porTime as $time => $itens) {
                $txt .= "{$time} recebe: " . ($itens ? implode(', ', $itens) : 'nada') . "\n";
            }
            continue;
        }

        [$vai, $vem] = wcItensDaTroca($pdo, (int)$t['id'], (string)($t['league'] ?? $liga ?? ''));
        $deNome  = wcNomeDoTime(['city' => $t['de_city'],  'name' => $t['de_name']]);
        $praNome = wcNomeDoTime(['city' => $t['pra_city'], 'name' => $t['pra_name']]);

        $txt .= "\n*{$deNome}* ⇄ *{$praNome}*{$sufixoLiga}\n"
              . '→ ' . ($vai ? implode(', ', $vai) : 'nada') . "\n"
              . '← ' . ($vem ? implode(', ', $vem) : 'nada') . "\n";
    }
    return rtrim($txt);
}

function wcComparar(PDO $pdo, string $termo, ?string $ligaDoGrupo = null): string
{
    // Aceita "x", "vs", "versus" ou "e" como separador — o pessoal digita
    // qualquer um dos quatro.
    $partes = preg_split('/\s+(?:x|vs\.?|versus|e)\s+/iu', $termo, 2);
    if (count($partes) < 2 || trim($partes[0]) === '' || trim($partes[1]) === '') {
        return "Use assim: /comparar lebron x tatum";
    }

    $ovr = wcColunaOvr($pdo);
    $ordem = wcOrdemLiga($ligaDoGrupo);
    $achar = function (string $nome) use ($pdo, $ovr, $ordem) {
        $st = $pdo->prepare("
            SELECT p.id, p.name, p.age, p.position, p.{$ovr} AS ovr, p.seasons_in_league,
                   p.team_id, COALESCE(p.is_lenda,0) AS is_lenda,
                   " . wcColunasSkill('p') . ",
                   t.city, t.name AS team_name, t.league
            FROM players p JOIN teams t ON t.id = p.team_id
            WHERE p.name LIKE ? ORDER BY {$ordem}, p.{$ovr} DESC LIMIT 1
        ");
        $st->execute(['%' . trim($nome) . '%']);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    $a = $achar($partes[0]);
    $b = $achar($partes[1]);
    if (!$a) return 'Não achei jogador com "' . trim($partes[0]) . '".';
    if (!$b) return 'Não achei jogador com "' . trim($partes[1]) . '".';
    if ((int)$a['id'] === (int)$b['id']) return 'Os dois nomes acharam o mesmo jogador (' . $a['name'] . ').';

    $salario = function (array $p) use ($pdo): ?int {
        if (!wcLigaEmSalario($pdo, (string)$p['league'])) return null;
        foreach ((getTeamCapSummary($pdo, (int)$p['team_id'])['roster'] ?? []) as $r) {
            if ((int)$r['id'] === (int)$p['id']) return (int)$r['total_salary'];
        }
        return null;
    };
    $sa = $salario($a); $sb = $salario($b);

    // Marca quem leva em cada quesito — é o que a pessoa quer ver de relance.
    $m = fn($x, $y, bool $maiorGanha = true) => $x === $y ? '' : (($maiorGanha ? $x > $y : $x < $y) ? ' ✅' : '');

    $linha = function (string $rotulo, $va, $vb, string $sufA = '', string $sufB = '') {
        return "{$rotulo}: {$va}{$sufA}  |  {$vb}{$sufB}\n";
    };

    $txt = '*' . $a['name'] . '*' . (!empty($a['is_lenda']) ? ' 👑' : '')
         . "\n_vs_\n"
         . '*' . $b['name'] . '*' . (!empty($b['is_lenda']) ? ' 👑' : '') . "\n\n"
         . $linha('OVR', $a['ovr'], $b['ovr'], $m((int)$a['ovr'], (int)$b['ovr']), $m((int)$b['ovr'], (int)$a['ovr']))
         // Mais novo é vantagem: por isso este compara ao contrário.
         . $linha('Idade', $a['age'] . ' anos', $b['age'] . ' anos',
                  $m((int)$a['age'], (int)$b['age'], false), $m((int)$b['age'], (int)$a['age'], false))
         . $linha('Posição', $a['position'], $b['position'])
         . $linha('Temporadas', (int)$a['seasons_in_league'], (int)$b['seasons_in_league']);

    if ($sa !== null || $sb !== null) {
        // Salário menor é vantagem, mas só compara quando os dois têm.
        $txt .= $linha('Salário', $sa !== null ? $sa . 'M' : '—', $sb !== null ? $sb . 'M' : '—',
            ($sa !== null && $sb !== null) ? $m($sa, $sb, false) : '',
            ($sa !== null && $sb !== null) ? $m($sb, $sa, false) : '');
    }

    // ── Números da temporada ─────────────────────────────────────────────
    $ea = wcStatsDoJogador($pdo, (int)$a['id']);
    $eb = wcStatsDoJogador($pdo, (int)$b['id']);
    if ($ea || $eb) {
        // Se cada um tem lançamento de uma temporada diferente, dizer qual —
        // senão a comparação parece do mesmo ano e não é.
        $mesmaTemp = $ea && $eb && (int)$ea['season_number'] === (int)$eb['season_number'];
        $txt .= "\n📊 *" . ($mesmaTemp ? 'Temporada ' . (int)$ea['season_number'] : 'Última temporada de cada um') . "*\n";

        foreach ([['pts_pg','Pontos'], ['reb_pg','Rebotes'], ['ast_pg','Assist.'],
                  ['stl_pg','Roubos'], ['blk_pg','Tocos'], ['min_pg','Minutos']] as [$campo, $rot]) {
            $va = $ea ? (float)$ea[$campo] : null;
            $vb = $eb ? (float)$eb[$campo] : null;
            if ($va === null && $vb === null) continue;
            $txt .= $linha($rot,
                $va !== null ? wcNum($va) : '—',
                $vb !== null ? wcNum($vb) : '—',
                ($va !== null && $vb !== null) ? $m($va, $vb) : '',
                ($va !== null && $vb !== null) ? $m($vb, $va) : '');
        }
        if (!$mesmaTemp) {
            $txt .= '_' . ($ea ? 'T' . (int)$ea['season_number'] : 'sem dados')
                  . ' · ' . ($eb ? 'T' . (int)$eb['season_number'] : 'sem dados') . "_\n";
        }
    }

    // ── Skills ───────────────────────────────────────────────────────────
    $ska = wcSkillsDoJogador($a);
    $skb = wcSkillsDoJogador($b);
    if ($ska && !$skb) {
        // Dez linhas de "A+ | —" não comparam nada, só ocupam a tela.
        $txt .= "\n_" . $b['name'] . " ainda não tem skills cadastradas._\n";
    } elseif ($skb && !$ska) {
        $txt .= "\n_" . $a['name'] . " ainda não tem skills cadastradas._\n";
    } elseif ($ska && $skb) {
        $txt .= "\n⭐ *Skills*\n";
        foreach (WC_SKILLS as $rotulo) {
            $va = $ska[$rotulo] ?? null;
            $vb = $skb[$rotulo] ?? null;
            if ($va === null && $vb === null) continue;
            // Compara pela nota numérica: sem isso "A" e "B+" seriam só textos.
            $na = wcNotaSkill($va);
            $nb = wcNotaSkill($vb);
            $podeComparar = $na !== null && $nb !== null;
            $txt .= $linha($rotulo, $va ?? '—', $vb ?? '—',
                $podeComparar ? $m($na, $nb) : '',
                $podeComparar ? $m($nb, $na) : '');
        }
    }

    $txt .= "\n" . wcNomeDoTime($a) . ' (' . $a['league'] . ')'
          . '  |  ' . wcNomeDoTime($b) . ' (' . $b['league'] . ')';
    return $txt;
}

/**
 * Títulos do time na edição (sprint) atual da liga dele.
 *
 * O campeão de uma temporada é gravado em DOIS lugares — playoff_results
 * (position='champion') e season_history (champion_team_id) — e nem sempre nos
 * dois. Conto das duas fontes deduplicando por temporada: assim funciona seja
 * qual for a que o admin usou, e uma temporada registrada nas duas não vira
 * dois títulos.
 *
 * Retorna ['titulos' => int, 'edicao' => int|null].
 */
function wcTitulosNaEdicao(PDO $pdo, int $teamId, string $league): array
{
    try {
        $st = $pdo->prepare("SELECT id, sprint_number FROM sprints
                             WHERE league = ?
                             ORDER BY (status = 'active') DESC, sprint_number DESC
                             LIMIT 1");
        $st->execute([$league]);
        $sprint = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sprint) return ['titulos' => 0, 'edicao' => null];

        $st = $pdo->prepare("
            SELECT COUNT(DISTINCT s.id)
            FROM seasons s
            WHERE s.sprint_id = ?
              AND (EXISTS (SELECT 1 FROM playoff_results pr
                           WHERE pr.season_id = s.id AND pr.team_id = ? AND pr.position = 'champion')
                OR EXISTS (SELECT 1 FROM season_history sh
                           WHERE sh.season_id = s.id AND sh.champion_team_id = ?))
        ");
        $st->execute([(int)$sprint['id'], $teamId, $teamId]);

        return ['titulos' => (int)$st->fetchColumn(), 'edicao' => (int)$sprint['sprint_number']];
    } catch (Throwable $e) {
        // Tabela de playoffs ausente num banco antigo não pode derrubar a
        // comparação inteira — a linha some e o resto continua.
        error_log('[whatsapp-cmd] titulos: ' . $e->getMessage());
        return ['titulos' => 0, 'edicao' => null];
    }
}

/**
 * Quantas séries de playoff o time venceu, e até onde chegou de melhor.
 *
 * O banco guarda só ONDE o time parou (playoff_results.position), não quantas
 * séries ganhou — mas dá pra derivar: quem perdeu na 1ª rodada ganhou zero
 * séries, quem caiu na 2ª ganhou uma, e assim por diante até o campeão com
 * quatro. É a mesma contagem que qualquer um faria de cabeça.
 */
function wcPlayoffsNaEdicao(PDO $pdo, int $teamId, ?int $sprintId): array
{
    $SERIES = ['first_round' => 0, 'second_round' => 1, 'conference_final' => 2,
               'runner_up' => 3, 'champion' => 4];
    $NOME   = ['first_round' => '1ª rodada', 'second_round' => 'semi de conf.',
               'conference_final' => 'final de conf.', 'runner_up' => 'vice', 'champion' => 'campeão'];

    $out = ['series' => 0, 'melhor' => null, 'aparicoes' => 0];
    if (!$sprintId) return $out;

    try {
        $st = $pdo->prepare("
            SELECT pr.position
            FROM playoff_results pr
            JOIN seasons s ON s.id = pr.season_id
            WHERE pr.team_id = ? AND s.sprint_id = ?
        ");
        $st->execute([$teamId, $sprintId]);
        $melhorValor = -1;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pos) {
            if (!isset($SERIES[$pos])) continue;
            $out['series'] += $SERIES[$pos];
            $out['aparicoes']++;
            if ($SERIES[$pos] > $melhorValor) { $melhorValor = $SERIES[$pos]; $out['melhor'] = $NOME[$pos]; }
        }
    } catch (Throwable $e) {
        error_log('[whatsapp-cmd] playoffs: ' . $e->getMessage());
    }
    return $out;
}

/** Sprint (edição) atual da liga. */
function wcSprintAtual(PDO $pdo, string $league): ?array
{
    try {
        $st = $pdo->prepare("SELECT id, sprint_number FROM sprints WHERE league = ?
                             ORDER BY (status = 'active') DESC, sprint_number DESC LIMIT 1");
        $st->execute([$league]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * O que só existe ENTRE os dois: trocas que fizeram um com o outro.
 *
 * Numa comparação de times isso é o dado mais específico que dá pra dar — e é
 * o que costuma render discussão no grupo.
 */
function wcEntreOsDois(PDO $pdo, int $aId, int $bId): array
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM trades
                             WHERE status = 'accepted'
                               AND ((from_team_id = ? AND to_team_id = ?)
                                 OR (from_team_id = ? AND to_team_id = ?))");
        $st->execute([$aId, $bId, $bId, $aId]);
        return ['trocas' => (int)$st->fetchColumn()];
    } catch (Throwable $e) {
        return ['trocas' => 0];
    }
}

function wcCompararTimes(PDO $pdo, string $termo, ?string $ligaDoGrupo = null): string
{
    // Só x/vs/versus aqui. O /comparar de jogador também aceita "e", mas nome
    // de time tem muito mais chance de conter " e " no meio e ser partido no
    // lugar errado.
    $partes = preg_split('/\s+(?:x|vs\.?|versus)\s+/iu', $termo, 2);
    if (count($partes) < 2 || trim($partes[0]) === '' || trim($partes[1]) === '') {
        return "Use assim: /comparartime lakers x celtics";
    }

    [$a, $erroA] = wcResolverTime($pdo, trim($partes[0]), $ligaDoGrupo);
    if ($erroA) return $erroA;
    [$b, $erroB] = wcResolverTime($pdo, trim($partes[1]), $ligaDoGrupo);
    if ($erroB) return $erroB;
    if ((int)$a['id'] === (int)$b['id']) return 'Os dois nomes acharam o mesmo time (' . wcNomeDoTime($a) . ').';

    $ovr = wcColunaOvr($pdo);
    $stElenco = $pdo->prepare("SELECT name, {$ovr} AS ovr, age, COALESCE(is_lenda,0) AS is_lenda
                               FROM players WHERE team_id = ? ORDER BY {$ovr} DESC");

    $medir = function (array $t) use ($pdo, $stElenco) {
        $stElenco->execute([(int)$t['id']]);
        $elenco = $stElenco->fetchAll(PDO::FETCH_ASSOC);
        $ovrs = array_map(fn($p) => (int)$p['ovr'], $elenco);
        $idades = array_map(fn($p) => (int)$p['age'], $elenco);

        $m = [
            'elenco'  => count($elenco),
            'melhor'  => $ovrs ? max($ovrs) : 0,
            // Soma dos CAP_TOP_N melhores é o que vale de CAP fora da ELITE — comparar
            // por aí diz mais que a média do elenco inteiro, que afunda com
            // quem tem banco grande.
            'topn'    => array_sum(array_slice($ovrs, 0, CAP_TOP_N)),
            'idade'   => $idades ? round(array_sum($idades) / count($idades), 1) : 0,
            'lendas'  => count(array_filter($elenco, fn($p) => !empty($p['is_lenda']))),
            'nomes'   => array_slice(array_map(fn($p) => $p['name'] . ' (' . $p['ovr'] . ')', $elenco), 0, 3),
            'folha'   => null,
        ];

        if (wcLigaEmSalario($pdo, (string)$t['league'])) {
            $cap = getTeamCapSummary($pdo, (int)$t['id']);
            $m['folha'] = (int)$cap['payroll'];
            $m['espaco'] = (int)$cap['space'];
        }

        $st = $pdo->prepare("SELECT COUNT(*) FROM picks WHERE team_id = ?");
        $st->execute([(int)$t['id']]);
        $m['picks'] = (int)$st->fetchColumn();

        $m += wcTitulosNaEdicao($pdo, (int)$t['id'], (string)$t['league']);

        $sprint = wcSprintAtual($pdo, (string)$t['league']);
        $m['playoffs'] = wcPlayoffsNaEdicao($pdo, (int)$t['id'], $sprint ? (int)$sprint['id'] : null);

        // A posição na tabela, e não a campanha: vitórias e derrotas não são
        // cadastradas, então a linha vinha "0-0 x 0-0" pra qualquer dupla.
        $temp = wcTemporadaAtiva($pdo, (string)$t['league']);
        $m['posto'] = null;
        if ($temp) {
            $st = $pdo->prepare("SELECT position FROM season_standings WHERE season_id = ? AND team_id = ?");
            $st->execute([(int)$temp['id'], (int)$t['id']]);
            $p = $st->fetchColumn();
            if ($p) $m['posto'] = (int)$p;
        }
        return $m;
    };

    $ma = $medir($a);
    $mb = $medir($b);

    $marca = fn($x, $y, bool $maiorGanha = true) => $x === $y ? '' : (($maiorGanha ? $x > $y : $x < $y) ? ' ✅' : '');
    $linha = fn(string $r, $va, $vb, string $sa = '', string $sb = '') => "{$r}: {$va}{$sa}  |  {$vb}{$sb}\n";

    $txt = '*' . wcNomeDoTime($a) . '*' . "\n_vs_\n" . '*' . wcNomeDoTime($b) . "*\n";
    if ($a['league'] !== $b['league']) $txt .= "_{$a['league']} · {$b['league']}_\n";
    $txt .= "\n";

    // Título vem primeiro: é o que decide discussão. O rótulo diz de qual
    // edição, senão "2 títulos" parece ser de sempre — e quando os times são de
    // ligas diferentes, cada um está numa edição com número próprio.
    if ($ma['edicao'] !== null || $mb['edicao'] !== null) {
        $ed = ($ma['edicao'] !== null && $ma['edicao'] === $mb['edicao'])
            ? 'Títulos (edição ' . $ma['edicao'] . ')'
            : 'Títulos na edição';
        $txt .= $linha($ed, $ma['titulos'] . ($ma['edicao'] !== null && $ma['edicao'] !== $mb['edicao'] ? ' (ed. ' . $ma['edicao'] . ')' : ''),
                            $mb['titulos'] . ($mb['edicao'] !== null && $ma['edicao'] !== $mb['edicao'] ? ' (ed. ' . $mb['edicao'] . ')' : ''),
                       $marca($ma['titulos'], $mb['titulos']), $marca($mb['titulos'], $ma['titulos']));
    }

    $txt .= $linha('Melhor jogador', $ma['melhor'], $mb['melhor'], $marca($ma['melhor'], $mb['melhor']), $marca($mb['melhor'], $ma['melhor']))
        . $linha('Soma top ' . CAP_TOP_N, $ma['topn'], $mb['topn'], $marca($ma['topn'], $mb['topn']), $marca($mb['topn'], $ma['topn']))
        . $linha('Elenco', $ma['elenco'], $mb['elenco'])
        // Elenco mais novo é vantagem: compara ao contrário.
        . $linha('Idade média', $ma['idade'], $mb['idade'], $marca($ma['idade'], $mb['idade'], false), $marca($mb['idade'], $ma['idade'], false))
        . $linha('Picks', $ma['picks'], $mb['picks'], $marca($ma['picks'], $mb['picks']), $marca($mb['picks'], $ma['picks']));

    if ($ma['lendas'] || $mb['lendas']) {
        $txt .= $linha('Lendas 👑', $ma['lendas'], $mb['lendas'], $marca($ma['lendas'], $mb['lendas']), $marca($mb['lendas'], $ma['lendas']));
    }

    // Folha só compara quando as DUAS ligas cobram em dinheiro — 44M contra
    // "soma de OVR" não é comparação, é confusão.
    if ($ma['folha'] !== null && $mb['folha'] !== null) {
        // Folha vai sem ✅ de propósito: folha alta não é defeito, é elenco
        // caro. Quem diz quem está melhor de dinheiro é o espaço — e marcar os
        // dois seria a mesma informação repetida ao contrário.
        $txt .= $linha('Folha', $ma['folha'] . 'M', $mb['folha'] . 'M')
              . $linha('Espaço no cap', $ma['espaco'] . 'M', $mb['espaco'] . 'M',
                       $marca($ma['espaco'], $mb['espaco']), $marca($mb['espaco'], $ma['espaco']));
    }

    if ($ma['posto'] || $mb['posto']) {
        // Posição menor é melhor — daí o `false` no comparador.
        $txt .= $linha('Posição na tabela',
                       $ma['posto'] ? $ma['posto'] . 'º' : '—',
                       $mb['posto'] ? $mb['posto'] . 'º' : '—',
                       $marca($ma['posto'] ?: 99, $mb['posto'] ?: 99, false),
                       $marca($mb['posto'] ?: 99, $ma['posto'] ?: 99, false));
    }

    // ── Playoffs da edição ───────────────────────────────────────────────
    $pa = $ma['playoffs']; $pb = $mb['playoffs'];
    if ($pa['aparicoes'] || $pb['aparicoes']) {
        $txt .= "\n🏀 *Playoffs na edição*\n"
              . $linha('Séries vencidas', $pa['series'], $pb['series'],
                       $marca($pa['series'], $pb['series']), $marca($pb['series'], $pa['series']))
              . $linha('Melhor campanha', $pa['melhor'] ?: 'não foi', $pb['melhor'] ?: 'não foi')
              . $linha('Vezes no mata-mata', $pa['aparicoes'], $pb['aparicoes'],
                       $marca($pa['aparicoes'], $pb['aparicoes']), $marca($pb['aparicoes'], $pa['aparicoes']));
    }

    // ── O que só existe entre os dois ────────────────────────────────────
    $entre = wcEntreOsDois($pdo, (int)$a['id'], (int)$b['id']);
    if ($entre['trocas'] > 0) {
        $txt .= "\n🔁 Já fizeram *" . $entre['trocas'] . ' troca'
              . ($entre['trocas'] === 1 ? '' : 's') . "* entre si.\n";
    }

    $txt .= "\n*Melhores:*\n"
          . '• ' . implode(', ', $ma['nomes'] ?: ['elenco vazio']) . "\n"
          . '• ' . implode(', ', $mb['nomes'] ?: ['elenco vazio']);

    return $txt;
}

/**
 * O histórico que existe SÓ entre dois times.
 *
 * Não há placar jogo a jogo no banco — o app guarda a classificação final da
 * temporada e o resultado de playoff, não o resultado de cada partida. Então
 * "confronto direto" aqui é o que os dois construíram um contra o outro fora
 * de quadra: trocas, quem ficou com pick de quem, e quais jogadores mudaram
 * de lado.
 */
function wcHistoricoEntre(PDO $pdo, array $a, array $b): array
{
    $aId = (int)$a['id']; $bId = (int)$b['id'];
    $out = ['trocas' => [], 'picks_a_com_b' => 0, 'picks_b_com_a' => 0,
            'foram' => [], 'vieram' => [], 'picks_foram' => 0, 'picks_vieram' => 0];

    try {
        $st = $pdo->prepare("
            SELECT id, from_team_id, updated_at
            FROM trades
            WHERE status = 'accepted'
              AND ((from_team_id = ? AND to_team_id = ?) OR (from_team_id = ? AND to_team_id = ?))
            ORDER BY updated_at DESC, id DESC
        ");
        $st->execute([$aId, $bId, $bId, $aId]);
        $out['trocas'] = $st->fetchAll(PDO::FETCH_ASSOC);

        // Quem mudou de lado. O item guarda o nome copiado na hora da troca,
        // então continua certo mesmo que o jogador já tenha saído dos dois.
        $stItens = $pdo->prepare("SELECT from_team, player_name, player_ovr, player_age, pick_id
                                  FROM trade_items WHERE trade_id = ?");
        foreach ($out['trocas'] as $t) {
            $stItens->execute([(int)$t['id']]);
            foreach ($stItens->fetchAll(PDO::FETCH_ASSOC) as $i) {
                // from_team=1 é quem PROPÔS a troca. Se o proponente foi o time
                // A, o item saiu de A; senão saiu de B.
                $saiuDeA = ((int)$t['from_team_id'] === $aId) === (bool)$i['from_team'];

                if ($i['pick_id'] !== null) {
                    // Pick vai só na contagem: o /confronto quer o tamanho do
                    // negócio, não o inventário de cada escolha.
                    if ($saiuDeA) $out['picks_foram']++; else $out['picks_vieram']++;
                    continue;
                }
                if ($i['player_name'] === null) continue;

                $j = ['nome' => (string)$i['player_name'],
                      'ovr'  => (int)$i['player_ovr'],
                      'idade'=> (int)$i['player_age']];
                if ($saiuDeA) $out['foram'][] = $j; else $out['vieram'][] = $j;
            }
        }

        // Maior OVR primeiro: é quem conta a história da troca.
        $porOvr = fn($x, $y) => $y['ovr'] <=> $x['ovr'];
        usort($out['foram'], $porOvr);
        usort($out['vieram'], $porOvr);
    } catch (Throwable $e) {
        error_log('[whatsapp-cmd] confronto trocas: ' . $e->getMessage());
    }

    try {
        // Pick que nasceu de um e hoje está com o outro.
        $st = $pdo->prepare("SELECT COUNT(*) FROM picks WHERE original_team_id = ? AND team_id = ?");
        $st->execute([$aId, $bId]);
        $out['picks_a_com_b'] = (int)$st->fetchColumn();
        $st->execute([$bId, $aId]);
        $out['picks_b_com_a'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[whatsapp-cmd] confronto picks: ' . $e->getMessage());
    }

    return $out;
}

/** Sigla de três letras pro time. Sai do mascote, que é a parte curta. */
function wcSigla(array $t): string
{
    $base = trim((string)($t['mascot'] ?? '')) ?: trim((string)($t['name'] ?? ''));
    $letras = preg_replace('/[^A-Za-zÀ-ÿ]/u', '', $base);
    return mb_strtoupper(mb_substr($letras, 0, 3)) ?: '???';
}

/** "LeBron James" vira "L. James". Nome de uma palavra só fica inteiro. */
function wcNomeCurto(string $nome): string
{
    $p = preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($p) < 2) return $nome;
    return mb_strtoupper(mb_substr($p[0], 0, 1)) . '. ' . end($p);
}

/**
 * O placar que o palpite arrisca.
 *
 * A diferença de força vira diferença de pontos, e o resto é uma partida de
 * basquete comum. Sem sorteio: o mesmo confronto tem que dar sempre o mesmo
 * placar, senão a mesma pergunta feita duas vezes no grupo se contradiz.
 */
function wcPlacarPrevisto(float $distancia, bool $aFavorito): array
{
    $margem = max(1, min(26, (int)round($distancia * 0.45)));
    $maior = 110 + intdiv($margem + 1, 2);
    $menor = 110 - intdiv($margem, 2);
    return $aFavorito ? [$maior, $menor] : [$menor, $maior];
}
/**
 * Força do time pela mesma conta do power ranking do Mundo FBA: média e teto
 * de OVR do quinteto, com bônus de juventude e castigo de idade.
 *
 * Refiz a fórmula aqui em vez de importar o mundo-fba.php porque lá ela mora
 * dentro da página, junto de meia dúzia de consultas que o bot não precisa.
 * Se a de lá mudar, esta tem que mudar junto — é o preço de não ter a conta
 * num lugar só.
 */
function wcForcaDoTime(array $elenco): float
{
    // Só Titular e Banco. "Outro" e G-League são reserva de contrato, não
    // rotação — deixar eles contarem premiava quem tem elenco inchado, e
    // um time com quinteto igual perdia pra outro por causa de gente que
    // não entra em quadra.
    $elenco = array_values(array_filter($elenco, function ($p) {
        $r = trim((string)($p['role'] ?? ''));
        return $r === 'Titular' || $r === 'Banco';
    }));

    $cinco = array_values(array_filter(wcQuintetoTitular($elenco)));
    if (!$cinco) return 0.0;

    $ovrs   = array_map(fn($p) => (int)$p['ovr'], $cinco);
    $idades = array_filter(array_map(fn($p) => (int)$p['age'], $cinco));

    $mediaOvr   = array_sum($ovrs) / count($ovrs);
    $tetoOvr    = max($ovrs);
    $mediaIdade = $idades ? array_sum($idades) / count($idades) : 0;

    $juventude = $mediaIdade > 0 ? max(0, 30 - $mediaIdade) : 0;
    $castigo   = $mediaIdade > 32 ? ($mediaIdade - 32) * 1.8 : 0;

    // O banco entra: com quinteto montado sozinho, dois times podiam ter os
    // mesmos cinco na frente e elencos completamente diferentes atrás, e a
    // régua dava empate. Pesa menos que os titulares de propósito — banco
    // decide série, não decide quem é favorito.
    //
    // Só os TRÊS melhores de fora do quinteto: rotação de verdade é isso, e
    // somar o elenco inteiro faria o 15º jogador diluir quem realmente joga.
    $idsCinco = array_flip(array_map(fn($p) => (int)$p['id'], $cinco));
    $banco = [];
    foreach ($elenco as $p) {
        if (isset($idsCinco[(int)($p['id'] ?? 0)])) continue;
        $banco[] = (int)($p['ovr'] ?? 0);
    }
    rsort($banco);
    $banco = array_slice($banco, 0, 3);
    $mediaBanco = $banco ? array_sum($banco) / count($banco) : 0;

    $score = ($mediaOvr * 1.6) + ($tetoOvr * 0.6) + ($mediaBanco * 0.5)
           + ($juventude * 0.8) - $castigo;
    if ($tetoOvr >= 89) $score += 2.0;                        // tem franquia
    if (count($cinco) < 5) $score -= (5 - count($cinco)) * 1.2;

    return round($score, 1);
}

/**
 * O palpite. Não existe adivinhação aqui: é regra sobre número.
 *
 * A força do elenco diz quem entra favorito; o retrospecto de playoff diz se
 * a história concorda. Quando os dois discordam é onde mora a zebra, e é esse
 * caso que ganha a frase mais interessante — time melhor no papel que já
 * apanhou do outro no mata-mata é a melhor história que estes dados contam.
 */
function wcPalpite(string $nomeA, string $nomeB, float $fA, float $fB, array $duelo, array $h): string
{
    // Palpite sem escolher lado nao e palpite: nunca sai empate. Quando a
    // forca da igual, quem ja passou pelo outro no playoff leva o voto; se nem
    // isso desempata, fica com o time perguntado primeiro.
    $margem = abs($fA - $fB);
    $aFavorito = $fA > $fB
        || ($fA === $fB && (int)$duelo['a'] >= (int)$duelo['b']);

    $favorito = $aFavorito ? $nomeA : $nomeB;
    $azarao   = $aFavorito ? $nomeB : $nomeA;

    // Retrospecto do ponto de vista do favorito.
    $vitFav = $aFavorito ? (int)$duelo['a'] : (int)$duelo['b'];
    $vitAza = $aFavorito ? (int)$duelo['b'] : (int)$duelo['a'];
    $temHistorico = ($vitFav + $vitAza) > 0;

    if ($margem < 2.5) {
        $veredito = "Dá pra jogar cara ou coroa, mas eu fico com o *{$favorito}*";
    } elseif ($margem < 6) {
        $veredito = "*{$favorito}* leva, mas apertado";
    } elseif ($margem < 12) {
        $veredito = "*{$favorito}* entra favorito";
    } else {
        $veredito = "*{$favorito}* ganha sem sustos";
    }

    // Placar sempre na ordem A x B, igual ao cabeçalho da mensagem: com
    // "moeda no ar" não existe favorito nomeado, e favorito-primeiro deixaria
    // o leitor sem saber de quem é cada número.
    [$ptsA, $ptsB] = wcPlacarPrevisto($margem, $aFavorito);
    $linhas = [$veredito . " _({$ptsA} x {$ptsB})_."];

    if (!$temHistorico) {
        // Sem retrospecto sobra o que passou entre eles fora de quadra —
        // melhor que encerrar com "não sei".
        $linhas[] = $h['trocas']
            ? 'Nunca se cruzaram no mata-mata, mas já negociaram: tem conhecido dos dois lados.'
            : 'Nunca se cruzaram no mata-mata nem trocaram nada. Papel em branco.';
    } elseif ($vitAza > $vitFav) {
        $linhas[] = $margem < 6
            ? "E o retrospecto é do *{$azarao}*: {$vitAza}-{$vitFav} em séries. Num jogo desses, a memória vale ponto."
            : "Só que quem passa é o *{$azarao}*: {$vitAza}-{$vitFav} em séries. Favoritismo no papel nunca ganhou série.";
    } elseif ($vitFav > $vitAza) {
        $linhas[] = ($vitAza === 0 && $vitFav >= 2)
            ? "E tem paternidade: {$vitFav}-0 em séries. Isso pesa antes da bola subir."
            : "E o retrospecto ajuda: {$vitFav}-{$vitAza} em séries.";
    } else {
        $linhas[] = "Retrospecto empatado em {$vitFav}-{$vitAza}. Não ajuda ninguém.";
    }

    return "\n🔮 *Palpite*\n" . implode("\n", $linhas);
}

function wcConfronto(PDO $pdo, string $termo, ?string $ligaDoGrupo = null): string
{
    $partes = preg_split('/\s+(?:x|vs\.?|versus)\s+/iu', $termo, 2);
    if (count($partes) < 2 || trim($partes[0]) === '' || trim($partes[1]) === '') {
        return "Use assim: /confronto lakers x celtics";
    }

    [$a, $erroA] = wcResolverTime($pdo, trim($partes[0]), $ligaDoGrupo);
    if ($erroA) return $erroA;
    [$b, $erroB] = wcResolverTime($pdo, trim($partes[1]), $ligaDoGrupo);
    if ($erroB) return $erroB;
    if ((int)$a['id'] === (int)$b['id']) return 'Os dois nomes acharam o mesmo time (' . wcNomeDoTime($a) . ').';

    $nomeA = wcNomeDoTime($a); $nomeB = wcNomeDoTime($b);
    $h = wcHistoricoEntre($pdo, $a, $b);

    $txt = "⚔️ *{$nomeA}*\n_contra_\n*{$nomeB}*\n";

    // ── Campanha e playoffs, lado a lado ─────────────────────────────────
    $marca = fn($x, $y) => $x === $y ? '' : ($x > $y ? ' ✅' : '');
    $linha = fn(string $r, $va, $vb, string $sa = '', string $sb = '') => "{$r}: {$va}{$sa}  |  {$vb}{$sb}\n";

    $tA = wcTitulosNaEdicao($pdo, (int)$a['id'], (string)$a['league']);
    $tB = wcTitulosNaEdicao($pdo, (int)$b['id'], (string)$b['league']);
    $spA = wcSprintAtual($pdo, (string)$a['league']);
    $spB = wcSprintAtual($pdo, (string)$b['league']);
    $pA = wcPlayoffsNaEdicao($pdo, (int)$a['id'], $spA ? (int)$spA['id'] : null);
    $pB = wcPlayoffsNaEdicao($pdo, (int)$b['id'], $spB ? (int)$spB['id'] : null);

    $txt .= "\n🏆 *Na edição atual*\n"
          . $linha('Títulos', $tA['titulos'], $tB['titulos'], $marca($tA['titulos'], $tB['titulos']), $marca($tB['titulos'], $tA['titulos']))
          . $linha('Séries de playoff', $pA['series'], $pB['series'], $marca($pA['series'], $pB['series']), $marca($pB['series'], $pA['series']))
          . $linha('Melhor campanha', $pA['melhor'] ?: 'não foi', $pB['melhor'] ?: 'não foi');

    // ── Mano a mano: vaga por vaga do quinteto ──────────────────────────
    //
    // O quinteto é o mesmo que o /time mostra (wcQuintetoTitular), então o
    // bot não inventa uma escalação diferente da que o GM vê no comando dele.
    //
    // Os OVRs ficam colados no separador — lado A com o número depois do
    // nome, lado B com o número antes — porque a comparação é entre eles, e
    // ler dois números grudados é mais rápido que caçá-los nas pontas.
    $ovrCol = wcColunaOvr($pdo);
    $elencoDe = function (int $id) use ($pdo, $ovrCol): array {
        $st = $pdo->prepare("SELECT name, position, secondary_position, role, age, {$ovrCol} AS ovr
                             FROM players WHERE team_id = ? ORDER BY {$ovrCol} DESC");
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $elencoA = $elencoDe((int)$a['id']);
    $elencoB = $elencoDe((int)$b['id']);
    $qA = wcQuintetoTitular($elencoA);
    $qB = wcQuintetoTitular($elencoB);

    $txt .= "\n🆚 *Mano a mano*\n";
    $vitA = 0; $vitB = 0;
    foreach ($qA as $vaga => $pa) {
        $pb = $qB[$vaga] ?? null;
        if (!$pa && !$pb) continue;

        $oa = $pa ? (int)$pa['ovr'] : null;
        $ob = $pb ? (int)$pb['ovr'] : null;
        if ($oa !== null && $ob !== null) {
            if ($oa > $ob) $vitA++;
            elseif ($ob > $oa) $vitB++;
        }
        // Quem leva a vaga sai em negrito. Empate não marca ninguém.
        $ladoA = $pa ? wcNomeCurto((string)$pa['name']) . " {$oa}" : '_vazio_';
        $ladoB = $pb ? "{$ob} " . wcNomeCurto((string)$pb['name']) : '_vazio_';
        if ($oa !== null && $ob !== null) {
            if ($oa > $ob)      $ladoA = "*{$ladoA}*";
            elseif ($ob > $oa)  $ladoB = "*{$ladoB}*";
        }

        $txt .= "{$vaga}: {$ladoA}  |  {$ladoB}\n";
    }
    if ($vitA || $vitB) {
        $txt .= $vitA === $vitB
            ? "_Empate no quinteto: {$vitA} a {$vitB}._\n"
            : '_Quinteto: ' . max($vitA, $vitB) . ' a ' . min($vitA, $vitB)
              . ' pro ' . ($vitA > $vitB ? $nomeA : $nomeB) . "._\n";
    }

    /* ── Jogo da semana entre os dois ────────────────────────────────────
       Os dois pagaram FBA Points pra aquele jogo ser transmitido, e isso
       vira retrospecto: quem comprou o duelo mais vezes, e quem ganhou.

       Entra ANTES do playoff de propósito. O playoff é raro — muitos pares
       nunca se cruzaram numa série — e o jogo da semana acontece toda
       semana; entre dois times quaisquer, é o histórico que costuma
       existir. */
    require_once __DIR__ . '/../backend/leilao_semana.php';
    $jds = leilaoSemanaEntre($pdo, (int)$a['id'], (int)$b['id']);
    if ($jds['jogos']) {
        $n = count($jds['jogos']);
        $txt .= "\n📺 *Jogo da semana*\n"
              . $n . ($n === 1 ? ' vez' : ' vezes') . " na tela\n";

        if ($jds['vitorias_a'] || $jds['vitorias_b']) {
            $va = $jds['vitorias_a']; $vb = $jds['vitorias_b'];
            $txt .= $va === $vb
                ? "Empatados: {$va} a {$vb}\n"
                : '*' . ($va > $vb ? $nomeA : $nomeB) . '* leva: '
                  . max($va, $vb) . ' a ' . min($va, $vb) . "\n";
        }
        // Jogo sem resultado informado não é jogo empatado: dizer quantos
        // são evita ler o placar acima como se fosse a conta toda.
        if ($jds['sem_resultado']) {
            $s = $jds['sem_resultado'];
            $txt .= '_' . $s . ($s === 1 ? ' sem resultado informado' : ' sem resultado informado') . "._\n";
        }
    }

    // ── Confronto de verdade: séries de playoff entre os dois ────────────
    $duelo = playoffSeriesEntre($pdo, (int)$a['id'], (int)$b['id']);
    if ($duelo['series']) {
        $txt .= "\n🥊 *Já se enfrentaram no playoff*\n"
              . "*{$duelo['a']} x {$duelo['b']}* em séries\n\n";
        $fases = playoffFases();
        foreach (array_slice($duelo['series'], 0, 6) as $s) {
            $venceu = (int)$s['winner_team_id'] === (int)$a['id'] ? $nomeA : $nomeB;
            $placar = playoffPlacarPorJogos((int)$s['jogos']);
            $txt .= '• T' . (int)$s['season_number'] . ' · ' . ($fases[$s['fase']] ?? $s['fase'])
                  . ': *' . $venceu . '* ' . ($placar ? $placar[0] . '-' . $placar[1] : '') . "\n";
        }
        if (count($duelo['series']) > 6) $txt .= '_+' . (count($duelo['series']) - 6) . " séries antigas_\n";
    }

    // ── O núcleo: o que houve entre os dois ──────────────────────────────
    $txt .= "\n🔁 *Negócios entre eles*\n";
    if (!$h['trocas']) {
        $txt .= "_Nunca trocaram nada._\n";
    } else {
        $n = count($h['trocas']);
        $txt .= $n . ' troca' . ($n === 1 ? '' : 's') . " fechada" . ($n === 1 ? '' : 's');
        if (!empty($h['trocas'][0]['updated_at'])) {
            $txt .= ' · última em ' . date('d/m/Y', strtotime((string)$h['trocas'][0]['updated_at']));
        }
        $txt .= "\n";

        // Três nomes por lado. Quem foi trocado em peso já está no topo da
        // lista, e mais que isso vira parede de texto no grupo.
        $lado = function (array $jogadores, int $picks): string {
            $partes = [];
            foreach (array_slice($jogadores, 0, 3) as $j) {
                $partes[] = wcNomeCurto($j['nome'])
                          . ($j['ovr'] ? ' ' . $j['ovr'] : '')
                          . ($j['idade'] ? '|' . $j['idade'] . 'y' : '');
            }
            $resto = count($jogadores) - min(3, count($jogadores));
            // "mais 2" e nao "+2": as partes ja sao unidas por " + ",
            // e o resultado saia "+ +2".
            if ($resto > 0) $partes[] = "mais {$resto}";
            if ($picks > 0)  $partes[] = $picks . ' pick' . ($picks === 1 ? '' : 's');
            return $partes ? implode(' + ', $partes) : 'nada';
        };

        $txt .= wcSigla($a) . ' -> ' . $lado($h['foram'], (int)$h['picks_foram'])
              . '  |  ' . wcSigla($b) . ' -> ' . $lado($h['vieram'], (int)$h['picks_vieram']) . "\n";
    }

    if ($h['picks_a_com_b'] || $h['picks_b_com_a']) {
        $txt .= "\n🎯 *Picks na mão do outro*\n";
        if ($h['picks_a_com_b']) $txt .= "{$nomeB} tem *{$h['picks_a_com_b']}* pick(s) do {$nomeA}\n";
        if ($h['picks_b_com_a']) $txt .= "{$nomeA} tem *{$h['picks_b_com_a']}* pick(s) do {$nomeB}\n";
    }

    // O palpite fecha a mensagem. É o motivo de alguém pedir /confronto: o
    // resto é consulta, isto é conversa. Os elencos já vieram pro mano a
    // mano — buscar de novo seriam duas consultas iguais na mesma resposta.
    $txt .= wcPalpite($nomeA, $nomeB,
                      wcForcaDoTime($elencoA),
                      wcForcaDoTime($elencoB),
                      $duelo, $h);

    return $txt;
}

function wcLendas(PDO $pdo, string $termo, ?string $ligaDoGrupo): string
{
    $liga = $termo !== '' ? wcNormalizarLiga($termo) : $ligaDoGrupo;
    $ovr = wcColunaOvr($pdo);
    $filtro = $liga ? ' AND t.league = ?' : '';

    $st = $pdo->prepare("
        SELECT p.name, p.age, p.position, p.{$ovr} AS ovr, t.city, t.name AS team_name, t.league
        FROM players p JOIN teams t ON t.id = p.team_id
        WHERE COALESCE(p.is_lenda,0) = 1 {$filtro}
        ORDER BY p.{$ovr} DESC LIMIT 25
    ");
    $st->execute($liga ? [$liga] : []);
    $lendas = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$lendas) return 'Nenhuma LENDA marcada' . ($liga ? " na {$liga}" : '') . ' ainda.';

    $txt = '👑 *Lendas' . ($liga ? " — {$liga}" : '') . '* (' . count($lendas) . ")\n\n";
    foreach ($lendas as $l) {
        $txt .= "• *{$l['name']}* — {$l['ovr']} OVR, {$l['age']} anos — " . wcNomeDoTime($l)
              . ($liga ? '' : ' _' . $l['league'] . '_') . "\n";
    }
    return rtrim($txt);
}

/**
 * O Hall da Fama é de GM, não de time — e por isso vinha faltando gente.
 *
 * A consulta daqui filtrava is_active = 1, que na hall_of_fame não quer dizer
 * "registro válido": separa a linha do time atual do GM (com liga e nome de
 * time) das linhas históricas dele (sem liga e sem time, só nome e títulos).
 * Com o filtro, os 24 campeões históricos sumiam — inclusive quem tem mais
 * títulos na FBA inteira. Filtrar por liga junto piorava: linha histórica tem
 * liga nula e nunca casaria.
 *
 * Agora usa getHallOfFameGrouped() de backend/helpers.php, o mesmo que o
 * admin já usa: junta as linhas do mesmo GM e soma os títulos. Uma conta só
 * pra todo o sistema, em vez de duas que discordam.
 *
 * Sem argumento mostra a FBA inteira, mesmo em grupo de liga: título histórico
 * não tem liga, e limitar pela liga do grupo traria de volta o problema exato
 * que este comando estava tendo.
 */
/**
 * Uma linha do Hall: "1. Nome (Time) — 3 títulos".
 */
function wcLinhaHall(int $pos, array $g, int $titulos): string
{
    $nome = trim((string)$g['gm_name']) ?: (string)($g['teams'][0] ?? 'GM sem nome');
    // O time só entra quando o GM ainda está em atividade; nas linhas
    // históricas ele não existe, e inventar um confundiria.
    $time = $g['is_active'] && !empty($g['teams']) ? ' (' . end($g['teams']) . ')' : '';
    return "{$pos}. *{$nome}*{$time} — {$titulos} título" . ($titulos === 1 ? '' : 's') . "\n";
}

/**
 * O Hall de UMA divisão, já ordenado e cortado.
 *
 * Ordena por título, e não pelo weighted_score que vem de getHallOfFameGrouped.
 *
 * O peso serve pro admin, que compara título da ELITE com título da ROOKIE.
 * Aqui ele atrapalha duas vezes: a lista sai fora de ordem pro leitor (10
 * títulos aparecendo antes de 12) e, pior, título histórico tem liga nula,
 * peso 0 — os campeões antigos afundavam pro fim da lista e não entravam no
 * corte. Era o mesmo sumiço, por outro caminho.
 */
function wcHallDaDivisao(array $grupos, string $chave, int $limite): array
{
    $lista = [];
    foreach ($grupos as $g) {
        $n = (int)($g['leagues'][$chave] ?? 0);
        if ($n > 0) $lista[] = [$g, $n];
    }
    usort($lista, fn(array $a, array $b): int =>
        $b[1] <=> $a[1] ?: strcasecmp((string)$a[0]['gm_name'], (string)$b[0]['gm_name']));

    $total = count($lista);
    $txt = '';
    foreach (array_slice($lista, 0, $limite) as $i => [$g, $n]) {
        $txt .= wcLinhaHall($i + 1, $g, $n);
    }
    // Diz quantos ficaram de fora em vez de cortar calado: sem isso a lista
    // parece completa, e quem está no 11º lugar some sem explicação.
    if ($total > $limite) $txt .= '_+' . ($total - $limite) . " fora da lista_\n";
    return [$txt, $total];
}

function wcHall(PDO $pdo, string $termo, ?string $ligaDoGrupo): string
{
    $liga = $termo !== '' ? wcNormalizarLiga($termo) : null;
    if ($termo !== '' && !$liga) return "Liga não reconhecida. Use ELITE, NEXT, RISE ou ROOKIE.";

    // getHallOfFameGrouped lê hof.user_id, coluna que a migração pode não ter
    // criado ainda (ela tem throttle). O admin chama isto antes pelo mesmo
    // motivo — sem a chamada, o comando morre com "Unknown column".
    ensureHallOfFameTable($pdo);
    $grupos = getHallOfFameGrouped($pdo);

    // Uma liga só: a lista dela, mais longa, sem cabeçalho de seção.
    if ($liga) {
        [$corpo] = wcHallDaDivisao($grupos, $liga, 25);
        if ($corpo === '') return "O Hall da Fama da {$liga} está vazio.";
        return rtrim("🏛️ *Hall da Fama — {$liga}*\n\n" . $corpo);
    }

    // Sem argumento: uma seção por divisão, na ordem delas.
    //
    // Era uma lista só, somando os títulos das quatro ligas na mesma linha.
    // Nela um GM com 3 títulos da ROOKIE aparecia à frente de um campeão da
    // ELITE, e não dava pra ler quem manda em cada divisão — que é a pergunta
    // que o grupo faz.
    $secoes = [];
    foreach (WC_DIVISOES as $div) {
        [$corpo] = wcHallDaDivisao($grupos, $div, 10);
        if ($corpo !== '') $secoes[] = "*{$div}*\n" . $corpo;
    }

    // Títulos antigos entraram sem liga (league nulo vira 'N/A' no
    // agrupamento). Sem esta seção eles sumiriam da tela ao dividir por
    // divisão — e são justamente os campeões mais antigos da liga.
    [$hist] = wcHallDaDivisao($grupos, 'N/A', 10);
    if ($hist !== '') $secoes[] = "*Histórico* _(antes das divisões)_\n" . $hist;

    if (!$secoes) return 'O Hall da Fama está vazio.';
    return rtrim("🏛️ *Hall da Fama*\n\n" . implode("\n", $secoes));
}
function wcPremios(PDO $pdo, string $termo, ?string $ligaDoGrupo): string
{
    // O termo pode ser a liga ou um ano ("/premios 2027").
    $ano = preg_match('/^\d{4}$/', trim($termo)) ? (int)trim($termo) : null;
    $liga = $ano ? $ligaDoGrupo : ($termo !== '' ? wcNormalizarLiga($termo) : $ligaDoGrupo);
    if (!$liga) $liga = 'ELITE';

    if (!$ano) {
        $st = $pdo->prepare("SELECT MAX(season_year) FROM awards WHERE league = ?");
        $st->execute([$liga]);
        $ano = (int)($st->fetchColumn() ?: 0);
    }
    if (!$ano) return "A {$liga} ainda não tem prêmios cadastrados.";

    $st = $pdo->prepare("
        SELECT a.award_name, a.player_name, a.points, t.city, t.name AS team_name
        FROM awards a LEFT JOIN teams t ON t.id = a.team_id
        WHERE a.league = ? AND a.season_year = ?
        ORDER BY a.id
    ");
    $st->execute([$liga, $ano]);
    $premios = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$premios) return "Nada premiado na {$liga} em {$ano}.";

    $txt = "🏆 *Prêmios {$liga} — {$ano}*\n\n";
    foreach ($premios as $p) {
        $quem = $p['player_name'] ?: ($p['team_name'] ? wcNomeDoTime($p) : '—');
        $txt .= '*' . $p['award_name'] . ':* ' . $quem
              . ($p['player_name'] && $p['team_name'] ? ' (' . wcNomeDoTime($p) . ')' : '') . "\n";
    }
    return rtrim($txt);
}

// ─────────────────────────────────────────────────────────────────────────
// Comandos que sabem quem perguntou
// ─────────────────────────────────────────────────────────────────────────


// ─────────────────────────────────────────────────────────────────────────

/**
 * Roteia o texto pro comando. Retorna null quando não é comando conhecido —
 * o webhook trata null como "não responder".
 *
 * $ligaDoGrupo é a liga do grupo de onde veio a mensagem, quando ele é de uma
 * liga só. Serve pros comandos que dá pra responder sem argumento.
 */
function wcResponderComando(PDO $pdo, string $texto, ?string $ligaDoGrupo = null,
                            string $deQuem = '', string $grupoJid = ''): ?string
{
    $texto = trim($texto);
    if ($texto === '' || $texto[0] !== '/') return null;

    // Separa "/cap lakers" em "cap" e "lakers".
    $partes = preg_split('/\s+/', substr($texto, 1), 2);
    $cmd = mb_strtolower($partes[0] ?? '');
    $arg = trim($partes[1] ?? '');

    // O WhatsApp deixa mencionar o bot; tirar o @ evita comando engolido.
    $arg = trim(preg_replace('/@\d+/', '', $arg));

    try {
        // Voto do quiz: /1 a /4. Vem antes do switch porque o comando é o
        // próprio número, e devolve string VAZIA — atendido sem resposta.
        //
        // O silêncio não é economia de texto: são doze pessoas votando contra
        // um freio de doze comandos por minuto. Se cada voto tivesse resposta,
        // o quiz derrubaria o bot no meio da própria rodada.
        if (preg_match('/^[1-4]$/', $cmd) && $grupoJid !== '' && $deQuem !== '') {
            require_once __DIR__ . '/../backend/quiz.php';
            quizVotar($pdo, $grupoJid, $deQuem, (int)$cmd);
            return '';
        }

        switch ($cmd) {
            case 'ajuda':
            case 'comandos':
            case 'help':
                return wcAjuda();

            // As estatísticas da liga. O catálogo mora em
            // backend/estatisticas_bot.php, e é ele que diz quais comandos
            // existem — não uma segunda lista aqui.
            case 'estatisticas':
            case 'estatísticas':
            case 'stats':
                require_once __DIR__ . '/../backend/estatisticas_bot.php';
                return ebListar($ligaDoGrupo);

            case 'apostas':
            case 'aposta':
            case 'palpites':
                require_once __DIR__ . '/../backend/apostas.php';
                return apostasTextoParcial($pdo);

            case 'jogador':
            case 'player':
                return wcJogador($pdo, $arg, $ligaDoGrupo);

            case 'time':
            case 'elenco':
                return wcTime($pdo, $arg, null, $ligaDoGrupo);

            case 'cap':
            case 'folha':
                return wcCap($pdo, $arg, null, $ligaDoGrupo);

            case 'picks':
            case 'pick':
                return wcPicks($pdo, $arg, null, $ligaDoGrupo);

            case 'ranking':
            case 'classificacao':
            case 'classificação':
            case 'tabela':
                return wcClassificacao($pdo, $arg, $ligaDoGrupo);

            case 'playoffs':
            case 'playoff':
            case 'offs':
            case 'chaveamento':
                return wcPlayoffs($pdo, $arg, $ligaDoGrupo);

            case 'power':
            case 'powerranking':
                return wcPowerRanking($pdo, $arg, $ligaDoGrupo);

            case 'powerc':
            case 'powerconf':
            case 'powerconferencia':
                return wcPowerRankingConferencia($pdo, $arg, $ligaDoGrupo);

            case 'aceitar':
            case 'recusar':
                return wcDecidirPropostaDeLeilao($pdo, $cmd, $arg, $deQuem);

            case 'confronto':
            case 'duelo':
                return wcConfronto($pdo, $arg, $ligaDoGrupo);

            case 'comparartime':
            case 'comparartimes':
            case 'compararelenco':
                return wcCompararTimes($pdo, $arg, $ligaDoGrupo);

            case 'comparar':
            case 'compara':
            case 'vs':
                return wcComparar($pdo, $arg, $ligaDoGrupo);

            // /trades e /trocas sao a MESMA porta: o feed das ultimas
            // trocas. Quem quer o ranking digita o sufixo — /tradesaceitas.
            // Sem o 'trades' aqui, a mesma palavra em ingles caia no
            // catalogo de estatisticas e trazia coisa diferente do /trocas.
            case 'trocas':
            case 'troca':
            case 'trades':
            case 'trade':
                return wcTrocas($pdo, $arg, $ligaDoGrupo);

            // ── A escala das lives ─────────────────────────────────────
            // Uma função, um comando. Poderia ser um só com argumento
            // (/escala comentarista), mas no grupo o que a pessoa lê é a
            // lista de comandos — e comando que se copia e manda erra
            // menos que comando que se digita com complemento.
            case 'comentarista':
            case 'narrador':
            case 'operacional':
            case 'transmissao':
            case 'transmissão':
                return wcEscalaTopar($pdo, $cmd === 'transmissão' ? 'transmissao' : $cmd,
                                     $arg, $deQuem, $ligaDoGrupo);

            case 'sair':
                return wcEscalaSair($pdo, $arg, $deQuem, $ligaDoGrupo);

            case 'verescala':
                return wcEscalaVer($pdo, $arg, $deQuem, $ligaDoGrupo);

            case 'escala':
                return wcEscalaChamar($pdo, $arg, $deQuem, $ligaDoGrupo);

            // O leilão do jogo da semana. Cada grupo tem a sua liga, então
            // sem argumento responde a liga DAQUELE grupo — quem pergunta no
            // Chat Off da NEXT quer o jogo da NEXT, não o da ELITE.
            case 'jogosemana':
            case 'jogodasemana':
                require_once __DIR__ . '/../backend/leilao_semana.php';
                $lg = trim($arg) !== '' ? wcNormalizarLiga(trim($arg)) : null;
                if (!$lg) $lg = strtoupper((string)($ligaDoGrupo ?? '')) ?: 'ELITE';
                return leilaoSemanaTexto($pdo, $lg);

            case 'apostasresultado':
            case 'apostasresultados':
            case 'resultadoapostas':
                require_once __DIR__ . '/../backend/apostas.php';
                return apostasTextoResultados($pdo, 10);

            // A Copa do Mundo do Games. Sem argumento mostra a copa em
            // andamento; com um número, aquela copa — pra conferir uma
            // antiga sem ter que abrir o site.
            case 'vercopa':
            case 'copa':
                require_once __DIR__ . '/../games/core/copa_motor.php';
                return copaTextoAgora($pdo, ctype_digit(trim($arg)) ? (int)trim($arg) : null);

            // O manual, pra fixar no grupo. Separado do /escala de propósito:
            // a chamada é do momento e some na rolagem; este responde sempre
            // igual, e é o que quem chega depois precisa achar.
            case 'live':
            case 'lives':
                require_once __DIR__ . '/../backend/escala_live.php';
                return escalaTextoAjuda();

            // Os "meus": respondem sobre o time de quem digitou, sem precisar
            // dizer o nome. Dependem do telefone estar certo no cadastro — foi
            // por isso que saíram do ar em agosto e voltaram só depois de os
            // números serem padronizados.
            case 'meutime':
            case 'meuelenco':
                return wcMeuElenco($pdo, $deQuem, $ligaDoGrupo);

            case 'meucap':
            case 'minhafolha':
                return wcMeuCap($pdo, $deQuem, $ligaDoGrupo);

            case 'meutblock':
            case 'meutradeblock':
                return wcMeuTradeBlock($pdo, $deQuem, $ligaDoGrupo);

            case 'tblock':
            case 'tradeblock':
                return wcTradeBlockDeTime($pdo, $arg, $ligaDoGrupo);

            case 'minhaspicks':
            case 'meuspicks':
                return wcMinhasPicks($pdo, $deQuem, $ligaDoGrupo);

            case 'minhastrades':
            case 'minhastrocas':
            case 'meustrades':
                return wcMinhasTrades($pdo, $deQuem, $ligaDoGrupo);

            case 'lendas':
            case 'lenda':
                return wcLendas($pdo, $arg, $ligaDoGrupo);

            case 'hall':
            case 'halldafama':
                return wcHall($pdo, $arg, $ligaDoGrupo);

            case 'premios':
            case 'prêmios':
            case 'premio':
                return wcPremios($pdo, $arg, $ligaDoGrupo);

            case 'guia':
                return "*Guia do GM:* https://fbabrasil.com.br/guia.php";

            // Só admin, e só dentro do grupo que vai receber o quiz.
            case 'quizaqui':
                return wcQuizAqui($pdo, $deQuem, $grupoJid);

            default:
                // Um comando por estatística (/playoffs, /4a0, /rivalidades…).
                // Não estão listados um por um de propósito: quem sabe quais
                // existem é o catálogo em backend/estatisticas_bot.php, e
                // repetir os nomes aqui seria uma segunda lista pra manter.
                require_once __DIR__ . '/../backend/estatisticas_bot.php';
                $est = ebResponder($pdo, $cmd, $ligaDoGrupo);
                if ($est !== null) return $est;

                return null;
        }
    } catch (Throwable $e) {
        // Erro meu não pode virar stack trace no grupo.
        error_log('[whatsapp-cmd] ' . $cmd . ': ' . $e->getMessage());
        return 'Deu erro aqui ao buscar isso. Avisa o admin.';
    }
}
