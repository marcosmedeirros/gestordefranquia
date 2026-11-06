<?php
/**
 * ATUALIZAR O ELENCO PELO WHATSAPP, COM UMA FOTO.
 *
 * O GM manda o print do jogo com a legenda
 *
 *     /atualizarelenco letras      a tela "View List › Simple" (letras, idade e OVR)
 *     /atualizarelenco stats       a aba "Per Game" (jogos, minutos, pontos...)
 *
 * e o bot responde com o que ENTENDEU — jogador por jogador, só o que muda — e espera um /ok
 * pra gravar (ou /cancelar). É o "Atualizar Elenco" do site (atualizar-elenco.php) levado pro
 * zap, com as MESMAS regras: a mesma leitura por IA (Gemini), as mesmas faixas de OVR/idade/
 * estatística, o mesmo balde de leituras por time e por mês, a mesma gravação (letras + o
 * snapshot da temporada; estatísticas na temporada-alvo) e o mesmo carimbo de "elenco
 * atualizado" pro painel do admin.
 *
 * ── POR QUE TEM CONFIRMAÇÃO ───────────────────────────────────────────
 *
 * O site mostra o que a IA leu numa tabela e só grava no botão Salvar, porque "o
 * reconhecimento erra, principalmente em OVR e idade". No zap não há tabela pra conferir; o
 * preview faz esse papel, e o /ok é o botão Salvar. Salto suspeito (OVR que muda mais de 6, idade
 * que não é a de antes ou a de antes +1) sai marcado com ⚠️.
 *
 * ── DE ONDE VEM A FOTO ────────────────────────────────────────────────
 *
 * A Evolution (que baixa a mídia) roda na VPS e o site na Hostinger não chega nela. Quem baixa é o
 * relay do webhook (bot/relay/enviar.php), que cola o arquivo no evento em `fba_midia`
 * ({base64, mimetype} ou {erro}). Sem isso o comando responde que não recebeu a foto.
 *
 * ── QUEM PODE ─────────────────────────────────────────────────────────
 *
 * O GM atualiza o PRÓPRIO time, achado pelo telefone do cadastro. Admin da liga pode apontar
 * outro time da liga dele (`/atualizarelenco letras Paisley`).
 */

require_once __DIR__ . '/ocr_skills.php';          // também traz o ocr_stats.php
require_once __DIR__ . '/edital_ia.php';           // a chave do Gemini
require_once __DIR__ . '/atualizacoes.php';        // as faixas e as notas válidas
require_once __DIR__ . '/stats_temporada.php';
require_once __DIR__ . '/snapshot_temporada.php';
require_once __DIR__ . '/helpers.php';

/** Mesmo limite de leituras por time e por temporada do site (api/vision_skills.php). */
const EF_LIMITE_TEMPORADA = 4;
/** Mesmo freio mensal da liga inteira (VISION_MONTHLY_CAP). */
const EF_LIMITE_MENSAL = 2100;
/** Quem não tem limite de leitura (os mesmos e-mails do site). */
const EF_SEM_LIMITE = ['medeirros99@gmail.com'];
/* SÓ A ELITE (pedido do Marcos, 06/11/2026). A tela do site continua liberada pras quatro ligas
   (VISION_LEAGUES); só o comando do zap é restrito. Valem pro TIME alvo, então vale também
   pro admin: ele não aponta um time de NEXT/RISE/ROOKIE por aqui. Pra liberar outra liga, é só
   acrescentar nesta lista. */
const EF_LIGAS = ['ELITE'];
/** Quanto tempo o /ok vale depois do preview. */
const EF_VALIDADE_MIN = 20;
/** Foto maior que isto não entra (o WhatsApp comprime; passar de 8 MB é outra coisa). */
const EF_IMAGEM_MAX = 8 * 1024 * 1024;
/** Mudança de OVR maior que isto, ou idade que não é a de antes (ou +1), sai com ⚠️ no preview. */
const EF_OVR_SALTO = 6;

/** Coluna do banco => chave que o players.player_skill_grades (JSON) usa. */
const EF_SKILL_JSON = ['skill_in' => 'in', 'skill_mid' => 'mid', 'skill_3pt' => 'pt3', 'skill_post_d' => 'post_d', 'skill_per_d' => 'per_d',
                       'skill_play' => 'play', 'skill_reb' => 'reb', 'skill_athl' => 'athl', 'skill_iq' => 'iq', 'skill_pot' => 'pot'];
