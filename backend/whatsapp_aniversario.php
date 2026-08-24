<?php
/**
 * OS ANIVERSARIANTES DO DIA, às 9h no grupo.
 *
 * Uma mensagem só por dia, com todo mundo que faz aniversário — e não uma
 * por pessoa. Em dia de três aniversariantes, três mensagens seguidas no
 * grupo é o que faz gente silenciar o grupo.
 *
 * Dia sem aniversariante NÃO manda nada. "Hoje ninguém faz aniversário" é
 * uma mensagem que não serve pra nada e sai 340 vezes por ano.
 *
 * A data vem de users.birth_date, preenchida em Minha Conta. Enquanto
 * ninguém preencher, isto simplesmente não tem o que dizer.
 */

require_once __DIR__ . '/whatsapp.php';

/** Horário de Brasília. Cai dentro da janela de envio (08:45–18:00). */
const ANIVERSARIO_HORA = 9;

/**
 * Quem faz aniversário hoje.
 *
 * Compara mês e dia, ignorando o ano — o ano é a idade, e a pergunta aqui é
 * outra. E o "hoje" é o de Brasília, não o do servidor: a Hostinger roda em
 * UTC, então das 21h à meia-noite o dia dele já é o de amanhã, e o
 * aniversariante seria parabenizado um dia adiantado.
 *
 * Só GM com time: o grupo é da liga, e parabéns pra quem o grupo não conhece
 * é constrangimento pros dois lados.
 *
 * O 29 de fevereiro tem tratamento próprio — ver aniversarioDiaDeHoje().
 *
 * @return array<int, array{id:int,name:string,phone:?string,birth_date:string}>
 */
