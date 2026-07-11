/* AutoCare Hub — Global UI helpers */

function closeModal() {
  const root = document.getElementById('modal-root');
  if (root) root.classList.remove('active');
}

function openModal(html) {
  const box = document.getElementById('modal-box');
  const root = document.getElementById('modal-root');
  if (!box || !root) return;
  box.innerHTML = html;
  root.classList.add('active');
}

function toast(msg, type = 'info') {
  const c = document.getElementById('toast-container');
  if (!c) return;
  const colors = {
    success: 'toast-success',
    error: 'toast-error',
    info: 'toast-info'
  };
  const el = document.createElement('div');
  el.className = `p-3 rounded-lg text-sm border shadow-lg toast-enter ${colors[type] || colors.info}`;
  el.textContent = msg;
  c.appendChild(el);
  setTimeout(() => {
    el.classList.add('toast-exit');
    setTimeout(() => el.remove(), 280);
  }, 3200);
}

function isSidebarCollapsed() {
  return document.documentElement.classList.contains('sidebar-collapsed');
}

function setSidebarCollapsed(collapsed) {
  document.documentElement.classList.toggle('sidebar-collapsed', !!collapsed);
  document.body.classList.toggle('sidebar-collapsed', !!collapsed);
  try {
    localStorage.setItem('ach-sidebar', collapsed ? 'collapsed' : 'open');
  } catch (e) { /* ignore */ }
}

function openMobileSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebar-overlay');
  if (!sidebar) return;
  sidebar.classList.add('is-open');
  sidebar.classList.remove('-translate-x-full');
  overlay?.classList.remove('hidden');
  overlay?.classList.add('is-visible');
}

function closeMobileSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebar-overlay');
  if (!sidebar) return;
  sidebar.classList.remove('is-open');
  sidebar.classList.add('-translate-x-full');
  overlay?.classList.add('hidden');
  overlay?.classList.remove('is-visible');
}

document.addEventListener('DOMContentLoaded', () => {
  // Sync collapsed class onto body for CSS that targets body
  if (document.documentElement.classList.contains('sidebar-collapsed')) {
    document.body.classList.add('sidebar-collapsed');
  }

  const themeToggle = document.getElementById('theme-toggle');
  if (themeToggle && typeof toggleTheme === 'function') {
    themeToggle.addEventListener('click', (e) => {
      e.preventDefault();
      toggleTheme();
    });
  }

  // Mobile drawer
  const menuToggle = document.getElementById('menu-toggle');
  const overlay = document.getElementById('sidebar-overlay');
  const sidebar = document.getElementById('sidebar');
  if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', () => {
      if (sidebar.classList.contains('is-open')) closeMobileSidebar();
      else openMobileSidebar();
    });
  }
  overlay?.addEventListener('click', closeMobileSidebar);

  // Desktop collapse
  const collapseBtn = document.getElementById('sidebar-collapse');
  collapseBtn?.addEventListener('click', () => {
    setSidebarCollapsed(!isSidebarCollapsed());
  });

  // Collapsible panels (details[data-collapse] or .collapsible)
  document.querySelectorAll('[data-collapsible]').forEach((wrap) => {
    const btn = wrap.querySelector('[data-collapse-toggle]');
    const body = wrap.querySelector('[data-collapse-body]');
    if (!btn || !body) return;
    btn.addEventListener('click', () => {
      const open = wrap.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      body.style.maxHeight = open ? body.scrollHeight + 'px' : '0px';
    });
    // default open
    if (wrap.classList.contains('is-open') || wrap.dataset.collapsible === 'open') {
      wrap.classList.add('is-open');
      body.style.maxHeight = body.scrollHeight + 'px';
    } else {
      body.style.maxHeight = '0px';
    }
  });

  // Animate stat cards in sequence
  document.querySelectorAll('.stat-card, .pipeline-item, .panel-card').forEach((el, i) => {
    el.style.animationDelay = Math.min(i * 40, 400) + 'ms';
    el.classList.add('fade-up');
  });

  // Auto-dismiss flash
  const flash = document.querySelector('.flash-banner');
  if (flash) {
    setTimeout(() => {
      flash.style.opacity = '0';
      flash.style.transform = 'translateY(-6px)';
      setTimeout(() => flash.remove(), 300);
    }, 5000);
  }
});

async function requestReceipt(historyId, baseUrl) {
  try {
    const res = await fetch(baseUrl + 'api/receipt.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ history_id: historyId })
    });
    const data = await res.json();
    if (data.success) {
      openModal(`
        <h3 class="text-lg font-bold app-heading mb-2">Receipt Generated</h3>
        <p class="text-sm app-muted mb-4">Receipt <strong class="text-accent">${data.receipt_no}</strong> has been created.</p>
        <div class="panel-card p-4 mb-4 text-sm">
          <p><span class="app-muted">Vehicle:</span> ${data.vehicle}</p>
          <p><span class="app-muted">Service:</span> ${data.service}</p>
          <p><span class="app-muted">Amount:</span> <span class="text-emerald-400 font-semibold">${data.amount}</span></p>
          <p><span class="app-muted">Date:</span> ${data.date}</p>
        </div>
        <div class="flex gap-3 justify-end">
          <button onclick="closeModal()" class="btn-secondary">Close</button>
          <a href="${data.download_url}" class="btn-primary" style="width:auto;padding:.5rem 1rem">View PDF</a>
        </div>`);
      toast('Receipt generated successfully', 'success');
    } else {
      toast(data.message || 'Failed to generate receipt', 'error');
    }
  } catch (e) {
    toast('Network error', 'error');
  }
}
