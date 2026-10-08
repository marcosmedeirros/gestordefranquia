<?php
/**
 * FBA DRAFT — o FUT Draft da liga.
 *
 * Escolhe a formação, monta onze cartas escolhendo uma entre cinco por vaga,
 * e joga uma partida narrada minuto a minuto valendo moedas. Contra a
 * máquina ou contra o time de outro GM.
 *
 * ── AINDA NÃO ESTÁ NO HUB, E ISSO É DE PROPÓSITO ─────────────────────
 *
 * Pedido do Marcos em 07/10/2026: "não coloca lá ainda, somente por link".
 * Então não existe card em games/index.clean.php — quem tem o endereço
 * entra, o resto da liga não vê. Pra publicar depois, é só acrescentar o
 * card; nada aqui depende disso.
 *
 * ── O DRAFT MORA NA SESSÃO, O TIME MORA NO BANCO ─────────────────────
 *
 * Enquanto a pessoa escolhe, o estado é da sessão: são onze passos num
 * minuto, e gravar cada clique no banco seria onze escritas pra uma coisa
 * que pode ser abandonada no meio. Quando o time fica pronto, aí sim ele é
 * gravado — porque a partir daí ele enfrenta gente, aparece pros outros e
 * precisa sobreviver ao navegador fechar.
 *
 * @see games/core/draftfut.php           formações, cartas e química
 * @see games/core/draftfut_partida.php   a partida minuto a minuto
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../core/conexao.php';
require_once __DIR__ . '/../core/draftfut_partida.php';
require_once __DIR__ . '/../core/draftfut_duelo.php';
require_once __DIR__ . '/draftfut_carta.php';

if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];

/* ── O QUE CUSTA E O QUE PAGA ────────────────────────────────────────
   A entrada existe pra a escolha ter peso: draft de graça vira reroll até
   sair o time perfeito. O prêmio por vitória cobre a entrada com folga, e
   o empate devolve quase tudo — perder um draft bem montado num 1x1 não
   pode doer mais que não ter jogado. */
/* ENTRADA SIMBÓLICA. Pedido do Marcos (08/10/2026): "a cobrança por partida
   contra o bot, coloca 10 moedas apenas, simbolico". Ela não existe mais pra
   doer, e sim pra o draft não ser de graça — sem custo nenhum vira reroll
   até sair o time perfeito. */
const DF_ENTRADA = 10;
const DF_VITORIA = 150;
const DF_EMPATE  = 40;

