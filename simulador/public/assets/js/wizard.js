/**
 * FBA Save Creation Wizard
 * Multi-step save creation: Era → Team → GM Setup → Confirm
 */
(function(){
  'use strict';

  // ---- state ----
  let currentStep = 1;
  const TOTAL_STEPS = 4;
  const state = {
    era: '', eraName: '', eraYear: '',
    team: '', teamName: '', teamCity: '', teamColor: '',
    gmName: '', saveName: '',
    coachStyle: 'equilibrado',
    difficulty: 'normal',
    potentialType: 'real',
  };

  // ---- selectors ----
  const overlay  = document.getElementById('wizardOverlay');
  const panels   = document.querySelectorAll('.wiz-panel');
  const dots     = document.querySelectorAll('.wiz-step-dot');
  const errBox   = document.getElementById('wizErr');

  function open() {
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    goTo(1);
  }
  function close() {
    overlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  function goTo(step) {
    if (step < 1 || step > TOTAL_STEPS) return;
    currentStep = step;
    panels.forEach((p,i) => p.classList.toggle('active', i+1 === step));
    dots.forEach((d,i) => {
      d.classList.remove('active','done');
      if (i+1 === step)   d.classList.add('active');
      if (i+1 < step)     d.classList.add('done');
    });
    if (errBox) errBox.style.display = 'none';
    overlay.querySelector('.wizard-inner').scrollTop = 0;
    if (step === TOTAL_STEPS) populateConfirm();
  }

  function showErr(msg) {
    if (!errBox) return;
    errBox.textContent = msg;
    errBox.style.display = 'block';
  }

  function validate() {
    if (currentStep === 1) {
      if (!state.era) { showErr('Escolha uma era para continuar.'); return false; }
    }
    if (currentStep === 2) {
      if (!state.team) { showErr('Escolha sua franquia para continuar.'); return false; }
    }
    if (currentStep === 3) {
      const nm = document.getElementById('f_gm_name');
      state.gmName = nm ? nm.value.trim() : '';
      const sn = document.getElementById('f_save_name');
      state.saveName = sn ? sn.value.trim() : '';
      if (!state.gmName) { showErr('Digite o nome do seu GM.'); nm && nm.focus(); return false; }
    }
    return true;
  }

  function populateConfirm() {
    // team badge
    const badge = document.getElementById('confBadge');
    const stripe = document.getElementById('confStripe');
    if (badge) { badge.textContent = state.team; badge.style.background = state.teamColor; }
    if (stripe) stripe.style.background = state.teamColor;

    const set = (id, val) => { const el = document.getElementById(id); if(el) el.textContent = val; };
    set('confEra',    state.eraName || '—');
    set('confTeam',   (state.teamCity + ' ' + state.teamName).trim() || state.team);
    set('confGM',     state.gmName || '—');
    set('confSave',   state.saveName || state.gmName + ' Dynasty');
    set('confStyle',  styleLabel(state.coachStyle));
    set('confDiff',   diffLabel(state.difficulty));
    set('confPot',    state.potentialType === 'real' ? 'Real (histórico)' : 'Aleatório (surpresa)');

    // populate hidden form fields
    fillHidden('h_era',           state.era);
    fillHidden('h_team',          state.team);
    fillHidden('h_gm_name',       state.gmName);
    fillHidden('h_save_name',     state.saveName || state.gmName + ' Dynasty');
    fillHidden('h_coach_style',   state.coachStyle);
    fillHidden('h_difficulty',    state.difficulty);
    fillHidden('h_potential_type',state.potentialType);
  }

  function fillHidden(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val;
  }

  function styleLabel(v) {
    return { ofensivo:'Ofensivo', defensivo:'Defensivo', equilibrado:'Equilibrado',
             desenvolvimento:'Desenvolvimento', vencedor:'Vencedor' }[v] || v;
  }
  function diffLabel(v) {
    return { facil:'Fácil', normal:'Normal', dificil:'Difícil' }[v] || v;
  }

  // ---- card selection helpers ----
  function bindCards(selector, stateKey, afterFn) {
    document.querySelectorAll(selector).forEach(card => {
      card.addEventListener('click', () => {
        document.querySelectorAll(selector).forEach(c => c.classList.remove('era-sel','tp-sel','opt-sel'));
        card.classList.add(selector.includes('era') ? 'era-sel' : (selector.includes('team') ? 'tp-sel' : 'opt-sel'));
        const inp = card.querySelector('input[type=radio]');
        if (inp) { inp.checked = true; state[stateKey] = inp.value; }
        if (afterFn) afterFn(card, inp);
      });
    });
  }

  // ---- times da era + busca ----
  // Cada cartão diz em quais eras o time já existe (data-eras); os outros entram na
  // liga depois, por expansão, e não podem ser escolhidos no começo daquela era.
  function inEra(card) {
    return !state.era || (' ' + (card.dataset.eras || '') + ' ').includes(' ' + state.era + ' ');
  }
  function filterTeams() {
    const input = document.getElementById('teamSearch');
    const q = input ? input.value.toLowerCase() : '';
    let count = 0;
    document.querySelectorAll('.team-pick-card').forEach(c => {
      const ok = inEra(c);
      if (ok) count++;
      c.hidden = !(ok && (c.dataset.search || '').toLowerCase().includes(q));
      if (!ok && c.classList.contains('tp-sel')) {
        c.classList.remove('tp-sel');
        const inp = c.querySelector('input[type=radio]');
        if (inp) inp.checked = false;
        state.team = ''; state.teamName = ''; state.teamCity = ''; state.teamColor = '';
      }
    });
    const others = document.getElementById('wizOthers');
    if (others && count) others.textContent = String(count - 1);
  }
  function bindTeamSearch() {
    const input = document.getElementById('teamSearch');
    if (input) input.addEventListener('input', filterTeams);
  }

  // ---- init ----
  function init() {
    // Open wizard on "new save" click
    document.querySelectorAll('[data-open-wizard]').forEach(btn => {
      btn.addEventListener('click', e => { e.preventDefault(); open(); });
    });
    // Close
    const closeBtn = document.getElementById('wizClose');
    if (closeBtn) closeBtn.addEventListener('click', close);
    // Press Esc to close
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

    // Era cards
    bindCards('.era-card', 'era', (card) => {
      state.eraName = card.dataset.eraName || '';
      state.eraYear = card.dataset.eraYear || '';
      filterTeams();
    });

    // Team cards
    bindCards('.team-pick-card', 'team', (card) => {
      state.teamName   = card.dataset.teamName  || '';
      state.teamCity   = card.dataset.teamCity  || '';
      state.teamColor  = card.dataset.teamColor || '#333';
    });
    bindTeamSearch();

    // Option cards (style, difficulty, potential)
    document.querySelectorAll('.opt-card').forEach(card => {
      card.addEventListener('click', () => {
        const group = card.closest('.opt-group');
        if (!group) return;
        group.querySelectorAll('.opt-card').forEach(c => c.classList.remove('opt-sel'));
        card.classList.add('opt-sel');
        const inp = card.querySelector('input[type=radio]');
        if (!inp) return;
        inp.checked = true;
        const key = inp.name;   // coach_style | difficulty | potential_type
        if (key === 'coach_style')    state.coachStyle   = inp.value;
        if (key === 'difficulty')     state.difficulty   = inp.value;
        if (key === 'potential_type') state.potentialType = inp.value;
      });
    });

    // Next / Prev buttons
    document.querySelectorAll('[data-wiz-next]').forEach(btn => {
      btn.addEventListener('click', () => {
        if (validate()) goTo(currentStep + 1);
      });
    });
    document.querySelectorAll('[data-wiz-prev]').forEach(btn => {
      btn.addEventListener('click', () => goTo(currentStep - 1));
    });

    // Set initial selections (first era, default style etc.)
    const firstEra = document.querySelector('.era-card');
    if (firstEra) firstEra.click();
    const defaultStyle  = document.querySelector('.opt-card[data-default]');
    if (defaultStyle) defaultStyle.click();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
