<?php
/**
 * O cartão compartilhável dos games, em imagem.
 *
 * Um jogo termina e a pessoa quer mandar o resultado no grupo. Print de tela
 * sai com barra de status, no tamanho do aparelho de quem mandou, e ilegível
 * na miniatura da conversa. Aqui todo jogo gera a MESMA imagem: 1080×1350, o
 * formato que WhatsApp e story usam, legível antes de abrir.
 *
 * Vive num arquivo só porque três jogos usam — Caminho, Build-A-Player e
 * Starting5x5. Cada um tem o resultado que tem; o que eles compartilham é o
 * desenho, e é justamente o desenho que não pode divergir: três cópias de
 * canvas viravam três cartões com cara diferente no primeiro ajuste.
 *
 * Uso:
 *   <?php require_once __DIR__ . '/../core/cartao.php'; ?>
 *   ...
 *   <?= cartaoScript() ?>
 *
 * E no JS do jogo:
 *   fbaCompartilhar({
 *     c1:'hsl(200 55% 26%)', c2:'hsl(20 45% 12%)',   // fundo
 *     numero:'94', rotulo:'pico de overall',          // o número grande
 *     direita:['PG','Envood','14 temporadas'],        // até 3 linhas
 *     titulo:'Lenda', sub:'163 pontos de legado',
 *     nums:[['3','Títulos'],['2','MVP']],             // até 8
 *     nome:'Marcos Silva', jogo:'CAMINHO ATÉ A NBA',
 *   }, botao);
 */

/** A cor do cartão sai de um nome — mesmo nome, mesma cor, print após print. */
function cartaoCoresDoNome(string $nome): array
{
    $h = 0;
    foreach (preg_split('//u', $nome ?: '?', -1, PREG_SPLIT_NO_EMPTY) as $c) {
        $h = ($h * 31 + mb_ord($c)) % 4294967296;
    }
    $mat  = $h % 360;
    $comp = ($mat + 150 + ($h % 60)) % 360;
    return ["hsl($mat 55% 26%)", "hsl($comp 45% 12%)"];
}

function cartaoScript(): string
{
    return <<<'HTML'
<script>
/* ── CARTÃO COMPARTILHÁVEL ──────────────────────────────────────────────
   Desenhado, não capturado: o resultado vira uma imagem pensada pro grupo,
   igual em qualquer aparelho. Ver games/core/cartao.php. */
(function(){
  const L = 1080, A = 1350, P = 84;
  const mono = (px, peso) => `${peso} ${px}px ui-monospace, "SF Mono", Menlo, Consolas, monospace`;
  const sans = (px, peso) => `${peso} ${px}px Poppins, system-ui, -apple-system, sans-serif`;

  /** Corta o texto no que couber, com reticências — nome grande não vaza. */
  function cabe(c, txt, largura){
    txt = String(txt ?? '');
    if (c.measureText(txt).width <= largura) return txt;
    while (txt.length > 1 && c.measureText(txt + '…').width > largura) txt = txt.slice(0, -1);
    return txt + '…';
  }

  window.fbaCartaoImagem = function (d){
    const cv = document.createElement('canvas');
    cv.width = L; cv.height = A;
    const c = cv.getContext('2d');

    const g = c.createLinearGradient(0, 0, L * 0.6, A);
    g.addColorStop(0, d.c1 || '#2a2a31');
    g.addColorStop(1, d.c2 || '#131317');
    c.fillStyle = g; c.fillRect(0, 0, L, A);

    // Listras diagonais: dão textura sem competir com o texto.
    c.save();
    c.globalAlpha = .035; c.strokeStyle = '#fff'; c.lineWidth = 18;
    for (let x = -A; x < L + A; x += 46){ c.beginPath(); c.moveTo(x, 0); c.lineTo(x + A, A); c.stroke(); }
    c.restore();

    c.textBaseline = 'alphabetic';

    // Número grande e seu rótulo.
    if (d.rotulo){
      c.fillStyle = 'rgba(255,255,255,.62)'; c.font = sans(26, 700);
      c.fillText(String(d.rotulo).toUpperCase(), P, 200);
    }
    c.fillStyle = '#fff'; c.font = mono(d.numero && String(d.numero).length > 5 ? 120 : 190, 900);
    c.fillText(String(d.numero ?? ''), P - 8, 370);

    // Até três linhas à direita.
    c.textAlign = 'right'; c.font = sans(34, 700); c.fillStyle = 'rgba(255,255,255,.92)';
    (d.direita || []).slice(0, 3).forEach((linha, i) => {
      c.fillText(cabe(c, linha, L / 2), L - P, 200 + i * 48);
    });
    c.textAlign = 'left';

    c.fillStyle = '#fff'; c.font = sans(62, 800);
    c.fillText(cabe(c, d.titulo || '', L - P * 2), P, 500);
    if (d.sub){
      c.fillStyle = 'rgba(255,255,255,.7)'; c.font = sans(30, 600);
      c.fillText(cabe(c, d.sub, L - P * 2), P, 552);
    }

    // A grade: quatro por linha, cada um centrado na sua coluna.
    const nums = (d.nums || []).slice(0, 8);
    const porLinha = 4, larg = (L - P * 2) / porLinha;
    nums.forEach(([valor, rot], i) => {
      const cx = P + larg * (i % porLinha) + larg / 2;
      const cy = 700 + Math.floor(i / porLinha) * 190;
      c.textAlign = 'center';
      c.fillStyle = '#fff'; c.font = mono(String(valor).length > 6 ? 48 : 76, 900);
      c.fillText(String(valor), cx, cy);
      c.fillStyle = 'rgba(255,255,255,.6)'; c.font = sans(23, 700);
      c.fillText(cabe(c, String(rot).toUpperCase(), larg - 10), cx, cy + 44);
    });

    c.textAlign = 'left';
    c.fillStyle = '#fff'; c.font = sans(46, 800);
    c.fillText(cabe(c, d.nome || '', L - P * 2), P, A - 140);
    c.fillStyle = 'rgba(255,255,255,.45)'; c.font = mono(24, 400);
    c.fillText('FBA GAMES · ' + String(d.jogo || '').toUpperCase(), P, A - 92);

    return cv;
  };

  /**
   * Manda a imagem. No celular abre a folha do sistema — um toque até o
   * grupo. No desktop essa folha não existe pra arquivo, então baixa.
   */
  window.fbaCompartilhar = async function (d, botao){
    const antes = botao ? botao.textContent : '';
    if (botao) botao.textContent = 'Gerando…';
    try {
      const cv = fbaCartaoImagem(d);
      const blob = await new Promise(r => cv.toBlob(r, 'image/png'));
      const arq = new File([blob], 'fba.png', {type:'image/png'});

      if (navigator.canShare && navigator.canShare({files:[arq]})){
        await navigator.share({files:[arq], title:d.nome || 'FBA Games', text:d.titulo || ''});
        if (botao) botao.textContent = antes;
        return;
      }
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = String(d.nome || 'fba').replace(/[^\w-]+/g, '-') + '.png';
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 4000);
      if (botao) botao.textContent = 'Baixado ✓';
    } catch (e) {
      // Cancelar o compartilhamento chega aqui como erro; não é falha.
      if (botao) botao.textContent = antes;
      return;
    }
    if (botao) setTimeout(() => { botao.textContent = antes; }, 1800);
  };
})();
</script>
HTML;
}
