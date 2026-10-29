-- Macrofit — MySQL 8, utf8mb4
-- Si el panel de hosting ya creó la base, comenta CREATE DATABASE / USE
-- e importa solo las tablas.
-- Bases ya creadas con el esquema anterior: no reimportes este archivo.
-- Corre una vez database/migrations/20261029_log_snapshots.sql.

CREATE DATABASE IF NOT EXISTS macrofit
	CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

USE macrofit;

CREATE TABLE users (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	email VARCHAR(255) NOT NULL,
	password_hash VARCHAR(255) NOT NULL,
	name VARCHAR(120) NOT NULL,
	gender ENUM('male', 'female') NULL,
	age TINYINT UNSIGNED NULL,
	weight_kg DECIMAL(6,2) NULL,
	height_cm DECIMAL(6,2) NULL,
	activity_level ENUM('sedentary', 'light', 'moderate', 'active') NULL,
	bmr DECIMAL(8,2) NULL,
	tdee DECIMAL(8,2) NULL,
	goal_kcal DECIMAL(8,2) NULL,
	goal_protein DECIMAL(8,2) NULL,
	goal_carbs DECIMAL(8,2) NULL,
	goal_fat DECIMAL(8,2) NULL,
	protein_pct DECIMAL(5,2) NULL,
	carbs_pct DECIMAL(5,2) NULL,
	fat_pct DECIMAL(5,2) NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE foods (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	name VARCHAR(255) NOT NULL,
	brand VARCHAR(255) NULL,
	barcode VARCHAR(32) NULL,
	kcal_per_100g DECIMAL(8,2) NOT NULL,
	protein_per_100g DECIMAL(8,2) NOT NULL,
	carbs_per_100g DECIMAL(8,2) NOT NULL,
	fat_per_100g DECIMAL(8,2) NOT NULL,
	serving_grams DECIMAL(8,2) NULL,
	source ENUM('local', 'openfoodfacts', 'ocr') NOT NULL DEFAULT 'local',
	created_by INT UNSIGNED NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY uq_foods_barcode (barcode),
	KEY idx_foods_name (name),
	KEY idx_foods_created_by (created_by),
	CONSTRAINT fk_foods_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recipes (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id INT UNSIGNED NOT NULL,
	name VARCHAR(255) NOT NULL,
	description TEXT NULL,
	servings DECIMAL(6,2) NOT NULL,
	total_kcal DECIMAL(10,2) NOT NULL DEFAULT 0,
	total_protein DECIMAL(10,2) NOT NULL DEFAULT 0,
	total_carbs DECIMAL(10,2) NOT NULL DEFAULT 0,
	total_fat DECIMAL(10,2) NOT NULL DEFAULT 0,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	KEY idx_recipes_user (user_id),
	CONSTRAINT fk_recipes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recipe_ingredients (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	recipe_id INT UNSIGNED NOT NULL,
	food_id INT UNSIGNED NOT NULL,
	grams DECIMAL(8,2) NOT NULL,
	PRIMARY KEY (id),
	KEY idx_recipe_ingredients_recipe (recipe_id),
	KEY idx_recipe_ingredients_food (food_id),
	CONSTRAINT fk_recipe_ingredients_recipe FOREIGN KEY (recipe_id) REFERENCES recipes (id) ON DELETE CASCADE,
	CONSTRAINT fk_recipe_ingredients_food FOREIGN KEY (food_id) REFERENCES foods (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- item_name, grams y los macros son la foto del momento del registro.
-- food_id y recipe_id pueden quedar NULL si después se borra el alimento o la receta.
-- No hay CHECK de “solo uno”: MySQL no lo permite junto con ON DELETE SET NULL.
-- DailyLog::create exige exactamente un alimento o una receta.
CREATE TABLE daily_logs (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id INT UNSIGNED NOT NULL,
	food_id INT UNSIGNED NULL,
	recipe_id INT UNSIGNED NULL,
	item_name VARCHAR(255) NOT NULL,
	quantity DECIMAL(8,2) NOT NULL,
	grams DECIMAL(10,2) NOT NULL,
	unit ENUM('grams', 'portions') NOT NULL,
	meal_type ENUM('breakfast', 'lunch', 'dinner', 'snack') NOT NULL,
	log_date DATE NOT NULL,
	kcal DECIMAL(10,2) NOT NULL,
	protein DECIMAL(10,2) NOT NULL,
	carbs DECIMAL(10,2) NOT NULL,
	fat DECIMAL(10,2) NOT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	KEY idx_daily_logs_user_date (user_id, log_date),
	KEY idx_daily_logs_food (food_id),
	KEY idx_daily_logs_recipe (recipe_id),
	CONSTRAINT fk_daily_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
	CONSTRAINT fk_daily_logs_food FOREIGN KEY (food_id) REFERENCES foods (id) ON DELETE SET NULL,
	CONSTRAINT fk_daily_logs_recipe FOREIGN KEY (recipe_id) REFERENCES recipes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo inicial ilustrativo (valores por 100 g, aproximados).
INSERT INTO foods (name, brand, barcode, kcal_per_100g, protein_per_100g, carbs_per_100g, fat_per_100g, source, created_by) VALUES
	('Pechuga de pollo', NULL, NULL, 110.00, 23.10, 0.00, 1.20, 'local', NULL),
	('Huevo', NULL, NULL, 143.00, 12.60, 0.70, 9.50, 'local', NULL),
	('Arroz blanco cocido', NULL, NULL, 130.00, 2.70, 28.20, 0.30, 'local', NULL),
	('Frijoles negros cocidos', NULL, NULL, 132.00, 8.90, 23.70, 0.50, 'local', NULL),
	('Tortilla de maíz', NULL, NULL, 218.00, 5.70, 44.60, 2.90, 'local', NULL),
	('Aguacate', NULL, NULL, 160.00, 2.00, 8.50, 14.70, 'local', NULL),
	('Plátano', NULL, NULL, 89.00, 1.10, 22.80, 0.30, 'local', NULL),
	('Avena en hojuelas', NULL, NULL, 389.00, 16.90, 66.30, 6.90, 'local', NULL),
	('Leche entera', NULL, NULL, 61.00, 3.20, 4.80, 3.30, 'local', NULL),
	('Aceite de oliva', NULL, NULL, 884.00, 0.00, 0.00, 100.00, 'local', NULL);
