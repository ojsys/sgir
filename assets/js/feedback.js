/**
 * SGIR RIGS Feedback — feedback.js
 * Public portal JS: multi-step forms, star rating, AJAX submission, etc.
 */

(function () {
  'use strict';

  /* ── Constants ─────────────────────────────────────────────────────────── */
  const FORM_TYPE  = window.FORM_TYPE  || 'general';
  const BASE_URL   = window.BASE_URL   || '';
  const CSRF_TOKEN = window.CSRF_TOKEN || '';

  /* ── Utility: $, $$ ────────────────────────────────────────────────────── */
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

  /* ══════════════════════════════════════════════════════════════════════════
     DEPARTMENT CARD RIPPLE (index.php)
  ══════════════════════════════════════════════════════════════════════════ */
  $$('.dept-card').forEach(card => {
    card.addEventListener('click', function (e) {
      const ripple = $('.dept-card-ripple', this);
      if (!ripple) return;
      const rect = this.getBoundingClientRect();
      const x    = e.clientX - rect.left;
      const y    = e.clientY - rect.top;
      ripple.style.cssText = `
        left: ${x}px; top: ${y}px;
        width: 200px; height: 200px;
        margin-left: -100px; margin-top: -100px;
        opacity: 0.5;
        transition: width 0.4s ease, height 0.4s ease, opacity 0.4s ease;
      `;
      setTimeout(() => {
        ripple.style.width   = '400px';
        ripple.style.height  = '400px';
        ripple.style.opacity = '0';
      }, 10);
    });
  });

  /* ══════════════════════════════════════════════════════════════════════════
     MULTI-STEP FORM LOGIC
  ══════════════════════════════════════════════════════════════════════════ */
  const formId = {
    general: 'feedbackForm',
    safety:  'safetyForm',
    medical: 'medicalForm',
  }[FORM_TYPE];

  const form = formId ? document.getElementById(formId) : null;
  if (!form) return; // Not on a form page

  // Determine total steps
  const allSteps   = $$('.form-step', form);
  const totalSteps = allSteps.length;
  let   currentStep = 1;

  // Progress fill element
  const progressFill = document.getElementById('progressFill');

  function updateProgress(step) {
    const pct = Math.round((step / totalSteps) * 100);
    if (progressFill) progressFill.style.width = pct + '%';

    $$('.step-dot').forEach(dot => {
      const n = parseInt(dot.dataset.step);
      dot.classList.toggle('active',    n === step);
      dot.classList.toggle('completed', n < step);
    });
  }

  function showStep(n) {
    allSteps.forEach(s => s.classList.remove('active'));
    const target = document.getElementById('step' + n);
    if (target) {
      target.classList.add('active');
      currentStep = n;
      updateProgress(n);
      target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  // Next buttons
  $$('.btn-next', form).forEach(btn => {
    btn.addEventListener('click', function () {
      const next = parseInt(this.dataset.next);
      if (validateStep(currentStep)) {
        showStep(next);
      }
    });
  });

  // Prev buttons
  $$('.btn-prev', form).forEach(btn => {
    btn.addEventListener('click', function () {
      const prev = parseInt(this.dataset.prev);
      showStep(prev);
    });
  });

  /* ── Validation ─────────────────────────────────────────────────────────── */
  function validateStep(step) {
    clearErrors();

    if (FORM_TYPE === 'general') {
      if (step === 1) {
        const rating = $('#ratingInput', form);
        if (!rating || !rating.value) {
          showError('ratingError', 'Please select a rating.');
          return false;
        }
      }
      if (step === 2) {
        const cat = $('#categoryInput', form);
        if (!cat || !cat.value) {
          showError('categoryError', 'Please select a feedback category.');
          return false;
        }
      }
      if (step === 3) {
        const msg = $('#messageInput', form);
        if (!msg || msg.value.trim().length < 10) {
          showError('messageError', 'Message must be at least 10 characters.');
          return false;
        }
      }
    }

    if (FORM_TYPE === 'safety') {
      if (step === 1) {
        const task = form.querySelector('input[name="task_activity"]');
        const area = form.querySelector('input[name="work_area"]');
        let ok = true;
        if (!task || !task.value.trim()) { showError('taskError', 'Task / activity is required.'); ok = false; }
        if (!area || !area.value.trim()) { showError('areaError', 'Work area is required.'); ok = false; }
        return ok;
      }
      if (step === 3) {
        const checked = $$('input[type="checkbox"]:checked', form);
        const obsTypes = ['stop_work_authority','is_safe','unsafe_act','unsafe_condition','near_miss'];
        const anyChecked = obsTypes.some(n => form.querySelector(`input[name="${n}"]:checked`));
        if (!anyChecked) { showError('obsTypeError', 'Please select at least one observation type.'); return false; }
      }
      if (step === 4) {
        const obs = form.querySelector('textarea[name="safety_observation"]');
        if (!obs || obs.value.trim().length < 10) { showError('safetyObsError', 'Please describe the safety observation (min 10 chars).'); return false; }
      }
    }

    if (FORM_TYPE === 'medical') {
      if (step === 1) {
        const vd = form.querySelector('input[name="visit_date"]');
        const vr = form.querySelector('input[name="visit_reason"]:checked');
        let ok = true;
        if (!vd || !vd.value) { showError('visitDateError', 'Visit date is required.'); ok = false; }
        if (!vr) { /* reason not strictly required for flow */ }
        return ok;
      }
    }

    return true;
  }

  function showError(id, msg) {
    const el = document.getElementById(id);
    if (el) { el.textContent = msg; el.style.display = 'block'; }
  }

  function clearErrors() {
    $$('.field-error', form).forEach(el => { el.textContent = ''; el.style.display = ''; });
  }

  /* ── Star Rating (general form) ─────────────────────────────────────────── */
  const starGroup = document.getElementById('starGroup');
  const ratingInput = document.getElementById('ratingInput');

  if (starGroup && ratingInput) {
    const stars = $$('.star-btn', starGroup);

    function setStars(val, hover = false) {
      stars.forEach(s => {
        const sv = parseInt(s.dataset.value);
        s.classList.toggle(hover ? 'hovered' : 'selected', sv <= val);
        if (!hover) s.classList.remove('hovered');
      });
    }

    stars.forEach(star => {
      star.addEventListener('mouseenter', () => setStars(parseInt(star.dataset.value), true));
      star.addEventListener('click', () => {
        ratingInput.value = star.dataset.value;
        setStars(parseInt(star.dataset.value));
        stars.forEach(s => s.classList.remove('hovered'));
      });
    });

    starGroup.addEventListener('mouseleave', () => {
      stars.forEach(s => s.classList.remove('hovered'));
    });
  }

  /* ── Category chips (general form) ─────────────────────────────────────── */
  const categoryInput = document.getElementById('categoryInput');
  $$('.cat-chip', form).forEach(chip => {
    chip.addEventListener('click', function () {
      $$('.cat-chip', form).forEach(c => c.classList.remove('selected'));
      this.classList.add('selected');
      if (categoryInput) categoryInput.value = this.dataset.value;
    });
  });

  /* ── Textarea char counter ──────────────────────────────────────────────── */
  const msgInput    = document.getElementById('messageInput');
  const charCounter = document.getElementById('charCounter');
  if (msgInput && charCounter) {
    msgInput.addEventListener('input', () => {
      const len = msgInput.value.length;
      charCounter.textContent = len + ' / ' + (msgInput.maxLength || 2000);
      charCounter.style.color = len > (msgInput.maxLength * 0.9) ? '#ef4444' : '#94a3b8';
    });
  }

  /* ── Anonymous toggle ───────────────────────────────────────────────────── */
  const anonToggle   = document.getElementById('anonToggle');
  const contactFields = document.getElementById('contactFields');
  if (anonToggle && contactFields) {
    function updateAnonUI() {
      if (anonToggle.checked) {
        contactFields.style.display = 'none';
      } else {
        contactFields.style.display = 'block';
        contactFields.style.animation = 'slideDown 0.3s ease forwards';
      }
    }
    anonToggle.addEventListener('change', updateAnonUI);
    updateAnonUI();
  }

  /* ── Safety: SWA warning banner ─────────────────────────────────────────── */
  const swaCheck   = document.getElementById('swaCheck');
  const swaWarning = document.getElementById('swaWarning');
  if (swaCheck && swaWarning) {
    swaCheck.addEventListener('change', () => {
      swaWarning.style.display = swaCheck.checked ? 'flex' : 'none';
    });
  }

  /* ── Safety: observation status chips ──────────────────────────────────── */
  const statusInput = document.getElementById('obsStatusInput');
  $$('.status-chip').forEach(chip => {
    chip.addEventListener('click', function () {
      $$('.status-chip').forEach(c => c.classList.remove('active'));
      this.classList.add('active');
      if (statusInput) statusInput.value = this.dataset.value;
    });
  });

  /* ── Medical: show "other" reason field ─────────────────────────────────── */
  const visitReasonOtherWrap = document.getElementById('visitReasonOtherWrap');
  $$('input[name="visit_reason"]', form).forEach(radio => {
    radio.addEventListener('change', function () {
      if (visitReasonOtherWrap) {
        visitReasonOtherWrap.style.display = this.value === 'other' ? 'block' : 'none';
      }
    });
  });

  /* ── yes/no button visual feedback ─────────────────────────────────────── */
  $$('.yesno-btn input', form).forEach(radio => {
    radio.addEventListener('change', function () {
      const group = this.closest('.yesno-row');
      if (!group) return;
      $$('.yesno-btn', group).forEach(btn => btn.classList.remove('active'));
      this.closest('.yesno-btn').classList.add('active');
    });
  });

  /* ── AJAX Form Submission ───────────────────────────────────────────────── */
  const submitBtn  = document.getElementById('submitBtn');
  const formAlert  = document.getElementById('formAlert');

  const endpointMap = {
    general: BASE_URL + '/feedback/submit.php',
    safety:  BASE_URL + '/feedback/submit-safety.php',
    medical: BASE_URL + '/feedback/submit-medical.php',
  };

  if (form) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      clearErrors();

      if (!validateStep(currentStep)) return;

      // Loading state
      if (submitBtn) {
        submitBtn.disabled = true;
        const txt = submitBtn.querySelector('.btn-text');
        const spin = submitBtn.querySelector('.btn-spinner');
        if (txt)  txt.textContent  = 'Submitting…';
        if (spin) spin.style.display = 'inline';
      }

      if (formAlert) formAlert.style.display = 'none';

      const formData = new FormData(form);

      // Ensure CSRF token
      if (!formData.get('_token')) {
        formData.set('_token', CSRF_TOKEN);
      }

      // Anonymous checkbox handling
      const anon = document.getElementById('anonToggle');
      if (anon) {
        formData.set('is_anonymous', anon.checked ? '1' : '0');
      }

      try {
        const res = await fetch(endpointMap[FORM_TYPE], {
          method: 'POST',
          body: formData,
        });

        const data = await res.json();

        if (data.success) {
          window.location.href = data.redirect || (BASE_URL + '/feedback/success.php');
        } else {
          // Show errors
          if (data.errors) {
            const errorMap = {
              // general
              rating:    'ratingError',
              category:  'categoryError',
              message:   'messageError',
              email:     'messageError',
              // safety
              task_activity:      'taskError',
              work_area:          'areaError',
              safety_observation: 'safetyObsError',
              obs_type:           'obsTypeError',
              observer_name:      'observerNameError',
              observation_date:   'obsDateError',
              // medical
              visit_date:   'visitDateError',
              visit_reason: 'visitDateError',
            };
            let firstGoTo = null;
            Object.entries(data.errors).forEach(([field, msg]) => {
              const elId = errorMap[field];
              if (elId) {
                showError(elId, msg);
                if (!firstGoTo && field === 'rating')    firstGoTo = 1;
                if (!firstGoTo && field === 'category')  firstGoTo = 2;
                if (!firstGoTo && field === 'message')   firstGoTo = 3;
              }
            });
            if (firstGoTo) showStep(firstGoTo);
          }

          if (formAlert) {
            formAlert.textContent = data.message || 'Please fix the errors above.';
            formAlert.className   = 'alert-box error';
            formAlert.style.display = 'block';
          }
        }
      } catch (err) {
        console.error(err);
        if (formAlert) {
          formAlert.textContent = 'Network error. Please check your connection and try again.';
          formAlert.className   = 'alert-box error';
          formAlert.style.display = 'block';
        }
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          const txt  = submitBtn.querySelector('.btn-text');
          const spin = submitBtn.querySelector('.btn-spinner');
          if (txt)  txt.textContent   = FORM_TYPE === 'general' ? 'Submit Feedback' : 'Submit';
          if (spin) spin.style.display = 'none';
        }
      }
    });
  }

})();
