/* HanzeStatus — gedeelde front-end interactiviteit. Vanilla JS, geen dependencies. */
(function () {
  "use strict";
  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }

  function csrfToken() {
    var meta = qs('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }
  window.hsCsrfToken = csrfToken;

  window.hsToast = function (message, type) {
    var wrap = qs('#hsToastWrap');
    if (!wrap) return;
    var toast = document.createElement('div');
    toast.className = 'hs-toast hs-toast--' + (type || 'success');
    toast.textContent = message;
    wrap.appendChild(toast);
    setTimeout(function () {
      toast.style.opacity = '0';
      toast.style.transition = 'opacity .2s';
      setTimeout(function () { toast.remove(); }, 200);
    }, 3500);
  };

  window.hsSetLoading = function (btn, loading) {
    if (!btn) return;
    btn.classList.toggle('hs-btn--loading', !!loading);
    btn.disabled = !!loading;
  };

  /** CSRF-aware fetch helper: stuurt X-CSRF-Token mee, verwacht JSON terug. */
  window.hsFetch = function (url, options) {
    options = options || {};
    options.headers = Object.assign({ 'X-CSRF-Token': csrfToken() }, options.headers || {});
    return fetch(url, options).then(function (r) { return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; }); });
  };

  /* --- Dropdowns (notificaties/gebruikersmenu) --- */
  function closeAllDropdowns(except) {
    qsa('.hs-dropdown.hs-is-open').forEach(function (d) {
      if (d !== except) {
        d.classList.remove('hs-is-open');
        var trigger = document.querySelector('[aria-controls="' + d.id + '"]') || d.previousElementSibling;
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
      }
    });
  }

  function initUserDropdown() {
    var btn = qs('#hsUserBtn');
    var panel = qs('#hsUserPanel');
    if (btn && panel) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var willOpen = !panel.classList.contains('hs-is-open');
        closeAllDropdowns();
        panel.classList.toggle('hs-is-open', willOpen);
        btn.setAttribute('aria-expanded', String(willOpen));
      });
    }
  }

  function renderNotifications(notifications) {
    var panel = qs('#hsNotifPanel');
    if (!panel) return;
    if (!notifications.length) {
      panel.innerHTML = '<div style="padding:1.5rem;text-align:center;color:var(--hs-text-muted);font-size:.85rem;">Geen notificaties</div>';
      return;
    }
    panel.innerHTML = notifications.map(function (n) {
      return '<a href="' + n.link + '" class="hs-notif-item ' + (n.read_at ? '' : 'hs-is-unread') + '">' +
        (n.read_at ? '' : '<span class="hs-notif-dot"></span>') +
        '<div><strong style="display:block;">' + n.title + '</strong>' + n.body + '<div style="color:var(--hs-text-muted);font-size:.72rem;margin-top:.2rem;">' + n.time_ago + '</div></div>' +
        '</a>';
    }).join('');
  }

  function initNotifDropdown() {
    var btn = qs('#hsNotifBtn');
    var panel = qs('#hsNotifPanel');
    if (!btn || !panel) return;
    var loaded = false;
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var willOpen = !panel.classList.contains('hs-is-open');
      closeAllDropdowns();
      panel.classList.toggle('hs-is-open', willOpen);
      btn.setAttribute('aria-expanded', String(willOpen));
      if (willOpen && !loaded) {
        loaded = true;
        fetch('api/notifications.php?actie=lijst')
          .then(function (r) { return r.json(); })
          .then(function (data) {
            renderNotifications(data.notifications || []);
            if (data.notifications && data.notifications.length) {
              fetch('api/notifications.php?actie=lees_alles', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken() } });
              var badge = document.querySelector('.hs-nav-badge');
              if (badge) badge.remove();
            }
          })
          .catch(function () { panel.innerHTML = '<div style="padding:1rem;color:var(--hs-down);font-size:.85rem;">Kon notificaties niet laden.</div>'; });
      }
    });
  }

  document.addEventListener('click', function () {
    closeAllDropdowns();
    var themePanel = qs('#hsThemePickerPanel');
    var themeBtn = qs('#hsThemePickerBtn');
    if (themePanel && themePanel.classList.contains('hs-is-open')) {
      themePanel.classList.remove('hs-is-open');
      if (themeBtn) themeBtn.setAttribute('aria-expanded', 'false');
    }
  });

  /* --- Modals --- */
  function initModals() {
    qsa('[data-hs-modal-open]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var modal = document.getElementById(btn.getAttribute('data-hs-modal-open'));
        if (modal) modal.classList.add('hs-is-open');
      });
    });
    qsa('[data-hs-modal-close]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var modal = btn.closest('.hs-modal-backdrop');
        if (modal) modal.classList.remove('hs-is-open');
      });
    });
    qsa('.hs-modal-backdrop').forEach(function (backdrop) {
      backdrop.addEventListener('click', function (e) { if (e.target === backdrop) backdrop.classList.remove('hs-is-open'); });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') backdrop.classList.remove('hs-is-open');
      });
    });
  }

  /* --- Zoeken met debounce (admin lijsten) --- */
  function initDebouncedSearch() {
    var input = qs('[data-hs-search-form]');
    if (!input) return;
    var form = input.closest('form');
    var timer = null;
    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { form.submit(); }, 450);
    });
  }

  /* --- Thema-kiezer (publieke statuspagina) — keuze onthouden per browser via
     localStorage, zodat een terugkerende bezoeker zijn/haar voorkeursthema behoudt. */
  var HS_THEME_KEY = 'hs-public-theme';
  var HS_THEME_LABELS = { '': 'Licht', 'dark': 'Donker', 'midnight': 'Middernacht', 'sunrise': 'Zonsopgang' };

  function applyTheme(theme) {
    if (theme) {
      document.documentElement.setAttribute('data-theme', theme);
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
    var label = qs('#hsThemePickerLabel');
    if (label) label.textContent = HS_THEME_LABELS[theme] || 'Thema';
    qsa('.hs-theme-option').forEach(function (opt) {
      opt.classList.toggle('hs-is-active', (opt.getAttribute('data-hs-theme') || '') === theme);
    });
  }

  function initThemePicker() {
    var btn = qs('#hsThemePickerBtn');
    var panel = qs('#hsThemePickerPanel');
    if (!btn || !panel) return;

    var saved = '';
    try { saved = localStorage.getItem(HS_THEME_KEY) || ''; } catch (e) { /* privémodus: geen opslag, val terug op Licht */ }
    applyTheme(saved);

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var willOpen = !panel.classList.contains('hs-is-open');
      closeAllDropdowns();
      panel.classList.toggle('hs-is-open', willOpen);
      btn.setAttribute('aria-expanded', String(willOpen));
    });

    qsa('.hs-theme-option').forEach(function (opt) {
      opt.addEventListener('click', function () {
        var theme = opt.getAttribute('data-hs-theme') || '';
        applyTheme(theme);
        try { localStorage.setItem(HS_THEME_KEY, theme); } catch (e) { /* privémodus: keuze geldt alleen voor deze paginaweergave */ }
        panel.classList.remove('hs-is-open');
        btn.setAttribute('aria-expanded', 'false');
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initUserDropdown();
    initNotifDropdown();
    initModals();
    initDebouncedSearch();
    initThemePicker();
  });
})();
