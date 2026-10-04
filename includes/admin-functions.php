<?php

// ============================================================
// AURVIA ADMIN FUNCTIONS (Step 15: Admin Panel)
//   products, categories, orders + status, customers,
//   inventory, reviews, dashboard and analytics queries
// All state changes run in transactions and are written so the
// admin pages stay thin.
// ============================================================

require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/order-functions.php";   // dbRun(), getOrderItems()
require_once __DIR__ . "/review-functions.php";  // recalcProductRating()


// ------------------------------------------------------------
// Small helpers
// ------------------------------------------------------------

function adminUrl($path = '')
{
    return BASE_URL . 'admin/' . $path;
}

// page / pages / offset for a list
function adminPaginate($total, $page, $perPage)
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min((int)$page, $pages));

    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage];
}

// <nav> with page links; $params = the current filters (without "page")
function adminPagerHtml($page, $pages, $params = [])
{
    if ($pages <= 1) {
        return '';
    }

    $params = array_filter($params, function ($v) {
        return $v !== '' && $v !== null && $v !== 'all';
    });

    $html = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center mb-0">';

    for ($i = 1; $i <= $pages; $i++) {
        $q = $params;
        if ($i > 1) {
            $q['page'] = $i;
        }
        $href = '?' . http_build_query($q);
        $html .= '<li class="page-item ' . ($i === $page ? 'active' : '') . '">'
            . '<a class="page-link" href="' . e($href) . '">' . $i . '</a></li>';
    }

    return $html . '</ul></nav>';
}

function stockBadge($stock, $lowAt)
{
    $stock = (int)$stock;

    if ($stock === 0) {
        return '<span class="badge text-bg-danger">Out of stock</span>';
    }

    if ($stock <= (int)$lowAt) {
        return '<span class="badge text-bg-warning">Low (' . $stock . ')</span>';
    }

    return '<span class="badge text-bg-success">' . $stock . ' in stock</span>';
}

// unique slug in a table (products / categories)
function uniqueSlug($conn, $table, $text, $ignoreId = 0)
{
    if (!in_array($table, ['products', 'categories'], true)) {
        throw new InvalidArgumentException('Bad table');
    }

    $base = createSlug($text);

    if ($base === '') {
        $base = 'item';
    }

    $slug = $base;
    $n = 1;

    while (true) {
        $stmt = $conn->prepare("SELECT id FROM $table WHERE slug = ? AND id <> ?");
        $stmt->bind_param("si", $slug, $ignoreId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            return $slug;
        }

        $n++;
        $slug = $base . '-' . $n;
    }
}


// ------------------------------------------------------------
// Image upload (products + categories)
// ------------------------------------------------------------

function adminImageDir($kind)
{
    $rel = $kind === 'category' ? CATEGORY_IMAGE_PATH : PRODUCT_IMAGE_PATH;

    return rtrim(dirname(__DIR__) . '/' . $rel, '/\\') . DIRECTORY_SEPARATOR;
}

// checks the $_FILES entry (size / error code / extension); no disk access
// returns ['ok', 'empty' => bool, 'ext', 'error']
function validateImageUpload($file)
{
    $none = ['ok' => true, 'empty' => true, 'ext' => '', 'error' => ''];

    if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $none;
    }

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'empty' => false, 'ext' => '', 'error' => 'The image is too large (max 2 MB).'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'empty' => false, 'ext' => '', 'error' => 'The image could not be uploaded.'];
    }

    if ((int)$file['size'] > 2 * 1024 * 1024) {
        return ['ok' => false, 'empty' => false, 'ext' => '', 'error' => 'The image is too large (max 2 MB).'];
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));

    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return ['ok' => false, 'empty' => false, 'ext' => $ext, 'error' => 'Only JPG, PNG or WEBP images are allowed.'];
    }

    return ['ok' => true, 'empty' => false, 'ext' => $ext, 'error' => ''];
}

// validates and stores the file; returns ['ok', 'file' => name|null, 'error']
function storeImageUpload($file, $kind, $prefix)
{
    $v = validateImageUpload($file);

    if (!$v['ok']) {
        return ['ok' => false, 'file' => null, 'error' => $v['error']];
    }

    if ($v['empty']) {
        return ['ok' => true, 'file' => null, 'error' => ''];
    }

    $tmp = $file['tmp_name'];

    // the file content must really be an image of an allowed type
    $info = @getimagesize($tmp);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];

    if (!$info || !in_array($info['mime'], $allowed, true)) {
        return ['ok' => false, 'file' => null, 'error' => 'That file is not a valid image.'];
    }

    $dir = adminImageDir($kind);

    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'file' => null, 'error' => 'The image folder is missing and could not be created.'];
    }

    $ext = $info['mime'] === 'image/png' ? 'png' : ($info['mime'] === 'image/webp' ? 'webp' : 'jpg');
    $name = preg_replace('/[^a-z0-9\-]/', '', strtolower($prefix));
    $name = ($name !== '' ? substr($name, 0, 40) : 'img') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;

    if (!@move_uploaded_file($tmp, $dir . $name)) {
        return ['ok' => false, 'file' => null, 'error' => 'The image could not be saved. Check folder permissions.'];
    }

    return ['ok' => true, 'file' => $name, 'error' => ''];
}

