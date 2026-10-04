USE aurvia_db;

-- ============================================================
-- AURVIA SAMPLE DATA
-- ============================================================


-- ============================================================
-- CATEGORIES
-- ============================================================

INSERT INTO categories
(name, slug, description, status)
VALUES

(
    'Everyday Essentials',
    'everyday-essentials',
    'Useful products designed for everyday life.',
    'active'
),

(
    'Home & Living',
    'home-living',
    'Thoughtful products for a comfortable home.',
    'active'
),

(
    'Tech & Accessories',
    'tech-accessories',
    'Practical technology and accessories.',
    'active'
),

(
    'Personal Care',
    'personal-care',
    'Everyday personal care and wellness products.',
    'active'
),

(
    'Travel & Lifestyle',
    'travel-lifestyle',
    'Products designed for travel and active lifestyles.',
    'active'
);


-- ============================================================
-- PRODUCTS
-- ============================================================

INSERT INTO products
(
    category_id,
    name,
    slug,
    description,
    brand,
    price,
    discount,
    stock,
    low_stock_at,
    image,
    status
)

VALUES


-- ------------------------------------------------------------
-- EVERYDAY ESSENTIALS
-- ------------------------------------------------------------

(
    1,
    'AeroSip Thermal Bottle',
    'aerosip-thermal-bottle',
    'A double-wall insulated bottle designed to keep beverages at the desired temperature throughout the day.',
    'AURVIA',
    899.00,
    10.00,
    40,
    5,
    NULL,
    'active'
),

(
    1,
    'FoldMate Everyday Organizer',
    'foldmate-everyday-organizer',
    'A compact organizer for keeping personal accessories, cables and small essentials neatly arranged.',
    'AURVIA',
    649.00,
    5.00,
    28,
    5,
    NULL,
    'active'
),

(
    1,
    'UrbanCarry Utility Pouch',
    'urbancarry-utility-pouch',
    'A lightweight multi-purpose pouch for carrying everyday essentials.',
    'AURVIA',
    499.00,
    15.00,
    35,
    5,
    NULL,
    'active'
),


-- ------------------------------------------------------------
-- HOME & LIVING
-- ------------------------------------------------------------

(
    2,
    'LumaNest Table Lamp',
    'lumanest-table-lamp',
    'A minimal table lamp designed for reading, studying and creating a warm workspace atmosphere.',
    'LumaNest',
    1499.00,
    12.00,
    18,
    5,
    NULL,
    'active'
),

(
    2,
    'CalmWeave Cushion Set',
    'calmweave-cushion-set',
    'Soft textured cushion covers designed to add a subtle modern touch to living spaces.',
    'CalmWeave',
    999.00,
    8.00,
    22,
    5,
    NULL,
    'active'
),

(
    2,
    'TerraGlow Aroma Diffuser',
    'terraglow-aroma-diffuser',
    'Compact aroma diffuser designed for creating a relaxing atmosphere at home.',
    'TerraGlow',
    1799.00,
    10.00,
    14,
    5,
    NULL,
    'active'
),


-- ------------------------------------------------------------
-- TECH & ACCESSORIES
-- ------------------------------------------------------------

(
    3,
    'PulseDock 4-in-1 Hub',
    'pulsedock-4-in-1-hub',
    'A compact connectivity hub designed for laptops and workstations requiring multiple ports.',
    'PulseDock',
    1899.00,
    12.00,
    25,
    5,
    NULL,
    'active'
),

(
    3,
    'QuietType Wireless Keyboard',
    'quiettype-wireless-keyboard',
    'A low-profile wireless keyboard designed for comfortable and quiet everyday typing.',
    'QuietType',
    2199.00,
    15.00,
    16,
    5,
    NULL,
    'active'
),

(
    3,
    'FocusBeat Wireless Earbuds',
    'focusbeat-wireless-earbuds',
    'Compact wireless earbuds designed for calls, music and everyday commuting.',
    'FocusBeat',
    2499.00,
    18.00,
    20,
    5,
    NULL,
    'active'
),


