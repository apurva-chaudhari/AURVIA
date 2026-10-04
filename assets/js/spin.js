// ============================================================
// AURVIA Spin & Win (front end only animates; the server decides the prize)
// ============================================================
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
    }

    ready(function () {

        var form = document.getElementById('spinForm');
        var wheel = document.getElementById('spinWheel');
        if (!form || !wheel) { return; }

        var btn = document.getElementById('spinBtn');
        var btnText = document.getElementById('spinBtnText');
        var resultBox = document.getElementById('spinResult');
        var cooldownBox = document.getElementById('spinCooldown');
        var countdownEl = document.getElementById('spinCountdown');
        var segments = parseInt(wheel.getAttribute('data-segments'), 10) || 6;
        var timer = null;
        var busy = false;

        function pad(n) { return (n < 10 ? '0' : '') + n; }

        function fmt(s) {
            s = Math.max(0, s);
            return pad(Math.floor(s / 3600)) + ':' + pad(Math.floor((s % 3600) / 60)) + ':' + pad(s % 60);
        }

        function startCountdown(seconds) {
            if (!countdownEl) { return; }
            clearInterval(timer);

            var left = parseInt(seconds, 10) || 0;
            if (left <= 0) { return; }

            btn.disabled = true;
            btnText.textContent = 'Already spun';
            cooldownBox.classList.remove('d-none');
            countdownEl.textContent = fmt(left);

            timer = setInterval(function () {
                left -= 1;
                countdownEl.textContent = fmt(left);
                if (left <= 0) {
                    clearInterval(timer);
                    window.location.reload();
                }
            }, 1000);
        }

        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) { n.className = cls; }
            if (text !== undefined) { n.textContent = text; }
            return n;
        }

        function copyText(text, done) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, done);
                return;
            }
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) { /* ignore */ }
            document.body.removeChild(ta);
            done();
        }

        function showMessage(text, type) {
            resultBox.className = 'spin-result mt-4 alert alert-' + (type || 'warning');
            resultBox.textContent = text;
        }

        function showResult(j) {
            resultBox.className = 'spin-result mt-4';
            resultBox.textContent = '';

            if (!j.won) {
                resultBox.appendChild(el('div', 'fs-5 fw-semibold', 'Better luck next time'));
                resultBox.appendChild(el('div', 'text-secondary', 'Come back after the timer ends for another spin.'));
                return;
            }

            resultBox.appendChild(el('div', 'fs-5 fw-semibold text-aurvia', 'You won ' + j.label + '!'));

            var row = el('div', 'd-flex justify-content-center align-items-center gap-2 my-2');
            row.appendChild(el('code', 'spin-code fs-5', j.coupon.code));
            var copy = el('button', 'btn btn-sm btn-outline-aurvia', 'Copy');
            copy.type = 'button';
            copy.addEventListener('click', function () {
                copyText(j.coupon.code, function () {
                    copy.textContent = 'Copied';
                    setTimeout(function () { copy.textContent = 'Copy'; }, 1500);
                });
            });
            row.appendChild(copy);
            resultBox.appendChild(row);

            resultBox.appendChild(el('div', 'small text-secondary', j.coupon.terms));
            if (j.coupon.expires_at) {
                resultBox.appendChild(el('div', 'small text-secondary', 'Valid for 7 days'));
            }

            var shop = el('a', 'btn btn-aurvia btn-sm mt-3', 'Shop now');
            shop.href = (window.AURVIA && window.AURVIA.baseUrl ? window.AURVIA.baseUrl : '/') + 'shop/products.php';
            resultBox.appendChild(shop);
        }

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (busy || btn.disabled) { return; }

            busy = true;
            btn.disabled = true;
            btnText.textContent = 'Spinning...';
            resultBox.className = 'd-none';

            fetch(form.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
                credentials: 'same-origin'
            }).then(function (res) {
                return res.json().then(function (j) { return { status: res.status, json: j }; });
            }).then(function (res) {

                var j = res.json;

                if (!j || !j.ok) {
                    showMessage((j && j.message) || 'Could not spin. Please try again.', 'warning');
                    busy = false;
                    if (j && j.seconds_left) {
                        startCountdown(j.seconds_left);
                    } else {
                        btn.disabled = false;
                        btnText.textContent = 'SPIN NOW';
                    }
                    return;
                }

                var seg = 360 / segments;
                var center = j.index * seg + seg / 2;
                var jitter = (Math.random() - 0.5) * (seg - 14);   // stays inside the segment
                var rotation = 360 * 6 - center + jitter;

                var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                var ms = reduce ? 300 : 5000;

                wheel.style.transition = 'transform ' + ms + 'ms cubic-bezier(.12,.67,.1,1)';
                wheel.style.transform = 'rotate(' + rotation + 'deg)';

                var finished = false;
                function finish() {
                    if (finished) { return; }
                    finished = true;
                    showResult(j);
                    busy = false;
                    startCountdown(j.seconds_left);
                }

                wheel.addEventListener('transitionend', finish, { once: true });
                setTimeout(finish, ms + 300);

            }).catch(function () {
                showMessage('Network problem. Please try again.', 'danger');
                busy = false;
                btn.disabled = false;
                btnText.textContent = 'SPIN NOW';
            });
        });

        // countdown already running on page load
        if (countdownEl) {
            var initial = parseInt(countdownEl.getAttribute('data-seconds'), 10) || 0;
            if (initial > 0) { startCountdown(initial); }
        }

        // "Copy" buttons in the rewards list
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest ? ev.target.closest('.js-copy') : null;
            if (!b) { return; }
            copyText(b.getAttribute('data-copy'), function () {
                var old = b.textContent;
                b.textContent = 'Copied';
                setTimeout(function () { b.textContent = old; }, 1500);
            });
        });
    });
})();
