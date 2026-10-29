<?php

use Zediz\Macrofit\models\Recipe;
use Zediz\Macrofit\shared\Request;

try {
	$recipe = (new Recipe())->update(Request::userId(), (int) $recipe_id, Request::json());
	HTTP::_200($recipe);
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
