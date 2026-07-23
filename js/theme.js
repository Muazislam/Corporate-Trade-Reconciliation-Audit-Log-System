/* ============================================================
   theme.js — Dark mode toggle
   ============================================================ */

(function () {
  var KEY = 'ledgerchain_theme';

  function setAttr(theme) {
    document.documentElement.setAttribute('data-theme', theme);
  }

  function getAttr() {
    return document.documentElement.getAttribute('data-theme');
  }

  // Restore saved preference
  try {
    var saved = localStorage.getItem(KEY);
    if (saved === 'dark') setAttr('dark');
  } catch (e) {}

  var btn = document.getElementById('themeToggle');
  if (!btn) return;

  function updateLabel() {
    btn.textContent = getAttr() === 'dark' ? '\u2600\uFE0F' : '\uD83C\uDF19';
  }

  btn.addEventListener('click', function () {
    var next = getAttr() === 'dark' ? 'light' : 'dark';
    setAttr(next);
    try { localStorage.setItem(KEY, next); } catch (e) {}
    updateLabel();
  });

  updateLabel();
})();
