<?php
/**
 * O AUDITOR DE CARREIRA
 *
 * Os dois jogos de carreira rodam no navegador e mandam o resultado pro
 * servidor, que paga moeda por conquista. O estado que chega é, portanto,
 * escrito pelo cliente — e quem sabe abrir o console pode escrever o que
 * quiser. Não existe conserto pequeno pra isso: a única defesa completa é o
 * servidor simular a carreira, e simular no servidor é reescrever os dois
 * jogos.
 *
 * O que este arquivo faz é outra coisa, mais barata e ainda assim útil:
 * rejeita a carreira IMPOSSÍVEL. Não a improvável — a impossível, a que o
 * motor não consegue produzir em nenhum sorteio. Quem forja no olho manda
 * quarenta temporadas de 99 OVR com todos os títulos; isso morre aqui. Quem
 * forja com cuidado, lendo o motor e respeitando cada teto, passa — e pra
 * esse sobra o freio de ritmo (ver auditarQuantoCabe), que
 * limita quanto dá pra ganhar por hora mesmo com carreiras perfeitas.
 *
 * Todo teto aqui saiu de MEDIÇÃO, não de chute: 6 carreiras completas do
 * Copero (139 temporadas) e 3 do Caminho, mais os limites que estão escritos
 * no próprio código do motor. Onde o motor tem um teto explícito, é ele que
 * vale. Onde não tem, o teto é o máximo medido com folga larga — errar pra
 * frouxo aqui custa uma fraude a mais; errar pra apertado custa um jogador
 * honesto sem a moeda dele, que é bem pior.
 */

require_once __DIR__ . '/db.php';

/** O veredito: ['ok' => bool, 'motivo' => string|null]. */
function auditarOk(): array { return ['ok' => true, 'motivo' => null]; }
function auditarNao(string $motivo): array { return ['ok' => false, 'motivo' => $motivo]; }

// ── COPERO ─────────────────────────────────────────────────────────────
// jogos: o motor faz Math.max(4, Math.min(52, ...)) e a lesão pode derrubar
//        pra 2. Então 0..52 é o intervalo do próprio código.
// gols/ast por jogo: DERIVADO DA FÓRMULA, não da amostra. O motor faz
//        jogos * peso * q * folga * aleatório, e o teto de cada fator é
//        conhecido: peso 0,68 (centroavante), q 1,96 (overall 99), folga
//        2,05, aleatório 1,30 — dá 3,55 gol por jogo. Assistência, pelo
//        mesmo caminho, 2,28.
//
//        A primeira versão usava 1,5 e 1,0, tirados do máximo que EU tinha
//        medido em oito carreiras (0,69 e 0,24). Parecia folga de duas
//        vezes; era menos da metade do que o jogo alcança. Um artilheiro de
//        99 numa liga fraca teria a carreira recusada — exatamente o
//        jogador que mais teria conquista a perder.
// ovr: a régua do jogo vai de 40 a 99.
// idade: 16 até COPERO_IDADE_FINAL (41 no catálogo de hoje).
const AUDITOR_COPERO = [
    'idade_min'      => 15,
    'idade_max'      => 45,
    // 32 era APERTADO DEMAIS e teria recusado carreira honesta. Chegou uma de
    // 34 temporadas — o motor grava mais de uma por ano (empréstimo, modo
    // rápido), e das oito carreiras que eu tinha medido nenhuma passou de 27,
    // então 32 parecia folgado e não era. O teto duro sobe pra 52, e quem faz
    // o trabalho de verdade é a regra de baixo, que amarra o número de
    // temporadas ao INTERVALO DE IDADE que elas cobrem.
    'temporadas_max' => 52,
    'temporadas_por_ano' => 3,
    'jogos_max'      => 52,
    'sel_jogos_max'  => 30,
    'gols_por_jogo'  => 4.0,
    'ast_por_jogo'   => 2.8,
    'ovr_min'        => 30,
    'ovr_max'        => 99,
    'ovr_salto_max'  => 22,
    'titulos_ano'    => 6,
    'valor_max'      => 900000000,
];

