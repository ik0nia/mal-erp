/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `ai_usage_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_usage_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_tokens` int unsigned NOT NULL DEFAULT '0',
  `output_tokens` int unsigned NOT NULL DEFAULT '0',
  `cost_usd` decimal(10,6) NOT NULL DEFAULT '0.000000',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_usage_logs_created_at_index` (`created_at`),
  KEY `ai_usage_logs_source_index` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_settings` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bi_analyses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bi_analyses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `generated_by` bigint unsigned DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `metrics_snapshot` json DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `generated_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bi_analyses_generated_by_foreign` (`generated_by`),
  CONSTRAINT `bi_analyses_generated_by_foreign` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bi_inventory_alert_candidates_daily`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bi_inventory_alert_candidates_daily` (
  `day` date NOT NULL,
  `reference_product_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_name` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `closing_qty` decimal(12,3) NOT NULL DEFAULT '0.000',
  `closing_price` decimal(10,4) DEFAULT NULL,
  `stock_value` decimal(14,2) NOT NULL DEFAULT '0.00',
  `avg_out_30d` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `days_left_estimate` decimal(8,1) DEFAULT NULL,
  `risk_level` enum('P0','P1','P2') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason_flags` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`day`,`reference_product_id`),
  KEY `idx_day_risk` (`day`,`risk_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bi_inventory_kpi_daily`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bi_inventory_kpi_daily` (
  `day` date NOT NULL,
  `products_total` int unsigned NOT NULL DEFAULT '0',
  `products_in_stock` int unsigned NOT NULL DEFAULT '0',
  `products_out_of_stock` int unsigned NOT NULL DEFAULT '0',
  `inventory_qty_closing_total` decimal(14,3) NOT NULL DEFAULT '0.000',
  `inventory_value_opening_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `inventory_value_closing_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `inventory_value_variation_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `snapshots_total` bigint unsigned NOT NULL DEFAULT '0',
  `imports_span_minutes` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bi_product_velocity_current`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bi_product_velocity_current` (
  `reference_product_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_for_day` date NOT NULL,
  `out_qty_7d` decimal(12,3) NOT NULL DEFAULT '0.000',
  `out_qty_30d` decimal(12,3) NOT NULL DEFAULT '0.000',
  `out_qty_90d` decimal(12,3) NOT NULL DEFAULT '0.000',
  `avg_out_qty_7d` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `avg_out_qty_30d` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `avg_out_qty_90d` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `last_movement_day` date DEFAULT NULL,
  `days_since_last_movement` int unsigned DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`reference_product_id`),
  KEY `bi_product_velocity_current_calculated_for_day_index` (`calculated_for_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brand_supplier`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brand_supplier` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `brand_id` bigint unsigned NOT NULL,
  `supplier_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `brand_supplier_brand_id_supplier_id_unique` (`brand_id`,`supplier_id`),
  KEY `brand_supplier_supplier_id_foreign` (`supplier_id`),
  CONSTRAINT `brand_supplier_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE,
  CONSTRAINT `brand_supplier_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brands` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `brands_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chat_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(254) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wants_specialist` tinyint(1) NOT NULL DEFAULT '0',
  `contacted_at` timestamp NULL DEFAULT NULL,
  `contacted_by` bigint unsigned DEFAULT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci,
  `interested_in` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_contacts_session_id_unique` (`session_id`),
  KEY `chat_contacts_contacted_by_foreign` (`contacted_by`),
  CONSTRAINT `chat_contacts_contacted_by_foreign` FOREIGN KEY (`contacted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chat_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('user','assistant') COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `has_products` tinyint(1) NOT NULL DEFAULT '0',
  `page_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `input_tokens` int unsigned DEFAULT NULL,
  `output_tokens` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chat_logs_session_id_created_at_index` (`session_id`,`created_at`),
  KEY `chat_logs_session_id_index` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_api_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_api_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'openapi',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'OpenAPI.ro',
  `base_url` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://api.openapi.ro',
  `api_key` text COLLATE utf8mb4_unicode_ci,
  `timeout` int unsigned NOT NULL DEFAULT '30',
  `verify_ssl` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_api_settings_provider_unique` (`provider`),
  KEY `company_api_settings_provider_is_active_index` (`provider`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_delivery_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_delivery_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_phone` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `county` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_delivery_addresses_customer_id_position_index` (`customer_id`,`position`),
  KEY `customer_delivery_addresses_customer_id_is_active_index` (`customer_id`,`is_active`),
  CONSTRAINT `customer_delivery_addresses_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `location_id` bigint unsigned NOT NULL,
  `type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'individual',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `representative_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cui` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_vat_payer` tinyint(1) DEFAULT NULL,
  `registration_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `county` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customers_location_id_type_index` (`location_id`,`type`),
  KEY `customers_location_id_name_index` (`location_id`,`name`),
  KEY `customers_cui_index` (`cui`),
  KEY `customers_is_active_index` (`is_active`),
  CONSTRAINT `customers_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `daily_stock_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_stock_metrics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `day` date NOT NULL,
  `reference_product_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `woo_product_id` bigint unsigned NOT NULL,
  `opening_total_qty` decimal(15,3) NOT NULL DEFAULT '0.000',
  `closing_total_qty` decimal(15,3) NOT NULL DEFAULT '0.000',
  `opening_available_qty` decimal(15,3) NOT NULL DEFAULT '0.000',
  `closing_available_qty` decimal(15,3) NOT NULL DEFAULT '0.000',
  `opening_sell_price` decimal(15,4) DEFAULT NULL,
  `closing_sell_price` decimal(15,4) DEFAULT NULL,
  `daily_total_variation` decimal(15,3) NOT NULL DEFAULT '0.000',
  `daily_available_variation` decimal(15,3) NOT NULL DEFAULT '0.000',
  `closing_sales_value` decimal(20,4) NOT NULL DEFAULT '0.0000',
  `daily_sales_value_variation` decimal(20,4) NOT NULL DEFAULT '0.0000',
  `min_available_qty` decimal(15,3) NOT NULL DEFAULT '0.000',
  `max_available_qty` decimal(15,3) NOT NULL DEFAULT '0.000',
  `snapshots_count` int unsigned NOT NULL DEFAULT '0',
  `first_snapshot_at` datetime DEFAULT NULL,
  `last_snapshot_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `daily_stock_metrics_day_reference_product_id_unique` (`day`,`reference_product_id`),
  KEY `daily_stock_metrics_product_day_idx` (`woo_product_id`,`day`),
  KEY `daily_stock_metrics_day_index` (`day`),
  KEY `daily_stock_metrics_reference_product_day_idx` (`reference_product_id`,`day`),
  CONSTRAINT `daily_stock_metrics_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ean_association_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ean_association_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ean` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `woo_product_id` bigint unsigned NOT NULL,
  `requested_by` bigint unsigned NOT NULL,
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `processed_by` bigint unsigned DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ean_association_requests_woo_product_id_foreign` (`woo_product_id`),
  KEY `ean_association_requests_requested_by_foreign` (`requested_by`),
  KEY `ean_association_requests_processed_by_foreign` (`processed_by`),
  CONSTRAINT `ean_association_requests_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ean_association_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ean_association_requests_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_entities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_entities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email_message_id` bigint unsigned NOT NULL,
  `entity_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `raw_text` text COLLATE utf8mb4_unicode_ci,
  `woo_product_id` bigint unsigned DEFAULT NULL,
  `product_name_raw` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_value` date DEFAULT NULL,
  `confidence` tinyint unsigned NOT NULL DEFAULT '80',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_entities_email_message_id_entity_type_index` (`email_message_id`,`entity_type`),
  KEY `email_entities_woo_product_id_entity_type_index` (`woo_product_id`,`entity_type`),
  KEY `email_entities_entity_type_index` (`entity_type`),
  CONSTRAINT `email_entities_email_message_id_foreign` FOREIGN KEY (`email_message_id`) REFERENCES `email_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `email_entities_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imap_uid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `imap_folder` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INBOX',
  `from_email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body_html` longtext COLLATE utf8mb4_unicode_ci,
  `body_text` longtext COLLATE utf8mb4_unicode_ci,
  `to_recipients` json DEFAULT NULL,
  `cc_recipients` json DEFAULT NULL,
  `attachments` json DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `is_flagged` tinyint(1) NOT NULL DEFAULT '0',
  `supplier_id` bigint unsigned DEFAULT NULL,
  `supplier_contact_id` bigint unsigned DEFAULT NULL,
  `purchase_order_id` bigint unsigned DEFAULT NULL,
  `agent_processed_at` timestamp NULL DEFAULT NULL,
  `agent_actions` json DEFAULT NULL,
  `internal_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_messages_imap_uid_imap_folder_unique` (`imap_uid`,`imap_folder`),
  KEY `email_messages_purchase_order_id_foreign` (`purchase_order_id`),
  KEY `email_messages_imap_uid_index` (`imap_uid`),
  KEY `email_messages_from_email_index` (`from_email`),
  KEY `email_messages_sent_at_index` (`sent_at`),
  KEY `email_messages_is_read_index` (`is_read`),
  KEY `email_messages_supplier_contact_id_foreign` (`supplier_contact_id`),
  KEY `idx_em_supplier_processed` (`supplier_id`,`agent_processed_at`),
  CONSTRAINT `email_messages_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_messages_supplier_contact_id_foreign` FOREIGN KEY (`supplier_contact_id`) REFERENCES `supplier_contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_messages_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `graphic_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `graphic_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `layout` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'product',
  `config` json NOT NULL,
  `preview_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `graphic_templates_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `integration_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `integration_connections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `location_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `consumer_key` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `consumer_secret` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `verify_ssl` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `settings` json DEFAULT NULL,
  `webhook_secret` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `integration_connections_location_id_provider_name_unique` (`location_id`,`provider`,`name`),
  KEY `integration_connections_provider_is_active_index` (`provider`,`is_active`),
  CONSTRAINT `integration_connections_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` bigint unsigned DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `county` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_vat_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_is_vat_payer` tinyint(1) DEFAULT NULL,
  `company_registration_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_postal_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_bank` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_bank_account` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `locations_type_index` (`type`),
  KEY `locations_is_active_index` (`is_active`),
  KEY `locations_store_id_foreign` (`store_id`),
  CONSTRAINT `locations_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` bigint unsigned NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offer_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offer_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `offer_id` bigint unsigned NOT NULL,
  `woo_product_id` bigint unsigned DEFAULT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(15,3) NOT NULL DEFAULT '1.000',
  `unit_price` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `line_subtotal` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `line_total` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `offer_items_offer_id_position_index` (`offer_id`,`position`),
  KEY `offer_items_woo_product_id_index` (`woo_product_id`),
  CONSTRAINT `offer_items_offer_id_foreign` FOREIGN KEY (`offer_id`) REFERENCES `offers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `offer_items_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `location_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `client_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_company` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'RON',
  `subtotal` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `discount_total` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `total` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `valid_until` date DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `offers_number_unique` (`number`),
  KEY `offers_location_id_status_index` (`location_id`,`status`),
  KEY `offers_user_id_status_index` (`user_id`,`status`),
  KEY `offers_status_updated_at_index` (`status`,`updated_at`),
  CONSTRAINT `offers_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `offers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_image_candidates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_image_candidates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `woo_product_id` bigint unsigned NOT NULL,
  `search_query` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_url` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `thumbnail_url` text COLLATE utf8mb4_unicode_ci,
  `source_page_url` text COLLATE utf8mb4_unicode_ci,
  `image_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `width` int unsigned DEFAULT NULL,
  `height` int unsigned DEFAULT NULL,
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bing',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_image_candidates_woo_product_id_status_index` (`woo_product_id`,`status`),
  CONSTRAINT `product_image_candidates_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_price_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_price_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `woo_product_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `old_price` decimal(15,4) DEFAULT NULL,
  `new_price` decimal(15,4) DEFAULT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'winmentor_csv',
  `sync_run_id` bigint unsigned DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `changed_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_price_logs_location_id_foreign` (`location_id`),
  KEY `product_price_logs_sync_run_id_foreign` (`sync_run_id`),
  KEY `price_logs_product_location_changed_at_idx` (`woo_product_id`,`location_id`,`changed_at`),
  CONSTRAINT `product_price_logs_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_price_logs_sync_run_id_foreign` FOREIGN KEY (`sync_run_id`) REFERENCES `sync_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_price_logs_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_review_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_review_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `woo_product_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','resolved') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `resolved_by_user_id` bigint unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_review_requests_woo_product_id_foreign` (`woo_product_id`),
  KEY `product_review_requests_user_id_foreign` (`user_id`),
  KEY `product_review_requests_resolved_by_user_id_foreign` (`resolved_by_user_id`),
  CONSTRAINT `product_review_requests_resolved_by_user_id_foreign` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_review_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_review_requests_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_stocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_stocks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `woo_product_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `quantity` decimal(15,3) NOT NULL DEFAULT '0.000',
  `price` decimal(15,4) DEFAULT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'winmentor_csv',
  `sync_run_id` bigint unsigned DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_stocks_woo_product_id_location_id_unique` (`woo_product_id`,`location_id`),
  KEY `product_stocks_sync_run_id_foreign` (`sync_run_id`),
  KEY `product_stocks_location_id_source_index` (`location_id`,`source`),
  CONSTRAINT `product_stocks_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_stocks_sync_run_id_foreign` FOREIGN KEY (`sync_run_id`) REFERENCES `sync_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_stocks_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_substitution_proposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_substitution_proposals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_product_id` bigint unsigned NOT NULL,
  `proposed_toya_id` bigint unsigned DEFAULT NULL,
  `confidence` decimal(3,2) DEFAULT NULL,
  `reasoning` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','approved','rejected','no_match') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_substitution_proposals_source_product_id_unique` (`source_product_id`),
  KEY `product_substitution_proposals_proposed_toya_id_foreign` (`proposed_toya_id`),
  KEY `product_substitution_proposals_approved_by_foreign` (`approved_by`),
  CONSTRAINT `product_substitution_proposals_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_substitution_proposals_proposed_toya_id_foreign` FOREIGN KEY (`proposed_toya_id`) REFERENCES `woo_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_substitution_proposals_source_product_id_foreign` FOREIGN KEY (`source_product_id`) REFERENCES `woo_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_supplier_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_supplier_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `woo_product_id` bigint unsigned NOT NULL,
  `supplier_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'associated' COMMENT 'associated | created_and_associated',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_supplier_logs_woo_product_id_foreign` (`woo_product_id`),
  KEY `product_supplier_logs_supplier_id_foreign` (`supplier_id`),
  KEY `product_supplier_logs_user_id_foreign` (`user_id`),
  CONSTRAINT `product_supplier_logs_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_supplier_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_supplier_logs_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_supplier_price_breaks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_supplier_price_breaks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_supplier_id` bigint unsigned NOT NULL,
  `min_qty` decimal(10,3) NOT NULL,
  `max_qty` decimal(10,3) DEFAULT NULL,
  `unit_price` decimal(10,4) NOT NULL,
  `discount_percent` decimal(5,2) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_supplier_price_breaks_product_supplier_id_index` (`product_supplier_id`),
  KEY `product_supplier_price_breaks_product_supplier_id_min_qty_index` (`product_supplier_id`,`min_qty`),
  CONSTRAINT `product_supplier_price_breaks_product_supplier_id_foreign` FOREIGN KEY (`product_supplier_id`) REFERENCES `product_suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_suppliers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `woo_product_id` bigint unsigned NOT NULL,
  `supplier_id` bigint unsigned NOT NULL,
  `supplier_sku` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Codul produsului la furnizor',
  `supplier_product_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_package_sku` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_package_ean` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_price` decimal(10,4) DEFAULT NULL COMMENT 'Preț achiziție de la acest furnizor',
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'RON',
  `purchase_uom` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conversion_factor` decimal(10,4) DEFAULT '1.0000',
  `lead_days` smallint unsigned DEFAULT NULL COMMENT 'Zile de livrare',
  `min_order_qty` decimal(10,3) DEFAULT NULL COMMENT 'Cantitate minimă comandă',
  `order_multiple` decimal(10,3) DEFAULT NULL,
  `po_max_qty` decimal(10,3) DEFAULT NULL,
  `is_preferred` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Furnizor preferat pentru acest produs',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `incoterms` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_includes_transport` tinyint(1) NOT NULL DEFAULT '0',
  `date_start` date DEFAULT NULL,
  `date_end` date DEFAULT NULL,
  `over_delivery_tolerance` decimal(5,2) DEFAULT '0.00',
  `under_delivery_tolerance` decimal(5,2) DEFAULT '0.00',
  `last_purchase_date` date DEFAULT NULL,
  `last_purchase_price` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_suppliers_woo_product_id_supplier_id_unique` (`woo_product_id`,`supplier_id`),
  KEY `product_suppliers_supplier_id_index` (`supplier_id`),
  CONSTRAINT `product_suppliers_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_suppliers_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_id` bigint unsigned NOT NULL,
  `woo_product_id` bigint unsigned DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_sku` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `received_quantity` decimal(10,3) DEFAULT NULL COMMENT 'Cantitate efectiv recepționată — null = nerecepționat încă',
  `unit_price` decimal(10,4) DEFAULT NULL,
  `line_total` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `purchase_request_item_id` bigint unsigned DEFAULT NULL,
  `sources_json` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_order_items_purchase_order_id_foreign` (`purchase_order_id`),
  KEY `purchase_order_items_woo_product_id_foreign` (`woo_product_id`),
  KEY `purchase_order_items_purchase_request_item_id_foreign` (`purchase_request_item_id`),
  CONSTRAINT `purchase_order_items_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_order_items_purchase_request_item_id_foreign` FOREIGN KEY (`purchase_request_item_id`) REFERENCES `purchase_request_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_order_items_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_id` bigint unsigned NOT NULL,
  `buyer_id` bigint unsigned NOT NULL,
  `status` enum('draft','pending_approval','approved','rejected','sent','received','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'draft',
  `total_value` decimal(12,2) NOT NULL DEFAULT '0.00',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'RON',
  `notes_internal` text COLLATE utf8mb4_unicode_ci,
  `notes_supplier` text COLLATE utf8mb4_unicode_ci,
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `sent_at` timestamp NULL DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `received_by` bigint unsigned DEFAULT NULL,
  `received_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_orders_number_unique` (`number`),
  KEY `purchase_orders_supplier_id_foreign` (`supplier_id`),
  KEY `purchase_orders_buyer_id_foreign` (`buyer_id`),
  KEY `purchase_orders_approved_by_foreign` (`approved_by`),
  KEY `purchase_orders_rejected_by_foreign` (`rejected_by`),
  KEY `purchase_orders_received_by_foreign` (`received_by`),
  CONSTRAINT `purchase_orders_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_buyer_id_foreign` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`),
  CONSTRAINT `purchase_orders_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_request_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_request_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `purchase_request_id` bigint unsigned NOT NULL,
  `woo_product_id` bigint unsigned DEFAULT NULL,
  `supplier_id` bigint unsigned DEFAULT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `ordered_quantity` decimal(10,3) NOT NULL DEFAULT '0.000',
  `needed_by` date DEFAULT NULL,
  `is_urgent` tinyint(1) NOT NULL DEFAULT '0',
  `is_reserved` tinyint(1) NOT NULL DEFAULT '0',
  `customer_id` bigint unsigned DEFAULT NULL,
  `offer_id` bigint unsigned DEFAULT NULL,
  `client_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','ordered','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `purchase_order_item_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_request_items_purchase_request_id_foreign` (`purchase_request_id`),
  KEY `purchase_request_items_woo_product_id_foreign` (`woo_product_id`),
  KEY `purchase_request_items_customer_id_foreign` (`customer_id`),
  KEY `purchase_request_items_purchase_order_item_id_foreign` (`purchase_order_item_id`),
  KEY `pri_supplier_status` (`supplier_id`,`status`),
  KEY `pri_status_ordered` (`status`,`ordered_quantity`),
  KEY `purchase_request_items_offer_id_foreign` (`offer_id`),
  CONSTRAINT `purchase_request_items_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_request_items_offer_id_foreign` FOREIGN KEY (`offer_id`) REFERENCES `offers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_request_items_purchase_order_item_id_foreign` FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_request_items_purchase_request_id_foreign` FOREIGN KEY (`purchase_request_id`) REFERENCES `purchase_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_request_items_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_request_items_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `status` enum('draft','submitted','partially_ordered','fully_ordered','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `woo_order_id` bigint unsigned DEFAULT NULL,
  `source_type` enum('manual','woo_order') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_requests_number_unique` (`number`),
  KEY `purchase_requests_user_id_foreign` (`user_id`),
  KEY `purchase_requests_location_id_foreign` (`location_id`),
  KEY `purchase_requests_woo_order_id_foreign` (`woo_order_id`),
  CONSTRAINT `purchase_requests_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `purchase_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `purchase_requests_woo_order_id_foreign` FOREIGN KEY (`woo_order_id`) REFERENCES `woo_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `can_access` tinyint(1) NOT NULL DEFAULT '1',
  `can_create` tinyint(1) NOT NULL DEFAULT '1',
  `can_edit` tinyint(1) NOT NULL DEFAULT '1',
  `can_delete` tinyint(1) NOT NULL DEFAULT '0',
  `can_view` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_permissions_role_resource_unique` (`role`,`resource`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sameday_awbs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sameday_awbs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `location_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `integration_connection_id` bigint unsigned DEFAULT NULL,
  `woo_order_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sameday',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'created',
  `awb_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_id` bigint unsigned DEFAULT NULL,
  `pickup_point_id` bigint unsigned DEFAULT NULL,
  `recipient_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_county` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_city` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_postal_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `package_count` int unsigned NOT NULL DEFAULT '1',
  `package_weight_kg` decimal(8,3) NOT NULL,
  `cod_amount` decimal(12,2) DEFAULT NULL,
  `insured_value` decimal(12,2) DEFAULT NULL,
  `shipping_cost` decimal(12,2) DEFAULT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observation` text COLLATE utf8mb4_unicode_ci,
  `request_payload` json DEFAULT NULL,
  `response_payload` json DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sameday_awbs_user_id_foreign` (`user_id`),
  KEY `sameday_awbs_integration_connection_id_foreign` (`integration_connection_id`),
  KEY `sameday_awbs_location_id_status_index` (`location_id`,`status`),
  KEY `sameday_awbs_provider_awb_number_index` (`provider`,`awb_number`),
  KEY `sameday_awbs_woo_order_id_index` (`woo_order_id`),
  CONSTRAINT `sameday_awbs_integration_connection_id_foreign` FOREIGN KEY (`integration_connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sameday_awbs_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sameday_awbs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sameday_awbs_woo_order_id_foreign` FOREIGN KEY (`woo_order_id`) REFERENCES `woo_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `social_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `social_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'facebook',
  `account_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_token` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `style_analyzed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `social_accounts_platform_is_active_index` (`platform`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `social_fetched_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `social_fetched_posts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `social_account_id` bigint unsigned NOT NULL,
  `platform_post_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `created_time` timestamp NULL DEFAULT NULL,
  `likes_count` int NOT NULL DEFAULT '0',
  `comments_count` int NOT NULL DEFAULT '0',
  `raw_data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `social_fetched_posts_social_account_id_platform_post_id_unique` (`social_account_id`,`platform_post_id`),
  CONSTRAINT `social_fetched_posts_social_account_id_foreign` FOREIGN KEY (`social_account_id`) REFERENCES `social_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `social_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `social_posts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `social_account_id` bigint unsigned NOT NULL,
  `woo_product_id` bigint unsigned DEFAULT NULL,
  `brand_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `brief_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `brief_direction` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `caption` text COLLATE utf8mb4_unicode_ci,
  `hashtags` text COLLATE utf8mb4_unicode_ci,
  `image_path` text COLLATE utf8mb4_unicode_ci,
  `template` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_prompt` text COLLATE utf8mb4_unicode_ci,
  `graphic_title` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `graphic_subtitle` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `graphic_label` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform_post_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `social_posts_woo_product_id_foreign` (`woo_product_id`),
  KEY `social_posts_created_by_foreign` (`created_by`),
  KEY `social_posts_status_index` (`status`),
  KEY `social_posts_scheduled_at_index` (`scheduled_at`),
  KEY `social_posts_social_account_id_index` (`social_account_id`),
  KEY `social_posts_brand_id_foreign` (`brand_id`),
  KEY `idx_sp_status_scheduled` (`status`,`scheduled_at`),
  CONSTRAINT `social_posts_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL,
  CONSTRAINT `social_posts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `social_posts_social_account_id_foreign` FOREIGN KEY (`social_account_id`) REFERENCES `social_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `social_posts_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `social_style_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `social_style_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `social_account_id` bigint unsigned NOT NULL,
  `posts_analyzed` int NOT NULL DEFAULT '0',
  `tone` text COLLATE utf8mb4_unicode_ci,
  `vocabulary` text COLLATE utf8mb4_unicode_ci,
  `hashtag_patterns` text COLLATE utf8mb4_unicode_ci,
  `caption_structure` text COLLATE utf8mb4_unicode_ci,
  `visual_style` text COLLATE utf8mb4_unicode_ci,
  `raw_analysis` longtext COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `generated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `social_style_profiles_social_account_id_is_active_index` (`social_account_id`,`is_active`),
  CONSTRAINT `social_style_profiles_social_account_id_foreign` FOREIGN KEY (`social_account_id`) REFERENCES `social_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `supplier_buyers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_buyers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_buyers_supplier_id_user_id_unique` (`supplier_id`,`user_id`),
  KEY `supplier_buyers_user_id_foreign` (`user_id`),
  CONSTRAINT `supplier_buyers_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supplier_buyers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `supplier_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `email_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `supplier_contacts_supplier_id_foreign` (`supplier_id`),
  CONSTRAINT `supplier_contacts_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `supplier_feeds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_feeds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint unsigned NOT NULL,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `settings` json DEFAULT NULL,
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `last_sync_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_sync_summary` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `supplier_feeds_supplier_id_foreign` (`supplier_id`),
  CONSTRAINT `supplier_feeds_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `supplier_price_quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_price_quotes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint unsigned NOT NULL,
  `email_message_id` bigint unsigned NOT NULL,
  `woo_product_id` bigint unsigned DEFAULT NULL,
  `product_name_raw` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'RON',
  `min_qty` decimal(10,2) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `quoted_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `supplier_price_quotes_email_message_id_foreign` (`email_message_id`),
  KEY `supplier_price_quotes_supplier_id_woo_product_id_quoted_at_index` (`supplier_id`,`woo_product_id`,`quoted_at`),
  KEY `supplier_price_quotes_woo_product_id_quoted_at_index` (`woo_product_id`,`quoted_at`),
  KEY `supplier_price_quotes_quoted_at_index` (`quoted_at`),
  CONSTRAINT `supplier_price_quotes_email_message_id_foreign` FOREIGN KEY (`email_message_id`) REFERENCES `email_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supplier_price_quotes_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supplier_price_quotes_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `vat_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'CUI/CIF',
  `reg_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nr. Reg. Com.',
  `bank_account` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'IBAN',
  `bank_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `conditions` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `buyer_id` bigint unsigned DEFAULT NULL,
  `po_approval_threshold` decimal(10,2) DEFAULT NULL,
  `default_markup` decimal(5,2) DEFAULT NULL COMMENT 'Adaos comercial implicit (%) pentru recalculare prețuri vânzare',
  `default_vat` decimal(5,2) DEFAULT NULL COMMENT 'TVA implicit (%) pentru recalculare prețuri vânzare',
  PRIMARY KEY (`id`),
  KEY `suppliers_buyer_id_foreign` (`buyer_id`),
  CONSTRAINT `suppliers_buyer_id_foreign` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sync_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sync_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `connection_id` bigint unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `stats` json DEFAULT NULL,
  `errors` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sync_runs_location_id_foreign` (`location_id`),
  KEY `sync_runs_provider_status_index` (`provider`,`status`),
  KEY `sync_runs_connection_id_type_index` (`connection_id`,`type`),
  CONSTRAINT `sync_runs_connection_id_foreign` FOREIGN KEY (`connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sync_runs_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `toya_category_proposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `toya_category_proposals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `toya_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Path-ul normalizat din feed Toya (ex: Unelte de grădină / Foarfece)',
  `product_count` int unsigned NOT NULL DEFAULT '0' COMMENT 'Număr produse cu acest path',
  `proposed_woo_category_id` bigint unsigned DEFAULT NULL,
  `alternative_category_ids` json DEFAULT NULL COMMENT 'ID-uri categorii alternative sugerate de AI',
  `confidence` decimal(3,2) DEFAULT NULL COMMENT 'Scor încredere AI 0-1',
  `reasoning` text COLLATE utf8mb4_unicode_ci COMMENT 'Explicația AI',
  `status` enum('pending','approved','rejected','no_match') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `toya_category_proposals_toya_path_unique` (`toya_path`),
  KEY `toya_category_proposals_approved_by_foreign` (`approved_by`),
  KEY `toya_category_proposals_status_index` (`status`),
  KEY `toya_category_proposals_proposed_woo_category_id_index` (`proposed_woo_category_id`),
  CONSTRAINT `toya_category_proposals_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `toya_category_proposals_proposed_woo_category_id_foreign` FOREIGN KEY (`proposed_woo_category_id`) REFERENCES `woo_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'operator',
  `location_id` bigint unsigned DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `is_super_admin` tinyint(1) NOT NULL DEFAULT '0',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_role_index` (`role`),
  KEY `users_location_id_index` (`location_id`),
  KEY `users_is_admin_index` (`is_admin`),
  KEY `users_is_super_admin_index` (`is_super_admin`),
  CONSTRAINT `users_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `woo_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `woo_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `connection_id` bigint unsigned NOT NULL,
  `woo_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` longtext COLLATE utf8mb4_unicode_ci,
  `parent_woo_id` bigint unsigned DEFAULT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `image_url` text COLLATE utf8mb4_unicode_ci,
  `menu_order` int DEFAULT NULL,
  `count` int DEFAULT NULL,
  `data` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `woo_categories_connection_id_woo_id_unique` (`connection_id`,`woo_id`),
  KEY `woo_categories_parent_id_foreign` (`parent_id`),
  KEY `woo_categories_connection_id_parent_woo_id_index` (`connection_id`,`parent_woo_id`),
  KEY `woo_categories_connection_name_idx` (`connection_id`,`name`),
  CONSTRAINT `woo_categories_connection_id_foreign` FOREIGN KEY (`connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `woo_categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `woo_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `woo_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `woo_order_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `woo_item_id` bigint unsigned DEFAULT NULL,
  `woo_product_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `price` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax` decimal(12,2) NOT NULL DEFAULT '0.00',
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `woo_order_items_order_id_index` (`order_id`),
  CONSTRAINT `woo_order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `woo_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `woo_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `woo_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `connection_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned DEFAULT NULL,
  `woo_id` bigint unsigned NOT NULL,
  `number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `currency` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'RON',
  `customer_note` text COLLATE utf8mb4_unicode_ci,
  `billing` json DEFAULT NULL,
  `shipping` json DEFAULT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_method_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `shipping_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `fee_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `date_paid` datetime DEFAULT NULL,
  `date_completed` datetime DEFAULT NULL,
  `order_date` datetime NOT NULL,
  `data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `woo_orders_connection_id_woo_id_unique` (`connection_id`,`woo_id`),
  KEY `woo_orders_location_id_status_index` (`location_id`,`status`),
  KEY `woo_orders_connection_id_status_index` (`connection_id`,`status`),
  CONSTRAINT `woo_orders_connection_id_foreign` FOREIGN KEY (`connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `woo_orders_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `woo_product_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `woo_product_attributes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `woo_product_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `woo_attribute_id` int unsigned DEFAULT NULL,
  `is_visible` tinyint(1) NOT NULL DEFAULT '1',
  `position` tinyint unsigned NOT NULL DEFAULT '0',
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'generated',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `woo_product_attributes_woo_product_id_index` (`woo_product_id`),
  KEY `woo_product_attributes_woo_product_id_name_index` (`woo_product_id`,`name`),
  CONSTRAINT `woo_product_attributes_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `woo_product_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `woo_product_category` (
  `woo_product_id` bigint unsigned NOT NULL,
  `woo_category_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`woo_product_id`,`woo_category_id`),
  KEY `woo_product_category_woo_category_id_foreign` (`woo_category_id`),
  CONSTRAINT `woo_product_category_woo_category_id_foreign` FOREIGN KEY (`woo_category_id`) REFERENCES `woo_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `woo_product_category_woo_product_id_foreign` FOREIGN KEY (`woo_product_id`) REFERENCES `woo_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `woo_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `woo_products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `substituted_by_id` bigint unsigned DEFAULT NULL COMMENT 'ID-ul produsului care înlocuiește acest produs la achiziții viitoare',
  `connection_id` bigint unsigned DEFAULT NULL,
  `woo_id` bigint unsigned DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `winmentor_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `short_description` longtext COLLATE utf8mb4_unicode_ci,
  `description` longtext COLLATE utf8mb4_unicode_ci,
  `regular_price` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sale_price` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stock_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manage_stock` tinyint(1) DEFAULT NULL,
  `unit` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `weight` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dim_length` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dim_width` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dim_height` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qty_per_inner_box` smallint unsigned DEFAULT NULL COMMENT 'Buc/cutie interioară (Inner Box)',
  `qty_per_carton` smallint unsigned DEFAULT NULL COMMENT 'Buc/carton master (Master Carton) — cantitate minimă comandă',
  `cartons_per_pallet` smallint unsigned DEFAULT NULL COMMENT 'Cartoane/palet',
  `ean_carton` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'EAN carton master (pentru recepție cu scanner)',
  `erp_notes` text COLLATE utf8mb4_unicode_ci,
  `procurement_type` enum('stock','on_demand') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stock',
  `is_discontinued` tinyint(1) NOT NULL DEFAULT '0',
  `min_stock_qty` decimal(10,2) DEFAULT NULL,
  `max_stock_qty` decimal(10,2) DEFAULT NULL,
  `safety_stock` decimal(10,2) DEFAULT NULL,
  `reorder_qty` decimal(10,2) DEFAULT NULL,
  `avg_daily_consumption` decimal(10,4) DEFAULT NULL,
  `abc_classification` enum('A','B','C') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `xyz_classification` enum('X','Y','Z') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `replenishment_method` enum('manual','reorder_point','min_max') COLLATE utf8mb4_unicode_ci DEFAULT 'manual',
  `discontinued_reason` text COLLATE utf8mb4_unicode_ci,
  `on_demand_label` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_type` enum('shop','production','pallet_fee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shop' COMMENT 'Clasificare produs: shop=comercializare, production=materie primă producție, pallet_fee=garanție palet',
  `woo_parent_id` bigint unsigned DEFAULT NULL,
  `main_image_url` text COLLATE utf8mb4_unicode_ci,
  `data` json NOT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'woocommerce',
  `is_placeholder` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `country_of_origin` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ISO 3166-1 alpha-2',
  `customs_tariff_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Cod HS/NC',
  `vat_rate` decimal(5,2) DEFAULT '19.00' COMMENT 'Cotă TVA %',
  `volume_m3` decimal(10,6) DEFAULT NULL COMMENT 'Volum în m³',
  `warranty_months` smallint unsigned DEFAULT NULL COMMENT 'Garanție în luni',
  `certification_codes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'CE, ROHS, etc (comma separated)',
  `msds_link` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Link fișă securitate',
  `storage_conditions` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Condiții depozitare',
  `is_fragile` tinyint(1) NOT NULL DEFAULT '0',
  `is_stackable` tinyint(1) NOT NULL DEFAULT '1',
  `is_temperature_sensitive` tinyint(1) NOT NULL DEFAULT '0',
  `shelf_life_days` int unsigned DEFAULT NULL COMMENT 'Termen valabilitate zile',
  PRIMARY KEY (`id`),
  UNIQUE KEY `woo_products_connection_id_woo_id_unique` (`connection_id`,`woo_id`),
  KEY `woo_products_connection_id_sku_index` (`connection_id`,`sku`),
  KEY `woo_products_connection_name_idx` (`connection_id`,`name`),
  KEY `woo_products_connection_slug_idx` (`connection_id`,`slug`),
  KEY `woo_products_connection_source_idx` (`connection_id`,`source`),
  KEY `woo_products_connection_placeholder_idx` (`connection_id`,`is_placeholder`),
  KEY `woo_products_procurement_type_index` (`procurement_type`),
  KEY `woo_products_is_discontinued_index` (`is_discontinued`),
  KEY `woo_products_substituted_by_id_foreign` (`substituted_by_id`),
  FULLTEXT KEY `woo_products_fulltext_search` (`name`,`winmentor_name`,`sku`),
  CONSTRAINT `woo_products_connection_id_foreign` FOREIGN KEY (`connection_id`) REFERENCES `integration_connections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `woo_products_substituted_by_id_foreign` FOREIGN KEY (`substituted_by_id`) REFERENCES `woo_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_02_21_172615_create_locations_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_02_21_173809_add_store_id_to_locations_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_02_21_174910_add_role_and_location_id_to_users_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_02_21_183724_add_admin_flags_to_users_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_02_21_185501_create_integration_connections_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_02_21_185501_create_sync_runs_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_02_21_185501_create_woo_catalog_tables',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_02_21_202638_add_search_indexes_to_woo_catalog_tables',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_02_21_205251_create_product_stocks_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_02_21_205252_create_product_price_logs_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_02_21_213044_add_placeholder_fields_to_woo_products_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_02_21_230452_create_offers_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_02_21_230453_create_offer_items_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_02_21_235900_create_company_api_settings_table',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_02_22_000100_add_company_contact_and_banking_fields_to_locations_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_02_22_000400_add_company_is_vat_payer_to_locations_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_02_22_000700_create_customers_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_02_22_000701_create_customer_delivery_addresses_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_02_23_070001_create_sameday_awbs_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_02_23_120000_create_daily_stock_metrics_table',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_02_23_121000_switch_daily_stock_metrics_to_reference_product_id',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_02_24_135133_make_location_id_nullable_in_integration_connections',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_02_25_215106_add_erp_fields_to_woo_products_and_create_suppliers_tables',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_02_25_215142_populate_erp_fields_from_json_in_woo_products',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_02_25_221152_add_brand_to_woo_products',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_02_25_221808_create_product_image_candidates_table',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_02_26_000709_create_woo_product_attributes_table',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_02_26_073111_add_source_to_product_image_candidates',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_02_26_203301_create_supplier_contacts_table',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_02_26_203302_create_brands_table',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_02_26_203303_create_brand_supplier_table',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_02_26_230644_add_logo_url_to_suppliers_table',26);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_02_27_100001_create_woo_orders_table',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_02_27_100002_create_woo_order_items_table',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_02_27_100003_add_woo_order_id_to_sameday_awbs_table',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_02_27_072927_add_webhook_secret_to_integration_connections_table',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_02_27_083956_create_notifications_table',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_02_27_112411_create_product_review_requests_table',30);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_02_27_113023_create_app_settings_table',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_02_27_200000_create_ean_association_requests_table',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_02_27_174448_create_bi_analyses_table',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_02_27_175535_add_status_to_bi_analyses_table',34);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_02_27_200001_create_bi_inventory_kpi_daily_table',35);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_02_27_200002_create_bi_product_velocity_current_table',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_02_27_200003_create_bi_inventory_alert_candidates_daily_table',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_02_27_220000_create_bi_inventory_kpi_daily_table',38);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_02_27_220001_create_bi_product_velocity_current_table',38);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_02_27_220002_create_bi_inventory_alert_candidates_daily_table',38);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_02_27_225603_alter_bi_analyses_add_type_nullable_generated_by',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_02_28_074459_add_buyer_fields_to_suppliers_table',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_02_28_074459_add_po_max_qty_to_product_suppliers_table',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_02_28_074501_create_purchase_requests_table',41);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_02_28_074502_create_purchase_request_items_table',41);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_02_28_074503_create_purchase_orders_table',41);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_02_28_074504_create_purchase_order_items_table',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_02_28_083557_add_sources_json_to_purchase_order_items_table',43);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_03_01_100000_add_product_type_to_woo_products_table',44);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_03_02_083805_add_customer_id_to_purchase_request_items',45);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_03_02_085523_create_role_permissions_table',46);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_03_02_000001_create_product_supplier_logs_table',47);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_03_02_000002_add_photo_path_to_product_review_requests_table',48);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_03_02_100000_add_winmentor_name_to_woo_products_table',49);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_03_02_182355_create_email_messages_table',50);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_03_02_204752_add_activity_fields_to_supplier_contacts',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_03_02_204752_add_supplier_contact_id_to_email_messages',51);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_03_02_205022_create_email_entities_table',52);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_03_02_205022_create_supplier_price_quotes_table',52);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_03_03_073734_add_is_ignored_to_email_messages',53);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_03_03_093815_create_chat_logs_table',53);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_03_03_120000_add_tokens_to_chat_logs',54);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_03_03_130000_create_chat_contacts_table',55);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_03_03_200000_add_summary_to_chat_contacts',56);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_03_03_100000_add_received_to_purchase_orders',57);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_03_03_110000_add_ordered_quantity_to_purchase_request_items',58);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_03_03_120000_add_received_quantity_to_purchase_order_items',59);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_03_03_130000_fix_purchase_request_items_constraints',60);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_03_04_100000_add_contacted_to_chat_contacts',61);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_03_04_120000_add_page_url_to_chat_logs',62);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_03_05_100000_add_fulltext_index_to_woo_products',63);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_03_05_180945_add_department_to_supplier_contacts',64);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_03_05_185537_add_conditions_to_suppliers',65);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_03_07_100000_add_offer_id_to_purchase_request_items',66);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_03_09_120000_create_ai_usage_logs_table',67);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_03_09_140000_add_on_demand_to_woo_products',68);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_03_09_150000_add_discontinued_to_woo_products',69);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_03_12_100000_create_social_accounts_table',70);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_03_12_110000_create_social_posts_table',70);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_03_12_120000_create_social_style_profiles_table',70);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_03_12_130000_create_social_fetched_posts_table',70);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_03_12_140000_add_visual_style_to_social_style_profiles',71);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_03_12_200000_add_brand_id_to_social_posts',72);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_03_12_210000_add_template_to_social_posts',73);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_03_12_220000_add_graphic_texts_to_social_posts',74);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_03_12_230000_create_graphic_templates_table',75);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_03_12_240000_add_layout_to_graphic_templates',76);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_03_15_100000_encrypt_webhook_secrets_in_integration_connections',77);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_03_15_110000_add_primary_key_to_woo_product_category',78);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_03_15_120000_add_performance_indexes',78);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_03_16_100000_add_stock_qty_limits_to_woo_products',79);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_03_17_100000_make_woo_products_connection_id_nullable',80);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_03_17_100001_make_woo_products_woo_id_nullable',81);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_03_17_200000_create_toya_category_proposals_table',82);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_03_17_210000_add_packaging_fields_to_woo_products',83);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_03_17_220000_add_pricing_defaults_to_suppliers',84);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_03_17_230000_add_substituted_by_to_woo_products',85);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_03_17_240000_create_product_substitution_proposals_table',86);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_03_17_300000_create_supplier_buyers_table',87);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_03_23_110000_create_supplier_feeds_table',88);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2026_03_25_170000_add_order_multiple_and_phase2_fields_to_product_suppliers',89);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2026_03_25_170001_create_product_supplier_price_breaks_table',89);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2026_03_25_170002_add_planning_fields_to_woo_products',89);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'2026_03_25_180000_add_compliance_logistics_fields_to_woo_products',90);
