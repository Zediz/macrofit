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
			if ($foodId === false || !(new Food())->findById((int) $foodId)) {
				throw new Exception('Alimento no encontrado', 404);
			}
			$recipeId = null;
		} else {
			$recipeId = filter_var($recipeId, FILTER_VALIDATE_INT);
			if ($recipeId === false || !(new Recipe())->findOwned($userId, (int) $recipeId)) {
				throw new Exception('Receta no encontrada', 404);
			}
			$foodId = null;
		}
		$stmt = $this->db->prepare(
			'INSERT INTO daily_logs (user_id, food_id, recipe_id, quantity, unit, meal_type, log_date)
			 VALUES (:user_id, :food_id, :recipe_id, :quantity, :unit, :meal_type, :log_date)'
		);
		$stmt->execute(array(
			'user_id' => $userId,
			'food_id' => $foodId,
			'recipe_id' => $recipeId,
			'quantity' => round((float) $quantity, 2),
			'unit' => $unit,
			'meal_type' => $meal,
			'log_date' => $date,
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

	private function selectSql(): string {
		return 'SELECT l.id, l.user_id, l.food_id, l.recipe_id, l.quantity, l.unit, l.meal_type, l.log_date,
			f.name AS food_name, f.brand AS food_brand,
			f.kcal_per_100g, f.protein_per_100g, f.carbs_per_100g, f.fat_per_100g,
			r.name AS recipe_name, r.servings AS recipe_servings,
			r.total_kcal, r.total_protein, r.total_carbs, r.total_fat,
			(SELECT COALESCE(SUM(ri.grams), 0) FROM recipe_ingredients ri WHERE ri.recipe_id = l.recipe_id) AS recipe_grams
			FROM daily_logs l
			LEFT JOIN foods f ON f.id = l.food_id
			LEFT JOIN recipes r ON r.id = l.recipe_id';
	}

	private function present(array $row): array {
		if ($row['food_id'] !== null) {
			$macros = Nutrition::logMacros('food', $row['unit'], (float) $row['quantity'], array(
				'kcal' => (float) $row['kcal_per_100g'],
				'protein' => (float) $row['protein_per_100g'],
				'carbs' => (float) $row['carbs_per_100g'],
				'fat' => (float) $row['fat_per_100g'],
			));
			$name = $row['food_name'];
			$brand = $row['food_brand'];
		} else {
			$macros = Nutrition::logMacros(
				'recipe',
				$row['unit'],
				(float) $row['quantity'],
				null,
				array(
					'kcal' => (float) $row['total_kcal'],
					'protein' => (float) $row['total_protein'],
					'carbs' => (float) $row['total_carbs'],
					'fat' => (float) $row['total_fat'],
				),
				(float) $row['recipe_servings'],
				(float) $row['recipe_grams']
			);
			$name = $row['recipe_name'];
			$brand = null;
		}
		return array(
			'id' => (int) $row['id'],
			'user_id' => (int) $row['user_id'],
			'food_id' => $row['food_id'] !== null ? (int) $row['food_id'] : null,
			'recipe_id' => $row['recipe_id'] !== null ? (int) $row['recipe_id'] : null,
			'quantity' => (float) $row['quantity'],
			'unit' => $row['unit'],
			'meal_type' => $row['meal_type'],
			'log_date' => $row['log_date'],
			'name' => $name,
			'brand' => $brand,
			'kcal' => $macros['kcal'],
			'protein' => $macros['protein'],
			'carbs' => $macros['carbs'],
			'fat' => $macros['fat'],
		);
	}
}