// ── CAMINHO ────────────────────────────────────────────────────────────
// pts/reb/ast são MÉDIA por jogo, não total. O teto medido de assistências
// é 9,3 por ano (está escrito no comentário do próprio desafio), e o de
// pontos passa de 30 em 1,3% das carreiras. Os tetos abaixo são bem acima
// disso e ainda assim fecham a porta pro "40 de média em 20 temporadas".
const AUDITOR_CAMINHO = [
    'idade_min'      => 15,
    'idade_max'      => 45,
    // 30 era justo: o Caminho grava uma temporada por ano, mas ano de formação
    // e ano perdido também entram na lista, e uma carreira de 17 a 41 com dois
    // anos de faculdade e três lesões passa de 28. Mesmo desenho do Copero: o
    // teto duro sobe e quem manda é a razão contra o intervalo de idade.
    'temporadas_max' => 45,
    'temporadas_por_ano' => 2,
    'jogos_max'      => 82,
    'pts_max'        => 45,
    'reb_max'        => 25,
    'ast_max'        => 20,
    'ovr_min'        => 25,
    'ovr_max'        => 99,
    'premios_ano'    => 8,
];

/**
 * A carreira do Copero cabe no que o motor produz?
 *
 * Recebe o array de temporadas — o mesmo de onde as conquistas são tiradas.
 */
function auditarCopero(array $temporadas): array
{
    $L = AUDITOR_COPERO;
    $n = count($temporadas);
    if ($n === 0) return auditarNao('carreira sem temporadas');
    if ($n > $L['temporadas_max']) return auditarNao("temporadas demais ({$n})");

    $idadeAnterior = null;
    $ovrAnterior   = null;
    $idadeMin = null;
    $idadeMax = null;

    foreach ($temporadas as $i => $t) {
        if (!is_array($t)) return auditarNao("temporada {$i} não é um objeto");

        $idade = (int)($t['idade'] ?? 0);
        $jogos = (int)($t['jogos'] ?? 0);
        $gols  = (int)($t['gols']  ?? 0);
        $ast   = (int)($t['ast']   ?? 0);
        $ovr   = (int)($t['ovr']   ?? 0);

        if ($idade < $L['idade_min'] || $idade > $L['idade_max'])
            return auditarNao("idade fora da régua na temporada {$i} ({$idade})");

        // A idade nunca VOLTA. E só isso.
        //
        // A primeira versão desta regra exigia que ela AVANÇASSE a cada
        // temporada, e teria recusado 100% das carreiras honestas: passei
        // sete carreiras completas do motor por ela e as sete foram
        // rejeitadas. O motor grava mais de uma temporada no mesmo ano — por
        // empréstimo, e porque o modo rápido joga duas de uma vez —, então a
        // sequência real de uma carreira boa é 16,16,18,18,20,20...
        //
        // Quem limita quantas temporadas cabem numa carreira é o
        // temporadas_max lá em cima, que faz esse trabalho direto e sem
        // depender de como o motor numera os anos.
        if ($idadeAnterior !== null && $idade < $idadeAnterior)
            return auditarNao("idade andou pra trás na temporada {$i} ({$idadeAnterior} → {$idade})");
        $idadeAnterior = $idade;

        if ($jogos < 0 || $jogos > $L['jogos_max'])
            return auditarNao("jogos fora da régua na temporada {$i} ({$jogos})");

        $selJogos = (int)($t['selJogos'] ?? 0);
        if ($selJogos < 0 || $selJogos > $L['sel_jogos_max'])
            return auditarNao("jogos de seleção fora da régua na temporada {$i}");

        // Gol e assistência saem do número de JOGOS. Sem este teto, uma
        // temporada de 4 jogos podia trazer 300 gols e destravar tudo.
        $tetoGols = (int)ceil(max(1, $jogos + $selJogos) * $L['gols_por_jogo']) + 3;
        $tetoAst  = (int)ceil(max(1, $jogos + $selJogos) * $L['ast_por_jogo'])  + 3;
        if ($gols < 0 || $gols > $tetoGols) return auditarNao("gols demais pra {$jogos} jogos na temporada {$i} ({$gols})");
        if ($ast  < 0 || $ast  > $tetoAst)  return auditarNao("assistências demais pra {$jogos} jogos na temporada {$i} ({$ast})");

        if ($ovr < $L['ovr_min'] || $ovr > $L['ovr_max'])
            return auditarNao("overall fora da régua na temporada {$i} ({$ovr})");

        // O overall sobe e desce devagar. Um salto de 40 pra 99 entre dois
        // anos é a assinatura de um estado montado à mão.
        if ($ovrAnterior !== null && abs($ovr - $ovrAnterior) > $L['ovr_salto_max'])
            return auditarNao("salto de overall na temporada {$i} ({$ovrAnterior} → {$ovr})");
        $ovrAnterior = $ovr;

        $titulos = is_array($t['titulos'] ?? null) ? $t['titulos'] : [];
        if (count($titulos) > $L['titulos_ano'])
            return auditarNao("títulos demais numa temporada só ({$i})");

        if ((int)($t['valor'] ?? 0) < 0 || (int)($t['valor'] ?? 0) > $L['valor_max'])
            return auditarNao("valor de mercado fora da régua na temporada {$i}");

        $idadeMin = $idadeMin === null ? $idade : min($idadeMin, $idade);
        $idadeMax = $idadeMax === null ? $idade : max($idadeMax, $idade);
    }

    // TEMPORADAS CONTRA O INTERVALO DE IDADE. É o teto honesto: o motor pode
    // gravar mais de uma temporada por ano, mas não pode gravar vinte no
    // mesmo ano. Uma carreira de 16 a 41 cobre 26 anos e cabe em 78 linhas
    // com folga — a de 34 que apareceu passa de longe. O que isto barra é o
    // estado forjado que empilha temporada sem envelhecer, que é o jeito
    // barato de multiplicar troféu.
    $anos = ($idadeMax - $idadeMin) + 1;
    $tetoPeloTempo = $anos * $L['temporadas_por_ano'] + 3;
    if ($n > $tetoPeloTempo)
        return auditarNao("{$n} temporadas em {$anos} anos de carreira");

    // O overall NÃO começa alto. Todo mundo entra cru — a régua do jogo nasce
    // na casa dos 50 — e é isso que impede a carreira inteira de 99 que um
    // estado montado à mão descreve sem esforço.
    $ovrDeEstreia = (int)($temporadas[0]['ovr'] ?? 0);
    if ($ovrDeEstreia > 78)
        return auditarNao("estreia com {$ovrDeEstreia} de overall");

    return auditarOk();
}

