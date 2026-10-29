<?php

use Zediz\Macrofit\models\Recipe;
use Zediz\Macrofit\shared\Request;

try {
	$recipe = (new Recipe())->create(Request::userId(), Request::json());
	HTTP::_201($recipe);
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
