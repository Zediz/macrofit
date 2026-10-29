<?php

use Zediz\Macrofit\models\DailyLog;
use Zediz\Macrofit\shared\Request;

try {
	$date = Request::dateOrToday(isset($_GET['date']) ? (string) $_GET['date'] : null);
	HTTP::_200List((new DailyLog())->listByDate(Request::userId(), $date));
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
