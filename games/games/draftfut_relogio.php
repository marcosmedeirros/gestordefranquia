<?php
/**
 * ── O RELÓGIO DE CINCO MINUTOS DO DUELO ──────────────────────────────
 *
 * Aparece em TODAS as telas em que se monta um time dentro de um duelo: a
 * escolha de esquema, o pacote de cartas e o campo. Era isso ou a pessoa
 * descobrir o prazo só quando ele acabasse.
 *
 * O desenho é de cronômetro mesmo — grande, fixo no alto — porque ele não é
 * informação, é pressão: o jogo mudou de "monte quando puder" pra "monte
 * agora". Fica vermelho no último minuto.
 *
 * QUEM DECIDE É O SERVIDOR. Este contador é desenho: quando ele chega a zero,
 * a página recarrega e quem aplica o W.O. é o PHP (@see dfdCobrarPrazo), que
 * lê o prazo do banco. Adiantar o relógio do computador não ganha segundo
 * nenhum.
 *
 * Espera $duelo e $user_id; não desenha nada fora de um duelo em montagem.
 */

if (empty($duelo) || ($duelo['status'] ?? '') !== 'montando') return;

$relLado  = dfdLado($duelo, $user_id);
$relResta = dfdSegundosRestantes($duelo, $relLado);
if ($relResta === null) return;   // já fechei o meu time: o relógio é do outro
?>
<div class="dfd-relogio<?= $relResta <= 60 ? ' aperta' : '' ?>" id="dfdRelogio"
     data-restam="<?= (int)$relResta ?>">
  <i class="bi bi-stopwatch"></i>
  <span class="dfd-rel-n" id="dfdRelogioN"><?= sprintf('%d:%02d', intdiv($relResta, 60), $relResta % 60) ?></span>
  <span class="dfd-rel-t">pra fechar o time — passou disso, o duelo é do adversário</span>
</div>
<style>
.dfd-relogio{position:sticky;top:8px;z-index:30;display:flex;align-items:center;gap:10px;
  margin:0 0 14px;padding:10px 14px;border-radius:14px;background:var(--painel2,#16161a);
  border:1px solid rgba(255,255,255,.08);box-shadow:0 10px 30px rgba(0,0,0,.35)}
.dfd-relogio i{font-size:18px;color:var(--amarelo,#f5c518)}
.dfd-rel-n{font-family:var(--fonte-num,inherit);font-size:24px;font-weight:800;line-height:1;
  font-variant-numeric:tabular-nums;min-width:62px}
.dfd-rel-t{font-size:12.5px;color:var(--txt2,#9a9aa3);line-height:1.3}
.dfd-relogio.aperta{border-color:rgba(255,92,122,.45);background:rgba(255,92,122,.10)}
.dfd-relogio.aperta i,.dfd-relogio.aperta .dfd-rel-n{color:#ff5c7a}
@media (max-width:560px){.dfd-rel-t{font-size:11.5px}}
</style>
<script>
(function(){
  var el = document.getElementById('dfdRelogio');
  var n  = document.getElementById('dfdRelogioN');
  if (!el || !n) return;
  var restam = parseInt(el.dataset.restam, 10) || 0;
  /* O alvo é um instante, não um contador de ticks: aba em segundo plano
     ganha setInterval mais lento, e descontar 1 por tick faria o relógio da
     tela atrasar em relação ao do servidor justamente em quem deixou a aba
     de lado. */
  var fim = Date.now() + restam * 1000;
  function passo(){
    var s = Math.max(0, Math.round((fim - Date.now()) / 1000));
    n.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    el.classList.toggle('aperta', s <= 60);
    if (s <= 0) { clearInterval(t); location.reload(); }
  }
  var t = setInterval(passo, 1000);
  passo();
})();
</script>
