// Pequenos aprimoramentos de UX globais.
document.addEventListener('click', function (e) {
  const a = e.target.closest('a.btn[href*="action="], a.gmh-next-btn[href*="action="], a.cta-btn[href*="action="]');
  if (a && /action=(advance|sim-season|sim-days|sim-round|preseason-finish|next-season|finish-fa)/.test(a.href)) {
    a.style.opacity = '.6';
    a.textContent = '⏳ Simulando...';
  }
});

// Menu agrupado (Liga / Franquia): no toque abre no clique; fecha ao clicar fora.
document.addEventListener('click', function (e) {
  const btn = e.target.closest('.tn-group-btn');
  document.querySelectorAll('.tn-group.open').forEach(g => { if (!btn || g !== btn.parentElement) { g.classList.remove('open'); g.querySelector('.tn-group-btn').setAttribute('aria-expanded', 'false'); } });
  if (btn) {
    const g = btn.parentElement;
    const open = g.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    e.preventDefault();
  }
});
