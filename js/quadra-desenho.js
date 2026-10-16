/**
 * O DESENHO DA MEIA QUADRA — compartilhado.
 *
 * Só o desenho: o traçado em SVG, onde fica cada um dos cinco lugares e a
 * cor de cada posição. Nenhuma regra de escalação mora aqui.
 *
 * Existe porque a Mesa Tática (mesa-tatica.php) quis a mesma quadra do Meu
 * Elenco (js/quadra.js), e copiar o SVG daria duas quadras que divergem na
 * primeira vez que alguém mexer numa delas — foi o que já aconteceu com o
 * CSV de elenco, que virou três cópias antes de virar um arquivo só.
 *
 * As duas telas usam o mesmo desenho e NADA ALÉM DELE: a escalação trava por
 * posição e salva na API; a mesa não trava nada e não salva no servidor. Era
 * justamente isso que impedia a mesa de reaproveitar o quadra.js inteiro.
 */
(function () {
  'use strict';

  window.QuadraDesenho = {
    POS: ['PG', 'SG', 'SF', 'PF', 'C'],

    /** A cor de cada posição, a mesma nas duas telas. */
    CORES: { PG: '#3b82f6', SG: '#06b6d4', SF: '#22c55e', PF: '#f59e0b', C: '#ef4444' },

    /** Onde cada lugar fica na meia quadra (cesta embaixo), em % da caixa. */
    LUGAR: { PG: [50, 17], SG: [83, 38], SF: [17, 38], PF: [69, 71], C: [31, 71] },

    SVG: `<svg viewBox="0 0 500 470" preserveAspectRatio="none" aria-hidden="true">
    <rect x="1" y="1" width="498" height="468"/>
    <path d="M190 1 A60 60 0 0 0 310 1"/>
    <rect x="170" y="280" width="160" height="189"/>
    <path d="M190 280 A60 60 0 0 1 310 280"/>
    <path d="M30 469 V350 A232 232 0 0 1 470 350 V469"/>
    <line x1="220" y1="442" x2="280" y2="442"/>
    <circle cx="250" cy="427" r="10"/>
  </svg>`,
  };
})();
