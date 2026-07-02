// Loteria do Draft: revela uma "bolinha" (posição) por clique, da 14ª até a 1ª escolha.
(function () {
  const reveal = window.LOTTERY || [];
  const slots = document.getElementById('lotterySlots');
  const hint = document.getElementById('lotteryHint');
  const btn = document.getElementById('revealBtn');
  const startBtn = document.getElementById('startDraftBtn');
  if (!btn || !slots) return;

  let i = 0;

  function escapeHtml(s) {
    return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }

  function revealNext() {
    if (i >= reveal.length) return;
    if (hint) { hint.remove(); }
    const item = reveal[i];
    const isFirst = item.pick === 1; // a última revelada é a 1ª escolha
    const ball = document.createElement('div');
    ball.className = 'lottery-ball pop' + (isFirst ? ' top-pick' : '') + (item.is_gm ? ' is-gm' : '');
    ball.innerHTML =
      '<span class="lb-num">#' + item.pick + '</span>' +
      '<span class="lb-dot" style="background:' + escapeHtml(item.color) + '"></span>' +
      '<span class="lb-team">' + escapeHtml(item.name) + '</span>' +
      (item.is_gm ? '<span class="lb-you">VOCÊ</span>' : '') +
      (isFirst ? '<span class="lb-crown">🏆 1ª escolha!</span>' : '');
    // mais recente no topo
    slots.insertBefore(ball, slots.firstChild);
    slots.scrollTop = 0;
    i++;

    if (i >= reveal.length) {
      btn.style.display = 'none';
      if (startBtn) startBtn.style.display = '';
    } else {
      btn.textContent = '🎱 Revelar #' + reveal[i].pick;
    }
  }

  btn.addEventListener('click', revealNext);
})();
