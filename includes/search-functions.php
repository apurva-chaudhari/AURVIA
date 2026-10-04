<?php

// ============================================================
// AURVIA SMART SEARCH
//
// Understands queries such as:
//   "wireless keyboard under 2500"   -> keywords + max price
//   "cheap backpack"                 -> keyword + sort by price
//   "lamp between 1000 and 2000"     -> keyword + price range
//   "earbuds below 2k"               -> 2k = 2000
//   "best discount"                  -> sort by discount
//
// No external AI service: plain PHP rules + MySQL.
// ============================================================

require_once __DIR__ . "/functions.php";


// words that carry no search meaning
function searchStopWords()
{
    return [
        'a', 'an', 'the', 'for', 'me', 'my', 'to', 'of', 'with', 'and', 'or',
        'in', 'on', 'at', 'is', 'are', 'some', 'any', 'i', 'we', 'you',
        'show', 'find', 'get', 'give', 'want', 'need', 'looking', 'look',
        'please', 'best', 'good', 'nice', 'top', 'buy', 'product',
        'products', 'item', 'items', 'gift', 'gifts', 'something', 'under',
        'below', 'above', 'over'
    ];
}

// word => other words that mean the same thing in our catalogue
function searchSynonyms()
{
    $bags = ['bag', 'backpack', 'sling', 'pouch'];
    $audio = ['earbuds', 'earphone', 'headphone'];

    return [
        'bag' => $bags, 'bags' => $bags, 'backpack' => $bags, 'backpacks' => $bags,
        'luggage' => $bags, 'sling' => $bags, 'pouch' => $bags,
        'earphone' => $audio, 'earphones' => $audio, 'headphone' => $audio,
        'headphones' => $audio, 'earbud' => $audio, 'earbuds' => $audio,
        'music' => $audio,
        'lamp' => ['lamp', 'light'], 'lamps' => ['lamp', 'light'],
        'light' => ['lamp', 'light'], 'lights' => ['lamp', 'light'],
        'bottle' => ['bottle', 'thermal'], 'flask' => ['bottle', 'thermal'],
        'thermos' => ['bottle', 'thermal'],
        'wireless' => ['wireless', 'bluetooth'], 'bluetooth' => ['wireless', 'bluetooth'],
        'cushion' => ['cushion'], 'cushions' => ['cushion'], 'pillow' => ['cushion'],
        'diffuser' => ['diffuser', 'aroma'], 'aroma' => ['diffuser', 'aroma'],
        'fragrance' => ['diffuser', 'aroma'],
        'steamer' => ['steamer', 'facial'], 'facial' => ['steamer', 'facial'],
        'skincare' => ['steamer', 'facial'],
        'grooming' => ['grooming'], 'trimmer' => ['grooming'],
        'laptop' => ['laptop', 'hub', 'keyboard'],
        'study' => ['study', 'reading', 'lamp'],
        'office' => ['workstation', 'laptop', 'keyboard', 'hub'],
        'organizer' => ['organizer'], 'organiser' => ['organizer'],
    ];
}

// ------------------------------------------------------------
// Turn free text into a structured query. Pure function.
// returns [
//   'original', 'groups' => [[term, term], ...], 'min', 'max',
//   'sort' (newest|price_asc|price_desc|discount), 'in_stock', 'notes' => []
// ]
// ------------------------------------------------------------