/** O que o Gemini devolve (ocrSkillsGemini) => coluna do banco. */
const EF_COLUNA_OCR = ['IN' => 'skill_in', 'MID' => 'skill_mid', '3PT' => 'skill_3pt', 'POST' => 'skill_post_d', 'PER' => 'skill_per_d',
                       'PLAY' => 'skill_play', 'REB' => 'skill_reb', 'ATHL' => 'skill_athl', 'IQ' => 'skill_iq', 'POT' => 'skill_pot'];
/** Rótulo curto de cada letra no preview. */
const EF_ROTULO = ['skill_in' => 'IN', 'skill_mid' => 'MID', 'skill_3pt' => '3PT', 'skill_post_d' => 'POST D', 'skill_per_d' => 'PER D',
                   'skill_play' => 'PLAY', 'skill_reb' => 'REB', 'skill_athl' => 'ATHL', 'skill_iq' => 'IQ', 'skill_pot' => 'POT'];

function efTabela(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS elenco_foto_pendente (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        team_id    INT NOT NULL,
        user_id    INT NULL,
        quem       VARCHAR(32) NOT NULL,
        tipo       VARCHAR(10) NOT NULL,
        season_id  INT NULL,
        payload    MEDIUMTEXT NOT NULL,
        criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        situacao   VARCHAR(12) NOT NULL DEFAULT 'aberto',
        INDEX idx_quem (quem, situacao),
        INDEX idx_team (team_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = true;
}

/** Só os dígitos do telefone de quem mandou ('' se for LID, que não é telefone). */
function efQuem(string $deQuem): string
{
    if ($deQuem === '' || str_contains($deQuem, '@lid')) return '';
    $d = preg_replace('/\D+/', '', explode('@', $deQuem)[0] ?? '');
    return strlen($d) >= 8 ? $d : '';
}

/** Nome sem acento, sem pontuação, minúsculo — pra casar "J. White" com "Jaden White" sem tropeçar em til. */
function efChave(string $s): string
{
    static $mapa = null;
    $mapa ??= ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
               'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','š'=>'s','ž'=>'z','č'=>'c','ć'=>'c','ā'=>'a','ē'=>'e','ī'=>'i','ō'=>'o','ū'=>'u'];
    $n = strtr(mb_strtolower(trim($s)), $mapa);
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', $n) ?? '') ?? '');
}

/**
 * Casa cada nome lido no print com um jogador do elenco.
 *
 * Mesma ideia do site (nome igual; senão mesmo sobrenome, com bônus se a inicial bate; senão
 * semelhança do texto, a partir de 60%), com duas diferenças que importam sem tela pra conferir:
 * o nome é comparado sem acento nem pontuação, e DOIS nomes lidos nunca caem no mesmo jogador — fica o
 * de maior nota e o outro vai pra lista dos "não reconhecidos".
 *
 * @param array<int,array> $lidos  cada um com 'name'
 * @param array<int,array> $elenco cada um com 'id' e 'name'
 * @return array{0:array<int,array>,1:array<int,array>} [casados (cada um com player_id, nota), sem par]
 */
function efCasar(array $lidos, array $elenco): array
{
    $cand = [];   // player_id => [nota, índice do lido]
    $semPar = [];
    foreach ($lidos as $i => $l) {
        $det = efChave((string)$l['name']);
        $melhor = 0; $jog = null;
        foreach ($elenco as $rp) {
            $ros = efChave((string)$rp['name']);
            if ($det !== '' && $det === $ros) { $melhor = 100; $jog = $rp; break; }
            $dp = explode(' ', $det); $rp2 = explode(' ', $ros);
            $nota = 0;
            if ($dp && $rp2 && end($dp) === end($rp2) && strlen(end($dp)) >= 2) {
                $nota = 80;
                if (($dp[0][0] ?? '') !== '' && ($dp[0][0] ?? '') === ($rp2[0][0] ?? '')) $nota = 95;
            }
            similar_text($det, $ros, $pct);
            if ($pct > $nota) $nota = $pct;
            if ($nota > $melhor) { $melhor = $nota; $jog = $rp; }
        }
        if (!$jog || $melhor < 60) { $semPar[] = $l; continue; }
        $pid = (int)$jog['id'];
        if (!isset($cand[$pid]) || $melhor > $cand[$pid][0]) {
            if (isset($cand[$pid])) $semPar[] = $lidos[$cand[$pid][1]];
            $cand[$pid] = [$melhor, $i];
        } else {
            $semPar[] = $l;
        }
    }
    $casados = [];
    foreach ($cand as $pid => [$nota, $i]) $casados[] = $lidos[$i] + ['player_id' => $pid, 'nota_nome' => $nota];
    return [$casados, $semPar];
}

/* ── Time e cotas ──────────────────────────────────────────────────────── */

/**
 * Qual time é o desta atualização — o do próprio GM (pelo telefone) ou, sendo admin, o que ele apontou.
 *
 * @return array{0:?array,1:?string,2:?array} [time, erro, usuário (id, email)]
 */
function efResolverTime(PDO $pdo, string $deQuem, string $argTime, ?string $ligaDoGrupo): array
{
    $quem = efQuem($deQuem);
    if ($quem === '') return [null, 'Não consegui identificar o seu número aqui (o WhatsApp mandou só um identificador interno). Tenta pelo privado do bot.', null];

    $meus = wcAcharPeloTelefone(wcGmsComTelefone($pdo), $quem);
    $u = null;
    $st = $pdo->query("SELECT id, email, phone FROM users WHERE phone IS NOT NULL AND phone <> ''");
    $fim = substr($quem, -8);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $d = preg_replace('/\D+/', '', (string)$x['phone']);
        if ($d === $quem || (strlen($d) >= 8 && substr($d, -8) === $fim)) { $u = $x; break; }
    }
    if (!$u) return [null, 'Não achei o seu cadastro pelo telefone. Confere o número no seu perfil do site.', null];

    if ($argTime !== '') {
        [$t, $erro] = wcResolverTime($pdo, $argTime, $ligaDoGrupo);
        if (!$t) return [null, $erro, $u];
        foreach ($meus as $m) if ((int)$m['id'] === (int)$t['id']) return [$t, null, $u];
        require_once __DIR__ . '/bot_admin.php';
        if (in_array(strtoupper((string)$t['league']), botAdminLigasDe($pdo, $deQuem), true)) return [$t, null, $u];
        return [null, 'Você só pode atualizar o *seu* time por aqui.', $u];
    }

    if (!$meus) return [null, 'Não achei um time no seu cadastro. Se você é admin, diga o time: /atualizarelenco letras _time_', $u];
    if (count($meus) > 1) {
        $da = $ligaDoGrupo ? array_values(array_filter($meus, fn($t) => strtoupper((string)$t['league']) === strtoupper($ligaDoGrupo))) : [];
        if (count($da) === 1) return [$da[0], null, $u];
        return [null, 'Você tem time em mais de uma liga. Diga qual: /atualizarelenco letras _time_', $u];
    }
    return [$meus[0], null, $u];
}

