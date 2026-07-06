// ============================================================
// SisCartera Universidad - app.js
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

    // ── Menu toggle (mobile) ──────────────────────────────────
    const menuBtn = document.getElementById('menuToggle');
    const sidebar  = document.getElementById('sidebar');
    if (menuBtn && sidebar) {
        menuBtn.addEventListener('click', () => sidebar.classList.toggle('open'));
        document.addEventListener('click', e => {
            if (!sidebar.contains(e.target) && !menuBtn.contains(e.target))
                sidebar.classList.remove('open');
        });
    }

    // ── Alert dismiss ─────────────────────────────────────────
    document.querySelectorAll('.alert-close').forEach(btn => {
        btn.addEventListener('click', () => btn.closest('.alert').remove());
    });

    // Auto-dismiss alerts after 5 seconds
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        setTimeout(() => {
            if (alert.parentNode) {
                alert.style.transition = 'opacity .4s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 400);
            }
        }, 5000);
    });

    // ── Modal logic ───────────────────────────────────────────
    document.querySelectorAll('[data-modal]').forEach(trigger => {
        trigger.addEventListener('click', () => {
            const id = trigger.dataset.modal;
            const overlay = document.getElementById(id);
            if (overlay) overlay.classList.add('open');
        });
    });

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) overlay.classList.remove('open');
        });
    });

    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', () => btn.closest('.modal-overlay').classList.remove('open'));
    });

    // ── Formateo automático de moneda ────────────────────────
    document.querySelectorAll('.input-currency').forEach(input => {
        input.addEventListener('blur', () => {
            const raw = parseFloat(input.value.replace(/[^0-9.]/g, ''));
            if (!isNaN(raw)) {
                input.dataset.value = raw;
                input.value = new Intl.NumberFormat('es-CO', {minimumFractionDigits: 0}).format(raw);
            }
        });
        input.addEventListener('focus', () => {
            input.value = input.dataset.value || '';
        });
    });

    // ── Búsqueda con debounce ─────────────────────────────────
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        let timer;
        searchInput.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                const url = new URL(window.location);
                url.searchParams.set('q', searchInput.value);
                url.searchParams.set('pagina', 1);
                window.location = url.toString();
            }, 500);
        });
    }

    // ── Confirmaciones ────────────────────────────────────────
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', e => {
            if (!confirm(el.dataset.confirm)) e.preventDefault();
        });
    });

    // ── AJAX para formularios con data-ajax ───────────────────
    document.querySelectorAll('form[data-ajax]').forEach(form => {
        form.addEventListener('submit', async e => {
            e.preventDefault();
            const btn = form.querySelector('[type=submit]');
            if (btn) { btn.disabled = true; btn.innerHTML += ' <i class="fas fa-spinner fa-spin"></i>'; }

            try {
                const res = await fetch(form.action, { method: 'POST', body: new FormData(form) });
                const data = await res.json();

                if (data.ok) {
                    showToast(data.mensaje, 'success');
                    if (data.redirect) window.location = data.redirect;
                    if (data.reload) window.location.reload();
                    form.reset();
                } else {
                    showToast(data.mensaje, 'error');
                }
            } catch (err) {
                showToast('Error de comunicación con el servidor', 'error');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = btn.innerHTML.replace(/ <i class="fas fa-spinner fa-spin"><\/i>/, '');
                }
            }
        });
    });

    // ── Toast notifications ───────────────────────────────────
    window.showToast = function(message, type = 'info') {
        const container = document.getElementById('toastContainer') || createToastContainer();
        const toast = document.createElement('div');
        const icons = { success: 'check-circle', error: 'times-circle', warning: 'exclamation-triangle', info: 'info-circle' };
        const colors = { success: '#16a34a', error: '#dc2626', warning: '#d97706', info: '#0284c7' };

        toast.style.cssText = `
            background: white; border-left: 4px solid ${colors[type] || colors.info};
            border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,.14);
            padding: .8rem 1rem; display: flex; align-items: center; gap: .6rem;
            font-size: .88rem; min-width: 280px; max-width: 420px;
            animation: toastIn .3s ease; cursor: pointer;
        `;
        toast.innerHTML = `<i class="fas fa-${icons[type] || 'info-circle'}" style="color:${colors[type]}"></i>${message}`;
        toast.addEventListener('click', () => toast.remove());
        container.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 400); }, 4000);
    };

    function createToastContainer() {
        const c = document.createElement('div');
        c.id = 'toastContainer';
        c.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:999;display:flex;flex-direction:column;gap:.5rem;';
        document.body.appendChild(c);
        const style = document.createElement('style');
        style.textContent = '@keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}';
        document.head.appendChild(style);
        return c;
    }
});

// ── Helpers globales ──────────────────────────────────────────
function formatPeso(valor) {
    return '$ ' + new Intl.NumberFormat('es-CO').format(valor);
}

function confirmarEliminar(url, nombre) {
    if (confirm(`¿Está seguro que desea eliminar "${nombre}"? Esta acción no se puede deshacer.`)) {
        window.location = url;
    }
}
