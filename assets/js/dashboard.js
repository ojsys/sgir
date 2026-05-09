/**
 * SGIR RIGS Feedback — dashboard.js
 * Admin dashboard JS: sidebar toggle, Canvas charts, misc UI.
 */

(function () {
  'use strict';

  /* ── Utility ───────────────────────────────────────────────────────────── */
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

  /* ══════════════════════════════════════════════════════════════════════════
     MOBILE SIDEBAR TOGGLE
  ══════════════════════════════════════════════════════════════════════════ */
  const sidebar  = document.getElementById('sidebar');
  const overlay  = document.getElementById('sidebarOverlay');
  const menuBtn  = document.getElementById('mobileMenuBtn');

  function openSidebar() {
    if (!sidebar) return;
    sidebar.classList.add('open');
    if (overlay) { overlay.classList.add('visible'); }
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('open');
    if (overlay) { overlay.classList.remove('visible'); }
    document.body.style.overflow = '';
  }

  if (menuBtn) menuBtn.addEventListener('click', openSidebar);
  if (overlay) overlay.addEventListener('click', closeSidebar);

  // Close on Escape
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeSidebar();
  });

  /* ══════════════════════════════════════════════════════════════════════════
     AUTO-DISMISS ALERTS
  ══════════════════════════════════════════════════════════════════════════ */
  const pageAlert = document.getElementById('pageAlert');
  if (pageAlert) {
    setTimeout(() => {
      pageAlert.style.transition = 'opacity 0.5s, transform 0.5s';
      pageAlert.style.opacity    = '0';
      pageAlert.style.transform  = 'translateY(-8px)';
      setTimeout(() => pageAlert.remove(), 500);
    }, 4000);
  }

  /* ══════════════════════════════════════════════════════════════════════════
     FILTER FORM AUTO-SUBMIT ON SELECT CHANGE
  ══════════════════════════════════════════════════════════════════════════ */
  $$('.auto-submit').forEach(el => {
    el.addEventListener('change', () => {
      el.closest('form')?.submit();
    });
  });

  /* ══════════════════════════════════════════════════════════════════════════
     CANVAS CHARTS
  ══════════════════════════════════════════════════════════════════════════ */

  /**
   * Draw a bar chart on a canvas element.
   * @param {string}   canvasId
   * @param {string[]} labels
   * @param {number[]} data
   * @param {string|string[]} color  CSS color or array of colors
   */
  function drawBarChart(canvasId, labels, data, color = '#44B944') {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx    = canvas.getContext('2d');
    const W      = canvas.parentElement.clientWidth || canvas.width;
    const H      = canvas.height;
    canvas.width = W;

    const PAD   = { top: 20, right: 16, bottom: 44, left: 42 };
    const chartW = W - PAD.left - PAD.right;
    const chartH = H - PAD.top  - PAD.bottom;
    const maxVal = Math.max(...data, 1);
    const n      = data.length;
    const barW   = Math.max(8, (chartW / n) * 0.62);
    const gap    = (chartW / n) - barW;

    ctx.clearRect(0, 0, W, H);

    // Grid lines
    const gridLines = 4;
    ctx.strokeStyle = 'rgba(0,0,0,0.06)';
    ctx.lineWidth   = 1;
    for (let i = 0; i <= gridLines; i++) {
      const y = PAD.top + chartH - (i / gridLines) * chartH;
      ctx.beginPath();
      ctx.moveTo(PAD.left, y);
      ctx.lineTo(PAD.left + chartW, y);
      ctx.stroke();

      // Y label
      ctx.fillStyle  = '#94a3b8';
      ctx.font       = '11px Inter, sans-serif';
      ctx.textAlign  = 'right';
      ctx.fillText(Math.round((i / gridLines) * maxVal), PAD.left - 6, y + 4);
    }

    // Bars
    data.forEach((val, i) => {
      const barH  = (val / maxVal) * chartH;
      const x     = PAD.left + i * (barW + gap) + gap / 2;
      const y     = PAD.top + chartH - barH;
      const c     = Array.isArray(color) ? color[i % color.length] : color;

      // Bar with rounded top
      const r = Math.min(4, barW / 2, barH);
      ctx.fillStyle = c;
      ctx.beginPath();
      ctx.moveTo(x + r, y);
      ctx.lineTo(x + barW - r, y);
      ctx.quadraticCurveTo(x + barW, y, x + barW, y + r);
      ctx.lineTo(x + barW, y + barH);
      ctx.lineTo(x, y + barH);
      ctx.lineTo(x, y + r);
      ctx.quadraticCurveTo(x, y, x + r, y);
      ctx.closePath();
      ctx.fill();

      // Value on bar
      if (val > 0) {
        ctx.fillStyle  = '#1e293b';
        ctx.font       = 'bold 11px Inter, sans-serif';
        ctx.textAlign  = 'center';
        ctx.fillText(val, x + barW / 2, y - 6);
      }

      // X label
      const label = labels[i] || '';
      ctx.fillStyle = '#64748b';
      ctx.font      = '11px Inter, sans-serif';
      ctx.textAlign = 'center';
      // Truncate label
      const maxLen = Math.floor(barW / 6) + 2;
      const short  = label.length > maxLen ? label.substring(0, maxLen) + '…' : label;
      ctx.fillText(short, x + barW / 2, PAD.top + chartH + 20);
    });
  }

  /**
   * Draw a line chart with gradient area fill.
   * @param {string}   canvasId
   * @param {string[]} labels
   * @param {number[]} data
   * @param {string}   lineColor
   */
  function drawLineChart(canvasId, labels, data, lineColor = '#44B944') {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx    = canvas.getContext('2d');
    const W      = canvas.parentElement.clientWidth || canvas.width;
    const H      = canvas.height;
    canvas.width = W;

    const PAD   = { top: 24, right: 20, bottom: 44, left: 42 };
    const chartW = W - PAD.left - PAD.right;
    const chartH = H - PAD.top  - PAD.bottom;
    const maxVal = Math.max(...data, 1);
    const n      = data.length;

    ctx.clearRect(0, 0, W, H);

    // Grid
    const gridLines = 4;
    ctx.strokeStyle = 'rgba(0,0,0,0.06)';
    ctx.lineWidth   = 1;
    for (let i = 0; i <= gridLines; i++) {
      const y = PAD.top + chartH - (i / gridLines) * chartH;
      ctx.beginPath();
      ctx.moveTo(PAD.left, y); ctx.lineTo(PAD.left + chartW, y);
      ctx.stroke();
      ctx.fillStyle  = '#94a3b8';
      ctx.font       = '11px Inter, sans-serif';
      ctx.textAlign  = 'right';
      ctx.fillText(Math.round((i / gridLines) * maxVal), PAD.left - 6, y + 4);
    }

    // Compute points
    const pts = data.map((val, i) => ({
      x: PAD.left + (i / (n - 1 || 1)) * chartW,
      y: PAD.top + chartH - (val / maxVal) * chartH,
    }));

    // Area fill
    const grad = ctx.createLinearGradient(0, PAD.top, 0, PAD.top + chartH);
    grad.addColorStop(0, hexToRgba(lineColor, 0.25));
    grad.addColorStop(1, hexToRgba(lineColor, 0.02));

    ctx.beginPath();
    ctx.moveTo(pts[0].x, PAD.top + chartH);
    pts.forEach(p => ctx.lineTo(p.x, p.y));
    ctx.lineTo(pts[pts.length - 1].x, PAD.top + chartH);
    ctx.closePath();
    ctx.fillStyle = grad;
    ctx.fill();

    // Line
    ctx.beginPath();
    pts.forEach((p, i) => i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y));
    ctx.strokeStyle = lineColor;
    ctx.lineWidth   = 2.5;
    ctx.lineJoin    = 'round';
    ctx.stroke();

    // Dots
    pts.forEach((p, i) => {
      ctx.beginPath();
      ctx.arc(p.x, p.y, 3.5, 0, Math.PI * 2);
      ctx.fillStyle   = lineColor;
      ctx.strokeStyle = '#fff';
      ctx.lineWidth   = 2;
      ctx.fill();
      ctx.stroke();
    });

    // X labels (show every N-th label to avoid overcrowding)
    const skip = Math.ceil(n / 8);
    labels.forEach((lbl, i) => {
      if (i % skip !== 0 && i !== n - 1) return;
      ctx.fillStyle  = '#94a3b8';
      ctx.font       = '10px Inter, sans-serif';
      ctx.textAlign  = 'center';
      ctx.fillText(lbl, pts[i].x, PAD.top + chartH + 18);
    });
  }

  function hexToRgba(hex, alpha) {
    hex = hex.replace('#', '');
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
    const r = parseInt(hex.slice(0, 2), 16);
    const g = parseInt(hex.slice(2, 4), 16);
    const b = parseInt(hex.slice(4, 6), 16);
    return `rgba(${r},${g},${b},${alpha})`;
  }

  /* ── Specific chart init functions ─────────────────────────────────────── */
  function drawTrendChart() {
    const cd = window.chartData?.trend;
    if (!cd) return;
    drawLineChart('trendChart', cd.labels, cd.data, '#44B944');
  }

  function drawCategoryChart() {
    const cd = window.chartData?.category;
    if (!cd) return;
    drawBarChart('categoryChart', cd.labels, cd.data, ['#22c55e', '#3b82f6', '#ef4444']);
  }

  function drawDeptChart() {
    const cd = window.chartData?.dept;
    if (!cd) return;
    drawBarChart('deptChart', cd.labels, cd.data, '#1B3A1B');
  }

  function drawRatingChart() {
    const cd = window.chartData?.rating;
    if (!cd) return;
    const colors = ['#ef4444','#f97316','#f59e0b','#84cc16','#22c55e'];
    drawBarChart('ratingChart', cd.labels, cd.data, colors);
  }

  function initAllCharts() {
    if (!window.chartData) return;
    drawTrendChart();
    drawCategoryChart();
    drawDeptChart();
    drawRatingChart();
  }

  // Init charts after DOM ready + small delay for layout
  if (window.chartData) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => setTimeout(initAllCharts, 80));
    } else {
      setTimeout(initAllCharts, 80);
    }

    // Re-draw on resize
    let resizeTimer;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(initAllCharts, 200);
    });
  }

  /* ══════════════════════════════════════════════════════════════════════════
     STATUS BADGE REFRESH
     (When admin changes status radio in detail page the badge refreshes)
  ══════════════════════════════════════════════════════════════════════════ */
  $$('input[name="status"]').forEach(radio => {
    radio.addEventListener('change', function () {
      const badges = $$('.badge-new, .badge-reviewed, .badge-actioned');
      badges.forEach(b => {
        if (b.closest('.card-header')) {
          b.className = 'badge badge-' + this.value;
          b.textContent = this.value.charAt(0).toUpperCase() + this.value.slice(1);
        }
      });
    });
  });

})();
