<?php

use Zediz\Macrofit\models\User;
use Zediz\Macrofit\shared\Request;

try {
	$user = (new User())->updateProfile(Request::userId(), Request::json());
	HTTP::_200($user);
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
