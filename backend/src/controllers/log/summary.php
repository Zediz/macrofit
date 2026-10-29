<?php

use Zediz\Macrofit\models\DailyLog;
use Zediz\Macrofit\models\User;
use Zediz\Macrofit\shared\Request;

try {
	$userId = Request::userId();
	$date = Request::dateOrToday(isset($_GET['date']) ? (string) $_GET['date'] : null);
	$user = User::present((new User())->findRawById($userId));
	HTTP::_200((new DailyLog())->summary($userId, $date, $user));
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
