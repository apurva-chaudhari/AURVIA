// ============================================================
// AURVIA - cart interactions
//  - AJAX "Add to cart" (falls back to normal form submit)
//  - quantity steppers
//  - navbar badge + toast
// ============================================================

(function () {

    function toast(message, type) {
        var box = document.getElementById('aurviaToasts');
        if (!box || !window.bootstrap) return;

        var el = document.createElement('div');
        el.className = 'toast align-items-center text-bg-' + (type || 'dark') + ' border-0';
        el.setAttribute('role', 'alert');

        var wrap = document.createElement('div');
        wrap.className = 'd-flex';

        var body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = message;          // textContent: no HTML injection

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close btn-close-white me-2 m-auto';
        close.setAttribute('data-bs-dismiss', 'toast');

        wrap.appendChild(body);
        wrap.appendChild(close);
        el.appendChild(wrap);
        box.appendChild(el);

        var t = new bootstrap.Toast(el, { delay: 3500 });
        t.show();
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    }

    function setBadge(count) {
        var badge = document.getElementById('cartBadge');
        if (!badge) return;
        badge.textContent = count;
        badge.classList.toggle('d-none', !(count > 0));
    }

    // ---------- AJAX add to cart ----------
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form.classList || !form.classList.contains('js-add-to-cart')) return;
        if (!window.fetch) return;                       // normal submit

        ev.preventDefault();

        var btn = form.querySelector('button[type=submit]');
        if (btn) btn.disabled = true;

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.redirect) {                     // not logged in
                    window.location = data.redirect;
                    return;
                }
                if (typeof data.cartCount !== 'undefined') setBadge(data.cartCount);
                toast(data.message, data.ok ? 'success' : 'danger');
            })
            .catch(function () {
                form.classList.remove('js-add-to-cart'); // fallback
                form.submit();
            })
            .finally(function () {
                if (btn) btn.disabled = false;
            });
    });

    // ---------- quantity steppers ----------
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-qty-step]');
        if (!btn) return;

        var box = btn.closest('.qty-box');
        var input = box.querySelector('input');
        var min = parseInt(input.min || '1', 10);
        var max = parseInt(input.max || '10', 10);
        var val = parseInt(input.value || '1', 10) || min;

        val += parseInt(btn.dataset.qtyStep, 10);
        val = Math.max(min, Math.min(max, val));
        input.value = val;

        // cart page: submit automatically
        if (input.form && input.form.classList.contains('js-cart-update')) {
            input.form.submit();
        }
    });

    // cart page: typing a quantity then pressing Enter / leaving field
    document.addEventListener('change', function (ev) {
        var input = ev.target;
        if (input.matches && input.matches('.js-cart-update .qty-box input')) {
            var min = parseInt(input.min || '1', 10);
            var max = parseInt(input.max || '10', 10);
            var v = parseInt(input.value, 10);
            if (isNaN(v)) v = min;
            input.value = Math.max(min, Math.min(max, v));
            input.form.submit();
        }
    });

    window.AurviaToast = toast;
})();
