<?php

namespace Zediz\Macrofit\shared;

use Exception;

class Nutrition {
	public const ACTIVITY = array(
		'sedentary' => 1.2,
		'light' => 1.375,
		'moderate' => 1.55,
		'active' => 1.725,
	);

	public static function requirements(
		string $gender,
		float $weightKg,
		float $heightCm,
		int $age,
		string $activity,
		?float $proteinPct,
		?float $carbsPct,
		?float $fatPct
	): array {
		$bmr = self::bmrRaw($gender, $weightKg, $heightCm, $age);
		$tdee = $bmr * self::multiplier($activity);
		$custom = $proteinPct !== null || $carbsPct !== null || $fatPct !== null;
		if ($custom) {
			if ($proteinPct === null || $carbsPct === null || $fatPct === null) {
				throw new Exception('Indica los tres porcentajes de macros', 422);
			}
			$goals = self::goalsFromPercentages($tdee, $proteinPct, $carbsPct, $fatPct);
		} else {
			$goals = self::defaultGoals($tdee, $weightKg);
		}
		$goals['bmr'] = self::round2($bmr);
		$goals['tdee'] = self::round2($tdee);
		$goals['goal_kcal'] = self::round2($tdee);
		return $goals;
	}

	public static function bmrRaw(string $gender, float $weightKg, float $heightCm, int $age): float {
		$base = (10 * $weightKg) + (6.25 * $heightCm) - (5 * $age);
		if ($gender === 'male') {
			return $base + 5;
		}
		if ($gender === 'female') {
			return $base - 161;
		}
		throw new Exception('Género no válido', 422);
	}

	public static function multiplier(string $level): float {
		if (!isset(self::ACTIVITY[$level])) {
			throw new Exception('Nivel de actividad no válido', 422);
		}
		return self::ACTIVITY[$level];
	}

	public static function defaultGoals(float $tdee, float $weightKg): array {
		$protein = 2 * $weightKg;
		$proteinKcal = $protein * 4;
		$fatKcal = $tdee * 0.25;
		$carbKcal = max(0, $tdee - $proteinKcal - $fatKcal);
		return array(
			'goal_protein' => self::round2($protein),
			'goal_fat' => self::round2($fatKcal / 9),
			'goal_carbs' => self::round2($carbKcal / 4),
			'protein_pct' => null,
			'carbs_pct' => null,
			'fat_pct' => null,
		);
	}

	public static function goalsFromPercentages(float $tdee, float $proteinPct, float $carbsPct, float $fatPct): array {
		foreach (array('proteína' => $proteinPct, 'carbohidratos' => $carbsPct, 'grasas' => $fatPct) as $label => $pct) {
			if ($pct < 0 || $pct > 100) {
				throw new Exception("Porcentaje de $label fuera de rango", 422);
			}
		}
		$sum = $proteinPct + $carbsPct + $fatPct;
		if (abs($sum - 100) > 0.05) {
			throw new Exception('Los porcentajes de macros deben sumar 100', 422);
		}
		return array(
			'goal_protein' => self::round2(($tdee * ($proteinPct / 100)) / 4),
			'goal_carbs' => self::round2(($tdee * ($carbsPct / 100)) / 4),
			'goal_fat' => self::round2(($tdee * ($fatPct / 100)) / 9),
			'protein_pct' => self::round2($proteinPct),
			'carbs_pct' => self::round2($carbsPct),
			'fat_pct' => self::round2($fatPct),
		);
	}

	public static function scalePer100g(array $per100, float $grams): array {
		$factor = $grams / 100;
		return self::pack(
			$per100['kcal'] * $factor,
			$per100['protein'] * $factor,
			$per100['carbs'] * $factor,
			$per100['fat'] * $factor
		);
	}

	public static function recipeTotals(array $ingredients): array {
		$kcal = 0.0;
		$protein = 0.0;
		$carbs = 0.0;
		$fat = 0.0;
		foreach ($ingredients as $ingredient) {
			$grams = (float) $ingredient['grams'];
			$per100 = $ingredient['per100'];
			$factor = $grams / 100;
			$kcal += $per100['kcal'] * $factor;
			$protein += $per100['protein'] * $factor;
			$carbs += $per100['carbs'] * $factor;
			$fat += $per100['fat'] * $factor;
		}
		return self::pack($kcal, $protein, $carbs, $fat);
	}

	public static function perPortion(array $totals, float $servings): array {
		if ($servings <= 0) {
			throw new Exception('El número de porciones debe ser mayor a 0', 422);
		}
		return self::pack(
			$totals['kcal'] / $servings,
			$totals['protein'] / $servings,
			$totals['carbs'] / $servings,
			$totals['fat'] / $servings
		);
	}

	/**
	 * Alimento en gramos: (macro por 100 g / 100) * gramos.
	 * Alimento en porciones: 1 porción = 100 g (el catálogo no guarda tamaño de porción).
	 * Receta en porciones: (totales / porciones de la receta) * cantidad.
	 * Receta en gramos: totales * (gramos consumidos / gramos de la receta).
	 */
	public static function logMacros(
		string $kind,
		string $unit,
		float $quantity,
		?array $per100 = null,
		?array $recipeTotals = null,
		float $recipeServings = 0,
		float $recipeGrams = 0
	): array {
		if ($quantity <= 0) {
			throw new Exception('La cantidad debe ser mayor a 0', 422);
		}
		if (!in_array($unit, array('grams', 'portions'), true)) {
			throw new Exception('Unidad no válida', 422);
		}
		if ($kind === 'food') {
			if ($per100 === null) {
				throw new Exception('Faltan los macros por 100 g', 422);
			}
			$grams = $unit === 'portions' ? $quantity * 100 : $quantity;
			return self::scalePer100g($per100, $grams);
		}
		if ($kind === 'recipe') {
			if ($recipeTotals === null || $recipeServings <= 0) {
				throw new Exception('Faltan los totales de la receta', 422);
			}
			if ($unit === 'portions') {
				return self::pack(
					($recipeTotals['kcal'] / $recipeServings) * $quantity,
					($recipeTotals['protein'] / $recipeServings) * $quantity,
					($recipeTotals['carbs'] / $recipeServings) * $quantity,
					($recipeTotals['fat'] / $recipeServings) * $quantity
				);
			}
			if ($recipeGrams <= 0) {
				throw new Exception('La receta no tiene gramos para escalar', 422);
			}
			$factor = $quantity / $recipeGrams;
			return self::pack(
				$recipeTotals['kcal'] * $factor,
				$recipeTotals['protein'] * $factor,
				$recipeTotals['carbs'] * $factor,
				$recipeTotals['fat'] * $factor
			);
		}
		throw new Exception('Tipo de registro no válido', 422);
	}

	public static function add(array $a, array $b): array {
		return self::pack(
			$a['kcal'] + $b['kcal'],
			$a['protein'] + $b['protein'],
			$a['carbs'] + $b['carbs'],
			$a['fat'] + $b['fat']
		);
	}

	public static function subtract(array $goals, array $consumed): array {
		return self::pack(
			$goals['kcal'] - $consumed['kcal'],
			$goals['protein'] - $consumed['protein'],
			$goals['carbs'] - $consumed['carbs'],
			$goals['fat'] - $consumed['fat']
		);
	}

	public static function pack(float $kcal, float $protein, float $carbs, float $fat): array {
		return array(
			'kcal' => self::round2($kcal),
			'protein' => self::round2($protein),
			'carbs' => self::round2($carbs),
			'fat' => self::round2($fat),
		);
	}

	public static function round2(float $value): float {
		return round($value, 2);
	}
}
