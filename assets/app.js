/* 艾比图鉴 · 交互层
   —— 移动端把筛选面板做成底部抽屉：按下即响应、1:1 跟手、松手时按手指速度做动量投影，
      反向拖回立刻取消关闭（动画永远从当前屏幕值出发）。 */

(function () {
  'use strict';

  var body = document.body;
  var pane = document.querySelector('.pane');
  var btn = document.querySelector('.filter-btn');
  var scrim = document.querySelector('.scrim');
  if (!pane || !btn || !scrim) return;

  var phone = window.matchMedia('(max-width: 1100px)');
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  var lastFocus = null;

  function isOpen() {
    return body.getAttribute('data-sheet') === 'open';
  }

  function open() {
    if (!phone.matches) return;
    lastFocus = document.activeElement;
    body.setAttribute('data-sheet', 'open');
    btn.setAttribute('aria-expanded', 'true');
    pane.focus({ preventScroll: true });
  }

  function close() {
    body.removeAttribute('data-sheet');
    btn.setAttribute('aria-expanded', 'false');
    pane.style.transform = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
  }

  btn.addEventListener('click', function () {
    isOpen() ? close() : open();
  });

  scrim.addEventListener('click', close);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen()) close();
  });

  // 点击抽屉里的筛选项后自动收起，让结果立刻可见
  pane.addEventListener('click', function (e) {
    var link = e.target.closest && e.target.closest('a');
    if (link && isOpen()) close();
  });

  /* ---------------- 拖拽关闭：1:1 跟手 + 动量投影 ---------------- */

  var drag = null;

  function height() {
    return pane.getBoundingClientRect().height || 320;
  }

  // 指数衰减投影（Apple 在 Designing Fluid Interfaces 里给的式子）
  function project(v, d) {
    return (v / 1000) * d / (1 - d);
  }

  pane.addEventListener('pointerdown', function (e) {
    if (!phone.matches || reduce.matches) return;
    if (e.button !== undefined && e.button !== 0) return;
    // 只在抽屉顶部把手/标题区起手，避免和内部滚动打架
    var rect = pane.getBoundingClientRect();
    if (e.clientY - rect.top > 64) return;
    if (pane.scrollTop > 0) return;

    drag = { y0: e.clientY, dy: 0, t0: performance.now(), v: 0, lastY: e.clientY, lastT: performance.now() };
    pane.classList.add('dragging');
    pane.setPointerCapture(e.pointerId);
  });

  pane.addEventListener('pointermove', function (e) {
    if (!drag) return;
    var now = performance.now();
    var dt = Math.max(1, now - drag.lastT);
    drag.v = (e.clientY - drag.lastY) / dt; // px/ms
    drag.lastY = e.clientY;
    drag.lastT = now;
    drag.dy = Math.max(0, e.clientY - drag.y0); // 向上拖回弹到 0：软边界
    pane.style.transform = 'translateY(' + drag.dy + 'px)';
  });

  function end(e) {
    if (!drag) return;
    var dy = drag.dy;
    var v = drag.v; // >0 向下
    var h = height();
    drag = null;
    pane.classList.remove('dragging');
    if (pane.hasPointerCapture && e && e.pointerId !== undefined && pane.hasPointerCapture(e.pointerId)) {
      pane.releasePointerCapture(e.pointerId);
    }
    pane.style.transform = '';

    var projected = dy + project(v * 1000, 0.998);
    if (projected > h * 0.28 || (v > 0.5 && dy > 24)) {
      close();
    }
  }

  pane.addEventListener('pointerup', end);
  pane.addEventListener('pointercancel', end);

  phone.addEventListener('change', function () {
    if (!phone.matches) close();
  });
})();
