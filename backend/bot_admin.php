<?php
/**
 * Os comandos de ADMIN do bot — e a tranca que os protege.
 *
 * O bot da liga é público: qualquer GM digita /time, /cap, /power no grupo
 * dele. Mas quem administra precisa de outras respostas — quem ainda não
 * atualizou o elenco, o que falta pra virar a temporada — e essas não podem
 * sair no grupo dos GMs. "Fulano não atualizou" no Gameplay é cobrança
 * pública; a mesma frase no grupo dos admins é organização.
 *
 * SÃO DUAS TRANCAS, e as duas precisam abrir:
 *
 *   1. O GRUPO tem que estar marcado como grupo de admin (/adminaqui).
 *   2. QUEM DIGITOU tem que administrar alguma liga, pelo telefone.
 *
 * Uma só não bastaria. Sem a primeira, um admin digitando no grupo errado
 * despeja a lista de devedores na frente de todo mundo — e o erro é fácil de
 * cometer, os grupos ficam lado a lado na mesma tela. Sem a segunda, qualquer
 * um que entre no grupo dos admins passa a mandar no bot.
 *
 * E a recusa é SILENCIOSA, como no /quizaqui: comando de admin digitado onde
 * não devia não responde nada, nem "você não pode". Um "não pode" conta que o
 * comando existe, e a lista de comandos de admin não é assunto do grupo dos
 * GMs.
 */

require_once __DIR__ . '/helpers.php';

/** Tipo das mensagens destes comandos na fila — separa no relatório do bot. */
const BOT_ADMIN_TIPO = 'admin';

function botAdminColunas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;
    try {
        $pdo->exec("ALTER TABLE whatsapp_grupos_comando
                    ADD COLUMN IF NOT EXISTS eh_admin TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
        error_log('[bot-admin] coluna eh_admin: ' . $e->getMessage());
    }
}

/** Este grupo foi marcado como grupo de admin? */
function botAdminGrupoLiberado(PDO $pdo, string $grupoJid): bool
{
    if ($grupoJid === '' || !str_ends_with($grupoJid, '@g.us')) return false;
    botAdminColunas($pdo);
    try {
        $st = $pdo->prepare("SELECT eh_admin FROM whatsapp_grupos_comando WHERE jid = ? LIMIT 1");
        $st->execute([$grupoJid]);
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
        error_log('[bot-admin] grupo: ' . $e->getMessage());
        return false;
    }
}

/**
 * O usuário do site por trás do telefone que digitou.
 *
 * Mesma tolerância dos comandos "meus": o número inteiro primeiro, e depois os
 * últimos 8 dígitos — o WhatsApp entrega o nono dígito do celular ora sim, ora
 * não, e o cadastro tem os dois formatos.
 */
function botAdminUsuario(PDO $pdo, string $deQuem): ?array
{
    $digitos = preg_replace('/\D+/', '', explode('@', $deQuem)[0] ?? '');
    // LID é identificador interno, não telefone: os dígitos existem e não são
    // de ninguém. Procurar no cadastro acharia o usuário errado ou nenhum.
    if (strlen($digitos) < 8 || str_contains($deQuem, '@lid')) return null;

    try {
        $st = $pdo->query("SELECT id, name, phone, user_type FROM users
                            WHERE phone IS NOT NULL AND phone <> ''");
        $fim = substr($digitos, -8);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $d = preg_replace('/\D+/', '', (string)$u['phone']);
            if ($d === $digitos || (strlen($d) >= 8 && substr($d, -8) === $fim)) return $u;
        }
    } catch (Throwable $e) {
        error_log('[bot-admin] usuario: ' . $e->getMessage());
    }
    return null;
}

/**
 * As ligas que quem digitou administra. Vazio = não administra nada.
 * Admin geral recebe todas, que é o que getAdminLeagues() já devolve.
 */
function botAdminLigasDe(PDO $pdo, string $deQuem): array
{
    $u = botAdminUsuario($pdo, $deQuem);
    if (!$u) return [];
    try {
        return array_values(array_map('strtoupper', getAdminLeagues($pdo, (int)$u['id'])));
    } catch (Throwable $e) {
        error_log('[bot-admin] ligas: ' . $e->getMessage());
        return ($u['user_type'] ?? '') === 'admin' ? ['ELITE', 'NEXT', 'RISE', 'ROOKIE'] : [];
    }
}

