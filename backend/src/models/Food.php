<?php

namespace Zediz\Macrofit\models;

use Database;
use Exception;
use PDO;

class Food {
	private PDO $db;

	public function __construct() {
		$this->db = Database::getInstance()->getConnection();
	}

	public function search(string $query, int $limit = 30): array {
		$limit = max(1, min($limit, 50));
		$query = trim($query);
		if ($query === '') {
			$stmt = $this->db->query(
				"SELECT * FROM foods ORDER BY name ASC LIMIT $limit"
			);
			return array_map(array($this, 'present'), $stmt->fetchAll(PDO::FETCH_ASSOC));
		}
		$like = '%' . $this->escapeLike($query) . '%';
		$stmt = $this->db->prepare(
			"SELECT * FROM foods
			 WHERE name LIKE :like OR brand LIKE :like OR barcode = :exact
			 ORDER BY (barcode = :exact) DESC, name ASC
			 LIMIT $limit"
		);
		$stmt->execute(array('like' => $like, 'exact' => $query));
		return array_map(array($this, 'present'), $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	public function findByBarcode(string $barcode): ?array {
		$stmt = $this->db->prepare('SELECT * FROM foods WHERE barcode = :barcode LIMIT 1');
		$stmt->execute(array('barcode' => $barcode));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? $this->present($row) : null;
	}

	public function findById(int $id): ?array {
		$stmt = $this->db->prepare('SELECT * FROM foods WHERE id = :id LIMIT 1');
		$stmt->execute(array('id' => $id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? $this->present($row) : null;
	}

	public function create(int $userId, array $input): array {
		$name = trim((string) ($input['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 255) {
			throw new Exception('El nombre del alimento es obligatorio', 422);
		}
		$brand = trim((string) ($input['brand'] ?? ''));
		$brand = $brand === '' ? null : $brand;
		if ($brand !== null && mb_strlen($brand) > 255) {
			throw new Exception('La marca es demasiado larga', 422);
		}
		$barcode = preg_replace('/\s+/', '', trim((string) ($input['barcode'] ?? '')));
		$barcode = $barcode === '' ? null : $barcode;
		if ($barcode !== null && !preg_match('/^[0-9A-Za-z]{4,32}$/', $barcode)) {
			throw new Exception('El código de barras no es válido', 422);
		}
		$kcal = self::macro($input['kcal_per_100g'] ?? null, 'kcal');
		$protein = self::macro($input['protein_per_100g'] ?? null, 'proteína');
		$carbs = self::macro($input['carbs_per_100g'] ?? null, 'carbohidratos');
		$fat = self::macro($input['fat_per_100g'] ?? null, 'grasas');
		$source = $input['source'] ?? 'local';
		if (!in_array($source, array('local', 'openfoodfacts', 'ocr'), true)) {
			throw new Exception('Origen de alimento no válido', 422);
		}
		if ($barcode !== null && $this->findByBarcode($barcode)) {
			throw new Exception('Ese código de barras ya está en el catálogo', 409);
		}
		try {
			$stmt = $this->db->prepare(
				'INSERT INTO foods
					(name, brand, barcode, kcal_per_100g, protein_per_100g, carbs_per_100g, fat_per_100g, source, created_by)
				 VALUES
					(:name, :brand, :barcode, :kcal, :protein, :carbs, :fat, :source, :created_by)'
			);
			$stmt->execute(array(
				'name' => $name,
				'brand' => $brand,
				'barcode' => $barcode,
				'kcal' => $kcal,
				'protein' => $protein,
				'carbs' => $carbs,
				'fat' => $fat,
				'source' => $source,
				'created_by' => $userId,
			));
		} catch (\PDOException $e) {
			if ((string) $e->getCode() === '23000') {
				throw new Exception('Ese código de barras ya está en el catálogo', 409);
			}
			throw $e;
		}
		return $this->findById((int) $this->db->lastInsertId());
	}

	public function present(array $row): array {
		return array(
			'id' => (int) $row['id'],
			'name' => $row['name'],
			'brand' => $row['brand'],
			'barcode' => $row['barcode'],
			'kcal_per_100g' => (float) $row['kcal_per_100g'],
			'protein_per_100g' => (float) $row['protein_per_100g'],
			'carbs_per_100g' => (float) $row['carbs_per_100g'],
			'fat_per_100g' => (float) $row['fat_per_100g'],
			'source' => $row['source'],
			'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
		);
	}

	private function escapeLike(string $value): string {
		return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $value);
	}

	private static function macro($value, string $label): float {
		$number = filter_var($value, FILTER_VALIDATE_FLOAT);
		if ($number === false || $number < 0 || $number > 1000) {
			throw new Exception("El valor de $label por 100 g no es válido", 422);
		}
		return round((float) $number, 2);
	}
}
