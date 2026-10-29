<?php

use Zediz\Macrofit\models\Food;

try {
	$barcode = trim((string) $barcode);
	$food = (new Food())->findByBarcode($barcode);
	if (!$food) {
		throw new Exception('Alimento no encontrado', 404);
	}
	HTTP::_200($food);
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
