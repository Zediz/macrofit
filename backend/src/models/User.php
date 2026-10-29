<?php

namespace Zediz\Macrofit\models;

use Database;
use Exception;
use PDO;
use Zediz\Macrofit\shared\Nutrition;

class User {
	private PDO $db;

	public function __construct() {
		$this->db = Database::getInstance()->getConnection();
	}

	public function findRawByEmail(string $email): ?array {
		$stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
		$stmt->execute(array('email' => $email));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public function findRawById(int $id): ?array {
		$stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
		$stmt->execute(array('id' => $id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public function create(string $email, string $passwordHash, string $name): array {
		try {
			$stmt = $this->db->prepare(
				'INSERT INTO users (email, password_hash, name) VALUES (:email, :password_hash, :name)'
			);
			$stmt->execute(array(
				'email' => $email,
				'password_hash' => $passwordHash,
				'name' => $name,
			));
		} catch (\PDOException $e) {
			if ((string) $e->getCode() === '23000') {
				throw new Exception('Ese correo ya está registrado', 409);
			}
			throw $e;
		}
		return self::present($this->findRawById((int) $this->db->lastInsertId()));
	}

	public function saveOnboarding(int $id, array $input): array {
		$bio = self::biometrics($input);
		$goals = Nutrition::requirements(
			$bio['gender'],
			$bio['weight_kg'],
			$bio['height_cm'],
			$bio['age'],
			$bio['activity_level'],
			null,
			null,
			null
		);
		$stmt = $this->db->prepare(
			'UPDATE users SET
				gender = :gender,
				age = :age,
				weight_kg = :weight_kg,
				height_cm = :height_cm,
				activity_level = :activity_level,
				bmr = :bmr,
				tdee = :tdee,
				goal_kcal = :goal_kcal,
				goal_protein = :goal_protein,
				goal_carbs = :goal_carbs,
				goal_fat = :goal_fat,
				protein_pct = NULL,
				carbs_pct = NULL,
				fat_pct = NULL
			WHERE id = :id'
		);
		$stmt->execute(array(
			'gender' => $bio['gender'],
			'age' => $bio['age'],
			'weight_kg' => $bio['weight_kg'],
			'height_cm' => $bio['height_cm'],
			'activity_level' => $bio['activity_level'],
			'bmr' => $goals['bmr'],
			'tdee' => $goals['tdee'],
			'goal_kcal' => $goals['goal_kcal'],
			'goal_protein' => $goals['goal_protein'],
			'goal_carbs' => $goals['goal_carbs'],
			'goal_fat' => $goals['goal_fat'],
			'id' => $id,
		));
		return self::present($this->findRawById($id));
	}

	public function updateProfile(int $id, array $input): array {
		$current = $this->findRawById($id);
		if (!$current) {
			throw new Exception('Usuario no encontrado', 404);
		}
		$name = array_key_exists('name', $input) ? trim((string) $input['name']) : $current['name'];
		if ($name === '' || mb_strlen($name) > 120) {
			throw new Exception('El nombre es obligatorio (máximo 120 caracteres)', 422);
		}
		$merged = array(
			'gender' => array_key_exists('gender', $input) ? $input['gender'] : $current['gender'],
			'age' => array_key_exists('age', $input) ? $input['age'] : $current['age'],
			'weight_kg' => array_key_exists('weight_kg', $input) ? $input['weight_kg'] : $current['weight_kg'],
			'height_cm' => array_key_exists('height_cm', $input) ? $input['height_cm'] : $current['height_cm'],
			'activity_level' => array_key_exists('activity_level', $input) ? $input['activity_level'] : $current['activity_level'],
		);
		$touched = false;
		foreach (array('gender', 'age', 'weight_kg', 'height_cm', 'activity_level') as $key) {
			if (array_key_exists($key, $input)) {
				$touched = true;
			}
		}
		if (!$touched) {
			$stmt = $this->db->prepare('UPDATE users SET name = :name WHERE id = :id');
			$stmt->execute(array('name' => $name, 'id' => $id));
			return self::present($this->findRawById($id));
		}
		$bio = self::biometrics($merged);
		$pct = self::percentages($current);
		$goals = Nutrition::requirements(
			$bio['gender'],
			$bio['weight_kg'],
			$bio['height_cm'],
			$bio['age'],
			$bio['activity_level'],
			$pct['protein_pct'],
			$pct['carbs_pct'],
			$pct['fat_pct']
		);
		$stmt = $this->db->prepare(
			'UPDATE users SET
				name = :name,
				gender = :gender,
				age = :age,
				weight_kg = :weight_kg,
				height_cm = :height_cm,
				activity_level = :activity_level,
				bmr = :bmr,
				tdee = :tdee,
				goal_kcal = :goal_kcal,
				goal_protein = :goal_protein,
				goal_carbs = :goal_carbs,
				goal_fat = :goal_fat,
				protein_pct = :protein_pct,
				carbs_pct = :carbs_pct,
				fat_pct = :fat_pct
			WHERE id = :id'
		);
		$stmt->execute(array(
			'name' => $name,
			'gender' => $bio['gender'],
			'age' => $bio['age'],
			'weight_kg' => $bio['weight_kg'],
			'height_cm' => $bio['height_cm'],
			'activity_level' => $bio['activity_level'],
			'bmr' => $goals['bmr'],
			'tdee' => $goals['tdee'],
			'goal_kcal' => $goals['goal_kcal'],
			'goal_protein' => $goals['goal_protein'],
			'goal_carbs' => $goals['goal_carbs'],
			'goal_fat' => $goals['goal_fat'],
			'protein_pct' => $goals['protein_pct'],
			'carbs_pct' => $goals['carbs_pct'],
			'fat_pct' => $goals['fat_pct'],
			'id' => $id,
		));
		return self::present($this->findRawById($id));
	}

	public function updateMacros(int $id, array $input): array {
		$current = $this->findRawById($id);
		if (!$current || $current['tdee'] === null || $current['gender'] === null) {
			throw new Exception('Completa el onboarding antes de ajustar los macros', 422);
		}
		$bio = self::biometrics(array(
			'gender' => $current['gender'],
			'age' => $current['age'],
			'weight_kg' => $current['weight_kg'],
			'height_cm' => $current['height_cm'],
			'activity_level' => $current['activity_level'],
		));
		$reset = !empty($input['reset']);
		if ($reset) {
			$protein = null;
			$carbs = null;
			$fat = null;
		} else {
			$protein = self::pct($input['protein_pct'] ?? null, 'proteína');
			$carbs = self::pct($input['carbs_pct'] ?? null, 'carbohidratos');
			$fat = self::pct($input['fat_pct'] ?? null, 'grasas');
		}
		$goals = Nutrition::requirements(
			$bio['gender'],
			$bio['weight_kg'],
			$bio['height_cm'],
			$bio['age'],
			$bio['activity_level'],
			$protein,
			$carbs,
			$fat
		);
		$stmt = $this->db->prepare(
			'UPDATE users SET
				bmr = :bmr,
				tdee = :tdee,
				goal_kcal = :goal_kcal,
				goal_protein = :goal_protein,
				goal_carbs = :goal_carbs,
				goal_fat = :goal_fat,
				protein_pct = :protein_pct,
				carbs_pct = :carbs_pct,
				fat_pct = :fat_pct
			WHERE id = :id'
		);
		$stmt->execute(array(
			'bmr' => $goals['bmr'],
			'tdee' => $goals['tdee'],
			'goal_kcal' => $goals['goal_kcal'],
			'goal_protein' => $goals['goal_protein'],
			'goal_carbs' => $goals['goal_carbs'],
			'goal_fat' => $goals['goal_fat'],
			'protein_pct' => $goals['protein_pct'],
			'carbs_pct' => $goals['carbs_pct'],
			'fat_pct' => $goals['fat_pct'],
			'id' => $id,
		));
		return self::present($this->findRawById($id));
	}

	public static function biometrics(array $input): array {
		$gender = $input['gender'] ?? null;
		if (!in_array($gender, array('male', 'female'), true)) {
			throw new Exception('Selecciona un género válido', 422);
		}
		$age = filter_var($input['age'] ?? null, FILTER_VALIDATE_INT);
		if ($age === false || $age < 10 || $age > 120) {
			throw new Exception('La edad debe estar entre 10 y 120 años', 422);
		}
		$weight = filter_var($input['weight_kg'] ?? null, FILTER_VALIDATE_FLOAT);
		if ($weight === false || $weight < 25 || $weight > 400) {
			throw new Exception('El peso debe estar entre 25 y 400 kg', 422);
		}
		$height = filter_var($input['height_cm'] ?? null, FILTER_VALIDATE_FLOAT);
		if ($height === false || $height < 80 || $height > 250) {
			throw new Exception('La altura debe estar entre 80 y 250 cm', 422);
		}
		$activity = $input['activity_level'] ?? null;
		if (!isset(Nutrition::ACTIVITY[$activity])) {
			throw new Exception('Selecciona un nivel de actividad válido', 422);
		}
		return array(
			'gender' => $gender,
			'age' => (int) $age,
			'weight_kg' => (float) $weight,
			'height_cm' => (float) $height,
			'activity_level' => $activity,
		);
	}

	public static function present(?array $row): array {
		if (!$row) {
			throw new Exception('Usuario no encontrado', 404);
		}
		return array(
			'id' => (int) $row['id'],
			'email' => $row['email'],
			'name' => $row['name'],
			'gender' => $row['gender'],
			'age' => $row['age'] !== null ? (int) $row['age'] : null,
			'weight_kg' => self::num($row['weight_kg']),
			'height_cm' => self::num($row['height_cm']),
			'activity_level' => $row['activity_level'],
			'bmr' => self::num($row['bmr']),
			'tdee' => self::num($row['tdee']),
			'goal_kcal' => self::num($row['goal_kcal']),
			'goal_protein' => self::num($row['goal_protein']),
			'goal_carbs' => self::num($row['goal_carbs']),
			'goal_fat' => self::num($row['goal_fat']),
			'protein_pct' => self::num($row['protein_pct']),
			'carbs_pct' => self::num($row['carbs_pct']),
			'fat_pct' => self::num($row['fat_pct']),
			'onboarding_complete' => $row['tdee'] !== null,
		);
	}

	private static function percentages(array $row): array {
		$protein = self::num($row['protein_pct']);
		$carbs = self::num($row['carbs_pct']);
		$fat = self::num($row['fat_pct']);
		if ($protein === null && $carbs === null && $fat === null) {
			return array('protein_pct' => null, 'carbs_pct' => null, 'fat_pct' => null);
		}
		return array(
			'protein_pct' => $protein,
			'carbs_pct' => $carbs,
			'fat_pct' => $fat,
		);
	}

	private static function pct($value, string $label): float {
		$number = filter_var($value, FILTER_VALIDATE_FLOAT);
		if ($number === false) {
			throw new Exception("Indica el porcentaje de $label", 422);
		}
		return (float) $number;
	}

	private static function num($value): ?float {
		return $value === null ? null : (float) $value;
	}
}
