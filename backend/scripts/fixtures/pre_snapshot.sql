-- Esquema anterior a la foto del diario. Lo usa scripts/check-migration.sh.

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

CREATE TABLE daily_logs (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id INT UNSIGNED NOT NULL,
	food_id INT UNSIGNED NULL,
	recipe_id INT UNSIGNED NULL,
	quantity DECIMAL(8,2) NOT NULL,
	unit ENUM('grams', 'portions') NOT NULL,
	meal_type ENUM('breakfast', 'lunch', 'dinner', 'snack') NOT NULL,
	log_date DATE NOT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	KEY idx_daily_logs_user_date (user_id, log_date),
	KEY idx_daily_logs_food (food_id),
	KEY idx_daily_logs_recipe (recipe_id),
	CONSTRAINT fk_daily_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
	CONSTRAINT fk_daily_logs_food FOREIGN KEY (food_id) REFERENCES foods (id) ON DELETE RESTRICT,
	CONSTRAINT fk_daily_logs_recipe FOREIGN KEY (recipe_id) REFERENCES recipes (id) ON DELETE RESTRICT,
	CONSTRAINT chk_daily_logs_target CHECK (
		(food_id IS NOT NULL AND recipe_id IS NULL)
		OR (food_id IS NULL AND recipe_id IS NOT NULL)
	)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (id, email, password_hash, name) VALUES
	(1, 'mig@macrofit.test', 'hash', 'Mig');

INSERT INTO foods (id, name, brand, barcode, kcal_per_100g, protein_per_100g, carbs_per_100g, fat_per_100g, source, created_by) VALUES
	(1, 'Pechuga de pollo', NULL, NULL, 110.00, 23.10, 0.00, 1.20, 'local', NULL),
	(2, 'Yogurt humo', 'Macrofit', NULL, 62.00, 3.50, 4.70, 3.30, 'local', 1);

INSERT INTO recipes (id, user_id, name, description, servings, total_kcal, total_protein, total_carbs, total_fat) VALUES
	(1, 1, 'Pollo receta', NULL, 2, 220.00, 46.20, 0.00, 2.40);

INSERT INTO recipe_ingredients (recipe_id, food_id, grams) VALUES
	(1, 1, 200);

INSERT INTO daily_logs (id, user_id, food_id, recipe_id, quantity, unit, meal_type, log_date) VALUES
	(1, 1, 1, NULL, 2, 'portions', 'lunch', '2026-10-01'),
	(2, 1, 2, NULL, 150, 'grams', 'breakfast', '2026-10-01'),
	(3, 1, NULL, 1, 1, 'portions', 'dinner', '2026-10-01');
