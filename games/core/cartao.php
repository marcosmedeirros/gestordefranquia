<?php
/**
 * O cartão dos games em imagem, compartilhado pelos quatro jogos.
 *
 * Uso na página:
 *   <?= cartaoScript() ?>
 *
 * E no JS do jogo:
 *   fbaCompartilhar({
 *     c1:'#a855f7', c2:'#0a0a0c',          // a cor do destaque e o fundo
 *     numero:'96', rotulo:'OVR',            // a caixa grande
 *     pilulas:[                             // a fileira do topo, até 4
 *       {img:'/img/bandeiras/cro.png'},     // imagem sozinha
 *       {rotulo:'VALOR', texto:'€157M'},    // rótulo pequeno + valor
 *       {texto:'#10'},                      // só o valor
 *     ],
 *     stats:[['1115','Jogos'],['1244','Gols'],['450','Ast']],
 *     faixas:[                              // até 2, uma embaixo da outra
 *       {titulo:'Trajetória', itens:[{img:'…'},{img:'…'}]},
 *       {titulo:'Títulos',    itens:[{img:'…', contagem:22}]},
 *     ],
 *     nome:'MARCOS', jogo:'COPERO',
 *   }, botao);
 *
 * ── Sobre as imagens ──────────────────────────────────────────────────
 *
 * Imagem de outro domínio CONTAMINA o canvas: o toBlob passa a estourar com
 * SecurityError e a foto simplesmente não sai. Por isso toda URL externa é
 * reescrita pra passar por /api/foto-proxy.php, que a serve pela nossa
 * origem. Imagem que não carregar é PULADA — cartão sem um escudo é melhor
 * que cartão que não gera.
 *
 * ── Sobre os itens ────────────────────────────────────────────────────
 *
 * Cada item de faixa é `{img}` ou `{texto, legenda}`. É o que deixa a mesma
 * faixa mostrar escudo no Copero, atributo no BuildPlayer e escalação no
 * 5x5 sem cada jogo precisar do seu próprio desenho.
 */

/**
 * A cor do cartão sai de um nome — mesmo nome, mesma cor, print após print.
 *
 * Vive aqui e não em cada jogo porque `my-roster.php` e o Build-A-Player
 * dependem dela pra pintar coisas que NÃO são o cartão: o elenco usa pra cor
 * da foto do time, e o build pra cor do topo.
 */
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
/* O cartão é desenhado no canvas, e não em HTML virado imagem: assim sai
   igual em qualquer aparelho. Ver games/core/cartao.php. */
