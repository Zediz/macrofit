-- Migración única para bases creadas antes de la foto del diario y de serving_grams.
-- No la corras sobre un schema.sql nuevo: esas columnas ya existen.
--
-- Qué hace:
--   * foods.serving_grams (NULL si el alimento no tiene porción propia).
--   * daily_logs guarda item_name, grams, kcal, protein, carbs y fat.
--   * Rellena esas columnas con los valores actuales (la misma cuenta que hacía el API).
--     Los alimentos viejos en unit=portions usan la regla de entonces: 1 porción = 100 g.
--   * Borrar un alimento o una receta deja el id en NULL y no borra el registro.
--
-- Hostinger: si no puedes ejecutar USE, bórralo e importa con la base ya seleccionada.

USE macrofit;

ALTER TABLE foods
	ADD COLUMN serving_grams DECIMAL(8,2) NULL AFTER fat_per_100g;

ALTER TABLE daily_logs
	ADD COLUMN item_name VARCHAR(255) NULL AFTER recipe_id,
	ADD COLUMN grams DECIMAL(10,2) NULL AFTER quantity,
	ADD COLUMN kcal DECIMAL(10,2) NULL AFTER log_date,
	ADD COLUMN protein DECIMAL(10,2) NULL AFTER kcal,
	ADD COLUMN carbs DECIMAL(10,2) NULL AFTER protein,
	ADD COLUMN fat DECIMAL(10,2) NULL AFTER carbs;

-- Alimentos. La porción histórica era 100 g; los registros nuevos ya no usan esa regla.
UPDATE daily_logs AS l
INNER JOIN foods AS f ON f.id = l.food_id
SET
	l.item_name = f.name,
	l.grams = ROUND(CASE WHEN l.unit = 'portions' THEN l.quantity * 100 ELSE l.quantity END, 2),
	l.kcal = ROUND(f.kcal_per_100g * (CASE WHEN l.unit = 'portions' THEN l.quantity * 100 ELSE l.quantity END) / 100, 2),
	l.protein = ROUND(f.protein_per_100g * (CASE WHEN l.unit = 'portions' THEN l.quantity * 100 ELSE l.quantity END) / 100, 2),
	l.carbs = ROUND(f.carbs_per_100g * (CASE WHEN l.unit = 'portions' THEN l.quantity * 100 ELSE l.quantity END) / 100, 2),
	l.fat = ROUND(f.fat_per_100g * (CASE WHEN l.unit = 'portions' THEN l.quantity * 100 ELSE l.quantity END) / 100, 2)
WHERE l.food_id IS NOT NULL;

-- Recetas: porciones = total / servings * cantidad. Gramos = cantidad * (gramos de la receta / servings).
UPDATE daily_logs AS l
INNER JOIN recipes AS r ON r.id = l.recipe_id
LEFT JOIN (
	SELECT recipe_id, SUM(grams) AS total_grams
	FROM recipe_ingredients
	GROUP BY recipe_id
) AS g ON g.recipe_id = r.id
SET
	l.item_name = r.name,
	l.grams = ROUND(
		CASE
			WHEN l.unit = 'grams' THEN l.quantity
			ELSE l.quantity * (COALESCE(g.total_grams, 0) / NULLIF(r.servings, 0))
		END
	, 2),
	l.kcal = ROUND(
		CASE
			WHEN l.unit = 'portions' THEN (r.total_kcal / NULLIF(r.servings, 0)) * l.quantity
			ELSE r.total_kcal * (l.quantity / NULLIF(g.total_grams, 0))
		END
	, 2),
	l.protein = ROUND(
		CASE
			WHEN l.unit = 'portions' THEN (r.total_protein / NULLIF(r.servings, 0)) * l.quantity
			ELSE r.total_protein * (l.quantity / NULLIF(g.total_grams, 0))
		END
	, 2),
	l.carbs = ROUND(
		CASE
			WHEN l.unit = 'portions' THEN (r.total_carbs / NULLIF(r.servings, 0)) * l.quantity
			ELSE r.total_carbs * (l.quantity / NULLIF(g.total_grams, 0))
		END
	, 2),
	l.fat = ROUND(
		CASE
			WHEN l.unit = 'portions' THEN (r.total_fat / NULLIF(r.servings, 0)) * l.quantity
			ELSE r.total_fat * (l.quantity / NULLIF(g.total_grams, 0))
		END
	, 2)
WHERE l.recipe_id IS NOT NULL;

ALTER TABLE daily_logs
	MODIFY item_name VARCHAR(255) NOT NULL,
	MODIFY grams DECIMAL(10,2) NOT NULL,
	MODIFY kcal DECIMAL(10,2) NOT NULL,
	MODIFY protein DECIMAL(10,2) NOT NULL,
	MODIFY carbs DECIMAL(10,2) NOT NULL,
	MODIFY fat DECIMAL(10,2) NOT NULL;

-- MySQL no permite CHECK sobre una columna con ON DELETE SET NULL.
-- El API sigue exigiendo un alimento o una receta al crear el registro.
ALTER TABLE daily_logs DROP CHECK chk_daily_logs_target;
ALTER TABLE daily_logs DROP FOREIGN KEY fk_daily_logs_food;
ALTER TABLE daily_logs DROP FOREIGN KEY fk_daily_logs_recipe;

ALTER TABLE daily_logs
	ADD CONSTRAINT fk_daily_logs_food FOREIGN KEY (food_id) REFERENCES foods (id) ON DELETE SET NULL,
	ADD CONSTRAINT fk_daily_logs_recipe FOREIGN KEY (recipe_id) REFERENCES recipes (id) ON DELETE SET NULL;