function parseSmartQuery($query)
{
    $original = trim((string)$query);
    $text = mb_strtolower(mb_substr($original, 0, 100));

    $result = [
        'original' => $original,
        'groups' => [],
        'min' => null,
        'max' => null,
        'sort' => 'newest',
        'in_stock' => false,
        'notes' => [],
    ];

    if ($text === '') {
        return $result;
    }

    // currency words / symbols
    $text = preg_replace('/(₹|\brs\.?(?=\s|\d)|\binr\b|\brupees?\b)/u', ' ', $text);
    // 1,500 -> 1500
    $text = preg_replace('/(?<=\d),(?=\d{3})/', '', $text);
    // 2k -> 2000
    $text = preg_replace_callback('/\b(\d+(?:\.\d+)?)\s*k\b/u', function ($m) {
        return (string)(int)round($m[1] * 1000);
    }, $text);

    // between 1000 and 2000 / 1000-2000 / from 1000 to 2000
    if (preg_match('/\b(?:between\s+|from\s+)?(\d+)\s*(?:-|to|and)\s*(\d+)\b/u', $text, $m)) {
        $a = (float)$m[1];
        $b = (float)$m[2];
        $result['min'] = min($a, $b);
        $result['max'] = max($a, $b);
        $text = str_replace($m[0], ' ', $text);
    }

    // around 1500 -> +-20%
    if (preg_match('/\b(?:around|about|near|approximately)\s+(\d+)\b/u', $text, $m)) {
        $result['min'] = round($m[1] * 0.8);
        $result['max'] = round($m[1] * 1.2);
        $text = str_replace($m[0], ' ', $text);
    }

    // under / below / upto ...
    if (preg_match('/\b(?:under|below|less than|lesser than|within|up ?to|max|maximum|at most|cheaper than|not more than)\s+(\d+)\b/u', $text, $m)) {
        $result['max'] = (float)$m[1];
        $text = str_replace($m[0], ' ', $text);
    }

    // above / over / minimum ...
    if (preg_match('/\b(?:above|over|more than|greater than|at least|min|minimum|starting from|from)\s+(\d+)\b/u', $text, $m)) {
        $result['min'] = (float)$m[1];
        $text = str_replace($m[0], ' ', $text);
    }

    // intent words
    $intents = [
        'price_asc'  => ['cheapest', 'cheap', 'budget', 'affordable', 'inexpensive', 'low price', 'lowest price', 'low cost'],
        'price_desc' => ['premium', 'expensive', 'costly', 'luxury', 'high end', 'highest price'],
        'discount'   => ['discounted', 'discount', 'discounts', 'offers', 'offer', 'deals', 'deal', 'sale', 'bargain'],
        'newest'     => ['newest', 'latest', 'new'],
    ];

    $sortSet = false;
    foreach ($intents as $sortKey => $words) {
        foreach ($words as $word) {
            $pattern = '/\b' . preg_quote($word, '/') . '\b/u';
            if (preg_match($pattern, $text)) {
                if (!$sortSet) {
                    $result['sort'] = $sortKey;
                    $sortSet = true;
                }
                $text = preg_replace($pattern, ' ', $text);
            }
        }
    }

    if (preg_match('/\b(?:in stock|available)\b/u', $text)) {
        $result['in_stock'] = true;
        $text = preg_replace('/\b(?:in stock|available)\b/u', ' ', $text);
    }

    // remaining words -> keyword groups
    $stop = array_flip(searchStopWords());
    $synonyms = searchSynonyms();

    $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $seen = [];

    foreach ($words as $word) {

        if (isset($stop[$word]) || ctype_digit($word) || mb_strlen($word) < 2) {
            continue;
        }

        $group = [$word];

        // simple plural -> singular
        if (mb_strlen($word) > 3 && mb_substr($word, -1) === 's') {
            $group[] = mb_substr($word, 0, -1);
        }

        if (isset($synonyms[$word])) {
            $group = array_merge($group, $synonyms[$word]);
        }

        $group = array_values(array_unique($group));
        $key = implode('|', $group);

        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $result['groups'][] = array_slice($group, 0, 6);
        }
    }

    // human readable explanation
    if ($result['min'] !== null && $result['max'] !== null) {
        $result['notes'][] = 'Price ' . formatPrice($result['min']) . ' - ' . formatPrice($result['max']);
    } elseif ($result['max'] !== null) {
        $result['notes'][] = 'Under ' . formatPrice($result['max']);
    } elseif ($result['min'] !== null) {
        $result['notes'][] = 'Above ' . formatPrice($result['min']);
    }

    $sortLabels = [
        'price_asc' => 'Lowest price first',
        'price_desc' => 'Highest price first',
        'discount' => 'Biggest discount first',
    ];
    if (isset($sortLabels[$result['sort']])) {
        $result['notes'][] = $sortLabels[$result['sort']];
    }

    if ($result['in_stock']) {
        $result['notes'][] = 'In stock only';
    }

    return $result;
}


// ------------------------------------------------------------
// Run a parsed query against MySQL (prepared statement)
// ------------------------------------------------------------