/** A temporada em andamento da liga (a mesma de onde sai a cota e o snapshot). */
function efTemporadaAtiva(PDO $pdo, string $liga): ?array
{
    $st = $pdo->prepare("SELECT id, season_number, year FROM seasons
                          WHERE league = ? AND (status IS NULL OR status <> 'completed')
                          ORDER BY id DESC LIMIT 1");
    $st->execute([$liga]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * A cota de leituras do time, ou o motivo de recusar. O balde é o MESMO do site (vision_skill_usage),
 * então foto pelo zap e foto pelo site gastam das mesmas 4 leituras.
 *
 * @return array{0:?string,1:int,2:?int} [motivo da recusa, usadas, season_id da cota]
 */
function efCota(PDO $pdo, array $time, ?array $usuario): array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS vision_skill_usage (
        id INT AUTO_INCREMENT PRIMARY KEY, team_id INT NOT NULL, season_id INT, count INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_team_season (team_id, season_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vision_monthly_usage (ym CHAR(7) NOT NULL PRIMARY KEY, count INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $pdo->prepare("SELECT id FROM seasons WHERE league = ? AND status <> 'completed' ORDER BY id DESC LIMIT 1");
    $st->execute([$time['league']]);
    $seasonId = $st->fetchColumn();
    $seasonId = $seasonId !== false && $seasonId !== null ? (int)$seasonId : null;

    $st = $pdo->prepare('SELECT count FROM vision_skill_usage WHERE team_id = ? AND season_id <=> ?');
    $st->execute([(int)$time['id'], $seasonId]);
    $usadas = (int)($st->fetchColumn() ?: 0);
    $livre = $usuario && in_array(strtolower((string)($usuario['email'] ?? '')), EF_SEM_LIMITE, true);
    if (!$livre && $usadas >= EF_LIMITE_TEMPORADA) {
        return ['Esse time já usou as ' . EF_LIMITE_TEMPORADA . ' leituras por foto desta temporada. Atualiza pelo site (atualizar-elenco) ou espera a próxima.', $usadas, $seasonId];
    }
    $st = $pdo->prepare('SELECT count FROM vision_monthly_usage WHERE ym = ?');
    $st->execute([date('Y-m')]);
    if ((int)($st->fetchColumn() ?: 0) >= EF_LIMITE_MENSAL) {
        return ['A liga chegou no limite mensal de leituras por foto. Atualiza pelo site ou tenta no mês que vem.', $usadas, $seasonId];
    }
    return [null, $usadas, $seasonId];
}

/** Conta a leitura assim que ela acontece (inclusive a que falha: imagem ruim repetida não fura o freio). */
function efGastarLeitura(PDO $pdo, int $teamId, ?int $seasonId): int
{
    $pdo->prepare('INSERT INTO vision_skill_usage (team_id, season_id, count) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE count = count + 1')->execute([$teamId, $seasonId]);
    $pdo->prepare('INSERT INTO vision_monthly_usage (ym, count) VALUES (?, 1) ON DUPLICATE KEY UPDATE count = count + 1')->execute([date('Y-m')]);
    $st = $pdo->prepare('SELECT count FROM vision_skill_usage WHERE team_id = ? AND season_id <=> ?');
    $st->execute([$teamId, $seasonId]);
    return (int)($st->fetchColumn() ?: 0);
}

/* ── Pedir (a foto chegou) ─────────────────────────────────────────────── */

const EF_USO = "📸 *Atualizar o elenco por foto*\n\nManda o print do jogo com a legenda:\n"
             . "• */atualizarelenco letras* — tela _View List › Simple_ (letras, idade e OVR)\n"
             . "• */atualizarelenco stats* — aba _Per Game_ (jogos, min, pts, reb, ast, stl, blk, FG%)\n\n"
             . "Eu mostro o que entendi e só gravo depois do */ok*.";

/**
 * O comando /atualizarelenco.
 *
 * @param ?array $midia {base64, mimetype} ou {erro}, colado no evento pelo relay; null = veio sem foto
 */
function efPedir(PDO $pdo, string $arg, string $deQuem, ?string $ligaDoGrupo, ?array $midia): string
{
    efTabela($pdo);
    $partes = preg_split('/\s+/', trim($arg), 2);
    $palavra = efChave($partes[0] ?? '');
    $tipo = match ($palavra) {
        'letras', 'letra', 'skills', 'skill', 'atributos', 'atributo', 'letrinhas' => 'letras',
        'stats', 'stat', 'estatisticas', 'estatistica' => 'stats',
        default => '',
    };
    if ($tipo === '') return EF_USO;
    $argTime = trim((string)($partes[1] ?? ''));

    if (!$midia) return "Não recebi a foto. Manda o *print* com a legenda */atualizarelenco {$tipo}* (a foto e o comando na mesma mensagem).";
    if (!empty($midia['erro'])) {
        error_log('[elenco-foto] relay não baixou a foto: ' . $midia['erro']);
        return "Não consegui baixar a foto agora. Manda de novo daqui a pouco.";
    }
    $b64 = (string)($midia['base64'] ?? '');
    if ($b64 === '') return 'A foto chegou vazia. Manda de novo.';
    if (strlen($b64) > EF_IMAGEM_MAX * 1.4) return 'A foto é grande demais. Manda um print mais leve.';
    $mime = (string)($midia['mimetype'] ?? 'image/jpeg');
    if (!str_starts_with($mime, 'image/')) $mime = 'image/jpeg';

    [$time, $erro, $usuario] = efResolverTime($pdo, $deQuem, $argTime, $ligaDoGrupo);
    if (!$time) return (string)$erro;
    if (!in_array(strtoupper((string)$time['league']), EF_LIGAS, true)) return 'A atualização por foto no WhatsApp está liberada só pra *' . implode(', ', EF_LIGAS) . "*. Atualiza o elenco pelo site (atualizar-elenco).";
    $nomeTime = wcNomeDoTime($time);

    [$recusa, $usadas, $seasonCota] = efCota($pdo, $time, $usuario);
    if ($recusa) return '⛔ ' . $recusa;

    $leitor = $GLOBALS['ef_leitor'] ?? null;   // gancho dos testes: troca o Gemini
    $chave = function_exists('editalIaChaveGemini') ? editalIaChaveGemini() : '';
    if ($chave === '' && !$leitor) return 'O leitor de imagem não está configurado no servidor. Avisa o admin.';

    $elenco = $pdo->prepare('SELECT id, name, ovr, age, skill_in, skill_mid, skill_3pt, skill_post_d, skill_per_d, skill_play, skill_reb, skill_athl, skill_iq, skill_pot
                               FROM players WHERE team_id = ? ORDER BY name');
    $elenco->execute([(int)$time['id']]);
    $elenco = $elenco->fetchAll(PDO::FETCH_ASSOC);
    if (!$elenco) return "{$nomeTime} não tem jogador cadastrado pra atualizar.";

    // A leitura leva de 16 a 72 s (o webhook já respondeu à Evolution; isto roda solto).
    $erroLeitura = null;
    $lidos = $leitor ? $leitor($tipo, $b64, $mime, $erroLeitura) : ($tipo === 'letras'
        ? ocrSkillsGemini($b64, $mime, $chave, $erroLeitura) : ocrStatsGemini($b64, $mime, $chave, $erroLeitura));

    // O banco desta hospedagem derruba a conexão parada: depois de uma espera dessas, reabre.
    require_once __DIR__ . '/db.php';
    $pdo = dbRevive($pdo);
    efTabela($pdo);
    $usadas = efGastarLeitura($pdo, (int)$time['id'], $seasonCota);

    if ($lidos === null) {
        error_log('[elenco-foto] nenhum leitor leu: ' . $erroLeitura);
        return "Não consegui ler a imagem. Confere se é " . ($tipo === 'letras' ? 'a tela *View List › Simple*' : 'a aba *Per Game*') . " e se o print está nítido.\n_Leituras usadas: {$usadas}/" . EF_LIMITE_TEMPORADA . '._';
    }
    [$casados, $semPar] = efCasar($lidos, $elenco);
    if (!$casados) {
        return "Li a imagem, mas nenhum jogador bateu com o elenco do *{$nomeTime}*. O print é do time certo?\n_Leituras usadas: {$usadas}/" . EF_LIMITE_TEMPORADA . '._';
    }

    $dados = $tipo === 'letras' ? efMontarLetras($casados, $elenco) : efMontarStats($pdo, $casados, $elenco, (string)$time['league']);
    if (isset($dados['erro'])) return $dados['erro'];
    if (!$dados['mudancas']) {
        return "✅ Li {$dados['lidos']} jogador(es) do *{$nomeTime}* e *nada mudou* em relação ao que já está no site.\n_Leituras usadas: {$usadas}/" . EF_LIMITE_TEMPORADA . '._';
    }

    // Um pedido em aberto por pessoa: o novo substitui o anterior.
    $quemDig = efQuem($deQuem);
    $pdo->prepare("UPDATE elenco_foto_pendente SET situacao = 'trocado' WHERE quem = ? AND situacao = 'aberto'")->execute([$quemDig]);
    $payload = ['mudancas' => $dados['mudancas'], 'alvo' => $dados['alvo'] ?? null, 'time' => $nomeTime, 'liga' => (string)$time['league']];
    $pdo->prepare('INSERT INTO elenco_foto_pendente (team_id, user_id, quem, tipo, season_id, payload) VALUES (?,?,?,?,?,?)')
        ->execute([(int)$time['id'], $usuario['id'] ?? null, $quemDig, $tipo, $dados['alvo']['id'] ?? null, json_encode($payload, JSON_UNESCAPED_UNICODE)]);

    return efTextoPreview($tipo, $nomeTime, $dados, $semPar, $usadas);
}

/** Letras, idade e OVR: só o que muda, jogador por jogador. */
function efMontarLetras(array $casados, array $elenco): array
{
    $porId = [];
    foreach ($elenco as $p) $porId[(int)$p['id']] = $p;
    $mud = []; $iguais = 0; $suspeitos = 0;
    foreach ($casados as $c) {
        $p = $porId[(int)$c['player_id']];
        $novo = []; $aviso = [];
        foreach ((array)($c['grades'] ?? []) as $col => $nota) {
            $coluna = EF_COLUNA_OCR[$col] ?? null;
            if (!$coluna) continue;
            [$ok, $v] = atualizacaoValidarSkills([$coluna => $nota]);
            if (!$ok || !isset($v[$coluna])) continue;
            if (strtoupper((string)($p[$coluna] ?? '')) !== $v[$coluna]) $novo[$coluna] = $v[$coluna];
        }
        $ficha = [];
        foreach (['ovr' => $c['rating'] ?? null, 'age' => $c['age'] ?? null] as $col => $val) {
            if ($val === null) continue;
            [$ok, $v] = atualizacaoValidarFicha([$col => (string)$val]);
            if ($ok && isset($v[$col]) && (int)$p[$col] !== $v[$col]) $ficha[$col] = $v[$col];
        }
        if (isset($ficha['ovr']) && abs($ficha['ovr'] - (int)$p['ovr']) > EF_OVR_SALTO) $aviso[] = 'OVR mudou muito';
        if (isset($ficha['age']) && !in_array($ficha['age'] - (int)$p['age'], [0, 1], true)) $aviso[] = 'idade estranha';
        if (!$novo && !$ficha) { $iguais++; continue; }
        if ($aviso) $suspeitos++;
        $antes = [];
        foreach ($novo as $col => $_) $antes[$col] = $p[$col];
        foreach ($ficha as $col => $_) $antes[$col] = $p[$col];
        $mud[] = ['player_id' => (int)$p['id'], 'nome' => $p['name'], 'lido' => $c['name'], 'letras' => $novo, 'ficha' => $ficha, 'antes' => $antes, 'aviso' => $aviso];
    }
    return ['mudancas' => $mud, 'lidos' => count($casados), 'iguais' => $iguais, 'suspeitos' => $suspeitos];
}

/** Estatísticas da temporada-alvo, já validadas com as mesmas faixas do site. */
function efMontarStats(PDO $pdo, array $casados, array $elenco, string $liga): array
{
    $alvo = statsTemporadaAlvo($pdo, $liga)['alvo'] ?? null;
    if (!$alvo) return ['erro' => 'Não achei a temporada pra lançar as estatísticas agora.'];
    $porId = [];
    foreach ($elenco as $p) $porId[(int)$p['id']] = $p;

    $st = $pdo->prepare('SELECT games, min_pg, pts_pg, reb_pg, ast_pg, stl_pg, blk_pg, fg_pct FROM player_season_stats WHERE player_id = ? AND season_id = ?');
    $mud = []; $recusados = [];
    foreach ($casados as $c) {
        $p = $porId[(int)$c['player_id']];
        $linha = []; $erroLinha = null;
        foreach (array_keys(ATUALIZACAO_STATS) as $col) {
            [$ok, $v, $erroV] = atualizacaoValorStat($col, $col === 'fg_pct' ? ($c[$col] ?? '') : ($c[$col] ?? 0));
            if (!$ok) { $erroLinha = $erroV; break; }
            $linha[$col] = $v;
        }
        if ($erroLinha) { $recusados[] = $p['name'] . ': ' . $erroLinha; continue; }
        if (!$linha['games'] && !$linha['pts_pg']) continue;   // linha sem jogos e sem pontos = não preenchida (igual ao site)
        $st->execute([(int)$p['id'], (int)$alvo['id']]);
        $antes = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $mud[] = ['player_id' => (int)$p['id'], 'nome' => $p['name'], 'lido' => $c['name'], 'stats' => $linha, 'antes' => $antes];
    }
    return ['mudancas' => $mud, 'lidos' => count($casados), 'alvo' => ['id' => (int)$alvo['id'], 'season_number' => (int)$alvo['season_number']], 'recusados' => $recusados];
}

function efFmtStat(string $col, $v): string
{
    if ($v === null || $v === '') return '—';
    return $col === 'games' ? (string)(int)$v : rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
}

function efTextoPreview(string $tipo, string $nomeTime, array $d, array $semPar, int $usadas): string
{
    $o = ($tipo === 'letras' ? "📸 *Letras lidas — {$nomeTime}*" : "📸 *Estatísticas lidas — {$nomeTime}*") . "\n";
    $o .= "Li {$d['lidos']} jogador(es) que batem com o elenco; *" . count($d['mudancas']) . '* vão mudar'
        . ($tipo === 'letras' ? " ({$d['iguais']} já estavam iguais)" : '') . ".\n";
    if ($tipo === 'stats') $o .= "_Lançando na temporada {$d['alvo']['season_number']}._\n";
    $o .= "\n";

    foreach ($d['mudancas'] as $m) {
        $o .= '• *' . $m['nome'] . '*';
        if ($tipo === 'letras') {
            $partes = [];
            foreach ($m['letras'] as $col => $v) $partes[] = EF_ROTULO[$col] . ' ' . (($m['antes'][$col] ?? '') !== '' && $m['antes'][$col] !== null ? $m['antes'][$col] : '—') . '→' . $v;
            foreach ($m['ficha'] as $col => $v) $partes[] = ($col === 'ovr' ? 'OVR' : 'idade') . ' ' . $m['antes'][$col] . '→' . $v;
            $o .= ' — ' . implode(' · ', $partes);
            if ($m['aviso']) $o .= ' ⚠️ _' . implode(', ', $m['aviso']) . '_';
        } else {
            $s = $m['stats'];
            $o .= ' — ' . efFmtStat('games', $s['games']) . ' J · ' . efFmtStat('min_pg', $s['min_pg']) . ' min · ' . efFmtStat('pts_pg', $s['pts_pg']) . ' pts · '
                . efFmtStat('reb_pg', $s['reb_pg']) . ' reb · ' . efFmtStat('ast_pg', $s['ast_pg']) . ' ast · ' . efFmtStat('stl_pg', $s['stl_pg']) . ' stl · '
                . efFmtStat('blk_pg', $s['blk_pg']) . ' blk · FG ' . ($s['fg_pct'] === null ? '—' : efFmtStat('fg_pct', $s['fg_pct']) . '%')
                . ($m['antes'] ? ' _(já tinha lançamento)_' : '');
        }
        $o .= "\n";
    }
    if ($semPar) $o .= "\n❓ Não reconheci no elenco: " . implode(', ', array_map(fn($x) => '"' . $x['name'] . '"', array_slice($semPar, 0, 6))) . (count($semPar) > 6 ? '…' : '') . "\n";
    foreach (array_slice($d['recusados'] ?? [], 0, 4) as $r) $o .= "⚠️ Pulei — {$r}\n";
    if (($d['suspeitos'] ?? 0) > 0) $o .= "\n⚠️ A leitura de OVR e idade é a que mais erra: confere os marcados.\n";
    $o .= "\nResponde */ok* pra gravar (vale " . EF_VALIDADE_MIN . " min) ou */cancelar*.\n_Leituras usadas: {$usadas}/" . EF_LIMITE_TEMPORADA . ' nesta temporada._';
    return $o;
}

/* ── Confirmar e cancelar ──────────────────────────────────────────────── */

/** O pedido em aberto de quem digitou (ainda válido), ou null. */
function efPendente(PDO $pdo, string $deQuem): ?array
{
    $quem = efQuem($deQuem);
    if ($quem === '') return null;
    efTabela($pdo);
    $st = $pdo->prepare("SELECT * FROM elenco_foto_pendente
                          WHERE quem = ? AND situacao = 'aberto' AND criado_em > NOW() - INTERVAL " . EF_VALIDADE_MIN . " MINUTE
                          ORDER BY id DESC LIMIT 1");
    $st->execute([$quem]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** /cancelar — null quando a pessoa não tinha nada pendente (o comando segue o caminho de sempre). */
function efCancelar(PDO $pdo, string $deQuem): ?string
{
    $p = efPendente($pdo, $deQuem);
    if (!$p) return null;
    $pdo->prepare("UPDATE elenco_foto_pendente SET situacao = 'cancelado' WHERE id = ?")->execute([(int)$p['id']]);
    return '🚫 Cancelei. Nada foi gravado no elenco.';
}

/**
 * /ok — grava o que o preview mostrou. null quando a pessoa não tinha nada pendente (aí o /ok segue pro
 * caminho de admin de sempre).
 */
function efConfirmar(PDO $pdo, string $deQuem): ?string
{
    $p = efPendente($pdo, $deQuem);
    if (!$p) return null;
    $dados = json_decode((string)$p['payload'], true) ?: [];
    $teamId = (int)$p['team_id'];
    $liga = (string)($dados['liga'] ?? '');

    // Trava de uso: o pedido só vale uma vez (o /ok repetido não grava de novo).
    $trava = $pdo->prepare("UPDATE elenco_foto_pendente SET situacao = 'gravando' WHERE id = ? AND situacao = 'aberto'");
    $trava->execute([(int)$p['id']]);
    if ($trava->rowCount() === 0) return null;

    // Só quem ainda é do elenco: o jogador pode ter sido trocado depois do preview.
    $ids = array_map(fn($m) => (int)$m['player_id'], (array)($dados['mudancas'] ?? []));
    $doElenco = [];
    if ($ids) {
        $st = $pdo->prepare('SELECT id FROM players WHERE team_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute(array_merge([$teamId], $ids));
        $doElenco = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    $gravados = 0; $pulados = 0;
    if ($p['tipo'] === 'stats') statsGarantirFgPct($pdo);   // antes da transação (ele não mexe em tabela dentro de uma)
    try {
        $pdo->beginTransaction();
        if ($p['tipo'] === 'letras') {
            $cols = implode(', ', array_keys(EF_SKILL_JSON));
            $le = $pdo->prepare("SELECT {$cols}, player_skill_grades FROM players WHERE id = ? AND team_id = ?");
            $up = $pdo->prepare('UPDATE players SET player_skill_grades = ?, skill_in = ?, skill_mid = ?, skill_3pt = ?, skill_post_d = ?, skill_per_d = ?,
                                        skill_play = ?, skill_reb = ?, skill_athl = ?, skill_iq = ?, skill_pot = ?, age = COALESCE(?, age), ovr = COALESCE(?, ovr)
                                  WHERE id = ? AND team_id = ?');
            foreach ($dados['mudancas'] as $m) {
                $pid = (int)$m['player_id'];
                if (!in_array($pid, $doElenco, true)) { $pulados++; continue; }
                $le->execute([$pid, $teamId]);
                $atual = $le->fetch(PDO::FETCH_ASSOC);
                if (!$atual) { $pulados++; continue; }
                $novas = array_merge(array_intersect_key($atual, EF_SKILL_JSON), $m['letras'] ?? []);   // letra que não veio no print fica como estava
                $json = [];
                foreach (EF_SKILL_JSON as $col => $k) $json[$k] = $novas[$col] ?? null;
                $up->execute([json_encode($json), $novas['skill_in'], $novas['skill_mid'], $novas['skill_3pt'], $novas['skill_post_d'], $novas['skill_per_d'],
                              $novas['skill_play'], $novas['skill_reb'], $novas['skill_athl'], $novas['skill_iq'], $novas['skill_pot'],
                              $m['ficha']['age'] ?? null, $m['ficha']['ovr'] ?? null, $pid, $teamId]);
                $gravados++;
            }
        } else {
            $alvo = $dados['alvo'] ?? null;
            $st = $pdo->prepare("SELECT season_number FROM seasons WHERE id = ?");
            $st->execute([(int)($alvo['id'] ?? 0)]);
            $numero = $st->fetchColumn();
            if (!$alvo || $numero === false) throw new RuntimeException('temporada-alvo sumiu');
            $ins = $pdo->prepare("INSERT INTO player_season_stats (player_id, season_id, season_number, league, team_id,
                                         games, min_pg, pts_pg, reb_pg, ast_pg, stl_pg, blk_pg, fg_pct, source)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'foto')
                                  ON DUPLICATE KEY UPDATE games=VALUES(games), min_pg=VALUES(min_pg), pts_pg=VALUES(pts_pg), reb_pg=VALUES(reb_pg),
                                         ast_pg=VALUES(ast_pg), stl_pg=VALUES(stl_pg), blk_pg=VALUES(blk_pg), fg_pct=VALUES(fg_pct), source=VALUES(source), team_id=VALUES(team_id)");
            foreach ($dados['mudancas'] as $m) {
                $pid = (int)$m['player_id'];
                if (!in_array($pid, $doElenco, true)) { $pulados++; continue; }
                $s = $m['stats'];
                $ins->execute([$pid, (int)$alvo['id'], (int)$numero, $liga, $teamId, $s['games'], $s['min_pg'], $s['pts_pg'], $s['reb_pg'], $s['ast_pg'],
                               $s['stl_pg'], $s['blk_pg'], $s['fg_pct']]);
                $gravados++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[elenco-foto] gravar: ' . $e->getMessage());
        $pdo->prepare("UPDATE elenco_foto_pendente SET situacao = 'erro' WHERE id = ?")->execute([(int)$p['id']]);
        return 'Deu erro ao gravar e *nada foi alterado*. Tenta de novo ou atualiza pelo site.';
    }

    marcarElencoAtualizado($pdo, $teamId);
    $extra = '';
    if ($p['tipo'] === 'letras' && $gravados > 0) {
        // Igual ao botão do site: congela a temporada pro histórico e pra comparação no perfil do jogador.
        $temp = efTemporadaAtiva($pdo, $liga);
        if ($temp) {
            $snap = snapshotTemporadaDoTime($pdo, $teamId, $temp, $liga);
            if (!empty($snap['ok'])) $extra = "\nHistórico da temporada {$temp['season_number']} registrado.";
        }
    }
    $pdo->prepare("UPDATE elenco_foto_pendente SET situacao = 'gravado' WHERE id = ?")->execute([(int)$p['id']]);

    $t = $p['tipo'] === 'letras' ? 'atualizado(s)' : 'com estatística lançada';
    return "✅ *{$dados['time']}*: {$gravados} jogador(es) {$t}." . ($pulados ? "\n_{$pulados} saiu(íram) do elenco depois do preview e não entrou(ram)._" : '') . $extra;
}
