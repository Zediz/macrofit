<?php

namespace Zediz\Macrofit\shared;

use Auth;
use Exception;

class Request {
	public static function json(): array {
		$raw = file_get_contents('php://input');
		if ($raw === false || trim($raw) === '') {
			return array();
		}
		$data = json_decode($raw, true);
		if (!is_array($data)) {
			throw new Exception('JSON inválido', 400);
		}
		return $data;
	}

	public static function userId(): int {
		$user = Auth::user();
		if (!is_array($user) || !isset($user['id']) || !is_numeric($user['id'])) {
			throw new Exception('No authorized', 401);
		}
		return (int) $user['id'];
	}

	public static function dateOrToday(?string $value): string {
		if ($value === null || $value === '') {
			return date('Y-m-d');
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			throw new Exception('La fecha debe tener formato YYYY-MM-DD', 422);
		}
		[$year, $month, $day] = array_map('intval', explode('-', $value));
		if (!checkdate($month, $day, $year)) {
			throw new Exception('La fecha no es válida', 422);
		}
		return $value;
	}
}