-- ------------------------------------------------------------
-- PERSONAL CARE
-- ------------------------------------------------------------

(
    4,
    'PureForm Travel Grooming Kit',
    'pureform-travel-grooming-kit',
    'A compact grooming kit designed for everyday personal care and travel.',
    'PureForm',
    799.00,
    10.00,
    30,
    5,
    NULL,
    'active'
),

(
    4,
    'BreezeMist Facial Steamer',
    'breezemist-facial-steamer',
    'A compact personal-care steamer designed for a simple home-care routine.',
    'BreezeMist',
    1299.00,
    8.00,
    12,
    5,
    NULL,
    'active'
),


-- ------------------------------------------------------------
-- TRAVEL & LIFESTYLE
-- ------------------------------------------------------------

(
    5,
    'NomadFlex Travel Backpack',
    'nomadflex-travel-backpack',
    'A versatile everyday backpack with dedicated storage for technology and travel essentials.',
    'NomadFlex',
    2699.00,
    15.00,
    19,
    5,
    NULL,
    'active'
),

(
    5,
    'TrailLoop Compact Sling',
    'trailloop-compact-sling',
    'A lightweight sling bag designed for short trips, commuting and everyday movement.',
    'TrailLoop',
    1199.00,
    10.00,
    27,
    5,
    NULL,
    'active'
);


-- ============================================================
-- PRODUCT ATTRIBUTES
-- ============================================================

INSERT INTO product_attributes
(product_id, attribute_name, attribute_value)

VALUES

(1, 'Material', 'Stainless Steel'),
(1, 'Capacity', '750 ml'),
(1, 'Insulation', 'Double Wall'),

(2, 'Material', 'Polyester'),
(2, 'Compartments', '6'),
(2, 'Use', 'Everyday'),

(3, 'Material', 'Nylon'),
(3, 'Closure', 'Zipper'),
(3, 'Use', 'Travel'),

(4, 'Light Type', 'LED'),
(4, 'Power', 'USB'),
(4, 'Use', 'Study'),

(5, 'Material', 'Cotton Blend'),
(5, 'Set', '2 Pieces'),
(5, 'Wash', 'Machine Wash'),

(6, 'Capacity', '300 ml'),
(6, 'Control', 'One Touch'),
(6, 'Power', 'USB'),

(7, 'Ports', '4'),
(7, 'Interface', 'USB-C'),
(7, 'Compatibility', 'Laptop'),

(8, 'Connection', 'Wireless'),
(8, 'Layout', 'Compact'),
(8, 'Battery', 'Rechargeable'),

(9, 'Connection', 'Bluetooth'),
(9, 'Microphone', 'Built-in'),
(9, 'Charging', 'USB-C'),

(10, 'Pieces', '5'),
(10, 'Use', 'Travel'),
(10, 'Material', 'ABS'),

(11, 'Capacity', '250 ml'),
(11, 'Power', 'USB'),
(11, 'Control', 'One Button'),

(12, 'Capacity', '28 L'),
(12, 'Material', 'Water Resistant Fabric'),
(12, 'Laptop Support', '15.6 inch'),

(13, 'Material', 'Water Resistant Fabric'),
(13, 'Strap', 'Adjustable'),
(13, 'Use', 'Everyday');


-- ============================================================
-- COUPONS
-- ============================================================

INSERT INTO coupons
(
    code,
    discount_type,
    discount_value,
    minimum_order,
    max_discount,
    usage_limit,
    expires_at,
    status
)

VALUES

(
    'AURVIA10',
    'percentage',
    10.00,
    1000.00,
    500.00,
    100,
    '2027-12-31 23:59:59',
    'active'
),

(
    'WELCOME200',
    'fixed',
    200.00,
    2000.00,
    NULL,
    50,
    '2027-12-31 23:59:59',
    'active'
);