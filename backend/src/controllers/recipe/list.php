<?php

use Zediz\Macrofit\models\Recipe;
use Zediz\Macrofit\shared\Request;

try {
	HTTP::_200List((new Recipe())->listByUser(Request::userId()));
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
