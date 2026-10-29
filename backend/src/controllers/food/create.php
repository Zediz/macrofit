<?php

use Zediz\Macrofit\models\Food;
use Zediz\Macrofit\shared\Request;

try {
	$food = (new Food())->create(Request::userId(), Request::json());
	HTTP::_201($food);
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
