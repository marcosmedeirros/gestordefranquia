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
            $disponiveis = futClubesParaComecar(10);
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

/* O PAINEL DE SUBSTITUIÇÃO PERGUNTA QUEM ESTÁ EM CAMPO. Responde JSON e sai
   antes do HTML: é o mesmo motivo do avanço do relógio — recarregar a página
   pra abrir o painel pararia a partida. */
if ($estado && ($_GET['json'] ?? '') === 'troca') {
    header('Content-Type: application/json; charset=utf-8');
    $v = $estado['aovivo'] ?? null;
    if (!$v) { echo json_encode(['campo' => [], 'banco' => [], 'restam' => 0]); exit; }

    $entraram = [];
    foreach ($v['trocas'] ?? [] as $t) $entraram[$t['entra']] = true;

    $emCampo = futCarreiraEscalacaoAtual($estado);
    $fora = array_keys($estado['suspensos'] ?? []);
    $banco = futReservas($estado['elenco'], $emCampo, $fora);

    $ficha = fn(array $j, bool $entrou = false) => [
        'nome'    => $j['nome'],
        'ovr'     => (int)$j['ovr'],
        'energia' => (int)($j['energia'] ?? 100),
        'entrou'  => $entrou,
    ];

    echo json_encode([
        'campo'  => array_values(array_map(fn($j) => $ficha($j, isset($entraram[$j['nome']])), $emCampo)),
        'banco'  => array_values(array_map(fn($j) => $ficha($j), $banco)),
        'restam' => FUT_AOVIVO_TROCAS - count($v['trocas'] ?? []),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$meuClube = $estado ? futCarreiraMeuClube($estado) : null;
$clubesTodos = futClubesDoBrasil();

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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0a0a0c; --panel:#131316; --panel2:#1a1a1f; --panel3:#212127;
  --borda:#26262d; --borda2:#33333c;
  --txt:#f4f4f5; --txt2:#a1a1aa; --txt3:#71717a;
  --verde:#16a34a; --verde-claro:#22c55e; --vermelho:#ef4444;
  --amarelo:#f59e0b; --azul:#3b82f6;
}
*{box-sizing:border-box}
/* O atributo hidden precisa vencer os display:flex e inline-flex deste
   arquivo. Sem isto, esconder as abas ou um botao nao escondia nada. */
[hidden]{display:none !important}
body{margin:0;background:var(--bg);color:var(--txt);font-family:'Inter',system-ui,-apple-system,sans-serif;
  font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
#app{max-width:1100px;margin:0 auto;padding:16px 14px 80px}
h1,h2,h3{margin:0;letter-spacing:-.4px}
button,input,select{font-family:inherit}
a{color:inherit}

.mono{display:inline-flex;align-items:center;justify-content:center;border-radius:7px;
  background:var(--panel3);border:1px solid var(--borda);font-weight:800;color:var(--txt2);flex-shrink:0}

/* ── Topo ───────────────────────────────────────────── */
.topo{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.marca{display:flex;align-items:center;gap:9px;font-weight:900;font-size:17px;letter-spacing:-.6px}
.marca i{color:var(--verde-claro)}
.voltar{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;
  border:1px solid var(--borda);background:transparent;color:var(--txt2);text-decoration:none;flex-shrink:0}
.voltar:hover{border-color:var(--verde);color:var(--verde-claro)}

/* ── Cartão do clube ────────────────────────────────── */
.clube-card{background:linear-gradient(135deg,var(--panel2),var(--panel));border:1px solid var(--borda);
  border-radius:14px;padding:14px;margin-bottom:14px}
.clube-topo{display:flex;align-items:center;gap:12px}
.clube-nome{font-size:19px;font-weight:900;letter-spacing:-.5px;line-height:1.15}
.clube-sub{font-size:12px;color:var(--txt2)}
.fichas{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:12px}
.ficha{background:var(--panel3);border:1px solid var(--borda);border-radius:10px;padding:8px 9px;text-align:center}
.ficha .v{font-size:16px;font-weight:900;letter-spacing:-.5px;line-height:1.1}
.ficha .r{font-size:10px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.meta-linha{margin-top:10px;padding:9px 11px;border-radius:9px;background:rgba(245,158,11,.10);
  border:1px solid rgba(245,158,11,.28);font-size:12.5px;display:flex;gap:8px;align-items:flex-start}
.meta-linha i{color:var(--amarelo);margin-top:1px}

/* ── Abas ───────────────────────────────────────────── */
.abas{display:flex;gap:6px;margin-bottom:14px;overflow-x:auto;padding-bottom:2px;-webkit-overflow-scrolling:touch}
.abas a{flex:0 0 auto;padding:8px 14px;border-radius:9px;border:1px solid var(--borda);background:var(--panel);
  color:var(--txt2);text-decoration:none;font-size:13px;font-weight:700;white-space:nowrap}
.abas a.on{background:var(--verde);border-color:var(--verde);color:#fff}

/* ── Blocos ─────────────────────────────────────────── */
.bloco{background:var(--panel);border:1px solid var(--borda);border-radius:13px;padding:14px;margin-bottom:12px}
.bloco h3{font-size:14px;font-weight:800;margin-bottom:10px;display:flex;align-items:center;gap:7px}
.bloco h3 i{color:var(--verde-claro)}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 16px;border-radius:10px;
  border:1px solid var(--verde);background:var(--verde);color:#fff;font-weight:800;font-size:14px;cursor:pointer}
.btn:hover{background:var(--verde-claro)}
.btn.sec{background:transparent;color:var(--txt2);border-color:var(--borda2)}
.btn.sec:hover{color:var(--txt);border-color:var(--txt3)}
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
.voltar-linha a:hover{color:var(--verde-claro)}
.elo{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;
  background:var(--panel3);border:1px solid var(--borda);font-size:11px;font-weight:700;color:var(--txt2)}
a.link-jogo{color:inherit;text-decoration:none;border-bottom:1px dotted var(--borda2)}
a.link-jogo:hover{color:var(--verde-claro);border-bottom-color:var(--verde)}
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
.relogio .bolinha{width:7px;height:7px;border-radius:50%;background:var(--verde-claro)}
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
.viv-barra span{display:block;height:100%;background:var(--verde);width:0;transition:width .45s linear}

.viv-acoes{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}

.viv-narracao{display:flex;flex-direction:column;gap:7px;max-height:290px;overflow-y:auto}
.viv-lance{display:flex;align-items:flex-start;gap:10px;padding:9px 11px;border-radius:10px;
  background:var(--panel3);border:1px solid var(--borda);animation:entra .35s ease}
.viv-lance.nosso{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.07)}
.viv-lance-min{font-size:11px;font-weight:800;color:var(--txt3);min-width:26px;font-variant-numeric:tabular-nums;
  padding-top:1px}
.viv-lance-txt{font-size:12.5px;min-width:0}
.viv-lance-txt b{font-weight:800}
.viv-lance-txt i{color:var(--txt3);font-style:normal;font-size:11.5px}
@keyframes entra{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:none}}
.viv-narracao-vazia{color:var(--txt3);font-size:12.5px;padding:8px 2px}

.notas-vivo{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px}
.nota-linha{display:flex;align-items:center;gap:8px;padding:6px 9px;border-radius:9px;background:var(--panel3);
  border:1px solid var(--borda);font-size:12px}
.nota-linha .n{margin-left:auto;font-weight:900;font-variant-numeric:tabular-nums;font-size:12.5px}
.nota-linha .n.boa{color:var(--verde-claro)}
.nota-linha .n.ruim{color:#fca5a5}
.nota-linha .p{font-size:10px;color:var(--txt3);font-weight:700}

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

/* ── Os números da partida ──────────────────────────── */
.viv-num-linha{display:grid;grid-template-columns:42px 1fr 42px;align-items:center;gap:9px;
  font-size:11.5px;margin-bottom:7px}
.viv-num-linha .n{font-weight:800;font-variant-numeric:tabular-nums;text-align:center}
.viv-num-barra{height:5px;border-radius:999px;background:var(--panel3);overflow:hidden;display:flex}
.viv-num-barra i{display:block;height:100%}
.viv-num-barra i.eu{background:var(--verde)}
.viv-num-barra i.ele{background:var(--borda2)}
.viv-num-rot{font-size:9.5px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;
  text-align:center;margin-top:2px}

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
.popup h4 i{color:var(--verde-claro)}
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

/* ── Começar a carreira ─────────────────────────────── */
.hero{background:linear-gradient(135deg,var(--panel2),var(--panel));border:1px solid var(--borda);
  border-radius:14px;padding:18px 16px;margin-bottom:12px}
.hero h2{font-size:22px;font-weight:900;letter-spacing:-.7px;margin-bottom:6px}
.hero p{margin:0;color:var(--txt2);font-size:13.5px;max-width:62ch}
.passos{display:flex;gap:14px;margin-top:14px;flex-wrap:wrap}
.passo{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--txt2)}
.passo i{color:var(--verde-claro);font-size:14px}

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
.clube-op-forca{font-size:15px;font-weight:900;letter-spacing:-.5px;font-variant-numeric:tabular-nums;
  color:var(--txt2);flex-shrink:0}
.clube-op input:checked + .clube-op-in .clube-op-forca{color:var(--verde-claro)}
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
th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--txt3);
  padding:6px 7px;border-bottom:1px solid var(--borda);font-weight:700;white-space:nowrap}
td{padding:7px;border-bottom:1px solid var(--borda);white-space:nowrap}
tr:last-child td{border-bottom:0}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
tr.eu td{background:rgba(34,197,94,.10);font-weight:700}
.pos{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:6px;
  background:var(--panel3);font-size:11px;font-weight:800;color:var(--txt2)}
.pos.sobe{background:rgba(34,197,94,.20);color:var(--verde-claro)}
.pos.cai{background:rgba(239,68,68,.18);color:#fca5a5}

.ovr{display:inline-flex;align-items:center;justify-content:center;min-width:28px;padding:2px 6px;border-radius:6px;
  font-weight:800;font-size:12px;background:var(--panel3);border:1px solid var(--borda)}
.ovr.b{background:rgba(34,197,94,.18);border-color:rgba(34,197,94,.35);color:var(--verde-claro)}
.ovr.m{background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.3);color:var(--amarelo)}
.tagpos{font-size:10px;font-weight:800;color:var(--txt3);letter-spacing:.4px}

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
.camisa .bola{width:30px;height:30px;border-radius:50%;background:var(--panel);border:2px solid rgba(255,255,255,.55);
  display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;color:#fff;
  box-shadow:0 2px 6px rgba(0,0,0,.35)}
.camisa .bola.improv{border-color:#fbbf24;background:#78350f}
.camisa .bola.vazio{border-style:dashed;opacity:.55}
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
.dica-drag i{color:var(--verde-claro)}
.slot{cursor:grab;user-select:none;-webkit-user-select:none;touch-action:manipulation}
.slot:active{cursor:grabbing}
.slot:focus-visible{outline:2px solid var(--verde-claro);outline-offset:2px}
.camisa.sel .bola{border-color:#fff;box-shadow:0 0 0 3px rgba(255,255,255,.35),0 2px 8px rgba(0,0,0,.5);
  transform:scale(1.12)}
.camisa.alvo .bola{border-color:var(--verde-claro);box-shadow:0 0 0 4px rgba(34,197,94,.4)}
.camisa .bola{transition:transform .12s,box-shadow .12s,border-color .12s}

.banco{margin-bottom:12px}
.banco-titulo{font-size:12px;font-weight:800;margin-bottom:7px;display:flex;align-items:center;gap:6px}
.banco-titulo i{color:var(--verde-claro)}
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

/* ── Celular ────────────────────────────────────────── */
@media (max-width:560px){
  #app{padding:12px 12px 80px}
  .fichas{grid-template-columns:repeat(2,1fr)}
  .clube-nome{font-size:17px}
  .ficha .v{font-size:15px}
  table{min-width:420px}
}
</style>
</head>
<body>
<div id="app">

  <div class="topo">
    <a href="../games.php" class="voltar" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <div class="marca"><i class="bi bi-trophy-fill"></i> Carreira</div>
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
  ?>
  <div class="hero">
    <h2>Comece de baixo</h2>
    <p>Você ainda não tem currículo, então os grandes não te atendem. Cumpra a meta que a
       diretoria cobra, ganhe reputação, e os convites melhores aparecem sozinhos.</p>
    <div class="passos">
      <span class="passo"><i class="bi bi-1-circle-fill"></i> Escolha um clube</span>
      <span class="passo"><i class="bi bi-2-circle-fill"></i> Cumpra a meta da temporada</span>
      <span class="passo"><i class="bi bi-3-circle-fill"></i> Suba de divisão</span>
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
              <label class="clube-op" data-busca="<?= h(mb_strtolower($nome . ' ' . ($c['uf'] ?? ''))) ?>">
                <input type="radio" name="clube" value="<?= h($nome) ?>"
                       data-nome="<?= h($nome) ?>" data-meta="<?= h($meta['texto']) ?>">
                <span class="clube-op-in">
                  <span class="clube-op-cab">
                    <?= escudo($c, 28) ?>
                    <span class="clube-op-txt">
                      <span class="clube-op-nome"><?= h($nome) ?></span>
                      <span class="clube-op-sub"><?= h($c['uf'] ?? '') ?> · força</span>
                    </span>
                    <span class="clube-op-forca"><?= (int)$c['forca'] ?></span>
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

    form.addEventListener('change', function (e) {
      var r = e.target;
      if (!r || r.name !== 'clube') return;
      bt.disabled = false;
      aviso.innerHTML = 'Você vai assumir o <b>' + r.dataset.nome + '</b> — ' + r.dataset.meta + '.';
    });

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

      <div class="viv-acoes">
        <button class="btn" id="btJogar"><i class="bi bi-play-fill"></i> Começar</button>
        <button class="btn sec" id="btPausar" hidden><i class="bi bi-pause-fill"></i> Pausar</button>
        <button class="btn sec" id="btEstrategia"><i class="bi bi-sliders"></i> Estratégia</button>
        <button class="btn sec" id="btTrocar"><i class="bi bi-arrow-left-right"></i> Substituir
          <span id="btTrocarConta">(<?= FUT_AOVIVO_TROCAS - count($vivo['trocas'] ?? []) ?>)</span></button>
        <form method="post" id="fmFechar" hidden>
          <input type="hidden" name="acao" value="aovivo_fechar">
          <button class="btn" type="submit"><i class="bi bi-flag-fill"></i> Encerrar e ver o resumo</button>
        </form>
      </div>
    </div>

    <div class="bloco">
      <h3><i class="bi bi-broadcast"></i> Narração</h3>
      <div class="viv-narracao" id="viv-narracao">
        <?php if (empty($vivo['eventos'])): ?>
          <div class="viv-narracao-vazia" id="narracaoVazia">Os times entram em viv. Aperte começar.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="bloco">
      <h3><i class="bi bi-bar-chart-fill"></i> Números da partida</h3>
      <div id="numerosVivo"></div>
    </div>

    <div class="bloco">
      <h3><i class="bi bi-star-fill"></i> Notas ao vivo</h3>
      <div class="notas-vivo" id="notasVivo"></div>
    </div>

    <?php // ── O popup de substituição ───────────────────────────── ?>
    <div class="fundo-popup" id="popTroca" hidden>
      <div class="popup">
        <h4><i class="bi bi-arrow-left-right"></i> Substituição</h4>
        <p class="popup-sub">Escolha quem sai e quem entra. Vale do minuto seguinte —
           e quem sai não volta mais nesta partida.</p>
        <div class="troca-cols">
          <div>
            <h5>Sai</h5>
            <div class="troca-lista" id="listaSai"></div>
          </div>
          <div>
            <h5>Entra</h5>
            <div class="troca-lista" id="listaEntra"></div>
          </div>
        </div>
        <div class="troca-restam" id="trocaRestam"></div>
        <div class="popup-acoes">
          <button type="button" class="btn sec" data-fechar>Cancelar</button>
          <button type="button" class="btn" id="btConfirmarTroca" disabled>
            <i class="bi bi-check-lg"></i> Fazer a troca</button>
        </div>
      </div>
    </div>

    <?php // ── O popup de estratégia ─────────────────────────────── ?>
    <div class="fundo-popup" id="popEstrategia" hidden>
      <div class="popup">
        <h4><i class="bi bi-sliders"></i> Como o time vai jogar</h4>
        <p class="popup-sub">Vale do minuto seguinte em diante — o que já passou não muda.</p>
        <form id="fmEstrategia">
          <div class="opcoes">
            <div class="opcoes-rot">Postura</div>
            <?php foreach (FUT_POSTURAS as $k => $o): ?>
              <label class="opcao">
                <input type="radio" name="postura" value="<?= h($k) ?>"
                       <?= ($estr['postura'] ?? 'neutro') === $k ? 'checked' : '' ?>>
                <span><b><?= h($o['nome']) ?></b><i><?= h($o['desc']) ?></i></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="opcoes">
            <div class="opcoes-rot">Marcação</div>
            <?php foreach (FUT_MARCACOES as $k => $o): ?>
              <label class="opcao">
                <input type="radio" name="marcacao" value="<?= h($k) ?>"
                       <?= ($estr['marcacao'] ?? 'normal') === $k ? 'checked' : '' ?>>
                <span><b><?= h($o['nome']) ?></b><i><?= h($o['desc']) ?></i></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="popup-acoes">
            <button type="button" class="btn sec" data-fechar>Cancelar</button>
            <button type="submit" class="btn"><i class="bi bi-check-lg"></i> Confirmar</button>
          </div>
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
      var barra = document.getElementById('barraTempo'), narr = document.getElementById('viv-narracao');
      var vazia = document.getElementById('narracaoVazia'), notas = document.getElementById('notasVivo');
      var btJogar = document.getElementById('btJogar'), btPausar = document.getElementById('btPausar');
      var fmFechar = document.getElementById('fmFechar');
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
      var LANCES = {
        fora:     {ico: 'bi-arrow-up-right',   txt: function (e) { return e.jogador + ' finalizou por cima'; }},
        defendeu: {ico: 'bi-hand-index-thumb', txt: function (e) { return e.jogador + ' chutou, o goleiro defendeu'; }},
        trave:    {ico: 'bi-exclamation-lg',   txt: function (e) { return '<b>Na trave!</b> ' + e.jogador + ' quase'; }},
        bloqueou: {ico: 'bi-shield',           txt: function (e) { return 'A defesa bloqueou o chute de ' + e.jogador; }},
        falta:    {ico: 'bi-flag',             txt: function (e) { return 'Falta perigosa, ' + e.jogador + ' na bola'; }},
        troca:    {ico: 'bi-arrow-left-right', txt: function (e) { return '<b>Substituição:</b> sai ' + e.sai + ', entra ' + e.jogador; }}
      };

      function textoDoLance(e) {
        if (e.tipo === 'gol') {
          return '<b>GOL' + (e.meu ? '' : ' DELES') + '!</b> ' + e.jogador +
                 (e.assistente ? ' <i>assist. ' + e.assistente + '</i>' : '');
        }
        if (e.tipo === 'vermelho') {
          return '<b>Vermelho</b> para ' + e.jogador + (e.segundo ? ' <i>(segundo amarelo)</i>' : '');
        }
        if (e.tipo === 'amarelo') return 'Amarelo para ' + e.jogador;

        var l = LANCES[e.tipo];
        if (!l) return e.jogador || '';
        return '<i class="bi ' + l.ico + '"></i> ' + l.txt(e) +
               (e.meu ? '' : ' <i>(eles)</i>');
      }

      function mostraLance(e) {
        if (vazia) { vazia.remove(); vazia = null; }
        var d = document.createElement('div');
        d.className = 'viv-lance' + (e.meu ? ' nosso' : '');
        d.innerHTML = '<span class="viv-lance-min">' + e.minuto + "'</span>" +
                      '<span class="viv-lance-txt">' + textoDoLance(e) + '</span>';
        narr.prepend(d);
      }

      function mostraNotas(mapa) {
        if (!mapa) return;
        var nomes = Object.keys(mapa);
        if (!nomes.length) return;
        notas.innerHTML = nomes.map(function (n) {
          var v = Number(mapa[n]).toFixed(1).replace('.', ',');
          var cls = mapa[n] >= 7 ? 'boa' : (mapa[n] < 5.5 ? 'ruim' : '');
          return '<div class="nota-linha"><span>' + n + '</span>' +
                 '<span class="n ' + cls + '">' + v + '</span></div>';
        }).join('');
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

      function acabou() {
        rolando = false;
        clearInterval(timer);
        btJogar.hidden = true; btPausar.hidden = true;
        fmFechar.hidden = false;
        rel.className = 'relogio parado';
        rlMin.textContent = '90';
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
      var notasFinais = null, numerosFinais = null;
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
        btJogar.innerHTML = '<i class="bi bi-play-fill"></i> Começar o segundo tempo';
        abreTroca(true);
      }

      function toca() {
        rolando = true;
        btJogar.hidden = true; btPausar.hidden = false;
        pintaRelogio();
        buscaMais();
        clearInterval(timer);
        timer = setInterval(tique, TIQUE);
      }
      function pausa() {
        rolando = false;
        clearInterval(timer);
        btJogar.hidden = false; btPausar.hidden = true;
        btJogar.innerHTML = '<i class="bi bi-play-fill"></i> Continuar';
        pintaRelogio();
      }

      btJogar.addEventListener('click', toca);
      btPausar.addEventListener('click', pausa);

      // ── O popup de estratégia ──────────────────────────────────
      var pop = document.getElementById('popEstrategia');
      var voltaARolar = false;
      document.getElementById('btEstrategia').addEventListener('click', function () {
        voltaARolar = rolando;
        if (rolando) pausa();
        pop.hidden = false;
      });
      pop.addEventListener('click', function (e) {
        if (e.target === pop || e.target.hasAttribute('data-fechar')) {
          pop.hidden = true;
          if (voltaARolar) toca();
        }
      });
      document.getElementById('fmEstrategia').addEventListener('submit', function (e) {
        e.preventDefault();
        var f = new FormData(e.target);
        f.append('acao', 'aovivo_estrategia');
        fetch(location.pathname, {method: 'POST', body: new URLSearchParams(f)})
          .then(function (r) { return r.json(); })
          .then(function () {
            pop.hidden = true;
            if (voltaARolar) toca();
          });
      });

      // ── A substituição ─────────────────────────────────────────
      var popTroca = document.getElementById('popTroca');
      var listaSai = document.getElementById('listaSai');
      var listaEntra = document.getElementById('listaEntra');
      var btConfirmar = document.getElementById('btConfirmarTroca');
      var btTrocarConta = document.getElementById('btTrocarConta');
      var trocaRestam = document.getElementById('trocaRestam');
      var escolhaSai = null, escolhaEntra = null, rolavaAntes = false;

      function pintaOpcoes(dados) {
        listaSai.innerHTML = dados.campo.map(function (j) {
          return '<button type="button" class="troca-op" data-nome="' + j.nome + '"' +
                 (j.entrou ? ' disabled title="Acabou de entrar"' : '') + '>' +
                 '<span class="o">' + j.ovr + '</span><span class="nm">' + j.nome + '</span>' +
                 '<span class="en">' + j.energia + '</span></button>';
        }).join('') || '<div style="color:var(--txt3);font-size:12px">Ninguém em campo.</div>';

        listaEntra.innerHTML = dados.banco.map(function (j) {
          return '<button type="button" class="troca-op" data-nome="' + j.nome + '">' +
                 '<span class="o">' + j.ovr + '</span><span class="nm">' + j.nome + '</span>' +
                 '<span class="en">' + j.energia + '</span></button>';
        }).join('') || '<div style="color:var(--txt3);font-size:12px">Banco vazio.</div>';

        trocaRestam.textContent = dados.restam + ' substituição(ões) restante(s)';
        escolhaSai = escolhaEntra = null;
        btConfirmar.disabled = true;
      }

      function ligaEscolha(lista, qual) {
        lista.addEventListener('click', function (e) {
          var b = e.target.closest('.troca-op');
          if (!b || b.disabled) return;
          lista.querySelectorAll('.troca-op').forEach(function (x) { x.classList.remove('sel'); });
          b.classList.add('sel');
          if (qual === 'sai') escolhaSai = b.dataset.nome; else escolhaEntra = b.dataset.nome;
          btConfirmar.disabled = !(escolhaSai && escolhaEntra);
        });
      }
      ligaEscolha(listaSai, 'sai');
      ligaEscolha(listaEntra, 'entra');

      function abreTroca(doIntervalo) {
        rolavaAntes = doIntervalo ? false : rolando;
        if (rolando) pausa();
        fetch(location.pathname + '?aba=partida&json=troca', {headers: {'X-Requested-With': 'fetch'}})
          .then(function (r) { return r.json(); })
          .then(function (d) {
            pintaOpcoes(d);
            var tit = popTroca.querySelector('h4');
            if (tit) tit.innerHTML = doIntervalo
              ? '<i class="bi bi-cup-hot"></i> Intervalo'
              : '<i class="bi bi-arrow-left-right"></i> Substituição';
            popTroca.hidden = false;
          });
      }
      document.getElementById('btTrocar').addEventListener('click', function () { abreTroca(false); });

      popTroca.addEventListener('click', function (e) {
        if (e.target === popTroca || e.target.hasAttribute('data-fechar')) {
          popTroca.hidden = true;
          if (rolavaAntes) toca();
        }
      });

      btConfirmar.addEventListener('click', function () {
        if (!escolhaSai || !escolhaEntra) return;
        btConfirmar.disabled = true;
        fetch(location.pathname, {method: 'POST', body: new URLSearchParams(
          {acao: 'aovivo_substituir', sai: escolhaSai, entra: escolhaEntra})})
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.ok) { trocaRestam.textContent = d.erro; btConfirmar.disabled = false; return; }
            btTrocarConta.textContent = '(' + d.restam + ')';
            mostraLance({minuto: minuto, tipo: 'troca', meu: true,
                         jogador: escolhaEntra, sai: escolhaSai});
            popTroca.hidden = true;
            if (rolavaAntes) toca();
          });
      });

      pintaRelogio();
      mostraNumeros(<?= json_encode($vivo['numeros'] ?? null) ?>);
      if (minuto >= 90) acabou();
      <?php if (!empty($vivo['eventos'])): ?>
        <?= 'var jaVistos = ' . json_encode(array_values($vivo['eventos']), JSON_UNESCAPED_UNICODE) . ';' ?>
        jaVistos.forEach(mostraLance);
      <?php endif; ?>
    })();
    </script>
  <?php endif; ?>

  <div class="clube-card" <?= $emCampo ? 'hidden' : '' ?>>
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
    <?php foreach (['inicio' => 'Início', 'jogo' => 'Partidas', 'escalacao' => 'Escalação', 'elenco' => 'Elenco',
                    'stats' => 'Números', 'tabela' => 'Tabela', 'mercado' => 'Mercado',
                    'carreira' => 'Carreira'] as $k => $rot): ?>
      <a href="?aba=<?= $k ?>" class="<?= $aba === $k ? 'on' : '' ?>"><?= h($rot) ?></a>
    <?php endforeach; ?>
  </div>

  <?php // ── ABA: INÍCIO ────────────────────────────────────────────── ?>
  <?php if ($aba === 'inicio'): ?>
    <?php
      /* O ESTADO DO CLUBE NUMA TELA. Antes o técnico precisava passar por
         quatro abas pra saber onde estava: a posição na Tabela, o elenco no
         Elenco, o artilheiro nos Números e o próximo jogo em Partidas. */
      $comps = futCarreiraCompeticoesDoAno($estado);
      $divEu = $clubesTodos[$estado['clube']]['div'] ?? '';
      $compNac = futCarreiraNomeDaDivisao($divEu);
      $tabNac = $compNac !== '' ? futCarreiraTabelaDaCompeticao($estado, $compNac) : [];

      // Os cinco melhores do elenco, que é o que o técnico olha primeiro.
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
            <button class="btn"><i class="bi bi-play-fill"></i> Entrar em campo</button>
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
              $pos = 0; $i = 0;
              foreach (array_keys($tabNac) as $n) { $i++; if ($n === $estado['clube']) $pos = $i; }
              $ini = max(0, $pos - 3); $trecho = array_slice($tabNac, $ini, 5, true);
              $k = $ini;
            ?>
            <?php foreach ($trecho as $nome => $l): $k++; ?>
              <div class="mini-linha" style="<?= $nome === $estado['clube'] ? 'color:var(--verde-claro);font-weight:700' : '' ?>">
                <span class="pos"><?= $k ?></span>
                <span class="esq"><?= h($nome) ?></span>
                <span class="dir"><?= (int)$l['p'] ?> pts</span>
              </div>
            <?php endforeach; ?>
          </div>
          <div style="margin-top:9px"><a class="btn sec peq" href="?aba=tabela" style="text-decoration:none">Ver a tabela</a></div>
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
        <h3><i class="bi bi-star-fill"></i> Os melhores do elenco</h3>
        <div class="mini-lista">
          <?php foreach ($melhores as $j): ?>
            <div class="mini-linha">
              <span class="tagpos"><?= h($j['pos']) ?></span>
              <span class="esq"><a class="link-jogo" href="?aba=jogador&amp;nome=<?= urlencode($j['nome']) ?>&amp;de=inicio"><?= h($j['nome']) ?></a></span>
              <span class="dir"><?= (int)$j['ovr'] ?></span>
            </div>
          <?php endforeach; ?>
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

      <?php if ($fora): ?>
        <div class="bloco">
          <h3><i class="bi bi-bandaid"></i> Fora da próxima</h3>
          <div class="mini-lista">
            <?php foreach ($fora as $nome => $d): ?>
              <div class="mini-linha">
                <span class="esq"><?= h($nome) ?></span>
                <span class="dir" style="color:#fca5a5"><?= h($d['motivo']) ?> · <?= (int)$d['jogos'] ?>j</span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($ultimos): ?>
        <div class="bloco">
          <h3><i class="bi bi-clock-history"></i> Últimos resultados</h3>
          <div class="mini-lista">
            <?php foreach ($ultimos as $r): ?>
              <?php $cls = $r['meus'] > $r['deles'] ? 'v' : ($r['meus'] < $r['deles'] ? 'd' : ''); ?>
              <div class="mini-linha">
                <span class="esq"><?= h($r['adversario']) ?></span>
                <span class="dir"><span class="placar <?= $cls ?>"><?= (int)$r['meus'] ?>–<?= (int)$r['deles'] ?></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

  <?php // ── ABA: PARTIDAS ──────────────────────────────────────────── ?>
  <?php elseif ($aba === 'jogo'): ?>
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
                  <?= h(futCarreiraRotuloDaDivisao((string)$pr['div'])) ?> · força <?= (int)$pr['forca'] ?>
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
                  <?= h(futCarreiraRotuloDaDivisao((string)$c['div'])) ?> · força <?= (int)$c['forca'] ?>
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
      <?php if ($proximo): ?>
        <div class="bloco">
          <h3><i class="bi bi-calendar-event"></i> Próxima partida</h3>
          <div style="display:flex;align-items:center;gap:11px;margin-bottom:12px">
            <?= escudo($clubesTodos[$proximo['adversario']] ?? ['nome' => $proximo['adversario']], 34) ?>
            <div style="min-width:0">
              <div style="font-weight:800;font-size:15px"><?= h($proximo['adversario']) ?></div>
              <div style="font-size:11.5px;color:var(--txt2)">
                <?= h($proximo['comp']) ?> · <?= h($proximo['fase'] ?? '') ?> ·
                <?= $proximo['casa'] ? 'em casa' : 'fora' ?>
              </div>
            </div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <form method="post" style="display:inline">
              <input type="hidden" name="acao" value="jogar">
              <button class="btn"><i class="bi bi-play-fill"></i> Entrar em campo</button>
            </form>
            <a class="btn sec" href="?aba=escalacao"><i class="bi bi-diagram-3"></i> Escalação</a>
          </div>
          <div style="margin-top:10px;font-size:12px;color:var(--txt3)">
            Jogo <?= (int)$estado['rodada'] + 1 ?> de <?= count($estado['calendario'] ?? []) ?>
          </div>
        </div>
      <?php endif; ?>

      <?php $ultimo = null; foreach (array_reverse($estado['resultados'] ?? []) as $rr) { if (!empty($rr['eventos'])) { $ultimo = $rr; break; } } ?>
      <?php if ($ultimo): ?>
        <div class="bloco">
          <h3><i class="bi bi-file-text"></i> Resumo da última partida</h3>
          <div class="resumo-topo">
            <div class="resumo-time">
              <?= escudo($clubesTodos[$estado['clube']] ?? ['nome' => $estado['clube']], 30) ?>
              <span><?= h($estado['clube']) ?></span>
            </div>
            <div class="resumo-placar"><?= (int)$ultimo['meus'] ?> <span>–</span> <?= (int)$ultimo['deles'] ?></div>
            <div class="resumo-time dir">
              <span><?= h($ultimo['adversario']) ?></span>
              <?= escudo($clubesTodos[$ultimo['adversario']] ?? ['nome' => $ultimo['adversario']], 30) ?>
            </div>
          </div>
          <div style="text-align:center;font-size:11.5px;color:var(--txt3);margin-bottom:12px">
            <?= h($ultimo['comp']) ?> · <?= h($ultimo['fase'] ?? '') ?> · <?= $ultimo['casa'] ? 'em casa' : 'fora' ?>
          </div>

          <?php if (empty($ultimo['eventos'])): ?>
            <div class="vazio">Jogo sem lances marcantes.</div>
          <?php endif; ?>
          <?php foreach ($ultimo['eventos'] as $ev): ?>
            <div class="lance <?= $ev['meu'] ? '' : 'deles' ?>">
              <span class="min"><?= (int)$ev['minuto'] ?>'</span>
              <?php if ($ev['tipo'] === 'gol'): ?>
                <i class="bi bi-dribbble" style="color:var(--verde-claro)"></i>
                <span class="quem"><?= h($ev['jogador']) ?></span>
                <?php if (!empty($ev['assistente'])): ?>
                  <span class="det">assist. <?= h($ev['assistente']) ?></span>
                <?php endif; ?>
              <?php elseif ($ev['tipo'] === 'amarelo'): ?>
                <span class="cartao ama"></span>
                <span class="quem"><?= h($ev['jogador']) ?></span>
              <?php else: ?>
                <span class="cartao ver"></span>
                <span class="quem"><?= h($ev['jogador']) ?></span>
                <?php if (!empty($ev['segundo'])): ?><span class="det">segundo amarelo</span><?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <?php if (!empty($ultimo['escalacao'])): ?>
            <details style="margin-top:12px">
              <summary style="cursor:pointer;font-size:12.5px;color:var(--txt2);font-weight:700">Notas dos jogadores</summary>
              <div class="rolar" style="margin-top:8px"><table>
                <thead><tr><th>Jogador</th><th>Pos</th><th class="num">OVR</th><th class="num">Nota</th></tr></thead>
                <tbody>
                <?php $esc = $ultimo['escalacao']; usort($esc, fn($a,$b) => $b['nota'] <=> $a['nota']); ?>
                <?php foreach ($esc as $j): ?>
                  <tr>
                    <td><?= h($j['nome']) ?></td>
                    <td><span class="tagpos"><?= h($j['pos']) ?></span></td>
                    <td class="num"><?= (int)$j['ovr'] ?></td>
                    <td class="num"><span class="nota <?= $j['nota'] >= 7.5 ? 'alta' : ($j['nota'] < 5.5 ? 'baixa' : '') ?>"><?= number_format((float)$j['nota'], 1, ',', '.') ?></span></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table></div>
            </details>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="bloco">
        <h3><i class="bi bi-list-ul"></i> Últimos resultados</h3>
        <?php $ult = array_reverse(array_slice($estado['resultados'] ?? [], -12)); ?>
        <?php if (!$ult): ?><div class="vazio">Nenhum jogo ainda.</div><?php endif; ?>
        <?php foreach ($ult as $r): ?>
          <?php $cls = $r['meus'] > $r['deles'] ? 'v' : ($r['meus'] < $r['deles'] ? 'd' : ''); ?>
          <div class="partida">
            <?= escudo($clubesTodos[$r['adversario']] ?? ['nome' => $r['adversario']], 24) ?>
            <div style="min-width:0">
              <div class="comp"><?= h($r['comp']) ?> · <?= $r['casa'] ? 'casa' : 'fora' ?></div>
              <div class="adv"><?= h($r['adversario']) ?></div>
            </div>
            <div class="placar <?= $cls ?>"><?= (int)$r['meus'] ?>–<?= (int)$r['deles'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
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
                   aria-label="<?= h($j['nome']) ?>, <?= h($j['pos']) ?>, força <?= (int)$j['ovr'] ?>">
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

      function limparSelecao() {
        document.querySelectorAll('.slot.sel').forEach(s => s.classList.remove('sel'));
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
                Campeão d<?= stripos($compSel, 'copa') === 0 ? 'a' : 'o' ?> <?= h($compSel) ?></div>
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
              /* DA SÉRIE D NINGUÉM CAI — ela é o fundo da escada. E só o
                 nacional tem acesso e queda: num estadual ou numa copa
                 regional, pintar as pontas seria inventar regra. */
              $ehNacional = $compSel === futCarreiraNomeDaDivisao($divAtual) && $divAtual !== '';
              $temQueda = $ehNacional && $divAtual !== 'BR4';
              $temAcesso = $ehNacional && $divAtual !== 'BR1';
              $i = 0; $total = count($tab); foreach ($tab as $nome => $l): $i++;
              $clsPos = ($temAcesso && $i <= 4) ? 'sobe' : (($temQueda && $i > $total - 4) ? 'cai' : ''); ?>
              <tr class="<?= $nome === $estado['clube'] ? 'eu' : '' ?>">
                <td><span class="pos <?= $clsPos ?>"><?= $i ?></span></td>
                <td><a class="link-jogo" href="?aba=clube&amp;nome=<?= urlencode($nome) ?>&amp;de=tabela"><?= h($nome) ?></a></td>
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
          <div class="ficha"><div class="v"><?= (int)$forcaDele ?></div><div class="r">força</div></div>
          <div class="ficha"><div class="v"><?= count($elencoDele) ?></div><div class="r">jogadores</div></div>
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

      usort($filtrada, function ($a, $b) use ($ordenar) {
        if ($ordenar === 'preco')  return $a['pedido'] <=> $b['pedido'];
        if ($ordenar === 'idade')  return $a['idade'] <=> $b['idade'];
        if ($ordenar === 'valor')  return ($a['pedido'] / max(0.01, $a['valor'])) <=> ($b['pedido'] / max(0.01, $b['valor']));
        return $b['ovr'] <=> $a['ovr'];
      });
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
        <i class="bi bi-box-arrow-in-down" style="color:var(--verde-claro)"></i>
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
              <option value="ovr"   <?= $ordenar === 'ovr' ? 'selected' : '' ?>>melhor OVR</option>
              <option value="preco" <?= $ordenar === 'preco' ? 'selected' : '' ?>>mais barato</option>
              <option value="idade" <?= $ordenar === 'idade' ? 'selected' : '' ?>>mais novo</option>
              <option value="valor" <?= $ordenar === 'valor' ? 'selected' : '' ?>>melhor negócio</option>
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
          <thead><tr><th>Jogador</th><th>Pos</th><th class="num">OVR</th><th class="num">Idade</th>
            <th>Clube</th><th class="num">Vale</th><th class="num">Pede</th><th></th></tr></thead>
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
</body>
</html>
