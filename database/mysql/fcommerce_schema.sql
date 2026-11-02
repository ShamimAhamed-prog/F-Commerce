-- F-Commerce SaaS: MySQL 8 schema (InnoDB, utf8mb4)
-- Generated from the Laravel migrations with Laravel's MySQL grammar on 2026-11-02.
-- Import:  mysql -u USER -p < fcommerce_schema.sql
-- Then point .env at it (DB_CONNECTION=mysql, DB_DATABASE=fcommerce). `php artisan migrate` will report nothing to migrate.
-- Contains the schema, the migrations bookkeeping rows and the built-in roles/permissions. No tenant or user data.

CREATE DATABASE IF NOT EXISTS `fcommerce` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fcommerce`;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

create table `migrations` (`id` int unsigned not null auto_increment primary key, `migration` varchar(255) not null, `batch` int not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

-- ---- 0001_01_01_000000_create_users_table
create table `users` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) not null, `email` varchar(255) not null, `email_verified_at` timestamp null, `password` varchar(255) not null, `remember_token` varchar(100) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `users` add unique `users_email_unique`(`email`);
create table `password_reset_tokens` (`email` varchar(255) not null, `token` varchar(255) not null, `created_at` timestamp null, primary key (`email`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
create table `sessions` (`id` varchar(255) not null, `user_id` bigint unsigned null, `ip_address` varchar(45) null, `user_agent` text null, `payload` longtext not null, `last_activity` int not null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `sessions` add index `sessions_user_id_index`(`user_id`);
alter table `sessions` add index `sessions_last_activity_index`(`last_activity`);

-- ---- 0001_01_01_000001_create_cache_table
create table `cache` (`key` varchar(255) not null, `value` mediumtext not null, `expiration` int not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `cache` add index `cache_expiration_index`(`expiration`);
create table `cache_locks` (`key` varchar(255) not null, `owner` varchar(255) not null, `expiration` int not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `cache_locks` add index `cache_locks_expiration_index`(`expiration`);

-- ---- 0001_01_01_000002_create_jobs_table
create table `jobs` (`id` bigint unsigned not null auto_increment primary key, `queue` varchar(255) not null, `payload` longtext not null, `attempts` tinyint unsigned not null, `reserved_at` int unsigned null, `available_at` int unsigned not null, `created_at` int unsigned not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `jobs` add index `jobs_queue_index`(`queue`);
create table `job_batches` (`id` varchar(255) not null, `name` varchar(255) not null, `total_jobs` int not null, `pending_jobs` int not null, `failed_jobs` int not null, `failed_job_ids` longtext not null, `options` mediumtext null, `cancelled_at` int null, `created_at` int not null, `finished_at` int null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
create table `failed_jobs` (`id` bigint unsigned not null auto_increment primary key, `uuid` varchar(255) not null, `connection` text not null, `queue` text not null, `payload` longtext not null, `exception` longtext not null, `failed_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `failed_jobs` add unique `failed_jobs_uuid_unique`(`uuid`);

-- ---- 2026_11_02_000001_add_super_admin_to_users
alter table `users` add `phone` varchar(32) null after `email`;
alter table `users` add `is_super_admin` tinyint(1) not null default '0' after `password`;
alter table `users` add `locale` varchar(5) not null default 'en' after `is_super_admin`;

