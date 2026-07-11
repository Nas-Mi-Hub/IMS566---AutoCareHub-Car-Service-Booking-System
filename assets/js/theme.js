/* AutoCare Hub — Theme toggle */
(function () {
  function getTheme() {
    return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
  }

  function updateThemeIcons() {
    var isDark = getTheme() === 'dark';
    var sun = document.getElementById('theme-icon-sun');
    var moon = document.getElementById('theme-icon-moon');
    if (sun) {
      sun.style.display = isDark ? 'block' : 'none';
    }
    if (moon) {
      moon.style.display = isDark ? 'none' : 'block';
    }
  }

  function setTheme(theme) {
    if (theme !== 'light' && theme !== 'dark') theme = 'dark';
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.style.colorScheme = theme;
    try {
      localStorage.setItem('ach-theme', theme);
    } catch (e) { /* ignore */ }
    updateThemeIcons();
    window.dispatchEvent(new CustomEvent('themechange', { detail: { theme: theme } }));
  }

  function toggleTheme() {
    setTheme(getTheme() === 'dark' ? 'light' : 'dark');
  }

  function initTheme() {
    var saved = 'dark';
    try {
      saved = localStorage.getItem('ach-theme') || 'dark';
    } catch (e) { /* ignore */ }
    if (saved !== 'light' && saved !== 'dark') saved = 'dark';
    setTheme(saved);
  }

  window.getTheme = getTheme;
  window.setTheme = setTheme;
  window.toggleTheme = toggleTheme;
  window.updateThemeIcons = updateThemeIcons;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTheme);
  } else {
    initTheme();
  }
})();