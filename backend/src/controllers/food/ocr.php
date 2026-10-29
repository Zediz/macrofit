<?php

use Zediz\Macrofit\shared\LabelOcr;
use Zediz\Macrofit\shared\Request;

try {
	LabelOcr::assertConfigured();
	$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
	$json = array();
	if (stripos($contentType, 'application/json') !== false || stripos($contentType, 'text/json') !== false) {
		$json = Request::json();
	}
	$image = LabelOcr::imageFromRequest($json, $_FILES);
	HTTP::_200((new LabelOcr())->read($image['bytes'], $image['mime']));
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
