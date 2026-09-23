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

        elseif ($estado && $acao === 'jogar') {
            /* Quantas partidas de uma vez. O "jogar a rodada" de uma em uma é o
               ritmo do jogo, mas ninguém quer clicar 50 vezes pra ver o fim do
               ano — por isso existe o pular pra frente. */
            $quantas = max(1, min(50, (int)($_POST['quantas'] ?? 1)));
            for ($i = 0; $i < $quantas; $i++) {
                $r = futCarreiraJogarProxima($estado);
                if ($r['fim']) { $estado['fase'] = 'fim'; break; }
                $estado = $r['estado'];
            }
            futCarreiraSalvar($pdo, $idUsuario, $estado);
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

$aba = (string)($_GET['aba'] ?? 'jogo');
$meuClube = $estado ? futCarreiraMeuClube($estado) : null;
$clubesTodos = futClubesDoBrasil();

/** Escapa pra HTML. */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** O escudo do clube, ou um monograma quando não há imagem. */
function escudo(array $c, int $tam = 26): string
{
    $url = $c['escudo'] ?? '';
    if ($url !== '') {
        return '<img src="' . h($url) . '" alt="" width="' . $tam . '" height="' . $tam . '" loading="lazy" style="object-fit:contain;flex-shrink:0">';
    }
    $ini = mb_strtoupper(mb_substr($c['nome'] ?? '?', 0, 2));
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

input[type=text],input[type=number],select{width:100%;padding:10px 12px;border-radius:9px;border:1px solid var(--borda2);
  background:var(--panel3);color:var(--txt);font-size:14px}
label{display:block;font-size:12px;color:var(--txt2);margin-bottom:5px;font-weight:600}

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
  <?php $disponiveis = futClubesParaComecar(10); ?>
  <div class="bloco">
    <h3><i class="bi bi-flag-fill"></i> Começar a carreira</h3>
    <p style="color:var(--txt2);margin:0 0 14px;font-size:13px">
      Você começa sem currículo, então os grandes ainda não te atendem. Cumpra as metas,
      ganhe reputação, e os clubes maiores vêm atrás.
    </p>
    <?php if ($erro): ?><div class="msg err"><i class="bi bi-exclamation-triangle"></i><?= h($erro) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="acao" value="comecar">
      <div style="margin-bottom:12px">
        <label for="tecnico">Seu nome</label>
        <input type="text" id="tecnico" name="tecnico" maxlength="40" placeholder="Como você quer ser chamado" required>
      </div>
      <div style="margin-bottom:14px">
        <label for="clube">Clube</label>
        <select id="clube" name="clube" required>
          <?php foreach ($disponiveis as $nome => $c): ?>
            <option value="<?= h($nome) ?>"><?= h($nome) ?> — <?= h($c['div'] ?: 'estadual') ?>, força <?= (int)$c['forca'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn" type="submit"><i class="bi bi-play-fill"></i> Assumir o clube</button>
    </form>
  </div>

<?php else: ?>
  <?php
    $campNac = futCarreiraCampanha($estado, futCarreiraNomeDaDivisao($clubesTodos[$estado['clube']]['div'] ?? ''));
    $posicao = futCarreiraMinhaPosicao($estado);
    $folha = futFolhaDoElenco($estado['elenco']);
  ?>

  <div class="clube-card">
    <div class="clube-topo">
      <?= escudo($clubesTodos[$estado['clube']] ?? ['nome' => $estado['clube']], 42) ?>
      <div style="min-width:0">
        <div class="clube-nome"><?= h($estado['clube']) ?></div>
        <div class="clube-sub">
          <?= h($estado['tecnico']['nome']) ?> ·
          <?= h($clubesTodos[$estado['clube']]['div'] ?? 'estadual') ?> ·
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

  <?php if ($erro): ?><div class="msg err"><i class="bi bi-exclamation-triangle"></i><?= h($erro) ?></div><?php endif; ?>
  <?php if ($aviso): ?><div class="msg ok"><i class="bi bi-check-circle"></i><?= h($aviso) ?></div><?php endif; ?>

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

  <div class="abas">
    <?php foreach (['jogo' => 'Partidas', 'escalacao' => 'Escalação', 'elenco' => 'Elenco',
                    'stats' => 'Números', 'tabela' => 'Tabela', 'mercado' => 'Mercado',
                    'carreira' => 'Carreira'] as $k => $rot): ?>
      <a href="?aba=<?= $k ?>" class="<?= $aba === $k ? 'on' : '' ?>"><?= h($rot) ?></a>
    <?php endforeach; ?>
  </div>

  <?php // ── ABA: PARTIDAS ──────────────────────────────────────────── ?>
  <?php if ($aba === 'jogo'): ?>
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
                  <?= h($pr['div'] ?: 'estadual') ?> · força <?= (int)$pr['forca'] ?>
                </div>
              </div>
              <form method="post" onsubmit="return confirm('Assumir o <?= h($pr['nome']) ?>? Você deixa o <?= h($estado['clube']) ?>.')">
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
                  <?= h($c['div'] ?: 'estadual') ?> · força <?= (int)$c['forca'] ?>
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
              onsubmit="return confirm('Apagar esta carreira e começar outra do zero?')">
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
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="post" style="display:inline">
              <input type="hidden" name="acao" value="jogar"><input type="hidden" name="quantas" value="1">
              <button class="btn"><i class="bi bi-play-fill"></i> Jogar</button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="acao" value="jogar"><input type="hidden" name="quantas" value="5">
              <button class="btn sec"><i class="bi bi-fast-forward-fill"></i> Jogar 5</button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="acao" value="jogar"><input type="hidden" name="quantas" value="50">
              <button class="btn sec"><i class="bi bi-skip-end-fill"></i> Até o fim</button>
            </form>
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
            <td><?= h($j['nome']) ?></td>
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
              <form method="post" style="display:inline">
                <input type="hidden" name="acao" value="vender">
                <input type="hidden" name="aba" value="elenco">
                <input type="hidden" name="jogador" value="<?= h($j['nome']) ?>">
                <input type="hidden" name="oferta" value="<?= $v ?>">
                <input type="hidden" name="comprador" value="um clube interessado">
                <button class="btn sec peq" type="submit">Vender</button>
              </form>
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

        <div class="banco">
          <div class="banco-titulo">
            <i class="bi bi-people"></i> Banco
            <span style="color:var(--txt3);font-weight:400">— arraste ou toque pra trocar</span>
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
    <?php $tab = futCarreiraTabelaNacional($estado); ?>
    <div class="bloco">
      <h3><i class="bi bi-table"></i> <?= h(futCarreiraNomeDaDivisao($clubesTodos[$estado['clube']]['div'] ?? '') ?: 'Classificação') ?></h3>
      <?php if (!$tab): ?>
        <div class="vazio">A tabela aparece depois da primeira rodada do nacional.</div>
      <?php else: ?>
        <div class="rolar"><table>
          <thead><tr><th></th><th>Clube</th><th class="num">P</th><th class="num">J</th>
            <th class="num">V</th><th class="num">E</th><th class="num">D</th><th class="num">SG</th></tr></thead>
          <tbody>
          <?php $i = 0; $total = count($tab); foreach ($tab as $nome => $l): $i++;
            $clsPos = $i <= 4 ? 'sobe' : ($i > $total - 4 ? 'cai' : ''); ?>
            <tr class="<?= $nome === $estado['clube'] ? 'eu' : '' ?>">
              <td><span class="pos <?= $clsPos ?>"><?= $i ?></span></td>
              <td><?= h($nome) ?></td>
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

  <?php // ── ABA: MERCADO ───────────────────────────────────────────── ?>
  <?php elseif ($aba === 'mercado'): ?>
    <?php
      $div = $clubesTodos[$estado['clube']]['div'] ?? '';
      /* O mercado varre a sua divisão e a de baixo: é de onde o clube compra
         de verdade. Varrer os 98 clubes deixaria a lista lenta e cheia de nome
         que não interessa a ninguém. */
      $ordem = ['BR1' => ['BR1', 'BR2'], 'BR2' => ['BR2', 'BR3'], 'BR3' => ['BR3', '']];
      $divs = $ordem[$div] ?? ['BR3', ''];
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
                <?= h($m['nome']) ?>
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
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  <?php // ── ABA: CARREIRA ──────────────────────────────────────────── ?>
  <?php else: ?>
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
      <form method="post" onsubmit="return confirm('Apagar esta carreira e começar outra?')">
        <input type="hidden" name="acao" value="recomecar">
        <button class="btn sec" type="submit">Apagar e recomeçar</button>
      </form>
    </div>
  <?php endif; ?>

<?php endif; ?>

</div>
</body>
</html>
