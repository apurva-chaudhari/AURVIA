<?php

// Shared page end: footer + scripts. Include at the bottom of a page.

require_once __DIR__ . "/footer.php";

?>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="aurviaToasts"></div>

<script>
    window.AURVIA = {
        baseUrl: <?php echo json_encode(BASE_URL); ?>,
        csrf: <?php echo json_encode(csrfToken()); ?>
    };
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo BASE_URL; ?>assets/js/validation.js"></script>
<script src="<?php echo BASE_URL; ?>assets/js/cart.js"></script>
<script src="<?php echo BASE_URL; ?>assets/js/wishlist.js"></script>
<script src="<?php echo BASE_URL; ?>assets/js/suggest.js"></script>

</body>
</html>
