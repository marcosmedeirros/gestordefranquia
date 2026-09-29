<?php
/**
 * CARREIRA — o jogo de técnico, no estilo Brasfoot.
 *
 * Você assume um clube pequeno, cumpre a meta da diretoria pra não ser
 * demitido, compra e vende no mercado e vai subindo de clube conforme a
 * reputação cresce.
 *
 * O motor vive em games/core/: fut_motor (partida e tabela), fut_clubes_br
 * (os 98 clubes), fut_elencos (os jogadores), fut_mercado (preço e proposta) e
 * fut_carreira (o save e o laço do ano). Aqui é só a tela e o roteamento das
 * ações — a regra do jogo não mora neste arquivo, e não deve passar a morar.
 *
 * PRECISA DE LOGIN, ao contrário dos outros jogos do Games: uma carreira dura
 * temporadas e não faz sentido sem onde salvar. Quem não está logado vê o
 * convite pra entrar, e não um jogo que perde tudo ao fechar a aba.
 *
 * Os nomes de clube identificam dentro da simulação. O jogo não é afiliado,
 * patrocinado nem endossado por nenhum deles, não hospeda escudo, e os
 * jogadores dos clubes sem elenco real são personagens fictícios.
 */

session_start();
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../core/fut_carreira.php';
require_once __DIR__ . '/../core/fut_busca.php';
/* As cores de cada clube. Opcional de propósito: o jogo roda inteiro sem elas
   (cai no verde padrão), e quem clona o projeto sem rodar o extrator não fica
   com uma tela quebrada. */
if (is_file(__DIR__ . '/../core/fut_cores.php')) require_once __DIR__ . '/../core/fut_cores.php';

$idUsuario = (int)($_SESSION['user_id'] ?? 0);
$pdo = db();

$erro = null;
$aviso = null;
$relatorio = null;

$estado = $idUsuario > 0 ? futCarreiraCarregar($pdo, $idUsuario) : null;

