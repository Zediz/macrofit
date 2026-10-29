<?php

use Zediz\Macrofit\models\Recipe;
use Zediz\Macrofit\shared\Request;

try {
	$recipe = (new Recipe())->findOwned(Request::userId(), (int) $recipe_id);
	if (!$recipe) {
		throw new Exception('Receta no encontrada', 404);
	}
	HTTP::_200($recipe);
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