/**
 * O estado do Caminho cabe no que o motor produz?
 *
 * Aqui pts/reb/ast são MÉDIA por jogo — é assim que o jogo guarda, e por isso
 * os tetos são pequenos comparados aos do Copero.
 */
function auditarCaminho(array $estado): array
{
    $L = AUDITOR_CAMINHO;
    $lista = is_array($estado['temporadas'] ?? null) ? $estado['temporadas'] : [];
    if (!$lista) return auditarNao('carreira sem temporadas');
    if (count($lista) > $L['temporadas_max']) return auditarNao('temporadas demais (' . count($lista) . ')');

    $idadeAnterior = null;
    $jogadas = 0;

    foreach ($lista as $i => $t) {
        if (!is_array($t)) return auditarNao("temporada {$i} não é um objeto");

        $idade = (int)($t['idade'] ?? 0);
        if ($idade < $L['idade_min'] || $idade > $L['idade_max'])
            return auditarNao("idade fora da régua na temporada {$i} ({$idade})");
        if ($idadeAnterior !== null) {
            $passo = $idade - $idadeAnterior;
            if ($passo < 1) return auditarNao("idade não avançou na temporada {$i}");
            if ($passo > 3) return auditarNao("buraco de {$passo} anos na temporada {$i}");
        }
        $idadeAnterior = $idade;

        // Ano de formação e ano perdido não têm números — e não contam como
        // temporada jogada em lugar nenhum.
        if (!empty($t['formacao']) || !empty($t['perdida'])) continue;
        $jogadas++;

        $jogos = (int)($t['jogos'] ?? 0);
        if ($jogos < 0 || $jogos > $L['jogos_max'])
            return auditarNao("jogos fora da régua na temporada {$i} ({$jogos})");

        foreach ([['pts', $L['pts_max']], ['reb', $L['reb_max']], ['ast', $L['ast_max']]] as [$k, $teto]) {
            $v = (float)($t[$k] ?? 0);
            if ($v < 0 || $v > $teto) return auditarNao("média de {$k} fora da régua na temporada {$i} ({$v})");
        }

        $ovr = (int)($t['ovr'] ?? 0);
        if ($ovr && ($ovr < $L['ovr_min'] || $ovr > $L['ovr_max']))
            return auditarNao("overall fora da régua na temporada {$i} ({$ovr})");

        $premios = is_array($t['premios'] ?? null) ? $t['premios'] : [];
        if (count($premios) > $L['premios_ano'])
            return auditarNao("prêmios demais numa temporada só ({$i})");
    }

    if ($jogadas === 0) return auditarNao('nenhuma temporada jogada');

    // Mesma regra do Copero: temporada contra o tempo que ela cobre.
    $primeira = null; $ultima = null;
    foreach ($lista as $t) {
        if (!is_array($t)) continue;
        $idade = (int)($t['idade'] ?? 0);
        if (!$idade) continue;
        $primeira = $primeira === null ? $idade : min($primeira, $idade);
        $ultima   = $ultima   === null ? $idade : max($ultima, $idade);
    }
    if ($primeira !== null) {
        $anos = ($ultima - $primeira) + 1;
        $teto = $anos * $L['temporadas_por_ano'] + 3;
        if (count($lista) > $teto)
            return auditarNao(count($lista) . " temporadas em {$anos} anos de carreira");
    }

    // Nenhum troféu pode passar do número de temporadas jogadas. É o mesmo
    // teto que caminhoLegado() já aplicava pro legado — aqui ele vale pra
    // recusar o estado inteiro, e não só pra achatar a nota.
    $trofeus = is_array($estado['trofeus'] ?? null) ? $estado['trofeus'] : [];
    foreach ($trofeus as $k => $v) {
        if (!is_numeric($v)) continue;
        if ((int)$v < 0 || (int)$v > $jogadas)
            return auditarNao("troféu '{$k}' ({$v}) passa das {$jogadas} temporadas jogadas");
    }

    return auditarOk();
}

