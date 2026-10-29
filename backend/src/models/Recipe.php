<?php

namespace Zediz\Macrofit\models;

use Database;
use Exception;
use PDO;
use Throwable;
use Zediz\Macrofit\shared\Nutrition;

class Recipe {
	private PDO $db;

	public function __construct() {
		$this->db = Database::getInstance()->getConnection();
	}

	public function listByUser(int $userId): array {
		$stmt = $this->db->prepare(
			'SELECT * FROM recipes WHERE user_id = :user_id ORDER BY updated_at DESC, id DESC'
		);
		$stmt->execute(array('user_id' => $userId));
		$recipes = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$recipes[] = $this->present($row, $this->ingredientsOf((int) $row['id']));
		}
		return $recipes;
	}

	public function findOwned(int $userId, int $id): ?array {
		$stmt = $this->db->prepare('SELECT * FROM recipes WHERE id = :id AND user_id = :user_id LIMIT 1');
		$stmt->execute(array('id' => $id, 'user_id' => $userId));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			return null;
		}
		return $this->present($row, $this->ingredientsOf($id));
	}

	public function create(int $userId, array $input): array {
		$payload = $this->normalize($input);
		$resolved = $this->resolveIngredients($payload['ingredients']);
		$totals = Nutrition::recipeTotals($resolved);
		$this->db->beginTransaction();
		try {
			$stmt = $this->db->prepare(
				'INSERT INTO recipes
					(user_id, name, description, servings, total_kcal, total_protein, total_carbs, total_fat)
				 VALUES
					(:user_id, :name, :description, :servings, :total_kcal, :total_protein, :total_carbs, :total_fat)'
			);
			$stmt->execute(array(
				'user_id' => $userId,
				'name' => $payload['name'],
				'description' => $payload['description'],
				'servings' => $payload['servings'],
				'total_kcal' => $totals['kcal'],
				'total_protein' => $totals['protein'],
				'total_carbs' => $totals['carbs'],
				'total_fat' => $totals['fat'],
			));
			$id = (int) $this->db->lastInsertId();
			$this->insertIngredients($id, $resolved);
			$this->db->commit();
		} catch (Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->findOwned($userId, $id);
	}

	public function update(int $userId, int $id, array $input): array {
		if (!$this->findOwned($userId, $id)) {
			throw new Exception('Receta no encontrada', 404);
		}
		$payload = $this->normalize($input);
		$resolved = $this->resolveIngredients($payload['ingredients']);
		$totals = Nutrition::recipeTotals($resolved);
		$this->db->beginTransaction();
		try {
			$stmt = $this->db->prepare(
				'UPDATE recipes SET
					name = :name,
					description = :description,
					servings = :servings,
					total_kcal = :total_kcal,
					total_protein = :total_protein,
					total_carbs = :total_carbs,
					total_fat = :total_fat
				 WHERE id = :id AND user_id = :user_id'
			);
			$stmt->execute(array(
				'name' => $payload['name'],
				'description' => $payload['description'],
				'servings' => $payload['servings'],
				'total_kcal' => $totals['kcal'],
				'total_protein' => $totals['protein'],
				'total_carbs' => $totals['carbs'],
				'total_fat' => $totals['fat'],
				'id' => $id,
				'user_id' => $userId,
			));
			$delete = $this->db->prepare('DELETE FROM recipe_ingredients WHERE recipe_id = :recipe_id');
			$delete->execute(array('recipe_id' => $id));
			$this->insertIngredients($id, $resolved);
			$this->db->commit();
		} catch (Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $this->findOwned($userId, $id);
	}

	public function delete(int $userId, int $id): void {
		if (!$this->findOwned($userId, $id)) {
			throw new Exception('Receta no encontrada', 404);
		}
		try {
			$stmt = $this->db->prepare('DELETE FROM recipes WHERE id = :id AND user_id = :user_id');
			$stmt->execute(array('id' => $id, 'user_id' => $userId));
		} catch (\PDOException $e) {
			if ((string) $e->getCode() === '23000') {
				throw new Exception('No se puede eliminar: la receta ya está en un diario', 409);
			}
			throw $e;
		}
	}

	private function normalize(array $input): array {
		$name = trim((string) ($input['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 255) {
			throw new Exception('El nombre de la receta es obligatorio', 422);
		}
		$description = trim((string) ($input['description'] ?? ''));
		$description = $description === '' ? null : $description;
		$servings = filter_var($input['servings'] ?? null, FILTER_VALIDATE_FLOAT);
		if ($servings === false || $servings <= 0 || $servings > 100) {
			throw new Exception('Las porciones deben ser mayores a 0', 422);
		}
		$ingredients = $input['ingredients'] ?? null;
		if (!is_array($ingredients) || count($ingredients) < 1) {
			throw new Exception('Agrega al menos un ingrediente', 422);
		}
		if (count($ingredients) > 40) {
			throw new Exception('Una receta admite hasta 40 ingredientes', 422);
		}
		return array(
			'name' => $name,
			'description' => $description,
			'servings' => round((float) $servings, 2),
			'ingredients' => $ingredients,
		);
	}

	private function resolveIngredients(array $ingredients): array {
		$resolved = array();
		foreach ($ingredients as $ingredient) {
			if (!is_array($ingredient)) {
				throw new Exception('Ingrediente no válido', 422);
			}
			$foodId = filter_var($ingredient['food_id'] ?? null, FILTER_VALIDATE_INT);
			$grams = filter_var($ingredient['grams'] ?? null, FILTER_VALIDATE_FLOAT);
			if ($foodId === false || $grams === false || $grams <= 0 || $grams > 20000) {
				throw new Exception('Cada ingrediente necesita un alimento y gramos mayores a 0', 422);
			}
			$food = (new Food())->findById((int) $foodId);
			if (!$food) {
				throw new Exception('Un ingrediente no existe en el catálogo', 404);
			}
			$resolved[] = array(
				'food_id' => (int) $foodId,
				'grams' => round((float) $grams, 2),
				'food' => $food,
				'per100' => array(
					'kcal' => $food['kcal_per_100g'],
					'protein' => $food['protein_per_100g'],
					'carbs' => $food['carbs_per_100g'],
					'fat' => $food['fat_per_100g'],
				),
			);
		}
		return $resolved;
	}

	private function insertIngredients(int $recipeId, array $resolved): void {
		$stmt = $this->db->prepare(
			'INSERT INTO recipe_ingredients (recipe_id, food_id, grams) VALUES (:recipe_id, :food_id, :grams)'
		);
		foreach ($resolved as $ingredient) {
			$stmt->execute(array(
				'recipe_id' => $recipeId,
				'food_id' => $ingredient['food_id'],
				'grams' => $ingredient['grams'],
			));
		}
	}

	private function ingredientsOf(int $recipeId): array {
		$stmt = $this->db->prepare(
			'SELECT ri.id, ri.food_id, ri.grams, f.name AS food_name, f.brand,
				f.kcal_per_100g, f.protein_per_100g, f.carbs_per_100g, f.fat_per_100g
			 FROM recipe_ingredients ri
			 JOIN foods f ON f.id = ri.food_id
			 WHERE ri.recipe_id = :recipe_id
			 ORDER BY ri.id ASC'
		);
		$stmt->execute(array('recipe_id' => $recipeId));
		$items = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$per100 = array(
				'kcal' => (float) $row['kcal_per_100g'],
				'protein' => (float) $row['protein_per_100g'],
				'carbs' => (float) $row['carbs_per_100g'],
				'fat' => (float) $row['fat_per_100g'],
			);
			$scaled = Nutrition::scalePer100g($per100, (float) $row['grams']);
			$items[] = array(
				'id' => (int) $row['id'],
				'food_id' => (int) $row['food_id'],
				'grams' => (float) $row['grams'],
				'food_name' => $row['food_name'],
				'brand' => $row['brand'],
				'kcal_per_100g' => $per100['kcal'],
				'protein_per_100g' => $per100['protein'],
				'carbs_per_100g' => $per100['carbs'],
				'fat_per_100g' => $per100['fat'],
				'kcal' => $scaled['kcal'],
				'protein' => $scaled['protein'],
				'carbs' => $scaled['carbs'],
				'fat' => $scaled['fat'],
			);
		}
		return $items;
	}

	private function present(array $row, array $ingredients): array {
		$totals = array(
			'kcal' => (float) $row['total_kcal'],
			'protein' => (float) $row['total_protein'],
			'carbs' => (float) $row['total_carbs'],
			'fat' => (float) $row['total_fat'],
		);
		$grams = 0.0;
		foreach ($ingredients as $ingredient) {
			$grams += (float) $ingredient['grams'];
		}
		return array(
			'id' => (int) $row['id'],
			'user_id' => (int) $row['user_id'],
			'name' => $row['name'],
			'description' => $row['description'],
			'servings' => (float) $row['servings'],
			'total_kcal' => $totals['kcal'],
			'total_protein' => $totals['protein'],
			'total_carbs' => $totals['carbs'],
			'total_fat' => $totals['fat'],
			'total_grams' => round($grams, 2),
			'per_portion' => Nutrition::perPortion($totals, (float) $row['servings']),
			'ingredients' => $ingredients,
		);
	}
}