/**
 * As duas trancas de uma vez.
 *
 * @return array as ligas que a pessoa pode consultar aqui, ou [] se não pode
 *               nada — e aí quem chamou fica em silêncio.
 */
function botAdminPermitido(PDO $pdo, string $deQuem, string $grupoJid): array
{
    if (!botAdminGrupoLiberado($pdo, $grupoJid)) {
        botAdminDicaNoPrivado($pdo, $deQuem, $grupoJid);
        return [];
    }
    return botAdminLigasDe($pdo, $deQuem);
}

/**
 * O SILÊNCIO É PRA QUEM NÃO PODE, NÃO PRA QUEM ESQUECEU DE DESTRANCAR.
 *
 * Comando de admin em grupo não marcado não responde nada — e está certo, o
 * grupo dos GMs não precisa saber que ele existe. Só que isso também engole a
 * pista de quem ADMINISTRA e só esqueceu do /adminaqui: cadastrar o grupo no
 * painel não o marca como grupo de admin, e os dois passos são parecidos o
 * bastante pra confundir. Aconteceu no grupo ADM da RISE em 21/09/2026.
 *
 * Então: o grupo continua em silêncio, e quem é admin geral recebe a dica no
 * PRIVADO. Ninguém no grupo vê, e quem pode resolver fica sabendo o que fazer.
 *
 * Uma vez por grupo, por hora: comando repetido não vira enxurrada no PV.
 */