function dfTabelas(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS draftfut_times (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        nome VARCHAR(60) NOT NULL,
        formacao VARCHAR(12) NOT NULL,
        time_json TEXT NOT NULL,
        forca INT NOT NULL,
        quimica INT NOT NULL,
        vitorias INT NOT NULL DEFAULT 0,
        empates INT NOT NULL DEFAULT 0,
        derrotas INT NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_df_user (id_usuario),
        INDEX idx_df_forca (forca)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS draftfut_partidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        time_casa INT NOT NULL,
        time_fora INT NULL,
        fora_json TEXT NULL,
        semente INT NOT NULL,
        gols_casa INT NOT NULL,
        gols_fora INT NOT NULL,
        moedas INT NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dfp_casa (time_casa)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
dfTabelas($pdo);

$msg = null; $erro = null;
$d = &$_SESSION['draftfut'];

/* ── OS CINCO ESQUEMAS DA MESA ────────────────────────────────────────
   São sorteados de DFUT_FORMACOES (treze), e a semente VIVE NA SESSÃO: a
   tela é desenhada de novo a cada F5, e sorteando na hora a lista mudaria
   embaixo de quem está decidindo. Mesma disciplina das cinco cartas de cada
   vaga. A semente só troca quando um draft acaba. */
if (!isset($_SESSION['draftfut_formacoes'])) {
    $_SESSION['draftfut_formacoes'] = random_int(1, 2000000000);
}
$formacoesNaMesa = draftFutFormacoesSorteadas((int)$_SESSION['draftfut_formacoes']);

/* ── O DUELO EM QUE ESTE GM ESTÁ ──────────────────────────────────────
   Carregado antes das ações porque quase toda ação depende dele: começar um
   draft dentro do duelo não cobra entrada (a aposta já saiu), e terminar não
   joga contra a máquina, fecha o lado e espera o outro. */
dfdTabelas($pdo);
$duelo = dfdMeuDuelo($pdo, $user_id);

/* O duelo que acabou continua na tela de CADA UM dos dois até fechar. A
   marca é no banco, por lado (@see dfdResultadoPendente): guardá-la na sessão
   mostrava o placar só pra quem disparou a partida, e pro outro GM o duelo
   simplesmente sumia. */
$dueloFim = $duelo ? null : dfdResultadoPendente($pdo, $user_id);

/* CONVITE DE REVANCHE: o outro já pediu, e aqui ele aparece como convite.
   A aposta de quem foi chamado só sai quando ele aceita — descontar de quem
   não clicou em nada é cobrar sem pedir. */
$convite = $duelo ? null : dfdConvitePendente($pdo, $user_id);

/* Qual tela o GM pediu: o salão, o modo bot ou o multiplayer. */
$vista = (string)($_GET['v'] ?? $_POST['v'] ?? '');

/* O NOME DO TIME VEM DA FRANQUIA DO GM ("Las Vegas Coyotes"), não de um campo
   pra digitar. Digitar o nome do próprio time a cada draft é trabalho sem
   graça — e abria espaço pra um "asdf" aparecer no ranking da liga. */
$nomeDoTime = dfdNomeDoTime($pdo, $user_id, (string)($_SESSION['user_name'] ?? ''));

/* ═══════════════════════════ AÇÕES ══════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    /* ── CRIAR DUELO ─────────────────────────────────────────────────
       A aposta sai AGORA, não no fim: cobrar só no fim deixaria o perdedor
       sem saldo e a dívida sem cobrança. O esquema é sorteado por duelo, e a
       semente guardada — é ela que faz os dois verem a mesma partida e
       receberem a mesma mão de cinco esquemas. */
    if ($acao === 'duelo_criar') {
        /* VALOR LIVRE, e não só os cinco atalhos. Pedido do Marcos
           (08/10/2026): "deixe a pessoa escolher o valor, alem dos que tem
           la, tipo valor outro e a pessoa que coloca, sem limitação". Os
           botões ficam porque são o caminho rápido; o campo é pra quem quer
           apostar os 1.337 que tem na conta.

           O único teto é o saldo, e esse não é limitação minha: é a conta do
           GM. O piso é 1 — aposta de zero não é aposta, e negativo seria um
           jeito de imprimir moeda. */
        $aposta = (int)($_POST['aposta'] ?? 0);
        $saldo  = dfMoedas($pdo, $user_id);
        if ($duelo) {
            $erro = 'Você já está num duelo. Termine ou cancele antes de abrir outro.';
        } elseif ($aposta < 1) {
            $erro = 'A aposta tem que ser de pelo menos 1 moeda.';
        } elseif ($saldo < $aposta) {
            $erro = 'Você tem ' . $saldo . ' moedas e quis apostar ' . $aposta . '.';
        } else {
            try {
                $codigo = dfdGerarCodigo($pdo);
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO draftfut_duelos (codigo, id_criador, aposta, semente)
                               VALUES (?,?,?,?)')
                    ->execute([$codigo, $user_id, $aposta, random_int(1, 2000000000)]);
                dfMoedasMexer($pdo, $user_id, -$aposta);
                $pdo->commit();
                $duelo = dfdPorCodigo($pdo, $codigo);
                $msg = 'Duelo aberto! Manda o código ' . $codigo . ' pro seu adversário.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $erro = 'Não consegui abrir o duelo. Tente de novo.';
                error_log('[draftfut] criar duelo: ' . $e->getMessage());
            }
        }
    }

    /* ── ENTRAR NUM DUELO ───────────────────────────────────────────── */
    if ($acao === 'duelo_entrar') {
        $cod = strtoupper(trim((string)($_POST['codigo'] ?? '')));
        $alvo = $cod === '' ? null : dfdPorCodigo($pdo, $cod);
        if ($duelo) {
            $erro = 'Você já está num duelo.';
        } elseif (!$alvo) {
            $erro = 'Não achei duelo com esse código.';
        } elseif ((int)$alvo['id_criador'] === $user_id) {
            $erro = 'Esse duelo é o seu — manda o código pra outra pessoa.';
        } elseif ($alvo['id_desafiado'] !== null) {
            $erro = 'Esse duelo já tem adversário.';
        } elseif (dfMoedas($pdo, $user_id) < (int)$alvo['aposta']) {
            $erro = 'A aposta desse duelo é ' . (int)$alvo['aposta'] . ' moedas e você não tem.';
        } else {
            $pdo->beginTransaction();
            try {
                /* A TRANCA É O UPDATE CONDICIONAL: dois GMs digitando o mesmo
                   código ao mesmo tempo entram aqui juntos, e só um sai com
                   linha afetada. Sem isso, os dois pagariam a aposta e um
                   ficaria de fora do duelo que pagou. */
                $up = $pdo->prepare("UPDATE draftfut_duelos
                                        SET id_desafiado = ?, status = 'montando', entrou_em = NOW()
                                      WHERE id = ? AND id_desafiado IS NULL");
                $up->execute([$user_id, (int)$alvo['id']]);
                if ($up->rowCount() !== 1) throw new RuntimeException('duelo tomado');
                dfMoedasMexer($pdo, $user_id, -(int)$alvo['aposta']);
                $pdo->commit();
                $duelo = dfdPorCodigo($pdo, $cod);
                $msg = 'Você entrou no duelo! Monte o seu time.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $erro = 'Alguém entrou nesse duelo antes de você.';
            }
        }
    }

    /* ── PEDIR REVANCHE ─────────────────────────────────────────────── */
    /* Saldo lido aqui, e não o $moedas lá embaixo: ele só é calculado depois
       das ações, e comparar aposta com um valor que ainda não existe deixava
       passar qualquer convite. */
    $moedasAgora = dfMoedas($pdo, $user_id);

    if ($acao === 'duelo_revanche') {
        $antes = null;
        $id = (int)($_POST['duelo'] ?? 0);
        if ($id) {
            $st = $pdo->prepare('SELECT * FROM draftfut_duelos WHERE id = ?');
            $st->execute([$id]);
            $antes = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$antes) {
            $erro = 'Não achei o duelo pra revanche.';
        } elseif (($novo = dfdRevanche($pdo, $antes, $user_id)) === null) {
            $erro = 'Não deu pra abrir a revanche — confira se você tem a aposta '
                  . 'e se nenhum dos dois está em outro duelo.';
        } else {
            /* O resultado antigo fica visto: a revanche é a página seguinte. */
            dfdMarcarVisto($pdo, (int)$antes['id'], dfdLado($antes, $user_id));
            $duelo = $novo; $dueloFim = null; $convite = null; $d = null;
            /* Se o outro já tinha clicado, este clique FECHOU a dupla e o
               duelo já está de pé — a mensagem tem que dizer isso, e não
               mandar esperar alguém que já está montando o time. */
            $msg = $novo['status'] === 'montando'
                 ? 'Revanche fechada! Os dois querem — monte o seu time.'
                 : 'Revanche aberta por ' . (int)$novo['aposta'] . ' moedas. '
                   . 'Seu adversário já foi avisado.';
        }
    }

    /* ── ACEITAR A REVANCHE ─────────────────────────────────────────── */
    if ($acao === 'duelo_aceitar') {
        if (!$convite) {
            $erro = 'Esse convite não está mais de pé.';
        } elseif ($moedasAgora < (int)$convite['aposta']) {
            $erro = 'A revanche é por ' . (int)$convite['aposta'] . ' moedas e você não tem.';
        } elseif (($novo = dfdEntrarNoConvite($pdo, $convite, $user_id)) === null) {
            $erro = 'Esse convite não está mais de pé.';
        } else {
            /* O placar anterior fica visto: a partida nova é a página seguinte. */
            if ($dueloFim) {
                dfdMarcarVisto($pdo, (int)$dueloFim['id'], dfdLado($dueloFim, $user_id));
                $dueloFim = null;
            }
            $duelo = $novo; $convite = null; $d = null;
            $msg = 'Revanche fechada! Monte o seu time.';
        }
    }

    /* ── RECUSAR ────────────────────────────────────────────────────── */
    if ($acao === 'duelo_recusar' && $convite) {
        /* Recusar devolve a aposta de quem chamou: ele pagou por uma partida
           que não vai acontecer. */
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare("DELETE FROM draftfut_duelos
                                  WHERE id = ? AND status = 'aguardando' AND id_desafiado IS NULL");
            $up->execute([(int)$convite['id']]);
            if ($up->rowCount() === 1) {
                dfMoedasMexer($pdo, (int)$convite['id_criador'], (int)$convite['aposta']);
            }
            $pdo->commit();
            $convite = null;
            $msg = 'Convite recusado. A aposta voltou pra quem chamou.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $erro = 'Não consegui recusar. Recarregue a página.';
        }
    }

    if ($acao === 'duelo_cancelar' && $duelo) {
        if (dfdCancelar($pdo, $duelo, $user_id)) {
            $duelo = null; $d = null;
            $msg = 'Duelo cancelado e aposta devolvida.';
        } else {
            $erro = 'Esse duelo já tem adversário — não dá mais pra cancelar.';
        }
    }

    if ($acao === 'duelo_fechar' && $dueloFim) {
        dfdMarcarVisto($pdo, (int)$dueloFim['id'], dfdLado($dueloFim, $user_id));
        $dueloFim = null;
    }

    if ($acao === 'comecar') {
        $formacao = (string)($_POST['formacao'] ?? '');
        /* Tem que estar entre os CINCO SORTEADOS, não só existir: senão
           bastava mandar o nome de outro esquema no POST pra escolher fora
           da mão que a pessoa recebeu. */
        if (!in_array($formacao, $formacoesNaMesa, true)) {
            $erro = 'Formação inválida.';
        } elseif (!$duelo && dfMoedas($pdo, $user_id) < DF_ENTRADA) {
            $erro = 'Você precisa de ' . DF_ENTRADA . ' moedas pra entrar no draft.';
        } else {
            /* DENTRO DO DUELO A ENTRADA NÃO É COBRADA: a aposta já saiu quando
               o duelo foi aberto ou aceito. Cobrar de novo seria cobrar duas
               vezes pela mesma partida. */
            if (!$duelo) dfMoedasMexer($pdo, $user_id, -DF_ENTRADA);
            $d = ['formacao' => $formacao, 'time' => array_fill(0, DFUT_TOTAL, null),
                  'usados' => [], 'aberta' => null, 'opcoes' => null, 'resultado' => null,
                  'duelo' => $duelo ? (int)$duelo['id'] : null];
            if ($duelo) dfdSalvarDraft($pdo, (int)$duelo['id'], dfdLado($duelo, $user_id), $d);
            $msg = $duelo
                ? 'Monte o seu time. O adversário monta o dele quando puder.'
                : 'Boa sorte! Entrada de ' . DF_ENTRADA . ' moedas paga.';
        }
    }

    /* ABRIR UMA VAGA é o que sorteia as cartas dela. O jogo não manda mais
       a ordem: quem escolhe por onde começar é quem está jogando, como no
       FIFA. As cartas ficam guardadas na sessão até a escolha, senão um F5
       daria cinco cartas novas e o draft viraria roleta. */
    if ($acao === 'abrir' && $d && empty($d['resultado'])) {
        $v = (int)($_POST['vaga'] ?? -1);
        if ($v >= 0 && $v < DFUT_TOTAL && empty($d['time'][$v])) {
            $d['aberta'] = $v;
            $preenchidas = count(array_filter($d['time']));
            [$lo, $hi] = draftFutFaixa($preenchidas);
            $d['opcoes'] = draftFutOpcoes(draftFutPosDaVaga($d['formacao'], $v), $d['usados'], $lo, $hi);
        }
    }

    if ($acao === 'escolher' && $d && $d['opcoes'] && $d['aberta'] !== null) {
        $i = (int)($_POST['carta'] ?? -1);
        if (isset($d['opcoes'][$i])) {
            $c = $d['opcoes'][$i];
            $d['time'][$d['aberta']] = $c;
            $d['usados'][] = $c['nome'];
            if ($duelo) dfdSalvarDraft($pdo, (int)$duelo['id'], dfdLado($duelo, $user_id), $d);
            $d['aberta'] = null;
            $d['opcoes'] = null;
        }
    }

    /* TROCAR DOIS DE LUGAR — é o escalar. Vale entre campo e banco também,
       que é como se faz substituição antes do jogo. A química é recalculada
       sozinha na hora de desenhar, então não há nada a atualizar aqui. */
    if ($acao === 'trocar' && $d && empty($d['resultado'])) {
        $a = (int)($_POST['a'] ?? -1); $b = (int)($_POST['b'] ?? -1);
        /* ── SÓ TROCA QUEM JÁ FOI ESCOLHIDO ──────────────────────────
           Pedido do Marcos (07/10/2026): "so deixe trocar de posicao se ja
           abriu a cartinha". Antes, trocar com uma vaga vazia MUDAVA O
           JOGADOR DE LUGAR sem abrir a vaga — a pessoa ficava com um buraco
           onde a carta tinha sido sorteada e um jogador numa vaga que ela
           nunca pagou. A conferência é aqui no servidor, não só no clique:
           o JS é conveniência, não tranca. */
        if ($a >= 0 && $b >= 0 && $a < DFUT_TOTAL && $b < DFUT_TOTAL && $a !== $b
            && !empty($d['time'][$a]) && !empty($d['time'][$b])) {
            $tmp = $d['time'][$a]; $d['time'][$a] = $d['time'][$b]; $d['time'][$b] = $tmp;
            if ($duelo) dfdSalvarDraft($pdo, (int)$duelo['id'], dfdLado($duelo, $user_id), $d);
        }
    }

    /* ── FECHAR O LADO DO DUELO ──────────────────────────────────────
       No duelo não se "joga": grava-se o time e espera. A partida roda
       sozinha quando o segundo lado fecha (@see dfdRodarSeDerPra), com a
       semente do duelo — é isso que faz os dois verem a mesma narração. */
    if ($acao === 'duelo_jogar' && $d && $duelo && draftFutCampoCheio($d)) {
        dfdFinalizarLado($pdo, $duelo, $user_id, $nomeDoTime, $d);
        $st = $pdo->prepare('SELECT * FROM draftfut_duelos WHERE id = ?');
        $st->execute([(int)$duelo['id']]);
        $duelo = $st->fetch(PDO::FETCH_ASSOC) ?: $duelo;
        $duelo = dfdRodarSeDerPra($pdo, $duelo);
        $d = null;
        if ($duelo['status'] === 'concluido') {
            $dueloFim = $duelo; $duelo = null;
            $msg = 'Os dois times ficaram prontos — a partida saiu!';
        } else {
            $msg = 'Time guardado. Falta o adversário terminar o dele.';
        }
    }

    if ($acao === 'jogar' && $d && draftFutCampoCheio($d)) {
        $formacao = $d['formacao'];
        $time = $d['time'];
        $forca = draftFutForcaDoTime($formacao, $time);
        $quim  = draftFutQuimica($formacao, $time)['total'];

        /* O NOME VEM DA FRANQUIA, não de um campo digitado. @see dfdNomeDoTime */
        $nome = $nomeDoTime;
        $pdo->prepare('INSERT INTO draftfut_times (id_usuario, nome, formacao, time_json, forca, quimica)
                       VALUES (?,?,?,?,?,?)')
            ->execute([$user_id, mb_substr($nome, 0, 60), $formacao,
                       json_encode($time, JSON_UNESCAPED_UNICODE), $forca, $quim]);
        $meuId = (int)$pdo->lastInsertId();

        /* O ADVERSÁRIO É SEMPRE A MÁQUINA, e um time novo a cada partida.
           Havia um modo que sorteava o time salvo de outro GM: ele mexia no
           cartel de alguém que não clicou em nada, e repetia de pior jeito o
           que o duelo faz direito. Saiu a pedido do Marcos (08/10/2026). */
        $modo = 'maquina';
        $advId = null;
        $semente = random_int(1, 2000000000);
        /* O bot não repete quem já está no meu time. @see a função */
        $adv = draftFutAdversarioDaMaquina($forca, $semente,
                                           array_column(array_filter($time), 'nome'));

        $p = draftFutPartida(['nome' => $nome, 'formacao' => $formacao, 'time' => $time, 'forca' => $forca],
                             $adv, $semente);

        $premio = $p['placar'][0] > $p['placar'][1] ? DF_VITORIA
                : ($p['placar'][0] === $p['placar'][1] ? DF_EMPATE : 0);
        if ($premio) dfMoedasMexer($pdo, $user_id, $premio);

        $col = $p['placar'][0] > $p['placar'][1] ? 'vitorias'
             : ($p['placar'][0] === $p['placar'][1] ? 'empates' : 'derrotas');
        $pdo->prepare("UPDATE draftfut_times SET $col = $col + 1 WHERE id = ?")->execute([$meuId]);

        $pdo->prepare('INSERT INTO draftfut_partidas (time_casa, time_fora, fora_json, semente, gols_casa, gols_fora, moedas)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([$meuId, $advId, $advId ? null : json_encode($adv, JSON_UNESCAPED_UNICODE),
                       $semente, $p['placar'][0], $p['placar'][1], $premio]);

        $d['resultado'] = ['partida' => $p, 'adv' => $adv, 'modo' => $modo,
                           'premio' => $premio, 'forca' => $forca, 'quimica' => $quim, 'nome' => $nome,
                           /* O escudo da franquia do GM de um lado, o do clube do bot
                              do outro (@see dfdEscudoDoTime, draftFutAdversarioDaMaquina). */
                           'escudo' => dfdEscudoDoTime($pdo, $user_id),
                           /* O TIME INTEIRO VAI JUNTO, dos dois lados: é o que
                              permite mostrar as escalações enquanto a partida
                              corre. O adversário já vem completo em $adv. */
                           'formacao' => $formacao, 'time' => $time];
    }

    /* ── DESISTIR DO DRAFT ───────────────────────────────────────────
       Pedido do Marcos (08/10/2026): "coloque a opção, desistir draft... o
       desistir cancela o draft e volta pra tela inicial do jogo".

       A entrada NÃO volta: ela foi paga pra jogar, e devolver faria o draft
       virar uma roleta de graça — abre, não gostou das cartas, desiste, abre
       de novo. A pergunta no popup diz isso antes de a pessoa clicar.

       DENTRO DE UM DUELO é outra conversa, e tem três casos:
         · ninguém entrou ainda  -> cancela e devolve a aposta;
         · o adversário está dentro mas nenhum dos dois fechou o time, ou eu
           ainda não fechei -> é W.O.: ele leva as duas apostas;
         · eu já fechei o meu time -> não há o que desistir, a partida sai
           sozinha quando ele terminar.
       Sem a regra do W.O., desistir no meio seria um jeito de segurar a
       aposta do outro pra sempre. */
    if ($acao === 'desistir') {
        if ($duelo) {
            $meuLado = dfdLado($duelo, $user_id);
            if ((int)$duelo['pronto_' . $meuLado]) {
                $erro = 'Seu time já está fechado — a partida sai quando o adversário terminar.';
            } elseif ($duelo['id_desafiado'] === null) {
                if (dfdCancelar($pdo, $duelo, $user_id)) {
                    $duelo = null; $d = null;
                    $msg = 'Duelo cancelado e aposta devolvida.';
                } else {
                    $erro = 'Não consegui cancelar. Recarregue a página.';
                }
            } elseif (dfdDesistir($pdo, $duelo, $user_id)) {
                $dueloFim = dfdResultadoPendente($pdo, $user_id);
                $duelo = null; $d = null;
                $msg = 'Você desistiu do duelo. A aposta foi pro seu adversário.';
            } else {
                $erro = 'Não consegui registrar a desistência. Recarregue a página.';
            }
        } else {
            $d = null;
            $_SESSION['draftfut_formacoes'] = random_int(1, 2000000000);
            $msg = 'Draft cancelado. A entrada de ' . DF_ENTRADA . ' moedas não volta.';
        }
    }

    if ($acao === 'novo') {
        /* Mão nova de esquemas junto com o draft novo. */
        $_SESSION['draftfut_formacoes'] = random_int(1, 2000000000); $d = null;

        /* ONDE O BOTÃO LARGA A PESSOA. Os dois botões do fim de jogo
           mandavam o mesmo 'novo' e caíam os dois no salão: quem clicava
           em "Novo draft" tinha que achar o caminho de volta sozinho.
           Agora quem pede draft novo cai na escolha de esquema. */
        if (($_POST['v'] ?? '') === 'bot') {
            header('Location: /games/games/draftfut.php?v=bot');
            exit;
        }
    }

    header('Location: /games/games/draftfut.php' . ($msg ? '?m=' . urlencode($msg) : ($erro ? '?e=' . urlencode($erro) : '')));
    exit;
}

$msg = $_GET['m'] ?? null;
$erro = $_GET['e'] ?? null;

/** Os onze de campo estão todos preenchidos? É o que libera jogar. */
function draftFutCampoCheio(array $d): bool
{
    for ($i = 0; $i < DFUT_VAGAS; $i++) if (empty($d['time'][$i])) return false;
    return true;
}

/* O DRAFT DO DUELO VOLTA DO BANCO quando a sessão não o tem: é o que
   permite fechar o navegador no meio e voltar onde parou — sem isso, "cada um
   monta no seu tempo" só valeria enquanto a aba ficasse aberta. */
if ($duelo && !$d) {
    $meuLado = dfdLado($duelo, $user_id);
    if (!(int)$duelo['pronto_' . $meuLado]) {
        $guardado = dfdLerDraft($duelo, $meuLado);
        if ($guardado && !empty($guardado['formacao'])) $d = $guardado;
    }
}

$moedas = dfMoedas($pdo, $user_id);


/* ── A TELA "TIME PRONTO" ESTAVA INALCANÇÁVEL ────────────────────────
   `$emDraft` vinha antes de `$pronto` na cadeia de `elseif` lá embaixo e
   cobria todo draft em andamento — inclusive o já terminado. Resultado: a
   pessoa preenchia as 19 vagas e ficava olhando o campinho, sem botão de
   jogar. Achado jogando um draft inteiro por POST, não por leitura.

   Pronto é com TUDO escolhido, banco incluído: o FUT Draft também faz
   escolher os reservas, e é pra isso que eles existem aqui — entrar no time
   por troca antes do apito. */
$tudoCheio = $d && !empty($d['time'])
             && count(array_filter(array_slice($d['time'], 0, DFUT_TOTAL))) >= DFUT_TOTAL;
$emDraft = $d && empty($d['resultado']) && !$tudoCheio;
$pronto  = $d && $tudoCheio && empty($d['resultado']);
$fim     = $d && !empty($d['resultado']);
$quim = $d ? draftFutQuimica($d['formacao'], $d['time']) : ['total' => 0, 'jogadores' => []];
$aberta = $d['aberta'] ?? null;

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FBA Draft</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Oswald:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
:root{
  --bg:#07070a; --panel:#101013; --panel2:#16161a; --panel3:#1c1c21;
  --borda:rgba(255,255,255,.08); --borda2:rgba(255,255,255,.14);
  --txt:#f0f0f3; --txt2:#9a9aa4; --txt3:#70707a;
  --verde:#22c55e; --amarelo:#f5c518; --vermelho:#fc0025; --azul:#3b82f6;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font-family:'Montserrat',sans-serif;padding-bottom:50px}
a{color:inherit}
.topo{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
  padding:14px 18px;border-bottom:1px solid var(--borda);background:var(--panel)}
.topo h1{margin:0;font-family:'Oswald',sans-serif;font-size:22px;letter-spacing:.5px}
.topo h1 span{color:var(--vermelho)}
.saldo{background:var(--panel3);border:1px solid var(--borda2);border-radius:999px;
  padding:6px 14px;font-weight:700;font-size:13px}
.saldo i{color:var(--amarelo)}
.wrap{max-width:1040px;margin:0 auto;padding:18px}
.aviso{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:13.5px;font-weight:600}
.aviso.ok{background:rgba(34,197,94,.12);color:var(--verde);border:1px solid rgba(34,197,94,.3)}
.aviso.err{background:rgba(252,0,37,.12);color:#ff5c7a;border:1px solid rgba(252,0,37,.3)}
.bloco{background:var(--panel);border:1px solid var(--borda);border-radius:14px;padding:18px;margin-bottom:16px}
.bloco h2{margin:0 0 4px;font-family:'Oswald',sans-serif;font-size:19px;letter-spacing:.4px}
.bloco p.sub{margin:0 0 14px;color:var(--txt2);font-size:13.5px}
.btn{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--borda2);background:var(--panel3);
  color:var(--txt);font:inherit;font-weight:700;font-size:13px;border-radius:10px;padding:10px 16px;cursor:pointer}
.btn:hover{border-color:var(--vermelho)}
.btn.pri{background:var(--vermelho);border-color:var(--vermelho);color:#fff}
.btn:disabled{opacity:.45;cursor:not-allowed}

/* O popup do jogo */
.pop{position:fixed;inset:0;background:rgba(0,0,0,.72);display:flex;align-items:center;
  justify-content:center;padding:20px;z-index:90}
.pop[hidden]{display:none}
.pop-caixa{background:var(--panel);border:1px solid var(--borda2);border-radius:14px;
  padding:20px;max-width:380px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.6)}
.pop-caixa p{margin:0 0 16px;font-size:14px;line-height:1.5;color:var(--txt)}
.pop-acoes{display:flex;gap:9px;justify-content:flex-end;flex-wrap:wrap}

/* Desistir fica longe dos botões de jogar, e discreto: é saída, não ação. */
.fim-acoes{display:flex;gap:9px;justify-content:center;flex-wrap:wrap}
.desistir{display:flex;justify-content:center;margin:-4px 0 16px}
.desistir button{background:none;border:0;color:var(--txt3);font:inherit;font-size:12.5px;
  cursor:pointer;padding:6px 10px;border-radius:8px;display:inline-flex;align-items:center;gap:6px}
.desistir button:hover{color:#ff5c7a;background:rgba(252,0,37,.08)}

/* ── O SALÃO, O MULTIPLAYER E O DUELO ────────────────────────────── */
.salao-topo{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.salao-topo h2{margin:0}
.salao-h3{margin:18px 0 6px;font-family:'Oswald',sans-serif;font-size:15px;letter-spacing:.4px}

.modos{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:4px}
.modo-card{display:flex;flex-direction:column;gap:5px;background:var(--panel2);
  border:1px solid var(--borda);border-radius:12px;padding:16px;text-decoration:none;color:var(--txt);
  transition:border-color .15s,transform .15s}
.modo-card:hover{border-color:var(--vermelho);transform:translateY(-2px)}
.modo-card i{font-size:22px;color:var(--amarelo)}
.modo-card b{font-family:'Oswald',sans-serif;font-size:18px;letter-spacing:.5px}
.modo-card small{color:var(--txt3);font-size:11.5px;line-height:1.4}

.apostas{display:grid;grid-template-columns:repeat(auto-fit,minmax(86px,1fr));gap:9px}
.aposta-card{background:var(--panel2);border:1px solid var(--borda);border-radius:11px;padding:12px 8px;
  cursor:pointer;font:inherit;color:var(--txt);display:flex;flex-direction:column;gap:1px;align-items:center}
.aposta-card:hover:not(:disabled){border-color:var(--amarelo)}
.aposta-card:disabled{opacity:.4;cursor:not-allowed}
.aposta-card b{font-family:'Oswald',sans-serif;font-size:21px;color:var(--amarelo)}
.aposta-card small{color:var(--txt3);font-size:10.5px}
/* O "outro valor" ocupa a linha inteira embaixo dos atalhos: é um campo,
   não mais um cartão, e espremido na grade de 86px não caberia o número. */
.aposta-outro{grid-column:1/-1;display:flex;gap:9px;align-items:center;flex-wrap:wrap;
  background:var(--panel2);border:1px dashed var(--borda2);border-radius:11px;padding:10px 12px}
.aposta-outro label{color:var(--txt2);font-size:12.5px;font-weight:600}
.aposta-outro input{flex:1;min-width:100px;background:var(--panel3);border:1px solid var(--borda2);
  border-radius:9px;color:var(--txt);padding:9px 11px;font:inherit;font-size:14px;
  font-family:'Oswald',sans-serif}
.aposta-outro input:focus{outline:none;border-color:var(--amarelo)}

.entrar-cod{display:flex;gap:9px;flex-wrap:wrap;align-items:center}
.entrar-cod input{background:var(--panel3);border:1px solid var(--borda2);border-radius:10px;
  color:var(--txt);padding:11px 14px;font:inherit;font-size:19px;font-family:'Oswald',sans-serif;
  letter-spacing:4px;text-transform:uppercase;width:160px;text-align:center}

.codigo-caixa{display:flex;flex-direction:column;align-items:center;gap:4px;padding:20px 14px;
  background:var(--panel3);border:1px dashed var(--borda2);border-radius:14px}
.codigo-caixa .cod{font-family:'Oswald',sans-serif;font-size:40px;letter-spacing:9px;
  color:var(--amarelo);line-height:1}
.codigo-caixa .cod-sub{color:var(--txt3);font-size:12.5px}
.duelo-acoes{display:flex;gap:9px;margin-top:13px;flex-wrap:wrap}

.duelo-lados{display:flex;align-items:center;gap:12px;margin-top:6px}
.dlado{flex:1;min-width:0;background:var(--panel2);border:1px solid var(--borda);border-radius:11px;
  padding:11px 13px;display:flex;flex-direction:column;gap:2px}
.dlado.ok{border-color:var(--verde);background:rgba(34,197,94,.08)}
.dlado b{font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dlado small{color:var(--txt3);font-size:11.5px}
.dvs{font-family:'Oswald',sans-serif;font-size:17px;color:var(--txt3);flex:0 0 auto}

.duelo-fim{display:flex;flex-direction:column;align-items:center;gap:5px;padding:22px 14px;
  border-radius:14px;border:1px solid var(--borda2);background:var(--panel3)}
.duelo-fim.ganhou{border-color:rgba(34,197,94,.45);background:rgba(34,197,94,.1)}
.duelo-fim.perdeu{border-color:rgba(252,0,37,.4);background:rgba(252,0,37,.08)}
.duelo-fim .df-rot{font-family:'Oswald',sans-serif;font-size:17px;letter-spacing:.5px}
.duelo-fim .df-placar{font-family:'Oswald',sans-serif;font-size:46px;line-height:1}
.duelo-fim .df-placar i{color:var(--txt3);font-style:normal;font-size:28px}
.duelo-fim .df-times{color:var(--txt2);font-size:13px;text-align:center}
.duelo-fim .df-times i{color:var(--txt3);font-style:normal}
.duelo-fim .df-premio{margin-top:3px;font-weight:700;font-size:13px;color:var(--amarelo)}

.rank-lista{display:flex;flex-direction:column;gap:5px}
.rank-l{display:grid;grid-template-columns:26px 1fr auto;gap:10px;align-items:center;
  background:var(--panel2);border:1px solid var(--borda);border-radius:9px;padding:8px 11px;font-size:13px}
.rank-l.eu{border-color:var(--vermelho);background:rgba(252,0,37,.07)}
.rank-l .rk-pos{text-align:center;font-weight:800;color:var(--txt3);font-size:12px}
.rank-l:nth-child(1) .rk-pos{color:#f5c542}
.rank-l .rk-gm{font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rank-l .rk-nums{color:var(--txt2);font-size:12px;white-space:nowrap}
.rank-l .rk-nums b{color:var(--verde);font-size:14px}
.rank-l .rk-nums small{color:var(--txt3);margin-left:6px}

.confrontos{display:flex;flex-direction:column;gap:5px}
.conf-l{display:grid;grid-template-columns:1fr auto 1fr auto;gap:10px;align-items:center;
  background:var(--panel2);border:1px solid var(--borda);border-radius:9px;padding:8px 11px;font-size:12.5px}
.conf-l .cf-time{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--txt2)}
.conf-l .cf-time:last-of-type{text-align:right}
.conf-l .cf-time.venceu{color:var(--txt);font-weight:700}
.conf-l .cf-time small{color:var(--txt3);margin-left:5px}
.conf-l .cf-placar{font-family:'Oswald',sans-serif;font-size:16px;white-space:nowrap}
.conf-l .cf-placar i{color:var(--txt3);font-style:normal;font-size:12px}
.conf-l .cf-peso{font-size:11px;color:var(--txt3);font-weight:700}
@media (max-width:620px){
  .conf-l{grid-template-columns:1fr auto 1fr;row-gap:2px}
  .conf-l .cf-peso{display:none}
  .entrar-cod input{width:100%}
  /* NOME EM CIMA, NÚMEROS EMBAIXO. Lado a lado num telefone sobrava meia
     linha pro nome, e "Las Vegas Coyotes" virava "Las Vegas C..." — o
     ranking existe justamente pra ler o nome de quem fez. */
  .rank-l{grid-template-columns:26px 1fr}
  .rank-l .rk-nums{grid-column:2;white-space:normal}
}

/* Formações */
.forms{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.form-card{background:var(--panel2);border:1px solid var(--borda);border-radius:12px;padding:14px;
  text-align:center;cursor:pointer;font:inherit;color:var(--txt)}
.form-card:hover{border-color:var(--vermelho)}
.form-card b{display:block;font-family:'Oswald',sans-serif;font-size:21px;letter-spacing:1px}
.form-card small{color:var(--txt3);font-size:11.5px}

/* ── A CARTA ──────────────────────────────────────────────────────────
   Um desenho só, dois tamanhos: a grande na escolha, a `dfc-mini` no
   campinho. O desenho mora em games/games/draftfut_carta.php. */
.cartas{display:grid;grid-template-columns:repeat(auto-fill,minmax(124px,1fr));gap:11px}
@media (min-width:700px){.cartas{grid-template-columns:repeat(5,1fr);gap:14px}}

.carta-btn{display:block;padding:0;border:0;background:none;font:inherit;color:inherit;
  cursor:pointer;border-radius:13px;transition:transform .16s}
.carta-btn:hover{transform:translateY(-6px)}
.carta-btn:focus-visible{outline:2px solid var(--amarelo);outline-offset:3px}

/* ALTURA FIXA, que era o bug: `aspect-ratio` mais três faixas de grade, e o
   nome comprido é cortado em vez de empurrar a carta pra baixo. */
.dfc{position:relative;display:grid;grid-template-rows:auto 1fr auto;
  aspect-ratio:11/15;border-radius:13px;overflow:hidden;box-sizing:border-box;
  padding:8px 8px 7px;text-align:left;
  color:var(--dfc-tx);background:var(--dfc-bg);
  box-shadow:inset 0 0 0 1px var(--dfc-bd),inset 0 1px 0 rgba(255,255,255,.3),
             0 6px 15px rgba(0,0,0,.4)}
.carta-btn:hover .dfc{box-shadow:inset 0 0 0 1px var(--dfc-bd),inset 0 1px 0 rgba(255,255,255,.4),
             0 14px 26px rgba(0,0,0,.55)}

/* CADA TIPO TEM O SEU DESENHO, e é só este bloco que define isso — uma
   coleção nova é um seletor a mais aqui, nada no resto do jogo muda. */
.dfc-bronze{--dfc-bg:linear-gradient(157deg,#a9754a 0%,#6d4725 46%,#3c2513 100%);
  --dfc-tx:#f8e7d2;--dfc-bd:rgba(255,214,170,.55);--dfc-ac:#ffd9ad;--dfc-lin:rgba(0,0,0,.3)}
.dfc-prata{--dfc-bg:linear-gradient(157deg,#dde5eb 0%,#929ba3 46%,#4d545a 100%);
  --dfc-tx:#15191c;--dfc-bd:rgba(255,255,255,.75);--dfc-ac:#16202a;--dfc-lin:rgba(0,0,0,.22)}
.dfc-ouro{--dfc-bg:linear-gradient(157deg,#f7dd84 0%,#d6ab38 46%,#8a6316 100%);
  --dfc-tx:#2b2005;--dfc-bd:rgba(255,238,176,.8);--dfc-ac:#3a2a06;--dfc-lin:rgba(0,0,0,.24)}
/* ÍCONE é branco-prata com brilho no alto, como o do EA FC. */
/* FUTURO: o ciano da promoção de garoto, com o fio claro em cima. */
.dfc-futuro{--dfc-bg:linear-gradient(157deg,#5ef0e0 0%,#15a9c9 44%,#0b3d78 100%);
  --dfc-tx:#04222e;--dfc-bd:rgba(180,255,250,.8);--dfc-ac:#04222e;--dfc-lin:rgba(0,0,0,.22)}
.dfc-futuro .dfc-selo{background:#04222e;color:#5ef0e0}
/* TIME DA SEMANA: o preto da promoção, com fio dourado. */
.dfc-totw{--dfc-bg:linear-gradient(157deg,#3a3f46 0%,#1b1e23 44%,#0a0c0e 100%);
  --dfc-tx:#f3f6f9;--dfc-bd:rgba(245,197,24,.62);--dfc-ac:#f5c518;--dfc-lin:rgba(255,255,255,.14)}
.dfc-totw .dfc-selo{background:#f5c518;color:#141414}
.dfc-icone{--dfc-bg:radial-gradient(125% 92% at 50% -8%,#fff 0%,#eef3f7 32%,#b9c4cf 66%,#6d7883 100%);
  --dfc-tx:#11161b;--dfc-bd:#fff;--dfc-ac:#0d1217;--dfc-lin:rgba(0,0,0,.2)}
/* HERÓI é o rosa-roxo chapado da promoção. */
.dfc-heroi{--dfc-bg:linear-gradient(152deg,#ff2f7d 0%,#bd1684 44%,#4a0f61 100%);
  --dfc-tx:#fff;--dfc-bd:rgba(255,150,200,.75);--dfc-ac:#ffe14d;--dfc-lin:rgba(0,0,0,.3)}

/* A TARJA DA COLEÇÃO VAI DE PONTA A PONTA. Como fitinha centrada, "TIME DA
   SEMANA" crescia até cobrir a bandeira; de ponta a ponta ela não disputa
   espaço com nada, e `dfc-selado` abre a altura dela lá em cima. */
.dfc-selo{position:absolute;top:0;left:0;right:0;z-index:3;text-align:center;
  font-size:8px;font-weight:900;letter-spacing:1.4px;padding:3px 5px 4px;
  background:#10151a;color:#fff}
.dfc-selado{padding-top:22px}
.dfc-heroi .dfc-selo{background:#ffe14d;color:#49093a}
.dfc-selo-mini{position:absolute;top:0;right:0;z-index:3;width:0;height:0;
  border-top:13px solid var(--dfc-ac);border-left:13px solid transparent}

.dfc-topo{display:flex;align-items:flex-start;justify-content:space-between;gap:4px;z-index:1}
.dfc-id{display:flex;flex-direction:column;min-width:0}
.dfc-ovr{font-family:'Oswald',sans-serif;font-weight:600;font-size:29px;line-height:.92;
  letter-spacing:-.5px}
/* A POSIÇÃO AGORA SE LÊ: era 10.5px cinza em fundo escuro, virou negrito na
   cor da carta com espaçamento — foi reclamação direta do Marcos. */
.dfc-pos{font-size:11px;font-weight:800;letter-spacing:1.4px;margin-top:3px;opacity:.85;
  white-space:nowrap}
.dfc-pos i{font-size:9px;margin-left:3px;color:#ff2d55;opacity:1}
.dfc-insig{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex:0 0 auto}
.dfc-band{width:23px;height:16px;object-fit:cover;border-radius:2px;
  box-shadow:0 0 0 1px rgba(0,0,0,.35)}
.dfc-esc{width:23px;height:23px;object-fit:contain}

.dfc-retrato{position:relative;display:flex;align-items:flex-end;justify-content:center;
  min-height:0;overflow:hidden}
.dfc-foto{max-width:112%;max-height:100%;object-fit:contain;
  filter:drop-shadow(0 4px 7px rgba(0,0,0,.45))}
/* RETRATO QUADRADO VIRA MEDALHÃO. O recorte das lendas recentes é PNG sem
   fundo e entra inteiro; as antigas só existem como foto de estúdio ou de
   estádio, e coladas cruas seriam um azulejo no meio do dourado. Redondo,
   com a borda apagando pro nada, o fundo some e sobra o rosto. */
.dfc-foto-retrato{max-width:72%;max-height:88%;object-fit:cover;aspect-ratio:1;
  /* O CORTE SOBE. Foto de arquivo costuma vir em pé, de corpo inteiro, e um
     medalhão centrado nela enquadra a barriga. Puxando pro alto, pega o
     rosto; em foto quadrada não corta nada e isto não faz diferença. */
  object-position:center 18%;
  border-radius:50%;align-self:center;
  -webkit-mask-image:radial-gradient(circle at 50% 50%, #000 58%, transparent 74%);
  mask-image:radial-gradient(circle at 50% 50%, #000 58%, transparent 74%)}
.dfc-mini .dfc-foto-retrato{max-width:78%;max-height:78%}
/* Sem foto o escudo vira marca d'água: carta cheia em vez de buraco. */
.dfc-marca{max-width:66%;max-height:86%;object-fit:contain;opacity:.28;align-self:center}
.dfc-mono{font-family:'Oswald',sans-serif;font-size:44px;line-height:1;opacity:.34;align-self:center}

.dfc-pe{display:flex;flex-direction:column;align-items:center;gap:3px;padding-top:5px;
  border-top:1px solid var(--dfc-lin);z-index:1;min-width:0}
.dfc-nome{font-weight:800;font-size:11px;line-height:1.15;text-transform:uppercase;
  letter-spacing:.2px;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dfc-clube{font-size:9px;font-weight:600;opacity:.68;max-width:100%;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* A QUÍMICA DA CARTA: três losangos, 0 a 3, a régua do EA FC. */
.dfc-quim{display:flex;gap:3px;margin-top:1px}
.dfc-quim i{width:6px;height:6px;transform:rotate(45deg);border-radius:1px;
  background:none;box-shadow:inset 0 0 0 1.5px currentColor;opacity:.3}
.dfc-quim i.on{opacity:1;background:var(--dfc-ac);box-shadow:none}
.dfc-fora{box-shadow:inset 0 0 0 2px #ff2d55,0 6px 15px rgba(0,0,0,.4)}

/* ── A QUÍMICA, FORA DA CARTA ────────────────────────────────────────
   Era o último item do rodapé e virava enfeite do desenho. Solta embaixo,
   ela lê como o que é: uma nota sobre o time, não sobre a figurinha. */
.dfq{display:flex;justify-content:center;gap:5px;margin-top:7px}
.dfq i{width:8px;height:8px;transform:rotate(45deg);border-radius:1px;
  background:none;box-shadow:inset 0 0 0 1.5px var(--txt3);opacity:.5}
.dfq i.on{opacity:1;background:var(--verde);box-shadow:0 0 7px rgba(34,197,94,.45)}
.dfq-mini{gap:3px;margin-top:3px}
/* A PRÉVIA DO BANCO EM AZUL. Verde é química que já está valendo; o
   reserva ainda não vale nada pro time, e dois números da mesma cor
   convidariam a somar o banco no total. */
.dfq-prev i.on{background:var(--azul);box-shadow:0 0 7px rgba(59,130,246,.4)}
.azulzinho{color:var(--azul)}
.banco-sub{margin:-2px 0 10px;font-size:12.5px}

/* A explicação da química */
.quim-ajuda{margin:0 0 12px;border:1px solid var(--borda);border-radius:10px;
  background:var(--panel2);overflow:hidden}
.quim-ajuda summary{cursor:pointer;padding:9px 13px;font-size:13px;font-weight:700;
  color:var(--txt2);list-style:none;display:flex;align-items:center;gap:7px}
.quim-ajuda summary::-webkit-details-marker{display:none}
.quim-ajuda summary:hover{color:var(--txt)}
.quim-ajuda summary i{color:var(--amarelo)}
.quim-ajuda[open] summary{border-bottom:1px solid var(--borda)}
.qa-corpo{padding:12px 14px 14px;font-size:13px;line-height:1.55;color:var(--txt2)}
.qa-corpo p{margin:0 0 9px}
.qa-corpo p:last-child{margin-bottom:0}
.qa-corpo b{color:var(--txt)}
.qa-regras{margin:0 0 9px;padding-left:18px}
.qa-regras li{margin-bottom:3px}
.dfq-mini i{width:5px;height:5px;box-shadow:inset 0 0 0 1.2px rgba(255,255,255,.45)}
.legenda-quim{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:14px 0 0;
  color:var(--txt3);font-size:11.5px;line-height:1.45}
.legenda-quim .lq{display:inline-flex;gap:3px}
.legenda-quim .lq i{width:6px;height:6px;transform:rotate(45deg);border-radius:1px;background:var(--verde)}

/* Escalação */
.escal{display:flex;flex-direction:column;gap:6px;margin-top:4px}
.esc-l{display:grid;grid-template-columns:44px 1fr auto auto;gap:10px;align-items:center;
  background:var(--panel2);border:1px solid var(--borda);border-radius:9px;padding:7px 11px;font-size:13px}
.esc-l .rot{font-size:10px;font-weight:800;color:var(--txt3);letter-spacing:.5px}
.esc-l .nm{font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.esc-l .nm small{color:var(--txt3);font-weight:400}
.esc-l .ov{font-family:'Oswald',sans-serif;font-size:16px;color:var(--amarelo)}
.esc-l .qm{font-size:11px;font-weight:700;padding:2px 7px;border-radius:999px}
.qm.alta{background:rgba(34,197,94,.15);color:var(--verde)}
.qm.media{background:rgba(245,197,24,.15);color:var(--amarelo)}
.qm.baixa{background:rgba(252,0,37,.15);color:#ff5c7a}
.esc-l.vazia{opacity:.35}

.nums{display:flex;gap:18px;flex-wrap:wrap;margin:12px 0}
.num{background:var(--panel3);border:1px solid var(--borda);border-radius:10px;padding:9px 16px;text-align:center}
.num b{display:block;font-family:'Oswald',sans-serif;font-size:24px;line-height:1}
.num small{color:var(--txt3);font-size:11px;text-transform:uppercase;letter-spacing:.5px}

/* Partida */
/* O ESCUDO NO PLACAR. Tamanho fixo e `contain` porque os escudos vêm de
   fontes diferentes — PNG quadrado da thesportsdb, SVG da NBA, foto que o
   GM subiu — e sem isso cada time teria um tamanho de cabeça. */
/* AS DUAS ESCALACOES. Duas colunas no desktop, uma embaixo da outra no
   telefone — e as cartas numa grade que se adapta, porque a defesa tem
   quatro e o ataque pode ter um. Largura minima de 62px: menor que isso
   a carta mini perde o nome. */
/* A BARRA DO PROGRESSO de cada lado no duelo. Fininha de propósito: ela
   acompanha o número que está escrito ali do lado, não substitui. */
.dbarra{display:block;height:4px;border-radius:3px;background:var(--panel3);
  margin-top:7px;overflow:hidden}
.dbarra i{display:block;height:100%;background:var(--amarelo);border-radius:3px;
  transition:width .3s}
.escalacoes{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.esc-lado{background:var(--panel2);border:1px solid var(--borda);border-radius:13px;padding:12px}
.esc-topo{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}
.esc-topo b{font-size:13.5px}
.esc-topo small{color:var(--txt3);font-size:11.5px}
.esc-esc{width:26px;height:26px;object-fit:contain;flex:0 0 auto}
.esc-setor{color:var(--txt3);font-size:10.5px;letter-spacing:.7px;text-transform:uppercase;
  margin:10px 0 5px;font-weight:700}
.esc-setor:first-of-type{margin-top:0}
.esc-cartas{display:grid;grid-template-columns:repeat(auto-fill,minmax(62px,1fr));gap:7px}
.esc-carta{display:block;position:relative;aspect-ratio:.72}
@media (max-width:620px){
  .escalacoes{grid-template-columns:1fr;gap:12px}
  .esc-cartas{grid-template-columns:repeat(auto-fill,minmax(56px,1fr))}
}
.pl-esc{width:34px;height:34px;object-fit:contain;display:block;margin:0 auto 5px;
  filter:drop-shadow(0 2px 4px rgba(0,0,0,.4))}
.pl-mono{display:block;width:34px;height:34px;margin:0 auto 5px;border-radius:50%;
  background:var(--panel3);color:var(--txt2);font-family:'Oswald',sans-serif;
  font-size:18px;line-height:34px;text-align:center}
.placar{display:flex;align-items:center;justify-content:center;gap:18px;margin:6px 0 16px;flex-wrap:wrap}
.placar .t{text-align:center;min-width:130px}
.placar .t b{display:block;font-size:14px}
.placar .t small{color:var(--txt3);font-size:11px}
.placar .g{font-family:'Oswald',sans-serif;font-size:46px;line-height:1}
.narra{display:flex;flex-direction:column;gap:7px;max-height:420px;overflow-y:auto;padding-right:4px}
.lance{display:grid;grid-template-columns:40px 1fr;gap:10px;align-items:start;font-size:13.5px;
  padding:7px 10px;border-radius:8px;background:var(--panel2);border-left:3px solid var(--borda2);
  opacity:0;transform:translateY(6px);animation:ent .25s forwards}
@keyframes ent{to{opacity:1;transform:none}}
/* O lance que entra SEM animação (o "Pular pro fim" joga todos de uma vez)
   precisa aparecer por conta própria: a regra acima nasce em opacity:0 e
   quem tirava a opacidade era justamente a animação desligada. */
.lance.pronto{opacity:1;transform:none;animation:none}
.lance .m{font-family:'Oswald',sans-serif;color:var(--txt3);font-size:13px}
.lance.gol{border-left-color:var(--verde);background:rgba(34,197,94,.1);font-weight:700}
.lance.defesa{border-left-color:var(--azul)}
.lance.perdeu{border-left-color:var(--amarelo)}
.lance.fim{border-left-color:var(--vermelho);font-weight:700}
.lance .pl{font-family:'Oswald',sans-serif;color:var(--verde);margin-left:6px}
/* ── O CAMPINHO ───────────────────────────────────────────────────── */
.campo-topo{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.quim-barra{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--txt2)}
.quim-barra b{font-family:'Oswald',sans-serif;font-size:17px;color:var(--txt)}
.qb-trilho{display:block;width:110px;height:7px;border-radius:99px;background:var(--panel3);overflow:hidden}
.qb-fill{display:block;height:100%;border-radius:99px;
  background:linear-gradient(90deg,var(--vermelho),var(--amarelo),var(--verde));
  transition:width .5s cubic-bezier(.2,.8,.2,1)}
.campo{position:relative;width:100%;aspect-ratio:68/95;max-height:540px;margin:10px auto 0;
  background:
    repeating-linear-gradient(180deg,#16331d 0 7%,#143019 7% 14%);
  border:2px solid rgba(255,255,255,.18);border-radius:10px;overflow:hidden}
.linha-meio{position:absolute;left:0;right:0;top:50%;height:2px;background:rgba(255,255,255,.18)}
.circulo{position:absolute;left:50%;top:50%;width:26%;aspect-ratio:1;transform:translate(-50%,-50%);
  border:2px solid rgba(255,255,255,.18);border-radius:50%}
.area{position:absolute;left:22%;width:56%;height:14%;border:2px solid rgba(255,255,255,.18)}
.area-cima{top:0;border-top:none}
.area-baixo{bottom:0;border-bottom:none}

.slot{position:absolute;transform:translate(-50%,-50%);width:68px;
  display:flex;flex-direction:column;align-items:center;
  cursor:pointer;border-radius:10px;
  transition:transform .15s,box-shadow .15s;animation:slotEnt .3s backwards}
/* A CARTA tem a altura; os losangos ficam fora dela, embaixo. */
.slot-carta{position:relative;display:block;width:100%;aspect-ratio:10/13}
@keyframes slotEnt{from{opacity:0;transform:translate(-50%,-50%) scale(.6)}}
.slot:hover{transform:translate(-50%,-50%) scale(1.12);z-index:5}
.slot.vazio{justify-content:center;gap:2px;aspect-ratio:10/13;
  background:rgba(0,0,0,.44);border:1px dashed rgba(255,255,255,.34)}
.slot.aberta .slot-carta,.slot.aberta.vazio{box-shadow:0 0 0 3px rgba(245,197,24,.8);border-radius:10px}
.slot.sel .slot-carta{box-shadow:0 0 0 3px rgba(59,130,246,.9);border-radius:10px}
.slot .s-mais{font-size:20px;line-height:1;color:var(--txt3)}
.slot .s-rot{font-size:8px;font-weight:800;letter-spacing:.5px;color:var(--txt3)}

/* A CARTA MINI preenche o slot. O retrato vira fundo, porque em 64px de
   largura não cabe uma faixa só pra foto — e o que tem de ficar legível é o
   OVR, a posição e os losangos da química. */
.dfc-mini{position:absolute;inset:0;aspect-ratio:auto;border-radius:10px;padding:4px 4px 3px}
.dfc-mini .dfc-retrato{position:absolute;inset:0;z-index:0;align-items:center;opacity:.55}
.dfc-mini .dfc-foto{max-width:100%;max-height:100%}
.dfc-mini .dfc-marca{max-width:60%;max-height:60%;opacity:.3}
.dfc-mini .dfc-mono{font-size:26px}
.dfc-mini .dfc-ovr{font-size:17px}
.dfc-mini .dfc-pos{font-size:8px;letter-spacing:.6px;margin-top:1px}
.dfc-mini .dfc-pos i{font-size:7px;margin-left:1px}
.dfc-mini .dfc-band{width:15px;height:10px}
.dfc-mini .dfc-esc{width:15px;height:15px}
.dfc-mini .dfc-insig{gap:2px}
.dfc-mini .dfc-pe{padding-top:3px;gap:2px}
.dfc-mini .dfc-nome{font-size:8px;letter-spacing:-.25px}
.dfc-mini .dfc-quim{gap:2.5px;margin-top:0}
.dfc-mini .dfc-quim i{width:4.5px;height:4.5px}

.banco-tit{margin:16px 0 8px;font-family:'Oswald',sans-serif;font-size:15px;letter-spacing:.4px}
.banco-tit span{color:var(--txt3);font-size:12px;font-family:'Montserrat',sans-serif}
.banco{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
@media (min-width:620px){.banco{grid-template-columns:repeat(8,1fr)}}
.banco-s{position:relative;transform:none;width:auto}
.banco-s:hover{transform:scale(1.07)}
@keyframes slotEnt2{from{opacity:0;transform:scale(.7)}}
.banco-s{animation:slotEnt2 .3s backwards}

/* Cartas: entram em cascata */
.cartas-bloco{animation:sobe .3s}
@keyframes sobe{from{opacity:0;transform:translateY(10px)}}
.carta-btn{animation:cartaEnt .4s backwards}
@keyframes cartaEnt{from{opacity:0;transform:translateY(18px) rotateX(20deg)}}

/* Placar que anda com a narração */
.placar .g{transition:transform .2s}
.placar .g.pulsa{transform:scale(1.22);color:var(--verde)}
.relogio{text-align:center;font-family:'Oswald',sans-serif;font-size:14px;color:var(--txt3);
  letter-spacing:1px;margin-bottom:10px}
.lance.intervalo{border-left-color:var(--amarelo);background:rgba(245,197,24,.08);font-weight:700}
@media (max-width:620px){
  .campo{max-height:none}
  .slot{width:56px}
  .dfc-mini{padding:3px 3px 2px}
  .dfc-mini .dfc-ovr{font-size:14px}
  .dfc-mini .dfc-pos{font-size:7px;letter-spacing:.3px}
  .dfc-mini .dfc-nome{font-size:7.5px}
  .dfc-mini .dfc-band{width:12px;height:8px}
  .dfc-mini .dfc-esc{width:12px;height:12px}
  .dfc-mini .dfc-quim i{width:4px;height:4px}
  .banco{grid-template-columns:repeat(4,1fr)}
}
@media (max-width:620px){
  .wrap{padding:12px}
  .esc-l{grid-template-columns:38px 1fr auto auto;gap:7px;font-size:12px}
  .placar .g{font-size:36px}
}
</style>
</head>
<body>

<div class="topo">
  <h1>FBA <span>Draft</span></h1>
  <div style="display:flex;gap:10px;align-items:center">
    <span class="saldo"><i class="bi bi-coin"></i> <?= number_format($moedas, 0, ',', '.') ?></span>
    <?php /* /games.php, que é a página dos jogos — /games/ cai no índice do
         diretório, que não é tela de ninguém. É o mesmo link que o Copero e o
         Dream Team usam. */ ?>
    <a class="btn" href="/games.php"><i class="bi bi-arrow-left"></i> Games</a>
  </div>
</div>

<div class="wrap">
<?php if ($msg): ?><div class="aviso ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="aviso err"><?= e($erro) ?></div><?php endif; ?>

<?php if ($convite): ?>
  <?php /* ── CONVITE DE REVANCHE ────────────────────────────────────
         Aparece antes de qualquer outra tela: é uma decisão esperando, e
         esconder isso num canto faria o outro GM esperar sem saber por quê. */ ?>
  <div class="bloco">
    <h2>Revanche!</h2>
    <p class="sub"><b><?= e(dfdNomeDoTime($pdo, (int)$convite['id_criador'])) ?></b> quer
       jogar de novo, pela mesma aposta de <b><?= (int)$convite['aposta'] ?> moedas</b>.</p>
    <div class="fim-acoes" style="justify-content:flex-start">
      <form method="POST" style="margin:0">
        <input type="hidden" name="acao" value="duelo_aceitar">
        <button class="btn pri" type="submit" <?= $moedas < (int)$convite['aposta'] ? 'disabled' : '' ?>>
          <i class="bi bi-check2-circle"></i> Aceitar por <?= (int)$convite['aposta'] ?></button>
      </form>
      <form method="POST" style="margin:0"
            data-confirmar="Recusar a revanche? A aposta volta pra quem chamou."
            data-confirmar-ok="Recusar">
        <input type="hidden" name="acao" value="duelo_recusar">
        <button class="btn" type="submit"><i class="bi bi-x-lg"></i> Recusar</button>
      </form>
    </div>
    <?php if ($moedas < (int)$convite['aposta']): ?>
      <p class="sub" style="margin:12px 0 0;color:#ff5c7a">Você tem <?= $moedas ?> moedas
         e a revanche é por <?= (int)$convite['aposta'] ?>.</p>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php if ($dueloFim): ?>
  <?php /* ── O DUELO QUE ACABOU ─────────────────────────────────── */ ?>
  <?php include __DIR__ . '/draftfut_duelo_tela.php'; ?>

<?php elseif (!$d && $duelo): ?>
  <?php /* ── DENTRO DE UM DUELO, SEM TIME MONTADO ────────────────
         A tela do duelo diz onde ele está, e a escolha de esquema aparece
         embaixo quando ainda dá pra montar. */ ?>
  <?php include __DIR__ . '/draftfut_duelo_tela.php'; ?>
  <?php /* DESISTIR TAMBÉM NO SAGUÃO: aqui é onde a pessoa está quando o
           adversário entrou e ela ainda não começou a montar — e era
           justamente onde o botão não aparecia. */ ?>
  <?php include __DIR__ . '/draftfut_desistir.php'; ?>
  <?php if (!(int)$duelo['pronto_' . dfdLado($duelo, $user_id)]): ?>
    <div class="bloco">
      <h2>Escolha a formação</h2>
      <p class="sub">Depois de começar você não troca mais — e as cinco cartas de cada
         vaga dependem dela. Neste duelo a entrada já foi paga na aposta.</p>
      <div class="forms">
        <?php foreach ($formacoesNaMesa as $nome): $vagas = DFUT_FORMACOES[$nome]; ?>
          <form method="POST">
            <input type="hidden" name="acao" value="comecar">
            <input type="hidden" name="formacao" value="<?= e($nome) ?>">
            <button class="form-card" type="submit">
              <b><?= e($nome) ?></b>
              <small><?= e(implode(' · ', array_slice(array_unique(array_column($vagas, 0)), 0, 5))) ?></small>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

<?php elseif (!$d && $vista !== 'bot'): ?>
  <?php /* ── O SALÃO: modos, ranking e confrontos ────────────────── */ ?>
  <?php include __DIR__ . '/draftfut_salao.php'; ?>

<?php elseif (!$d): ?>
  <?php /* ── 1. ESCOLHER A FORMAÇÃO (modo bot) ──────────────────── */ ?>
  <div class="bloco">
    <div class="salao-topo">
      <h2>Contra o bot</h2>
      <a class="btn" href="?"><i class="bi bi-arrow-left"></i> Voltar</a>
    </div>
    <p class="sub">Entrada: <b><?= DF_ENTRADA ?> moedas</b>. Vitória paga
       <b><?= DF_VITORIA ?></b>, empate <b><?= DF_EMPATE ?></b>.</p>
    <h3 class="salao-h3">Escolha a formação</h3>
    <p class="sub">Cinco esquemas sorteados de treze. Depois de começar você não troca
       mais — e as cinco cartas de cada vaga dependem dele.</p>
    <div class="forms">
      <?php foreach ($formacoesNaMesa as $nome): $vagas = DFUT_FORMACOES[$nome]; ?>
        <form method="POST">
          <input type="hidden" name="acao" value="comecar">
          <input type="hidden" name="formacao" value="<?= e($nome) ?>">
          <button class="form-card" type="submit" <?= $moedas < DF_ENTRADA ? 'disabled' : '' ?>>
            <b><?= e($nome) ?></b>
            <small><?= e(implode(' · ', array_slice(array_unique(array_column($vagas, 0)), 0, 5))) ?></small>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
    <?php if ($moedas < DF_ENTRADA): ?>
      <p class="sub" style="margin-top:12px;color:#ff5c7a">
        Você tem <?= $moedas ?> moedas e a entrada custa <?= DF_ENTRADA ?>.</p>
    <?php endif; ?>
  </div>

<?php elseif ($emDraft): ?>
  <?php if ($aberta !== null && $d['opcoes']):
    $natural = draftFutPosDaVaga($d['formacao'], $aberta);
    /* VAGA DE BANCO NÃO TEM POSIÇÃO: vem de tudo, misturado. Então aqui não
       se promete setor nenhum no título, e nenhuma carta nasce marcada como
       "fora de posição" — no banco não existe lugar errado. */
    $noBanco = $aberta >= DFUT_VAGAS; ?>
    <div class="bloco cartas-bloco">
      <h2>Escolha <?= $noBanco ? 'pro banco' : 'pra ' . e(draftFutRotuloDaVaga($d['formacao'], $aberta)) ?></h2>
      <p class="sub"><?= $noBanco
          ? 'Cinco cartas de qualquer posição. Reserva não pontua na química — ele ganha a dele quando sobe pro time.'
          : 'Cinco cartas. A química muda conforme quem já está em campo.' ?></p>
      <div class="cartas">
        <?php foreach ($d['opcoes'] as $i => $c):
          /* QUANTO ESTA CARTA DARIA DE QUÍMICA AQUI — pedido do Marcos
             ("mostre quanto de quimica cada carta ta dando"). Não é
             estimativa: o jogo encaixa a carta na vaga aberta, roda a química
             do time inteiro e lê o ponto dela. */
          $simul = $d['time'];
          $simul[$aberta] = $c;
          $qSim = draftFutQuimica($d['formacao'], $simul);
          $qCarta = $aberta < DFUT_VAGAS ? (int)($qSim['jogadores'][$aberta] ?? 0) : null; ?>
          <form method="POST" style="margin:0">
            <input type="hidden" name="acao" value="escolher">
            <input type="hidden" name="carta" value="<?= $i ?>">
            <button class="carta-btn" type="submit" style="animation-delay:<?= $i * 70 ?>ms"
                    title="<?= e($c['nome']) ?> — <?= e($c['clube']) ?> · <?= e(draftFutPais($c['liga'])) ?><?= !empty($c['nac']) ? ' · ' . e($c['nac']) : '' ?>">
              <?= dfutCartaHtml($c, ['rotulo' => $noBanco ? $c['pos'] : draftFutRotuloDaVaga($d['formacao'], $aberta),
                                     'fora'   => !$noBanco && $c['pos'] !== $natural]) ?>
              <?= dfutQuimicaHtml($qCarta) ?>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
      <?php if (!$noBanco): ?>
      <p class="legenda-quim">
        <span class="lq"><i></i><i></i><i></i></span> química que a carta dá nesta vaga —
        mesmo clube, mesma nação, mesma liga. Máximo 3 por jogador, 33 no time.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php include __DIR__ . '/draftfut_campo.php'; ?>
  <?php include __DIR__ . '/draftfut_desistir.php'; ?>

<?php elseif ($pronto): ?>
  <div class="bloco">
    <h2>Time pronto</h2>
    <p class="sub">Confere o time e manda jogar.</p>
    <div class="nums">
      <div class="num"><b><?= draftFutForcaDoTime($d['formacao'], $d['time']) ?></b><small>Força</small></div>
      <div class="num"><b><?= $quim['total'] ?><small style="opacity:.5">/33</small></b><small>Química</small></div>
      <div class="num"><b><?= e($d['formacao']) ?></b><small>Formação</small></div>
      <div class="num" style="flex:1 1 180px"><b style="font-size:15px"><?= e($nomeDoTime) ?></b><small>Seu time</small></div>
    </div>
    <?php if (!empty($d['duelo'])): ?>
      <?php /* NO DUELO NÃO SE ESCOLHE ADVERSÁRIO: ele já está do outro lado.
               Fechar aqui guarda o time e espera — a partida sai sozinha
               quando o segundo terminar. */ ?>
      <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="acao" value="duelo_jogar">
        <button class="btn pri" type="submit">
          <i class="bi bi-check2-circle"></i> Time pronto pro duelo</button>
      </form>
      <p class="sub" style="margin:10px 0 0;font-size:12px">
        Depois disso não dá pra mexer no time. A partida roda quando o adversário
        terminar o dele.</p>
    <?php else: ?>
      <?php /* UM BOTÃO SÓ. Existia um "contra time salvo" que sorteava o time
               de outro GM com força parecida — e marcava derrota no cartel
               dele sem ele saber de nada. Pedido do Marcos (08/10/2026):
               "acho que nao precisa, pode ser somente contra o bot, que é um
               time aleatorio". Com o duelo de verdade no multiplayer, jogar
               contra o time parado de alguém não era nem uma coisa nem
               outra. */ ?>
      <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="acao" value="jogar">
        <button class="btn pri" type="submit">
          <i class="bi bi-cpu"></i> Jogar contra o bot</button>
      </form>
      <p class="sub" style="margin:10px 0 0;font-size:12px">
        O bot monta um time aleatório na sua faixa de força. Pra jogar contra um GM
        de verdade, use o <a href="?v=multi">multiplayer</a>.</p>
    <?php endif; ?>
  </div>
  <?php include __DIR__ . '/draftfut_campo.php'; ?>
  <?php include __DIR__ . '/draftfut_desistir.php'; ?>

<?php else:
  $r = $d['resultado']; $p = $r['partida'];
  include __DIR__ . '/draftfut_narracao.php';
  include __DIR__ . '/draftfut_campo.php'; ?>
<?php endif; ?>
</div>
<script>
/* ── O CAMPO: clicar abre a vaga, dois cliques trocam ────────────────
   Vaga vazia abre as cartas. Vaga cheia entra em modo "selecionada", e o
   clique seguinte troca os dois de lugar — é assim que se escala sem
   arrastar, que no celular nunca funciona direito. */
(function(){
  const campo = document.getElementById('campo');
  if (!campo) return;
  const todos = document.querySelectorAll('.slot');
  let sel = null;

  function post(campos){
    const f = document.createElement('form');
    f.method = 'POST';
    for (const [k,v] of Object.entries(campos)) {
      const i = document.createElement('input');
      i.type = 'hidden'; i.name = k; i.value = v;
      f.appendChild(i);
    }
    document.body.appendChild(f); f.submit();
  }

  todos.forEach(s => s.addEventListener('click', () => {
    const vaga = s.dataset.vaga, tem = s.dataset.tem === '1';

    if (sel === vaga) { s.classList.remove('sel'); sel = null; return; }

    /* TROCA SÓ ENTRE DOIS JÁ ESCOLHIDOS. Com um selecionado, clicar numa
       vaga vazia não arrasta ninguém pra lá — abre a vaga, que é o que a
       pessoa quis dizer ao clicar num "+". */
    if (sel !== null && tem) { post({acao:'trocar', a:sel, b:vaga}); return; }
    if (!tem) { post({acao:'abrir', vaga}); return; }

    document.querySelectorAll('.slot.sel').forEach(o => o.classList.remove('sel'));
    s.classList.add('sel'); sel = vaga;
  }));
})();
</script>
<?php /* ── O POPUP DO JOGO, NUNCA O DO NAVEGADOR ──────────────────────
     Mesma caixa que a Carreira usa. A do Chrome não tem a cara do jogo, não
     dá pra escrever direito nela e some no meio da tela sem contexto — e
     desistir de um draft é exatamente a hora de a pergunta estar clara. */ ?>
<div class="pop" id="popConfirmar" hidden>
  <div class="pop-caixa">
    <p id="pcTexto"></p>
    <div class="pop-acoes">
      <button type="button" class="btn" data-fechar>Voltar</button>
      <button type="button" class="btn pri" id="pcOk">Confirmar</button>
    </div>
  </div>
</div>
<script>
(function () {
  var pc = document.getElementById('popConfirmar');
  var pcTexto = document.getElementById('pcTexto');
  var pcOk = document.getElementById('pcOk');
  var pendente = null;
  if (!pc) return;

  pc.addEventListener('click', function (e) {
    if (e.target === pc || e.target.hasAttribute('data-fechar')) pc.hidden = true;
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !pc.hidden) pc.hidden = true;
  });

  /* ── COPIAR LINK E CÓDIGO ─────────────────────────────────────────
     O caminho moderno (navigator.clipboard) só existe em página segura, e o
     jogo roda em http no desenvolvimento e em https no ar. O caminho velho
     (textarea + execCommand) cobre o resto, e se nem ele funcionar o texto
     fica selecionado pra pessoa copiar na mão — nunca um silêncio.
     O botão diz "Copiado!" por um segundo: sem resposta, a pessoa clica de
     novo achando que falhou. */
  document.querySelectorAll('[data-copiar]').forEach(function (b) {
    b.addEventListener('click', function () {
      var txt = b.getAttribute('data-copiar') || '';
      var antes = b.innerHTML;
      var feito = function () {
        b.innerHTML = '<i class="bi bi-check2"></i> Copiado!';
        setTimeout(function () { b.innerHTML = antes; }, 1400);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(txt).then(feito, velho);
      } else { velho(); }

      function velho() {
        var ta = document.createElement('textarea');
        ta.value = txt;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        if (ok) { feito(); }
        else if (window.fbaAlert) { fbaAlert('Copie na mão: ' + txt); }
        else { window.prompt('Copie na mão:', txt); }
      }
    });
  });

  document.querySelectorAll('form[data-confirmar]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (f.dataset.confirmado === '1') return;
      e.preventDefault();
      pendente = f;
      pcTexto.textContent = f.dataset.confirmar;
      pcOk.textContent = f.dataset.confirmarOk || 'Confirmar';
      pcOk.style.background = f.dataset.confirmarPerigo ? '#b91c1c' : '';
      pcOk.style.borderColor = f.dataset.confirmarPerigo ? '#b91c1c' : '';
      pc.hidden = false;
    });
  });

  pcOk.addEventListener('click', function () {
    if (!pendente) return;
    pendente.dataset.confirmado = '1';
    pc.hidden = true;
    pendente.submit();
  });
})();
</script>
</body>
</html>
