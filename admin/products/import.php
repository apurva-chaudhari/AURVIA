<?php

// ============================================================
// Bulk product import from CSV (admin only)
// Reuses validateProductInput() + adminCreateProduct(), so every
// row follows exactly the same rules as the "Add product" form.
// ============================================================

require_once "../../includes/admin-check.php";
require_once "../../includes/admin-layout.php";

$self = adminUrl('products/import.php');

// ---------- sample CSV download ----------
if (isset($_GET['sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="products-sample.csv"');
    readfile(__DIR__ . '/products-sample.csv');
    exit;
}

$report = null;   // ['added' => n, 'skipped' => [[line, name, reason], ...]]

if (isPost()) {

    if (!verifyCsrf()) {
        setFlash('danger', 'Session expired. Please try again.');
        redirect($self);
    }

    $file = $_FILES['csv'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        setFlash('danger', 'Please choose a CSV file.');
        redirect($self);
    }

    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv' || $file['size'] > 2 * 1024 * 1024) {
        setFlash('danger', 'Upload a .csv file under 2 MB.');
        redirect($self);
    }

    $dryRun = !empty($_POST['dry_run']);

    // category name or slug -> id
    $catMap = [];
    foreach (adminAllCategories($conn) as $c) {
        $catMap[strtolower($c['name'])] = (int)$c['id'];
        $catMap[strtolower($c['slug'])] = (int)$c['id'];
    }

    $fh = fopen($file['tmp_name'], 'r');

    // strip UTF-8 BOM (Excel adds one)
    $first = fgets($fh);
    $first = preg_replace('/^\xEF\xBB\xBF/', '', (string)$first);
    $header = array_map(function ($h) { return strtolower(trim($h)); }, str_getcsv($first));

    $need = ['name', 'category', 'description', 'price'];
    $missing = array_diff($need, $header);

    if ($missing) {
        fclose($fh);
        setFlash('danger', 'Missing column(s): ' . implode(', ', $missing));
        redirect($self);
    }

    $report = ['added' => 0, 'skipped' => []];
    $line = 1;
    $imageDir = __DIR__ . '/../../' . PRODUCT_IMAGE_PATH;

    while (($cells = fgetcsv($fh)) !== false) {

        $line++;

        if (count($cells) === 1 && trim((string)$cells[0]) === '') {
            continue;   // blank line
        }

        $row = [];
        foreach ($header as $i => $h) {
            $row[$h] = trim((string)($cells[$i] ?? ''));
        }

        $catKey = strtolower($row['category'] ?? '');
        $in = [
            'category_id'  => $catMap[$catKey] ?? (ctype_digit($catKey) ? (int)$catKey : 0),
            'name'         => $row['name'] ?? '',
            'description'  => $row['description'] ?? '',
            'brand'        => $row['brand'] ?? 'AURVIA',
            'price'        => $row['price'] ?? '',
            'discount'     => ($row['discount'] ?? '') === '' ? '0' : $row['discount'],
            'stock'        => ($row['stock'] ?? '') === '' ? '0' : $row['stock'],
            'low_stock_at' => ($row['low_stock_at'] ?? '') === '' ? (string)DEFAULT_LOW_STOCK : $row['low_stock_at'],
            'status'       => ($row['status'] ?? '') === '' ? 'active' : strtolower($row['status']),
        ];

        $res = validateProductInput($conn, $in);

        if ($res['errors']) {
            $report['skipped'][] = [$line, $in['name'], implode(' ', $res['errors'])];
            continue;
        }

        // optional image: a file name already inside assets/images/products/
        $image = null;
        $img = basename($row['image'] ?? '');
        if ($img !== '') {
            if (is_file($imageDir . $img)) {
                $image = $img;
            } else {
                $report['skipped'][] = [$line, $in['name'], 'Image "' . $img . '" not found in ' . PRODUCT_IMAGE_PATH];
                continue;
            }
        }

        // attributes column:  Material=Steel | Capacity=750 ml
        $attrs = [];
        foreach (explode('|', $row['attributes'] ?? '') as $pair) {
            if (strpos($pair, '=') === false) { continue; }
            [$n, $v] = array_map('trim', explode('=', $pair, 2));
            if ($n !== '' && $v !== '' && count($attrs) < 20) {
                $attrs[] = [$n, $v];
            }
        }

        if ($dryRun) {
            $report['added']++;
            continue;
        }

        $made = adminCreateProduct($conn, $res['clean'], $image, $attrs);

        if ($made['ok']) {
            $report['added']++;
        } else {
            $report['skipped'][] = [$line, $in['name'], $made['message']];
        }
    }

    fclose($fh);
    $report['dry'] = $dryRun;
}

adminHeader($conn, 'Import products', 'products');
?>

<div class="card p-4 mb-4">
    <h5 class="mb-3">Import products from CSV</h5>

    <p class="text-muted mb-2">
        Columns: <code>name, category, description, price</code> (required) and
        <code>brand, discount, stock, low_stock_at, status, image, attributes</code> (optional).
    </p>
    <p class="text-muted">
        <b>category</b> = existing category name or slug. <b>image</b> = a file already in
        <code>assets/images/products/</code> (leave empty for the placeholder).
        <b>attributes</b> = <code>Material=Steel | Capacity=750 ml</code>.
        <a href="<?php echo e($self . '?sample=1'); ?>">Download sample CSV</a>
    </p>

    <form method="post" enctype="multipart/form-data">
        <?php echo csrfField(); ?>
        <input type="file" name="csv" accept=".csv" class="form-control mb-3" required>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="dry_run" id="dry" value="1" checked>
            <label class="form-check-label" for="dry">Check only (don't save). Untick to really import.</label>
        </div>
        <button class="btn btn-aurvia">Upload</button>
        <a href="<?php echo e(adminUrl('products/index.php')); ?>" class="btn btn-outline-secondary">Back</a>
    </form>
</div>

<?php if ($report): ?>
<div class="card p-4">
    <h6>
        <?php echo (int)$report['added']; ?> product(s)
        <?php echo !empty($report['dry']) ? 'passed the check (nothing saved yet)' : 'imported'; ?>,
        <?php echo count($report['skipped']); ?> skipped
    </h6>

    <?php if ($report['skipped']): ?>
        <table class="table table-sm mt-3">
            <thead><tr><th>CSV line</th><th>Name</th><th>Problem</th></tr></thead>
            <tbody>
            <?php foreach ($report['skipped'] as $s): ?>
                <tr>
                    <td><?php echo (int)$s[0]; ?></td>
                    <td><?php echo e($s[1]); ?></td>
                    <td><?php echo e($s[2]); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php adminFooter(); ?>
