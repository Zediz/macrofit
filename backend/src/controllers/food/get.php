<?php

use Zediz\Macrofit\models\Food;

try {
	$food = (new Food())->findById((int) $food_id);
	if (!$food) {
		throw new Exception('Alimento no encontrado', 404);
	}
	HTTP::_200($food);
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