function botAdminDicaNoPrivado(PDO $pdo, string $deQuem, string $grupoJid): void
{
    if ($grupoJid === '' || !str_ends_with($grupoJid, '@g.us')) return;

    $u = botAdminUsuario($pdo, $deQuem);
    if (!$u || ($u['user_type'] ?? '') !== 'admin') return;   // só o admin geral

    $digitos = preg_replace('/\D+/', '', explode('@', $deQuem)[0] ?? '');
    if (strlen($digitos) < 8) return;

    try {
        botAdminPendentesTabela($pdo);
        // A marca de "já avisei" mora na própria tabela de pendências, como
        // uma ação que nunca é confirmada — evita tabela nova só pra isto.
        $st = $pdo->prepare("SELECT 1 FROM bot_admin_pendentes
                              WHERE acao = 'dica' AND grupo_jid = ? AND quem = ?
                                AND criado_em > DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 1");
        $st->execute([$grupoJid, $deQuem]);
        if ($st->fetchColumn()) return;

        $pdo->prepare("INSERT INTO bot_admin_pendentes (codigo, grupo_jid, quem, acao, descricao, usado_em)
                       VALUES ('0000', ?, ?, 'dica', 'aviso de grupo nao marcado', NOW())")
            ->execute([$grupoJid, $deQuem]);

        require_once __DIR__ . '/whatsapp.php';
        whatsappEnfileirar($pdo, $digitos . '@s.whatsapp.net',
            "🔐 Você usou um comando de admin num grupo que *ainda não é grupo de admin*.\n\n"
            . "Cadastrar o grupo no painel não basta — digite */adminaqui* dentro dele que eu libero os comandos ali.\n\n"
            . '_Respondi aqui no privado pra não abrir isso no grupo._',
            false, BOT_ADMIN_TIPO, null, null, $deQuem, 'adminaqui');
    } catch (Throwable $e) {
        error_log('[bot-admin] dica: ' . $e->getMessage());
    }
}

/**
 * /adminaqui — marca (ou desmarca) o grupo atual como grupo de admin.
 *
 * Mesmo caminho do /quizaqui, e pelo mesmo motivo: o identificador do grupo é
 * um número de 18 dígitos que não aparece em lugar nenhum do WhatsApp, então a
 * única forma prática de cadastrá-lo é de dentro dele. Só admin geral: marcar
 * um grupo é decidir onde a informação interna da FBA pode sair, e isso não é
 * da alçada de quem administra uma liga só.
 *
 * Responde no privado de quem digitou, nunca no grupo.
 */
function botAdminMarcarGrupo(PDO $pdo, string $deQuem, string $grupoJid, bool $ligar): string
{
    require_once __DIR__ . '/whatsapp.php';

    $digitos = preg_replace('/\D+/', '', explode('@', $deQuem)[0] ?? '');
    $privado = strlen($digitos) >= 8 && !str_contains($deQuem, '@lid')
             ? $digitos . '@s.whatsapp.net' : null;

    $avisar = function (string $txt) use ($pdo, $privado, $deQuem) {
        if ($privado) {
            whatsappEnfileirar($pdo, $privado, $txt, false, BOT_ADMIN_TIPO, null, null, $deQuem, 'adminaqui');
        }
        return '';
    };

    if ($grupoJid === '' || !str_ends_with($grupoJid, '@g.us')) {
        return $avisar('O /adminaqui só funciona dentro do grupo que vai virar grupo de admin.');
    }
    if (!$privado) return '';

    $u = botAdminUsuario($pdo, $deQuem);
    if (!$u || ($u['user_type'] ?? '') !== 'admin') return '';   // silêncio

    botAdminColunas($pdo);
    try {
        // O grupo também passa a ser atendido pelo bot (ativo=1): marcar como
        // grupo de admin sem isso deixaria o comando sem resposta justamente
        // no grupo que acabou de ser liberado.
        $pdo->prepare("INSERT INTO whatsapp_grupos_comando (jid, nome, ativo, eh_admin)
                       VALUES (?,?,1,?)
                       ON DUPLICATE KEY UPDATE ativo = 1, eh_admin = VALUES(eh_admin)")
            ->execute([$grupoJid, 'Grupo de admin', $ligar ? 1 : 0]);
    } catch (Throwable $e) {
        error_log('[bot-admin] marcar: ' . $e->getMessage());
        return $avisar('Não consegui salvar. Tenta de novo em um minuto.');
    }

    // Relê antes de confirmar: dizer "pronto" sem ter gravado é o pior
    // desfecho no comando que existe pra destrancar os outros.
    $agora = botAdminGrupoLiberado($pdo, $grupoJid);
    if ($agora !== $ligar) return $avisar('Salvei, mas não ficou como devia. Confere no painel do bot.');

    return $avisar($ligar
        ? "✅ Este grupo virou *grupo de admin*.\n\nOs comandos de admin respondem aqui pra quem administra alguma liga. */ajudaadmin* lista quais são.\n\n_Pra desfazer: /adminaqui off._"
        : '✅ Este grupo *não é mais* grupo de admin. Os comandos de admin pararam de responder aqui.');
}

/**
 * /timesstatus — quem já atualizou o elenco da temporada e quem não.
 *
 * A conta é a MESMA do checklist do admin e da bolinha da aba Times
 * (leagueRosterUpdateStatus): o elenco conta como atualizado quando
 * roster_updated_at é desta temporada. Três telas com a mesma resposta é o
 * mínimo — foi justamente a divergência entre duas delas que deu trabalho em
 * 21/09/2026.
 *
 * Sem liga no comando: o resumo das ligas que a pessoa administra, porque é a
 * pergunta de quem abre o grupo de manhã. Com liga: a lista nominal, com o GM
 * de cada time devendo — cobrar sem o nome de quem cobrar não serve pra nada.
 */
function botAdminTimesStatus(PDO $pdo, string $arg, array $ligasPermitidas, ?string $ligaDoGrupo = null): string
{
    require_once __DIR__ . '/league_cap.php';

    $arg = strtoupper(trim($arg));
    $alvo = null;
    if ($arg !== '') {
        foreach ($ligasPermitidas as $l) if ($l === $arg) { $alvo = $l; break; }
        if ($alvo === null) {
            return "Você não administra a *{$arg}*, ou essa liga não existe. "
                 . 'Suas ligas: ' . implode(', ', $ligasPermitidas) . '.';
        }
    } elseif ($ligaDoGrupo && in_array(strtoupper($ligaDoGrupo), $ligasPermitidas, true)) {
        $alvo = strtoupper($ligaDoGrupo);
    }

    $temporadaDe = function (string $liga) use ($pdo): ?array {
        $st = $pdo->prepare("SELECT id, season_number FROM seasons
                              WHERE league = ? AND (status IS NULL OR status <> 'completed')
                           ORDER BY id DESC LIMIT 1");
        $st->execute([$liga]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    // ── Resumo de todas as ligas que ele administra ──────────────────────
    if ($alvo === null) {
        $linhas = [];
        $pior = null; $piorFalta = 0;
        foreach ($ligasPermitidas as $liga) {
            $t = $temporadaDe($liga);
            if (!$t) { $linhas[] = "· *{$liga}* — sem temporada aberta"; continue; }
            $r = leagueRosterUpdateStatus($pdo, $liga, (int)$t['id']);
            $falta = $r['total'] - $r['done'];
            $linhas[] = ($falta === 0 ? '✅' : '⏳')
                . " *{$liga}* T{$t['season_number']} — {$r['done']}/{$r['total']}"
                . ($falta ? " _({$falta} faltando)_" : '');
            if ($falta > $piorFalta) { $piorFalta = $falta; $pior = $liga; }
        }
        if (!$linhas) return 'Você não administra nenhuma liga.';

        // A dica aponta pra liga que tem gente devendo, e some quando não há
        // nenhuma: mandar abrir a lista de uma liga completa é passo perdido.
        $dica = $pior ? "\n\n_/timesstatus " . strtolower($pior) . " pra ver quem está devendo._" : '';
        return "🧾 *ELENCOS ATUALIZADOS*\n\n" . implode("\n", $linhas) . $dica;
    }

    // ── Uma liga, com nome e GM de quem falta ────────────────────────────
    $t = $temporadaDe($alvo);
    if (!$t) return "A *{$alvo}* não tem temporada aberta agora.";

    $r = leagueRosterUpdateStatus($pdo, $alvo, (int)$t['id']);
    $cab = "🧾 *ELENCOS · {$alvo} T{$t['season_number']}*\n{$r['done']} de {$r['total']} atualizados";

    if (empty($r['pendentes_ids'])) return $cab . "\n\n✅ Todo mundo atualizou.";

    // O GM vem junto: a lista existe pra cobrar, e quem se cobra é a pessoa,
    // não o time.
    $marcas = implode(',', array_fill(0, count($r['pendentes_ids']), '?'));
    $st = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(te.city,''),' ',COALESCE(te.name,''))) AS time,
                                u.name AS gm
                           FROM teams te
                      LEFT JOIN users u ON u.id = te.user_id
                          WHERE te.id IN ({$marcas})
                       ORDER BY time");
    $st->execute($r['pendentes_ids']);

    $linhas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $gm = trim((string)$p['gm']);
        $linhas[] = '• ' . $p['time'] . ($gm !== '' ? " — _{$gm}_" : ' — _sem GM_');
    }

    $falta = count($linhas);
    return $cab . "\n\n*Faltam {$falta}:*\n" . implode("\n", $linhas);
}

/**
 * Qual liga o comando está falando.
 *
 * O argumento manda; sem ele, a liga do grupo; sem as duas, erro que diz o que
 * digitar. Um comando que escreve NUNCA assume liga: abrir a free agency da
 * ELITE achando que era a da RISE é um estrago que o grupo inteiro vê.
 *
 * @return array{0:?string,1:?string} [liga, erro]
 */
function botAdminLigaDoComando(?string $arg, array $ligasPermitidas, ?string $ligaDoGrupo): array
{
    $arg = strtoupper(trim((string)$arg));
    if ($arg !== '') {
        if (in_array($arg, $ligasPermitidas, true)) return [$arg, null];
        return [null, "Você não administra a *{$arg}*, ou essa liga não existe. "
                    . 'Suas ligas: ' . implode(', ', $ligasPermitidas) . '.'];
    }
    $daqui = strtoupper((string)$ligaDoGrupo);
    if ($daqui !== '' && in_array($daqui, $ligasPermitidas, true)) return [$daqui, null];

    return [null, 'Diz a liga: ' . implode(', ', array_map('mb_strtolower', $ligasPermitidas)) . '.'];
}

// ── Confirmação em duas etapas ──────────────────────────────────────────
//
// Tudo que ESCREVE passa por aqui. No grupo não existe "tem certeza?" de
// verdade: o dedo escorrega, o comando sai, e abrir a free agency por engano
// é a liga inteira correndo pros free agents três dias antes da hora. Então o
// comando só prepara a ação e devolve um código; o código é que executa.
//
// Vale 3 minutos e só pra quem pediu, no grupo onde pediu.

const BOT_ADMIN_CONFIRMA_SEG = 180;

function botAdminPendentesTabela(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_admin_pendentes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(8) NOT NULL,
            grupo_jid VARCHAR(120) NOT NULL,
            quem VARCHAR(120) NOT NULL,
            acao VARCHAR(120) NOT NULL,
            descricao VARCHAR(255) NOT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            usado_em DATETIME NULL,
            INDEX idx_cod (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[bot-admin] tabela pendentes: ' . $e->getMessage());
    }
}

/** Guarda a ação e devolve o texto que pede a confirmação. */
function botAdminPedirConfirmacao(PDO $pdo, string $grupoJid, string $quem, string $acao, string $descricao): string
{
    botAdminPendentesTabela($pdo);
    $codigo = (string)random_int(1000, 9999);
    try {
        $pdo->prepare('INSERT INTO bot_admin_pendentes (codigo, grupo_jid, quem, acao, descricao)
                       VALUES (?,?,?,?,?)')
            ->execute([$codigo, $grupoJid, $quem, $acao, mb_substr($descricao, 0, 255)]);
    } catch (Throwable $e) {
        error_log('[bot-admin] pedir confirmacao: ' . $e->getMessage());
        return 'Não consegui preparar isso agora. Tenta de novo.';
    }

    $min = (int)round(BOT_ADMIN_CONFIRMA_SEG / 60);
    return "⚠️ *{$descricao}*\n\nConfirma com */ok {$codigo}* — vale {$min} minutos.";
}

/** /ok CÓDIGO — executa o que estava guardado. */
function botAdminConfirmar(PDO $pdo, string $arg, string $grupoJid, string $quem, array $ligasPermitidas): string
{
    botAdminPendentesTabela($pdo);
    $codigo = preg_replace('/\D+/', '', $arg);
    if ($codigo === '') return 'Manda o código: */ok 1234*.';

    try {
        /* O UPDATE condicional é o que garante UMA execução: dois /ok do mesmo
           código, ou duas pessoas ao mesmo tempo, e só um muda linha. Quem não
           mudou, não executa. */
        $st = $pdo->prepare("UPDATE bot_admin_pendentes SET usado_em = NOW()
                              WHERE codigo = ? AND grupo_jid = ? AND quem = ? AND usado_em IS NULL
                                AND criado_em > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $st->execute([$codigo, $grupoJid, $quem, BOT_ADMIN_CONFIRMA_SEG]);
        if ($st->rowCount() === 0) {
            return 'Esse código não vale mais — ou já foi usado, ou passou dos 3 minutos, ou é de outra pessoa. Manda o comando de novo.';
        }

        $st = $pdo->prepare('SELECT acao, descricao FROM bot_admin_pendentes
                              WHERE codigo = ? AND grupo_jid = ? AND quem = ?
                           ORDER BY id DESC LIMIT 1');
        $st->execute([$codigo, $grupoJid, $quem]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return 'Perdi o que era pra fazer. Manda o comando de novo.';
    } catch (Throwable $e) {
        error_log('[bot-admin] confirmar: ' . $e->getMessage());
        return 'Não consegui executar agora. Tenta de novo.';
    }

    return botAdminExecutar($pdo, (string)$p['acao'], $ligasPermitidas);
}

/**
 * Faz o que foi confirmado.
 *
 * A liga é conferida DE NOVO contra o que a pessoa administra: entre pedir e
 * confirmar, ela pode ter perdido o cargo — e o código guardado não é
 * autorização eterna.
 */
function botAdminExecutar(PDO $pdo, string $acao, array $ligasPermitidas): string
{
    $partes = explode('|', $acao);
    $tipo = $partes[0] ?? '';
    $liga = strtoupper($partes[1] ?? '');

    if (!in_array($liga, $ligasPermitidas, true)) return "Você não administra mais a *{$liga}*.";

    switch ($tipo) {
        case 'fase':
            require_once __DIR__ . '/fases_liga.php';
            $fase  = $partes[2] ?? '';
            $abrir = ($partes[3] ?? '0') === '1';
            $r = faseLigaDefinir($pdo, $liga, $fase, $abrir);
            return $r['texto'];

        case 'cap':
            require_once __DIR__ . '/league_cap.php';
            $r = recalcularCapAgora($pdo, $liga);
            if (!$r['ok']) return '❌ ' . ($r['erro'] ?? 'Não deu pra recalcular.');
            $s = $r['resumo'];
            $atu = $s['atualizados'] ?? null;

            /* O ALVO APARECE QUANDO O TETO SEGUROU.
               Sem isso, o admin que esperava a faixa da média e viu outra não
               sabe se o passo entrou ou se a conta mudou — e é justamente o
               passo que ele precisa acompanhar de recálculo em recálculo. */
            $freio = !empty($s['segurou'])
                ? "\n_A média pedia {$s['alvo_min']}–{$s['alvo_max']}; o cap anda no máximo "
                  . LEAGUE_CAP_PASSO_MAXIMO . " por vez._"
                : '';
            $antes = !empty($s['antes_min'])
                ? "Era: {$s['antes_min']} – {$s['antes_max']}\n" : '';

            return "📊 *CAP DA {$liga} ATUALIZADO*\n"
                 . $antes
                 . "Faixa nova: *{$s['cap_min']} – {$s['cap_max']}*\n"
                 . "Média dos elencos: {$s['avg']} · margem {$s['margin']}\n"
                 . "{$s['teams_above']} acima, {$s['teams_below']} abaixo"
                 . $freio
                 . ($atu ? "\n\n_Elencos atualizados quando rodou: {$atu['done']}/{$atu['total']}._" : '');

        case 'draft':
            return botAdminIniciarDraft($pdo, $liga);

        case 'relogio':
            return botAdminIniciarRelogio($pdo, $liga);
    }
    return 'Não sei mais o que era pra fazer.';
}

// ── As ações ────────────────────────────────────────────────────────────

/** /fases — como estão trades, free agency e dispensas. */
function botAdminFases(PDO $pdo, string $arg, array $ligasPermitidas, ?string $ligaDoGrupo): string
{
    require_once __DIR__ . '/fases_liga.php';

    $ligas = $ligasPermitidas;
    [$uma, $erro] = botAdminLigaDoComando($arg, $ligasPermitidas, null);
    if ($uma !== null) $ligas = [$uma];
    elseif (trim($arg) !== '') return $erro;

    $saida = [];
    foreach ($ligas as $liga) {
        $e = faseLigaEstado($pdo, $liga);
        $linha = [];
        foreach (FASES_LIGA as $chave => $f) {
            $linha[] = ($e[$chave]['aberta'] ? '🟢' : '🔴') . ' ' . $f['nome'];
        }
        $texto = "*{$liga}*\n" . implode('  ·  ', $linha);

        // Fechamento já marcado na tela do admin: quem abre a fase pelo bot
        // precisa saber que existe um horário pra fechá-la de novo.
        $ag = [];
        foreach (['trades' => 'Trades', 'fa' => 'Free Agency'] as $k => $nome) {
            if (!empty($e['_agendado'][$k])) {
                $ag[] = $nome . ' fecha ' . date('d/m H:i', strtotime((string)$e['_agendado'][$k]));
            }
        }
        if ($ag) $texto .= "\n_" . implode(' · ', $ag) . '_';
        $saida[] = $texto;
    }

    return "🎚️ *FASES*\n\n" . implode("\n\n", $saida)
         . "\n\n_/abrir trades " . mb_strtolower($ligas[0]) . " · /fechar fa " . mb_strtolower($ligas[0]) . "_";
}

/** /abrir e /fechar — preparam a mudança e pedem confirmação. */
function botAdminPedirFase(PDO $pdo, string $arg, bool $abrir, array $ligasPermitidas,
                           ?string $ligaDoGrupo, string $grupoJid, string $quem): string
{
    require_once __DIR__ . '/fases_liga.php';

    $partes = preg_split('/\s+/', trim($arg), 2);
    $fase = faseLigaNormalizar((string)($partes[0] ?? ''));
    if ($fase === null) {
        return 'O que abrir ou fechar? *trades*, *fa* ou *dispensas*. Ex.: /' . ($abrir ? 'abrir' : 'fechar') . ' trades rise';
    }

    [$liga, $erro] = botAdminLigaDoComando($partes[1] ?? null, $ligasPermitidas, $ligaDoGrupo);
    if ($liga === null) return $erro;

    $estado = faseLigaEstado($pdo, $liga)[$fase];
    if ($estado['aberta'] === $abrir) {
        return "{$estado['nome']} da *{$liga}* já " . ($abrir ? 'está aberta.' : 'está fechada.');
    }

    $desc = ($abrir ? 'Abrir' : 'Fechar') . " {$estado['nome']} da {$liga}"
          . (!$abrir && $fase === 'trades' ? ' (cancela as propostas pendentes)' : '')
          . ' — a liga recebe aviso';

    return botAdminPedirConfirmacao($pdo, $grupoJid, $quem,
        "fase|{$liga}|{$fase}|" . ($abrir ? '1' : '0'), $desc);
}

/** /atualizarcap — recalcula a faixa de CAP da liga. */
function botAdminPedirCap(PDO $pdo, string $arg, array $ligasPermitidas,
                          ?string $ligaDoGrupo, string $grupoJid, string $quem): string
{
    require_once __DIR__ . '/league_cap.php';

    [$liga, $erro] = botAdminLigaDoComando($arg, $ligasPermitidas, $ligaDoGrupo);
    if ($liga === null) return $erro;

    /* QUANTOS TIMES AINDA NÃO ATUALIZARAM VEM NO AVISO, e não depois.
       A faixa sai da média dos elencos: recalcular com metade da liga ainda
       com o elenco da temporada passada produz um número errado que todo
       mundo passa a seguir. É a informação que decide se é hora ou não. */
    $st = $pdo->prepare("SELECT id, season_number FROM seasons
                          WHERE league = ? AND (status IS NULL OR status <> 'completed')
                       ORDER BY id DESC LIMIT 1");
    $st->execute([$liga]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return "A *{$liga}* não tem temporada aberta agora.";

    $r = leagueRosterUpdateStatus($pdo, $liga, (int)$t['id']);
    $falta = $r['total'] - $r['done'];

    $desc = "Recalcular o CAP da {$liga} (T{$t['season_number']})"
          . ($falta ? " — ATENÇÃO: {$falta} time(s) ainda não atualizaram o elenco" : ' — todos os elencos atualizados');

    $pedido = botAdminPedirConfirmacao($pdo, $grupoJid, $quem, "cap|{$liga}", $desc);

    /* A FAIXA SUGERIDA VEM ANTES DO /ok.
       Confirmar um recálculo sem ver o número é assinar em branco: a mesma
       conta que vai rodar já sabe dizer no que dá, e é ela que o admin quer
       olhar antes de dizer sim. Nada é gravado aqui. */
    $previa = capPreviaDoRecalculo($pdo, $liga);
    if ($previa) {
        $un = ($previa['cap_mode'] ?? '') === 'salary' ? 'M' : '';
        $linhas = "\n\n📊 *Vai ficar: {$previa['cap_min']} – {$previa['cap_max']}{$un}*";
        if (!empty($previa['antes_min'])) {
            $linhas .= "\n_Hoje: {$previa['antes_min']} – {$previa['antes_max']}{$un}_";
        }
        /* Três linhas e só. O alvo da média e a contagem de quem fica fora da
           faixa saíram a pedido dele: a pergunta aqui é "aplico este cap?", e
           o resto é conversa pra depois de aplicado. */
        $linhas .= "\n_Média dos elencos: {$previa['avg']}{$un}_";
        $pedido .= $linhas;
    }

    return $pedido;
}

/** /iniciardraft — tira o draft do "configurando" e abre a 1ª rodada. */
function botAdminIniciarDraft(PDO $pdo, string $liga): string
{
    $st = $pdo->prepare("SELECT ds.id, ds.status, s.season_number
                           FROM draft_sessions ds
                           JOIN seasons s ON s.id = ds.season_id
                          WHERE ds.league = ? ORDER BY ds.id DESC LIMIT 1");
    $st->execute([$liga]);
    $d = $st->fetch(PDO::FETCH_ASSOC);

    if (!$d) return "A *{$liga}* não tem draft criado. Isso é na tela do admin.";
    if ($d['status'] === 'in_progress') return "O draft da *{$liga}* já está rolando.";
    if ($d['status'] !== 'setup') return "O draft da *{$liga}* está como *{$d['status']}* — não dá pra iniciar.";

    $st = $pdo->prepare('SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ?');
    $st->execute([(int)$d['id']]);
    if ((int)$st->fetchColumn() === 0) {
        return "A ordem do draft da *{$liga}* está vazia. Defina a ordem antes (loteria ou tela do admin).";
    }

    $pdo->prepare('UPDATE draft_sessions
                      SET status = "in_progress", started_at = NOW(), current_pick_started_at = NOW()
                    WHERE id = ? AND status = "setup"')->execute([(int)$d['id']]);

    // O aviso da vez é do relógio (backend/draft_relogio.php), que também
    // agenda a abertura automática 16h depois. Uma passada agora já deixa
    // tudo armado, em vez de esperar o próximo tick.
    try {
        require_once __DIR__ . '/draft_relogio.php';
        draftRelogioSessao($pdo, (int)$d['id']);
    } catch (Throwable $e) {
        error_log('[bot-admin] relogio pos-inicio: ' . $e->getMessage());
    }

    return "🏀 *Draft da {$liga} iniciado* (T{$d['season_number']}).\n"
         . "_O relógio abre sozinho em 16h; /relogio começa agora._";
}

/** /relogio — liga o relógio de 3 min por pick na hora. */
function botAdminIniciarRelogio(PDO $pdo, string $liga): string
{
    require_once __DIR__ . '/draft_relogio.php';

    $st = $pdo->prepare("SELECT id, current_round, round1_clock_start_at
                           FROM draft_sessions
                          WHERE league = ? AND status = 'in_progress'
                       ORDER BY id DESC LIMIT 1");
    $st->execute([$liga]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) return "A *{$liga}* não tem draft em andamento.";

    $inicio = $d['round1_clock_start_at'] ? strtotime((string)$d['round1_clock_start_at']) : null;
    if ($inicio !== null && $inicio <= time()) return "O relógio da *{$liga}* já está correndo.";

    draftRelogioColunas($pdo);
    $pdo->prepare('UPDATE draft_sessions
                      SET round1_clock_start_at = NOW(), clock_manual_off = NULL,
                          clock_aviso_em = NULL, clock_abertura_em = NULL, vez_anunciada = NULL
                    WHERE id = ?')->execute([(int)$d['id']]);

    // Anuncia a abertura e chama o primeiro da fila no mesmo pulso.
    try {
        draftRelogioSessao($pdo, (int)$d['id']);
    } catch (Throwable $e) {
        error_log('[bot-admin] relogio: ' . $e->getMessage());
    }

    return "⏱️ *Relógio da {$liga} ligado* — 3 minutos por pick a partir de agora.\n"
         . '_O bot já chamou o time da vez no Gameplay._';
}

/**
 * /abrirtela — antecipa a venda dos slots de tela da próxima live.
 *
 * Só abre. A venda já abre sozinha no horário e fecha sozinha quando a live
 * começa; o comando existe pro caso em que a organização quer vender antes.
 * Não há "fechartela" de propósito — desfazer a antecipação é tela de admin,
 * e um comando a mais no grupo por algo que se resolve sozinho em horas é
 * mais uma linha pra alguém digitar por engano.
 *
 * Quem já comprou continua comprado: os slots vendidos não são tocados aqui.
 */
function botAdminTela(PDO $pdo, string $liga, int $adminId): string
{
    require_once __DIR__ . '/slots_tela.php';

    $r = slotsTelaAbrirAgora($pdo, $liga, $adminId);
    if (empty($r['ok'])) return '❌ ' . ($r['erro'] ?? 'Não deu certo.');

    $live = $r['live'] ?? [];
    $quando = !empty($live['inicio'])
        ? date('d/m \à\s H:i', strtotime((string)$live['inicio'])) : 'a próxima live';

    return "📺 *Venda de tela aberta na {$liga}*\nLive de {$quando}.\n_Fecha sozinha quando a live começar._";
}

/**
 * /admin — a lista dos comandos que só existem aqui.
 *
 * Fora do /ajuda de propósito: aquele é o cartaz do grupo dos GMs, e comando
 * que só funciona no grupo de admin lá vira pergunta ("por que não funciona
 * pra mim?"). Aqui dentro, /admin é o índice.
 */
function botAdminAjuda(array $ligasPermitidas): string
{
    $l = mb_strtolower($ligasPermitidas[0] ?? 'elite');
    return "🔐 *COMANDOS DE ADMIN*\n"
         . '_Só neste grupo, e só pra quem administra liga._' . "\n\n"
         . "*Ver*\n"
         . "/timesstatus — quem atualizou o elenco e quem não\n"
         . "/timesstatus _{$l}_ — a lista nominal, com o GM\n"
         . "/fases — trades, free agency e dispensas de cada liga\n"
         . "/irregulares _{$l}_ — quem está fora do cap ou da faixa\n\n"
         . "*Mexer* _(pede confirmação)_\n"
         . "/abrir trades _{$l}_ · /fechar fa _{$l}_\n"
         . "/atualizarcap _{$l}_ — recalcula a faixa de CAP\n"
         . "/iniciardraft _{$l}_ — abre o draft\n"
         . "/relogio _{$l}_ — liga os 3 min por pick agora\n\n"
         . "*Games*\n"
         . "/abrirtela _{$l}_ — antecipa a venda de slot da próxima live\n\n"
         . "*Edital e regras*\n"
         . "/duvida _sua pergunta_ · /edital _termo_\n\n"
         . '_Suas ligas: ' . (implode(', ', $ligasPermitidas) ?: 'nenhuma') . '._';
}
