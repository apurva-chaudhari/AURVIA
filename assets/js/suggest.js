// ============================================================
// AURVIA - live search suggestions
// Usage: <input data-suggest> inside a normal form
// ============================================================

(function () {

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments, self = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    }

    function attach(input) {

        var wrap = document.createElement('div');
        wrap.className = 'suggest-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var box = document.createElement('div');
        box.className = 'suggest-box d-none';
        wrap.appendChild(box);

        input.setAttribute('autocomplete', 'off');

        var items = [];
        var active = -1;
        var lastQuery = null;

        function hide() { box.classList.add('d-none'); active = -1; }

        function head(text) {
            var h = document.createElement('div');
            h.className = 'suggest-head';
            h.textContent = text;
            return h;
        }

        function render(list, q) {

            box.innerHTML = '';
            items = [];
            active = -1;

            if (!list.length) { hide(); return; }

            var lastType = null;

            list.forEach(function (s) {

                if (s.type !== lastType) {
                    lastType = s.type;
                    box.appendChild(head(
                        s.type === 'product' ? 'Products' :
                        s.type === 'category' ? 'Categories' :
                        (q ? 'Searches' : 'Trending searches')
                    ));
                }

                var a = document.createElement('a');
                a.className = 'suggest-item';
                a.href = s.url;

                if (s.type === 'product' && s.image) {
                    var img = document.createElement('img');
                    img.src = s.image;
                    img.alt = '';
                    a.appendChild(img);
                } else {
                    var ic = document.createElement('span');
                    ic.className = 'suggest-icon';
                    ic.innerHTML = s.type === 'category'
                        ? '<i class="fa-solid fa-layer-group"></i>'
                        : s.type === 'search'
                            ? '<i class="fa-solid fa-arrow-trend-up"></i>'
                            : '<i class="fa-solid fa-cube"></i>';
                    a.appendChild(ic);
                }

                var text = document.createElement('div');
                var title = document.createElement('div');
                title.className = 'suggest-title';
                title.textContent = s.label;                  // textContent: XSS-safe
                text.appendChild(title);

                if (s.type === 'product') {
                    var sub = document.createElement('div');
                    sub.className = 'suggest-sub';
                    sub.textContent = s.brand + ' · ' + s.category + ' · ' + s.price;
                    text.appendChild(sub);
                }

                a.appendChild(text);
                box.appendChild(a);
                items.push(a);
            });

            box.classList.remove('d-none');
        }

        var load = debounce(function () {

            var q = input.value.trim();
            if (q === lastQuery) return;
            if (q.length === 1) { hide(); return; }
            lastQuery = q;

            fetch(window.AURVIA.baseUrl + 'api/product-suggestions.php?q=' + encodeURIComponent(q), {
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (input.value.trim() === q) render(data.suggestions || [], q);
                })
                .catch(hide);
        }, 220);

        input.addEventListener('input', load);
        input.addEventListener('focus', function () { lastQuery = null; load(); });

        input.addEventListener('keydown', function (e) {
            if (box.classList.contains('d-none') || !items.length) return;

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (active >= 0) items[active].classList.remove('active');
                active = e.key === 'ArrowDown'
                    ? (active + 1) % items.length
                    : (active - 1 + items.length) % items.length;
                items[active].classList.add('active');
            } else if (e.key === 'Enter' && active >= 0) {
                e.preventDefault();
                window.location = items[active].href;
            } else if (e.key === 'Escape') {
                hide();
            }
        });

        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) hide();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[data-suggest]').forEach(attach);
    });
})();
