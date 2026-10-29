<?php

use Zediz\Macrofit\models\DailyLog;
use Zediz\Macrofit\shared\Request;

try {
	$log = (new DailyLog())->create(Request::userId(), Request::json());
	HTTP::_201($log);
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
