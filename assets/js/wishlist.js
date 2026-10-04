// ============================================================
// AURVIA - wishlist hearts (AJAX, falls back to normal submit)
// ============================================================

(function () {

    function setWishBadge(count) {
        var badge = document.getElementById('wishlistBadge');
        if (!badge) return;
        badge.textContent = count;
        badge.classList.toggle('d-none', !(count > 0));
    }

    function paint(form, inWishlist) {
        var btn = form.querySelector('button');
        var icon = form.querySelector('i.fa-heart');
        var label = form.querySelector('[data-wl-label]');

        if (btn) {
            btn.classList.toggle('active', inWishlist);
            btn.setAttribute('aria-label', inWishlist ? 'Remove from wishlist' : 'Add to wishlist');
        }
        if (icon) {
            icon.classList.toggle('fa-solid', inWishlist);
            icon.classList.toggle('fa-regular', !inWishlist);
        }
        if (label) {
            label.textContent = inWishlist ? 'Saved' : 'Save to wishlist';
        }
    }

    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form.classList || !form.classList.contains('js-wishlist-toggle')) return;
        if (!window.fetch) return;

        ev.preventDefault();

        var btn = form.querySelector('button');
        if (btn) btn.disabled = true;

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {

                if (data.redirect) {
                    window.location = data.redirect;
                    return;
                }

                if (typeof data.count !== 'undefined') setWishBadge(data.count);
                if (window.AurviaToast) {
                    AurviaToast(data.message, data.ok ? 'success' : 'danger');
                }

                if (data.ok) {
                    paint(form, !!data.inWishlist);

                    // on the wishlist page: remove the card
                    var col = form.closest('.wishlist-col');
                    if (col && !data.inWishlist) {
                        col.remove();
                        var total = document.getElementById('wishlistTotal');
                        if (total) total.textContent = document.querySelectorAll('.wishlist-col').length;
                        if (!document.querySelector('.wishlist-col')) window.location.reload();
                    }
                }
            })
            .catch(function () {
                form.classList.remove('js-wishlist-toggle');
                form.submit();
            })
            .finally(function () {
                if (btn) btn.disabled = false;
            });
    });
})();