function aniversariantesDoDia(PDO $pdo, ?DateTimeImmutable $agora = null): array
{
    $agora = $agora ?: new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $mes = (int)$agora->format('n');
    $dia = (int)$agora->format('j');

    try {
        $st = $pdo->prepare("SELECT u.id, u.name, u.phone, u.birth_date
                               FROM users u
                              WHERE u.birth_date IS NOT NULL
                                AND MONTH(u.birth_date) = ?
                                AND DAY(u.birth_date) = ?
                                AND u.name IS NOT NULL AND u.name <> ''
                                AND EXISTS (SELECT 1 FROM teams t WHERE t.user_id = u.id)
                              ORDER BY u.name ASC");
        $st->execute([$mes, $dia]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // Banco ainda sem a coluna birth_date: nada a comemorar, e o cron não
        // pode quebrar por causa disso.
        error_log('[aniversario] consulta: ' . $e->getMessage());
        return [];
    }
}

/**
 * Hoje é 28 de fevereiro de um ano que não tem dia 29?
 *
 * Nesse dia o parabéns também vai pra quem nasceu em 29/02 — senão essa
 * pessoa passa três anos em cada quatro sem parabéns nenhum, e ela é
 * justamente quem mais repara.
 *
 * 28/02 e não 01/03 porque mantém o parabéns dentro do mês em que a pessoa
 * nasceu. As duas datas são defensáveis; não existe regra oficial.
 */
function aniversarioPegaVinteNove(DateTimeImmutable $agora): bool
{
    return (int)$agora->format('n') === 2
        && (int)$agora->format('j') === 28
        && !$agora->format('L');
}

/**
 * O texto do parabéns.
 *
 * O nome vem NA FRENTE da menção pelo mesmo motivo do abraço: a etiqueta
 * sozinha mostra o nome que QUEM LÊ tem salvo, e quem não tem o contato
 * salvo enxerga só um número.
 *
 * A data aparece do lado do nome porque foi pedida assim — e ela ganha
 * sentido quando alguém rola o grupo depois e quer saber de que dia era.
 */
function aniversarioTexto(array $gente): string
{
    $linhas = [];
    $mencoes = [];

    foreach ($gente as $g) {
        $nome = trim((string)$g['name']);
        $tel  = preg_replace('/\D+/', '', (string)($g['phone'] ?? ''));
        $alvo = $nome;
        if (strlen($tel) >= 10) {
            $alvo = $nome . ' (@' . $tel . ')';
            $mencoes[] = $tel;
        }
        $data = date('d/m', strtotime((string)$g['birth_date']));
        $linhas[] = '🎉 ' . $alvo . ' — ' . $data;
    }

    $cabecalho = count($gente) === 1
        ? '🎂 *Hoje é aniversário de:*'
        : '🎂 *Os aniversariantes de hoje são:*';

    $fecho = count($gente) === 1
        ? 'Parabéns! Que venha mais um ano de boas escolhas no draft. 🏀'
        : 'Parabéns aos dois... digo, a todos! 🏀';
    if (count($gente) > 2) $fecho = 'Parabéns a todos! 🏀';

    return implode("\n", array_merge([$cabecalho, ''], $linhas, ['', $fecho]));
}

/**
 * Manda o parabéns do dia. Devolve o que aconteceu, pra tela e pro log.
 *
 * @param bool $forcar Ignora o horário E a marca do dia — é o "testar agora".
 * @return array{enviado:bool, motivo:string, quantos:int, nomes:array}
 */
function enviarAniversariosDoDia(PDO $pdo, bool $forcar = false): array
{
    ensureWhatsAppTables($pdo);

    $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

    // Antes das 9h em Brasília não faz nada, mesmo que o relógio do servidor
    // diga outra coisa. Depois das 9h manda — o que cobre execução perdida,
    // e é por isso que dá pra agendar de hora em hora sem medo.
    if (!$forcar && (int)$agora->format('G') < ANIVERSARIO_HORA) {
        return ['enviado' => false, 'motivo' => 'fora_de_hora', 'quantos' => 0, 'nomes' => []];
    }

    $marca = 'aniversario_do_dia_' . $agora->format('Y-m-d');

    // A marca é gravada ANTES de enfileirar: duas execuções entrando juntas,
    // a segunda esbarra na chave primária e sai. Enfileirar primeiro deixaria
    // a brecha pras duas mandarem o mesmo parabéns.
    if (!$forcar) {
        try {
            $pdo->prepare("INSERT INTO app_flags (flag, applied_at) VALUES (?, NOW())")->execute([$marca]);
        } catch (Throwable $e) {
            return ['enviado' => false, 'motivo' => 'ja_foi_hoje', 'quantos' => 0, 'nomes' => []];
        }
    }

    $gente = aniversariantesDoDia($pdo, $agora);

    if (aniversarioPegaVinteNove($agora)) {
        try {
            $st = $pdo->prepare("SELECT u.id, u.name, u.phone, u.birth_date
                                   FROM users u
                                  WHERE MONTH(u.birth_date) = 2 AND DAY(u.birth_date) = 29
                                    AND u.name IS NOT NULL AND u.name <> ''
                                    AND EXISTS (SELECT 1 FROM teams t WHERE t.user_id = u.id)
                                  ORDER BY u.name ASC");
            $st->execute();
            $gente = array_merge($gente, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            error_log('[aniversario] 29/02: ' . $e->getMessage());
        }
    }

    if (!$gente) {
        // Dia sem aniversariante não manda nada, e a MARCA FICA: sem ela, uma
        // execução de hora em hora refaria a consulta o dia inteiro.
        return ['enviado' => false, 'motivo' => 'ninguem_hoje', 'quantos' => 0, 'nomes' => []];
    }

    $grupo = trim((string)($pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: ''));
    if ($grupo === '') {
        if (!$forcar) $pdo->prepare("DELETE FROM app_flags WHERE flag = ?")->execute([$marca]);
        return ['enviado' => false, 'motivo' => 'sem_grupo', 'quantos' => count($gente), 'nomes' => []];
    }

    $texto = aniversarioTexto($gente);

    // As menções precisam bater com os @numero que já estão no texto — quem
    // monta os dois é a mesma função acima, então basta recolher de novo.
    $mencoes = [];
    foreach ($gente as $g) {
        $tel = preg_replace('/\D+/', '', (string)($g['phone'] ?? ''));
        if (strlen($tel) >= 10) $mencoes[] = $tel;
    }

    $ok = whatsappEnfileirar($pdo, $grupo, $texto, true, 'aniversario', null, $mencoes ?: null);
    if (!$ok) {
        // A marca do dia está gravada mas não há mensagem na fila. Apago pra
        // que a próxima execução tente de novo, em vez de pular o dia calada.
        if (!$forcar) $pdo->prepare("DELETE FROM app_flags WHERE flag = ?")->execute([$marca]);
        return ['enviado' => false, 'motivo' => 'bot_desligado', 'quantos' => count($gente), 'nomes' => []];
    }

    return [
        'enviado' => true,
        'motivo'  => 'ok',
        'quantos' => count($gente),
        'nomes'   => array_column($gente, 'name'),
    ];
}
