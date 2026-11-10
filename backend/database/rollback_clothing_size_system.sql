-- ============================================================================
-- RentAttire: Rollback Script for Clothing Size System Migration
-- Database: rentattire_db
-- ============================================================================

USE rentattire_db;

-- 1. Remove foreign key constraints and columns from existing tables
ALTER TABLE products DROP FOREIGN KEY fk_products_size_type;
ALTER TABLE products DROP COLUMN size_type_id;
ALTER TABLE products DROP COLUMN custom_measurement_notes;

ALTER TABLE cart_items DROP COLUMN variant_id;
ALTER TABLE cart_items MODIFY COLUMN size VARCHAR(20) NULL;

ALTER TABLE order_items DROP COLUMN variant_id;
ALTER TABLE order_items MODIFY COLUMN size VARCHAR(20) NULL;

-- 2. Drop new tables in reverse dependency order
DROP TABLE IF EXISTS product_variants;
DROP TABLE IF EXISTS size_options;
DROP TABLE IF EXISTS size_types;