// ─────────────────────────────────────────────────────────────────────
//  AÇÕES
// ─────────────────────────────────────────────────────────────────────
if ($idUsuario > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'comecar') {
            $tecnico = trim((string)($_POST['tecnico'] ?? '')) ?: 'Técnico';
            $clube = (string)($_POST['clube'] ?? '');

            /* DUAS PORTAS, DUAS LISTAS. Quem escolheu "desempregado" só pode
               fechar com um dos cinco que apareceram pra ele — e é por isso
               que a conferência tem que ser feita aqui, e não só escondendo o
               outro grupo na tela: o formulário é do navegador, e trocar o
               valor de um radio é a coisa mais fácil do mundo. */
            $disponiveis = ($_POST['modo'] ?? '') === 'convites'
                ? futConvitesDeEstreia(crc32('estreia|' . $idUsuario))
                : futClubesParaComecar(10);

            if (!isset($disponiveis[$clube])) {
                $erro = 'Esse clube não está disponível para quem está começando.';
            } else {
                $estado = futCarreiraNova(mb_substr($tecnico, 0, 40), $clube);
                futCarreiraSalvar($pdo, $idUsuario, $estado);
            }
        }

        elseif ($estado && $acao === 'iniciar_temporada') {
            $estado['calendario'] = futCarreiraMontarCalendario($estado);
            $estado['fase'] = 'temporada';
            futCarreiraSalvar($pdo, $idUsuario, $estado);
        }

        /* JOGAR É ENTRAR EM CAMPO. O botão que simulava cinco partidas de uma
           vez saiu: ele existia porque a partida era um clique e um placar, e
           ninguém quer dar cinquenta cliques iguais. Agora a partida é a tela
           onde o jogo acontece, e pular cinco delas seria pular o jogo. */
        elseif ($estado && $acao === 'jogar') {
            $r = futCarreiraAoVivoIniciar($estado);
            if (!$r['ok']) {
                $estado['fase'] = 'fim';
                futCarreiraSalvar($pdo, $idUsuario, $estado);
            } else {
                $estado = $r['estado'];
                futCarreiraSalvar($pdo, $idUsuario, $estado);
                header('Location: ?aba=partida');
                exit;
            }
        }

        /* O RELÓGIO ANDANDO. Responde JSON porque quem chama é o cronômetro da
           tela, e recarregar a página a cada cinco minutos de jogo acabaria
           com a partida ao vivo. */
        elseif ($estado && $acao === 'aovivo_avancar') {
            header('Content-Type: application/json; charset=utf-8');
            if (empty($estado['aovivo'])) {
                echo json_encode(['ok' => false, 'erro' => 'Não há partida em andamento.']);
                exit;
            }
            $ate = (int)($_POST['ate'] ?? 0);
            $a = futCarreiraAoVivoAvancar($estado, $ate);
            $estado = $a['estado'];
            futCarreiraSalvar($pdo, $idUsuario, $estado);
            $v = $estado['aovivo'] ?? [];
            echo json_encode([
                'ok'     => true,
                'minuto' => (int)($v['minuto'] ?? 0),
                'meus'   => (int)($v['meus'] ?? 0),
                'deles'  => (int)($v['deles'] ?? 0),
                'novos'  => array_values($a['novos']),
                'notas'  => futCarreiraAoVivoNotas($estado),
                'numeros' => $v['numeros'] ?? null,
                'fim'    => (bool)$a['fim'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        /* A PAUSA VALENDO: o técnico mexe e o resto do jogo sente. */
        elseif ($estado && $acao === 'aovivo_estrategia') {
            header('Content-Type: application/json; charset=utf-8');
            $postura  = (string)($_POST['postura'] ?? 'neutro');
            $marcacao = (string)($_POST['marcacao'] ?? 'normal');
            if (!isset(FUT_POSTURAS[$postura]))  $postura = 'neutro';
            if (!isset(FUT_MARCACOES[$marcacao])) $marcacao = 'normal';
            $estado['estrategia'] = ['postura' => $postura, 'marcacao' => $marcacao];
            futCarreiraSalvar($pdo, $idUsuario, $estado);
            echo json_encode(['ok' => true, 'postura' => $postura, 'marcacao' => $marcacao]);
            exit;
        }

        elseif ($estado && $acao === 'aovivo_formacao') {
            header('Content-Type: application/json; charset=utf-8');
            $r = futCarreiraAoVivoFormacao($estado, (string)($_POST['esquema'] ?? ''));
            if ($r['ok']) {
                $estado = $r['estado'];
                futCarreiraSalvar($pdo, $idUsuario, $estado);
            }
            echo json_encode(['ok' => $r['ok'], 'erro' => $r['erro'],
                              'esquema' => $estado['esquema'] ?? '', 'forca' => $r['forca']],
                             JSON_UNESCAPED_UNICODE);
            exit;
        }

        elseif ($estado && $acao === 'aovivo_substituir') {
            header('Content-Type: application/json; charset=utf-8');
            $r = futCarreiraAoVivoSubstituir($estado, (string)($_POST['sai'] ?? ''),
                                                       (string)($_POST['entra'] ?? ''));
            if ($r['ok']) {
                $estado = $r['estado'];
                futCarreiraSalvar($pdo, $idUsuario, $estado);
            }
            $v = $estado['aovivo'] ?? [];
            echo json_encode([
                'ok'     => $r['ok'],
                'erro'   => $r['erro'],
                'trocas' => count($v['trocas'] ?? []),
                'restam' => FUT_AOVIVO_TROCAS - count($v['trocas'] ?? []),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        elseif ($estado && $acao === 'aovivo_fechar') {
            $f = futCarreiraAoVivoFechar($estado);
            if ($f['ok']) {
                $estado = $f['estado'];
                if ((int)($estado['rodada'] ?? 0) >= count($estado['calendario'] ?? [])) {
                    $estado['fase'] = 'fim';
                }
                futCarreiraSalvar($pdo, $idUsuario, $estado);
            }
            header('Location: ?');
            exit;
        }

        elseif ($estado && $acao === 'fechar_temporada') {
            $f = futCarreiraFecharTemporada($estado);
            $estado = $f['estado'];
            $relatorio = $f['relatorio'];
            $_SESSION['fut_relatorio'] = $relatorio;
            futCarreiraSalvar($pdo, $idUsuario, $estado);
        }

        elseif ($estado && $acao === 'trocar_clube') {
            $destino = (string)($_POST['clube'] ?? '');
            /* SÓ VALE UM CLUBE QUE FEZ PROPOSTA. Sem esta conferência, um POST
               montado à mão levaria o técnico direto pro Palmeiras. */
            $convidou = false;
            foreach ($estado['propostas'] ?? [] as $pr) if ($pr['nome'] === $destino) $convidou = true;
            if (!$convidou) {
                $erro = 'Esse clube não fez proposta a você.';
            } else {
                $r = futCarreiraTrocarDeClube($estado, $destino);
                if ($r['ok']) { $estado = $r['estado']; $aviso = $r['motivo']; futCarreiraSalvar($pdo, $idUsuario, $estado); }
                else $erro = $r['motivo'];
            }
        }

        elseif ($estado && $acao === 'assumir_clube') {
            /* SÓ VALE NA FASE DE DESEMPREGADO e dentro do que a reputação
               alcança: senão um POST montado à mão levaria o técnico demitido
               direto pro Palmeiras. */
            $destino = (string)($_POST['clube'] ?? '');
            $permitidos = futClubesParaComecar((int)($estado['tecnico']['reputacao'] ?? 0));
            if (($estado['fase'] ?? '') !== 'desempregado') {
                $erro = 'Você já tem clube.';
            } elseif (!isset($permitidos[$destino]) || $destino === $estado['clube']) {
                $erro = 'Esse clube não está ao seu alcance agora.';
            } else {
                $r = futCarreiraTrocarDeClube($estado, $destino);
                if ($r['ok']) { $estado = $r['estado']; $aviso = $r['motivo']; futCarreiraSalvar($pdo, $idUsuario, $estado); }
                else $erro = $r['motivo'];
            }
        }

        elseif ($estado && $acao === 'recusar_propostas') {
            $estado['propostas'] = [];
            futCarreiraSalvar($pdo, $idUsuario, $estado);
            $aviso = 'Você ficou no ' . $estado['clube'] . '.';
        }

        elseif ($estado && $acao === 'escalar') {
            $esquema = (string)($_POST['esquema'] ?? '4-4-2');
            if (!isset(FUT_ESQUEMAS[$esquema])) $esquema = '4-4-2';
            $estado['esquema'] = $esquema;

            /* POSTURA E MARCAÇÃO VÊM NO MESMO FORMULÁRIO. Elas nasceram dentro
               da partida, onde servem pra reagir ao jogo — mas a escolha de
               como o time entra em campo é de véspera, e é aqui que o técnico
               monta o time. */
            $postura  = (string)($_POST['postura'] ?? 'neutro');
            $marcacao = (string)($_POST['marcacao'] ?? 'normal');
            if (!isset(FUT_POSTURAS[$postura]))   $postura = 'neutro';
            if (!isset(FUT_MARCACOES[$marcacao])) $marcacao = 'normal';
            $estado['estrategia'] = ['postura' => $postura, 'marcacao' => $marcacao];

            $mapa = $_POST['vaga'] ?? [];
            if (is_array($mapa) && $mapa) {
                $fora = array_keys($estado['suspensos'] ?? []);
                $v = futValidarEscalacao($mapa, $estado['elenco'], $esquema, $fora);
                if ($v['ok']) {
                    $estado['escalacao'] = $mapa;
                    $aviso = 'Escalação salva.';
                } else {
                    $erro = $v['erro'];
                }
            } else {
                // Sem mapa: o jogador só trocou de esquema. Reescala sozinho.
                $estado['escalacao'] = [];
                $aviso = 'Esquema trocado para ' . $esquema . '.';
            }
            if (!$erro) futCarreiraSalvar($pdo, $idUsuario, $estado);
        }

        elseif ($estado && $acao === 'escalar_auto') {
            $estado['escalacao'] = [];
            futCarreiraSalvar($pdo, $idUsuario, $estado);
            $aviso = 'O time foi escalado automaticamente.';
        }

        elseif ($estado && $acao === 'comprar') {
            $r = futCarreiraComprar($estado, (string)($_POST['clube_vendedor'] ?? ''),
                                    (string)($_POST['jogador'] ?? ''), (float)($_POST['oferta'] ?? 0));
            $estado = $r['estado'];
            if ($r['ok']) { $aviso = $r['motivo']; futCarreiraSalvar($pdo, $idUsuario, $estado); }
            else $erro = $r['motivo'];
        }

        elseif ($estado && $acao === 'emprestar') {
            $r = futCarreiraPedirEmprestado($estado, (string)($_POST['clube_dono'] ?? ''),
                                                      (string)($_POST['jogador'] ?? ''));
            $estado = $r['estado'];
            if ($r['ok']) { $aviso = $r['motivo']; futCarreiraSalvar($pdo, $idUsuario, $estado); }
            else $erro = $r['motivo'];
        }

        elseif ($estado && $acao === 'vender') {
            $r = futCarreiraVender($estado, (string)($_POST['jogador'] ?? ''),
                                   (float)($_POST['oferta'] ?? 0), (string)($_POST['comprador'] ?? 'um clube'));
            $estado = $r['estado'];
            if ($r['ok']) { $aviso = $r['motivo']; futCarreiraSalvar($pdo, $idUsuario, $estado); }
            else $erro = $r['motivo'];
        }

        elseif ($acao === 'recomecar') {
            futCarreiraApagar($pdo, $idUsuario);
            $estado = null;
        }
    } catch (Throwable $e) {
        error_log('[carreira] ' . $e->getMessage());
        $erro = 'Deu ruim aqui. Tenta de novo.';
    }

    /* POST-REDIRECT-GET: sem isso, o F5 depois de jogar uma rodada joga a
       rodada de novo, e o jogador perde partidas sem entender por quê. */
    if (!$erro) {
        $aba = $_POST['aba'] ?? ($_GET['aba'] ?? 'jogo');
        $q = $aviso ? '&msg=' . rawurlencode($aviso) : '';
        header('Location: carreira.php?aba=' . rawurlencode($aba) . $q);
        exit;
    }
}

if (!$aviso && !empty($_GET['msg'])) $aviso = mb_substr((string)$_GET['msg'], 0, 200);
if (!$relatorio && !empty($_SESSION['fut_relatorio'])) {
    $relatorio = $_SESSION['fut_relatorio'];
    unset($_SESSION['fut_relatorio']);
}

/* A ENTRADA É O INÍCIO. Quem abre o jogo quer ver como o clube está e
   entrar em campo, não uma lista de resultados. */
$aba = (string)($_GET['aba'] ?? 'inicio');

/* A ABA "PARTIDAS" VIROU O INÍCIO. Ela mostrava a próxima partida, o resumo
   da última e os resultados — tudo que o painel já mostra, uma aba adiante.
   O apelido fica porque link antigo, botão gravado no POST e F5 de quem
   estava nela continuam chegando com aba=jogo, e cair numa tela vazia seria
   pior do que a aba repetida que existia antes. */
if ($aba === 'jogo') $aba = 'inicio';

/* O PAINEL DE SUBSTITUIÇÃO PERGUNTA QUEM ESTÁ EM CAMPO. Responde JSON e sai
   antes do HTML: é o mesmo motivo do avanço do relógio — recarregar a página
   pra abrir o painel pararia a partida. */
/* A BUSCA RESPONDE EM JSON E SAI ANTES DO HTML. Ela é chamada a cada pausa na
   digitação: devolver a página inteira por tecla seria mandar 70 KB pra
   preencher uma lista de seis linhas. */
if ($estado && ($_GET['json'] ?? '') === 'busca') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(futCarreiraBuscar($estado, (string)($_GET['q'] ?? '')),
                     JSON_UNESCAPED_UNICODE);
    exit;
}

if ($estado && ($_GET['json'] ?? '') === 'troca') {
    header('Content-Type: application/json; charset=utf-8');
    $v = $estado['aovivo'] ?? null;
    if (!$v) { echo json_encode(['campo' => [], 'banco' => [], 'restam' => 0]); exit; }

    $entraram = [];
    foreach ($v['trocas'] ?? [] as $t) $entraram[$t['entra']] = true;

    $emCampo = futCarreiraEscalacaoAtual($estado);
    $fora = array_keys($estado['suspensos'] ?? []);
    $banco = futReservas($estado['elenco'], $emCampo, $fora);

    /* A POSICAO VAI JUNTO: o banco da prancheta passou a ser o mesmo cartao
       do banco da escalacao, e la a posicao aparece. Sem ela, o cartao ficava
       com um buraco no meio. */
    $ficha = fn(array $j, bool $entrou = false) => [
        'nome'    => $j['nome'],
        'pos'     => (string)($j['pos'] ?? ''),
        'ovr'     => (int)$j['ovr'],
        'energia' => (int)($j['energia'] ?? 100),
        'entrou'  => $entrou,
    ];

    /* AS VAGAS COM AS COORDENADAS: é o mesmo desenho de campo da escalação, e
       elas vêm do esquema, não de uma cópia — se alguém mexer no 4-3-3, o
       campo da substituição acompanha sozinho. */
    $esquema = $estado['esquema'] ?? '4-4-2';
    $vagas = FUT_ESQUEMAS[$esquema]['vagas'] ?? FUT_ESQUEMAS['4-4-2']['vagas'];
    $emCampoPorVaga = [];
    foreach ($vagas as $iv => $vg) {
        $j = $emCampo[$iv] ?? null;
        $emCampoPorVaga[] = [
            'vaga'    => $iv,
            'pos'     => $vg[0],
            'x'       => $vg[1],
            'y'       => $vg[2],
            'nome'    => $j['nome'] ?? '',
            'ovr'     => $j ? futOvrNaVaga($j, $vg[0]) : 0,
            'energia' => $j ? (int)($j['energia'] ?? 100) : 0,
            'entrou'  => $j ? isset($entraram[$j['nome']]) : false,
        ];
    }

    echo json_encode([
        'esquema' => $esquema,
        'vagas'   => $emCampoPorVaga,
        'campo'   => array_values(array_map(fn($j) => $ficha($j, isset($entraram[$j['nome']])), $emCampo)),
        'banco'   => array_values(array_map(fn($j) => $ficha($j), $banco)),
        'restam'  => FUT_AOVIVO_TROCAS - count($v['trocas'] ?? []),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$meuClube = $estado ? futCarreiraMeuClube($estado) : null;
$clubesTodos = futClubesDoJogo();

/** Escapa pra HTML. */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** O escudo do clube, ou um monograma quando não há imagem. */
/**
 * AS INICIAIS QUE VÃO NO EMBLEMA.
 *
 * Duas letras que a pessoa reconheça: as iniciais das duas primeiras palavras
 * quando o nome tem mais de uma ("Nova Mutum" vira NM), e as duas primeiras
 * letras quando é só uma ("Bangu" vira BA). Palavra curta de ligação não
 * conta, senão "União Beltrão" viraria UB e "Águia de Marabá", AD.
 */
function iniciaisDoClube(string $nome): string
{
    $limpo = trim(preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $nome));
    $partes = preg_split('/[\s-]+/u', $limpo, -1, PREG_SPLIT_NO_EMPTY) ?: [$nome];
    $partes = array_values(array_filter($partes,
        fn($p) => !in_array(mb_strtolower($p), ['de', 'do', 'da', 'dos', 'das', 'e'], true)));
    if (!$partes) $partes = [$nome];

    if (count($partes) >= 2) {
        return mb_strtoupper(mb_substr($partes[0], 0, 1) . mb_substr($partes[1], 0, 1));
    }
    return mb_strtoupper(mb_substr($partes[0], 0, 2));
}

/**
 * AS DUAS CORES DO CLUBE, pra tela vestir a camisa dele.
 *
 * Elas saem do escudo (@see games/core/fut_importar_cores_cli.php) e não de
 * uma lista escrita à mão — com 212 clubes, a lista seria 212 chances de errar
 * a cor de um clube que o dono do jogo conhece de cor.
 *
 * O VERDE PADRÃO NÃO É ERRO. Clube sem escudo não tem cor extraída, e cair no
 * verde do jogo é melhor do que sortear um matiz: cor errada é pior do que cor
 * genérica, porque cor errada parece uma afirmação.
 *
 * @return array{0:string,1:string} [sotaque, fundo]
 */
function coresDoClube(string $nome): array
{
    if ($nome !== '' && defined('FUT_CORES_CLUBE') && isset(FUT_CORES_CLUBE[$nome])) {
        return FUT_CORES_CLUBE[$nome];
    }
    return ['#22c55e', '#0d4f27'];
}

/**
 * PRETO OU BRANCO EM CIMA DA COR DO CLUBE.
 *
 * O botão principal é preenchido com o sotaque, e o sotaque vai de amarelo
 * (Dortmund) a azul-escuro. Uma cor de texto fixa erra metade dos clubes: em
 * cima do amarelo, branco some; em cima do azul, preto some. A conta é a
 * luminância relativa do W3C, a mesma que decide contraste acessível.
 */
function textoSobre(string $hex): string
{
    [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    $canal = fn(float $c) => ($c /= 255) <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    $lum = 0.2126 * $canal($r) + 0.7152 * $canal($g) + 0.0722 * $canal($b);
    return $lum > 0.42 ? '#07100a' : '#ffffff';
}

function escudo(array $c, int $tam = 26): string
{
    $url = $c['escudo'] ?? '';
    if ($url !== '') {
        /* SEM loading="lazy". Escudo pesa dois ou três KB, então adiar não
           economiza nada — e o adiamento tem custo: onde alguma extensão do
           navegador mexe no layout, o Chrome decide que a imagem está fora da
           tela e não carrega nenhuma. O cabeçalho do clube e a ficha ficavam
           com o escudo em branco por causa disso. */
        return '<img src="' . h($url) . '" alt="" width="' . $tam . '" height="' . $tam . '" style="object-fit:contain;flex-shrink:0">';
    }
    $ini = iniciaisDoClube((string)($c['nome'] ?? '?'));
    return '<span class="mono" style="width:' . $tam . 'px;height:' . $tam . 'px;font-size:' . round($tam * 0.38) . 'px">' . h($ini) . '</span>';
}

$proximo = null;
if ($estado && ($estado['fase'] ?? '') === 'temporada') {
    $cal = $estado['calendario'] ?? [];
    $i = (int)($estado['rodada'] ?? 0);
    $proximo = $cal[$i] ?? null;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Carreira — Técnico de Futebol</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php $corDoClube = coresDoClube((string)($estado['clube'] ?? '')); ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Barlow+Condensed:wght@500;600;700&display=swap" rel="stylesheet">
<style>
/* O CLUBE PINTA A PÁGINA — ver coresDoClube(). Vem antes do resto do
   estilo pra qualquer regra abaixo poder usar, e depois do :root padrão
   não: é o :root padrão que define o valor de reserva. */
:root{
  /* O CINZA TEM VIÉS FRIO, e isso é escolha: o cinza neutro puro é o cinza de
     painel administrativo, e era exatamente o que a tela parecia. Um sopro de
     azul no preto dá a noite de estádio sem custar contraste. */
  --bg:#07090c; --panel:#101318; --panel2:#151921; --panel3:#1c212a;
  --borda:#232834; --borda2:#313746;
  --txt:#f2f4f7; --txt2:#98a1b0; --txt3:#68707e;
  --verde:#16a34a; --verde-claro:#22c55e; --vermelho:#ef4444;
  --amarelo:#f59e0b; --azul:#3b82f6;

  /* ── O SOTAQUE É DO CLUBE ──────────────────────────────────────────
     Estes três são reescritos no #app com as cores do escudo do clube que
     você dirige (@see fut_cores.php). Dirigir o Porto deixa a tela azul e
     dirigir o Flamengo deixa vermelha — é a coisa que mais separa "jogo" de
     "painel", e a que faltava.

     O VERDE CONTINUA SENDO O VERDE DO RESULTADO. Vitória, overall bom e nota
     alta seguem verdes em clube nenhum: se eles virassem a cor do clube, o
     técnico do Flamengo leria a tabela com vitória vermelha. */
  --acento:#22c55e; --acento-2:#0d4f27; --acento-rgb:34,197,94;

  --display:'Barlow Condensed','Inter',system-ui,sans-serif;
}
<?php /* As duas cores do escudo do clube que você dirige. */ ?>
:root{--acento:<?= h($corDoClube[0]) ?>;--acento-2:<?= h($corDoClube[1]) ?>;--acento-txt:<?= h(textoSobre($corDoClube[0])) ?>}
*{box-sizing:border-box}
/* O atributo hidden precisa vencer os display:flex e inline-flex deste
   arquivo. Sem isto, esconder as abas ou um botao nao escondia nada. */
[hidden]{display:none !important}
body{margin:0;background:var(--bg);color:var(--txt);font-family:'Inter',system-ui,-apple-system,sans-serif;
  font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
#app{max-width:1100px;margin:0 auto;padding:16px 14px 80px}
h1,h2,h3{margin:0;letter-spacing:-.4px}
/* NÚMERO EM COLUNA PRECISA DE LARGURA FIXA. Sem isto a tabela de
   classificação treme a cada rodada: o 1 é mais estreito que o 8, e as
   colunas de pontos e saldo dançam de linha pra linha. */
table,.num,.ficha .v,.placar,.ovr,.nota{font-variant-numeric:tabular-nums}
button,input,select{font-family:inherit}
a{color:inherit}

.mono{display:inline-flex;align-items:center;justify-content:center;border-radius:7px;
  background:var(--panel3);border:1px solid var(--borda);font-weight:800;color:var(--txt2);flex-shrink:0}

/* ── Topo ───────────────────────────────────────────── */
/* ── A barra de cima ─────────────────────────────────────────────────
   Grudada no alto: as duas ações que se quer a qualquer momento não podem
   depender de rolar até elas. O fundo é opaco porque o conteúdo passa por
   baixo — barra translúcida sobre tabela vira sopa. */
.topo{position:sticky;top:0;z-index:40;display:flex;align-items:center;gap:10px;
  margin:0 -14px 14px;padding:10px 14px;flex-wrap:wrap;background:var(--bg);
  border-bottom:1px solid transparent;transition:border-color .2s}
.topo.rolou{border-bottom-color:var(--borda)}
.topo.com-busca .marca{margin-right:2px}

.busca-caixa{position:relative;flex:1;min-width:180px;display:flex;align-items:center}
.busca-caixa > .bi{position:absolute;left:11px;color:var(--txt3);font-size:13px;pointer-events:none}
.busca-caixa input{width:100%;padding:8px 12px 8px 32px;border-radius:10px;
  border:1px solid var(--borda);background:var(--panel);color:var(--txt);font-size:13px}
.busca-caixa input:focus{outline:none;border-color:var(--acento)}
.busca-caixa input::-webkit-search-cancel-button{filter:invert(.6)}

/* A lista flutua: empurrar o conteúdo pra baixo a cada tecla faria a página
   pular enquanto se digita. */
.busca-lista{position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:50;
  background:var(--panel2);border:1px solid var(--borda2);border-radius:12px;
  box-shadow:0 12px 28px rgba(0,0,0,.5);overflow:hidden;max-height:60vh;overflow-y:auto}
.busca-grupo{font-size:10px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;
  color:var(--txt3);padding:9px 12px 4px}
.busca-item{display:flex;align-items:center;gap:9px;padding:8px 12px;font-size:13px;
  color:inherit;text-decoration:none;cursor:pointer}
.busca-item:hover,.busca-item.sel{background:color-mix(in srgb, var(--acento) 14%, transparent)}
.busca-item .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.busca-item .det{font-size:11px;color:var(--txt3);flex-shrink:0}
.busca-vazio{padding:12px;font-size:12.5px;color:var(--txt3)}
.topo-jogar{margin:0;flex-shrink:0}

@media (max-width:560px){
  /* No celular a busca desce pra própria linha: espremida ao lado da marca e
     do botão, sobrava espaço pra três letras. */
  .topo{gap:8px}
  .busca-caixa{order:3;flex-basis:100%;min-width:0}
}
.marca{display:flex;align-items:center;gap:9px;font-weight:900;font-size:17px;letter-spacing:-.6px}
.marca i{color:var(--acento)}
.voltar{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;
  border:1px solid var(--borda);background:transparent;color:var(--txt2);text-decoration:none;flex-shrink:0}
.voltar:hover{border-color:var(--acento);color:var(--acento)}

/* ── A FAIXA DO CLUBE ───────────────────────────────────────────────
   Era um retângulo cinza com o escudo de 42px e quatro caixinhas iguais —
   o mesmo cartão que qualquer painel de qualquer coisa tem. Agora ele veste
   a camisa: a segunda cor do escudo faz o fundo, a primeira faz o filete de
   cima, e o próprio escudo entra gigante e apagado no canto, como a parede
   de um vestiário. As listras são as do gramado recém-cortado, a 2% de
   opacidade — só o bastante pra a superfície não ser chapada. */
.clube-card{position:relative;overflow:hidden;border:1px solid var(--borda);
  border-radius:16px;padding:16px;margin-bottom:14px;
  background:linear-gradient(150deg, var(--acento-2), var(--panel));
  background:
    radial-gradient(120% 140% at 88% 0%, color-mix(in srgb, var(--acento-2) 62%, transparent) 0%, transparent 62%),
    repeating-linear-gradient(112deg, rgba(255,255,255,.022) 0 26px, transparent 26px 52px),
    linear-gradient(150deg, var(--panel2), var(--panel))}
.clube-card::before{content:'';position:absolute;inset:0 0 auto;height:3px;
  background:var(--acento);
  background:linear-gradient(90deg, var(--acento), color-mix(in srgb, var(--acento) 30%, transparent))}
.clube-marca{position:absolute;right:-26px;top:50%;transform:translateY(-50%);
  width:190px;height:190px;opacity:.07;pointer-events:none;object-fit:contain;filter:grayscale(.2)}
.clube-topo{display:flex;align-items:center;gap:13px;position:relative}
.clube-nome{font-family:var(--display);font-size:33px;font-weight:700;letter-spacing:.2px;
  line-height:.98;text-transform:uppercase}
.clube-sub{font-size:12px;color:var(--txt2);margin-top:2px}

/* AS QUATRO FICHAS SEM CAIXA. Quatro retângulos com borda ao lado de um
   cartão com borda dentro de uma página de cartões com borda é onde a
   hierarquia morre. Aqui elas são separadas por um fio e o número é que
   carrega o peso. */
.fichas{display:grid;grid-template-columns:repeat(4,1fr);margin-top:14px;position:relative;
  border-top:1px solid var(--borda);padding-top:12px}
.ficha{padding:0 10px;text-align:left;border-left:1px solid var(--borda)}
.ficha:first-child{border-left:0;padding-left:0}
.ficha .v{font-family:var(--display);font-size:27px;font-weight:700;letter-spacing:0;line-height:1}
.ficha .r{font-size:10px;color:var(--txt3);text-transform:uppercase;letter-spacing:.7px;margin-top:1px;
  font-weight:700}
@media (max-width:560px){
  .clube-nome{font-size:27px}
  .ficha .v{font-size:22px}
  .clube-marca{width:140px;right:-34px}
}
.meta-linha{margin-top:10px;padding:9px 11px;border-radius:9px;background:rgba(245,158,11,.10);
  border:1px solid rgba(245,158,11,.28);font-size:12.5px;display:flex;gap:8px;align-items:flex-start}
.meta-linha i{color:var(--amarelo);margin-top:1px}

/* ── Abas ───────────────────────────────────────────── */
/* ── AS ABAS ────────────────────────────────────────────────────────
   Eram oito pílulas cinzas numa faixa com barra de rolagem à mostra — no
   celular, a barra ocupava tanto quanto as abas. Agora é uma régua: o item
   ativo é marcado por baixo, na cor do clube, e a rolagem continua existindo
   sem aparecer. O véu na direita é o que avisa que há mais abas adiante, que
   é o trabalho que a barra fazia feio. */
.abas{position:relative;display:flex;gap:2px;margin-bottom:16px;overflow-x:auto;
  border-bottom:1px solid var(--borda);-webkit-overflow-scrolling:touch;
  scrollbar-width:none;mask-image:linear-gradient(90deg,#000 calc(100% - 26px),transparent)}
.abas::-webkit-scrollbar{display:none}
.abas a{flex:0 0 auto;padding:9px 13px 10px;color:var(--txt3);text-decoration:none;
  font-size:13px;font-weight:700;white-space:nowrap;border-bottom:2px solid transparent;
  margin-bottom:-1px;transition:color .15s}
.abas a:hover{color:var(--txt)}
.abas a.on{color:var(--txt);border-bottom-color:var(--acento)}

/* ── Blocos ─────────────────────────────────────────── */
.bloco{background:var(--panel);border:1px solid var(--borda);border-radius:14px;padding:15px;margin-bottom:12px;
  box-shadow:0 1px 0 rgba(255,255,255,.03) inset}
.bloco h3{font-size:14px;font-weight:800;margin-bottom:10px;display:flex;align-items:center;gap:7px}
.bloco h3 i{color:var(--acento)}
/* O TÍTULO DO BLOCO EM CONDENSADA E CAIXA ALTA. Em Inter 14 semibold ele
   tinha exatamente o mesmo peso visual que o conteúdo abaixo, e a tela virava
   uma coluna de texto sem degraus. */
.bloco h3{font-family:var(--display);font-size:17px;font-weight:600;letter-spacing:.5px;
  text-transform:uppercase;color:var(--txt)}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 16px;border-radius:10px;
  border:0;background:var(--acento);color:var(--acento-txt);font-weight:800;font-size:14px;cursor:pointer;
  box-shadow:0 1px 0 rgba(255,255,255,.14) inset}
.btn:hover{filter:brightness(1.12)}
/* O BOTAO SECUNDARIO PRECISA DA BORDA DE VOLTA. O principal perdeu a dele
   quando passou a ser preenchido com a cor do clube, e o secundario so
   trocava a COR da borda — ficou sem contorno nenhum, um texto solto. */
.btn.sec{background:transparent;color:var(--txt2);border:1px solid var(--borda2);box-shadow:none}
.btn.sec:hover{color:var(--txt);border-color:var(--txt3);filter:none}
.btn.peq{padding:6px 11px;font-size:12px;border-radius:8px}
.btn:disabled{opacity:.45;cursor:not-allowed}

input[type=text],input[type=number],input[type=search],select{width:100%;padding:10px 12px;border-radius:9px;border:1px solid var(--borda2);
  background:var(--panel3);color:var(--txt);font-size:14px}
label{display:block;font-size:12px;color:var(--txt2);margin-bottom:5px;font-weight:600}

/* ── Aba de início ──────────────────────────────────── */
.proximo{background:linear-gradient(135deg,var(--panel2),var(--panel));border:1px solid var(--borda);
  border-radius:14px;padding:16px 14px;margin-bottom:12px}
.proximo-rot{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--txt3);
  font-weight:700;margin-bottom:9px}
.proximo-jogo{display:flex;align-items:center;gap:12px;margin-bottom:13px;flex-wrap:wrap}
.proximo-nome{font-size:18px;font-weight:900;letter-spacing:-.5px;line-height:1.15}
.proximo-sub{font-size:12px;color:var(--txt2);margin-top:2px}
.proximo-acoes{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.proximo-acoes .btn{font-size:15px;padding:12px 20px}
.proximo-conta{font-size:11.5px;color:var(--txt3);margin-top:10px}

.resumo-grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}
.mini-lista{display:flex;flex-direction:column;gap:6px}
.mini-linha{display:flex;align-items:center;gap:9px;font-size:12.5px;padding:5px 0;
  border-bottom:1px solid var(--borda)}
.mini-linha:last-child{border-bottom:0}
.mini-linha .esq{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mini-linha .dir{font-weight:800;font-variant-numeric:tabular-nums;flex-shrink:0}
/* ── O JOGO DA RODADA ───────────────────────────────────────────────
   Duas colunas espelhadas com o placar no meio, porque e assim que a
   rodada e lida: o mandante puxado pra direita, o visitante pra esquerda,
   e os placares alinhados numa coluna so. Nome longo encolhe em vez de
   empurrar o placar de lugar — a coluna do meio tem que ficar parada. */
.jogo-rodada{gap:7px;font-size:12px}
.jogo-rodada .lado{flex:1 1 0;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.jogo-rodada .lado.dir{text-align:right}
.jogo-rodada .pl{flex-shrink:0;font-weight:800;font-variant-numeric:tabular-nums;
  background:var(--panel3);border-radius:6px;padding:1px 7px;font-size:11.5px}

/* ── Campo e banco lado a lado ──────────────────────── */
.escalar-lado{display:grid;grid-template-columns:minmax(0,1fr) 250px;gap:14px;align-items:start}
.escalar-lado > .banco{margin-bottom:0}
.banco-vert .banco-lista{flex-direction:column;flex-wrap:nowrap;gap:5px;
  max-height:520px;overflow-y:auto;padding-right:2px}
.banco-vert .reserva{width:100%}
.banco-vert .reserva .r-nome{max-width:none;flex:1}
@media (max-width:840px){
  .escalar-lado{grid-template-columns:1fr}
  .banco-vert .banco-lista{flex-direction:row;flex-wrap:wrap;max-height:none}
  .banco-vert .reserva{width:auto}
}

/* ── Estratégia fora da partida ─────────────────────── */
.estrategia-cols{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:560px){ .estrategia-cols{grid-template-columns:1fr} }

/* ── Fichas de clube e de jogador ───────────────────── */
.ficha-topo{display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap}
.ficha-topo .nome{font-size:22px;font-weight:900;letter-spacing:-.7px;line-height:1.1}
.ficha-topo .sub{font-size:12.5px;color:var(--txt2);margin-top:2px}
.voltar-linha{margin-bottom:12px}
.voltar-linha a{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--txt2);
  text-decoration:none}
.voltar-linha a:hover{color:var(--acento)}
.elo{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;
  background:var(--panel3);border:1px solid var(--borda);font-size:11px;font-weight:700;color:var(--txt2)}
a.link-jogo{color:inherit;text-decoration:none;border-bottom:1px dotted var(--borda2)}
a.link-jogo:hover{color:var(--acento);border-bottom-color:var(--acento)}
.barra-skill{display:flex;align-items:center;gap:9px;padding:5px 0}
.barra-skill .r{font-size:11.5px;color:var(--txt2);width:96px;flex-shrink:0}
.barra-skill .t{flex:1;height:6px;border-radius:999px;background:var(--panel3);overflow:hidden}
.barra-skill .t span{display:block;height:100%;background:var(--verde);border-radius:999px}
.barra-skill .v{font-size:11.5px;font-weight:800;width:28px;text-align:right;font-variant-numeric:tabular-nums}

/* ── Partida ao vivo ────────────────────────────────── */
.viv{background:linear-gradient(160deg,#0f2417,#0a1a10 55%,var(--panel));border:1px solid var(--borda);
  border-radius:16px;padding:16px 14px;margin-bottom:12px}
.viv-topo{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:11.5px;color:var(--txt2)}
.viv-comp{display:flex;align-items:center;gap:7px;min-width:0}
.viv-comp b{color:var(--txt);font-weight:700}
.relogio{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;
  background:rgba(0,0,0,.35);border:1px solid var(--borda);font-variant-numeric:tabular-nums;
  font-weight:800;font-size:13px;color:var(--txt)}
.viv-tempo{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--txt3);font-weight:700;margin-right:8px}
.relogio .bolinha{width:7px;height:7px;border-radius:50%;background:var(--acento)}
.relogio.rolando .bolinha{animation:pulso 1.1s ease-in-out infinite}
.relogio.parado .bolinha{background:var(--amarelo);animation:none}
@keyframes pulso{0%,100%{opacity:1}50%{opacity:.25}}

.viv-placar{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:10px;margin:16px 0 4px}
.viv-time{display:flex;align-items:center;gap:9px;min-width:0}
.viv-time.dir{flex-direction:row-reverse;text-align:right}
.viv-nome{font-weight:800;font-size:14.5px;letter-spacing:-.3px;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.viv-num{font-size:40px;font-weight:900;letter-spacing:-2px;line-height:1;font-variant-numeric:tabular-nums}
.viv-x{font-size:15px;color:var(--txt3);font-weight:800;padding:0 2px}
.viv-barra{height:4px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden;margin-top:14px}
.viv-barra span{display:block;height:100%;background:var(--acento);width:0;transition:width .45s linear}

.viv-acoes{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}

/* ── A escalação ao vivo ─────────────────────────────────────────────
   Quatro colunas de larguras fixas nas pontas e elástica no meio: a nota
   fica sempre na mesma coluna, então dá pra varrer a lista de cima a baixo
   só olhando a direita. Sem isso, cada nome de tamanho diferente empurraria
   a nota pra um lugar. */
.esc-vivo{display:flex;flex-direction:column;gap:1px}
.esc-linha{display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:7px;font-size:12.5px}
.esc-linha:nth-child(odd){background:rgba(255,255,255,.028)}
.esc-linha .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.esc-linha .mk{display:inline-flex;align-items:center;gap:3px;flex-shrink:0}
.esc-linha .mk .bi{color:var(--acento);font-size:11px}
.esc-linha .n{width:30px;text-align:right;font-weight:900;font-variant-numeric:tabular-nums;
  flex-shrink:0;color:var(--txt3)}
.esc-linha .n.boa{color:var(--verde-claro)}
.esc-linha .n.ruim{color:#fca5a5}
/* Quem levou vermelho sai do jogo — a linha continua ali, apagada, porque
   some-la faria parecer que ele nunca entrou. */
.esc-linha.fora{opacity:.45}
/* O melhor em campo ganha uma linha própria acima da lista: é o resumo do
   resumo, e é a primeira coisa que se quer saber depois do apito. */
.craque{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:11px;
  background:color-mix(in srgb, var(--acento) 12%, var(--panel3));background:var(--panel3);
  background:color-mix(in srgb, var(--acento) 12%, var(--panel3));margin-top:8px}
.craque > .bi{color:var(--amarelo);font-size:16px}
.craque-nome{font-weight:800;font-size:14px}
.craque-sub{font-size:11px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px}

.viv-lance{display:flex;align-items:flex-start;gap:10px;padding:9px 11px;border-radius:10px;
  background:var(--panel3);border:1px solid var(--borda);animation:entra .35s ease}
.viv-lance.nosso{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.07)}
.viv-lance-min{font-size:11px;font-weight:800;color:var(--txt3);min-width:26px;font-variant-numeric:tabular-nums;
  padding-top:1px}
.viv-lance-txt{font-size:12.5px;min-width:0}
.viv-lance-txt b{font-weight:800}
.viv-lance-txt i{color:var(--txt3);font-style:normal;font-size:11.5px}
@keyframes entra{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:none}}

.notas-vivo{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px}
.nota-linha{display:flex;align-items:center;gap:8px;padding:6px 9px;border-radius:9px;background:var(--panel3);
  border:1px solid var(--borda);font-size:12px}
.nota-linha .n{margin-left:auto;font-weight:900;font-variant-numeric:tabular-nums;font-size:12.5px}
.nota-linha .n.boa{color:var(--verde-claro)}
.nota-linha .n.ruim{color:#fca5a5}
.nota-linha .p{font-size:10px;color:var(--txt3);font-weight:700}

/* ── Os destaques da competição ─────────────────────── */
.destaques{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px}
.destaque-lista{display:flex;flex-direction:column;gap:5px}
.destaque-linha{display:flex;align-items:center;gap:9px;font-size:12.5px;padding:6px 0;
  border-bottom:1px solid var(--borda)}
.destaque-linha:last-child{border-bottom:0}
.destaque-linha .p{width:16px;font-weight:800;color:var(--txt3);font-size:11px;flex-shrink:0}
.destaque-linha .n{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.destaque-linha .c{font-size:10.5px;color:var(--txt3);max-width:92px;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap;flex-shrink:0}
.destaque-linha .v{font-weight:900;font-variant-numeric:tabular-nums;flex-shrink:0;min-width:34px;
  text-align:right}
.destaque-linha.eu{color:var(--acento)}
.destaque-nota{font-size:11px;color:var(--txt3);margin-top:8px;line-height:1.4}

/* ── O caminho na copa ──────────────────────────────── */
.chave{display:flex;flex-direction:column;gap:8px}
.chave-fase{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:11px;
  border:1px solid var(--borda);background:var(--panel3);position:relative}
.chave-fase.venceu{border-color:rgba(34,197,94,.32);background:rgba(34,197,94,.06)}
.chave-fase.caiu{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.06)}
.chave-fase.futura{opacity:.5;border-style:dashed}
.chave-fase + .chave-fase::before{content:'';position:absolute;left:26px;top:-9px;width:2px;height:9px;
  background:var(--borda)}
.chave-rot{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--txt3);
  font-weight:700;width:86px;flex-shrink:0}
.chave-adv{display:flex;align-items:center;gap:8px;flex:1;min-width:0;font-size:13px;font-weight:700}
.chave-adv span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chave-placar{font-weight:900;font-variant-numeric:tabular-nums;font-size:14px;flex-shrink:0}
.chave-marca{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;
  padding:2px 8px;border-radius:999px;flex-shrink:0}
.chave-marca.ok{background:rgba(34,197,94,.16);color:var(--verde-claro)}
.chave-marca.fim{background:rgba(239,68,68,.16);color:#fca5a5}
.chave-marca.tac{background:var(--panel);color:var(--txt3)}
.chave-titulo{margin-top:10px;padding:11px;border-radius:11px;text-align:center;font-weight:900;
  background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.32);color:var(--amarelo)}
@media (max-width:520px){
  .chave-fase{flex-wrap:wrap;gap:7px}
  .chave-rot{width:100%}
}

/* ── O jogo numa tela só ────────────────────────────── */
.viv-mesa{display:grid;grid-template-columns:minmax(0,340px) minmax(0,1fr);gap:14px;
  align-items:start;margin-top:14px}
.viv-mesa .viv-campo-caixa{margin:0}
.viv-lado{display:flex;flex-direction:column;gap:12px;min-width:0}
.viv-lado .bloco{margin-bottom:0}
/* SEM ROLAGEM INTERNA. São onze linhas e elas cabem — a barrinha dentro
   do bloco escondia meio time e obrigava a rolar dentro de uma coluna que
   já rola junto com a página. */
.viv-lado .esc-vivo{max-height:none}
@media (max-width:840px){
  .viv-mesa{grid-template-columns:1fr}
  .viv-mesa .viv-campo-caixa{margin:0 auto}
}

/* ── O campo ao vivo ────────────────────────────────── */
.viv-campo-caixa{position:relative;margin:14px auto 0;max-width:330px}
.viv-campo-caixa .campo{margin:0 auto}
.viv-campo-caixa .camisa{transition:transform .2s}
.viv-campo-caixa .camisa .bola{transition:box-shadow .25s,border-color .25s,background .25s}

/* A camisa de quem acabou de aparecer no lance */
.camisa.pulsou{z-index:5}
.camisa.pulsou .bola{border-color:#fff;box-shadow:0 0 0 5px rgba(255,255,255,.25)}
.camisa.gol .bola{border-color:#ffffff;box-shadow:0 0 0 3px rgba(34,197,94,.75);
  box-shadow:0 0 0 7px rgba(34,197,94,.45);animation:bateu .5s ease}
.camisa.cartao .bola{border-color:var(--amarelo);box-shadow:0 0 0 5px rgba(245,158,11,.4)}
.camisa.expulso .bola{border-color:#ef4444;box-shadow:0 0 0 5px rgba(239,68,68,.45);opacity:.55}
@keyframes bateu{0%{transform:scale(1)}45%{transform:scale(1.45)}100%{transform:scale(1)}}

/* A faixa do último lance, sobre o campo */
.viv-agora{position:absolute;left:0;right:0;bottom:8px;margin:0 8px;padding:8px 11px;
  border-radius:10px;background:rgba(0,0,0,.72);backdrop-filter:blur(3px);
  border:1px solid rgba(255,255,255,.14);font-size:12px;line-height:1.35;
  opacity:0;transform:translateY(6px);transition:opacity .25s,transform .25s;pointer-events:none}
.viv-agora.aparece{opacity:1;transform:none}
.viv-agora b{font-weight:800}
.viv-agora .min{font-weight:800;color:var(--acento);margin-right:5px}

/* O GOL toma a tela por um segundo e meio */
.viv-gol{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;
  justify-content:center;gap:4px;border-radius:14px;background:rgba(21,128,61,.92);
  color:#fff;opacity:0;pointer-events:none;transition:opacity .2s;z-index:6}
.viv-gol.aparece{opacity:1;animation:gritou .6s ease}
.viv-gol.deles{background:rgba(127,29,29,.92)}
.viv-gol .grande{font-size:38px;font-weight:900;letter-spacing:-1.5px;line-height:1}
.viv-gol .quem{font-size:15px;font-weight:800}
.viv-gol .quando{font-size:12px;opacity:.85}
@keyframes gritou{0%{transform:scale(.7)}55%{transform:scale(1.08)}100%{transform:scale(1)}}

/* Quem está pressionando agora */
.viv-pressao{display:flex;align-items:center;gap:8px;margin-top:12px;font-size:10.5px;
  text-transform:uppercase;letter-spacing:.5px;color:var(--txt3);font-weight:700}
.viv-pressao .trilho{flex:1;height:5px;border-radius:999px;background:var(--panel3);overflow:hidden}
.viv-pressao .trilho i{display:block;height:100%;background:var(--acento);width:50%;
  transition:width .6s ease}

@media (max-width:520px){
  .viv-campo-caixa{max-width:100%}
  .viv-gol .grande{font-size:30px}
}

/* ── Os números da partida ──────────────────────────── */
.viv-num-linha{display:grid;grid-template-columns:42px 1fr 42px;align-items:center;gap:9px;
  font-size:11.5px;margin-bottom:7px}
.viv-num-linha .n{font-weight:800;font-variant-numeric:tabular-nums;text-align:center}
.viv-num-barra{height:5px;border-radius:999px;background:var(--panel3);overflow:hidden;display:flex}
.viv-num-barra i{display:block;height:100%}
.viv-num-barra i.eu{background:var(--acento)}
.viv-num-barra i.ele{background:var(--borda2)}
.viv-num-rot{font-size:9.5px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;
  text-align:center;margin-top:2px}

/* ── O campo dentro do popup de substituição ────────── */
.campo-troca{max-width:100%;margin:0 auto 4px}
.campo-troca .camisa{cursor:pointer}
.campo-troca .camisa{cursor:grab}
.campo-troca .camisa.alvo .bola{border-color:var(--verde-claro);box-shadow:0 0 0 4px rgba(34,197,94,.45)}
.campo-troca .camisa.sel .bola{border-color:#fff;box-shadow:0 0 0 4px rgba(255,255,255,.32)}
.campo-troca .camisa.trocado .bola{border-color:var(--amarelo);box-shadow:0 0 0 3px rgba(245,158,11,.35)}
.troca-banco{flex-direction:row;flex-wrap:wrap;max-height:26vh}
.troca-banco .troca-op{width:auto}

/* ── O painel de substituição ───────────────────────── */
.troca-cols{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.troca-cols h5{margin:0 0 7px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;
  color:var(--txt3);font-weight:700}
.troca-lista{display:flex;flex-direction:column;gap:5px;max-height:44vh;overflow-y:auto}
.troca-op{display:flex;align-items:center;gap:7px;padding:7px 9px;border-radius:9px;
  border:1px solid var(--borda);background:var(--panel3);font-size:12px;cursor:pointer;
  text-align:left;width:100%;color:inherit}
.troca-op:hover{border-color:var(--borda2)}
.troca-op.sel{border-color:var(--verde);background:rgba(34,197,94,.10)}
.troca-op:disabled{opacity:.4;cursor:not-allowed}
.troca-op .o{font-weight:900;min-width:22px;font-variant-numeric:tabular-nums}
.troca-op .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.troca-op .en{font-size:10px;font-weight:800;padding:1px 5px;border-radius:5px;background:var(--panel)}
.troca-restam{font-size:11.5px;color:var(--txt3);text-align:center;margin-top:2px}
@media (max-width:520px){ .troca-cols{grid-template-columns:1fr} .troca-lista{max-height:26vh} }

/* ── Popup do jogo (nunca o do navegador) ───────────── */
.fundo-popup{position:fixed;inset:0;background:rgba(0,0,0,.66);backdrop-filter:blur(3px);z-index:60;
  display:flex;align-items:center;justify-content:center;padding:16px}
.fundo-popup[hidden]{display:none}
.popup{width:100%;max-width:440px;max-height:86vh;overflow-y:auto;background:var(--panel);
  border:1px solid var(--borda2);border-radius:15px;padding:16px;animation:sobe .18s ease}
@keyframes sobe{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.popup h4{margin:0 0 4px;font-size:15px;font-weight:900;letter-spacing:-.4px;display:flex;align-items:center;gap:8px}
.popup h4 i{color:var(--acento)}
.popup-sub{color:var(--txt2);font-size:12.5px;margin:0 0 14px}
.popup-acoes{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}

.opcoes{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.opcoes-rot{font-size:11px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;
  font-weight:700;margin-bottom:2px}
.opcao{display:block;cursor:pointer;position:relative}
.opcao input{position:absolute;opacity:0;width:0;height:0}
.opcao span{display:block;padding:9px 11px;border-radius:10px;border:1px solid var(--borda);
  background:var(--panel3);font-size:12.5px}
.opcao span b{display:block;font-weight:800;font-size:13px;margin-bottom:1px}
.opcao span i{font-style:normal;color:var(--txt3);font-size:11.5px}
.opcao input:checked + span{border-color:var(--verde);background:rgba(34,197,94,.10)}

@media (max-width:520px){
  .viv-num{font-size:32px}
  .viv-nome{font-size:13px}
  .notas-vivo{grid-template-columns:1fr 1fr}
  .viv-acoes .btn{flex:1}
}

/* ── A prancheta: campo e banco lado a lado ─────────── */
.popup-mesa{max-width:820px}
.mesa{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,260px);gap:14px;align-items:start;margin-top:12px}
.mesa-campo{min-width:0}
.mesa-lado{display:flex;flex-direction:column;gap:12px;min-width:0}
.mesa-bloco{min-width:0}
.mesa-bloco .troca-banco{flex-direction:column;flex-wrap:nowrap;max-height:30vh;overflow-y:auto}
.mesa-bloco .troca-banco .reserva{width:100%;cursor:grab}
.mesa-bloco .troca-banco .reserva .r-nome{flex:1;max-width:none}
.mesa-select{width:100%;padding:8px 9px;border-radius:9px;border:1px solid var(--borda);
  background:var(--panel3);color:inherit;font-size:12.5px;font-weight:700}
.mesa-chips{display:flex;gap:5px;flex-wrap:wrap}
.mesa-chip{flex:1;min-width:64px;padding:7px 6px;border-radius:9px;border:1px solid var(--borda);
  background:var(--panel3);color:var(--txt2);font-size:11.5px;font-weight:800;cursor:pointer}
.mesa-chip:hover{border-color:var(--borda2);color:var(--txt)}
.mesa-chip.on{border-color:var(--verde);background:rgba(34,197,94,.12);color:var(--verde-claro)}
.mesa-nota{font-size:10.5px;color:var(--txt3);margin-top:5px;line-height:1.35}
/* O JEITO DE VOLTAR AO JOGO NÃO ROLA PRA FORA DA TELA. No celular a
   prancheta inteira é uma coluna só, e o botão ficava lá embaixo depois de
   trinta reservas — quem abria no intervalo tinha que caçar a saída. */
.popup-mesa .popup-acoes{position:sticky;bottom:-16px;margin:16px -16px -16px;padding:12px 16px 16px;
  background:linear-gradient(180deg,rgba(0,0,0,0),var(--panel) 30%)}
@media (max-width:760px){
  .popup-mesa .popup-acoes .btn{flex:1}
  /* No celular não existe "ao lado": o campo vem primeiro e o banco logo
     embaixo dele, com o resto da prancheta em seguida. */
  .mesa{grid-template-columns:1fr}
  .mesa-bloco .troca-banco{flex-direction:row;flex-wrap:wrap;max-height:22vh}
  .mesa-bloco .troca-banco .reserva{width:auto}
}

/* ── O resumo do apito final ────────────────────────── */
.popup-fim{max-width:420px;text-align:center}
.fim-veredito{font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:1.2px;
  color:var(--txt3)}
.fim-veredito.v{color:var(--verde-claro)}
.fim-veredito.d{color:#fca5a5}
.fim-placar{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:10px;margin:10px 0 2px}
.fim-placar > b{font-size:30px;font-weight:900;letter-spacing:-1px;font-variant-numeric:tabular-nums;
  white-space:nowrap}
.fim-time{display:flex;flex-direction:column;align-items:center;gap:5px;min-width:0}
.fim-time span{font-size:11.5px;font-weight:700;color:var(--txt2);line-height:1.2;
  overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.fim-comp{font-size:11px;color:var(--txt3);margin-bottom:12px}
.fim-comp i{color:var(--amarelo)}
.fim-numeros{display:flex;flex-direction:column;gap:5px;text-align:left}
.fim-linha{display:grid;grid-template-columns:48px 1fr 48px;align-items:center;gap:8px;
  padding:6px 9px;border-radius:9px;background:var(--panel3);font-size:11.5px}
.fim-linha span{font-weight:900;font-variant-numeric:tabular-nums;text-align:center}
.fim-linha i{font-style:normal;color:var(--txt3);text-align:center;font-size:10.5px;
  text-transform:uppercase;letter-spacing:.4px}
.fim-craque{margin-top:10px;text-align:left}
.popup-fim .popup-acoes{justify-content:center}
.popup-fim .popup-acoes .btn{flex:1}
/* ── Começar a carreira ─────────────────────────────── */
.hero{background:linear-gradient(135deg,var(--panel2),var(--panel));border:1px solid var(--borda);
  border-radius:14px;padding:18px 16px;margin-bottom:12px}
.hero h2{font-size:22px;font-weight:900;letter-spacing:-.7px;margin-bottom:6px}
.hero p{margin:0;color:var(--txt2);font-size:13.5px;max-width:62ch}
.passos{display:flex;gap:14px;margin-top:14px;flex-wrap:wrap}
.passo{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--txt2)}
.passo i{color:var(--acento);font-size:14px}

.campo-busca{margin-bottom:12px}
.conta{margin-left:auto;font-size:11px;color:var(--txt3);font-weight:600;letter-spacing:.3px}

.divisao + .divisao{margin-top:16px}
.divisao-cab{display:flex;align-items:baseline;gap:8px;padding-bottom:7px;margin-bottom:9px;
  border-bottom:1px solid var(--borda)}
.divisao-cab b{font-size:12.5px;font-weight:800;letter-spacing:-.2px}
.divisao-cab span{font-size:11px;color:var(--txt3)}

.grade-clubes{display:grid;grid-template-columns:repeat(auto-fill,minmax(216px,1fr));gap:8px}
.clube-op{display:block;cursor:pointer;position:relative}
.clube-op input{position:absolute;opacity:0;width:0;height:0}
.clube-op-in{display:flex;flex-direction:column;gap:7px;height:100%;padding:10px 11px;border-radius:11px;
  border:1px solid var(--borda);background:var(--panel3);transition:border-color .12s,background .12s}
.clube-op:hover .clube-op-in{border-color:var(--borda2)}
.clube-op input:focus-visible + .clube-op-in{outline:2px solid var(--verde-claro);outline-offset:2px}
.clube-op input:checked + .clube-op-in{border-color:var(--verde);background:rgba(34,197,94,.09)}
.clube-op-cab{display:flex;align-items:center;gap:9px;min-width:0}
.clube-op-txt{min-width:0;flex:1}
.clube-op-nome{display:block;font-weight:800;font-size:13.5px;letter-spacing:-.3px;line-height:1.2;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.clube-op-sub{display:block;font-size:11px;color:var(--txt3);margin-top:1px}

/* ── As duas portas de entrada ───────────────────────────────────────
   Dois cartões grandes e não um par de radios miúdos: é a primeira decisão
   do jogo e ela precisa parecer uma decisão. O texto de baixo existe porque
   sem ele as duas opções pareceriam a mesma coisa com nomes diferentes. */
.portas{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px}
.porta input{position:absolute;opacity:0;pointer-events:none}
.porta-in{display:block;padding:13px 14px;border-radius:12px;border:1px solid var(--borda);
  background:var(--panel2);cursor:pointer;height:100%;transition:border-color .15s,background .15s}
.porta:hover .porta-in{border-color:var(--borda2)}
.porta input:checked + .porta-in{border-color:var(--acento);
  background:color-mix(in srgb, var(--acento) 10%, var(--panel2))}
.porta input:focus-visible + .porta-in{outline:2px solid var(--acento);outline-offset:2px}
.porta-in > .bi{font-size:17px;color:var(--acento)}
.porta-tit{display:block;font-weight:800;font-size:14px;margin-top:5px}
.porta-sub{display:block;font-size:11.5px;color:var(--txt2);margin-top:3px;line-height:1.45}
.porta-nota{font-size:11.5px;color:var(--txt3);margin:0 0 10px;line-height:1.45}
.clube-op-meta{display:flex;gap:6px;align-items:flex-start;font-size:11px;color:var(--txt2);
  padding-top:7px;border-top:1px solid var(--borda);line-height:1.35}
.clube-op-meta i{color:var(--amarelo);font-size:11px;margin-top:1px;flex-shrink:0}

.barra-comecar{position:sticky;bottom:0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  padding:12px 14px;margin:14px -14px -80px;background:var(--panel2);border-top:1px solid var(--borda)}
.barra-comecar .escolhido{font-size:13px;color:var(--txt2);flex:1;min-width:140px}
.barra-comecar .escolhido b{color:var(--txt)}
.sem-resultado{padding:18px 4px;color:var(--txt3);font-size:13px;text-align:center}

@media (max-width:520px){
  .hero{padding:15px 14px}
  .hero h2{font-size:19px}
  .grade-clubes{grid-template-columns:1fr 1fr;gap:7px}
  .clube-op-in{padding:9px}
  .clube-op-nome{font-size:12.5px}
  .clube-op-meta{font-size:10.5px}
  .barra-comecar .btn{width:100%}
}
@media (max-width:360px){ .grade-clubes{grid-template-columns:1fr} }

/* ── Tabelas ────────────────────────────────────────── */
.rolar{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -14px;padding:0 14px}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:460px}
th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--txt3);
  padding:7px;border-bottom:1px solid var(--borda2);font-weight:700;white-space:nowrap}
/* ZEBRA NO LUGAR DE UM FIO POR LINHA. Numa tabela de 20 clubes, vinte fios da
   mesma cor viram grade — e grade é o que faz uma tabela parecer planilha. A
   faixa alternada separa igual e não desenha nada. */
td{padding:7px;border:0;white-space:nowrap}
tbody tr:nth-child(even) td{background:rgba(255,255,255,.022)}
tbody tr:hover td{background:rgba(255,255,255,.045)}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
/* A LINHA DO SEU CLUBE NA COR DO SEU CLUBE — era verde em toda carreira, o
   que deixava o técnico do Porto com a própria linha destoando da página. */
tr.eu td,tbody tr.eu:nth-child(even) td{
  background:rgba(255,255,255,.07);
  background:color-mix(in srgb, var(--acento) 13%, transparent);font-weight:700}
tr.eu td:first-child{box-shadow:inset 3px 0 0 var(--acento)}
.pos{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:6px;
  background:var(--panel3);font-size:11px;font-weight:800;color:var(--txt2)}
.pos.sobe{background:rgba(34,197,94,.20);color:var(--verde-claro)}
/* A FAIXA DE BAIXO DA EUROPA — Liga Europa e Conference. Não pode ser o
   mesmo verde da Champions: são vagas de valor muito diferente, e pintar as
   sete primeiras da mesma cor diria que terminar em sétimo é o mesmo que
   terminar em primeiro. */
.pos.euro{background:rgba(59,130,246,.18);color:#93c5fd}
.pos.cai{background:rgba(239,68,68,.18);color:#fca5a5}

.ovr{display:inline-flex;align-items:center;justify-content:center;min-width:28px;padding:2px 6px;border-radius:6px;
  font-weight:800;font-size:12px;background:var(--panel3);border:1px solid var(--borda)}
.ovr.b{background:rgba(34,197,94,.18);border-color:rgba(34,197,94,.35);color:var(--verde-claro)}
.ovr.m{background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.3);color:var(--amarelo)}
.tagpos{font-size:10px;font-weight:800;color:var(--txt3);letter-spacing:.4px}
/* Cabeçalho que ordena. Precisa PARECER clicável antes de alguém tentar —
   por isso o sublinhado pontilhado, que some quando a coluna está ativa
   (aí a seta já diz o que está acontecendo). */
.th-ord{display:inline-flex;align-items:center;gap:3px;text-decoration:none;color:inherit;
  border-bottom:1px dotted var(--borda2);cursor:pointer}
.th-ord:hover{color:var(--txt2)}
.th-ord.on{color:var(--acento);border-bottom-color:transparent}
.th-ord .bi{font-size:9px}
/* Escudo e nome na mesma célula, alinhados pela base do texto. */
.clube-cel{display:inline-flex;align-items:center;gap:8px;border-bottom:0}
.clube-cel span{border-bottom:1px solid transparent}
.clube-cel:hover span{border-bottom-color:var(--acento)}

/* ── Resultado da partida ───────────────────────────── */
.partida{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--borda)}
.partida:last-child{border-bottom:0}
.partida .comp{font-size:10px;color:var(--txt3);text-transform:uppercase;letter-spacing:.4px}
.partida .adv{font-weight:700;font-size:13.5px}
.placar{margin-left:auto;font-weight:900;font-size:15px;padding:4px 10px;border-radius:8px;
  background:var(--panel3);font-variant-numeric:tabular-nums;flex-shrink:0}
.placar.v{background:rgba(34,197,94,.18);color:var(--verde-claro)}
.placar.d{background:rgba(239,68,68,.16);color:#fca5a5}

.msg{padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:13px;display:flex;gap:8px;align-items:flex-start}
.msg.ok{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.3)}
.msg.err{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.3)}

.vazio{text-align:center;padding:26px 14px;color:var(--txt3);font-size:13px}

/* ── Resumo da partida ──────────────────────────────── */
.resumo-topo{display:flex;align-items:center;gap:10px;margin-bottom:4px}
.resumo-time{display:flex;align-items:center;gap:8px;flex:1;min-width:0;font-weight:800;font-size:13.5px}
.resumo-time.dir{justify-content:flex-end;text-align:right}
.resumo-time span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.resumo-placar{font-size:26px;font-weight:900;letter-spacing:-1px;font-variant-numeric:tabular-nums;flex-shrink:0}
.resumo-placar span{color:var(--txt3);font-weight:400;margin:0 2px}
.lance{display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13px;border-bottom:1px solid var(--borda)}
.lance:last-of-type{border-bottom:0}
.lance.deles{opacity:.62}
.lance .min{font-size:11px;color:var(--txt3);width:28px;flex-shrink:0;font-variant-numeric:tabular-nums}
.lance .quem{font-weight:700}
.lance .det{font-size:11.5px;color:var(--txt3)}
.cartao{display:inline-block;width:9px;height:13px;border-radius:2px;flex-shrink:0}
.cartao.ama{background:#fbbf24}
.cartao.ver{background:var(--vermelho)}
.nota{display:inline-flex;align-items:center;justify-content:center;min-width:34px;padding:2px 6px;border-radius:6px;
  font-weight:800;font-size:12px;background:var(--panel3);border:1px solid var(--borda);font-variant-numeric:tabular-nums}
.nota.alta{background:rgba(34,197,94,.18);border-color:rgba(34,197,94,.35);color:var(--verde-claro)}
.nota.baixa{background:rgba(239,68,68,.15);border-color:rgba(239,68,68,.3);color:#fca5a5}
.selo-venda{display:inline-block;margin-left:5px;padding:1px 6px;border-radius:5px;font-size:9.5px;
  font-weight:800;letter-spacing:.3px;text-transform:uppercase;background:rgba(34,197,94,.16);
  border:1px solid rgba(34,197,94,.35);color:var(--verde-claro);vertical-align:middle}

/* ── O campo ────────────────────────────────────────── */
.campo{position:relative;width:100%;max-width:340px;margin:0 auto 14px;aspect-ratio:68/96;
  background:linear-gradient(175deg,#15803d,#166534 55%,#14532d);
  border:2px solid rgba(255,255,255,.18);border-radius:10px;overflow:hidden}
/* As listras do gramado, o círculo central e as áreas: só CSS, sem imagem. */
.campo::before{content:'';position:absolute;inset:0;
  background:repeating-linear-gradient(180deg,rgba(255,255,255,.045) 0 9%,transparent 9% 18%)}
.campo .linha-meio{position:absolute;left:0;right:0;top:50%;height:2px;background:rgba(255,255,255,.22)}
.campo .circulo{position:absolute;left:50%;top:50%;width:26%;aspect-ratio:1;transform:translate(-50%,-50%);
  border:2px solid rgba(255,255,255,.22);border-radius:50%}
.campo .area{position:absolute;left:50%;transform:translateX(-50%);width:54%;height:15%;
  border:2px solid rgba(255,255,255,.2)}
.campo .area.cima{top:0;border-top:0}
.campo .area.baixo{bottom:0;border-bottom:0}
.camisa{position:absolute;transform:translate(-50%,-50%);display:flex;flex-direction:column;align-items:center;
  gap:2px;width:60px;text-align:center}
/* A BOLA COM A COR DO CLUBE. Onze círculos cinza-escuros sobre o gramado é
   um diagrama; onze círculos azuis é o Porto entrando em campo. O contorno
   claro segura a leitura do número em cima de qualquer cor — inclusive nas
   camisas claras, onde o fundo do círculo fica mais escuro que o sotaque. */
.camisa .bola{width:30px;height:30px;border-radius:50%;
  background:var(--acento);border:2px solid rgba(255,255,255,.55);
  background:color-mix(in srgb, var(--acento) 78%, #04070d);
  border:2px solid color-mix(in srgb, var(--acento) 55%, #ffffff);
  display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;color:#fff;
  box-shadow:0 2px 6px rgba(0,0,0,.35)}
.camisa .bola.improv{border-color:#fbbf24;background:#78350f}
.camisa .bola.vazio{border-style:dashed;opacity:.55}
.camisa .bola{color:var(--acento-txt)}
.camisa .nom{font-size:9.5px;font-weight:700;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.9);
  line-height:1.15;max-width:60px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.camisa .vg{font-size:8px;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.3px}

.cond{display:inline-flex;align-items:center;gap:3px;font-size:10.5px;font-weight:800;
  padding:2px 7px;border-radius:6px;background:var(--panel3);border:1px solid var(--borda)}
.cond.verde{color:var(--verde-claro)}
.cond.amarelo{color:var(--amarelo)}
.cond.vermelho{color:#fca5a5;background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.3)}
.proposta{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--borda)}
.proposta:last-of-type{border-bottom:0}
.noticia{font-size:12.5px;color:var(--txt2);padding:3px 0;line-height:1.45}

/* ── Banco e escalação interativa ───────────────────── */
.dica-drag{display:flex;align-items:center;gap:7px;padding:8px 10px;border-radius:9px;margin-bottom:10px;
  background:var(--panel3);border:1px solid var(--borda);font-size:12px;color:var(--txt2)}
.dica-drag i{color:var(--acento)}
.slot{cursor:grab;user-select:none;-webkit-user-select:none;touch-action:manipulation}
.slot:active{cursor:grabbing}
.slot:focus-visible{outline:2px solid var(--verde-claro);outline-offset:2px}
.camisa.sel .bola{border-color:#fff;box-shadow:0 0 0 3px rgba(255,255,255,.35),0 2px 8px rgba(0,0,0,.5);
  transform:scale(1.12)}
.camisa.alvo .bola{border-color:var(--verde-claro);box-shadow:0 0 0 4px rgba(34,197,94,.4)}

/* ── Onde o jogador escolhido cabe ───────────────────────────────────
   O anel pulsando é forte de propósito na posição dele e discreto nas que
   servem: a diferença entre as duas faixas tem que dar pra ver de relance,
   senão viram a mesma informação. O tracejado da segunda faixa diz
   "improviso barato" sem precisar de legenda. */
.slot.cabe .bola{border-color:#fff;box-shadow:0 0 0 3px var(--acento),0 0 14px 2px rgba(255,255,255,.25)}
.slot.serve .bola{border-style:dashed;border-color:color-mix(in srgb, var(--acento) 70%, #ffffff);
  box-shadow:0 0 0 2px color-mix(in srgb, var(--acento) 45%, transparent)}
.slot.cabe .nom,.slot.serve .nom{color:#fff}
@media (prefers-reduced-motion:no-preference){
  .slot.cabe .bola{animation:cabePulsa 1.4s ease-in-out infinite}
}
@keyframes cabePulsa{
  0%,100%{box-shadow:0 0 0 3px var(--acento),0 0 14px 2px rgba(255,255,255,.25)}
  50%{box-shadow:0 0 0 6px color-mix(in srgb, var(--acento) 35%, transparent),0 0 18px 3px rgba(255,255,255,.3)}
}
.camisa .bola{transition:transform .12s,box-shadow .12s,border-color .12s}

.banco{margin-bottom:12px}
.banco-titulo{font-size:12px;font-weight:800;margin-bottom:7px;display:flex;align-items:center;gap:6px}
.banco-titulo i{color:var(--acento)}
.banco-lista{display:flex;flex-wrap:wrap;gap:6px}
.reserva{display:flex;align-items:center;gap:6px;padding:6px 9px;border-radius:9px;
  background:var(--panel3);border:1px solid var(--borda);font-size:12px}
.reserva.sel{border-color:#fff;background:var(--panel2);box-shadow:0 0 0 2px rgba(255,255,255,.25)}
.reserva.alvo{border-color:var(--verde-claro);box-shadow:0 0 0 2px rgba(34,197,94,.35)}
.reserva .r-ovr{font-weight:900;min-width:22px;text-align:center}
.reserva .r-nome{font-weight:600;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.reserva .r-pos{font-size:9.5px;font-weight:800;color:var(--txt3);letter-spacing:.3px}
.reserva .r-en{font-size:10px;font-weight:800;padding:1px 5px;border-radius:5px;background:var(--panel)}
.reserva .r-en.verde{color:var(--verde-claro)}
.reserva .r-en.amarelo{color:var(--amarelo)}
.reserva .r-en.vermelho{color:#fca5a5}
@keyframes pulsa{0%,100%{transform:scale(1)}50%{transform:scale(1.04)}}
.btn.pulsa{animation:pulsa 1.1s ease-in-out infinite}
@media (prefers-reduced-motion:reduce){.btn.pulsa{animation:none}}

/* ── Celular ────────────────────────────────────────────────────────
   AS QUATRO FICHAS VIRAM 2x2, e aí o fio da esquerda que separa colunas
   passa a cortar no lugar errado: a terceira ficha começa uma linha nova e
   herdava o fio como se fosse vizinha da segunda. Aqui a grade tem folga
   vertical e só a coluna da direita leva fio. */
@media (max-width:560px){
  #app{padding:12px 12px 80px}
  .fichas{grid-template-columns:repeat(2,1fr);row-gap:12px}
  .ficha{padding-left:12px}
  .ficha:nth-child(odd){border-left:0;padding-left:0}
  table{min-width:420px}
}
</style>
</head>
<body>
<div id="app">

  <?php
    /* ── O TOPO É A BARRA DE FERRAMENTAS ──────────────────────────────
       Duas coisas que se quer a qualquer momento e de qualquer aba: entrar no
       próximo jogo e achar alguém. Estavam as duas enterradas — o jogo só no
       card do Início, depois de rolar; a busca não existia. Aqui elas grudam
       no alto da tela e seguem a rolagem.

       Durante a partida a barra some junto com o resto: lá não há "próximo
       jogo" nem motivo pra procurar ninguém. */
    /* NAO reusa $emCampo: ele so nasce la embaixo, depois desta barra, e o
       mesmo nome guarda OUTRA COISA dentro do tratador de POST (a escalacao
       atual, uma lista). Duas variaveis com o mesmo nome e sentidos diferentes
       no mesmo arquivo e o tipo de coisa que passa despercebida ate o dia em
       que passa. */
    $naPartida   = ($aba === 'partida' && !empty($estado['aovivo']));
    $mostraBarra = $estado && !$naPartida;
    $podeJogar   = $mostraBarra && ($estado['fase'] ?? '') === 'temporada' && $proximo;
  ?>
  <div class="topo <?= $mostraBarra ? 'com-busca' : '' ?>">
    <a href="../games.php" class="voltar" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <div class="marca"><i class="bi bi-trophy-fill"></i> Carreira</div>

    <?php if ($mostraBarra): ?>
      <div class="busca-caixa">
        <i class="bi bi-search"></i>
        <input type="search" id="buscaGeral" autocomplete="off" spellcheck="false"
               placeholder="Buscar jogador, clube ou competição"
               aria-label="Buscar jogador, clube ou competição">
        <div class="busca-lista" id="buscaLista" hidden></div>
      </div>

      <?php if ($podeJogar): ?>
        <form method="post" class="topo-jogar">
          <input type="hidden" name="acao" value="jogar">
          <button class="btn peq" title="<?= h($proximo['adversario']) ?> · <?= h($proximo['comp']) ?>">
            <i class="bi bi-play-fill"></i> Jogar
          </button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php if ($idUsuario <= 0): ?>
  <div class="bloco">
    <h3><i class="bi bi-person-lock"></i> Precisa entrar</h3>
    <p style="color:var(--txt2);margin:0 0 12px">
      A carreira dura temporadas e fica salva na sua conta. Entre na FBA pra começar a sua.
    </p>
    <a class="btn" href="../../login.php"><i class="bi bi-box-arrow-in-right"></i> Entrar</a>
  </div>

<?php elseif (!$estado): ?>
  <?php
    /* OS CLUBES AGRUPADOS POR DIVISÃO. Eram 64 num <select>, uma lista de
       nomes onde escolher o primeiro clube da carreira — que é a decisão que
       define as próximas temporadas — dava o mesmo trabalho que escolher um
       item de menu. Em card dá pra ver o escudo, a força e, principalmente, o
       que a diretoria vai cobrar: é isso que separa assumir um clube de
       assumir outro. */
    $disponiveis = futClubesParaComecar(10);
    $porDivisao = [];
    foreach ($disponiveis as $nome => $c) $porDivisao[$c['div']][$nome] = $c;
    ksort($porDivisao);

    /* Os cinco que procuram um técnico sem nome. A semente é do usuário, então
       recarregar a página não troca os convites — ver futConvitesDeEstreia. */
    $convites = futConvitesDeEstreia(crc32('estreia|' . $idUsuario));
  ?>
  <div class="hero">
    <h2>Comece de baixo</h2>
    <p>Você ainda não tem currículo, então os grandes não te atendem. Cumpra a meta que a
       diretoria cobra, ganhe reputação, e os convites melhores aparecem sozinhos —
       até a Premier League, a La Liga, a Serie A, a Bundesliga, a Ligue 1 e a Liga Portugal,
       que estão no jogo e esperam por currículo.</p>
    <div class="passos">
      <span class="passo"><i class="bi bi-1-circle-fill"></i> Assuma um clube</span>
      <span class="passo"><i class="bi bi-2-circle-fill"></i> Cumpra a meta da temporada</span>
      <span class="passo"><i class="bi bi-3-circle-fill"></i> Suba de divisão</span>
      <span class="passo"><i class="bi bi-4-circle-fill"></i> Atravesse o Atlântico</span>
    </div>
  </div>


  <form method="post" id="fmComecar">
    <input type="hidden" name="acao" value="comecar">

    <div class="bloco">
      <h3><i class="bi bi-person-badge"></i> Seu nome</h3>
      <input type="text" id="tecnico" name="tecnico" maxlength="40"
             placeholder="Como você quer ser chamado na beira do campo" required>
    </div>

    <div class="bloco">
      <h3><i class="bi bi-signpost-split"></i> Como você quer começar</h3>
      <div class="portas">
        <label class="porta">
          <input type="radio" name="modo" value="lista" checked>
          <span class="porta-in">
            <i class="bi bi-hand-index-thumb"></i>
            <span class="porta-tit">Eu escolho o clube</span>
            <span class="porta-sub">Os <?= count($disponiveis) ?> times que aceitam um técnico sem
              currículo. Você olha um por um e decide.</span>
          </span>
        </label>
        <label class="porta">
          <input type="radio" name="modo" value="convites">
          <span class="porta-in">
            <i class="bi bi-telephone"></i>
            <span class="porta-tit">Começo desempregado</span>
            <span class="porta-sub"><?= count($convites) ?> clubes te procuram, e só eles. Menos
              escolha — mas quem liga primeiro costuma mirar um pouco mais alto.</span>
          </span>
        </label>
      </div>
    </div>

    <div class="bloco" id="caixaConvites" hidden>
      <h3><i class="bi bi-telephone-fill"></i> Quem te procurou</h3>
      <p class="porta-nota">Foram esses que ligaram. Recarregar a página não muda a lista —
         num começo de carreira o técnico pega o que aparece.</p>
      <div class="grade-clubes">
        <?php foreach ($convites as $nome => $c): ?>
          <?php $meta = futMetaDaTemporada($c); ?>
          <label class="clube-op">
            <input type="radio" name="clube" value="<?= h($nome) ?>" disabled
                   data-nome="<?= h($nome) ?>" data-meta="<?= h($meta['texto']) ?>">
            <span class="clube-op-in">
              <span class="clube-op-cab">
                <?= escudo($c, 28) ?>
                <span class="clube-op-txt">
                  <span class="clube-op-nome"><?= h($nome) ?></span>
                  <span class="clube-op-sub"><?= h($c['uf'] ?: futPaisDoClube($c)) ?>
                    · <?= h(futCarreiraRotuloDaDivisao((string)$c['div'])) ?></span>
                </span>
              </span>
              <span class="clube-op-meta">
                <i class="bi bi-bullseye"></i><?= h($meta['texto']) ?>
              </span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="bloco" id="caixaLista">
      <h3><i class="bi bi-shield-fill"></i> O clube
        <span class="conta"><?= count($disponiveis) ?> disponíveis</span></h3>
      <input type="search" id="buscaClube" class="campo-busca" autocomplete="off"
             placeholder="Buscar por clube ou estado">

      <div id="listaClubes">
      <?php foreach ($porDivisao as $div => $lista): ?>
        <div class="divisao">
          <div class="divisao-cab">
            <b><?= h(futCarreiraRotuloDaDivisao((string)$div)) ?></b>
            <span><?= count($lista) ?> clube<?= count($lista) === 1 ? '' : 's' ?></span>
          </div>
          <div class="grade-clubes">
            <?php foreach ($lista as $nome => $c): ?>
              <?php $meta = futMetaDaTemporada($c); ?>
              <label class="clube-op" data-busca="<?= h(mb_strtolower($nome . ' ' . ($c['uf'] ?: futPaisDoClube($c)))) ?>">
                <input type="radio" name="clube" value="<?= h($nome) ?>"
                       data-nome="<?= h($nome) ?>" data-meta="<?= h($meta['texto']) ?>">
                <span class="clube-op-in">
                  <span class="clube-op-cab">
                    <?= escudo($c, 28) ?>
                    <span class="clube-op-txt">
                      <?php /* A FORÇA SAIU DAQUI. Ela transformava a escolha
                                do primeiro clube numa conta: todo mundo pegava
                                o maior número da lista e pronto. Sem ela sobra
                                o que devia pesar — o escudo, de onde o clube é,
                                e o que a diretoria vai cobrar logo abaixo. */ ?>
                      <span class="clube-op-nome"><?= h($nome) ?></span>
                      <span class="clube-op-sub"><?= h($c['uf'] ?: futPaisDoClube($c)) ?></span>
                    </span>
                  </span>
                  <span class="clube-op-meta">
                    <i class="bi bi-bullseye"></i><?= h($meta['texto']) ?>
                  </span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <div class="sem-resultado" id="semResultado" hidden>Nenhum clube com esse nome.</div>
    </div>

    <div class="barra-comecar">
      <div class="escolhido" id="escolhido">Escolha um clube para começar.</div>
      <button class="btn" type="submit" id="btComecar" disabled>
        <i class="bi bi-play-fill"></i> Assumir o clube
      </button>
    </div>
  </form>

  <script>
  (function () {
    var form = document.getElementById('fmComecar');
    if (!form) return;
    var bt = document.getElementById('btComecar');
    var aviso = document.getElementById('escolhido');
    var busca = document.getElementById('buscaClube');
    var vazio = document.getElementById('semResultado');

    var caixaLista = document.getElementById('caixaLista');
    var caixaConvites = document.getElementById('caixaConvites');

    /* ── A PORTA ESCOLHIDA MANDA NO FORMULÁRIO ───────────────────────
       Esconder a outra lista não basta: um radio escondido continua sendo
       enviado se estiver marcado, e o jogador que clicasse num clube da
       lista e depois trocasse pra "desempregado" mandaria os dois. Por isso
       a lista que sai fica DESABILITADA — campo desabilitado não viaja — e a
       escolha anterior é desmarcada junto. */
    function trocarPorta() {
      var porConvite = form.querySelector('input[name=modo]:checked').value === 'convites';
      caixaLista.hidden = porConvite;
      caixaConvites.hidden = !porConvite;

      form.querySelectorAll('input[name=clube]').forEach(function (r) {
        var meu = porConvite === !!r.closest('#caixaConvites');
        r.disabled = !meu;
        if (!meu) r.checked = false;
      });

      bt.disabled = !form.querySelector('input[name=clube]:checked');
      if (bt.disabled) aviso.textContent = 'Escolha um clube para começar.';
    }

    form.addEventListener('change', function (e) {
      var r = e.target;
      if (r && r.name === 'modo') { trocarPorta(); return; }
      if (!r || r.name !== 'clube') return;
      bt.disabled = false;
      aviso.innerHTML = 'Você vai assumir o <b>' + r.dataset.nome + '</b> — ' + r.dataset.meta + '.';
    });
    trocarPorta();

    /* A busca é local: com 64 clubes, achar o seu time não pode custar uma
       ida ao servidor. Esconde o cabeçalho da divisão que ficou sem ninguém. */
    busca.addEventListener('input', function () {
      var termo = busca.value.trim().toLowerCase();
      var achou = 0;
      document.querySelectorAll('.divisao').forEach(function (bloco) {
        var visiveis = 0;
        bloco.querySelectorAll('.clube-op').forEach(function (op) {
          var bate = !termo || op.dataset.busca.indexOf(termo) !== -1;
          op.hidden = !bate;
          if (bate) visiveis++;
        });
        bloco.hidden = visiveis === 0;
        achou += visiveis;
      });
      vazio.hidden = achou > 0;
    });
  })();
  </script>
<?php else: ?>
  <?php
    $campNac = futCarreiraCampanha($estado, futCarreiraNomeDaDivisao($clubesTodos[$estado['clube']]['div'] ?? ''));
    $posicao = futCarreiraMinhaPosicao($estado);
    $folha = futFolhaDoElenco($estado['elenco']);
  ?>

  <?php
    /* EM CAMPO A TELA É SÓ A PARTIDA: o cartão do clube e as abas saem da
       frente, porque durante o jogo não há mais nada pra fazer. */
    $emCampo = ($aba === 'partida' && !empty($estado['aovivo']));
    $vivo = $estado['aovivo'] ?? null;
  ?>

  <?php if ($emCampo): ?>
    <?php
      $advC = $clubesTodos[$vivo['adversario']] ?? ['nome' => $vivo['adversario']];
      $euC  = $clubesTodos[$estado['clube']] ?? ['nome' => $estado['clube']];
      $casa = (bool)$vivo['casa'];
      $estr = $estado['estrategia'] ?? ['postura' => 'neutro', 'marcacao' => 'normal'];
    ?>
    <div class="viv">
      <div class="viv-topo">
        <span class="viv-comp">
          <i class="bi bi-trophy"></i> <b><?= h($vivo['comp']) ?></b>
          <?= $vivo['fase'] ? '· ' . h($vivo['fase']) : '' ?>
          · <?= $casa ? 'em casa' : 'fora' ?>
        </span>
        <span class="viv-tempo" id="rlEtiqueta">1º tempo</span><span class="relogio parado" id="relogio"><span class="bolinha"></span><b id="rlMin">0</b>'</span>
      </div>

      <div class="viv-placar">
        <div class="viv-time">
          <?= escudo($casa ? $euC : $advC, 30) ?>
          <span class="viv-nome"><?= h($casa ? $estado['clube'] : $vivo['adversario']) ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
          <span class="viv-num" id="plCasa"><?= (int)($casa ? $vivo['meus'] : $vivo['deles']) ?></span>
          <span class="viv-x">×</span>
          <span class="viv-num" id="plFora"><?= (int)($casa ? $vivo['deles'] : $vivo['meus']) ?></span>
        </div>
        <div class="viv-time dir">
          <?= escudo($casa ? $advC : $euC, 30) ?>
          <span class="viv-nome"><?= h($casa ? $vivo['adversario'] : $estado['clube']) ?></span>
        </div>
      </div>

      <div class="viv-barra"><span id="barraTempo"></span></div>

      <div class="viv-mesa">
      <div>
      <div class="viv-campo-caixa">
        <div class="campo" id="campoVivo">
          <div class="linha-meio"></div><div class="circulo"></div>
          <div class="area cima"></div><div class="area baixo"></div>
        </div>
        <div class="viv-agora" id="lanceAgora"></div>
        <div class="viv-gol" id="telaGol">
          <div class="grande">GOL!</div>
          <div class="quem" id="golQuem"></div>
          <div class="quando" id="golQuando"></div>
        </div>
      </div>

      <div class="viv-pressao">
        <span id="pressaoEu">Você</span>
        <span class="trilho"><i id="pressaoBarra"></i></span>
        <span id="pressaoEle">Eles</span>
      </div>
      </div><?php // fecha a coluna do campo ?>

      <div class="viv-lado">
        <?php
          /* ── QUEM ESTÁ EM CAMPO, COM NOTA ───────────────────────────
             Aqui ficava a narração: uma lista de frases que rolava pra cima
             e que ninguém lia, porque o lance já acontece no campo logo ao
             lado — a camisa acende, o placar vira, o "GOL!" toma a tela. O
             que faltava era o oposto: o boletim. Quem está jogando, quem
             está indo bem, quem fez gol e quem está pendurado — a informação
             que muda a decisão de substituir.

             AS MARCAS VÃO PRO JAVASCRIPT TAMBÉM (marcasVivo). A lista é
             redesenhada a cada troca, e sem elas o gol do camisa 9 sumiria
             da tela no minuto em que entrasse alguém no lugar do lateral. */
          /* A NOTA TAMBEM VEM DO SERVIDOR. Quem recarrega a pagina no meio da
             partida ja jogou trinta minutos: mostrar um traco ate o relogio
             andar de novo faria a tela parecer que o jogo nao comecou. */
          $notasVivo = futCarreiraAoVivoNotas($estado);
          $marcasVivo = [];
          foreach ($vivo['gols'] ?? [] as $g) {
            $n = $g['autor']['nome'] ?? '';
            if ($n === '') continue;
            $marcasVivo[$n]['gols'] = ($marcasVivo[$n]['gols'] ?? 0) + 1;
          }
          foreach ($vivo['cartoes'] ?? [] as $c) {
            $n = $c['jogador']['nome'] ?? '';
            if ($n === '') continue;
            $marcasVivo[$n]['cartao'] = ($c['tipo'] ?? '') === 'vermelho' ? 'ver' : 'ama';
          }
        ?>
        <div class="bloco">
          <h3><i class="bi bi-list-check"></i> Em campo</h3>
          <div class="esc-vivo" id="escalacaoVivo">
            <?php foreach (futCarreiraEscalacaoAtual($estado) as $j): ?>
              <?php $m = $marcasVivo[$j['nome']] ?? []; ?>
              <div class="esc-linha" data-nome="<?= h($j['nome']) ?>">
                <span class="tagpos"><?= h($j['pos']) ?></span>
                <span class="nm"><?= h($j['nome']) ?></span>
                <span class="mk">
                  <?php for ($g = 0; $g < (int)($m['gols'] ?? 0); $g++): ?>
                    <i class="bi bi-dribbble"></i>
                  <?php endfor; ?>
                  <?php if (!empty($m['cartao'])): ?>
                    <span class="cartao <?= h($m['cartao']) ?>"></span>
                  <?php endif; ?>
                </span>
                <?php $nt = $notasVivo[$j['nome']] ?? null; ?>
                <span class="n <?= $nt === null ? '' : ($nt >= 7 ? 'boa' : ($nt < 5.5 ? 'ruim' : '')) ?>"><?=
                  $nt === null ? '—' : number_format((float)$nt, 1, ',', '') ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="bloco">
          <h3><i class="bi bi-bar-chart-fill"></i> Números da partida</h3>
          <div id="numerosVivo"></div>
        </div>
      </div>
      </div><?php // fecha .viv-mesa ?>

      <?php /* ── UM BOTÃO SÓ ─────────────────────────────────────────
           Eram quatro — Começar, Pausar, Estratégia e Substituir — e os três
           últimos faziam a mesma coisa antes de fazer a sua: parar o jogo.
           Pausar É abrir a prancheta, porque não existe motivo pra parar a
           partida que não seja mexer no time. E "Começar" sumiu: quem clicou
           em Jogar na tela anterior já disse que quer jogar. */ ?>
      <div class="viv-acoes">
        <button class="btn" id="btPausar"><i class="bi bi-pause-fill"></i> Pausar e mexer no time</button>
        <button class="btn" id="btVoltar" hidden><i class="bi bi-play-fill"></i> Continuar</button>
      </div>
    </div>



    <?php /* ── A PRANCHETA ──────────────────────────────────────────
         Campo e banco LADO A LADO, e não um embaixo do outro: substituir é
         comparar quem está em campo com quem está sentado, e com o banco
         embaixo da dobra a comparação virava rolar pra cima e pra baixo.

         A estratégia e a formação vêm junto porque são a mesma decisão — o
         técnico para o jogo uma vez e resolve tudo. Três popups pra três
         partes da mesma prancheta era o desenho antigo. */ ?>
    <div class="fundo-popup" id="popTroca" hidden>
      <div class="popup popup-mesa">
        <h4><i class="bi bi-clipboard2-pulse"></i> <span id="mesaTitulo">Prancheta</span></h4>

        <div class="mesa">
          <div class="mesa-campo">
            <div class="campo campo-troca" id="campoTroca"></div>
            <div class="troca-restam" id="trocaRestam"></div>
          </div>

          <div class="mesa-lado">
            <div class="mesa-bloco">
              <div class="opcoes-rot">Banco — arraste até quem sai</div>
              <div class="troca-lista troca-banco" id="listaEntra"></div>
            </div>

            <div class="mesa-bloco">
              <div class="opcoes-rot">Formação</div>
              <select id="mesaEsquema" class="mesa-select">
                <?php foreach (FUT_ESQUEMAS as $k => $e): ?>
                  <option value="<?= h($k) ?>" <?= ($estado['esquema'] ?? '4-4-2') === $k ? 'selected' : '' ?>>
                    <?= h($e['nome']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="mesa-nota" id="mesaEsquemaNota">Mudar de esquema não gasta substituição — os mesmos onze trocam de lugar.</div>
            </div>

            <div class="mesa-bloco">
              <div class="opcoes-rot">Postura</div>
              <div class="mesa-chips" data-campo="postura">
                <?php foreach (FUT_POSTURAS as $k => $o): ?>
                  <button type="button" class="mesa-chip <?= ($estr['postura'] ?? 'neutro') === $k ? 'on' : '' ?>"
                          data-valor="<?= h($k) ?>" title="<?= h($o['desc']) ?>"><?= h($o['nome']) ?></button>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="mesa-bloco">
              <div class="opcoes-rot">Marcação</div>
              <div class="mesa-chips" data-campo="marcacao">
                <?php foreach (FUT_MARCACOES as $k => $o): ?>
                  <button type="button" class="mesa-chip <?= ($estr['marcacao'] ?? 'normal') === $k ? 'on' : '' ?>"
                          data-valor="<?= h($k) ?>" title="<?= h($o['desc']) ?>"><?= h($o['nome']) ?></button>
                <?php endforeach; ?>
              </div>
              <div class="mesa-nota">Vale do minuto seguinte em diante — o que já passou não muda.</div>
            </div>
          </div>
        </div>

        <div class="popup-acoes">
          <button type="button" class="btn" data-fechar><i class="bi bi-play-fill"></i> Voltar ao jogo</button>
        </div>
      </div>
    </div>

    <?php /* O popup de estratégia sumiu: postura e marcação viraram
         duas fileiras de botões dentro da prancheta, e aplicam no
         clique. Um popup por cima de outro pra escolher duas coisas
         era caminho demais pra uma decisão de meio segundo. */ ?>

    <?php /* ── O APITO FINAL ────────────────────────────────────────
         O jogo acabou e não há mais nada pra decidir, então o resumo vem
         sozinho — antes era um botão "Encerrar e ver o resumo" que deixava
         a partida parada esperando alguém achá-lo. Placar, veredito, os
         números e o melhor em campo, porque é isso que se quer saber
         depois do apito e é o que sumia junto com a tela da partida.

         "Avançar" é o único caminho adiante, e ele fecha a partida de
         verdade (aovivo_fechar grava o resultado e volta pra tela inicial). */ ?>
    <div class="fundo-popup" id="popFim" hidden>
      <div class="popup popup-fim">
        <div class="fim-veredito" data-fim-veredito>Fim de jogo</div>

        <div class="fim-placar">
          <div class="fim-time">
            <?= escudo($casa ? $euC : $advC, 34) ?>
            <span><?= h($casa ? $estado['clube'] : $vivo['adversario']) ?></span>
          </div>
          <b data-fim-placar>0 – 0</b>
          <div class="fim-time dir">
            <?= escudo($casa ? $advC : $euC, 34) ?>
            <span><?= h($casa ? $vivo['adversario'] : $estado['clube']) ?></span>
          </div>
        </div>

        <div class="fim-comp">
          <i class="bi bi-trophy"></i> <?= h($vivo['comp']) ?><?= $vivo['fase'] ? ' · ' . h($vivo['fase']) : '' ?>
        </div>

        <div class="fim-numeros" data-fim-numeros></div>
        <div class="craque fim-craque" data-fim-craque hidden></div>

        <form method="post" class="popup-acoes">
          <input type="hidden" name="acao" value="aovivo_fechar">
          <button class="btn" type="submit">Avançar <i class="bi bi-arrow-right"></i></button>
        </form>
      </div>
    </div>

    <script>
    (function () {
      var PASSO = <?= FUT_AOVIVO_PASSO ?>;   // minutos que o servidor simula por vez
      var TIQUE = 420;                        // ms de verdade por minuto de jogo
      var INTERVALO = 45;                     // onde o juiz manda pro vestiário
      var minuto = <?= (int)$vivo['minuto'] ?>;
      var carregado = minuto;                 // até que minuto o servidor já simulou
      var rolando = false, ocupado = false, timer = null;
      var fila = [];                          // lances à espera do relógio chegar
      var jaTeveIntervalo = minuto >= INTERVALO;

      var rel = document.getElementById('relogio'), rlMin = document.getElementById('rlMin');
      var plCasa = document.getElementById('plCasa'), plFora = document.getElementById('plFora');
      var barra = document.getElementById('barraTempo');
      var escCaixa = document.getElementById('escalacaoVivo');
      var marcasVivo = <?= json_encode($marcasVivo ?: new stdClass(), JSON_UNESCAPED_UNICODE) ?>;
      var btPausar = document.getElementById('btPausar');
      var btVoltar = document.getElementById('btVoltar');
      var casa = <?= $casa ? 'true' : 'false' ?>;

      function pintaRelogio() {
        rlMin.textContent = minuto;
        barra.style.width = Math.min(100, minuto / 90 * 100) + '%';
        rel.className = 'relogio ' + (rolando ? 'rolando' : 'parado');
        var etq = document.getElementById('rlEtiqueta');
        if (etq) {
          etq.textContent = minuto >= 90 ? 'fim de jogo'
                          : (minuto === INTERVALO && !rolando ? 'intervalo'
                          : (minuto > INTERVALO ? '2º tempo' : '1º tempo'));
        }
      }

      /* CADA LANCE TEM A SUA FRASE. O gol e o cartão já estavam aqui; o resto
         é o que faltava pro relógio parar de andar no vazio — em catorze das
         dezoito atualizações não acontecia nada. */
      /* CADA LANCE TEM VÁRIAS FRASES. Com uma só, a narração de um jogo com
         vinte finalizações vira a mesma linha repetida vinte vezes, e o que
         devia parecer futebol parece um log. A escolha é pelo minuto, então o
         mesmo lance sai sempre com a mesma frase se a página recarregar. */
      var LANCES = {
        fora: {ico: 'bi-arrow-up-right', txt: [
          function (n) { return n + ' finalizou por cima'; },
          function (n) { return n + ' chutou pra fora'; },
          function (n) { return n + ' mandou longe do gol'; },
          function (n) { return n + ' arriscou de fora da área e errou o alvo'; },
          function (n) { return 'Tentou ' + n + ', pela linha de fundo'; }
        ]},
        defendeu: {ico: 'bi-hand-index-thumb', txt: [
          function (n) { return n + ' chutou, o goleiro defendeu'; },
          function (n) { return 'Boa defesa no chute de ' + n; },
          function (n) { return n + ' obrigou o goleiro a trabalhar'; },
          function (n) { return 'O goleiro espalmou a finalização de ' + n; }
        ]},
        trave: {ico: 'bi-exclamation-lg', txt: [
          function (n) { return '<b>Na trave!</b> ' + n + ' quase'; },
          function (n) { return '<b>No travessão!</b> A bola voltou no chute de ' + n; },
          function (n) { return '<b>Que azar!</b> ' + n + ' acertou o poste'; }
        ]},
        bloqueou: {ico: 'bi-shield', txt: [
          function (n) { return 'A defesa bloqueou o chute de ' + n; },
          function (n) { return n + ' chutou, o zagueiro travou'; },
          function (n) { return 'Desviaram a finalização de ' + n; }
        ]},
        falta: {ico: 'bi-flag', txt: [
          function (n) { return 'Falta perigosa, ' + n + ' na bola'; },
          function (n) { return 'Falta na entrada da área — ' + n + ' vai cobrar'; },
          function (n) { return n + ' sofreu falta em posição boa'; }
        ]},
        troca: {ico: 'bi-arrow-left-right', txt: null}   // tem texto próprio
      };

      function fraseDoLance(l, e) {
        var qual = l.txt[(e.minuto + (e.jogador || '').length) % l.txt.length];
        return qual(e.jogador);
      }

      function textoDoLance(e) {
        if (e.tipo === 'gol') {
          return '<b>GOL' + (e.meu ? '' : ' DELES') + '!</b> ' + e.jogador +
                 (e.assistente ? ' <i>assist. ' + e.assistente + '</i>' : '');
        }
        if (e.tipo === 'vermelho') {
          return '<b>Vermelho</b> para ' + e.jogador + (e.segundo ? ' <i>(segundo amarelo)</i>' : '');
        }
        if (e.tipo === 'amarelo') return 'Amarelo para ' + e.jogador;

        if (e.tipo === 'troca') {
          return '<i class="bi bi-arrow-left-right"></i> <b>Substituição:</b> sai ' +
                 e.sai + ', entra ' + e.jogador;
        }
        var l = LANCES[e.tipo];
        if (!l || !l.txt) return e.jogador || '';
        return '<i class="bi ' + l.ico + '"></i> ' + fraseDoLance(l, e) +
               (e.meu ? '' : ' <i>(eles)</i>');
      }

      // ── O campo ao vivo ────────────────────────────────────────
      var campoVivo = document.getElementById('campoVivo');
      var lanceAgora = document.getElementById('lanceAgora');
      var telaGol = document.getElementById('telaGol');
      var pressaoBarra = document.getElementById('pressaoBarra');
      var camisaPorNome = {};
      var timerAgora = null, timerGol = null;

      /* O CAMPO É O MESMO DESENHO DA ESCALAÇÃO, com os dados que o painel de
         substituição já sabia entregar. Redesenhado a cada troca, senão a
         camisa continuaria com o nome de quem saiu. */
      function desenhaCampo(dados) {
        if (!dados || !dados.vagas) return;
        campoVivo.innerHTML =
          '<div class="linha-meio"></div><div class="circulo"></div>' +
          '<div class="area cima"></div><div class="area baixo"></div>' +
          dados.vagas.map(function (v) {
            return '<div class="camisa" data-nome="' + v.nome + '"' +
                   ' style="left:' + v.x + '%;top:' + v.y + '%">' +
                   '<div class="bola">' + (v.ovr || '—') + '</div>' +
                   '<div class="nom">' + (v.nome || '—') + '</div>' +
                   '<div class="vg">' + v.pos + '</div></div>';
          }).join('');
        camisaPorNome = {};
        campoVivo.querySelectorAll('.camisa').forEach(function (c) {
          if (c.dataset.nome) camisaPorNome[c.dataset.nome] = c;
        });
        desenhaEscalacao(dados);
      }

      function carregaCampo() {
        fetch(location.pathname + '?aba=partida&json=troca', {headers: {'X-Requested-With': 'fetch'}})
          .then(function (r) { return r.json(); })
          .then(desenhaCampo)
          .catch(function () {});
      }

      /* A CAMISA REAGE. É o que liga a narração ao campo: sem isso o lance é
         uma linha de texto que podia ser de qualquer jogo. */
      function acendeCamisa(e) {
        if (!e.meu) return;
        var c = camisaPorNome[e.jogador];
        if (!c) return;
        var classe = e.tipo === 'gol' ? 'gol'
                   : (e.tipo === 'vermelho' ? 'expulso'
                   : (e.tipo === 'amarelo' ? 'cartao' : 'pulsou'));
        c.classList.add(classe);
        if (classe === 'expulso') return;          // expulso fica apagado
        setTimeout(function () { c.classList.remove(classe); },
                   classe === 'gol' ? 1600 : (classe === 'cartao' ? 1400 : 900));
      }

      /* O LANCE DA VEZ, grande, em cima do campo — quem está assistindo olha
         pro campo, e não pra lista que cresce embaixo. */
      function faixaDoLance(e) {
        lanceAgora.innerHTML = '<span class="min">' + e.minuto + "'</span>" + textoDoLance(e);
        lanceAgora.classList.add('aparece');
        clearTimeout(timerAgora);
        timerAgora = setTimeout(function () { lanceAgora.classList.remove('aparece'); }, 2600);
      }

      function gritaGol(e) {
        telaGol.className = 'viv-gol aparece' + (e.meu ? '' : ' deles');
        telaGol.querySelector('.grande').textContent = e.meu ? 'GOL!' : 'GOL DELES';
        document.getElementById('golQuem').textContent = e.jogador;
        document.getElementById('golQuando').textContent = e.minuto + "'" +
          (e.assistente ? ' · assistência de ' + e.assistente : '');
        clearTimeout(timerGol);
        timerGol = setTimeout(function () { telaGol.classList.remove('aparece'); }, 1900);
      }

      /* O LANCE NÃO VIRA MAIS LINHA DE TEXTO. A lista que rolava aqui saiu —
         o lance acontece no campo, que está do lado: a camisa acende, a faixa
         aparece embaixo, o "GOL!" toma a tela, e a escalação ganha a marca.
         A função continua sendo o ponto único por onde todo lance passa, que
         é o que faz a reprise (aoVivo === false) acertar o estado sem repetir
         a festa. */
      function mostraLance(e, aoVivo) {
        marcaJogador(e);
        if (aoVivo === false) return;              // lance antigo, sem festa
        faixaDoLance(e);
        acendeCamisa(e);
        if (e.tipo === 'gol') gritaGol(e);
        if (e.tipo === 'troca') carregaCampo();
      }

      /* A NOTA CAI NA LINHA DE QUEM JÁ ESTÁ NA TELA, em vez de redesenhar a
         lista inteira. Redesenhar a cada avanço do relógio perderia a ordem
         (que é a da escalação, não a da nota) e piscaria a cada cinco
         minutos de jogo. */
      function mostraNotas(mapa) {
        if (!mapa || !escCaixa) return;
        escCaixa.querySelectorAll('.esc-linha').forEach(function (l) {
          var v = mapa[l.dataset.nome];
          var c = l.querySelector('.n');
          if (v === undefined || !c) return;
          c.textContent = Number(v).toFixed(1).replace('.', ',');
          c.className = 'n ' + (v >= 7 ? 'boa' : (v < 5.5 ? 'ruim' : ''));
        });
      }

      /* Gol e cartão viram marca na linha do jogador. */
      function marcaJogador(e) {
        if (!e.meu || !e.jogador) return;
        var m = marcasVivo[e.jogador] || (marcasVivo[e.jogador] = {});
        if (e.tipo === 'gol') m.gols = (m.gols || 0) + 1;
        else if (e.tipo === 'amarelo') m.cartao = 'ama';
        else if (e.tipo === 'vermelho') m.cartao = 'ver';
        else return;
        pintaMarcas();
      }

      function pintaMarcas() {
        if (!escCaixa) return;
        escCaixa.querySelectorAll('.esc-linha').forEach(function (l) {
          var m = marcasVivo[l.dataset.nome] || {};
          var alvo = l.querySelector('.mk');
          if (!alvo) return;
          var html = '';
          for (var i = 0; i < (m.gols || 0); i++) html += '<i class="bi bi-dribbble"></i>';
          if (m.cartao) html += '<span class="cartao ' + m.cartao + '"></span>';
          alvo.innerHTML = html;
          l.classList.toggle('fora', m.cartao === 'ver');
        });
      }

      /* A lista acompanha as trocas: mesmos dados do campo, mesma ordem. */
      function desenhaEscalacao(dados) {
        if (!dados || !dados.vagas || !escCaixa) return;
        escCaixa.innerHTML = dados.vagas.map(function (v) {
          return '<div class="esc-linha" data-nome="' + v.nome + '">' +
                 '<span class="tagpos">' + v.pos + '</span>' +
                 '<span class="nm">' + v.nome + '</span>' +
                 '<span class="mk"></span><span class="n">—</span></div>';
        }).join('');
        pintaMarcas();
      }

      var caixaNumeros = document.getElementById('numerosVivo');
      function linhaNumero(rot, meu, dele) {
        var t = (meu + dele) || 1;
        return '<div>' +
          '<div class="viv-num-linha">' +
            '<span class="n">' + meu + '</span>' +
            '<span class="viv-num-barra">' +
              '<i class="eu" style="width:' + (meu / t * 100) + '%"></i>' +
              '<i class="ele" style="width:' + (dele / t * 100) + '%"></i>' +
            '</span>' +
            '<span class="n">' + dele + '</span>' +
          '</div>' +
          '<div class="viv-num-rot">' + rot + '</div></div>';
      }
      function mostraNumeros(n) {
        if (!n || !caixaNumeros) return;
        if (pressaoBarra) pressaoBarra.style.width = n.posse + '%';
        caixaNumeros.innerHTML =
          linhaNumero('posse de bola', n.posse + '%', (100 - n.posse) + '%')
            .replace(/width:NaN%/g, 'width:' + n.posse + '%')
          + linhaNumero('finalizações', n.chutes, n.chutes_deles)
          + linhaNumero('no alvo', n.no_alvo, n.no_alvo_deles);
        /* A barra da posse não sai da soma como as outras (é porcentagem, não
           contagem), então ela é desenhada na mão. */
        var barras = caixaNumeros.querySelectorAll('.viv-num-barra');
        if (barras[0]) {
          barras[0].children[0].style.width = n.posse + '%';
          barras[0].children[1].style.width = (100 - n.posse) + '%';
        }
      }

      /* ── O APITO FINAL ───────────────────────────────────────────────
         Antes aparecia um botão "Encerrar e ver o resumo" e a partida ficava
         parada esperando alguém achá-lo. Agora o resumo vem sozinho: o jogo
         acabou, não há mais nada pra decidir, e o único caminho adiante é
         seguir. */
      var jaAcabou = false;
      function acabou() {
        rolando = false;
        clearInterval(timer);
        btPausar.hidden = true;
        btVoltar.hidden = true;
        rel.className = 'relogio parado';
        rlMin.textContent = '90';
        if (jaAcabou) return;
        jaAcabou = true;
        setTimeout(mostraFimDeJogo, 700);   // deixa o último lance respirar
      }

      function mostraFimDeJogo() {
        var pop = document.getElementById('popFim');
        if (!pop) return;
        var meus = golsMostrados.meus, deles = golsMostrados.deles;
        pop.querySelector('[data-fim-placar]').textContent =
          (casa ? meus : deles) + ' – ' + (casa ? deles : meus);
        var veredito = meus > deles ? 'Vitória' : (meus < deles ? 'Derrota' : 'Empate');
        var caixa = pop.querySelector('[data-fim-veredito]');
        caixa.textContent = veredito;
        caixa.className = 'fim-veredito ' + (meus > deles ? 'v' : (meus < deles ? 'd' : ''));

        var n = numerosFinais;
        var linhas = pop.querySelector('[data-fim-numeros]');
        linhas.innerHTML = n ? [
          ['Posse de bola', n.posse + '%', (100 - n.posse) + '%'],
          ['Finalizações', n.chutes, n.chutes_deles],
          ['No alvo', n.no_alvo, n.no_alvo_deles]
        ].map(function (l) {
          return '<div class="fim-linha"><span>' + l[1] + '</span><i>' + l[0] + '</i><span>' + l[2] + '</span></div>';
        }).join('') : '';

        /* O MELHOR EM CAMPO é o que se quer saber depois do apito, e é o
           único jeito de as notas não sumirem junto com a tela da partida. */
        var craque = null;
        if (notasFinais) {
          Object.keys(notasFinais).forEach(function (nome) {
            if (!craque || notasFinais[nome] > craque.nota) craque = {nome: nome, nota: notasFinais[nome]};
          });
        }
        var cx = pop.querySelector('[data-fim-craque]');
        cx.innerHTML = craque
          ? '<i class="bi bi-star-fill"></i><div style="flex:1;min-width:0">'
            + '<div class="craque-nome">' + craque.nome + '</div>'
            + '<div class="craque-sub">melhor em campo</div></div>'
            + '<span class="nota alta">' + craque.nota.toFixed(1).replace('.', ',') + '</span>'
          : '';
        cx.hidden = !craque;

        pop.hidden = false;
      }

      /* PEDE O PRÓXIMO PEDAÇO ANTES DE PRECISAR DELE. Enquanto o relógio toca
         os cinco minutos que já estão na mão, o seguinte já vem vindo — é isso
         que faz o relógio não travar de cinco em cinco. */
      function buscaMais() {
        if (ocupado || carregado >= 90) return;
        ocupado = true;
        var ate = Math.min(90, carregado + PASSO);
        fetch(location.pathname, {method: 'POST', headers: {'X-Requested-With': 'fetch'},
              body: new URLSearchParams({acao: 'aovivo_avancar', ate: String(ate)})})
          .then(function (r) { return r.json(); })
          .then(function (d) {
            ocupado = false;
            if (!d.ok) { acabou(); return; }
            carregado = d.minuto;
            placarFinal = {meus: d.meus, deles: d.deles};
            notasFinais = d.notas;
            numerosFinais = d.numeros;
            (d.novos || []).forEach(function (e) { fila.push(e); });
            fila.sort(function (a, b) { return a.minuto - b.minuto; });
          })
          .catch(function () { ocupado = false; pausa(); });
      }

      var placarFinal = {meus: <?= (int)$vivo['meus'] ?>, deles: <?= (int)$vivo['deles'] ?>};
      /* JA VEM PREENCHIDO DO SERVIDOR: quem recarrega a pagina com a partida
         no fim nao passa mais pelo buscaMais, e sem isso o resumo do apito
         final abria sem numeros e sem melhor em campo. */
      var notasFinais = <?= json_encode($notasVivo ?: null, JSON_UNESCAPED_UNICODE) ?>;
      var numerosFinais = <?= json_encode($vivo['numeros'] ?? null) ?>;
      var golsMostrados = {meus: placarFinal.meus, deles: placarFinal.deles};

      /* O RELÓGIO ANDA UM MINUTO POR VEZ e solta o que estava marcado pra
         aquele minuto. O placar sobe junto com o gol, e não antes dele: ver o
         2 a 1 aparecer três minutos antes do lance que fez o gol estragava a
         única coisa que a partida ao vivo tem pra dar, que é a surpresa. */
      function tique() {
        if (!rolando) return;

        if (minuto >= carregado) {
          buscaMais();
          if (minuto >= 90) { acabou(); }
          return;                       // esperando o servidor: o relógio segura
        }

        minuto++;
        while (fila.length && fila[0].minuto <= minuto) {
          var e = fila.shift();
          mostraLance(e);
          if (e.tipo === 'gol') {
            if (e.meu) golsMostrados.meus++; else golsMostrados.deles++;
            plCasa.textContent = casa ? golsMostrados.meus : golsMostrados.deles;
            plFora.textContent = casa ? golsMostrados.deles : golsMostrados.meus;
          }
        }
        pintaRelogio();
        if (notasFinais) mostraNotas(notasFinais);
        if (numerosFinais) mostraNumeros(numerosFinais);

        // Faltando pouco pro fim do que está carregado, já pede o próximo.
        if (carregado - minuto <= 2) buscaMais();

        if (minuto >= INTERVALO && !jaTeveIntervalo) { jaTeveIntervalo = true; apitaIntervalo(); }
        if (minuto >= 90 && carregado >= 90 && !fila.length) acabou();
      }

      /* O INTERVALO. É onde o técnico mexe no time de verdade, então a partida
         para sozinha e o campo abre — sem isso a pausa dependia do jogador
         lembrar de apertar o botão no meio de um relógio correndo. */
      function apitaIntervalo() {
        pausa();
        abreTroca(true);
      }

      function toca() {
        if (jaAcabou) return;
        rolando = true;
        btPausar.hidden = false; btVoltar.hidden = true;
        pintaRelogio();
        buscaMais();
        clearInterval(timer);
        timer = setInterval(tique, TIQUE);
      }
      function pausa() {
        rolando = false;
        clearInterval(timer);
        if (!jaAcabou) { btPausar.hidden = true; btVoltar.hidden = false; }
        pintaRelogio();
      }

      /* PAUSAR É ABRIR A PRANCHETA. Não existe motivo pra parar a partida que
         não seja mexer no time — e quando existia um botão só de pausa, ele
         parava o jogo e deixava a pessoa olhando pra tela parada. */
      btPausar.addEventListener('click', function () { abreTroca(false); });
      btVoltar.addEventListener('click', toca);

      /* ── ESTRATÉGIA E FORMAÇÃO, DENTRO DA PRANCHETA ─────────────────
         As duas aplicam NO CLIQUE, sem botão de confirmar. Um "Confirmar"
         só faria sentido se desse pra desistir no meio, e não dá: postura e
         marcação são uma escolha entre três, e formação entre cinco — não há
         estado intermediário pra abandonar. */
      function mandaEstrategia() {
        var d = {acao: 'aovivo_estrategia'};
        document.querySelectorAll('.mesa-chips').forEach(function (g) {
          var on = g.querySelector('.mesa-chip.on');
          d[g.dataset.campo] = on ? on.dataset.valor : '';
        });
        fetch(location.pathname, {method: 'POST', body: new URLSearchParams(d)})
          .catch(function () {});
      }

      document.querySelectorAll('.mesa-chips').forEach(function (grupo) {
        grupo.addEventListener('click', function (e) {
          var b = e.target.closest('.mesa-chip');
          if (!b) return;
          grupo.querySelectorAll('.mesa-chip').forEach(function (x) { x.classList.remove('on'); });
          b.classList.add('on');
          mandaEstrategia();
        });
      });

      var selEsquema = document.getElementById('mesaEsquema');
      var notaEsquema = document.getElementById('mesaEsquemaNota');
      if (selEsquema) {
        selEsquema.addEventListener('change', function () {
          var antes = selEsquema.dataset.valia || selEsquema.value;
          fetch(location.pathname, {method: 'POST', body: new URLSearchParams(
            {acao: 'aovivo_formacao', esquema: selEsquema.value})})
            .then(function (r) { return r.json(); })
            .then(function (d) {
              if (!d.ok) {
                // O servidor recusou: a tela não pode ficar dizendo que mudou.
                selEsquema.value = antes;
                notaEsquema.textContent = d.erro || 'Não deu pra mudar o esquema.';
                return;
              }
              selEsquema.dataset.valia = selEsquema.value;
              notaEsquema.textContent = 'Agora em ' + d.esquema + ' — força em campo ' + d.forca + '.';
              // O campo da prancheta e o campo de trás mostram o time novo.
              recarregaPrancheta();
              carregaCampo();
            })
            .catch(function () { selEsquema.value = antes; });
        });
        selEsquema.dataset.valia = selEsquema.value;
      }

      // ── A substituição ─────────────────────────────────────────
      var popTroca = document.getElementById('popTroca');
      var campoTroca = document.getElementById('campoTroca');
      var listaEntra = document.getElementById('listaEntra');
      var trocaRestam = document.getElementById('trocaRestam');
      /* AS DUAS PONTAS DA TROCA. O gesto tem dois sentidos porque a cabeça
         tem dois: às vezes se pensa "quero o Luiz Araújo em campo" e às vezes
         "o Samuel Lino não está dando conta". Quem começa pelo banco arrasta
         pra camisa; quem começa pelo titular arrasta pro banco. */
      var escolhaEntra = null, escolhaSai = null;

      /* O CAMPO DA SUBSTITUIÇÃO é o mesmo da escalação: as camisas nas
         coordenadas do esquema, e o banco embaixo. Arrastar um do banco até a
         camisa é a substituição — e no celular, que não arrasta, toca-se num e
         depois no outro. */
      function pintaOpcoes(dados) {
        campoTroca.innerHTML =
          '<div class="linha-meio"></div><div class="circulo"></div>' +
          '<div class="area cima"></div><div class="area baixo"></div>' +
          dados.vagas.map(function (v) {
            return '<div class="camisa alvo-troca' + (v.entrou ? ' trocado' : '') + '"' +
                   ' draggable="' + (v.entrou ? 'false' : 'true') + '"' +
                   ' data-nome="' + v.nome + '" data-entrou="' + (v.entrou ? 1 : 0) + '"' +
                   ' style="left:' + v.x + '%;top:' + v.y + '%" tabindex="0" role="button"' +
                   ' title="' + v.nome + (v.entrou ? ' (acabou de entrar)' : '') + '">' +
                   '<div class="bola">' + (v.ovr || '—') + '</div>' +
                   '<div class="nom">' + (v.nome || '—') + '</div>' +
                   '<div class="vg">' + v.pos + '</div></div>';
          }).join('');

        /* O BANCO É O MESMO CARTÃO DA ESCALAÇÃO (.reserva): overall, nome,
           posição e energia, na mesma ordem e com o mesmo desenho. Eram duas
           telas pra fazer a mesma coisa — escolher quem entra — e cada uma com
           um cartão diferente, então a mão tinha que reaprender. */
        listaEntra.innerHTML = dados.banco.map(function (j) {
          var en = j.energia >= 80 ? 'verde' : (j.energia >= 55 ? 'amarelo' : 'vermelho');
          return '<button type="button" class="reserva troca-op" draggable="true" data-nome="' + j.nome + '">' +
                 '<span class="r-ovr">' + j.ovr + '</span>' +
                 '<span class="r-nome">' + j.nome + '</span>' +
                 '<span class="r-pos">' + (j.pos || '') + '</span>' +
                 '<span class="r-en ' + en + '">' + j.energia + '</span></button>';
        }).join('') || '<div style="color:var(--txt3);font-size:12px">Banco vazio.</div>';

        trocaRestam.textContent = dados.restam > 0
          ? dados.restam + ' substituição(ões) restante(s)'
          : 'Acabaram as substituições.';
        escolhaEntra = null; escolhaSai = null;
        ligaCampo();
      }

      function limpaSel() {
        listaEntra.querySelectorAll('.troca-op').forEach(function (x) { x.classList.remove('sel', 'alvo'); });
        campoTroca.querySelectorAll('.camisa').forEach(function (x) { x.classList.remove('alvo', 'sel'); });
      }

      /* Quem acabou de entrar não sai de novo, e dizer isso na hora do gesto
         evita um POST que o servidor ia recusar de qualquer jeito. */
      function podeSair(c) {
        if (c.dataset.entrou !== '1') return true;
        trocaRestam.textContent = c.dataset.nome + ' acabou de entrar.';
        return false;
      }

      function fazTroca(sai, entra) {
        if (!sai || !entra) return;
        fetch(location.pathname, {method: 'POST', body: new URLSearchParams(
          {acao: 'aovivo_substituir', sai: sai, entra: entra})})
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.ok) { trocaRestam.textContent = d.erro; return; }
            mostraLance({minuto: minuto, tipo: 'troca', meu: true, jogador: entra, sai: sai});
            escolhaEntra = null; escolhaSai = null;
            // Recarrega o campo com o time já mexido.
            fetch(location.pathname + '?aba=partida&json=troca', {headers: {'X-Requested-With': 'fetch'}})
              .then(function (r) { return r.json(); }).then(pintaOpcoes);
          });
      }

      function ligaCampo() {
        campoTroca.querySelectorAll('.camisa').forEach(function (c) {
          /* TOCAR NUM TITULAR SEM NINGUÉM ESCOLHIDO É DIZER QUEM SAI. Antes
             este clique não fazia nada, e o jogo só entendia o caminho que
             começava no banco — no celular isso obrigava a decorar a ordem. */
          c.addEventListener('click', function () {
            if (!podeSair(c)) return;
            if (escolhaEntra) { fazTroca(c.dataset.nome, escolhaEntra); limpaSel(); return; }
            var jaEra = c.classList.contains('sel');
            limpaSel();
            if (jaEra) { escolhaSai = null; return; }
            c.classList.add('sel');
            escolhaSai = c.dataset.nome;
            trocaRestam.textContent = 'Agora toque em quem entra, no banco.';
          });

          c.addEventListener('dragstart', function (e) {
            if (!podeSair(c)) { e.preventDefault(); return; }
            escolhaSai = c.dataset.nome;
            escolhaEntra = null;
            c.classList.add('sel');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', c.dataset.nome);
          });
          c.addEventListener('dragend', limpaSel);

          /* A camisa só é alvo pra quem vem do banco: arrastar um titular pra
             cima de outro titular não é substituição nenhuma. */
          c.addEventListener('dragover', function (e) {
            if (!escolhaEntra) return;
            e.preventDefault(); c.classList.add('alvo');
          });
          c.addEventListener('dragleave', function () { c.classList.remove('alvo'); });
          c.addEventListener('drop', function (e) {
            e.preventDefault();
            c.classList.remove('alvo');
            var quem = escolhaEntra || e.dataTransfer.getData('text/plain');
            if (!escolhaEntra) return;          // veio do campo: não é troca
            if (!podeSair(c)) return;
            fazTroca(c.dataset.nome, quem);
            limpaSel();
          });
        });
      }

      listaEntra.addEventListener('click', function (e) {
        var b = e.target.closest('.troca-op');
        if (!b) return;
        // Se um titular já está marcado, este toque fecha a substituição.
        if (escolhaSai) { fazTroca(escolhaSai, b.dataset.nome); limpaSel(); return; }
        limpaSel();
        b.classList.add('sel');
        escolhaEntra = b.dataset.nome;
        trocaRestam.textContent = 'Agora toque em quem sai.';
      });
      listaEntra.addEventListener('dragstart', function (e) {
        var b = e.target.closest('.troca-op');
        if (!b) return;
        escolhaEntra = b.dataset.nome;
        escolhaSai = null;
        b.classList.add('sel');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', b.dataset.nome);
      });
      listaEntra.addEventListener('dragend', limpaSel);

      /* ── O CAMINHO DE VOLTA: DO CAMPO PRO BANCO ─────────────────────
         Arrastar o titular até o reserva é o mesmo gesto do contrário, e a
         mão que já tirou um jogador da escalação arrastando espera que
         funcione aqui também. Sem isso, metade dos arrastos não fazia nada e
         não dizia por quê. */
      listaEntra.addEventListener('dragover', function (e) {
        if (!escolhaSai) return;
        e.preventDefault();
        var b = e.target.closest('.troca-op');
        if (b) b.classList.add('alvo');
      });
      listaEntra.addEventListener('dragleave', function (e) {
        var b = e.target.closest('.troca-op');
        if (b) b.classList.remove('alvo');
      });
      listaEntra.addEventListener('drop', function (e) {
        if (!escolhaSai) return;
        e.preventDefault();
        var b = e.target.closest('.troca-op');
        if (!b) return;
        fazTroca(escolhaSai, b.dataset.nome);
        limpaSel();
      });

      /* Recarrega só o conteúdo da prancheta, sem abrir nem fechar nada: é o
         que a troca de formação precisa pra o campo mostrar o time novo. */
      function recarregaPrancheta() {
        if (popTroca.hidden) return;
        fetch(location.pathname + '?aba=partida&json=troca', {headers: {'X-Requested-With': 'fetch'}})
          .then(function (r) { return r.json(); })
          .then(pintaOpcoes)
          .catch(function () {});
      }

      function abreTroca(doIntervalo) {
        if (jaAcabou) return;
        if (rolando) pausa();
        var tit = document.getElementById('mesaTitulo');
        if (tit) tit.textContent = doIntervalo ? 'Intervalo' : 'Pausado aos ' + minuto + "'";
        fetch(location.pathname + '?aba=partida&json=troca', {headers: {'X-Requested-With': 'fetch'}})
          .then(function (r) { return r.json(); })
          .then(function (d) {
            pintaOpcoes(d);
            popTroca.hidden = false;
          })
          .catch(function () { popTroca.hidden = false; });
      }

      /* FECHAR A PRANCHETA É VOLTAR A JOGAR — inclusive saindo do intervalo,
         que antes deixava a partida parada esperando mais um clique. Abrir
         pausa, fechar toca: são os dois lados do mesmo gesto, e não sobra
         estado nenhum em que a pessoa fica olhando pro relógio parado sem
         saber o que apertar. */
      popTroca.addEventListener('click', function (e) {
        if (e.target !== popTroca && !e.target.hasAttribute('data-fechar')) return;
        popTroca.hidden = true;
        if (!jaAcabou) toca();
      });


      carregaCampo();
      pintaRelogio();
      /* COMEÇA RODANDO. Quem clicou em "Jogar" na tela anterior já disse o que
         queria; pedir mais um clique em "Começar" era uma porta a mais entre a
         decisão e o jogo. Quem chega aqui com a partida já no fim cai direto
         no resumo, sem relógio andando à toa. */
      setTimeout(function () { if (!jaAcabou && minuto < 90) toca(); }, 350);
      mostraNumeros(<?= json_encode($vivo['numeros'] ?? null) ?>);
      if (minuto >= 90) acabou();
      <?php if (!empty($vivo['eventos'])): ?>
        <?= 'var jaVistos = ' . json_encode(array_values($vivo['eventos']), JSON_UNESCAPED_UNICODE) . ';' ?>
        jaVistos.forEach(function (e) { mostraLance(e, false); });
      <?php endif; ?>
    })();
    </script>
  <?php endif; ?>

  <div class="clube-card" <?= $emCampo ? 'hidden' : '' ?>>
    <?php
      /* O ESCUDO GRANDE E APAGADO NO CANTO. É decoração, e por isso sai do
         fluxo e não tem alt: quem usa leitor de tela já ouviu o nome do clube
         na linha abaixo, e repetir o escudo só atrapalharia. */
      $escudoUrl = (string)($clubesTodos[$estado['clube']]['escudo'] ?? '');
    ?>
    <?php if ($escudoUrl !== ''): ?>
      <img class="clube-marca" src="<?= h($escudoUrl) ?>" alt="" aria-hidden="true">
    <?php endif; ?>
    <div class="clube-topo">
      <?= escudo($clubesTodos[$estado['clube']] ?? ['nome' => $estado['clube']], 42) ?>
      <div style="min-width:0">
        <div class="clube-nome"><?= h($estado['clube']) ?></div>
        <div class="clube-sub">
          <?= h($estado['tecnico']['nome']) ?> ·
          <?= h(futCarreiraRotuloDaDivisao((string)($clubesTodos[$estado['clube']]['div'] ?? ''))) ?> ·
          temporada <?= (int)$estado['temporada'] ?> (<?= (int)$estado['ano'] ?>)
        </div>
      </div>
    </div>
    <div class="fichas">
      <div class="ficha"><div class="v"><?= h(futDinheiro((float)$estado['caixa'], false)) ?></div><div class="r">caixa (<?= h(trim(str_replace(futDinheiro((float)$estado['caixa'], false), '', futDinheiro((float)$estado['caixa'])))) ?>)</div></div>
      <div class="ficha"><div class="v"><?= (int)$meuClube['forca'] ?></div><div class="r">força</div></div>
      <div class="ficha"><div class="v"><?= (int)$estado['tecnico']['reputacao'] ?></div><div class="r">reputação</div></div>
      <div class="ficha"><div class="v"><?= $posicao ? $posicao . 'º' : '—' ?></div><div class="r">posição</div></div>
    </div>
    <div class="meta-linha">
      <i class="bi bi-bullseye"></i>
      <div><strong>Meta da diretoria:</strong> <?= h($estado['meta']['texto'] ?? '—') ?>
      <?php if (($estado['falhas'] ?? 0) >= 1): ?>
        <span style="color:#fca5a5"> · você já falhou uma vez. Outra e está fora.</span>
      <?php endif; ?>
      </div>
    </div>
  </div>

  <?php /* O recado do jogo vem em popup do jogo — ver o fim do arquivo. */ ?>

  <?php if ($relatorio): ?>
    <div class="bloco" style="border-color:<?= $relatorio['demitido'] ? 'rgba(239,68,68,.4)' : 'rgba(34,197,94,.4)' ?>">
      <h3><i class="bi bi-calendar-check"></i> Fim de temporada</h3>
      <p style="margin:0 0 10px;font-size:13.5px">
        Terminou em <strong><?= $relatorio['posicao'] ? $relatorio['posicao'] . 'º' : '—' ?></strong>.
        Meta: <?= h($relatorio['meta']['texto']) ?> —
        <strong style="color:<?= $relatorio['cumpriu'] ? 'var(--verde-claro)' : '#fca5a5' ?>">
          <?= $relatorio['cumpriu'] ? 'cumprida' : 'não cumprida' ?></strong>.
      </p>
      <?php if ($relatorio['titulo']): ?>
        <p style="margin:0 0 10px;color:var(--amarelo);font-weight:800">
          <i class="bi bi-trophy-fill"></i> <?= h($relatorio['titulo']) ?>!</p>
      <?php endif; ?>
      <div class="rolar"><table><tbody>
        <tr><td>Receita do ano</td><td class="num">+<?= h(futDinheiro($relatorio['receita'])) ?></td></tr>
        <tr><td>Premiação</td><td class="num">+<?= h(futDinheiro($relatorio['premio'])) ?></td></tr>
        <tr><td>Folha salarial</td><td class="num">−<?= h(futDinheiro($relatorio['folha'])) ?></td></tr>
        <tr><td><strong>Caixa agora</strong></td><td class="num"><strong><?= h(futDinheiro($relatorio['caixa'])) ?></strong></td></tr>
      </tbody></table></div>
      <?php if (!empty($relatorio['aposentados'])): ?>
        <div style="margin-top:12px">
          <div style="font-size:12px;font-weight:800;margin-bottom:5px"><i class="bi bi-door-closed"></i> Penduraram as chuteiras</div>
          <?php foreach ($relatorio['aposentados'] as $a): ?>
            <div style="font-size:12.5px;color:var(--txt2)">· <?= h($a['nome']) ?>, <?= (int)$a['idade'] ?> anos (<?= h($a['pos']) ?>)</div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($relatorio['novos'])): ?>
        <div style="margin-top:12px">
          <div style="font-size:12px;font-weight:800;margin-bottom:5px"><i class="bi bi-stars"></i> Subiram da base</div>
          <?php foreach ($relatorio['novos'] as $n): ?>
            <div style="font-size:12.5px;color:var(--txt2)">
              · <?= h($n['nome']) ?>, <?= (int)$n['idade'] ?> anos — <?= h($n['pos']) ?> <?= (int)$n['ovr'] ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php
        $subiram = array_filter($relatorio['evolucao'] ?? [], fn($x) => $x['delta'] >= 3);
        $cairam  = array_filter($relatorio['evolucao'] ?? [], fn($x) => $x['delta'] <= -3);
      ?>
      <?php if ($subiram || $cairam): ?>
        <div style="margin-top:12px">
          <div style="font-size:12px;font-weight:800;margin-bottom:5px"><i class="bi bi-graph-up-arrow"></i> Quem mudou de patamar</div>
          <?php foreach ($subiram as $nm => $x): ?>
            <div style="font-size:12.5px">· <?= h($nm) ?>
              <span style="color:var(--verde-claro);font-weight:700"><?= (int)$x['antes'] ?> → <?= (int)$x['depois'] ?></span>
              <span style="color:var(--txt3)">(<?= (int)$x['idade'] ?> anos)</span></div>
          <?php endforeach; ?>
          <?php foreach ($cairam as $nm => $x): ?>
            <div style="font-size:12.5px">· <?= h($nm) ?>
              <span style="color:#fca5a5;font-weight:700"><?= (int)$x['antes'] ?> → <?= (int)$x['depois'] ?></span>
              <span style="color:var(--txt3)">(<?= (int)$x['idade'] ?> anos)</span></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($relatorio['demitido']): ?>
        <div class="msg err" style="margin-top:12px"><i class="bi bi-door-open"></i>
          Você foi demitido. Duas temporadas sem cumprir a meta.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$emCampo && !empty($estado['aovivo'])): ?>
    <?php $v2 = $estado['aovivo']; ?>
    <div class="bloco" style="border-color:rgba(34,197,94,.45)">
      <h3><i class="bi bi-broadcast"></i> Tem jogo rolando</h3>
      <p style="color:var(--txt2);font-size:13px;margin:0 0 11px">
        <?= h($estado['clube']) ?> <?= (int)$v2['meus'] ?> × <?= (int)$v2['deles'] ?>
        <?= h($v2['adversario']) ?>, aos <?= (int)$v2['minuto'] ?> minutos.
      </p>
      <a class="btn" href="?aba=partida"><i class="bi bi-arrow-right"></i> Voltar ao jogo</a>
    </div>
  <?php endif; ?>

  <div class="abas" <?= $emCampo ? 'hidden' : '' ?>>
    <?php foreach (['inicio' => 'Início', 'escalacao' => 'Escalação', 'elenco' => 'Elenco',
                    'stats' => 'Números', 'tabela' => 'Tabela', 'mercado' => 'Mercado',
                    'carreira' => 'Carreira'] as $k => $rot): ?>
      <a href="?aba=<?= $k ?>" class="<?= $aba === $k ? 'on' : '' ?>"><?= h($rot) ?></a>
    <?php endforeach; ?>
  </div>

  <?php // ── ABA: INÍCIO — o painel do clube ────────────────────────── ?>
  <?php if ($aba === 'inicio'): ?>

    <?php if (($estado['fase'] ?? '') === 'mercado'): ?>
      <?php if (!empty($estado['propostas'])): ?>
        <div class="bloco" style="border-color:rgba(34,197,94,.4)">
          <h3><i class="bi bi-telephone-fill"></i> Clubes querem você</h3>
          <p style="color:var(--txt2);font-size:13px;margin:0 0 12px">
            Sua campanha chamou atenção. Aceitar significa começar do zero em outro clube,
            com elenco e caixa dele — a reputação e os títulos vão com você.
          </p>
          <?php foreach ($estado['propostas'] as $pr): ?>
            <div class="proposta">
              <?= escudo($clubesTodos[$pr['nome']] ?? ['nome' => $pr['nome']], 32) ?>
              <div style="min-width:0;flex:1">
                <div style="font-weight:800"><?= h($pr['nome']) ?></div>
                <div style="font-size:11.5px;color:var(--txt2)">
                  <?= h(futCarreiraRotuloDaDivisao((string)$pr['div'])) ?>
                </div>
              </div>
              <form method="post" data-confirmar="Assumir o <?= h($pr['nome']) ?>? Você deixa o <?= h($estado['clube']) ?>." data-confirmar-ok="Assumir">
                <input type="hidden" name="acao" value="trocar_clube">
                <input type="hidden" name="clube" value="<?= h($pr['nome']) ?>">
                <button class="btn peq" type="submit">Aceitar</button>
              </form>
            </div>
          <?php endforeach; ?>
          <form method="post" style="margin-top:10px">
            <input type="hidden" name="acao" value="recusar_propostas">
            <button class="btn sec peq" type="submit">Ficar no <?= h($estado['clube']) ?></button>
          </form>
        </div>
      <?php endif; ?>

      <?php if (!empty($estado['noticias'])): ?>
        <div class="bloco">
          <h3><i class="bi bi-newspaper"></i> O que aconteceu na virada</h3>
          <?php foreach (array_slice($estado['noticias'], 0, 12) as $n): ?>
            <div class="noticia">· <?= h($n) ?></div>
          <?php endforeach; ?>
          <?php if (count($estado['noticias']) > 12): ?>
            <details style="margin-top:6px">
              <summary style="cursor:pointer;font-size:12px;color:var(--txt2)">ver todas</summary>
              <?php foreach (array_slice($estado['noticias'], 12) as $n): ?>
                <div class="noticia">· <?= h($n) ?></div>
              <?php endforeach; ?>
            </details>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="bloco">
        <h3><i class="bi bi-calendar-plus"></i> Pré-temporada</h3>
        <p style="color:var(--txt2);font-size:13px;margin:0 0 12px">
          Ajuste o elenco no mercado antes de começar. Depois que a temporada começar,
          o calendário corre até o fim do ano.
        </p>
        <form method="post"><input type="hidden" name="acao" value="iniciar_temporada">
          <button class="btn"><i class="bi bi-play-fill"></i> Começar a temporada</button>
        </form>
      </div>


    <?php elseif (($estado['fase'] ?? '') === 'fim'): ?>
      <div class="bloco">
        <h3><i class="bi bi-flag"></i> Temporada encerrada</h3>
        <p style="color:var(--txt2);font-size:13px;margin:0 0 12px">
          Todos os jogos do ano acabaram. Feche a temporada pra ver o balanço e o que a diretoria decidiu.
        </p>
        <form method="post"><input type="hidden" name="acao" value="fechar_temporada">
          <button class="btn"><i class="bi bi-calendar-check"></i> Fechar a temporada</button>
        </form>
      </div>


    <?php elseif (($estado['fase'] ?? '') === 'desempregado'): ?>
      <?php
        /* DEMITIDO NÃO É FIM DE JOGO. A carreira continua: a reputação, os
           títulos e o histórico ficam, e o técnico procura clube — só que
           agora a lista é a que a reputação dele alcança, que depois de duas
           temporadas ruins costuma ser mais curta que a anterior. */
        $rep = (int)($estado['tecnico']['reputacao'] ?? 0);
        $vagas = futClubesParaComecar($rep);
        unset($vagas[$estado['clube']]);   // o clube que te demitiu não te chama de volta
        $vagas = array_slice($vagas, 0, 12, true);
      ?>
      <div class="bloco">
        <h3><i class="bi bi-door-open"></i> Sem clube</h3>
        <p style="color:var(--txt2);font-size:13px;margin:0 0 12px">
          O <?= h($estado['clube']) ?> te demitiu. Sua reputação é <strong><?= $rep ?></strong> —
          é ela que define quem te atende agora. Seus títulos e seu histórico continuam com você.
        </p>
        <?php if (!$vagas): ?>
          <div class="vazio">Nenhum clube quer você no momento.</div>
        <?php else: ?>
          <?php foreach ($vagas as $nome => $c): ?>
            <div class="proposta">
              <?= escudo($c, 30) ?>
              <div style="min-width:0;flex:1">
                <div style="font-weight:800"><?= h($nome) ?></div>
                <div style="font-size:11.5px;color:var(--txt2)">
                  <?= h(futCarreiraRotuloDaDivisao((string)$c['div'])) ?>
                  · técnico atual: <?= h(futTecnicoDoClube($nome, (int)$estado['temporada'], $estado['trocas_tecnico'] ?? [])) ?>
                </div>
              </div>
              <form method="post">
                <input type="hidden" name="acao" value="assumir_clube">
                <input type="hidden" name="clube" value="<?= h($nome) ?>">
                <button class="btn peq" type="submit">Assumir</button>
              </form>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <form method="post" style="margin-top:12px"
              data-confirmar="Apagar esta carreira e começar outra do zero? Não dá pra desfazer." data-confirmar-ok="Apagar tudo" data-confirmar-perigo="1">
          <input type="hidden" name="acao" value="recomecar">
          <button class="btn sec peq"><i class="bi bi-arrow-repeat"></i> Apagar e começar do zero</button>
        </form>
      </div>

      <?php if (!empty($estado['noticias'])): ?>
        <div class="bloco">
          <h3><i class="bi bi-newspaper"></i> O que aconteceu na virada</h3>
          <?php foreach (array_slice($estado['noticias'], 0, 12) as $n): ?>
            <div class="noticia">· <?= h($n) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php else: ?>
    <?php
      /* O ESTADO DO CLUBE NUMA TELA. Antes o técnico precisava passar por
         quatro abas pra saber onde estava: a posição na Tabela, o elenco no
         Elenco, o artilheiro nos Números e o próximo jogo em Partidas. */
      $comps = futCarreiraCompeticoesDoAno($estado);
      $divEu = $clubesTodos[$estado['clube']]['div'] ?? '';
      $compNac = futCarreiraNomeDaDivisao($divEu);
      $tabNac = $compNac !== '' ? futCarreiraTabelaDaCompeticao($estado, $compNac) : [];

      /* AS NOTAS VÊM ANTES DO OVERALL. O overall está a um clique daqui, na
         aba Elenco, e não muda de uma semana pra outra; a nota é o que diz
         quem está jogando bem AGORA, e não aparece em nenhum outro lugar da
         tela. Antes da primeira partida não existe nota nenhuma, e aí o
         overall volta a ser a resposta. */
      $porNota = [];
      foreach ($estado['stats'] ?? [] as $nome => $st) {
        $j = (int)($st['jogos'] ?? 0);
        if ($j < 2) continue;                      // um jogo não faz média
        $porNota[] = ['nome' => $nome, 'pos' => $st['pos'] ?? '', 'jogos' => $j,
                      'media' => round((float)($st['soma_notas'] ?? 0) / $j, 2)];
      }
      usort($porNota, fn($a, $b) => $b['media'] <=> $a['media']);
      $porNota = array_slice($porNota, 0, 5);

      $melhores = $estado['elenco'] ?? [];
      usort($melhores, fn($a, $b) => (int)$b['ovr'] <=> (int)$a['ovr']);
      $melhores = array_slice($melhores, 0, 5);

      // Quem mais fez gol, e quem está fora.
      $art = [];
      foreach ($estado['stats'] ?? [] as $nome => $st) {
        if ((int)$st['gols'] > 0) $art[$nome] = (int)$st['gols'];
      }
      arsort($art);
      $art = array_slice($art, 0, 5, true);
      $fora = futIndisponiveis($estado['elenco'] ?? [], $estado['suspensos'] ?? []);
      $ultimos = array_slice(array_reverse($estado['resultados'] ?? []), 0, 5);
      $outros = futCarreiraOutrosJogosDaRodada($estado);
    ?>

    <?php if (($estado['fase'] ?? '') === 'temporada' && $proximo): ?>
      <div class="proximo">
        <div class="proximo-rot">Próxima partida</div>
        <div class="proximo-jogo">
          <?= escudo($clubesTodos[$proximo['adversario']] ?? ['nome' => $proximo['adversario']], 44) ?>
          <div style="min-width:0">
            <div class="proximo-nome"><?= h($proximo['adversario']) ?></div>
            <div class="proximo-sub">
              <?= h($proximo['comp']) ?><?= $proximo['fase'] ? ' · ' . h($proximo['fase']) : '' ?>
              · <?= $proximo['casa'] ? 'em casa' : 'fora' ?>
            </div>
          </div>
        </div>
        <div class="proximo-acoes">
          <form method="post" style="display:inline">
            <input type="hidden" name="acao" value="jogar">
            <button class="btn"><i class="bi bi-play-fill"></i> Jogar</button>
          </form>
          <a class="btn sec" href="?aba=escalacao"><i class="bi bi-diagram-3"></i> Escalação</a>
        </div>
        <div class="proximo-conta">
          Jogo <?= (int)$estado['rodada'] + 1 ?> de <?= count($estado['calendario'] ?? []) ?> na temporada
        </div>
      </div>
    <?php endif; ?>

    <div class="resumo-grade">
      <?php if ($tabNac): ?>
        <div class="bloco">
          <h3><i class="bi bi-table"></i> <?= h(futCarreiraRotuloDaDivisao((string)$divEu)) ?></h3>
          <div class="mini-lista">
            <?php
              /* A JANELA ACOMPANHA O CLUBE: quatro acima e três abaixo dele.
                 Cinco linhas fixas a partir do topo diziam quem é o líder e
                 não diziam o que o técnico precisa — se ele está subindo ou
                 caindo, e de quem. Perto das pontas a janela encosta na borda
                 em vez de sair da tabela. */
              $pos = 0; $i = 0;
              foreach (array_keys($tabNac) as $n) { $i++; if ($n === $estado['clube']) $pos = $i; }
              $janela = 8;
              $ini = max(0, min(count($tabNac) - $janela, $pos - 5));
              $trecho = array_slice($tabNac, $ini, $janela, true);
              [$zVerde, $zEuro, $zCai] = futZonasDaTabela((string)$divEu);
              $total = count($tabNac);
              $k = $ini;
            ?>
            <?php foreach ($trecho as $nome => $l): $k++; ?>
              <?php $cz = $k <= $zVerde ? 'sobe' : ($k <= $zEuro ? 'euro' : (($zCai && $k > $total - $zCai) ? 'cai' : '')); ?>
              <div class="mini-linha" style="<?= $nome === $estado['clube'] ? 'color:var(--acento);font-weight:700' : '' ?>">
                <span class="pos <?= $cz ?>"><?= $k ?></span>
                <?= escudo($clubesTodos[$nome] ?? ['nome' => $nome], 16) ?>
                <span class="esq"><?= h($nome) ?></span>
                <span class="dir"><?= (int)$l['p'] ?> pts</span>
              </div>
            <?php endforeach; ?>
          </div>
          <div style="margin-top:9px"><a class="btn sec peq" href="?aba=tabela" style="text-decoration:none">Ver a tabela</a></div>
        </div>
      <?php endif; ?>

      <?php if ($outros): ?>
        <div class="bloco">
          <h3><i class="bi bi-calendar3"></i> Os outros jogos da rodada</h3>
          <div class="mini-lista">
            <?php foreach ($outros as $j): ?>
              <div class="mini-linha jogo-rodada">
                <span class="lado dir"><?= h($j['casa']) ?></span>
                <?= escudo($clubesTodos[$j['casa']] ?? ['nome' => $j['casa']], 16) ?>
                <span class="pl"><?= (int)$j['gc'] ?>–<?= (int)$j['gf'] ?></span>
                <?= escudo($clubesTodos[$j['fora']] ?? ['nome' => $j['fora']], 16) ?>
                <span class="lado"><?= h($j['fora']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="bloco">
        <h3><i class="bi bi-trophy"></i> As competições do ano</h3>
        <div class="mini-lista">
          <?php foreach ($comps as $c => $d): ?>
            <?php $camp = futCarreiraCampanha($estado, $c); ?>
            <div class="mini-linha">
              <span class="esq"><?= h($c) ?></span>
              <span class="dir"><?= (int)$camp['v'] ?>V <?= (int)$camp['e'] ?>E <?= (int)$camp['d'] ?>D</span>
            </div>
          <?php endforeach; ?>
          <?php if (!$comps): ?><div style="color:var(--txt3);font-size:12.5px">A temporada ainda não começou.</div><?php endif; ?>
        </div>
      </div>

      <div class="bloco">
        <h3><i class="bi bi-star-fill"></i> <?= $porNota ? 'Quem está jogando melhor' : 'Os melhores do elenco' ?></h3>
        <div class="mini-lista">
          <?php if ($porNota): ?>
            <?php foreach ($porNota as $j): ?>
              <div class="mini-linha">
                <span class="tagpos"><?= h($j['pos']) ?></span>
                <span class="esq"><a class="link-jogo" href="?aba=jogador&amp;nome=<?= urlencode($j['nome']) ?>&amp;de=inicio"><?= h($j['nome']) ?></a></span>
                <span class="dir"><span class="nota <?= $j['media'] >= 7.5 ? 'alta' : ($j['media'] < 5.5 ? 'baixa' : '') ?>"><?= number_format($j['media'], 2, ',', '') ?></span></span>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <?php foreach ($melhores as $j): ?>
              <div class="mini-linha">
                <span class="tagpos"><?= h($j['pos']) ?></span>
                <span class="esq"><a class="link-jogo" href="?aba=jogador&amp;nome=<?= urlencode($j['nome']) ?>&amp;de=inicio"><?= h($j['nome']) ?></a></span>
                <span class="dir"><?= (int)$j['ovr'] ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($art): ?>
        <div class="bloco">
          <h3><i class="bi bi-bullseye"></i> Quem está fazendo gol</h3>
          <div class="mini-lista">
            <?php foreach ($art as $nome => $g): ?>
              <div class="mini-linha">
                <span class="esq"><a class="link-jogo" href="?aba=jogador&amp;nome=<?= urlencode($nome) ?>&amp;de=inicio"><?= h($nome) ?></a></span>
                <span class="dir"><?= (int)$g ?> gol<?= $g === 1 ? '' : 's' ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="bloco">
        <h3><i class="bi bi-person-dash"></i> Desfalques</h3>
        <div class="mini-lista">
          <?php if (!$fora): ?>
            <div style="color:var(--txt3);font-size:12.5px">Elenco inteiro à disposição.</div>
          <?php endif; ?>
          <?php foreach ($fora as $nome => $d): ?>
            <?php $susp = $d['motivo'] === 'suspensão'; ?>
            <div class="mini-linha">
              <i class="bi <?= $susp ? 'bi-card-text' : 'bi-bandaid' ?>"
                 style="color:<?= $susp ? 'var(--amarelo)' : '#fca5a5' ?>;font-size:12px"></i>
              <span class="esq"><?= h($nome) ?></span>
              <span class="dir" style="color:<?= $susp ? 'var(--amarelo)' : '#fca5a5' ?>">
                <?= h($d['motivo']) ?> · <?= (int)$d['jogos'] ?>j</span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($ultimos): ?>
        <div class="bloco">
          <h3><i class="bi bi-clock-history"></i> Últimos resultados</h3>
          <div class="mini-lista">
            <?php foreach ($ultimos as $r): ?>
              <?php $cls = $r['meus'] > $r['deles'] ? 'v' : ($r['meus'] < $r['deles'] ? 'd' : ''); ?>
              <div class="mini-linha">
                <?= escudo($clubesTodos[$r['adversario']] ?? ['nome' => $r['adversario']], 16) ?>
                <span class="esq"><?= h($r['adversario']) ?></span>
                <span class="dir"><span class="placar <?= $cls ?>"><?= (int)$r['meus'] ?>–<?= (int)$r['deles'] ?></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

      <?php /* O RESUMO DA ULTIMA PARTIDA SAIU (29/09/2026, a pedido).
           Ele desenhava cada lance da partida, e desenhava errado: o markup
           tratava 'gol' e 'amarelo' e mandava TODO O RESTO pro ramo do cartao
           vermelho — entao chute pra fora, defesa, bola na trave, bloqueio e
           falta viravam expulsao na tela. Um jogo normal aparecia com doze
           expulsoes nos primeiros trinta minutos.
           O motor sempre esteve certo (0,07 vermelho por jogo); quem errava
           era o desenho. As notas de cada um, que moravam aqui, continuam na
           coluna 'Em campo' durante a partida e na aba Numeros. */ ?>


    <?php endif; ?>
  <?php // ── ABA: ELENCO ────────────────────────────────────────────── ?>
  <?php elseif ($aba === 'elenco'): ?>
    <div class="bloco">
      <h3><i class="bi bi-people-fill"></i> Elenco (<?= count($estado['elenco']) ?>)</h3>
      <div style="font-size:12px;color:var(--txt2);margin-bottom:10px">
        Folha: <strong><?= h(futDinheiro($folha)) ?>/ano</strong> ·
        Mínimo <?= FUT_ELENCO_MINIMO ?>, máximo <?= FUT_ELENCO_MAXIMO ?> jogadores
      </div>
      <div class="rolar"><table>
        <thead><tr>
          <th>Jogador</th><th>Pos</th><th class="num">OVR</th><th class="num">Idade</th>
          <th>Condição</th><th class="num">Valor</th><th class="num">Salário</th><th></th>
        </tr></thead>
        <tbody>
        <?php
          $elenco = $estado['elenco'];
          usort($elenco, fn($a, $b) => $b['ovr'] <=> $a['ovr']);
          foreach ($elenco as $j):
            $v = futValorDeMercado((int)$j['ovr'], (int)$j['idade']);
            $s = futSalarioDe((int)$j['ovr'], (int)$j['idade']);
            $cls = $j['ovr'] >= 80 ? 'b' : ($j['ovr'] >= 70 ? 'm' : '');
        ?>
          <tr>
            <td>
              <a class="link-jogo" href="?aba=jogador&amp;nome=<?= urlencode($j['nome']) ?>&amp;de=elenco"><?= h($j['nome']) ?></a>
              <?php if (!empty($j['emprestado_de'])): ?>
                <span class="selo-venda" style="background:rgba(96,165,250,.14);color:#93c5fd;border-color:rgba(96,165,250,.32)"
                      title="Volta pro <?= h($j['emprestado_de']) ?> no fim da temporada">emprestado</span>
              <?php endif; ?>
            </td>
            <td><span class="tagpos"><?= h($j['pos']) ?></span></td>
            <td class="num"><span class="ovr <?= $cls ?>"><?= (int)$j['ovr'] ?></span></td>
            <td class="num"><?= (int)$j['idade'] ?></td>
            <td style="white-space:nowrap">
              <?php $lz = (int)($j['lesao'] ?? 0); $en = futTextoEnergia((int)($j['energia'] ?? 100)); ?>
              <?php if ($lz > 0): ?>
                <span class="cond vermelho"><i class="bi bi-bandaid-fill"></i> <?= $lz ?>j</span>
              <?php elseif (isset(($estado['suspensos'] ?? [])[$j['nome']])): ?>
                <span class="cond vermelho"><i class="bi bi-slash-circle"></i> susp.</span>
              <?php else: ?>
                <span class="cond <?= h($en['cor']) ?>"><?= h($en['txt']) ?></span>
              <?php endif; ?>
            </td>
            <td class="num"><?= h(futDinheiro($v)) ?></td>
            <td class="num"><?= h(futDinheiro($s)) ?></td>
            <td class="num">
              <?php if (!empty($j['emprestado_de'])): ?>
                <span style="font-size:11px;color:var(--txt3)">volta pro <?= h($j['emprestado_de']) ?></span>
              <?php else: ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="acao" value="vender">
                <input type="hidden" name="aba" value="elenco">
                <input type="hidden" name="jogador" value="<?= h($j['nome']) ?>">
                <input type="hidden" name="oferta" value="<?= $v ?>">
                <input type="hidden" name="comprador" value="um clube interessado">
                <button class="btn sec peq" type="submit">Vender</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

  <?php // ── ABA: ESCALAÇÃO ─────────────────────────────────────────── ?>
  <?php elseif ($aba === 'escalacao'): ?>
    <?php
      $esquema = $estado['esquema'] ?? '4-4-2';
      $vagas = FUT_ESQUEMAS[$esquema]['vagas'];
      $escalados = futCarreiraEscalacaoAtual($estado);
      $avisos = futAvisosDaEscalacao($escalados, $esquema);
      $forcaEscalada = futForcaEscalada($escalados, $esquema);
      $suspensos = $estado['suspensos'] ?? [];
      $indisp = futIndisponiveis($estado['elenco'], $suspensos);
      $reservas = futReservas($estado['elenco'], $escalados, array_keys($suspensos));

      // Tudo que o JS precisa saber sobre cada jogador, num lugar só.
      $dadosJs = [];
      foreach ($estado['elenco'] as $j) {
        $dadosJs[$j['nome']] = [
          'nome' => $j['nome'], 'pos' => $j['pos'], 'ovr' => (int)$j['ovr'],
          'idade' => (int)$j['idade'], 'energia' => (int)($j['energia'] ?? 100),
          'moral' => (int)($j['moral'] ?? 75), 'lesao' => (int)($j['lesao'] ?? 0),
          'fora' => isset($indisp[$j['nome']]),
        ];
      }
    ?>
    <div class="bloco">
      <h3><i class="bi bi-diagram-3"></i> Escalação</h3>

      <form method="post" id="formEsq">
        <input type="hidden" name="acao" value="escalar">
        <input type="hidden" name="aba" value="escalacao">

        <div style="display:flex;gap:8px;align-items:flex-end;margin-bottom:10px;flex-wrap:wrap">
          <div style="flex:1;min-width:150px">
            <label for="esquema">Esquema tático</label>
            <select id="esquema" name="esquema" onchange="this.form.querySelectorAll('input[name^=vaga]').forEach(i=>i.remove());this.form.submit()" title="Trocar o esquema reescala o time do zero">
              <?php foreach (FUT_ESQUEMAS as $k => $e): ?>
                <option value="<?= h($k) ?>" <?= $k === $esquema ? 'selected' : '' ?>><?= h($e['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="text-align:right">
            <div style="font-size:11px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px">Força em campo</div>
            <div style="font-size:22px;font-weight:900;line-height:1" id="forcaCampo"><?= (int)$forcaEscalada ?></div>
          </div>
        </div>
        <div style="font-size:12px;color:var(--txt2);margin-bottom:10px"><?= h(FUT_ESQUEMAS[$esquema]['desc']) ?></div>

        <div class="dica-drag" id="dicaDrag">
          <i class="bi bi-hand-index"></i>
          <span>Toque num jogador e depois no outro pra trocar. No computador, dá pra arrastar.</span>
        </div>

        <div class="escalar-lado">
        <div class="campo" id="campo">
          <div class="linha-meio"></div><div class="circulo"></div>
          <div class="area cima"></div><div class="area baixo"></div>
          <?php foreach ($vagas as $iv => $v): ?>
            <?php $j = $escalados[$iv] ?? null; ?>
            <div class="camisa slot" data-vaga="<?= (int)$iv ?>" data-pos="<?= h($v[0]) ?>"
                 data-nome="<?= h($j['nome'] ?? '') ?>"
                 style="left:<?= (float)$v[1] ?>%;top:<?= (float)$v[2] ?>%"
                 draggable="true" tabindex="0" role="button"
                 aria-label="<?= h($v[0]) ?>: <?= h($j['nome'] ?? 'vazio') ?>">
              <div class="bola"><?= $j ? (int)futOvrNaVaga($j, $v[0]) : '—' ?></div>
              <div class="nom"><?= $j ? h($j['nome']) : '—' ?></div>
              <div class="vg"><?= h($v[0]) ?></div>
            </div>
          <?php endforeach; ?>
          <input type="hidden" name="_" value="1">
        </div>

        <div class="banco banco-vert">
          <div class="banco-titulo">
            <i class="bi bi-people"></i> Banco
            <span style="color:var(--txt3);font-weight:400">— arraste ou toque</span>
          </div>
          <div class="banco-lista" id="banco">
            <?php foreach ($reservas as $j): ?>
              <div class="reserva slot" data-vaga="" data-nome="<?= h($j['nome']) ?>"
                   data-pos="<?= h($j['pos']) ?>" draggable="true" tabindex="0" role="button"
                   aria-label="<?= h($j['nome']) ?>, <?= h($j['pos']) ?>, overall <?= (int)$j['ovr'] ?>">
                <span class="r-ovr"><?= (int)$j['ovr'] ?></span>
                <span class="r-nome"><?= h($j['nome']) ?></span>
                <span class="r-pos"><?= h($j['pos']) ?></span>
                <?php $en = futTextoEnergia((int)($j['energia'] ?? 100)); ?>
                <span class="r-en <?= h($en['cor']) ?>" title="energia"><?= (int)($j['energia'] ?? 100) ?></span>
              </div>
            <?php endforeach; ?>
            <?php if (!$reservas): ?>
              <div style="color:var(--txt3);font-size:12px;padding:6px">Ninguém no banco.</div>
            <?php endif; ?>
          </div>
        </div>
        </div><?php // fecha .escalar-lado ?>

        <?php // ── COMO O TIME ENTRA EM CAMPO ────────────────────────── ?>
        <?php $estrAtual = $estado['estrategia'] ?? ['postura' => 'neutro', 'marcacao' => 'normal']; ?>
        <div class="banco-titulo" style="margin-top:14px">
          <i class="bi bi-sliders"></i> Como o time entra em campo
          <span style="color:var(--txt3);font-weight:400">— dá pra mudar durante a partida</span>
        </div>
        <div class="estrategia-cols" style="margin-bottom:12px">
          <div class="opcoes" style="margin-bottom:0">
            <div class="opcoes-rot">Postura</div>
            <?php foreach (FUT_POSTURAS as $k => $o): ?>
              <label class="opcao">
                <input type="radio" name="postura" value="<?= h($k) ?>"
                       <?= ($estrAtual['postura'] ?? 'neutro') === $k ? 'checked' : '' ?>>
                <span><b><?= h($o['nome']) ?></b><i><?= h($o['desc']) ?></i></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="opcoes" style="margin-bottom:0">
            <div class="opcoes-rot">Marcação</div>
            <?php foreach (FUT_MARCACOES as $k => $o): ?>
              <label class="opcao">
                <input type="radio" name="marcacao" value="<?= h($k) ?>"
                       <?= ($estrAtual['marcacao'] ?? 'normal') === $k ? 'checked' : '' ?>>
                <span><b><?= h($o['nome']) ?></b><i><?= h($o['desc']) ?></i></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($avisos): ?>
          <div class="msg err" style="display:block">
            <strong><i class="bi bi-exclamation-triangle"></i> Improvisos custam força:</strong>
            <?php foreach ($avisos as $a): ?>
              <div style="font-size:12.5px;margin-top:3px">· <?= h($a['texto']) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($indisp): ?>
          <div class="msg err" style="display:block">
            <strong><i class="bi bi-bandaid"></i> Fora desta partida:</strong>
            <?php foreach ($indisp as $nm => $d): ?>
              <div style="font-size:12.5px;margin-top:3px">
                · <?= h($nm) ?> — <?= h($d['motivo']) ?>, <?= (int)$d['jogos'] ?> jogo(s)
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
          <button class="btn" type="submit"><i class="bi bi-check-lg"></i> Salvar escalação</button>
          <button class="btn sec" type="button" id="btnAuto"><i class="bi bi-magic"></i> Escalar automaticamente</button>
        </div>
      </form>
      <form method="post" id="formAuto" style="display:none">
        <input type="hidden" name="acao" value="escalar_auto">
        <input type="hidden" name="aba" value="escalacao">
      </form>
    </div>

    <script>
    /* ── ESCALAÇÃO POR TOQUE E POR ARRASTO ──────────────────────────────
       Dois jeitos de fazer a mesma coisa, porque nenhum serve sozinho: o
       arrasto do HTML5 não funciona em toque, e no celular o que as pessoas
       tentam primeiro é tocar. Então TOCAR é o mecanismo principal (tocar num
       jogador seleciona, tocar no outro troca) e o arrasto é um atalho de
       teclado e mouse por cima. O teclado entra de graça: Enter no elemento
       focado faz o mesmo que o toque. */
    (function () {
      const JOGADORES = <?= json_encode($dadosJs, JSON_UNESCAPED_UNICODE) ?>;
      const VAGAS = <?= json_encode(array_map(fn($v) => $v[0], $vagas)) ?>;
      const AFIN = <?= json_encode(FUT_AFINIDADE) ?>;
      const form = document.getElementById('formEsq');
      const campo = document.getElementById('campo');
      const banco = document.getElementById('banco');
      let selecionado = null;

      function ajusteCondicao(j) {
        const porEnergia = j.energia >= 80 ? 0 : -Math.round((80 - j.energia) * 0.20);
        const porMoral = Math.round((j.moral - 75) / 12);
        return porEnergia + porMoral;
      }
      function ovrNaVaga(nome, pos) {
        const j = JOGADORES[nome];
        if (!j) return 0;
        const perda = (AFIN[pos] && AFIN[pos][j.pos] !== undefined) ? AFIN[pos][j.pos] : 12;
        return Math.max(20, j.ovr - perda + ajusteCondicao(j));
      }

      function pintar(slot) {
        const nome = slot.dataset.nome;
        const j = JOGADORES[nome];
        if (slot.classList.contains('reserva')) {
          slot.querySelector('.r-ovr').textContent = j ? j.ovr : '';
          slot.querySelector('.r-nome').textContent = j ? j.nome : '';
          slot.querySelector('.r-pos').textContent = j ? j.pos : '';
          return;
        }
        const pos = slot.dataset.pos;
        const bola = slot.querySelector('.bola');
        bola.textContent = j ? ovrNaVaga(nome, pos) : '—';
        slot.querySelector('.nom').textContent = j ? j.nome : '—';
        const perda = j ? ((AFIN[pos] && AFIN[pos][j.pos] !== undefined) ? AFIN[pos][j.pos] : 12) : 0;
        bola.classList.toggle('improv', !!j && perda >= 8);
        bola.classList.toggle('vazio', !j);
      }

      /* A força em campo tem que ser a MESMA conta do servidor, senão o número
         muda sozinho ao salvar e o jogador deixa de confiar na tela. */
      const PESO = {GOL:1.25, ZAG:1.1, LAT:0.95, VOL:1.0, MEI:1.05, PON:0.95, ATA:1.1};
      function recalcularForca() {
        let soma = 0, pesos = 0;
        campo.querySelectorAll('.slot').forEach(s => {
          const pos = s.dataset.pos, p = PESO[pos] || 1;
          const nome = s.dataset.nome;
          soma += (nome && JOGADORES[nome] ? ovrNaVaga(nome, pos) : 35) * p;
          pesos += p;
        });
        document.getElementById('forcaCampo').textContent = Math.round(soma / Math.max(0.001, pesos));
      }

      /* ── ONDE ESSE JOGADOR CABE ──────────────────────────────────────
         A tabela de afinidade já sabia o custo de cada improviso, mas só
         contava depois — o número da camisa mudava DEPOIS de arrastar. Quem
         não decorou a tabela tinha que tentar pra descobrir, e desfazer.

         Três faixas, porque o futebol tem três: a posição dele (custo zero),
         a que dá pra jogar sem estragar (até quatro de perda — lateral de
         ponta, volante de meia) e o improviso de verdade. A terceira não
         ganha marca nenhuma de propósito: marcar tudo é o mesmo que não
         marcar nada. */
      function marcarVagas(slot) {
        const j = JOGADORES[slot.dataset.nome];
        campo.querySelectorAll('.slot').forEach(s => {
          s.classList.remove('cabe', 'serve');
          if (!j || s === slot) return;
          const perda = (AFIN[s.dataset.pos] && AFIN[s.dataset.pos][j.pos] !== undefined)
                      ? AFIN[s.dataset.pos][j.pos] : 12;
          if (perda === 0) s.classList.add('cabe');
          else if (perda <= 4) s.classList.add('serve');
        });
      }

      function limparSelecao() {
        document.querySelectorAll('.slot.sel').forEach(s => s.classList.remove('sel'));
        campo.querySelectorAll('.cabe, .serve').forEach(s => s.classList.remove('cabe', 'serve'));
        selecionado = null;
      }

      function trocar(a, b) {
        if (!a || !b || a === b) return;
        const na = a.dataset.nome, nb = b.dataset.nome;
        // Trocar dois vazios não faz nada, e mover pro banco exige alguém.
        if (!na && !nb) return;
        a.dataset.nome = nb;
        b.dataset.nome = na;

        /* Uma reserva que ficou sem ninguém some do banco: um cartão vazio no
           banco não significa nada e só ocupa espaço. */
        [a, b].forEach(el => {
          pintar(el);
          if (el.classList.contains('reserva') && !el.dataset.nome) el.remove();
        });
        recalcularForca();
        sincronizar();
        marcarSujo();
      }

      let sujo = false;
      function marcarSujo() {
        if (sujo) return;
        sujo = true;
        const botao = form.querySelector('button[type=submit]');
        if (botao) botao.classList.add('pulsa');
      }

      function aoAtivar(slot) {
        if (!selecionado) {
          if (!slot.dataset.nome) return;       // não dá pra pegar o vazio
          selecionado = slot;
          slot.classList.add('sel');
          marcarVagas(slot);
          return;
        }
        if (selecionado === slot) { limparSelecao(); return; }
        trocar(selecionado, slot);
        limparSelecao();
      }

      function ligar(slot) {
        slot.addEventListener('click', () => aoAtivar(slot));
        slot.addEventListener('keydown', e => {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); aoAtivar(slot); }
          if (e.key === 'Escape') limparSelecao();
        });
        slot.addEventListener('dragstart', e => {
          if (!slot.dataset.nome) { e.preventDefault(); return; }
          selecionado = slot;
          slot.classList.add('sel');
          marcarVagas(slot);
          e.dataTransfer.effectAllowed = 'move';
          // Sem isto o Firefox ignora o arrasto.
          e.dataTransfer.setData('text/plain', slot.dataset.nome);
        });
        slot.addEventListener('dragend', () => limparSelecao());
        slot.addEventListener('dragover', e => { e.preventDefault(); slot.classList.add('alvo'); });
        slot.addEventListener('dragleave', () => slot.classList.remove('alvo'));
        slot.addEventListener('drop', e => {
          e.preventDefault();
          slot.classList.remove('alvo');
          trocar(selecionado, slot);
          limparSelecao();
        });
      }
      document.querySelectorAll('.slot').forEach(ligar);

      // Clicar fora cancela a seleção — senão ela fica presa e confunde.
      document.addEventListener('click', e => {
        if (!e.target.closest('.slot')) limparSelecao();
      });

      /* OS CAMPOS OCULTOS FICAM SEMPRE SINCRONIZADOS com o campo, e não são
         montados no evento submit. Parece detalhe e não é: form.submit()
         chamado por código NÃO dispara o evento 'submit', então a versão
         anterior enviava a escalação vazia sempre que o envio não vinha de um
         clique no botão — o servidor recebia zero nomes, reescalava sozinho, e
         o trabalho do jogador ia pro lixo sem nenhum aviso. */
      function sincronizar() {
        campo.querySelectorAll('.slot').forEach(s => {
          let inp = form.querySelector('input[name="vaga[' + s.dataset.vaga + ']"]');
          if (!inp) {
            inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'vaga[' + s.dataset.vaga + ']';
            form.appendChild(inp);
          }
          inp.value = s.dataset.nome || '';
        });
      }
      sincronizar();

      document.getElementById('btnAuto').addEventListener('click', () => {
        document.getElementById('formAuto').submit();
      });
    })();
    </script>
  <?php // ── ABA: NÚMEROS ───────────────────────────────────────────── ?>
  <?php elseif ($aba === 'stats'): ?>
    <?php
      $stats = $estado['stats'] ?? [];
      $comJogo = array_filter($stats, fn($d) => ($d['jogos'] ?? 0) > 0);
    ?>
    <div class="bloco">
      <h3><i class="bi bi-bar-chart-fill"></i> Números da temporada</h3>
      <?php if (!$comJogo): ?>
        <div class="vazio">Os números aparecem depois da primeira partida.</div>
      <?php else: ?>
        <?php
          $ordenar = $_GET['por'] ?? 'gols';
          $lista = [];
          foreach ($comJogo as $nome => $d) $lista[] = ['nome' => $nome] + $d;
          usort($lista, function ($a, $b) use ($ordenar) {
            if ($ordenar === 'assist') return $b['assist'] <=> $a['assist'];
            if ($ordenar === 'nota')   return futNotaMedia($b) <=> futNotaMedia($a);
            if ($ordenar === 'cartoes') return ($b['amarelos'] + $b['vermelhos'] * 3) <=> ($a['amarelos'] + $a['vermelhos'] * 3);
            return [$b['gols'], $b['assist']] <=> [$a['gols'], $a['assist']];
          });
        ?>
        <div class="abas" style="margin-bottom:10px">
          <?php foreach (['gols' => 'Gols', 'assist' => 'Assistências', 'nota' => 'Nota média', 'cartoes' => 'Cartões'] as $k => $rot): ?>
            <a href="?aba=stats&por=<?= $k ?>" class="<?= $ordenar === $k ? 'on' : '' ?>"><?= h($rot) ?></a>
          <?php endforeach; ?>
        </div>
        <div class="rolar"><table>
          <thead><tr><th>Jogador</th><th>Pos</th><th class="num">J</th><th class="num">G</th>
            <th class="num">A</th><th class="num">Nota</th><th class="num">Cartões</th></tr></thead>
          <tbody>
          <?php foreach ($lista as $d): $nm = futNotaMedia($d); ?>
            <tr>
              <td><?= h($d['nome']) ?></td>
              <td><span class="tagpos"><?= h($d['pos'] ?? '') ?></span></td>
              <td class="num"><?= (int)$d['jogos'] ?></td>
              <td class="num"><strong><?= (int)$d['gols'] ?></strong></td>
              <td class="num"><?= (int)$d['assist'] ?></td>
              <td class="num"><span class="nota <?= $nm >= 7 ? 'alta' : ($nm < 5.8 ? 'baixa' : '') ?>"><?= number_format($nm, 2, ',', '.') ?></span></td>
              <td class="num" style="white-space:nowrap">
                <?php for ($i = 0; $i < min(5, (int)$d['amarelos']); $i++): ?><span class="cartao ama" style="margin-right:1px"></span><?php endfor; ?>
                <?php if ((int)$d['amarelos'] > 5): ?><span style="font-size:10px;color:var(--txt3)">×<?= (int)$d['amarelos'] ?></span><?php endif; ?>
                <?php for ($i = 0; $i < min(3, (int)$d['vermelhos']); $i++): ?><span class="cartao ver" style="margin-right:1px"></span><?php endfor; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

  <?php // ── ABA: TABELA ────────────────────────────────────────────── ?>
  <?php elseif ($aba === 'tabela'): ?>
    <?php
      /* TODAS AS COMPETIÇÕES DO ANO, e não só o nacional. O técnico disputa
         três ou quatro ao mesmo tempo — estadual, nacional, copa regional e
         Copa do Brasil —, e a tabela de uma só contava um terço da temporada. */
      $comps = futCarreiraCompeticoesDoAno($estado);
      $divAtual = $clubesTodos[$estado['clube']]['div'] ?? '';
      $padrao = futCarreiraNomeDaDivisao($divAtual);
      if (!isset($comps[$padrao])) $padrao = (string)(array_key_first($comps) ?? '');
      $compSel = (string)($_GET['comp'] ?? $padrao);
      if (!isset($comps[$compSel])) $compSel = $padrao;
      $info = $comps[$compSel] ?? ['jogos' => 0, 'jogados' => 0, 'tabela' => false];
      $tab = $info['tabela'] ? futCarreiraTabelaDaCompeticao($estado, $compSel) : [];
      $camp = futCarreiraCampanha($estado, $compSel);
    ?>
    <?php if (!$comps): ?>
      <div class="bloco"><div class="vazio">A temporada ainda não começou.</div></div>
    <?php else: ?>
      <div class="bloco">
        <h3><i class="bi bi-table"></i> Classificação</h3>

        <form method="get" style="margin-bottom:12px">
          <input type="hidden" name="aba" value="tabela">
          <label for="comp">Competição</label>
          <select id="comp" name="comp" onchange="this.form.submit()">
            <?php foreach ($comps as $c => $d): ?>
              <option value="<?= h($c) ?>" <?= $c === $compSel ? 'selected' : '' ?>>
                <?= h($c) ?> — <?= (int)$d['jogados'] ?> de <?= (int)$d['jogos'] ?> jogos
              </option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn peq" type="submit" style="margin-top:8px">Ver</button></noscript>
        </form>

        <div class="fichas" style="grid-template-columns:repeat(5,1fr);margin-bottom:12px">
          <div class="ficha"><div class="v"><?= (int)$camp['j'] ?></div><div class="r">jogos</div></div>
          <div class="ficha"><div class="v"><?= (int)$camp['v'] ?></div><div class="r">vitórias</div></div>
          <div class="ficha"><div class="v"><?= (int)$camp['e'] ?></div><div class="r">empates</div></div>
          <div class="ficha"><div class="v"><?= (int)$camp['d'] ?></div><div class="r">derrotas</div></div>
          <div class="ficha"><div class="v"><?= (int)$camp['gp'] ?>–<?= (int)$camp['gc'] ?></div><div class="r">gols</div></div>
        </div>

        <?php if (!$info['tabela']): ?>
          <?php
            /* O CAMINHO ATÉ A FINAL. Mata-mata não tem classificação, e a
               lista de jogos não conta a história: o que importa numa copa é
               até onde você foi e quem te tirou. As fases que ainda estão no
               calendário entram como "a jogar" — some quando o clube cai,
               porque a eliminação tira os jogos seguintes dali. */
            $caminho = [];
            foreach ($estado['resultados'] ?? [] as $r) {
              if (($r['comp'] ?? '') !== $compSel) continue;
              $caminho[] = ['fase' => (string)($r['fase'] ?? ''), 'adv' => $r['adversario'],
                            'meus' => (int)$r['meus'], 'deles' => (int)$r['deles'],
                            'jogado' => true, 'passou' => !empty($r['passou']),
                            'penaltis' => !empty($r['penaltis'])];
            }
            foreach ($estado['calendario'] ?? [] as $k => $j) {
              if (($j['comp'] ?? '') !== $compSel || $k < (int)$estado['rodada']) continue;
              $caminho[] = ['fase' => (string)($j['fase'] ?? ''), 'adv' => $j['adversario'],
                            'jogado' => false];
            }
            $campeao = $caminho && end($caminho)['jogado']
                    && end($caminho)['passou'] && stripos(end($caminho)['fase'], 'final') !== false
                    && stripos(end($caminho)['fase'], 'semi') === false;
          ?>
          <?php if (!$caminho): ?>
            <div class="vazio">O clube ainda não entrou nesta competição.</div>
          <?php else: ?>
            <div class="chave">
              <?php foreach ($caminho as $p): ?>
                <?php
                  $cls = !$p['jogado'] ? 'futura' : ($p['passou'] ? 'venceu' : 'caiu');
                ?>
                <div class="chave-fase <?= $cls ?>">
                  <span class="chave-rot"><?= h($p['fase']) ?></span>
                  <span class="chave-adv">
                    <?= escudo($clubesTodos[$p['adv']] ?? ['nome' => $p['adv']], 22) ?>
                    <span><?= h($p['adv']) ?></span>
                  </span>
                  <?php if ($p['jogado']): ?>
                    <span class="chave-placar"><?= $p['meus'] ?>–<?= $p['deles'] ?></span>
                    <span class="chave-marca <?= $p['passou'] ? 'ok' : 'fim' ?>">
                      <?= $p['passou'] ? 'passou' : 'eliminado' ?>
                      <?= $p['penaltis'] ? ' nos pênaltis' : '' ?>
                    </span>
                  <?php else: ?>
                    <span class="chave-marca tac">a jogar</span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if ($campeao): ?>
              <div class="chave-titulo"><i class="bi bi-trophy-fill"></i>
                Campeão d<?= futArtigoDaCompeticao($compSel) ?> <?= h($compSel) ?></div>
            <?php endif; ?>
          <?php endif; ?>
        <?php elseif (!$tab): ?>
          <div class="vazio">A tabela aparece depois da primeira rodada.</div>
        <?php else: ?>
          <div class="rolar"><table>
            <thead><tr><th></th><th>Clube</th><th class="num">P</th><th class="num">J</th>
              <th class="num">V</th><th class="num">E</th><th class="num">D</th><th class="num">SG</th></tr></thead>
            <tbody>
            <?php
              /* SÓ O NACIONAL TEM ZONA PINTADA: num estadual ou numa copa
                 regional, pintar as pontas seria inventar regra. Quantos
                 sobem e quantos caem depende da liga — a Série D não rebaixa
                 ninguém e a Bundesliga rebaixa dois (@see futZonasDaTabela). */
              $ehNacional = $compSel === futCarreiraNomeDaDivisao($divAtual) && $divAtual !== '';
              [$nSobe, $nEuro, $nCai] = $ehNacional ? futZonasDaTabela($divAtual) : [0, 0, 0];
              $i = 0; $total = count($tab); foreach ($tab as $nome => $l): $i++;
              $clsPos = ($i <= $nSobe) ? 'sobe'
                      : (($i <= $nEuro) ? 'euro'
                      : (($nCai && $i > $total - $nCai) ? 'cai' : '')); ?>
              <tr class="<?= $nome === $estado['clube'] ? 'eu' : '' ?>">
                <td><span class="pos <?= $clsPos ?>"><?= $i ?></span></td>
                <td><a class="link-jogo clube-cel" href="?aba=clube&amp;nome=<?= urlencode($nome) ?>&amp;de=tabela"><?php
                  /* O ESCUDO NA TABELA. Vinte nomes numa coluna é uma lista de
                     texto; vinte escudos é uma classificação, e o técnico acha
                     o time dele sem ler. */
                  echo escudo($clubesTodos[$nome] ?? ['nome' => $nome], 18); ?><span><?= h($nome) ?></span></a></td>
                <td class="num"><strong><?= (int)$l['p'] ?></strong></td>
                <td class="num"><?= (int)$l['j'] ?></td>
                <td class="num"><?= (int)$l['v'] ?></td>
                <td class="num"><?= (int)$l['e'] ?></td>
                <td class="num"><?= (int)$l['d'] ?></td>
                <td class="num"><?= $l['sg'] > 0 ? '+' : '' ?><?= (int)$l['sg'] ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </div>

      <?php
        $jogosComp = array_values(array_filter($estado['resultados'] ?? [],
                                  fn($r) => ($r['comp'] ?? '') === $compSel));
      ?>
      <?php
        /* OS DESTAQUES SÓ FAZEM SENTIDO ONDE HÁ TABELA. Numa copa de
           mata-mata cada clube joga um punhado de partidas e o artilheiro
           sairia de três jogos — número que não diz nada. */
        $dest = $info['tabela'] ? futCarreiraDestaquesDaCompeticao($estado, $compSel) : null;
      ?>
      <?php if ($dest && ($dest['artilheiros'] || $dest['goleiros'])): ?>
        <div class="bloco">
          <h3><i class="bi bi-award"></i> Destaques d<?= futArtigoDaCompeticao($compSel) ?> <?= h($compSel) ?></h3>
          <div class="destaques">

            <?php if ($dest['artilheiros']): ?>
              <div>
                <div class="opcoes-rot">Artilheiros</div>
                <div class="destaque-lista">
                  <?php foreach ($dest['artilheiros'] as $i => $a): ?>
                    <div class="destaque-linha <?= $a['clube'] === $estado['clube'] ? 'eu' : '' ?>">
                      <span class="p"><?= $i + 1 ?></span>
                      <span class="n"><?= h($a['nome']) ?></span>
                      <span class="c"><?= h($a['clube']) ?></span>
                      <span class="v"><?= (int)$a['gols'] ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($dest['garcons']): ?>
              <div>
                <div class="opcoes-rot">Assistências</div>
                <div class="destaque-lista">
                  <?php foreach ($dest['garcons'] as $i => $a): ?>
                    <div class="destaque-linha <?= $a['clube'] === $estado['clube'] ? 'eu' : '' ?>">
                      <span class="p"><?= $i + 1 ?></span>
                      <span class="n"><?= h($a['nome']) ?></span>
                      <span class="c"><?= h($a['clube']) ?></span>
                      <span class="v"><?= (int)$a['assist'] ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($dest['goleiros']): ?>
              <div>
                <div class="opcoes-rot">Goleiros menos vencidos</div>
                <div class="destaque-lista">
                  <?php foreach ($dest['goleiros'] as $i => $g): ?>
                    <div class="destaque-linha <?= $g['clube'] === $estado['clube'] ? 'eu' : '' ?>">
                      <span class="p"><?= $i + 1 ?></span>
                      <span class="n"><?= h($g['nome']) ?></span>
                      <span class="c"><?= h($g['clube']) ?></span>
                      <span class="v" title="<?= (int)$g['sofridos'] ?> gols em <?= (int)$g['jogos'] ?> jogos">
                        <?= number_format((float)$g['media'], 2, ',', '') ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($dest['notas']): ?>
              <div>
                <div class="opcoes-rot">Melhores notas · seu elenco</div>
                <div class="destaque-lista">
                  <?php foreach ($dest['notas'] as $i => $n): ?>
                    <div class="destaque-linha eu">
                      <span class="p"><?= $i + 1 ?></span>
                      <span class="n"><a class="link-jogo" href="?aba=jogador&amp;nome=<?= urlencode($n['nome']) ?>&amp;de=tabela"><?= h($n['nome']) ?></a></span>
                      <span class="c"><?= (int)$n['jogos'] ?> jogos</span>
                      <span class="v"><?= number_format((float)$n['media'], 2, ',', '') ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

          </div>
          <div class="destaque-nota">
            Gols e assistências saem da mesma tabela acima — são os gols que cada clube fez,
            distribuídos entre quem joga nele. A nota vem de partida disputada, então a lista
            de notas é a do seu elenco: os outros clubes não jogam partida, têm placar.
          </div>
        </div>
      <?php endif; ?>

      <?php if ($jogosComp): ?>
        <div class="bloco">
          <h3><i class="bi bi-list-ol"></i> Jogos em <?= h($compSel) ?></h3>
          <div class="rolar"><table>
            <thead><tr><th>Adversário</th><th>Onde</th><th>Fase</th><th class="num">Placar</th></tr></thead>
            <tbody>
              <?php foreach (array_reverse($jogosComp) as $r): ?>
                <?php $cls = $r['meus'] > $r['deles'] ? 'v' : ($r['meus'] < $r['deles'] ? 'd' : ''); ?>
                <tr>
                  <td><?= h($r['adversario']) ?></td>
                  <td><?= $r['casa'] ? 'casa' : 'fora' ?></td>
                  <td><?= h($r['fase'] ?? '') ?></td>
                  <td class="num"><span class="placar <?= $cls ?>"><?= (int)$r['meus'] ?>–<?= (int)$r['deles'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  <?php // ── PÁGINA: CLUBE ──────────────────────────────────────────── ?>
  <?php // ── PÁGINA: COMPETIÇÃO ─────────────────────────────────────── ?>
  <?php elseif ($aba === 'competicao'): ?>
    <?php
      $comp = (string)($_GET['nome'] ?? '');
      $info = futTodasAsCompeticoes()[$comp] ?? null;
    ?>
    <div class="voltar-linha"><a href="?aba=<?= h((string)($_GET['de'] ?? 'inicio')) ?>">
      <i class="bi bi-arrow-left"></i> Voltar</a></div>

    <?php if (!$info): ?>
      <div class="bloco"><div class="vazio">Não achei essa competição.</div></div>
    <?php else: ?>
      <?php
        $div     = (string)$info['div'];
        $minhas  = futCarreiraCompeticoesDoAno($estado);
        $euJogo  = isset($minhas[$comp]);
        /* A TABELA DE QUALQUER LIGA, inclusive as que o técnico não disputa —
           é o ponto da página. Copa não tem tabela: mata-mata classifica
           eliminando, e inventar uma seria mentir. */
        $tab     = $div !== '' ? futCarreiraTabelaDeQualquerLiga($estado, $div) : [];
        $campanha = $euJogo ? futCarreiraCampanha($estado, $comp) : null;
      ?>

      <div class="bloco">
        <div class="ficha-topo">
          <span class="mono" style="width:44px;height:44px;font-size:17px"><i class="bi bi-trophy"></i></span>
          <div style="min-width:0">
            <div class="ficha-nome"><?= h($comp) ?></div>
            <div class="ficha-sub">
              <?= h(ucfirst($info['tipo'])) ?>
              <?php if ($div !== ''): ?> · <?= count(futClubesDaDivisaoDoJogo($div)) ?> clubes<?php endif; ?>
              <?php if ($euJogo): ?> · <span style="color:var(--acento)">você disputa</span><?php endif; ?>
            </div>
          </div>
        </div>

        <?php if ($campanha): ?>
          <div class="fichas" style="grid-template-columns:repeat(4,1fr)">
            <div class="ficha"><div class="v"><?= (int)$campanha['j'] ?></div><div class="r">jogos</div></div>
            <div class="ficha"><div class="v"><?= (int)$campanha['v'] ?></div><div class="r">vitórias</div></div>
            <div class="ficha"><div class="v"><?= (int)$campanha['e'] ?></div><div class="r">empates</div></div>
            <div class="ficha"><div class="v"><?= (int)$campanha['d'] ?></div><div class="r">derrotas</div></div>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($tab): ?>
        <?php
          [$zVerde, $zEuro, $zCai] = futZonasDaTabela($div);
          $totalT = count($tab);
        ?>
        <div class="bloco">
          <h3><i class="bi bi-table"></i> Classificação</h3>
          <div class="rolar"><table>
            <thead><tr><th></th><th>Clube</th><th class="num">P</th><th class="num">J</th>
              <th class="num">V</th><th class="num">E</th><th class="num">D</th><th class="num">SG</th></tr></thead>
            <tbody>
            <?php $i = 0; foreach ($tab as $nome => $l): $i++;
              $cz = $i <= $zVerde ? 'sobe' : ($i <= $zEuro ? 'euro' : (($zCai && $i > $totalT - $zCai) ? 'cai' : '')); ?>
              <tr class="<?= $nome === $estado['clube'] ? 'eu' : '' ?>">
                <td><span class="pos <?= $cz ?>"><?= $i ?></span></td>
                <td><a class="link-jogo clube-cel" href="?aba=clube&amp;nome=<?= urlencode($nome) ?>&amp;de=inicio"><?php
                  echo escudo($clubesTodos[$nome] ?? ['nome' => $nome], 18); ?><span><?= h($nome) ?></span></a></td>
                <td class="num"><strong><?= (int)$l['p'] ?></strong></td>
                <td class="num"><?= (int)$l['j'] ?></td>
                <td class="num"><?= (int)$l['v'] ?></td>
                <td class="num"><?= (int)$l['e'] ?></td>
                <td class="num"><?= (int)$l['d'] ?></td>
                <td class="num"><?= $l['sg'] > 0 ? '+' : '' ?><?= (int)$l['sg'] ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php if (!$euJogo): ?>
            <div class="destaque-nota">
              Esta liga corre junto com a sua: ela está na mesma altura do ano em que você está na
              sua — se você jogou metade do seu campeonato, ela também jogou metade do dela.
            </div>
          <?php endif; ?>
        </div>

        <?php $dest = futCarreiraDestaquesDaCompeticao($estado, $comp, 8, $tab); ?>
        <?php if ($dest['artilheiros'] || $dest['goleiros']): ?>
          <div class="bloco">
            <h3><i class="bi bi-award"></i> Destaques</h3>
            <div class="destaques">
              <?php if ($dest['artilheiros']): ?>
                <div>
                  <div class="opcoes-rot">Artilheiros</div>
                  <div class="destaque-lista">
                    <?php foreach ($dest['artilheiros'] as $k => $a): ?>
                      <div class="destaque-linha <?= $a['clube'] === $estado['clube'] ? 'eu' : '' ?>">
                        <span class="p"><?= $k + 1 ?></span>
                        <span class="n"><?= h($a['nome']) ?></span>
                        <span class="c"><?= h($a['clube']) ?></span>
                        <span class="v"><?= (int)$a['gols'] ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
              <?php if ($dest['garcons']): ?>
                <div>
                  <div class="opcoes-rot">Assistências</div>
                  <div class="destaque-lista">
                    <?php foreach ($dest['garcons'] as $k => $a): ?>
                      <div class="destaque-linha <?= $a['clube'] === $estado['clube'] ? 'eu' : '' ?>">
                        <span class="p"><?= $k + 1 ?></span>
                        <span class="n"><?= h($a['nome']) ?></span>
                        <span class="c"><?= h($a['clube']) ?></span>
                        <span class="v"><?= (int)$a['assist'] ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
              <?php if ($dest['goleiros']): ?>
                <div>
                  <div class="opcoes-rot">Goleiros menos vencidos</div>
                  <div class="destaque-lista">
                    <?php foreach ($dest['goleiros'] as $k => $g): ?>
                      <div class="destaque-linha <?= $g['clube'] === $estado['clube'] ? 'eu' : '' ?>">
                        <span class="p"><?= $k + 1 ?></span>
                        <span class="n"><?= h($g['nome']) ?></span>
                        <span class="c"><?= h($g['clube']) ?></span>
                        <span class="v"><?= number_format((float)$g['media'], 2, ',', '') ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

      <?php elseif ($euJogo): ?>
        <?php
          $jogosComp = array_values(array_filter($estado['resultados'] ?? [],
                       fn($r) => ($r['comp'] ?? '') === $comp));
        ?>
        <div class="bloco">
          <h3><i class="bi bi-diagram-2"></i> Seu caminho</h3>
          <?php if (!$jogosComp): ?>
            <div class="vazio">Você ainda não jogou nada nesta competição.</div>
          <?php endif; ?>
          <?php foreach ($jogosComp as $r): ?>
            <?php $cls = $r['meus'] > $r['deles'] ? 'v' : ($r['meus'] < $r['deles'] ? 'd' : ''); ?>
            <div class="partida">
              <?= escudo($clubesTodos[$r['adversario']] ?? ['nome' => $r['adversario']], 24) ?>
              <div style="min-width:0">
                <div class="comp"><?= h($r['fase'] ?? '') ?> · <?= $r['casa'] ? 'casa' : 'fora' ?></div>
                <div class="adv"><?= h($r['adversario']) ?></div>
              </div>
              <div class="placar <?= $cls ?>"><?= (int)$r['meus'] ?>–<?= (int)$r['deles'] ?></div>
            </div>
          <?php endforeach; ?>
        </div>

      <?php else: ?>
        <div class="bloco">
          <div class="vazio">
            <?= h($comp) ?> é mata-mata, e mata-mata não tem classificação —
            quem perde vai pra casa. Como você não disputa esta, não há campanha pra mostrar.
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  <?php elseif ($aba === 'clube'): ?>
    <?php
      $alvo = (string)($_GET['nome'] ?? '');
      $c = $clubesTodos[$alvo] ?? null;
    ?>
    <?php if (!$c): ?>
      <div class="bloco"><div class="vazio">Não achei esse clube.</div></div>
    <?php else: ?>
      <?php
        $meu = $alvo === $estado['clube'];
        /* O ELENCO QUE ELE TEM HOJE, e não o do catálogo: quem você comprou
           dele já saiu, e quem você vendeu já está lá. */
        $elencoDele = $meu ? $estado['elenco']
                           : futElencoNoJogo($estado, $alvo, (int)$c['forca']);
        usort($elencoDele, fn($a, $b) => (int)$b['ovr'] <=> (int)$a['ovr']);
        $forcaDele = futForcaDoElenco($elencoDele);
        $metaDele = futMetaDaTemporada($c);

        // O que já rolou entre vocês nesta temporada.
        $confrontos = array_values(array_filter($estado['resultados'] ?? [],
                      fn($r) => ($r['adversario'] ?? '') === $alvo));
      ?>
      <div class="voltar-linha"><a href="?aba=<?= h((string)($_GET['de'] ?? 'tabela')) ?>">
        <i class="bi bi-arrow-left"></i> Voltar</a></div>

      <div class="bloco">
        <div class="ficha-topo">
          <?= escudo($c, 56) ?>
          <div style="min-width:0">
            <div class="nome"><?= h($alvo) ?><?= $meu ? ' <span class="elo">seu clube</span>' : '' ?></div>
            <div class="sub">
              <?= h(futCarreiraRotuloDaDivisao((string)$c['div'])) ?>
              <?= !empty($c['uf']) ? '· ' . h($c['uf']) : '' ?>
              <?= !empty($c['regiao']) && isset(FUT_REGIONAIS[$c['regiao']]) ? '· ' . h(FUT_REGIONAIS[$c['regiao']]) : '' ?>
            </div>
          </div>
        </div>

        <div class="fichas">
          <?php /* A ficha da força do OUTRO clube saiu: o elenco está logo
                   abaixo, com overall jogador por jogador, e é de lá que se
                   tira se o time é bom — que é uma leitura, não um número
                   pronto. A do clube do técnico continua: aquela é a dele,
                   e é o que ele move comprando e vendendo. */ ?>
          <div class="ficha"><div class="v"><?= count($elencoDele) ?></div><div class="r">jogadores</div></div>
          <div class="ficha"><div class="v"><?= (int)round(array_sum(array_column($elencoDele, 'idade')) / max(1, count($elencoDele))) ?></div><div class="r">idade média</div></div>
          <div class="ficha"><div class="v"><?= h(futDinheiro(futFolhaDoElenco($elencoDele))) ?></div><div class="r">folha</div></div>
          <div class="ficha"><div class="v"><?= h(futDinheiro(futReceitaAnual((int)$c['forca'], (string)$c['div']))) ?></div><div class="r">receita/ano</div></div>
        </div>

        <div class="meta-linha"><i class="bi bi-bullseye"></i>
          <span>A diretoria dele cobra: <strong><?= h($metaDele['texto']) ?></strong></span></div>
      </div>

      <?php if ($confrontos): ?>
        <div class="bloco">
          <h3><i class="bi bi-arrow-left-right"></i> Vocês já se enfrentaram</h3>
          <div class="rolar"><table>
            <thead><tr><th>Competição</th><th>Onde</th><th class="num">Placar</th></tr></thead>
            <tbody>
              <?php foreach (array_reverse($confrontos) as $r): ?>
                <?php $cls = $r['meus'] > $r['deles'] ? 'v' : ($r['meus'] < $r['deles'] ? 'd' : ''); ?>
                <tr>
                  <td><?= h($r['comp']) ?></td>
                  <td><?= $r['casa'] ? 'casa' : 'fora' ?></td>
                  <td class="num"><span class="placar <?= $cls ?>"><?= (int)$r['meus'] ?>–<?= (int)$r['deles'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        </div>
      <?php endif; ?>

      <div class="bloco">
        <h3><i class="bi bi-people-fill"></i> Elenco</h3>
        <div class="rolar"><table>
          <thead><tr><th>Jogador</th><th>Pos</th><th class="num">OVR</th><th class="num">Idade</th>
            <th class="num">Vale</th></tr></thead>
          <tbody>
            <?php foreach ($elencoDele as $j): ?>
              <tr>
                <td><a class="link-jogo" href="?aba=jogador&amp;nome=<?= urlencode($j['nome']) ?>&amp;clube=<?= urlencode($alvo) ?>&amp;de=clube"><?= h($j['nome']) ?></a></td>
                <td><span class="tagpos"><?= h($j['pos']) ?></span></td>
                <td class="num"><?= (int)$j['ovr'] ?></td>
                <td class="num"><?= (int)$j['idade'] ?></td>
                <td class="num"><?= h(futDinheiro(futValorDeMercado((int)$j['ovr'], (int)$j['idade']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    <?php endif; ?>

  <?php // ── PÁGINA: JOGADOR ────────────────────────────────────────── ?>
  <?php elseif ($aba === 'jogador'): ?>
    <?php
      $nomeJ = (string)($_GET['nome'] ?? '');
      $clubeJ = (string)($_GET['clube'] ?? $estado['clube']);

      $lista = $clubeJ === $estado['clube']
             ? $estado['elenco']
             : futElencoNoJogo($estado, $clubeJ, (int)($clubesTodos[$clubeJ]['forca'] ?? 50));
      $j = null;
      foreach ($lista as $x) if ($x['nome'] === $nomeJ) { $j = $x; break; }
    ?>
    <?php if (!$j): ?>
      <div class="bloco"><div class="vazio">Não achei esse jogador no <?= h($clubeJ) ?>.</div></div>
    <?php else: ?>
      <?php
        $meuJogador = $clubeJ === $estado['clube'];
        $st = $estado['stats'][$nomeJ] ?? null;
        $valor = futValorDeMercado((int)$j['ovr'], (int)$j['idade']);
        $salario = futSalarioDe((int)$j['ovr'], (int)$j['idade']);
        $ovrHoje = futOvrComCondicao($j);
      ?>
      <div class="voltar-linha"><a href="?aba=<?= h((string)($_GET['de'] ?? 'elenco')) ?>">
        <i class="bi bi-arrow-left"></i> Voltar</a></div>

      <div class="bloco">
        <div class="ficha-topo">
          <?= escudo($clubesTodos[$clubeJ] ?? ['nome' => $clubeJ], 48) ?>
          <div style="min-width:0">
            <div class="nome"><?= h($nomeJ) ?></div>
            <div class="sub">
              <span class="tagpos"><?= h($j['pos']) ?></span>
              <?= (int)$j['idade'] ?> anos ·
              <a class="link-jogo" href="?aba=clube&amp;nome=<?= urlencode($clubeJ) ?>&amp;de=elenco"><?= h($clubeJ) ?></a>
            </div>
          </div>
        </div>

        <div class="fichas">
          <div class="ficha"><div class="v"><?= (int)$j['ovr'] ?></div><div class="r">OVR</div></div>
          <div class="ficha"><div class="v"><?= $ovrHoje ?></div><div class="r">hoje em campo</div></div>
          <div class="ficha"><div class="v"><?= h(futDinheiro($valor)) ?></div><div class="r">vale</div></div>
          <div class="ficha"><div class="v"><?= h(futDinheiro($salario)) ?></div><div class="r">salário/ano</div></div>
        </div>

        <?php if ($meuJogador): ?>
          <div style="margin-top:14px">
            <div class="barra-skill"><span class="r">Energia</span>
              <span class="t"><span style="width:<?= (int)($j['energia'] ?? 100) ?>%"></span></span>
              <span class="v"><?= (int)($j['energia'] ?? 100) ?></span></div>
            <div class="barra-skill"><span class="r">Moral</span>
              <span class="t"><span style="width:<?= (int)($j['moral'] ?? 75) ?>%"></span></span>
              <span class="v"><?= (int)($j['moral'] ?? 75) ?></span></div>
          </div>
          <?php if ((int)($j['lesao'] ?? 0) > 0): ?>
            <div class="msg err" style="margin-top:12px"><i class="bi bi-bandaid"></i>
              Machucado: fica fora por <?= (int)$j['lesao'] ?> jogo(s).</div>
          <?php endif; ?>
          <?php if (isset($estado['suspensos'][$nomeJ])): ?>
            <div class="msg err" style="margin-top:8px"><i class="bi bi-slash-circle"></i>
              Suspenso por <?= (int)$estado['suspensos'][$nomeJ] ?> jogo(s).</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php if ($st): ?>
        <div class="bloco">
          <h3><i class="bi bi-graph-up"></i> Na temporada</h3>
          <div class="fichas" style="grid-template-columns:repeat(5,1fr)">
            <div class="ficha"><div class="v"><?= (int)$st['jogos'] ?></div><div class="r">jogos</div></div>
            <div class="ficha"><div class="v"><?= (int)$st['gols'] ?></div><div class="r">gols</div></div>
            <div class="ficha"><div class="v"><?= (int)$st['assist'] ?></div><div class="r">assist.</div></div>
            <div class="ficha"><div class="v"><?= (int)$st['amarelos'] ?>/<?= (int)$st['vermelhos'] ?></div><div class="r">cartões</div></div>
            <div class="ficha"><div class="v"><?= $st['jogos'] > 0
              ? number_format($st['soma_notas'] / $st['jogos'], 1, ',', '') : '—' ?></div>
              <div class="r">nota média</div></div>
          </div>
        </div>
      <?php elseif ($meuJogador): ?>
        <div class="bloco"><div class="vazio">Ainda não entrou em campo nesta temporada.</div></div>
      <?php endif; ?>
    <?php endif; ?>

  <?php // ── ABA: MERCADO ───────────────────────────────────────────── ?>
  <?php elseif ($aba === 'mercado'): ?>
    <?php
      $div = $clubesTodos[$estado['clube']]['div'] ?? '';
      /* O mercado varre a sua divisão e a de baixo: é de onde o clube compra
         de verdade. Varrer os 98 clubes deixaria a lista lenta e cheia de nome
         que não interessa a ninguém. */
      /* A Série D fechou o fundo da escada: antes o clube da Série C varria a
         divisão de baixo procurando quem não tinha divisão nenhuma, e desde que
         esses 42 clubes viraram a Série D essa busca não achava mais ninguém.

         E A DIVISÃO DE CIMA ENTROU POR CAUSA DO EMPRÉSTIMO. Comprar, o clube
         pequeno compra embaixo; pedir emprestado, ele pede em cima — é lá que
         estão os garotos que não jogam. Sem essa linha, o empréstimo só
         alcançaria quem ele já podia comprar. */
      $ordem = ['BR1' => ['BR1', 'BR2'], 'BR2' => ['BR1', 'BR2', 'BR3'],
                'BR3' => ['BR2', 'BR3', 'BR4'], 'BR4' => ['BR3', 'BR4']];
      $divs = $ordem[$div] ?? ['BR4'];
      $fonte = [];
      foreach ($divs as $d) {
        foreach ($clubesTodos as $c) if (($c['div'] ?? '') === $d && $c['nome'] !== $estado['clube']) $fonte[] = $c;
      }
      $fonte = array_slice($fonte, 0, 30);
      $lista = futMercadoDisponivel($fonte, (float)$estado['caixa'], 400, $estado['saidas'] ?? []);

      // ── Os filtros ────────────────────────────────────────────────
      $busca   = trim((string)($_GET['q'] ?? ''));
      $fPos    = (string)($_GET['pos'] ?? '');
      $soVenda = !empty($_GET['venda']);
      $soCabe  = !empty($_GET['cabe']);
      $ordenar = (string)($_GET['ord'] ?? 'ovr');

      $filtrada = array_filter($lista, function ($m) use ($busca, $fPos, $soVenda, $soCabe, $estado) {
        if ($busca !== '' && mb_stripos($m['nome'], $busca) === false
            && mb_stripos($m['clube'], $busca) === false) return false;
        if ($fPos !== '' && $m['pos'] !== $fPos) return false;
        if ($soVenda && empty($m['a_venda'])) return false;
        if ($soCabe && $m['pedido'] > (float)$estado['caixa']) return false;
        if (!empty($_GET['emp']) && empty($m['emprestavel'])) return false;
        return true;
      });

      /* ── ORDENAR PELA COLUNA ─────────────────────────────────────────
         Cada coluna tem um sentido NATURAL: overall e valor começam do
         maior, idade e preço do menor. É o que a pessoa quer no primeiro
         clique — ninguém abre o mercado procurando o jogador mais caro ou o
         mais velho. Clicar de novo inverte, e aí o segundo clique responde a
         outra pergunta ("quem é o veterano barato?"). */
      $ordemNatural = ['ovr' => 'desc', 'idade' => 'asc', 'preco' => 'asc',
                       'vale' => 'desc', 'nome' => 'asc', 'negocio' => 'asc'];
      if (!isset($ordemNatural[$ordenar])) $ordenar = 'ovr';
      $dir = ($_GET['dir'] ?? '') === 'asc' || ($_GET['dir'] ?? '') === 'desc'
           ? (string)$_GET['dir'] : $ordemNatural[$ordenar];

      $chave = function (array $m) use ($ordenar) {
        return match ($ordenar) {
          'preco'   => $m['pedido'],
          'idade'   => $m['idade'],
          'vale'    => $m['valor'],
          'nome'    => mb_strtolower($m['nome']),
          // "Melhor negócio" é quanto ele pede sobre quanto vale: quanto
          // menor a razão, mais barato ele está saindo.
          'negocio' => $m['pedido'] / max(0.01, $m['valor']),
          default   => $m['ovr'],
        };
      };
      usort($filtrada, function ($a, $b) use ($chave, $dir) {
        $r = $chave($a) <=> $chave($b);
        return $dir === 'desc' ? -$r : $r;
      });

      /* O link de cada cabeçalho: mantém os filtros, troca a coluna e, se já
         for a coluna atual, vira o sentido. */
      $linkOrdem = function (string $col) use ($ordenar, $dir, $ordemNatural) {
        $q = $_GET;
        $q['ord'] = $col;
        $q['dir'] = $ordenar === $col
            ? ($dir === 'asc' ? 'desc' : 'asc')
            : $ordemNatural[$col];
        return '?' . http_build_query($q);
      };
      $setaOrdem = fn(string $col) => $ordenar !== $col ? ''
          : ' <i class="bi bi-caret-' . ($dir === 'asc' ? 'up' : 'down') . '-fill"></i>';
      $total = count($filtrada);
      $filtrada = array_slice($filtrada, 0, 60);
    ?>
    <div class="bloco">
      <h3><i class="bi bi-search"></i> Mercado</h3>
      <div style="font-size:12px;color:var(--txt2);margin-bottom:12px">
        Caixa: <strong><?= h(futDinheiro((float)$estado['caixa'])) ?></strong>.
        Quem está <span style="color:var(--verde-claro);font-weight:700">à venda</span> sai perto do preço de tabela.
        Quem o clube não quer vender custa bem mais caro.
      </div>
      <?php
        $emprestadosAgora = 0;
        foreach ($estado['elenco'] as $j) if (!empty($j['emprestado_de'])) $emprestadosAgora++;
      ?>
      <div style="font-size:12px;color:var(--txt2);margin-bottom:12px">
        <i class="bi bi-box-arrow-in-down" style="color:var(--acento)"></i>
        <strong>Empréstimo</strong> não custa passe — você paga só o salário, e ele volta no fim
        da temporada. Só sai quem não é titular no clube dele, e cabem
        <?= FUT_EMPRESTIMO_MAXIMO ?> no elenco
        (<?= $emprestadosAgora ?> agora).
      </div>

      <form method="get" style="margin-bottom:12px">
        <input type="hidden" name="aba" value="mercado">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
          <div style="flex:2;min-width:150px">
            <label for="q">Buscar</label>
            <input type="text" id="q" name="q" value="<?= h($busca) ?>" placeholder="nome do jogador ou do clube">
          </div>
          <div style="flex:1;min-width:100px">
            <label for="pos">Posição</label>
            <select id="pos" name="pos">
              <option value="">todas</option>
              <?php foreach (array_keys(FUT_POSICOES) as $pp): ?>
                <option value="<?= h($pp) ?>" <?= $fPos === $pp ? 'selected' : '' ?>><?= h($pp) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:1;min-width:120px">
            <label for="ord">Ordenar por</label>
            <select id="ord" name="ord">
              <?php /* Quatro das cinco opções viraram cabeçalho de coluna; só
                        "melhor negócio" fica aqui, porque ela não é uma
                        coluna — é a razão entre duas. */ ?>
              <option value="ovr"     <?= $ordenar === 'ovr' ? 'selected' : '' ?>>melhor OVR</option>
              <option value="preco"   <?= $ordenar === 'preco' ? 'selected' : '' ?>>mais barato</option>
              <option value="idade"   <?= $ordenar === 'idade' ? 'selected' : '' ?>>mais novo</option>
              <option value="vale"    <?= $ordenar === 'vale' ? 'selected' : '' ?>>mais valioso</option>
              <option value="negocio" <?= $ordenar === 'negocio' ? 'selected' : '' ?>>melhor negócio</option>
            </select>
          </div>
        </div>
        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
          <label style="display:flex;align-items:center;gap:6px;margin:0;cursor:pointer">
            <input type="checkbox" name="venda" value="1" <?= $soVenda ? 'checked' : '' ?> style="width:auto">
            só quem está à venda
          </label>
          <label style="display:flex;align-items:center;gap:6px;margin:0;cursor:pointer">
            <input type="checkbox" name="cabe" value="1" <?= $soCabe ? 'checked' : '' ?> style="width:auto">
            só o que cabe no caixa
          </label>
          <label style="display:flex;align-items:center;gap:6px;margin:0;cursor:pointer">
            <input type="checkbox" name="emp" value="1" <?= !empty($_GET['emp']) ? 'checked' : '' ?> style="width:auto">
            só quem dá pra pegar emprestado
          </label>
          <button class="btn peq" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
          <?php if ($busca !== '' || $fPos !== '' || $soVenda || $soCabe): ?>
            <a href="?aba=mercado" class="btn sec peq" style="text-decoration:none">Limpar</a>
          <?php endif; ?>
        </div>
      </form>

      <div style="font-size:11.5px;color:var(--txt3);margin-bottom:8px">
        <?= $total ?> jogador(es) encontrado(s)<?= $total > 60 ? ', mostrando os 60 primeiros' : '' ?>
      </div>

      <?php if (!$filtrada): ?>
        <div class="vazio">Ninguém encontrado com esses filtros.</div>
      <?php else: ?>
        <div class="rolar"><table>
          <thead><tr>
            <th><a class="th-ord <?= $ordenar === 'nome' ? 'on' : '' ?>" href="<?= h($linkOrdem('nome')) ?>">Jogador<?= $setaOrdem('nome') ?></a></th>
            <th>Pos</th>
            <th class="num"><a class="th-ord <?= $ordenar === 'ovr' ? 'on' : '' ?>" href="<?= h($linkOrdem('ovr')) ?>">OVR<?= $setaOrdem('ovr') ?></a></th>
            <th class="num"><a class="th-ord <?= $ordenar === 'idade' ? 'on' : '' ?>" href="<?= h($linkOrdem('idade')) ?>">Idade<?= $setaOrdem('idade') ?></a></th>
            <th>Clube</th>
            <th class="num"><a class="th-ord <?= $ordenar === 'vale' ? 'on' : '' ?>" href="<?= h($linkOrdem('vale')) ?>">Vale<?= $setaOrdem('vale') ?></a></th>
            <th class="num"><a class="th-ord <?= $ordenar === 'preco' ? 'on' : '' ?>" href="<?= h($linkOrdem('preco')) ?>">Pede<?= $setaOrdem('preco') ?></a></th>
            <th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($filtrada as $m):
            $cls = $m['ovr'] >= 80 ? 'b' : ($m['ovr'] >= 70 ? 'm' : '');
            $podePagar = $m['pedido'] <= (float)$estado['caixa']; ?>
            <tr>
              <td>
                <a class="link-jogo" href="?aba=jogador&amp;nome=<?= urlencode($m['nome']) ?>&amp;clube=<?= urlencode($m['clube']) ?>&amp;de=mercado"><?= h($m['nome']) ?></a>
                <?php if (!empty($m['a_venda'])): ?>
                  <span class="selo-venda">à venda</span>
                <?php endif; ?>
              </td>
              <td><span class="tagpos"><?= h($m['pos']) ?></span></td>
              <td class="num"><span class="ovr <?= $cls ?>"><?= (int)$m['ovr'] ?></span></td>
              <td class="num"><?= (int)$m['idade'] ?></td>
              <td style="font-size:12px;color:var(--txt2)"><?= h($m['clube']) ?></td>
              <td class="num" style="color:var(--txt3)"><?= h(futDinheiro($m['valor'])) ?></td>
              <td class="num"><strong><?= h(futDinheiro($m['pedido'])) ?></strong></td>
              <td class="num">
                <form method="post" style="display:inline">
                  <input type="hidden" name="acao" value="comprar">
                  <input type="hidden" name="aba" value="mercado">
                  <input type="hidden" name="clube_vendedor" value="<?= h($m['clube']) ?>">
                  <input type="hidden" name="jogador" value="<?= h($m['nome']) ?>">
                  <input type="hidden" name="oferta" value="<?= $m['pedido'] ?>">
                  <button class="btn peq" type="submit" <?= $podePagar ? '' : 'disabled' ?>>Comprar</button>
                </form>
                <?php if (!empty($m['emprestavel'])): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="acao" value="emprestar">
                    <input type="hidden" name="aba" value="mercado">
                    <input type="hidden" name="clube_dono" value="<?= h($m['clube']) ?>">
                    <input type="hidden" name="jogador" value="<?= h($m['nome']) ?>">
                    <button class="btn sec peq" type="submit" title="Sem custo de passe: você paga só o salário, e ele volta no fim da temporada">
                      <i class="bi bi-box-arrow-in-down"></i> Emprestado</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  <?php // ── ABA: CARREIRA ──────────────────────────────────────────── ?>
  <?php elseif (!$emCampo): ?>
    <div class="bloco">
      <h3><i class="bi bi-person-badge"></i> <?= h($estado['tecnico']['nome']) ?></h3>
      <div class="fichas" style="grid-template-columns:repeat(3,1fr)">
        <div class="ficha"><div class="v"><?= count($estado['historico'] ?? []) ?></div><div class="r">temporadas</div></div>
        <div class="ficha"><div class="v"><?= count($estado['titulos'] ?? []) ?></div><div class="r">títulos</div></div>
        <div class="ficha"><div class="v"><?= (int)$estado['tecnico']['reputacao'] ?></div><div class="r">reputação</div></div>
      </div>
    </div>

    <?php if (!empty($estado['titulos'])): ?>
      <div class="bloco">
        <h3><i class="bi bi-trophy-fill"></i> Títulos</h3>
        <?php foreach ($estado['titulos'] as $t): ?>
          <div style="padding:6px 0;border-bottom:1px solid var(--borda)"><?= h($t) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="bloco">
      <h3><i class="bi bi-clock-history"></i> Histórico</h3>
      <?php if (empty($estado['historico'])): ?>
        <div class="vazio">Sua primeira temporada ainda está em andamento.</div>
      <?php else: ?>
        <div class="rolar"><table>
          <thead><tr><th>Ano</th><th>Clube</th><th>Competição</th><th class="num">Pos</th><th>Meta</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($estado['historico']) as $hst): ?>
            <tr>
              <td><?= (int)$hst['ano'] ?></td>
              <td><?= h($hst['clube']) ?></td>
              <td style="font-size:12px;color:var(--txt2)"><?= h($hst['comp'] ?: '—') ?></td>
              <td class="num"><?= $hst['posicao'] ? $hst['posicao'] . 'º' : '—' ?></td>
              <td><?= $hst['cumpriu']
                    ? '<span style="color:var(--verde-claro)">cumprida</span>'
                    : '<span style="color:#fca5a5">falhou</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

    <div class="bloco">
      <h3><i class="bi bi-arrow-repeat"></i> Recomeçar</h3>
      <p style="color:var(--txt2);font-size:13px;margin:0 0 12px">
        Apaga esta carreira e começa outra do zero. Não dá pra desfazer.
      </p>
      <form method="post" data-confirmar="Apagar esta carreira e começar outra? Não dá pra desfazer." data-confirmar-ok="Apagar tudo" data-confirmar-perigo="1">
        <input type="hidden" name="acao" value="recomecar">
        <button class="btn sec" type="submit">Apagar e recomeçar</button>
      </form>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php // ── O RECADO DO JOGO ───────────────────────────────────────── ?>
<?php if ($erro || $aviso): ?>
  <div class="fundo-popup" id="popMsg">
    <div class="popup">
      <h4>
        <i class="bi bi-<?= $erro ? 'exclamation-triangle-fill' : 'check-circle-fill' ?>"
           style="color:<?= $erro ? 'var(--amarelo)' : 'var(--verde-claro)' ?>"></i>
        <?= $erro ? 'Não deu' : 'Feito' ?>
      </h4>
      <p class="popup-sub" style="margin-bottom:0"><?= h($erro ?: $aviso) ?></p>
      <div class="popup-acoes">
        <button type="button" class="btn" data-fechar>Entendi</button>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php // ── A CONFIRMAÇÃO ──────────────────────────────────────────── ?>
<div class="fundo-popup" id="popConfirmar" hidden>
  <div class="popup">
    <h4><i class="bi bi-question-circle-fill"></i> <span id="pcTitulo">Confirmar</span></h4>
    <p class="popup-sub" id="pcTexto" style="margin-bottom:0"></p>
    <div class="popup-acoes">
      <button type="button" class="btn sec" data-fechar>Cancelar</button>
      <button type="button" class="btn" id="pcOk">Confirmar</button>
    </div>
  </div>
</div>

<script>
/* NUNCA O CONFIRM DO NAVEGADOR. A caixa do Chrome não tem a cara do jogo,
   não dá pra escrever direito nela e some no meio da tela sem contexto. */
(function () {
  function fechavel(pop) {
    pop.addEventListener('click', function (e) {
      if (e.target === pop || e.target.hasAttribute('data-fechar')) pop.hidden = true;
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !pop.hidden) pop.hidden = true;
    });
  }

  var msg = document.getElementById('popMsg');
  if (msg) fechavel(msg);

  var pc = document.getElementById('popConfirmar');
  var pcTexto = document.getElementById('pcTexto');
  var pcOk = document.getElementById('pcOk');
  var pendente = null;
  fechavel(pc);

  document.querySelectorAll('form[data-confirmar]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (f.dataset.confirmado === '1') return;      // já passou pelo popup
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
</div>
<script>
/* ── A BUSCA DO TOPO ──────────────────────────────────────────────────
 *
 * Uma consulta por PAUSA na digitação, não por tecla: quem escreve "flamengo"
 * dispararia oito idas ao servidor, e as sete primeiras seriam jogadas fora.
 * Duzentos e cinquenta milésimos é o intervalo em que a mão para entre uma
 * palavra e a próxima.
 *
 * E a resposta que chega ATRASADA é descartada. Sem isso, digitar rápido pode
 * terminar mostrando o resultado de "fla" depois do de "flamengo" — a lista
 * fica com o que já não se quer, e parece que a busca errou.
 */
(function () {
  var campo = document.getElementById('buscaGeral');
  var lista = document.getElementById('buscaLista');
  if (!campo || !lista) return;

  var timer = null, pedido = 0, itens = [], marcado = -1;

  function esc(t) {
    return String(t).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }

  function fechar() { lista.hidden = true; itens = []; marcado = -1; }

  function desenhar(d) {
    var html = '';
    itens = [];

    function grupo(rotulo, linhas) {
      if (!linhas.length) return;
      html += '<div class="busca-grupo">' + rotulo + '</div>' + linhas.join('');
    }

    grupo('Competições', (d.competicoes || []).map(function (c) {
      itens.push('?aba=competicao&nome=' + encodeURIComponent(c.nome));
      return '<a class="busca-item" href="?aba=competicao&nome=' + encodeURIComponent(c.nome) + '">'
           + '<i class="bi bi-trophy"></i><span class="nm">' + esc(c.nome) + '</span>'
           + '<span class="det">' + esc(c.tipo) + '</span></a>';
    }));

    grupo('Clubes', (d.clubes || []).map(function (c) {
      itens.push('?aba=clube&nome=' + encodeURIComponent(c.nome) + '&de=inicio');
      var cara = c.escudo
        ? '<img src="' + esc(c.escudo) + '" alt="" width="20" height="20" style="object-fit:contain">'
        : '<i class="bi bi-shield"></i>';
      return '<a class="busca-item" href="?aba=clube&nome=' + encodeURIComponent(c.nome) + '&de=inicio">'
           + cara + '<span class="nm">' + esc(c.nome) + '</span>'
           + '<span class="det">' + esc(c.liga || c.onde) + '</span></a>';
    }));

    grupo('Jogadores', (d.jogadores || []).map(function (j) {
      var u = '?aba=jogador&nome=' + encodeURIComponent(j.nome)
            + '&clube=' + encodeURIComponent(j.clube) + '&de=inicio';
      itens.push(u);
      return '<a class="busca-item" href="' + u + '">'
           + '<span class="tagpos">' + esc(j.pos) + '</span>'
           + '<span class="nm">' + esc(j.nome) + '</span>'
           + '<span class="det">' + esc(j.clube) + ' · ' + j.ovr + '</span></a>';
    }));

    if (!html) html = '<div class="busca-vazio">Nada com esse nome.</div>';
    lista.innerHTML = html;
    lista.hidden = false;
    marcado = -1;
  }

  function buscar() {
    var q = campo.value.trim();
    if (q.length < 2) { fechar(); return; }
    var meu = ++pedido;
    fetch(location.pathname + '?json=busca&q=' + encodeURIComponent(q),
          { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (meu === pedido) desenhar(d); })
      .catch(function () { if (meu === pedido) fechar(); });
  }

  campo.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(buscar, 250);
  });
  campo.addEventListener('focus', function () { if (itens.length) lista.hidden = false; });

  /* Setas e Enter: quem busca com o teclado não quer tirar a mão dele pra
     pegar o mouse no fim. */
  campo.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { fechar(); campo.blur(); return; }
    if (!itens.length || lista.hidden) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      marcado += (e.key === 'ArrowDown' ? 1 : -1);
      if (marcado < 0) marcado = itens.length - 1;
      if (marcado >= itens.length) marcado = 0;
      var todos = lista.querySelectorAll('.busca-item');
      todos.forEach(function (a, i) { a.classList.toggle('sel', i === marcado); });
      todos[marcado]?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' && marcado >= 0) {
      e.preventDefault();
      location.href = itens[marcado];
    }
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.busca-caixa')) fechar();
  });

  /* A barra só ganha o fio de baixo quando há conteúdo passando por trás —
     parada no topo, uma linha solta atravessando a tela não separa nada. */
  var topo = document.querySelector('.topo');
  if (topo) {
    var marcarRolagem = function () { topo.classList.toggle('rolou', window.scrollY > 4); };
    marcarRolagem();
    window.addEventListener('scroll', marcarRolagem, { passive: true });
  }
})();
</script>
</body>
</html>
