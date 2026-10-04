<?php

// ============================================================
// AURVIA APPLICATION CONSTANTS
// ============================================================

// Application
define("APP_NAME", "AURVIA");
define("APP_TAGLINE", "A better way to choose what you buy.");

// Base URL
define("BASE_URL", "http://localhost/AURVIA/");

// Currency
define("CURRENCY", "₹");

// Upload paths
define("PRODUCT_IMAGE_PATH", "assets/images/products/");
define("CATEGORY_IMAGE_PATH", "assets/images/categories/");
define("BANNER_IMAGE_PATH", "assets/images/banners/");

// Product settings
define("DEFAULT_LOW_STOCK", 5);

// Pagination
define("PRODUCTS_PER_PAGE", 12);

// Order settings
define("FREE_DELIVERY_LIMIT", 2000);
define("DELIVERY_CHARGE", 80);

// Cart settings
define("MAX_CART_QTY", 10);

// Security settings
define("SESSION_TIMEOUT", 1800);        // 30 minutes idle
define("MAX_LOGIN_ATTEMPTS", 5);        // failed tries
define("LOCKOUT_MINUTES", 15);          // per email + IP
define("RESET_TOKEN_MINUTES", 30);

// Demo mode: shows the password-reset link on screen
// (localhost has no mail server). Set to false in production.
define("APP_DEBUG", true);

// Wishlist / address limits
define("MAX_WISHLIST", 50);
define("MAX_ADDRESSES", 10);

// Recently viewed list length
define("RECENT_LIMIT", 12);

?>
