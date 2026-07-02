// Pequenos aprimoramentos de UX globais.
document.addEventListener('click', function (e) {
  const a = e.target.closest('a.btn[href*="action="]');
  if (a && /action=(advance|sim-season)/.test(a.href)) {
    a.style.opacity = '.6';
    a.textContent = '⏳ Simulando...';
  }
});
