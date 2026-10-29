<?php

use Zediz\Macrofit\models\Recipe;
use Zediz\Macrofit\shared\Request;

try {
	(new Recipe())->delete(Request::userId(), (int) $recipe_id);
	HTTP::_200(array('deleted' => true));
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