// ═══════════════════════════════════════════════════════════════════════
// O FREIO DE RITMO
//
// O auditor acima barra a carreira impossível. Contra quem forja com
// cuidado — lendo o motor, respeitando cada teto — ele não faz nada, e
// nenhum validador de campo faria.
//
// O que sobra é o tempo. Uma carreira leva minutos pra ser jogada, e as
// conquistas de uma conta são finitas: quarenta e poucas em cada jogo. Um
// humano jogando não passa de um punhado de conquistas por hora; um script
// pediria todas em segundos. É essa diferença que o freio mede.
//
// Ele não impede a fraude — atrasa. Mas atrasar o suficiente é o que separa
// "dá pra zerar a economia numa tarde" de "não vale o trabalho".
// ═══════════════════════════════════════════════════════════════════════

/** Quantas conquistas uma conta pode registrar por hora, em cada jogo. */
const AUDITOR_TETO_POR_HORA = 12;

function auditarGarantirRegistro(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS carreira_pagamentos (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            jogo       VARCHAR(16) NOT NULL,
            quantidade SMALLINT NOT NULL DEFAULT 0,
            criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_carreira_pag (id_usuario, jogo, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[auditor] tabela de ritmo: ' . $e->getMessage());
    }
}

/**
 * Quantas conquistas ainda cabem nesta hora pra esta conta neste jogo.
 *
 * Devolve o quanto ainda dá pra pagar. Zero significa "volte daqui a pouco",
 * e não "você trapaceou" — por isso o texto que sobe pra tela fala de ritmo,
 * não de fraude: quem bater nisso jogando de verdade merece uma explicação,
 * não uma acusação.
 */
function auditarQuantoCabe(PDO $pdo, int $idUsuario, string $jogo): int
{
    if ($idUsuario <= 0) return 0;
    auditarGarantirRegistro($pdo);
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(quantidade),0) FROM carreira_pagamentos
                             WHERE id_usuario = ? AND jogo = ? AND criado_em > (NOW() - INTERVAL 1 HOUR)");
        $st->execute([$idUsuario, $jogo]);
        $jaFoi = (int)$st->fetchColumn();
        return max(0, AUDITOR_TETO_POR_HORA - $jaFoi);
    } catch (Throwable $e) {
        // Falha de banco não pode travar o pagamento de quem está jogando:
        // o freio é uma trava contra abuso, não uma condição pra jogar.
        error_log('[auditor] quanto cabe: ' . $e->getMessage());
        return AUDITOR_TETO_POR_HORA;
    }
}

/** Anota o que foi pago agora, pra contar na hora seguinte. */
function auditarAnotar(PDO $pdo, int $idUsuario, string $jogo, int $quantas): void
{
    if ($idUsuario <= 0 || $quantas <= 0) return;
    auditarGarantirRegistro($pdo);
    try {
        $pdo->prepare("INSERT INTO carreira_pagamentos (id_usuario, jogo, quantidade) VALUES (?,?,?)")
            ->execute([$idUsuario, $jogo, $quantas]);
    } catch (Throwable $e) {
        error_log('[auditor] anotar: ' . $e->getMessage());
    }
}

/**
 * Deixa registro de uma carreira recusada.
 *
 * Não é só higiene: é o único jeito de você DESCOBRIR que alguém tentou.
 * Sem esta linha no log, uma tentativa de fraude é indistinguível de um
 * jogador que não ganhou nada.
 */
function auditarRegistrarRecusa(string $jogo, int $idUsuario, string $motivo): void
{
    error_log(sprintf('[auditor] %s RECUSOU user_id=%d: %s', $jogo, $idUsuario, $motivo));
}
