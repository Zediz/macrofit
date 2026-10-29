<?php

use Zediz\Macrofit\models\Food;

try {
	$query = trim((string) ($_GET['q'] ?? ''));
	HTTP::_200List((new Food())->search($query));
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