function searchSmart($conn, $parsed, $limit = 24, $ignorePrice = false)
{
    $finalPrice = "(p.price - (p.price * p.discount / 100))";

    $where = "p.status = 'active' AND c.status = 'active'";
    $params = [];
    $types = '';

    foreach ($parsed['groups'] as $group) {

        $ors = [];

        foreach ($group as $term) {
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $ors[] = "(p.name LIKE ? OR p.brand LIKE ? OR p.description LIKE ? OR c.name LIKE ?)";
            array_push($params, $like, $like, $like, $like);
            $types .= 'ssss';
        }

        $where .= " AND (" . implode(' OR ', $ors) . ")";
    }

    if (!$ignorePrice) {

        if ($parsed['min'] !== null) {
            $where .= " AND $finalPrice >= ?";
            $params[] = (float)$parsed['min'];
            $types .= 'd';
        }

        if ($parsed['max'] !== null) {
            $where .= " AND $finalPrice <= ?";
            $params[] = (float)$parsed['max'];
            $types .= 'd';
        }
    }

    if (!empty($parsed['in_stock'])) {
        $where .= " AND p.stock > 0";
    }

    $orderMap = [
        'newest'     => 'p.id DESC',
        'price_asc'  => "$finalPrice ASC, p.id ASC",
        'price_desc' => "$finalPrice DESC, p.id ASC",
        'discount'   => 'p.discount DESC, p.id ASC',
    ];
    $orderBy = $orderMap[$parsed['sort']] ?? $orderMap['newest'];
    $limit = max(1, min(100, (int)$limit));

    $sql = "
        SELECT p.id, p.name, p.slug, p.brand, p.price, p.discount, p.stock,
               p.image, c.name AS category_name
        FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE $where
        ORDER BY $orderBy
        LIMIT $limit
    ";

    $stmt = $conn->prepare($sql);

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}


// ------------------------------------------------------------
// Autocomplete suggestions
// returns list of ['type' => product|category|search, 'label', 'url', ...]
// ------------------------------------------------------------

function getSuggestions($conn, $q, $limit = 6)
{
    $q = mb_substr(trim((string)$q), 0, 60);
    $out = [];

    // empty box -> trending searches
    if ($q === '') {

        $res = $conn->query("
            SELECT search_text, COUNT(*) AS total
            FROM search_history
            WHERE created_at > (NOW() - INTERVAL 30 DAY)
            GROUP BY search_text
            ORDER BY total DESC, MAX(created_at) DESC
            LIMIT 5
        ");

        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'type' => 'search',
                'label' => $row['search_text'],
                'url' => BASE_URL . 'shop/smart-search.php?q=' . urlencode($row['search_text']),
            ];
        }

        return $out;
    }

    if (mb_strlen($q) < 2) {
        return [];
    }

    $like = '%' . addcslashes($q, '%_\\') . '%';
    $prefix = addcslashes($q, '%_\\') . '%';
    $limit = max(1, min(10, (int)$limit));

    $stmt = $conn->prepare("
        SELECT p.id, p.name, p.brand, p.price, p.discount, p.image, c.name AS category_name
        FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'active' AND c.status = 'active'
          AND (p.name LIKE ? OR p.brand LIKE ? OR c.name LIKE ?)
        ORDER BY (p.name LIKE ?) DESC, p.name ASC
        LIMIT $limit
    ");
    $stmt->bind_param("ssss", $like, $like, $like, $prefix);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'type' => 'product',
            'label' => $row['name'],
            'brand' => $row['brand'],
            'category' => $row['category_name'],
            'price' => formatPrice(discountedPrice((float)$row['price'], (float)$row['discount'])),
            'image' => productImageUrl($row['image']),
            'url' => BASE_URL . 'shop/product-details.php?id=' . (int)$row['id'],
        ];
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT name, slug FROM categories
        WHERE status = 'active' AND name LIKE ?
        ORDER BY name ASC LIMIT 2
    ");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'type' => 'category',
            'label' => $row['name'],
            'url' => BASE_URL . 'shop/products.php?category=' . urlencode($row['slug']),
        ];
    }
    $stmt->close();

    return $out;
}

?>