// delete a stored image if nobody else uses it
function removeStoredImage($conn, $kind, $name)
{
    if (empty($name)) {
        return;
    }

    $name = basename($name);
    $table = $kind === 'category' ? 'categories' : 'products';

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM $table WHERE image = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $still = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    if ($still > 0) {
        return;
    }

    $path = adminImageDir($kind) . $name;

    if (is_file($path)) {
        @unlink($path);
    }
}


// ------------------------------------------------------------
// CATEGORIES
// ------------------------------------------------------------

function adminAllCategories($conn, $onlyActive = false)
{
    $sql = "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
            FROM categories c "
        . ($onlyActive ? "WHERE c.status = 'active' " : "")
        . "ORDER BY c.name ASC";

    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function adminGetCategory($conn, $id)
{
    $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

// returns ['errors' => [], 'clean' => []]
function validateCategoryInput($conn, $in, $editingId = 0)
{
    $errors = [];

    $name = cleanInput($in['name'] ?? '');
    $desc = trim((string)($in['description'] ?? ''));
    $status = $in['status'] ?? 'active';

    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors['name'] = 'Name must be 2 to 100 characters.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND id <> ?");
        $stmt->bind_param("si", $name, $editingId);
        $stmt->execute();
        $dup = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($dup) {
            $errors['name'] = 'A category with this name already exists.';
        }
    }

    if (mb_strlen($desc) > 1000) {
        $errors['description'] = 'Description can be at most 1000 characters.';
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors['status'] = 'Choose a valid status.';
    }

    return ['errors' => $errors, 'clean' => ['name' => $name, 'description' => $desc, 'status' => $status]];
}

function adminCreateCategory($conn, $clean, $image)
{
    $slug = uniqueSlug($conn, 'categories', $clean['name']);
    $desc = $clean['description'] === '' ? null : $clean['description'];

    $stmt = $conn->prepare("INSERT INTO categories (name, slug, description, image, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $clean['name'], $slug, $desc, $image, $clean['status']);
    dbRun($stmt);
    $id = $stmt->insert_id;
    $stmt->close();

    return (int)$id;
}

// $image = new file name, or null to keep the old one
function adminUpdateCategory($conn, $id, $clean, $image)
{
    $slug = uniqueSlug($conn, 'categories', $clean['name'], $id);
    $desc = $clean['description'] === '' ? null : $clean['description'];

    if ($image !== null) {
        $stmt = $conn->prepare("UPDATE categories SET name = ?, slug = ?, description = ?, image = ?, status = ? WHERE id = ?");
        $stmt->bind_param("sssssi", $clean['name'], $slug, $desc, $image, $clean['status'], $id);
    } else {
        $stmt = $conn->prepare("UPDATE categories SET name = ?, slug = ?, description = ?, status = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $clean['name'], $slug, $desc, $clean['status'], $id);
    }

    dbRun($stmt);
    $stmt->close();
}

// refuses while products still use the category
function adminDeleteCategory($conn, $id)
{
    $cat = adminGetCategory($conn, $id);

    if (!$cat) {
        return ['ok' => false, 'message' => 'Category not found.'];
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM products WHERE category_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    if ($count > 0) {
        return ['ok' => false, 'message' => 'This category has ' . $count . ' product(s). Move or delete them first, or set the category to inactive.'];
    }

    $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    dbRun($stmt);
    $stmt->close();

    removeStoredImage($conn, 'category', $cat['image']);

    return ['ok' => true, 'message' => 'Category "' . $cat['name'] . '" was deleted.'];
}


// ------------------------------------------------------------
// PRODUCTS
// ------------------------------------------------------------

function adminGetProduct($conn, $id)
{
    $stmt = $conn->prepare("
        SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function adminGetAttributes($conn, $productId)
{
    $stmt = $conn->prepare("SELECT attribute_name, attribute_value FROM product_attributes WHERE product_id = ? ORDER BY id");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

// textarea "Name: Value" per line  ->  [[name, value], ...]
function parseAttributes($text)
{
    $out = [];

    foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {

        $line = trim($line);

        if ($line === '' || strpos($line, ':') === false) {
            continue;
        }

        [$name, $value] = array_map('trim', explode(':', $line, 2));

        if ($name === '' || $value === '' || mb_strlen($name) > 100 || mb_strlen($value) > 255) {
            continue;
        }

        $out[] = [$name, $value];

        if (count($out) >= 20) {
            break;
        }
    }

    return $out;
}

function attributesToText($rows)
{
    $lines = [];

    foreach ($rows as $r) {
        $lines[] = $r['attribute_name'] . ': ' . $r['attribute_value'];
    }

    return implode("\n", $lines);
}

// returns ['errors' => [], 'clean' => []]
function validateProductInput($conn, $in, $editingId = 0)
{
    $errors = [];

    $categoryId = (int)($in['category_id'] ?? 0);
    $name = cleanInput($in['name'] ?? '');
    $desc = trim((string)($in['description'] ?? ''));
    $brand = cleanInput($in['brand'] ?? '');
    $price = trim((string)($in['price'] ?? ''));
    $discount = trim((string)($in['discount'] ?? '0'));
    $stock = trim((string)($in['stock'] ?? '0'));
    $lowAt = trim((string)($in['low_stock_at'] ?? (string)DEFAULT_LOW_STOCK));
    $status = $in['status'] ?? 'active';

    // category must exist
    $stmt = $conn->prepare("SELECT id FROM categories WHERE id = ?");
    $stmt->bind_param("i", $categoryId);
    $stmt->execute();
    $catOk = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$catOk) {
        $errors['category_id'] = 'Choose a category.';
    }

    if (mb_strlen($name) < 2 || mb_strlen($name) > 200) {
        $errors['name'] = 'Name must be 2 to 200 characters.';
    }

    if (mb_strlen($desc) < 10 || mb_strlen($desc) > 5000) {
        $errors['description'] = 'Description must be 10 to 5000 characters.';
    }

    if (mb_strlen($brand) > 100) {
        $errors['brand'] = 'Brand can be at most 100 characters.';
    }

    if (!is_numeric($price) || (float)$price <= 0 || (float)$price > 9999999.99) {
        $errors['price'] = 'Enter a price greater than 0.';
    }

    if (!is_numeric($discount) || (float)$discount < 0 || (float)$discount > 90) {
        $errors['discount'] = 'Discount must be between 0 and 90 percent.';
    }

    if (!ctype_digit($stock) || (int)$stock > 1000000) {
        $errors['stock'] = 'Stock must be a whole number (0 or more).';
    }

    if (!ctype_digit($lowAt) || (int)$lowAt > 10000) {
        $errors['low_stock_at'] = 'Low-stock level must be a whole number (0 or more).';
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors['status'] = 'Choose a valid status.';
    }

    return [
        'errors' => $errors,
        'clean' => [
            'category_id' => $categoryId,
            'name' => $name,
            'description' => $desc,
            'brand' => $brand,
            'price' => round((float)$price, 2),
            'discount' => round((float)$discount, 2),
            'stock' => ctype_digit($stock) ? (int)$stock : 0,
            'low_stock_at' => ctype_digit($lowAt) ? (int)$lowAt : (int)DEFAULT_LOW_STOCK,
            'status' => $status,
        ],
    ];
}

function adminSaveAttributes($conn, $productId, $attrs)
{
    $stmt = $conn->prepare("DELETE FROM product_attributes WHERE product_id = ?");
    $stmt->bind_param("i", $productId);
    dbRun($stmt);
    $stmt->close();

    foreach ($attrs as $a) {
        $stmt = $conn->prepare("INSERT INTO product_attributes (product_id, attribute_name, attribute_value) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $productId, $a[0], $a[1]);
        dbRun($stmt);
        $stmt->close();
    }
}

function adminLogStock($conn, $productId, $type, $change, $after, $note)
{
    $stmt = $conn->prepare("
        INSERT INTO inventory_logs (product_id, change_type, quantity_change, stock_after, note)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isiis", $productId, $type, $change, $after, $note);
    dbRun($stmt);
    $stmt->close();
}

// returns ['ok', 'id', 'message']
function adminCreateProduct($conn, $clean, $image, $attrs)
{
    $conn->begin_transaction();

    try {

        $slug = uniqueSlug($conn, 'products', $clean['name']);
        $brand = $clean['brand'] === '' ? null : $clean['brand'];

        $stmt = $conn->prepare("
            INSERT INTO products
                (category_id, name, slug, description, brand, price, discount, stock, low_stock_at, image, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issssddiiss",
            $clean['category_id'], $clean['name'], $slug, $clean['description'], $brand,
            $clean['price'], $clean['discount'], $clean['stock'], $clean['low_stock_at'], $image, $clean['status']);
        dbRun($stmt);
        $id = (int)$stmt->insert_id;
        $stmt->close();

        adminSaveAttributes($conn, $id, $attrs);

        if ($clean['stock'] > 0) {
            adminLogStock($conn, $id, 'purchase', $clean['stock'], $clean['stock'], 'Initial stock');
        }

        $conn->commit();

        return ['ok' => true, 'id' => $id, 'message' => 'Product "' . $clean['name'] . '" was added.'];

    } catch (Throwable $ex) {
        $conn->rollback();
        error_log('AURVIA adminCreateProduct failed: ' . $ex->getMessage());
        return ['ok' => false, 'id' => 0, 'message' => 'Could not save the product.', 'debug' => $ex->getMessage()];
    }
}

// $image = new file name, or null to keep the old one
function adminUpdateProduct($conn, $id, $clean, $image, $attrs)
{
    $conn->begin_transaction();

    try {

        $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $id);
        dbRun($stmt);
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$old) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Product not found.'];
        }

        $slug = uniqueSlug($conn, 'products', $clean['name'], $id);
        $brand = $clean['brand'] === '' ? null : $clean['brand'];

        if ($image !== null) {
            $stmt = $conn->prepare("
                UPDATE products SET category_id = ?, name = ?, slug = ?, description = ?, brand = ?,
                    price = ?, discount = ?, stock = ?, low_stock_at = ?, status = ?, image = ?
                WHERE id = ?
            ");
            $stmt->bind_param("issssddiissi",
                $clean['category_id'], $clean['name'], $slug, $clean['description'], $brand,
                $clean['price'], $clean['discount'], $clean['stock'], $clean['low_stock_at'], $clean['status'], $image, $id);
        } else {
            $stmt = $conn->prepare("
                UPDATE products SET category_id = ?, name = ?, slug = ?, description = ?, brand = ?,
                    price = ?, discount = ?, stock = ?, low_stock_at = ?, status = ?
                WHERE id = ?
            ");
            $stmt->bind_param("issssddiisi",
                $clean['category_id'], $clean['name'], $slug, $clean['description'], $brand,
                $clean['price'], $clean['discount'], $clean['stock'], $clean['low_stock_at'], $clean['status'], $id);
        }

        dbRun($stmt);
        $stmt->close();

        adminSaveAttributes($conn, $id, $attrs);

        $diff = $clean['stock'] - (int)$old['stock'];

        if ($diff !== 0) {
            adminLogStock($conn, $id, 'adjustment', $diff, $clean['stock'], 'Edited in admin');
        }

        $conn->commit();

        return ['ok' => true, 'message' => 'Product "' . $clean['name'] . '" was updated.'];

    } catch (Throwable $ex) {
        $conn->rollback();
        error_log('AURVIA adminUpdateProduct failed: ' . $ex->getMessage());
        return ['ok' => false, 'message' => 'Could not save the product.', 'debug' => $ex->getMessage()];
    }
}

function adminSetProductStatus($conn, $id, $status)
{
    if (!in_array($status, ['active', 'inactive'], true)) {
        return false;
    }

    $stmt = $conn->prepare("UPDATE products SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $id);
    dbRun($stmt);
    $changed = $stmt->affected_rows;
    $stmt->close();

    return $changed > 0;
}

// products that appear on an order cannot be deleted (history must stay intact)
function adminDeleteProduct($conn, $id)
{
    $p = adminGetProduct($conn, $id);

    if (!$p) {
        return ['ok' => false, 'message' => 'Product not found.'];
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM order_items WHERE product_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $used = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    if ($used > 0) {
        return ['ok' => false, 'message' => '"' . $p['name'] . '" is part of ' . $used . ' order line(s), so it cannot be deleted. Set it to inactive instead to hide it from the shop.'];
    }

    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    dbRun($stmt);
    $stmt->close();

    removeStoredImage($conn, 'product', $p['image']);

    return ['ok' => true, 'message' => 'Product "' . $p['name'] . '" was deleted.'];
}

// list with filters: q, category, status, stock (all|low|out)
function getAdminProducts($conn, $f, $page, $perPage = 12)
{
    $where = ['1=1'];
    $types = '';
    $params = [];

    if (($f['q'] ?? '') !== '') {
        $where[] = '(p.name LIKE ? OR p.brand LIKE ?)';
        $types .= 'ss';
        $like = '%' . $f['q'] . '%';
        $params[] = $like;
        $params[] = $like;
    }

    if ((int)($f['category'] ?? 0) > 0) {
        $where[] = 'p.category_id = ?';
        $types .= 'i';
        $params[] = (int)$f['category'];
    }

    if (in_array($f['status'] ?? '', ['active', 'inactive'], true)) {
        $where[] = 'p.status = ?';
        $types .= 's';
        $params[] = $f['status'];
    }

    if (($f['stock'] ?? '') === 'out') {
        $where[] = 'p.stock = 0';
    } elseif (($f['stock'] ?? '') === 'low') {
        $where[] = 'p.stock > 0 AND p.stock <= p.low_stock_at';
    }

    $w = implode(' AND ', $where);

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM products p WHERE $w");
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $pg = adminPaginate($total, $page, $perPage);

    $stmt = $conn->prepare("
        SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE $w
        ORDER BY p.id DESC
        LIMIT $perPage OFFSET {$pg['offset']}
    ");
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['rows' => $rows, 'total' => $total] + $pg;
}


// ------------------------------------------------------------
// ORDERS + STATUS
// ------------------------------------------------------------

function adminOrderTransitions()
{
    return [
        'PLACED'    => ['CONFIRMED', 'CANCELLED'],
        'CONFIRMED' => ['PACKED', 'CANCELLED'],
        'PACKED'    => ['SHIPPED', 'CANCELLED'],
        'SHIPPED'   => ['DELIVERED'],
        'DELIVERED' => [],
        'CANCELLED' => [],
    ];
}

function adminNextStatuses($current)
{
    return adminOrderTransitions()[$current] ?? [];
}

function adminGetOrder($conn, $id)
{
    $stmt = $conn->prepare("
        SELECT o.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        WHERE o.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

// moves an order to $new if the workflow allows it
// returns ['ok', 'message']
function adminSetOrderStatus($conn, $orderId, $new)
{
    $orderId = (int)$orderId;

    $conn->begin_transaction();

    try {

        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $orderId);
        dbRun($stmt);
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Order not found.'];
        }

        $current = $order['order_status'];

        if (!in_array($new, adminNextStatuses($current), true)) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'An order that is ' . strtolower($current) . ' cannot be changed to ' . strtolower((string)$new) . '.'];
        }

        // online orders must be paid before they move forward
        if ($new !== 'CANCELLED' && $order['payment_method'] === 'ONLINE' && $order['payment_status'] !== 'PAID') {
            $conn->rollback();
            return ['ok' => false, 'message' => 'The online payment for this order is still pending.'];
        }

        if ($new === 'CANCELLED') {

            $stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ? ORDER BY product_id");
            $stmt->bind_param("i", $orderId);
            dbRun($stmt);
            $lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($lines as $line) {

                $pid = (int)$line['product_id'];
                $qty = (int)$line['quantity'];

                if ($pid < 1) {
                    continue;
                }

                $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $stmt->bind_param("ii", $qty, $pid);
                dbRun($stmt);
                $stmt->close();

                $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
                $stmt->bind_param("i", $pid);
                dbRun($stmt);
                $after = (int)$stmt->get_result()->fetch_assoc()['stock'];
                $stmt->close();

                adminLogStock($conn, $pid, 'return', $qty, $after, 'Admin cancelled ' . $order['order_number']);
            }

            $stmt = $conn->prepare("UPDATE payments SET status = 'FAILED' WHERE order_id = ? AND status = 'PENDING'");
            $stmt->bind_param("i", $orderId);
            dbRun($stmt);
            $stmt->close();

            if (!empty($order['coupon_code'])) {
                $stmt = $conn->prepare("UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE code = ?");
                $stmt->bind_param("s", $order['coupon_code']);
                dbRun($stmt);
                $stmt->close();
            }
        }

        $stmt = $conn->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
        $stmt->bind_param("si", $new, $orderId);
        dbRun($stmt);
        $stmt->close();

        // cash is collected when the parcel is delivered
        if ($new === 'DELIVERED' && $order['payment_method'] === 'COD' && $order['payment_status'] !== 'PAID') {

            $stmt = $conn->prepare("UPDATE orders SET payment_status = 'PAID' WHERE id = ?");
            $stmt->bind_param("i", $orderId);
            dbRun($stmt);
            $stmt->close();

            $stmt = $conn->prepare("UPDATE payments SET status = 'SUCCESS' WHERE order_id = ? AND status = 'PENDING'");
            $stmt->bind_param("i", $orderId);
            dbRun($stmt);
            $stmt->close();
        }

        $conn->commit();

        return ['ok' => true, 'message' => 'Order ' . $order['order_number'] . ' is now ' . strtolower($new) . '.'];

    } catch (Throwable $ex) {
        $conn->rollback();
        error_log('AURVIA adminSetOrderStatus failed: ' . $ex->getMessage());
        return ['ok' => false, 'message' => 'Could not update the order.', 'debug' => $ex->getMessage()];
    }
}

// filters: status (all|PLACED..), payment (all|COD|ONLINE), q (order no / customer)
function getAdminOrders($conn, $f, $page, $perPage = 12)
{
    $where = ['1=1'];
    $types = '';
    $params = [];

    if (in_array($f['status'] ?? '', ['PLACED', 'CONFIRMED', 'PACKED', 'SHIPPED', 'DELIVERED', 'CANCELLED'], true)) {
        $where[] = 'o.order_status = ?';
        $types .= 's';
        $params[] = $f['status'];
    }

    if (in_array($f['payment'] ?? '', ['COD', 'ONLINE'], true)) {
        $where[] = 'o.payment_method = ?';
        $types .= 's';
        $params[] = $f['payment'];
    }

    if (($f['q'] ?? '') !== '') {
        $where[] = '(o.order_number LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
        $types .= 'sss';
        $like = '%' . $f['q'] . '%';
        array_push($params, $like, $like, $like);
    }

    $w = implode(' AND ', $where);

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE $w");
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $pg = adminPaginate($total, $page, $perPage);

    $stmt = $conn->prepare("
        SELECT o.*, u.name AS customer_name, u.email AS customer_email,
               (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) AS units
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        WHERE $w
        ORDER BY o.id DESC
        LIMIT $perPage OFFSET {$pg['offset']}
    ");
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['rows' => $rows, 'total' => $total] + $pg;
}

function adminOrderStatusCounts($conn)
{
    $out = ['PLACED' => 0, 'CONFIRMED' => 0, 'PACKED' => 0, 'SHIPPED' => 0, 'DELIVERED' => 0, 'CANCELLED' => 0];

    $res = $conn->query("SELECT order_status, COUNT(*) AS c FROM orders GROUP BY order_status");

    while ($r = $res->fetch_assoc()) {
        $out[$r['order_status']] = (int)$r['c'];
    }

    return $out;
}


// ------------------------------------------------------------
// CUSTOMERS
// ------------------------------------------------------------

function getAdminCustomers($conn, $f, $page, $perPage = 12)
{
    $where = ["u.role = 'customer'"];
    $types = '';
    $params = [];

    if (($f['q'] ?? '') !== '') {
        $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
        $types .= 'sss';
        $like = '%' . $f['q'] . '%';
        array_push($params, $like, $like, $like);
    }

    if (in_array($f['status'] ?? '', ['active', 'blocked'], true)) {
        $where[] = 'u.status = ?';
        $types .= 's';
        $params[] = $f['status'];
    }

    $w = implode(' AND ', $where);

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM users u WHERE $w");
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $pg = adminPaginate($total, $page, $perPage);

    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.email, u.phone, u.status, u.created_at,
               (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count,
               (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.user_id = u.id AND o.order_status <> 'CANCELLED') AS spent
        FROM users u
        WHERE $w
        ORDER BY u.id DESC
        LIMIT $perPage OFFSET {$pg['offset']}
    ");
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['rows' => $rows, 'total' => $total] + $pg;
}

function adminGetCustomer($conn, $id)
{
    $stmt = $conn->prepare("SELECT id, name, email, phone, role, status, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

// admins cannot be blocked, and nobody can block themselves
function adminSetUserStatus($conn, $userId, $status, $actingAdminId)
{
    if (!in_array($status, ['active', 'blocked'], true)) {
        return ['ok' => false, 'message' => 'Invalid status.'];
    }

    $u = adminGetCustomer($conn, $userId);

    if (!$u) {
        return ['ok' => false, 'message' => 'Customer not found.'];
    }

    if ((int)$userId === (int)$actingAdminId) {
        return ['ok' => false, 'message' => 'You cannot change your own account status.'];
    }

    if ($u['role'] === 'admin') {
        return ['ok' => false, 'message' => 'Admin accounts cannot be blocked here.'];
    }

    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $userId);
    dbRun($stmt);
    $stmt->close();

    return ['ok' => true, 'message' => $u['name'] . ($status === 'blocked' ? ' was blocked.' : ' was unblocked.')];
}


// ------------------------------------------------------------
// INVENTORY
// ------------------------------------------------------------

// modes: add (+), return (+), correction (+/-), remove (-)
// returns ['ok', 'message', 'stock']
function adminAdjustStock($conn, $productId, $mode, $qty, $note = '')
{
    $productId = (int)$productId;
    $note = mb_substr(cleanInput($note), 0, 200);

    if (!in_array($mode, ['add', 'return', 'correction', 'remove'], true)) {
        return ['ok' => false, 'message' => 'Choose what kind of change this is.'];
    }

    if (!is_numeric($qty) || (int)$qty != $qty || (int)$qty === 0 || abs((int)$qty) > 1000000) {
        return ['ok' => false, 'message' => 'Enter a whole number other than 0.'];
    }

    $qty = (int)$qty;

    if (in_array($mode, ['add', 'return', 'remove'], true) && $qty < 0) {
        return ['ok' => false, 'message' => 'Enter a positive number for this kind of change.'];
    }

    $delta = $mode === 'remove' ? -$qty : $qty;
    $type = $mode === 'add' ? 'purchase' : ($mode === 'return' ? 'return' : 'adjustment');

    if ($note === '') {
        $note = ['add' => 'Stock received', 'return' => 'Customer return', 'correction' => 'Manual correction', 'remove' => 'Stock removed'][$mode];
    }

    $conn->begin_transaction();

    try {

        $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $productId);
        dbRun($stmt);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Product not found.'];
        }

        $after = (int)$row['stock'] + $delta;

        if ($after < 0) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Not enough stock: only ' . (int)$row['stock'] . ' in stock.'];
        }

        $stmt = $conn->prepare("UPDATE products SET stock = ? WHERE id = ?");
        $stmt->bind_param("ii", $after, $productId);
        dbRun($stmt);
        $stmt->close();

        adminLogStock($conn, $productId, $type, $delta, $after, $note);

        $conn->commit();

        return ['ok' => true, 'message' => 'Stock updated. New stock: ' . $after . '.', 'stock' => $after];

    } catch (Throwable $ex) {
        $conn->rollback();
        error_log('AURVIA adminAdjustStock failed: ' . $ex->getMessage());
        return ['ok' => false, 'message' => 'Could not update the stock.', 'debug' => $ex->getMessage()];
    }
}

// filter: all | low | out
function getInventoryList($conn, $filter, $q, $page, $perPage = 15)
{
    $where = ['1=1'];
    $types = '';
    $params = [];

    if ($filter === 'out') {
        $where[] = 'p.stock = 0';
    } elseif ($filter === 'low') {
        $where[] = 'p.stock > 0 AND p.stock <= p.low_stock_at';
    }

    if ($q !== '') {
        $where[] = '(p.name LIKE ? OR p.brand LIKE ?)';
        $types .= 'ss';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $w = implode(' AND ', $where);

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM products p WHERE $w");
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $pg = adminPaginate($total, $page, $perPage);

    $stmt = $conn->prepare("
        SELECT p.id, p.name, p.brand, p.image, p.stock, p.low_stock_at, p.status
        FROM products p
        WHERE $w
        ORDER BY (p.stock = 0) DESC, (p.stock <= p.low_stock_at) DESC, p.stock ASC, p.id DESC
        LIMIT $perPage OFFSET {$pg['offset']}
    ");
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['rows' => $rows, 'total' => $total] + $pg;
}

function getInventoryCounts($conn)
{
    $r = $conn->query("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(stock = 0), 0) AS out_of_stock,
               COALESCE(SUM(stock > 0 AND stock <= low_stock_at), 0) AS low
        FROM products
    ")->fetch_assoc();

    return ['total' => (int)$r['total'], 'out' => (int)$r['out_of_stock'], 'low' => (int)$r['low']];
}

function getInventoryLogs($conn, $productId = null, $limit = 15)
{
    $limit = max(1, min((int)$limit, 100));

    if ($productId) {
        $stmt = $conn->prepare("
            SELECT l.*, p.name AS product_name FROM inventory_logs l
            JOIN products p ON p.id = l.product_id
            WHERE l.product_id = ? ORDER BY l.id DESC LIMIT $limit
        ");
        $stmt->bind_param("i", $productId);
    } else {
        $stmt = $conn->prepare("
            SELECT l.*, p.name AS product_name FROM inventory_logs l
            JOIN products p ON p.id = l.product_id
            ORDER BY l.id DESC LIMIT $limit
        ");
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}


// ------------------------------------------------------------
// REVIEWS
// ------------------------------------------------------------

function adminGetReview($conn, $id)
{
    $stmt = $conn->prepare("SELECT * FROM reviews WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function adminSetReviewStatus($conn, $id, $status)
{
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        return ['ok' => false, 'message' => 'Invalid status.'];
    }

    $r = adminGetReview($conn, $id);

    if (!$r) {
        return ['ok' => false, 'message' => 'Review not found.'];
    }

    $stmt = $conn->prepare("UPDATE reviews SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $id);
    dbRun($stmt);
    $stmt->close();

    recalcProductRating($conn, (int)$r['product_id']);

    return ['ok' => true, 'message' => 'Review ' . $status . '.'];
}

function adminDeleteReview($conn, $id)
{
    $r = adminGetReview($conn, $id);

    if (!$r) {
        return ['ok' => false, 'message' => 'Review not found.'];
    }

    $stmt = $conn->prepare("DELETE FROM reviews WHERE id = ?");
    $stmt->bind_param("i", $id);
    dbRun($stmt);
    $stmt->close();

    recalcProductRating($conn, (int)$r['product_id']);

    return ['ok' => true, 'message' => 'Review deleted.'];
}

function getAdminReviews($conn, $status, $page, $perPage = 10)
{
    $where = '1=1';
    $types = '';
    $params = [];

    if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $where = 'r.status = ?';
        $types = 's';
        $params[] = $status;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM reviews r WHERE $where");
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $pg = adminPaginate($total, $page, $perPage);

    $stmt = $conn->prepare("
        SELECT r.*, u.name AS customer_name, p.name AS product_name,
               EXISTS (
                   SELECT 1 FROM orders o JOIN order_items oi ON oi.order_id = o.id
                   WHERE o.user_id = r.user_id AND oi.product_id = r.product_id AND o.order_status = 'DELIVERED'
               ) AS verified
        FROM reviews r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN products p ON p.id = r.product_id
        WHERE $where
        ORDER BY r.id DESC
        LIMIT $perPage OFFSET {$pg['offset']}
    ");
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['rows' => $rows, 'total' => $total] + $pg;
}

function getReviewCounts($conn)
{
    $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0];

    $res = $conn->query("SELECT status, COUNT(*) AS c FROM reviews GROUP BY status");

    while ($r = $res->fetch_assoc()) {
        $out[$r['status']] = (int)$r['c'];
    }

    return $out;
}

function starsHtml($rating)
{
    $rating = max(0, min(5, (int)$rating));

    return '<span class="text-warning">' . str_repeat('★', $rating) . '</span>'
        . '<span class="text-secondary opacity-50">' . str_repeat('★', 5 - $rating) . '</span>';
}


// ------------------------------------------------------------
// DASHBOARD + ANALYTICS
// revenue = orders that are not cancelled
// ------------------------------------------------------------

function getAdminStats($conn)
{
    $o = $conn->query("
        SELECT
            COALESCE(SUM(DATE(created_at) = CURDATE()), 0) AS orders_today,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND order_status <> 'CANCELLED' THEN total ELSE 0 END), 0) AS revenue_today,
            COALESCE(SUM(CASE WHEN order_status <> 'CANCELLED' THEN total ELSE 0 END), 0) AS revenue_total,
            COALESCE(SUM(order_status = 'PLACED'), 0) AS pending_orders,
            COUNT(*) AS orders_total
        FROM orders
    ")->fetch_assoc();

    $inv = getInventoryCounts($conn);
    $rev = getReviewCounts($conn);

    return [
        'orders_today' => (int)$o['orders_today'],
        'revenue_today' => (float)$o['revenue_today'],
        'revenue_total' => (float)$o['revenue_total'],
        'pending_orders' => (int)$o['pending_orders'],
        'orders_total' => (int)$o['orders_total'],
        'customers' => (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'customer'")->fetch_assoc()['c'],
        'products' => $inv['total'],
        'low_stock' => $inv['low'],
        'out_of_stock' => $inv['out'],
        'pending_reviews' => $rev['pending'],
    ];
}

function getRecentOrders($conn, $limit = 8)
{
    $limit = max(1, min((int)$limit, 50));

    return $conn->query("
        SELECT o.id, o.order_number, o.total, o.order_status, o.payment_status, o.created_at, u.name AS customer_name
        FROM orders o LEFT JOIN users u ON u.id = o.user_id
        ORDER BY o.id DESC LIMIT $limit
    ")->fetch_all(MYSQLI_ASSOC);
}

function getLowStockProducts($conn, $limit = 6)
{
    $limit = max(1, min((int)$limit, 50));

    return $conn->query("
        SELECT id, name, stock, low_stock_at FROM products
        WHERE stock <= low_stock_at
        ORDER BY stock ASC, id ASC LIMIT $limit
    ")->fetch_all(MYSQLI_ASSOC);
}

// one row per day for the last $days days (missing days = 0)
function getRevenueByDay($conn, $days)
{
    $days = max(1, min((int)$days, 365));
    $today = $conn->query("SELECT CURDATE() AS d")->fetch_assoc()['d'];

    $stmt = $conn->prepare("
        SELECT DATE(created_at) AS d, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue
        FROM orders
        WHERE order_status <> 'CANCELLED'
          AND DATE(created_at) > DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY DATE(created_at)
    ");
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $found = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $found[$r['d']] = $r;
    }
    $stmt->close();

    $out = [];
    $base = strtotime($today . ' 12:00:00');

    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', $base - $i * 86400);
        $out[] = [
            'date' => $d,
            'orders' => isset($found[$d]) ? (int)$found[$d]['orders'] : 0,
            'revenue' => isset($found[$d]) ? (float)$found[$d]['revenue'] : 0.0,
        ];
    }

    return $out;
}

function getAnalyticsKpis($conn, $days)
{
    $days = max(1, min((int)$days, 365));

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue
        FROM orders
        WHERE order_status <> 'CANCELLED' AND DATE(created_at) > DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ");
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $o = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c FROM users
        WHERE role = 'customer' AND DATE(created_at) > DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ");
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $newCustomers = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c FROM orders
        WHERE order_status = 'CANCELLED' AND DATE(created_at) > DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ");
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $cancelled = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $orders = (int)$o['orders'];
    $revenue = (float)$o['revenue'];

    return [
        'orders' => $orders,
        'revenue' => $revenue,
        'aov' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
        'new_customers' => $newCustomers,
        'cancelled' => $cancelled,
    ];
}

function getTopProducts($conn, $days, $limit = 5)
{
    $days = max(1, min((int)$days, 365));
    $limit = max(1, min((int)$limit, 50));

    $stmt = $conn->prepare("
        SELECT oi.product_name AS name, SUM(oi.quantity) AS units, SUM(oi.subtotal) AS revenue
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE o.order_status <> 'CANCELLED' AND DATE(o.created_at) > DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY oi.product_id, oi.product_name
        ORDER BY units DESC, revenue DESC
        LIMIT $limit
    ");
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function getSalesByCategory($conn, $days)
{
    $days = max(1, min((int)$days, 365));

    $stmt = $conn->prepare("
        SELECT c.name, SUM(oi.quantity) AS units, SUM(oi.subtotal) AS revenue
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN products p ON p.id = oi.product_id
        JOIN categories c ON c.id = p.category_id
        WHERE o.order_status <> 'CANCELLED' AND DATE(o.created_at) > DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY c.id, c.name
        ORDER BY revenue DESC
    ");
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function getPaymentSplit($conn, $days)
{
    $days = max(1, min((int)$days, 365));

    $stmt = $conn->prepare("
        SELECT payment_method, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue
        FROM orders
        WHERE order_status <> 'CANCELLED' AND DATE(created_at) > DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY payment_method
    ");
    $stmt->bind_param("i", $days);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

// "Showing 1-12 of 40"
function adminCount($pg, $perPage, $total)
{
    if ($total === 0) {
        return 'No results';
    }

    $from = $pg['offset'] + 1;
    $to = min($pg['offset'] + $perPage, $total);

    return 'Showing ' . $from . '-' . $to . ' of ' . $total;
}

// coloured badge for an order / payment / generic status word
function adminStatusBadge($status)
{
    $map = [
        'PLACED' => 'warning', 'CONFIRMED' => 'info', 'PACKED' => 'primary', 'SHIPPED' => 'primary',
        'DELIVERED' => 'success', 'CANCELLED' => 'danger',
        'PENDING' => 'warning', 'PAID' => 'success', 'FAILED' => 'danger',
        'active' => 'success', 'inactive' => 'secondary', 'blocked' => 'danger',
        'pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger',
    ];

    $color = $map[$status] ?? 'secondary';

    return '<span class="badge text-bg-' . $color . '">' . e(ucfirst(strtolower((string)$status))) . '</span>';
}

?>
