<?php

namespace Zediz\Macrofit\models;

use Database;
use Exception;
use PDO;
use Zediz\Macrofit\shared\Nutrition;
use Zediz\Macrofit\shared\Request;

class DailyLog {
	private PDO $db;

	public function __construct() {
		$this->db = Database::getInstance()->getConnection();
	}

	public function listByDate(int $userId, string $date): array {
		$stmt = $this->db->prepare($this->selectSql() . ' WHERE l.user_id = :user_id AND l.log_date = :log_date
			ORDER BY FIELD(l.meal_type, \'breakfast\', \'lunch\', \'dinner\', \'snack\'), l.id ASC');
		$stmt->execute(array('user_id' => $userId, 'log_date' => $date));
		return array_map(array($this, 'present'), $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	public function findOwned(int $userId, int $id): ?array {
		$stmt = $this->db->prepare($this->selectSql() . ' WHERE l.user_id = :user_id AND l.id = :id LIMIT 1');
		$stmt->execute(array('user_id' => $userId, 'id' => $id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? $this->present($row) : null;
	}

	public function create(int $userId, array $input): array {
		$date = Request::dateOrToday(isset($input['log_date']) ? (string) $input['log_date'] : null);
		$meal = $input['meal_type'] ?? '';
		if (!in_array($meal, array('breakfast', 'lunch', 'dinner', 'snack'), true)) {
			throw new Exception('Tipo de comida no válido', 422);
		}
		$unit = $input['unit'] ?? '';
		if (!in_array($unit, array('grams', 'portions'), true)) {
			throw new Exception('La unidad debe ser grams o portions', 422);
		}
		$quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
		if ($quantity === false || $quantity <= 0 || $quantity > 10000) {
			throw new Exception('La cantidad debe ser mayor a 0', 422);
		}
		$foodId = $input['food_id'] ?? null;
		$recipeId = $input['recipe_id'] ?? null;
		$hasFood = $foodId !== null && $foodId !== '';
		$hasRecipe = $recipeId !== null && $recipeId !== '';
		if ($hasFood === $hasRecipe) {
			throw new Exception('Indica un alimento o una receta, no ambos', 422);
		}
		if ($hasFood) {
			$foodId = filter_var($foodId, FILTER_VALIDATE_INT);
			$food = $foodId === false ? null : (new Food())->findById((int) $foodId);
			if (!$food) {
				throw new Exception('Alimento no encontrado', 404);
			}
			$recipeId = null;
			$snapshot = $this->foodSnapshot($food, $unit, (float) $quantity);
		} else {
			$recipeId = filter_var($recipeId, FILTER_VALIDATE_INT);
			$recipe = $recipeId === false ? null : (new Recipe())->findOwned($userId, (int) $recipeId);
			if (!$recipe) {
				throw new Exception('Receta no encontrada', 404);
			}
			$foodId = null;
			$snapshot = $this->recipeSnapshot($recipe, $unit, (float) $quantity);
		}
		$stmt = $this->db->prepare(
			'INSERT INTO daily_logs
				(user_id, food_id, recipe_id, item_name, quantity, grams, unit, meal_type, log_date, kcal, protein, carbs, fat)
			 VALUES
				(:user_id, :food_id, :recipe_id, :item_name, :quantity, :grams, :unit, :meal_type, :log_date, :kcal, :protein, :carbs, :fat)'
		);
		$stmt->execute(array(
			'user_id' => $userId,
			'food_id' => $foodId,
			'recipe_id' => $recipeId,
			'item_name' => $snapshot['name'],
			'quantity' => $snapshot['quantity'],
			'grams' => $snapshot['grams'],
			'unit' => $snapshot['unit'],
			'meal_type' => $meal,
			'log_date' => $date,
			'kcal' => $snapshot['kcal'],
			'protein' => $snapshot['protein'],
			'carbs' => $snapshot['carbs'],
			'fat' => $snapshot['fat'],
		));
		$created = $this->findOwned($userId, (int) $this->db->lastInsertId());
		if (!$created) {
			throw new Exception('No se pudo leer el registro', 500);
		}
		return $created;
	}

	public function delete(int $userId, int $id): void {
		$stmt = $this->db->prepare('DELETE FROM daily_logs WHERE id = :id AND user_id = :user_id');
		$stmt->execute(array('id' => $id, 'user_id' => $userId));
		if ($stmt->rowCount() === 0) {
			throw new Exception('Registro no encontrado', 404);
		}
	}

	public function summary(int $userId, string $date, array $user): array {
		$logs = $this->listByDate($userId, $date);
		$kcal = 0.0;
		$protein = 0.0;
		$carbs = 0.0;
		$fat = 0.0;
		foreach ($logs as $log) {
			$kcal += $log['kcal'];
			$protein += $log['protein'];
			$carbs += $log['carbs'];
			$fat += $log['fat'];
		}
		$consumed = Nutrition::pack($kcal, $protein, $carbs, $fat);
		$goals = array(
			'kcal' => (float) ($user['goal_kcal'] ?? 0),
			'protein' => (float) ($user['goal_protein'] ?? 0),
			'carbs' => (float) ($user['goal_carbs'] ?? 0),
			'fat' => (float) ($user['goal_fat'] ?? 0),
		);
		return array(
			'date' => $date,
			'consumed' => $consumed,
			'goals' => $goals,
			'remaining' => Nutrition::subtract($goals, $consumed),
			'onboarding_complete' => !empty($user['onboarding_complete']),
		);
	}

	private function foodSnapshot(array $food, string $unit, float $quantity): array {
		$per100 = array(
			'kcal' => $food['kcal_per_100g'],
			'protein' => $food['protein_per_100g'],
			'carbs' => $food['carbs_per_100g'],
			'fat' => $food['fat_per_100g'],
		);
		$grams = Nutrition::foodGrams($unit, $quantity, $food['serving_grams']);
		$macros = Nutrition::scalePer100g($per100, $grams);
		return array(
			'name' => $this->snapshotName($food['name']),
			'quantity' => $grams,
			'grams' => $grams,
			'unit' => 'grams',
			'kcal' => $macros['kcal'],
			'protein' => $macros['protein'],
			'carbs' => $macros['carbs'],
			'fat' => $macros['fat'],
		);
	}

	private function recipeSnapshot(array $recipe, string $unit, float $quantity): array {
		$totals = array(
			'kcal' => $recipe['total_kcal'],
			'protein' => $recipe['total_protein'],
			'carbs' => $recipe['total_carbs'],
			'fat' => $recipe['total_fat'],
		);
		$macros = Nutrition::logMacros(
			'recipe',
			$unit,
			$quantity,
			null,
			$totals,
			(float) $recipe['servings'],
			(float) $recipe['total_grams']
		);
		$grams = Nutrition::recipeSnapshotGrams(
			$unit,
			$quantity,
			(float) $recipe['servings'],
			(float) $recipe['total_grams']
		);
		return array(
			'name' => $this->snapshotName($recipe['name']),
			'quantity' => round($quantity, 2),
			'grams' => $grams,
			'unit' => $unit,
			'kcal' => $macros['kcal'],
			'protein' => $macros['protein'],
			'carbs' => $macros['carbs'],
			'fat' => $macros['fat'],
		);
	}

	private function snapshotName(string $name): string {
		$name = trim($name);
		if ($name === '') {
			throw new Exception('El registro no tiene nombre', 422);
		}
		if (mb_strlen($name) > 255) {
			$name = mb_substr($name, 0, 255);
		}
		return $name;
	}

	private function selectSql(): string {
		return 'SELECT l.id, l.user_id, l.food_id, l.recipe_id, l.item_name, l.quantity, l.grams, l.unit,
			l.meal_type, l.log_date, l.kcal, l.protein, l.carbs, l.fat, f.brand AS food_brand
			FROM daily_logs l
			LEFT JOIN foods f ON f.id = l.food_id';
	}

	/**
	 * Nombre, gramos y macros salen de las columnas guardadas al crear el registro.
	 * No se vuelven a calcular con el alimento o la receta actuales.
	 */
	private function present(array $row): array {
		return array(
			'id' => (int) $row['id'],
			'user_id' => (int) $row['user_id'],
			'food_id' => $row['food_id'] !== null ? (int) $row['food_id'] : null,
			'recipe_id' => $row['recipe_id'] !== null ? (int) $row['recipe_id'] : null,
			'quantity' => (float) $row['quantity'],
			'grams' => (float) $row['grams'],
			'unit' => $row['unit'],
			'meal_type' => $row['meal_type'],
			'log_date' => $row['log_date'],
			'name' => $row['item_name'],
			'brand' => $row['food_brand'] ?? null,
			'kcal' => (float) $row['kcal'],
			'protein' => (float) $row['protein'],
			'carbs' => (float) $row['carbs'],
			'fat' => (float) $row['fat'],
		);
	}
}
