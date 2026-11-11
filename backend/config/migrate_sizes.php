<?php
declare(strict_types=1);

/*
 * RentAttire: Dynamic Category-Based Clothing Size System Migration Runner
 * Run from Command Prompt:
 *   php backend/config/migrate_sizes.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('CLI execution only.');
}

require_once __DIR__ . '/database.php';

function output(string $msg): void {
    echo $msg . PHP_EOL;
}

try {
    output("Starting RentAttire Clothing Size System Migration...");

    // 1. Table size_types
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS size_types (
      size_type_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      type_code VARCHAR(50) NOT NULL UNIQUE,
      type_name VARCHAR(100) NOT NULL,
      category_group ENUM('Men','Women','Kids','Traditional Sri Lankan Wear') NOT NULL,
      description VARCHAR(255) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;
    ");
    output("[OK] Verified size_types table");

    // 2. Table size_options
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS size_options (
      size_option_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      size_type_id INT UNSIGNED NOT NULL,
      size_code VARCHAR(50) NOT NULL,
      display_label VARCHAR(100) NOT NULL,
      uk_size VARCHAR(20) NULL,
      us_size VARCHAR(20) NULL,
      eu_size VARCHAR(20) NULL,
      chest_bust_inches DECIMAL(5,1) NULL,
      waist_inches DECIMAL(5,1) NULL,
      hip_inches DECIMAL(5,1) NULL,
      height_cm DECIMAL(5,1) NULL,
      length_inches DECIMAL(5,1) NULL,
      sort_order INT NOT NULL DEFAULT 0,
      CONSTRAINT fk_size_options_type FOREIGN KEY (size_type_id) REFERENCES size_types(size_type_id) ON DELETE CASCADE,
      INDEX idx_size_options_type (size_type_id)
    ) ENGINE=InnoDB;
    ");
    output("[OK] Verified size_options table");

    // 3. Table product_variants
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS product_variants (
      variant_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      product_id INT UNSIGNED NOT NULL,
      size_option_id INT UNSIGNED NULL,
      variant_label VARCHAR(160) NOT NULL,
      sku VARCHAR(60) NULL,
      stock INT UNSIGNED NOT NULL DEFAULT 1,
      price_override DECIMAL(10,2) NULL,
      custom_measurements JSON NULL,
      status ENUM('active','inactive') NOT NULL DEFAULT 'active',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
      CONSTRAINT fk_variants_option FOREIGN KEY (size_option_id) REFERENCES size_options(size_option_id) ON DELETE SET NULL,
      INDEX idx_variants_product (product_id)
    ) ENGINE=InnoDB;
    ");
    output("[OK] Verified product_variants table");

    // 4. Alter products columns
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = ?");
    $check->execute(['size_type_id']);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE products ADD COLUMN size_type_id INT UNSIGNED NULL AFTER category_id");
        $pdo->exec("ALTER TABLE products ADD CONSTRAINT fk_products_size_type FOREIGN KEY (size_type_id) REFERENCES size_types(size_type_id) ON DELETE SET NULL");
        output("[OK] Added size_type_id column to products");
    }

    $check->execute(['custom_measurement_notes']);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE products ADD COLUMN custom_measurement_notes TEXT NULL AFTER sizes");
        output("[OK] Added custom_measurement_notes column to products");
    }

    $check->execute(['size_inventory']);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE products ADD COLUMN size_inventory JSON NULL AFTER sizes");
        output("[OK] Added size_inventory column to products");
    }

    // 5. Alter cart_items & order_items
    $checkItem = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    
    $checkItem->execute(['cart_items', 'variant_id']);
    if ((int)$checkItem->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE cart_items ADD COLUMN variant_id INT UNSIGNED NULL AFTER product_id");
        output("[OK] Added variant_id to cart_items");
    }
    $pdo->exec("ALTER TABLE cart_items MODIFY COLUMN size VARCHAR(255) NULL");

    $checkItem->execute(['order_items', 'variant_id']);
    if ((int)$checkItem->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE order_items ADD COLUMN variant_id INT UNSIGNED NULL AFTER product_id");
        output("[OK] Added variant_id to order_items");
    }
    $pdo->exec("ALTER TABLE order_items MODIFY COLUMN size VARCHAR(255) NULL");

    // 6. Seed size types
    $types = [
        ['saree_osari', 'Sarees & Kandyan Osari', 'Traditional Sri Lankan Wear', 'Free Size drape (5.5m - 6.3m) with selectable blouse options.'],
        ['mens_suit', 'Men\'s Suits & Blazers', 'Men', 'Trouser waist (30" - 44") and inseam length (Short, Regular, Long).'],
        ['womens_dress', 'Women\'s Dresses & Bridal Gowns', 'Women', 'Primary UK sizing (UK 6 - 20) with US and EU conversions and measurements.'],
        ['traditional_sri_lankan', 'Traditional Sri Lankan Attire', 'Traditional Sri Lankan Wear', 'National Dress, Sarongs, Lama Sarees, and ceremonial attire.'],
        ['kids_clothing', 'Kids & Children\'s Clothing', 'Kids', 'Age-based sizes (2–15 years) accompanied by height in cm and chest measurements.']
    ];
    $stmtT = $pdo->prepare("INSERT INTO size_types (type_code, type_name, category_group, description) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE type_name=VALUES(type_name), category_group=VALUES(category_group), description=VALUES(description)");
    foreach ($types as $t) {
        $stmtT->execute($t);
    }
    output("[OK] Seeded standard size types");

    output("");
    output("MIGRATION COMPLETED SUCCESSFULLY!");
} catch (Throwable $e) {
    fwrite(STDERR, "MIGRATION FAILED: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
