USE rentattire_db;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shops' AND COLUMN_NAME='logo_path');
SET @stmt := IF(@exists=0,'ALTER TABLE shops ADD COLUMN logo_path VARCHAR(255) NULL AFTER email','SELECT 1');
PREPARE migration_stmt FROM @stmt; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_items' AND COLUMN_NAME='vendor_status');
SET @stmt := IF(@exists=0,"ALTER TABLE order_items ADD COLUMN vendor_status ENUM('pending','accepted','rejected','preparing','ready_for_pickup','picked_up','returned','cancelled') NOT NULL DEFAULT 'pending' AFTER size",'SELECT 1');
PREPARE migration_stmt FROM @stmt; EXECUTE migration_stmt; DEALLOCATE PREPARE migration_stmt;

CREATE TABLE IF NOT EXISTS product_images (
  image_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  INDEX idx_product_images_product (product_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_unavailability (
  availability_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  reason VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_unavailability_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  INDEX idx_product_unavailability_dates (product_id,start_date,end_date)
) ENGINE=InnoDB;
