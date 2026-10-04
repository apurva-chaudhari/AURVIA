// ============================================================
// AURVIA - client-side helpers (server-side validation is the
// real protection; this only improves the experience)
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

    // ---- show / hide password ----
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.querySelector(btn.dataset.togglePassword);
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show
                ? '<i class="fa-regular fa-eye-slash"></i>'
                : '<i class="fa-regular fa-eye"></i>';
        });
    });

    // ---- password strength meter ----
    document.querySelectorAll('[data-strength]').forEach(function (input) {
        var bar = document.querySelector(input.dataset.strength);
        if (!bar) return;

        input.addEventListener('input', function () {
            var v = input.value;
            var score = 0;
            if (v.length >= 8) score++;
            if (/[A-Z]/.test(v)) score++;
            if (/[a-z]/.test(v)) score++;
            if (/[0-9]/.test(v)) score++;
            if (/[^A-Za-z0-9]/.test(v)) score++;

            var colors = ['#d9534f', '#d9534f', '#e7a94f', '#e7a94f', '#29966f', '#29966f'];
            bar.style.width = (score * 20) + '%';
            bar.style.background = colors[score];
        });
    });

    // ---- phone: digits only ----
    var phone = document.getElementById('phone');
    if (phone) {
        phone.addEventListener('input', function () {
            phone.value = phone.value.replace(/\D/g, '').slice(0, 10);
        });
    }
});