-- ---- 2026_11_02_000002_create_tenancy_tables
create table `tenants` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) not null, `slug` varchar(255) not null, `phone` varchar(32) null, `address` text null, `timezone` varchar(64) not null default 'Asia/Dhaka', `currency` varchar(3) not null default 'BDT', `invoice_details` json null, `settings` json null, `status` varchar(20) not null default 'active', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `tenants` add unique `tenants_slug_unique`(`slug`);
create table `permissions` (`id` bigint unsigned not null auto_increment primary key, `slug` varchar(255) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `permissions` add unique `permissions_slug_unique`(`slug`);
create table `roles` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned null, `slug` varchar(255) not null, `name` varchar(255) not null, `is_system` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `roles` add constraint `roles_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `roles` add unique `roles_tenant_id_slug_unique`(`tenant_id`, `slug`);
create table `role_permissions` (`role_id` bigint unsigned not null, `permission_id` bigint unsigned not null, primary key (`role_id`, `permission_id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `role_permissions` add constraint `role_permissions_role_id_foreign` foreign key (`role_id`) references `roles` (`id`) on delete cascade;
alter table `role_permissions` add constraint `role_permissions_permission_id_foreign` foreign key (`permission_id`) references `permissions` (`id`) on delete cascade;
create table `tenant_user` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `user_id` bigint unsigned not null, `role_id` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `tenant_user` add constraint `tenant_user_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `tenant_user` add constraint `tenant_user_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `tenant_user` add constraint `tenant_user_role_id_foreign` foreign key (`role_id`) references `roles` (`id`) on delete restrict;
alter table `tenant_user` add unique `tenant_user_tenant_id_user_id_unique`(`tenant_id`, `user_id`);
create table `audit_logs` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned null, `user_id` bigint unsigned null, `action` varchar(100) not null, `subject_type` varchar(100) null, `subject_id` bigint unsigned null, `old_values` json null, `new_values` json null, `meta` json null, `request_id` varchar(64) null, `ip` varchar(45) null, `user_agent` varchar(255) null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `audit_logs` add index `audit_logs_tenant_id_created_at_index`(`tenant_id`, `created_at`);
alter table `audit_logs` add index `audit_logs_subject_type_subject_id_index`(`subject_type`, `subject_id`);
alter table `audit_logs` add index `audit_logs_tenant_id_index`(`tenant_id`);
alter table `audit_logs` add index `audit_logs_user_id_index`(`user_id`);
alter table `audit_logs` add index `audit_logs_action_index`(`action`);

-- ---- 2026_11_03_000001_create_catalog_tables
create table `categories` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `name` varchar(255) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `categories` add constraint `categories_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `categories` add unique `categories_tenant_id_name_unique`(`tenant_id`, `name`);
create table `brands` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `name` varchar(255) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `brands` add constraint `brands_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `brands` add unique `brands_tenant_id_name_unique`(`tenant_id`, `name`);
create table `products` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `name` varchar(255) not null, `sku` varchar(100) not null, `slug` varchar(255) null, `description` text null, `category_id` bigint unsigned null, `brand_id` bigint unsigned null, `type` varchar(10) not null default 'simple', `purchase_cost` decimal(18, 2) not null default '0', `selling_price` decimal(18, 2) not null default '0', `compare_at_price` decimal(18, 2) null, `tax_rate` decimal(5, 2) null, `barcode` varchar(100) null, `weight` decimal(10, 3) null, `dimensions` varchar(60) null, `status` varchar(10) not null default 'draft', `reorder_threshold` int unsigned not null default '0', `created_by` bigint unsigned null, `updated_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `products` add constraint `products_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `products` add constraint `products_category_id_foreign` foreign key (`category_id`) references `categories` (`id`) on delete set null;
alter table `products` add constraint `products_brand_id_foreign` foreign key (`brand_id`) references `brands` (`id`) on delete set null;
alter table `products` add unique `products_tenant_id_sku_unique`(`tenant_id`, `sku`);
alter table `products` add index `products_tenant_id_status_index`(`tenant_id`, `status`);
alter table `products` add index `products_tenant_id_category_id_index`(`tenant_id`, `category_id`);
create table `product_variants` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `product_id` bigint unsigned not null, `sku` varchar(100) not null, `barcode` varchar(100) null, `options` json not null, `selling_price` decimal(18, 2) null, `purchase_cost` decimal(18, 2) null, `position` int unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `product_variants` add constraint `product_variants_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `product_variants` add constraint `product_variants_product_id_foreign` foreign key (`product_id`) references `products` (`id`) on delete cascade;
alter table `product_variants` add unique `product_variants_tenant_id_sku_unique`(`tenant_id`, `sku`);
alter table `product_variants` add index `product_variants_tenant_id_product_id_index`(`tenant_id`, `product_id`);
create table `media_files` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `disk` varchar(30) not null, `path` varchar(255) not null, `original_path` varchar(255) null, `mime` varchar(60) not null, `size` bigint unsigned not null, `width` int unsigned null, `height` int unsigned null, `checksum` varchar(64) null, `derivatives` json null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `media_files` add constraint `media_files_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `media_files` add index `media_files_tenant_id_index`(`tenant_id`);
create table `product_images` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `product_id` bigint unsigned not null, `media_file_id` bigint unsigned null, `position` int unsigned not null, `is_primary` tinyint(1) not null default '0', `alt_text` varchar(200) null, `caption` varchar(300) null, `status` varchar(12) not null default 'processing', `error` varchar(200) null, `version` int unsigned not null default '1', `created_by` bigint unsigned null, `updated_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `product_images` add constraint `product_images_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `product_images` add constraint `product_images_product_id_foreign` foreign key (`product_id`) references `products` (`id`) on delete cascade;
alter table `product_images` add constraint `product_images_media_file_id_foreign` foreign key (`media_file_id`) references `media_files` (`id`) on delete set null;
alter table `product_images` add index `product_images_tenant_id_product_id_position_index`(`tenant_id`, `product_id`, `position`);
create table `product_image_variant` (`product_image_id` bigint unsigned not null, `product_variant_id` bigint unsigned not null, primary key (`product_image_id`, `product_variant_id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `product_image_variant` add constraint `product_image_variant_product_image_id_foreign` foreign key (`product_image_id`) references `product_images` (`id`) on delete cascade;
alter table `product_image_variant` add constraint `product_image_variant_product_variant_id_foreign` foreign key (`product_variant_id`) references `product_variants` (`id`) on delete cascade;

-- ---- 2026_11_03_000002_create_stock_and_import_tables
create table `stock_movements` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `product_id` bigint unsigned not null, `product_variant_id` bigint unsigned null, `type` varchar(20) not null, `qty` int not null, `unit_cost` decimal(18, 4) not null default '0', `source_type` varchar(60) null, `source_id` bigint unsigned null, `reason` varchar(200) null, `actor_id` bigint unsigned null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `stock_movements` add constraint `stock_movements_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `stock_movements` add constraint `stock_movements_product_id_foreign` foreign key (`product_id`) references `products` (`id`) on delete restrict;
alter table `stock_movements` add constraint `stock_movements_product_variant_id_foreign` foreign key (`product_variant_id`) references `product_variants` (`id`) on delete restrict;
alter table `stock_movements` add index `stock_movements_tenant_id_product_id_created_at_index`(`tenant_id`, `product_id`, `created_at`);
create table `stock_balances` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `product_id` bigint unsigned not null, `variant_id` bigint unsigned not null default '0', `on_hand` int not null default '0', `reserved` int not null default '0', `avg_cost` decimal(18, 4) not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `stock_balances` add constraint `stock_balances_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `stock_balances` add constraint `stock_balances_product_id_foreign` foreign key (`product_id`) references `products` (`id`) on delete cascade;
alter table `stock_balances` add unique `stock_balances_tenant_id_product_id_variant_id_unique`(`tenant_id`, `product_id`, `variant_id`);
create table `imports` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `type` varchar(30) not null, `status` varchar(24) not null default 'uploaded', `original_name` varchar(255) not null, `path` varchar(255) not null, `headers` json null, `ignored_columns` json null, `mapping` json null, `options` json null, `total_rows` int unsigned not null default '0', `created_count` int unsigned not null default '0', `updated_count` int unsigned not null default '0', `skipped_count` int unsigned not null default '0', `failed_count` int unsigned not null default '0', `error` varchar(300) null, `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `imports` add constraint `imports_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `imports` add index `imports_tenant_id_type_created_at_index`(`tenant_id`, `type`, `created_at`);
create table `import_rows` (`id` bigint unsigned not null auto_increment primary key, `import_id` bigint unsigned not null, `row_number` int unsigned not null, `raw_data` json not null, `normalized_data` json null, `status` varchar(12) not null default 'pending', `errors` json null, `record_type` varchar(40) null, `record_id` bigint unsigned null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `import_rows` add constraint `import_rows_import_id_foreign` foreign key (`import_id`) references `imports` (`id`) on delete cascade;
alter table `import_rows` add index `import_rows_import_id_status_index`(`import_id`, `status`);
create table `import_mappings` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `type` varchar(30) not null, `mapping` json not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `import_mappings` add constraint `import_mappings_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `import_mappings` add unique `import_mappings_tenant_id_type_unique`(`tenant_id`, `type`);

-- ---- 2026_11_04_000001_create_sales_tables
create table `customers` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `name` varchar(255) not null, `phone` varchar(32) not null, `phone_normalized` varchar(20) not null, `alt_phone` varchar(32) null, `address` text null, `area` varchar(255) null, `notes` text null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `customers` add constraint `customers_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `customers` add unique `customers_tenant_id_phone_normalized_unique`(`tenant_id`, `phone_normalized`);
create table `orders` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `seq` int unsigned not null, `order_number` varchar(30) not null, `source` varchar(20) not null default 'manual', `customer_id` bigint unsigned not null, `customer_name` varchar(255) not null, `phone` varchar(32) not null, `address` text not null, `area` varchar(255) null, `items_subtotal` decimal(18, 2) not null default '0', `item_discount` decimal(18, 2) not null default '0', `order_discount` decimal(18, 2) not null default '0', `shipping_charge` decimal(18, 2) not null default '0', `other_charges` decimal(18, 2) not null default '0', `tax_amount` decimal(18, 2) not null default '0', `total` decimal(18, 2) not null default '0', `advance` decimal(18, 2) not null default '0', `cod_amount` decimal(18, 2) not null default '0', `status` varchar(24) not null default 'pending_confirmation', `stock_state` varchar(10) not null default 'none', `notes` text null, `external_ref` varchar(100) null, `idempotency_key` varchar(64) null, `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `orders` add constraint `orders_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `orders` add constraint `orders_customer_id_foreign` foreign key (`customer_id`) references `customers` (`id`) on delete restrict;
alter table `orders` add unique `orders_tenant_id_seq_unique`(`tenant_id`, `seq`);
alter table `orders` add unique `orders_tenant_id_order_number_unique`(`tenant_id`, `order_number`);
alter table `orders` add unique `orders_tenant_id_external_ref_unique`(`tenant_id`, `external_ref`);
alter table `orders` add unique `orders_tenant_id_idempotency_key_unique`(`tenant_id`, `idempotency_key`);
alter table `orders` add index `orders_tenant_id_status_created_at_index`(`tenant_id`, `status`, `created_at`);
alter table `orders` add index `orders_tenant_id_phone_index`(`tenant_id`, `phone`);
create table `order_items` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `order_id` bigint unsigned not null, `product_id` bigint unsigned not null, `product_variant_id` bigint unsigned null, `sku` varchar(100) not null, `name` varchar(255) not null, `qty` int unsigned not null, `unit_price` decimal(18, 2) not null, `discount` decimal(18, 2) not null default '0', `line_total` decimal(18, 2) not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `order_items` add constraint `order_items_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `order_items` add constraint `order_items_order_id_foreign` foreign key (`order_id`) references `orders` (`id`) on delete cascade;
alter table `order_items` add constraint `order_items_product_id_foreign` foreign key (`product_id`) references `products` (`id`) on delete restrict;
alter table `order_items` add constraint `order_items_product_variant_id_foreign` foreign key (`product_variant_id`) references `product_variants` (`id`) on delete restrict;
create table `order_status_histories` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `order_id` bigint unsigned not null, `old_status` varchar(24) null, `new_status` varchar(24) not null, `actor_id` bigint unsigned null, `note` varchar(300) null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `order_status_histories` add constraint `order_status_histories_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `order_status_histories` add constraint `order_status_histories_order_id_foreign` foreign key (`order_id`) references `orders` (`id`) on delete cascade;

-- ---- 2026_11_05_000001_create_tenant_invitations
create table `tenant_invitations` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `email` varchar(255) not null, `role_id` bigint unsigned not null, `token_hash` varchar(64) not null, `invited_by` bigint unsigned null, `expires_at` timestamp not null, `accepted_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `tenant_invitations` add constraint `tenant_invitations_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `tenant_invitations` add constraint `tenant_invitations_role_id_foreign` foreign key (`role_id`) references `roles` (`id`) on delete restrict;
alter table `tenant_invitations` add index `tenant_invitations_tenant_id_email_index`(`tenant_id`, `email`);
alter table `tenant_invitations` add unique `tenant_invitations_token_hash_unique`(`token_hash`);

-- ---- 2026_11_06_000001_create_courier_integrations
create table `courier_integrations` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `provider` varchar(30) not null, `display_name` varchar(100) not null, `credentials` text not null, `config` json null, `status` varchar(12) not null default 'pending', `last_tested_at` timestamp null, `last_error_code` varchar(40) null, `created_by` bigint unsigned null, `updated_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `courier_integrations` add constraint `courier_integrations_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `courier_integrations` add unique `courier_integrations_tenant_id_provider_unique`(`tenant_id`, `provider`);

-- ---- 2026_11_07_000001_create_accounting_tables
create table `accounts` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `code` varchar(10) not null, `name` varchar(255) not null, `type` varchar(10) not null, `is_system` tinyint(1) not null default '0', `is_active` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `accounts` add constraint `accounts_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `accounts` add unique `accounts_tenant_id_code_unique`(`tenant_id`, `code`);
create table `journal_entries` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `seq` int unsigned not null, `entry_number` varchar(20) not null, `transaction_date` date not null, `posting_date` date not null, `memo` varchar(300) not null, `source_type` varchar(40) null, `source_key` varchar(100) null, `source_id` bigint unsigned null, `reverses_entry_id` bigint unsigned null, `created_by` bigint unsigned null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `journal_entries` add constraint `journal_entries_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `journal_entries` add unique `journal_entries_tenant_id_seq_unique`(`tenant_id`, `seq`);
alter table `journal_entries` add unique `journal_entries_tenant_id_source_key_unique`(`tenant_id`, `source_key`);
alter table `journal_entries` add index `journal_entries_tenant_id_transaction_date_index`(`tenant_id`, `transaction_date`);
create table `journal_entry_lines` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `journal_entry_id` bigint unsigned not null, `account_id` bigint unsigned not null, `debit` decimal(18, 2) not null default '0', `credit` decimal(18, 2) not null default '0', `description` varchar(200) null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `journal_entry_lines` add constraint `journal_entry_lines_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `journal_entry_lines` add constraint `journal_entry_lines_journal_entry_id_foreign` foreign key (`journal_entry_id`) references `journal_entries` (`id`) on delete restrict;
alter table `journal_entry_lines` add constraint `journal_entry_lines_account_id_foreign` foreign key (`account_id`) references `accounts` (`id`) on delete restrict;
alter table `journal_entry_lines` add index `journal_entry_lines_tenant_id_account_id_index`(`tenant_id`, `account_id`);
create table `financial_accounts` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `account_id` bigint unsigned not null, `name` varchar(255) not null, `type` varchar(10) not null, `is_active` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `financial_accounts` add constraint `financial_accounts_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `financial_accounts` add constraint `financial_accounts_account_id_foreign` foreign key (`account_id`) references `accounts` (`id`) on delete restrict;
alter table `financial_accounts` add unique `financial_accounts_tenant_id_account_id_unique`(`tenant_id`, `account_id`);
create table `expense_categories` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `name` varchar(255) not null, `account_id` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `expense_categories` add constraint `expense_categories_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `expense_categories` add constraint `expense_categories_account_id_foreign` foreign key (`account_id`) references `accounts` (`id`) on delete restrict;
alter table `expense_categories` add unique `expense_categories_tenant_id_name_unique`(`tenant_id`, `name`);
create table `expenses` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `expense_date` date not null, `expense_category_id` bigint unsigned not null, `amount` decimal(18, 2) not null, `financial_account_id` bigint unsigned not null, `vendor` varchar(255) null, `reference` varchar(100) null, `description` varchar(500) null, `status` varchar(8) not null default 'paid', `entry_id` bigint unsigned null, `void_entry_id` bigint unsigned null, `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `expenses` add constraint `expenses_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `expenses` add constraint `expenses_expense_category_id_foreign` foreign key (`expense_category_id`) references `expense_categories` (`id`) on delete restrict;
alter table `expenses` add constraint `expenses_financial_account_id_foreign` foreign key (`financial_account_id`) references `financial_accounts` (`id`) on delete restrict;
alter table `expenses` add index `expenses_tenant_id_expense_date_index`(`tenant_id`, `expense_date`);
create table `capital_contributions` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `contribution_date` date not null, `amount` decimal(18, 2) not null, `financial_account_id` bigint unsigned not null, `note` varchar(300) null, `entry_id` bigint unsigned null, `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `capital_contributions` add constraint `capital_contributions_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `capital_contributions` add constraint `capital_contributions_financial_account_id_foreign` foreign key (`financial_account_id`) references `financial_accounts` (`id`) on delete restrict;
create table `owner_drawings` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `drawing_date` date not null, `amount` decimal(18, 2) not null, `financial_account_id` bigint unsigned not null, `note` varchar(300) null, `entry_id` bigint unsigned null, `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `owner_drawings` add constraint `owner_drawings_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `owner_drawings` add constraint `owner_drawings_financial_account_id_foreign` foreign key (`financial_account_id`) references `financial_accounts` (`id`) on delete restrict;
create table `cod_settlements` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `order_id` bigint unsigned not null, `settled_date` date not null, `received` decimal(18, 2) not null, `fee` decimal(18, 2) not null default '0', `financial_account_id` bigint unsigned not null, `reference` varchar(100) null, `entry_id` bigint unsigned null, `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `cod_settlements` add constraint `cod_settlements_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `cod_settlements` add constraint `cod_settlements_order_id_foreign` foreign key (`order_id`) references `orders` (`id`) on delete restrict;
alter table `cod_settlements` add constraint `cod_settlements_financial_account_id_foreign` foreign key (`financial_account_id`) references `financial_accounts` (`id`) on delete restrict;
create table `period_locks` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `locked_through` date not null, `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `period_locks` add constraint `period_locks_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;

-- ---- 2026_11_07_000002_accounting_idempotency
alter table `expenses` add `idempotency_key` varchar(64) null;
alter table `expenses` add unique `expenses_idem_unique`(`tenant_id`, `idempotency_key`);
alter table `capital_contributions` add `idempotency_key` varchar(64) null;
alter table `capital_contributions` add unique `capital_contributions_idem_unique`(`tenant_id`, `idempotency_key`);
alter table `owner_drawings` add `idempotency_key` varchar(64) null;
alter table `owner_drawings` add unique `owner_drawings_idem_unique`(`tenant_id`, `idempotency_key`);
alter table `cod_settlements` add `idempotency_key` varchar(64) null;
alter table `cod_settlements` add unique `cod_settlements_idem_unique`(`tenant_id`, `idempotency_key`);

-- ---- 2026_11_08_000001_loans_and_receipts
create table `loans` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `lender` varchar(150) not null, `principal` decimal(18, 2) not null, `received_date` date not null, `financial_account_id` bigint unsigned not null, `note` varchar(300) null, `entry_id` bigint unsigned null, `idempotency_key` varchar(64) null, `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `loans` add constraint `loans_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `loans` add constraint `loans_financial_account_id_foreign` foreign key (`financial_account_id`) references `financial_accounts` (`id`) on delete restrict;
alter table `loans` add unique `loans_tenant_id_idempotency_key_unique`(`tenant_id`, `idempotency_key`);
create table `loan_transactions` (`id` bigint unsigned not null auto_increment primary key, `tenant_id` bigint unsigned not null, `loan_id` bigint unsigned not null, `tx_date` date not null, `principal` decimal(18, 2) not null default '0', `interest` decimal(18, 2) not null default '0', `financial_account_id` bigint unsigned not null, `note` varchar(300) null, `entry_id` bigint unsigned null, `idempotency_key` varchar(64) null, `created_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;
alter table `loan_transactions` add constraint `loan_transactions_tenant_id_foreign` foreign key (`tenant_id`) references `tenants` (`id`) on delete cascade;
alter table `loan_transactions` add constraint `loan_transactions_loan_id_foreign` foreign key (`loan_id`) references `loans` (`id`) on delete restrict;
alter table `loan_transactions` add constraint `loan_transactions_financial_account_id_foreign` foreign key (`financial_account_id`) references `financial_accounts` (`id`) on delete restrict;
alter table `loan_transactions` add unique `loan_transactions_tenant_id_idempotency_key_unique`(`tenant_id`, `idempotency_key`);
alter table `expenses` add `receipt_path` varchar(255) null;
alter table `expenses` add `receipt_mime` varchar(60) null;

-- ---- migrations bookkeeping
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('0001_01_01_000000_create_users_table', 1),
  ('0001_01_01_000001_create_cache_table', 1),
  ('0001_01_01_000002_create_jobs_table', 1),
  ('2026_11_02_000001_add_super_admin_to_users', 1),
  ('2026_11_02_000002_create_tenancy_tables', 1),
  ('2026_11_03_000001_create_catalog_tables', 1),
  ('2026_11_03_000002_create_stock_and_import_tables', 1),
  ('2026_11_04_000001_create_sales_tables', 1),
  ('2026_11_05_000001_create_tenant_invitations', 1),
  ('2026_11_06_000001_create_courier_integrations', 1),
  ('2026_11_07_000001_create_accounting_tables', 1),
  ('2026_11_07_000002_accounting_idempotency', 1),
  ('2026_11_08_000001_loans_and_receipts', 1);

-- ---- built-in permissions, roles and role map (config/permissions.php)
INSERT INTO `permissions` (`id`, `slug`, `created_at`, `updated_at`) VALUES
  (1, 'dashboard.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (2, 'products.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (3, 'products.create', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (4, 'products.update', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (5, 'products.delete', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (6, 'products.import', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (7, 'products.images.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (8, 'products.images.manage', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (9, 'products.costs.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (10, 'inventory.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (11, 'inventory.adjust', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (12, 'orders.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (13, 'orders.create', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (14, 'orders.update', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (15, 'orders.cancel', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (16, 'orders.import', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (17, 'customers.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (18, 'customers.manage', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (19, 'shipments.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (20, 'shipments.manage', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (21, 'integrations.manage', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (22, 'accounting.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (23, 'accounting.post', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (24, 'accounting.period_close', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (25, 'expenses.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (26, 'expenses.manage', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (27, 'reports.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (28, 'profit.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (29, 'staff.manage', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (30, 'roles.manage', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (31, 'settings.manage', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (32, 'subscription.manage', '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (33, 'audit.view', '2026-11-02 10:59:20', '2026-11-02 10:59:20');
INSERT INTO `roles` (`id`, `tenant_id`, `slug`, `name`, `is_system`, `created_at`, `updated_at`) VALUES
  (1, NULL, 'owner', 'Tenant Owner', 1, '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (2, NULL, 'admin', 'Admin', 1, '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (3, NULL, 'manager', 'Manager', 1, '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (4, NULL, 'order_staff', 'Order Staff', 1, '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (5, NULL, 'inventory_staff', 'Inventory Staff', 1, '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (6, NULL, 'accounts_staff', 'Accounts Staff', 1, '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (7, NULL, 'delivery_staff', 'Delivery Staff', 1, '2026-11-02 10:59:20', '2026-11-02 10:59:20'),
  (8, NULL, 'viewer', 'Viewer', 1, '2026-11-02 10:59:20', '2026-11-02 10:59:20');
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
  (1, 1),
  (1, 2),
  (1, 3),
  (1, 4),
  (1, 5),
  (1, 6),
  (1, 7),
  (1, 8),
  (1, 9),
  (1, 10),
  (1, 11),
  (1, 12),
  (1, 13),
  (1, 14),
  (1, 15),
  (1, 16),
  (1, 17),
  (1, 18),
  (1, 19),
  (1, 20),
  (1, 21),
  (1, 22),
  (1, 23),
  (1, 24),
  (1, 25),
  (1, 26),
  (1, 27),
  (1, 28),
  (1, 29),
  (1, 30),
  (1, 31),
  (1, 32),
  (1, 33),
  (2, 1),
  (2, 2),
  (2, 3),
  (2, 4),
  (2, 5),
  (2, 6),
  (2, 7),
  (2, 8),
  (2, 9),
  (2, 10),
  (2, 11),
  (2, 12),
  (2, 13),
  (2, 14),
  (2, 15),
  (2, 16),
  (2, 17),
  (2, 18),
  (2, 19),
  (2, 20),
  (2, 21),
  (2, 22),
  (2, 23),
  (2, 25),
  (2, 26),
  (2, 27),
  (2, 28),
  (2, 29),
  (2, 31),
  (2, 33),
  (3, 1),
  (3, 2),
  (3, 3),
  (3, 4),
  (3, 6),
  (3, 7),
  (3, 8),
  (3, 10),
  (3, 11),
  (3, 12),
  (3, 13),
  (3, 14),
  (3, 15),
  (3, 16),
  (3, 17),
  (3, 18),
  (3, 19),
  (3, 20),
  (3, 27),
  (4, 1),
  (4, 2),
  (4, 7),
  (4, 12),
  (4, 13),
  (4, 14),
  (4, 17),
  (4, 18),
  (4, 19),
  (5, 1),
  (5, 2),
  (5, 3),
  (5, 4),
  (5, 7),
  (5, 8),
  (5, 10),
  (5, 11),
  (6, 1),
  (6, 22),
  (6, 23),
  (6, 25),
  (6, 26),
  (6, 27),
  (6, 28),
  (7, 1),
  (7, 12),
  (7, 7),
  (7, 19),
  (7, 20),
  (8, 1),
  (8, 2),
  (8, 7),
  (8, 10),
  (8, 12),
  (8, 17),
  (8, 19);

SET FOREIGN_KEY_CHECKS = 1;
