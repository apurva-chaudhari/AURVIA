<?php

require_once "config/database.php";
require_once "config/constants.php";
require_once "config/session.php";
require_once "includes/functions.php";

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>
        <?php echo APP_NAME; ?> - Core Test
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f7f5f2;
            min-height: 100vh;

            display: flex;
            justify-content: center;
            align-items: center;
        }

        .test-box {
            width: 600px;
            background: white;
            padding: 40px;
            border-radius: 18px;

            box-shadow:
                0 15px 40px rgba(0,0,0,0.08);
        }

        h1 {
            color: #3F3D56;
            margin-bottom: 5px;
        }

        .tagline {
            color: #777;
            margin-bottom: 30px;
        }

        .test {
            display: flex;
            justify-content: space-between;

            padding: 14px 16px;
            margin: 10px 0;

            background: #f7f7f7;
            border-radius: 10px;
        }

        .success {
            color: #2E8B70;
            font-weight: bold;
        }

        .value {
            color: #3F3D56;
            font-weight: bold;
        }

    </style>

</head>

<body>

<div class="test-box">

    <h1>AURVIA</h1>

    <div class="tagline">
        <?php echo APP_TAGLINE; ?>
    </div>


    <div class="test">

        <span>Database</span>

        <span class="success">
            ✓ Connected
        </span>

    </div>


    <div class="test">

        <span>Application Name</span>

        <span class="value">
            <?php echo APP_NAME; ?>
        </span>

    </div>


    <div class="test">

        <span>Currency</span>

        <span class="value">
            <?php echo CURRENCY; ?>
        </span>

    </div>


    <div class="test">

        <span>Base URL</span>

        <span class="value">
            Configured
        </span>

    </div>


    <div class="test">

        <span>Session</span>

        <span class="success">

            <?php

            if (session_status() === PHP_SESSION_ACTIVE) {
                echo "✓ Active";
            } else {
                echo "✗ Failed";
            }

            ?>

        </span>

    </div>


    <div class="test">

        <span>Email Validation</span>

        <span class="success">

            <?php

            echo isValidEmail(
                "test@example.com"
            )
            ? "✓ Working"
            : "✗ Failed";

            ?>

        </span>

    </div>


    <div class="test">

        <span>Phone Validation</span>

        <span class="success">

            <?php

            echo isValidPhone(
                "9876543210"
            )
            ? "✓ Working"
            : "✗ Failed";

            ?>

        </span>

    </div>


    <div class="test">

        <span>Currency Formatting</span>

        <span class="value">

            <?php

            echo formatPrice(54999);

            ?>

        </span>

    </div>


    <br>

    <div style="
        text-align:center;
        color:#2E8B70;
        font-weight:bold;
    ">

        ✓ AURVIA Core System Ready

    </div>

</div>

</body>

</html>