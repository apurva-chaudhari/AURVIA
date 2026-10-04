<?php

require_once "config/database.php";
require_once "config/constants.php";


// ------------------------------------------------------------
// Get category count
// ------------------------------------------------------------

$categoryQuery = "SELECT COUNT(*) AS total FROM categories";

$categoryResult = $conn->query($categoryQuery);

$categoryCount = $categoryResult->fetch_assoc()['total'];


// ------------------------------------------------------------
// Get product count
// ------------------------------------------------------------

$productQuery = "SELECT COUNT(*) AS total FROM products";

$productResult = $conn->query($productQuery);

$productCount = $productResult->fetch_assoc()['total'];


// ------------------------------------------------------------
// Get active products
// ------------------------------------------------------------

$activeQuery = "
    SELECT
        id,
        name,
        brand,
        price,
        discount,
        stock
    FROM products
    WHERE status = 'active'
    ORDER BY id ASC
";

$activeResult = $conn->query($activeQuery);

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        AURVIA - Data Test
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@300;400;500;600&display=swap"
        rel="stylesheet"
    >

    <style>

        body {
            font-family: 'Poppins', sans-serif;
            background: #f8f6fb;
        }

        .heading {
            font-family: 'Playfair Display', serif;
        }

        .aurvia-card {
            background: white;
            border-radius: 16px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
        }

    </style>

</head>


<body>


<div class="container py-5">

    <div class="text-center mb-5">

        <h1 class="heading">
            AURVIA Data Test
        </h1>

        <p class="text-secondary">
            Verifying product catalog and database data
        </p>

    </div>


    <div class="row g-4 mb-5">


        <div class="col-md-6">

            <div class="aurvia-card p-4 text-center">

                <h6 class="text-secondary">
                    Categories
                </h6>

                <h2>
                    <?php echo $categoryCount; ?>
                </h2>

                <div class="text-success">
                    ✓ Loaded from MySQL
                </div>

            </div>

        </div>


        <div class="col-md-6">

            <div class="aurvia-card p-4 text-center">

                <h6 class="text-secondary">
                    Products
                </h6>

                <h2>
                    <?php echo $productCount; ?>
                </h2>

                <div class="text-success">
                    ✓ Loaded from MySQL
                </div>

            </div>

        </div>

    </div>


    <div class="aurvia-card p-4">

        <h4 class="heading mb-4">
            Product Catalog
        </h4>


        <div class="table-responsive">

            <table class="table align-middle">

                <thead>

                    <tr>

                        <th>ID</th>

                        <th>Product</th>

                        <th>Brand</th>

                        <th>Price</th>

                        <th>Discount</th>

                        <th>Stock</th>

                    </tr>

                </thead>


                <tbody>

                <?php while ($product = $activeResult->fetch_assoc()): ?>

                    <tr>

                        <td>
                            <?php echo $product['id']; ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars(
                                $product['name']
                            ); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars(
                                $product['brand']
                            ); ?>
                        </td>

                        <td>
                            ₹<?php echo number_format(
                                $product['price'],
                                2
                            ); ?>
                        </td>

                        <td>
                            <?php echo $product['discount']; ?>%
                        </td>

                        <td>
                            <?php echo $product['stock']; ?>
                        </td>

                    </tr>

                <?php endwhile; ?>

                </tbody>

            </table>

        </div>

    </div>


    <div class="text-center mt-4 text-success">

        ✓ AURVIA product database is ready

    </div>

</div>


</body>

</html>