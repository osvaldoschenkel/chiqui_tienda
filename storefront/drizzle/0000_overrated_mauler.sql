CREATE TABLE `categories` (
	`id` text PRIMARY KEY NOT NULL,
	`name` text NOT NULL,
	`parent_id` text,
	`slug` text NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `categories_slug_unique` ON `categories` (`slug`);--> statement-breakpoint
CREATE TABLE `orders` (
	`id` text PRIMARY KEY NOT NULL,
	`number` text NOT NULL,
	`user_id` text NOT NULL,
	`created_at` text NOT NULL,
	`status` text NOT NULL,
	`payment_status` text NOT NULL,
	`total` real NOT NULL,
	`shipping_cost` real NOT NULL,
	`shipping_method` text NOT NULL,
	`tracking_code` text,
	`tracking_url` text,
	`is_demo` integer DEFAULT 0 NOT NULL,
	`items` text NOT NULL,
	`address` text NOT NULL,
	`customer_name` text NOT NULL,
	`customer_email` text NOT NULL,
	`payment_url` text,
	`payment_id` text,
	`preference_id` text,
	`reservation_active` integer DEFAULT 0 NOT NULL,
	`reservation_expires` text,
	`shipping_selection` text,
	`shipment_id` text
);
--> statement-breakpoint
CREATE UNIQUE INDEX `orders_number_unique` ON `orders` (`number`);--> statement-breakpoint
CREATE INDEX `orders_user_idx` ON `orders` (`user_id`);--> statement-breakpoint
CREATE INDEX `orders_created_idx` ON `orders` (`created_at`);--> statement-breakpoint
CREATE TABLE `products` (
	`id` text PRIMARY KEY NOT NULL,
	`sku` text NOT NULL,
	`name` text NOT NULL,
	`brand` text DEFAULT '' NOT NULL,
	`description` text DEFAULT '' NOT NULL,
	`price` real NOT NULL,
	`stock` integer DEFAULT 0 NOT NULL,
	`category_id` text NOT NULL,
	`active` integer DEFAULT 1 NOT NULL,
	`featured` integer DEFAULT 0 NOT NULL,
	`weight_grams` integer NOT NULL,
	`height_cm` real NOT NULL,
	`width_cm` real NOT NULL,
	`length_cm` real NOT NULL,
	`media` text DEFAULT '[]' NOT NULL,
	CONSTRAINT "product_stock_nonnegative" CHECK("products"."stock" >= 0),
	CONSTRAINT "product_price_positive" CHECK("products"."price" > 0)
);
--> statement-breakpoint
CREATE UNIQUE INDEX `products_sku_unique` ON `products` (`sku`);--> statement-breakpoint
CREATE INDEX `products_category_idx` ON `products` (`category_id`);--> statement-breakpoint
CREATE TABLE `shipping_quotes` (
	`id` text PRIMARY KEY NOT NULL,
	`user_id` text NOT NULL,
	`cart_hash` text NOT NULL,
	`destination` text NOT NULL,
	`expires_at` text NOT NULL,
	`options` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `store_metadata` (
	`key` text PRIMARY KEY NOT NULL,
	`value` text NOT NULL
);
