<?php

require_once "config/database.php";
require_once "config/constants.php";
require_once "config/session.php";

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
        AURVIA | UI Test
    </title>


    <!-- Google Fonts -->

    <link
        href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@300;400;500;600&display=swap"
        rel="stylesheet"
    >


    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- Font Awesome -->

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
    >


    <!-- AURVIA CSS -->

    <link
        rel="stylesheet"
        href="<?php echo BASE_URL; ?>assets/css/style.css"
    >

</head>


<body>


<?php require_once "includes/navbar.php"; ?>


<section class="py-5">

    <div class="container">

        <div class="row align-items-center g-5">

            <div class="col-lg-6">

                <span class="badge-aurvia">
                    A NEW KIND OF SHOPPING
                </span>


                <h1 class="heading-font display-3 fw-bold mt-3">

                    Choose better.
                    <br>

                    <span class="text-aurvia">
                        Shop smarter.
                    </span>

                </h1>


                <p class="lead text-secondary mt-3">

                    AURVIA brings products,
                    discovery and personalized
                    shopping into one experience.

                </p>


                <div class="d-flex gap-3 mt-4">

                    <a
                        href="#"
                        class="btn btn-aurvia"
                    >
                        Explore Products
                    </a>


                    <a
                        href="#"
                        class="btn btn-outline-aurvia"
                    >
                        Discover AURVIA
                    </a>

                </div>

            </div>


            <div class="col-lg-6">

                <div
                    class="aurvia-card p-5 text-center"
                    style="min-height:350px;"
                >

                    <i
                        class="fa-solid fa-bag-shopping text-aurvia"
                        style="font-size:90px;"
                    ></i>


                    <h2 class="heading-font mt-4">
                        Your shopping,
                        your way.
                    </h2>


                    <p class="text-secondary">
                        This is the beginning of
                        the AURVIA experience.
                    </p>

                </div>

            </div>

        </div>

    </div>

</section>


<section class="py-5 bg-white">

    <div class="container text-center">

        <h2 class="section-title">
            Built around the shopper
        </h2>

        <p class="section-subtitle mx-auto mt-2">

            AURVIA will combine intelligent
            discovery, transparent products,
            useful recommendations and
            a clean shopping experience.

        </p>


        <div class="row g-4 mt-4">

            <div class="col-md-4">

                <div class="aurvia-card p-4 h-100">

                    <i
                        class="fa-solid fa-compass text-aurvia fs-1 mb-3"
                    ></i>

                    <h5>
                        Discover
                    </h5>

                    <p class="text-secondary mb-0">

                        Find products based on
                        what you actually need.

                    </p>

                </div>

            </div>


            <div class="col-md-4">

                <div class="aurvia-card p-4 h-100">

                    <i
                        class="fa-solid fa-sliders text-aurvia fs-1 mb-3"
                    ></i>

                    <h5>
                        Compare
                    </h5>

                    <p class="text-secondary mb-0">

                        Understand products before
                        making a purchase.

                    </p>

                </div>

            </div>


            <div class="col-md-4">

                <div class="aurvia-card p-4 h-100">

                    <i
                        class="fa-solid fa-heart text-aurvia fs-1 mb-3"
                    ></i>

                    <h5>
                        Personalize
                    </h5>

                    <p class="text-secondary mb-0">

                        Your activity helps AURVIA
                        improve future suggestions.

                    </p>

                </div>

            </div>

        </div>

    </div>

</section>


<?php require_once "includes/footer.php"; ?>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>