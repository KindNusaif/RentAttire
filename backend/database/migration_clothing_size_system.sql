
-- Database: rentattire

USE rentattire_db;

-- 1. Create `size_types` table
CREATE TABLE IF NOT EXISTS size_types (
  size_type_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type_code VARCHAR(50) NOT NULL UNIQUE,
  type_name VARCHAR(100) NOT NULL,
  category_group ENUM('Men','Women','Kids','Traditional Sri Lankan Wear') NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Create `size_options` table
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

-- 3. Create `product_variants` table
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

-- 4. Create `product_images` gallery table if not exists
CREATE TABLE IF NOT EXISTS product_images (
  image_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  INDEX idx_product_images_product (product_id)
) ENGINE=InnoDB;

-- 5. Create `product_unavailability` table if not exists
CREATE TABLE IF NOT EXISTS product_unavailability (
  availability_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  reason VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_unavail_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  INDEX idx_product_unavail_product (product_id)
) ENGINE=InnoDB;

-- 6. Alter `products` table
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'size_type_id');
SET @sql := IF(@exist = 0, 'ALTER TABLE products ADD COLUMN size_type_id INT UNSIGNED NULL AFTER category_id, ADD CONSTRAINT fk_products_size_type FOREIGN KEY (size_type_id) REFERENCES size_types(size_type_id) ON DELETE SET NULL', 'SELECT "size_type_id already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'size_inventory');
SET @sql := IF(@exist = 0, 'ALTER TABLE products ADD COLUMN size_inventory JSON NULL AFTER sizes', 'SELECT "size_inventory already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'custom_measurement_notes');
SET @sql := IF(@exist = 0, 'ALTER TABLE products ADD COLUMN custom_measurement_notes TEXT NULL AFTER size_inventory', 'SELECT "custom_measurement_notes already exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Alter `cart_items` table
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cart_items' AND COLUMN_NAME = 'variant_id');
SET @sql := IF(@exist = 0, 'ALTER TABLE cart_items ADD COLUMN variant_id INT UNSIGNED NULL AFTER product_id', 'SELECT "variant_id already exists in cart_items"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE cart_items MODIFY COLUMN size VARCHAR(255) NULL;

-- 8. Alter `order_items` table
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'variant_id');
SET @sql := IF(@exist = 0, 'ALTER TABLE order_items ADD COLUMN variant_id INT UNSIGNED NULL AFTER product_id', 'SELECT "variant_id already exists in order_items"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE order_items MODIFY COLUMN size VARCHAR(255) NULL;

-- 9. Seed standard size types
INSERT INTO size_types (type_code, type_name, category_group, description) VALUES
('saree_osari', 'Sarees & Kandyan Osari', 'Traditional Sri Lankan Wear', 'Free Size drape (5.5m - 6.3m) with selectable blouse options (Ready-made bust sizes, unstitched blouse piece, or saree only).'),
('mens_suit', 'Men\'s Suits & Blazers', 'Men', 'Trouser waist (30" - 44") and inseam length (Short, Regular, Long).'),
('womens_dress', 'Women\'s Dresses & Bridal Gowns', 'Women', 'Primary UK sizing (UK 6 - 20) with US and EU conversions, plus bust, waist, and hip garment measurements.'),
('traditional_sri_lankan', 'Traditional Sri Lankan Attire', 'Traditional Sri Lankan Wear', 'National Dress (Arya Sinhala), Sarongs, Lama Sarees, and cultural ceremonial garments.'),
('kids_clothing', 'Kids & Children\'s Clothing', 'Kids', 'Age-based sizes (2–15 years) accompanied by height in cm, chest, and waist measurements.')
ON DUPLICATE KEY UPDATE type_name = VALUES(type_name), category_group = VALUES(category_group), description = VALUES(description);
