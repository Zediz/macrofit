<?php

use Zediz\Macrofit\models\DailyLog;
use Zediz\Macrofit\shared\Request;

try {
	(new DailyLog())->delete(Request::userId(), (int) $log_id);
	HTTP::_200(array('deleted' => true));
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
