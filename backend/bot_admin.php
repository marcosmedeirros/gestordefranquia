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
    if (!botAdminGrupoLiberado($pdo, $grupoJid)) return [];
    return botAdminLigasDe($pdo, $deQuem);
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

/** A lista dos comandos que só existem aqui. */
function botAdminAjuda(array $ligasPermitidas): string
{
    return "🔐 *COMANDOS DE ADMIN*\n"
         . '_Respondem só neste grupo, e só pra quem administra liga._' . "\n\n"
         . "/timesstatus — quem atualizou o elenco e quem não\n"
         . "/timesstatus _liga_ — a lista nominal, com o GM de cada um\n"
         . "/adminaqui off — tira este grupo da lista de grupos de admin\n\n"
         . '_Suas ligas: ' . (implode(', ', $ligasPermitidas) ?: 'nenhuma') . '._';
}
