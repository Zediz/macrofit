<?php

use Zediz\Macrofit\models\User;
use Zediz\Macrofit\shared\Request;

try {
	$row = (new User())->findRawById(Request::userId());
	HTTP::_200(User::present($row));
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