(function(){
  const L = 1080, A = 1350, P = 60;
  const mono = (px, peso) => `${peso} ${px}px ui-monospace, "SF Mono", Menlo, Consolas, monospace`;
  const sans = (px, peso) => `${peso} ${px}px Poppins, system-ui, -apple-system, sans-serif`;

  /** Corta o texto no que couber, com reticências — nome grande não vaza. */
  function cabe(c, txt, largura){
    txt = String(txt ?? '');
    if (c.measureText(txt).width <= largura) return txt;
    while (txt.length > 1 && c.measureText(txt + '…').width > largura) txt = txt.slice(0, -1);
    return txt + '…';
  }

  function retanguloRedondo(c, x, y, w, h, r){
    c.beginPath();
    c.moveTo(x + r, y);
    c.arcTo(x + w, y,     x + w, y + h, r);
    c.arcTo(x + w, y + h, x,     y + h, r);
    c.arcTo(x,     y + h, x,     y,     r);
    c.arcTo(x,     y,     x + w, y,     r);
    c.closePath();
  }

  /**
   * Reescreve URL externa pra passar pelo nosso proxy.
   *
   * Sem isto a imagem contamina o canvas e o cartão inteiro deixa de gerar —
   * um escudo derruba a foto toda. O proxy só aceita as fontes conhecidas, e
   * o que não casar volta como veio (e aí simplesmente não carrega).
   */
  function viaProxy(url){
    const u = String(url || '');
    if (!u) return '';
    if (u.startsWith('/') || u.startsWith('data:')) return u;   // já é nosso
    let m = u.match(/^https?:\/\/r2\.thesportsdb\.com\/images\/media\/(.+)$/);
    if (m) return '/api/foto-proxy.php?f=sdb&p=' + encodeURIComponent(m[1]);
    m = u.match(/^https?:\/\/a\.espncdn\.com\/i\/teamlogos\/(.+)$/);
    if (m) return '/api/foto-proxy.php?f=espn&p=' + encodeURIComponent(m[1]);
    m = u.match(/^https?:\/\/cdn\.nba\.com\/headshots\/nba\/latest\/\d+x\d+\/(\d+)\.png$/);
    if (m) return '/api/foto-proxy.php?id=' + m[1];
    return u;
  }

  /** Carrega uma imagem, ou null se ela não vier. Nunca rejeita. */
  function carregar(url){
    return new Promise(ok => {
      const src = viaProxy(url);
      if (!src) return ok(null);
      const img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload  = () => ok(img);
      img.onerror = () => ok(null);
      img.src = src;
      // Imagem que trava não pode segurar o cartão pra sempre.
      setTimeout(() => ok(img.complete && img.naturalWidth ? img : null), 6000);
    });
  }

  /** Desenha a imagem contida numa caixa, sem distorcer. */
  function desenharContido(c, img, x, y, lado){
    const e = Math.min(lado / img.naturalWidth, lado / img.naturalHeight);
    const w = img.naturalWidth * e, h = img.naturalHeight * e;
    c.drawImage(img, x + (lado - w) / 2, y + (lado - h) / 2, w, h);
  }

  /**
   * A imagem é escura demais pro fundo escuro do cartão?
   *
   * Escudo azul-marinho e logo preto existem aos montes, e sobre um fundo
   * quase preto eles somem — o cartão saía com um buraco onde devia estar o
   * clube. Quem for escuro ganha uma base clara atrás; quem já brilha, como
   * as taças douradas, fica como está.
   *
   * Só os pixels OPACOS contam: PNG de escudo é quase todo transparente, e
   * incluir o vazio faria toda imagem parecer escura.
   */
  function ehEscura(img){
    try {
      const n = 40, aux = document.createElement('canvas');
      aux.width = n; aux.height = n;
      const g = aux.getContext('2d', {willReadFrequently:true});
      g.drawImage(img, 0, 0, n, n);
      const d = g.getImageData(0, 0, n, n).data;
      let soma = 0, conta = 0;
      for (let i = 0; i < d.length; i += 4){
        if (d[i+3] < 128) continue;
        soma += (d[i] + d[i+1] + d[i+2]) / 3;
        conta++;
      }
      return conta > 20 && soma / conta < 82;
    } catch (e) {
      return false;   // imagem que não deixa ler fica sem base, e não sem cartão
    }
  }

  window.fbaCartaoImagem = async function (d){
    const cv = document.createElement('canvas');
    cv.width = L; cv.height = A;
    const c = cv.getContext('2d');

    // A cor de destaque é NORMALIZADA pra hex antes de qualquer coisa. O
    // canvas faz isso sozinho ao atribuir em fillStyle, e é o que permite
    // acrescentar transparência como sufixo ('…26'). Sem isto, quem passa
    // cor em hsl() — o Starting5x5 passa — estourava com "could not be
    // parsed as a color" e o cartão não saía.
    c.fillStyle = d.c1 || '#a855f7';
    const destaque = String(c.fillStyle).startsWith('#') ? c.fillStyle : '#a855f7';

    // Fundo: quase preto, com um respiro da cor do destaque no topo. O
    // gradiente inteiro na cor competia com a caixa do OVR.
    c.fillStyle = d.c2 || '#0d0d10';
    c.fillRect(0, 0, L, A);
    const brilho = c.createRadialGradient(L * .3, 120, 0, L * .3, 120, L * .95);
    brilho.addColorStop(0, destaque + '26');
    brilho.addColorStop(1, 'transparent');
    c.fillStyle = brilho; c.fillRect(0, 0, L, A);

    // Moldura fina: fecha o cartão e some no fundo escuro do WhatsApp.
    c.strokeStyle = 'rgba(255,255,255,.08)'; c.lineWidth = 3;
    retanguloRedondo(c, 14, 14, L - 28, A - 28, 34); c.stroke();

    c.textBaseline = 'alphabetic';

    // ── A caixa do número ────────────────────────────────────────────
    const bx = P, by = 56, bw = 210, bh = 240;
    const gb = c.createLinearGradient(bx, by, bx + bw, by + bh);
    gb.addColorStop(0, destaque);
    gb.addColorStop(1, destaque + 'cc');
    c.fillStyle = gb;
    retanguloRedondo(c, bx, by, bw, bh, 30); c.fill();

    c.textAlign = 'center';
    c.fillStyle = 'rgba(0,0,0,.55)'; c.font = sans(26, 800);
    c.fillText(String(d.rotulo || 'OVR').toUpperCase(), bx + bw / 2, by + 62);
    const txtNum = String(d.numero ?? '');
    c.fillStyle = '#fff';
    c.font = mono(txtNum.length > 4 ? 74 : (txtNum.length > 2 ? 100 : 130), 900);
    c.fillText(txtNum, bx + bw / 2, by + 178);
    c.textAlign = 'left';

    // ── As pílulas do topo ───────────────────────────────────────────
    const px0 = bx + bw + 22;
    const larguraPilulas = L - P - px0;
    const pilulas = (d.pilulas || []).slice(0, 4);
    const imgsPilula = await Promise.all(pilulas.map(p => p.img ? carregar(p.img) : null));

    if (pilulas.length){
      const gap = 14;
      const larg = (larguraPilulas - gap * (pilulas.length - 1)) / pilulas.length;
      pilulas.forEach((p, i) => {
        const x = px0 + (larg + gap) * i, y = by, h = 108;
        c.fillStyle = 'rgba(255,255,255,.07)';
        retanguloRedondo(c, x, y, larg, h, 26); c.fill();
        c.strokeStyle = 'rgba(255,255,255,.10)'; c.lineWidth = 2; c.stroke();

        const img = imgsPilula[i];
        if (img){
          desenharContido(c, img, x + (larg - 72) / 2, y + (h - 72) / 2, 72);
          return;
        }
        c.textAlign = 'center';
        if (p.rotulo){
          c.fillStyle = 'rgba(255,255,255,.5)'; c.font = sans(20, 800);
          c.fillText(cabe(c, String(p.rotulo).toUpperCase(), larg - 20), x + larg / 2, y + 42);
          c.fillStyle = '#fff'; c.font = sans(36, 800);
          c.fillText(cabe(c, String(p.texto ?? ''), larg - 20), x + larg / 2, y + 82);
        } else {
          c.fillStyle = '#fff'; c.font = sans(42, 800);
          c.fillText(cabe(c, String(p.texto ?? ''), larg - 20), x + larg / 2, y + 72);
        }
        c.textAlign = 'left';
      });
    }

    // ── A régua de números ───────────────────────────────────────────
    const stats = (d.stats || []).slice(0, 4);
    if (stats.length){
      const y = by + 126, h = 114;
      c.fillStyle = 'rgba(255,255,255,.05)';
      retanguloRedondo(c, px0, y, larguraPilulas, h, 26); c.fill();
      c.strokeStyle = 'rgba(255,255,255,.09)'; c.lineWidth = 2; c.stroke();

      const larg = larguraPilulas / stats.length;
      stats.forEach(([valor, rot], i) => {
        const cx = px0 + larg * i + larg / 2;
        if (i){
          c.strokeStyle = 'rgba(255,255,255,.10)'; c.lineWidth = 2;
          c.beginPath(); c.moveTo(px0 + larg * i, y + 22); c.lineTo(px0 + larg * i, y + h - 22); c.stroke();
        }
        c.textAlign = 'center';
        c.fillStyle = 'rgba(255,255,255,.5)'; c.font = sans(19, 800);
        c.fillText(cabe(c, String(rot).toUpperCase(), larg - 14), cx, y + 40);
        c.fillStyle = '#fff'; c.font = mono(String(valor).length > 5 ? 40 : 50, 900);
        c.fillText(cabe(c, String(valor), larg - 14), cx, y + 92);
        c.textAlign = 'left';
      });
    }

    // ── As faixas do meio ────────────────────────────────────────────
    //
    // Duas, empilhadas, dividindo o espaço entre o topo e o rodapé. Cada uma
    // tem título centralizado e os itens numa fileira que quebra em duas
    // linhas quando são muitos.
    const faixas = (d.faixas || []).slice(0, 2);
    const yInicio = 380, yFim = A - 190;
    const altura = (yFim - yInicio) / Math.max(1, faixas.length);

    // Carrega tudo antes de desenhar: meio cartão desenhado enquanto imagem
    // chega daria ordem diferente a cada geração.
    const imgsFaixa = await Promise.all(
      faixas.map(f => Promise.all((f.itens || []).slice(0, 8).map(it => it.img ? carregar(it.img) : null)))
    );

    faixas.forEach((f, fi) => {
      const topo = yInicio + altura * fi;
      c.textAlign = 'center';
      c.fillStyle = 'rgba(255,255,255,.42)'; c.font = sans(24, 800);
      // O letter-spacing também é aplicado DEPOIS da última letra, então o
      // texto centralizado sai 3px pra direita. Metade do espaçamento de
      // volta resolve.
      c.letterSpacing = '6px';
      c.fillText(String(f.titulo || '').toUpperCase(), L / 2 - 3, topo + 40);
      c.letterSpacing = '0px';

      const itens = (f.itens || []).slice(0, 8);
      if (!itens.length) return;

      // Até cinco cabem numa linha só — e precisam caber, senão um quinteto
      // sai 4+1 e deixa de parecer escalação. Acima disso, duas linhas
      // iguais.
      const porLinha = itens.length <= 5 ? itens.length : Math.ceil(itens.length / 2);
      const linhas = Math.ceil(itens.length / porLinha);
      const gap = 30;
      const lado = Math.min(linhas > 1 ? 112 : 140,
                            Math.floor((L - P * 2 - 60 - gap * (porLinha - 1)) / porLinha));
      const alturaItem = lado + 44;
      const yBase = topo + (altura - 40 - alturaItem * linhas) / 2 + 70;

      itens.forEach((item, i) => {
        const linha = Math.floor(i / porLinha);
        const nesta = Math.min(porLinha, itens.length - linha * porLinha);
        const largTotal = nesta * lado + (nesta - 1) * gap;
        const x = (L - largTotal) / 2 + (i % porLinha) * (lado + gap);
        const y = yBase + linha * alturaItem;

        const img = imgsFaixa[fi][i];
        if (img){
          if (ehEscura(img)){
            c.fillStyle = 'rgba(255,255,255,.93)';
            retanguloRedondo(c, x, y, lado, lado, lado * .2); c.fill();
            const folga = lado * .13;
            desenharContido(c, img, x + folga, y + folga, lado - folga * 2);
          } else {
            desenharContido(c, img, x, y, lado);
          }
        } else if (item.texto != null){
          c.fillStyle = 'rgba(255,255,255,.06)';
          retanguloRedondo(c, x, y, lado, lado, 24); c.fill();
          c.fillStyle = '#fff'; c.font = sans(String(item.texto).length > 3 ? 34 : 54, 900);
          c.fillText(cabe(c, String(item.texto), lado - 16), x + lado / 2, y + lado / 2 + 18);
        }

        // A contagem, num selo no canto — quatro Champions viram uma taça
        // com ×4, e não quatro desenhos iguais em fila.
        if (item.contagem > 1){
          const r = 30, cxs = x + lado - 6, cys = y + lado - 6;
          c.fillStyle = '#0d0d10';
          c.beginPath(); c.arc(cxs, cys, r, 0, Math.PI * 2); c.fill();
          c.strokeStyle = 'rgba(255,255,255,.18)'; c.lineWidth = 2; c.stroke();
          c.fillStyle = '#fff'; c.font = sans(26, 800);
          c.fillText('×' + item.contagem, cxs, cys + 9);
        }

        if (item.legenda){
          c.fillStyle = 'rgba(255,255,255,.55)'; c.font = sans(20, 700);
          c.fillText(cabe(c, String(item.legenda), lado + gap - 6), x + lado / 2, y + lado + 30);
        }
      });
      c.textAlign = 'left';
    });

    // ── Rodapé ───────────────────────────────────────────────────────
    c.strokeStyle = 'rgba(255,255,255,.12)'; c.lineWidth = 2;
    c.beginPath(); c.moveTo(P, A - 128); c.lineTo(L - P, A - 128); c.stroke();

    c.fillStyle = '#fff'; c.font = sans(40, 800);
    c.fillText(cabe(c, d.nome || '', L - P * 2 - 320), P, A - 66);

    c.textAlign = 'right';
    c.fillStyle = destaque; c.font = sans(30, 800);
    c.fillText('fbabrasil.com.br', L - P, A - 66);
    c.fillStyle = 'rgba(255,255,255,.4)'; c.font = sans(20, 700);
    c.fillText(String(d.jogo || 'FBA GAMES').toUpperCase(), L - P, A - 100);
    c.textAlign = 'left';

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
      const cv = await fbaCartaoImagem(d);
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
