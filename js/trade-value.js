/**
 * Avaliação de trocas da FBA — modelo compartilhado por trades.php e
 * trade-simulator.php (antes a fórmula estava duplicada nos dois).
 *
 * É um modelo de regras (heurístico), não um modelo estatístico treinado:
 * codifica como a liga enxerga valor. Princípios:
 *
 *  1. OVR não é linear. Um 90 não vale "20% a mais" que um 75 — vale ~2,7x.
 *     Estrelas são escassas, então a curva é convexa.
 *  2. Idade multiplica, não soma. O mesmo OVR vale muito mais aos 22 do que aos 32.
 *  3. Veterano mediano é ativo em queda. 70–80 de OVR depois dos 30 perde
 *     quase todo o valor de troca — não ajuda a ganhar agora nem no futuro.
 *  4. Pedigree de pick alto conta enquanto o jogador é jovem (aposta no teto).
 *     Depois dos 25 o OVR já diz o que ele é, e o pedigree deixa de importar.
 *  5. Picks futuras valem menos quanto mais distantes (incerteza).
 */
(function (global) {
  'use strict';

  var STAR_OVR = 84;   // a partir daqui o veterano ainda sustenta valor
  var FLOOR_OVR = 40;  // abaixo disso o jogador não tem valor de troca

  /** Curva convexa de OVR: estrelas valem desproporcionalmente mais. */
  function baseFromOvr(ovr) {
    var o = Math.max(FLOOR_OVR, Math.min(99, +ovr || 0));
    return Math.pow((o - FLOOR_OVR) / 10, 2.6);
  }

  /** Multiplicador por idade: pico entre 22 e 26, queda forte após os 30. */
  function ageMultiplier(age) {
    var a = +age || 25;
    if (a <= 21) return 1.35;
    if (a === 22) return 1.30;
    if (a === 23) return 1.24;
    if (a <= 26) return 1.15;
    if (a <= 28) return 1.04;
    if (a === 29) return 0.94;
    if (a === 30) return 0.82;
    if (a === 31) return 0.70;
    if (a === 32) return 0.56;
    if (a === 33) return 0.44;
    if (a === 34) return 0.32;
    return 0.22;
  }

  /**
   * Regra do veterano mediano: dos 30 em diante, quem não é estrela derrete.
   * Um 75 de 32 anos fica com ~5% do valor; um 90 de 32 mantém o valor cheio.
   */
  function veteranDecay(age, ovr) {
    if ((+age || 0) < 30) return 1;
    var o = +ovr || 0;
    if (o >= STAR_OVR) return 1;
    return Math.max(0.05, 1 - (STAR_OVR - o) * 0.105);
  }

  /** Bônus por ter sido pick alto — só enquanto jovem. */
  function pedigreeBonus(pick, round, age, ovr) {
    var p = +pick || 0;
    var r = +round || 0;
    if (!p || r !== 1) return 0;
    var a = +age || 25;
    var youth = a <= 21 ? 1 : a <= 23 ? 0.8 : a <= 25 ? 0.5 : 0;
    if (!youth) return 0;
    var b = p <= 3 ? 26 : p <= 5 ? 19 : p <= 10 ? 11 : p <= 20 ? 5 : 2;
    // Se já virou estrela, o OVR já reflete o teto — o pedigree conta menos.
    if ((+ovr || 0) >= 85) b *= 0.5;
    return b * youth;
  }

  /** Valor de uma pick como ativo de troca. */
  function pickValue(item) {
    var round = +(item.round || item.pick_round || 2);
    var base = round === 1 ? 30 : 7;
    var year = +(item.season_year || item.pick_year || 0);
    var current = +(global.__CURRENT_SEASON_YEAR__ || 0);
    if (year && current && year > current) {
      // Picks distantes valem menos: mais incerteza sobre quem estará escolhendo.
      base *= Math.max(0.72, 1 - (year - current) * 0.07);
    }
    return base;
  }

  /** Rótulo curto explicando o porquê do valor — usado nos tooltips. */
  function explain(item) {
    if (!isPlayer(item)) {
      var r = +(item.round || item.pick_round || 2);
      return r === 1 ? 'Pick de 1ª rodada' : 'Pick de 2ª rodada';
    }
    var age = +item.age || 25;
    var ovr = +item.ovr || 0;
    var pick = +item.draft_pick || 0;
    var round = +item.draft_round || 0;
    var partes = [];
    if (age <= 22 && ovr >= 80) partes.push('jovem de alto nível');
    else if (age <= 23) partes.push('jovem');
    else if (age >= 30 && ovr < STAR_OVR) partes.push('veterano em declínio');
    else if (age >= 30) partes.push('veterano de elite');
    if (ovr >= 90) partes.push('superestrela');
    else if (ovr >= 85) partes.push('estrela');
    if (pick && round === 1 && age <= 25) {
      partes.push(pick <= 5 ? 'pick top 5' : pick <= 10 ? 'pick de loteria' : 'pick de 1ª rodada');
    }
    return partes.length ? partes.join(' · ') : ovr + ' OVR, ' + age + ' anos';
  }

  function isPlayer(item) {
    return +(item.ovr || 0) > 0;
  }

  /** Valor de um item (jogador ou pick). */
  function itemValue(item) {
    if (!item) return 0;
    if (!isPlayer(item)) return pickValue(item);
    var ovr = +item.ovr || 0;
    var age = +item.age || 25;
    var v = baseFromOvr(ovr) * ageMultiplier(age) * veteranDecay(age, ovr);
    v += pedigreeBonus(item.draft_pick, item.draft_round, age, ovr);
    return Math.max(0, v);
  }

  /** Soma de uma lista de itens. */
  function totalValue(items) {
    return (items || []).reduce(function (s, i) { return s + itemValue(i); }, 0);
  }

  /**
   * Veredito comparando dois lados.
   * Usa diferença relativa, mas exige uma diferença absoluta mínima para não
   * chamar de "roubo" uma troca pequena (ex.: 2 contra 4 pontos).
   */
  function verdict(a, b) {
    var max = Math.max(a, b), min = Math.min(a, b);
    if (max <= 0) {
      return { key: 'neutral', label: 'AGUARDANDO', title: 'Aguardando itens', icon: 'hourglass-split' };
    }
    var rel = (max - min) / max;
    var abs = max - min;
    if (abs < 6 || rel <= 0.10) {
      return { key: 'valid', label: 'JUSTA', title: 'Troca justa — valores equivalentes', icon: 'check-circle-fill' };
    }
    if (rel <= 0.22) {
      return { key: 'warn', label: 'DESIGUAL', title: 'Levemente desigual', icon: 'exclamation-triangle-fill' };
    }
    if (rel <= 0.40) {
      return { key: 'invalid', label: 'DESEQ.', title: 'Desequilibrada — diferença significativa', icon: 'x-octagon-fill' };
    }
    return { key: 'robbery', label: 'ROUBO!', title: 'Isso é um roubo!', icon: 'emoji-dizzy-fill' };
  }

  global.TradeValue = {
    itemValue: itemValue,
    totalValue: totalValue,
    verdict: verdict,
    explain: explain,
    // expostos para inspeção/afinação
    _internals: {
      baseFromOvr: baseFromOvr,
      ageMultiplier: ageMultiplier,
      veteranDecay: veteranDecay,
      pedigreeBonus: pedigreeBonus,
      pickValue: pickValue
    }
  };
})(typeof window !== 'undefined' ? window : this);
