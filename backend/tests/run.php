<?php

require_once __DIR__ . '/../src/shared/Nutrition.php';
require_once __DIR__ . '/../src/shared/LabelOcr.php';
require_once __DIR__ . '/../src/core/http.php';

use Zediz\Macrofit\shared\LabelOcr;
use Zediz\Macrofit\shared\Nutrition;

$failures = 0;

function check(bool $ok, string $message): void {
	global $failures;
	if (!$ok) {
		fwrite(STDERR, "FAIL $message\n");
		$failures++;
	}
}

function near(float $actual, float $expected, string $message): void {
	check(abs($actual - $expected) < 0.001, "$message (got $actual, expected $expected)");
}

function expect_exception(callable $fn, int $code, string $needle): void {
	try {
		$fn();
		check(false, "expected exception containing $needle");
	} catch (Exception $e) {
		check((int) $e->getCode() === $code, "code {$e->getCode()} for $needle");
		check(str_contains($e->getMessage(), $needle), $e->getMessage() . " / $needle");
	}
}

/** @param array<string, string|null> $vars */
function with_env(array $vars, callable $fn): void {
	$previous = array();
	foreach ($vars as $name => $value) {
		$previous[$name] = array(
			'env' => array_key_exists($name, $_ENV) ? $_ENV[$name] : null,
			'env_set' => array_key_exists($name, $_ENV),
			'server' => array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null,
			'server_set' => array_key_exists($name, $_SERVER),
			'getenv' => getenv($name),
		);
		if ($value === null) {
			unset($_ENV[$name], $_SERVER[$name]);
			putenv($name);
		} else {
			$_ENV[$name] = $value;
			$_SERVER[$name] = $value;
			putenv($name . '=' . $value);
		}
	}
	try {
		$fn();
	} finally {
		foreach ($previous as $name => $old) {
			if (!$old['env_set']) {
				unset($_ENV[$name]);
			} else {
				$_ENV[$name] = $old['env'];
			}
			if (!$old['server_set']) {
				unset($_SERVER[$name]);
			} else {
				$_SERVER[$name] = $old['server'];
			}
			if ($old['getenv'] === false) {
				putenv($name);
			} else {
				putenv($name . '=' . $old['getenv']);
			}
		}
	}
}

$chicken = array('kcal' => 110, 'protein' => 23.1, 'carbs' => 0, 'fat' => 1.2);
$yogurt = array('kcal' => 62, 'protein' => 3.5, 'carbs' => 4.7, 'fat' => 3.3);

$scaled = Nutrition::scalePer100g($yogurt, 150);
near($scaled['kcal'], 93, '150 g yogurt kcal');
near($scaled['protein'], 5.25, '150 g yogurt protein');
near($scaled['carbs'], 7.05, '150 g yogurt carbs');
near($scaled['fat'], 4.95, '150 g yogurt fat');

$portion = Nutrition::logMacros('food', 'portions', 1.5, array('kcal' => 200, 'protein' => 10, 'carbs' => 20, 'fat' => 5), null, 0, 0, 30);
near($portion['kcal'], 90, '1.5 servings kcal');
near($portion['protein'], 4.5, '1.5 servings protein');
near($portion['carbs'], 9, '1.5 servings carbs');
near($portion['fat'], 2.25, '1.5 servings fat');
near(Nutrition::foodGrams('portions', 1.5, 30), 45, '1.5 x 30 g');

expect_exception(function () use ($chicken) {
	Nutrition::logMacros('food', 'portions', 2, $chicken);
}, 422, 'porción');

$recipePortion = Nutrition::logMacros('recipe', 'portions', 1, null, $yogurt, 2, 100);
near($recipePortion['kcal'], 31, 'recipe portion kcal');
near($recipePortion['protein'], 1.75, 'recipe portion protein');
near(Nutrition::recipeSnapshotGrams('portions', 1, 2, 100), 50, 'recipe snapshot grams');

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
check(is_string($png) && strlen($png) >= 24, 'png fixture');

$fromUrl = LabelOcr::imageFromEncoded('data:image/png;base64,' . base64_encode($png));
check($fromUrl['mime'] === 'image/png', 'data url mime ' . $fromUrl['mime']);
check($fromUrl['bytes'] === $png, 'data url bytes');

expect_exception(function () {
	LabelOcr::imageFromEncoded(base64_encode(str_repeat('not an image ', 4)));
}, 422, 'JPEG');

expect_exception(function () {
	LabelOcr::imageFromEncoded('****');
}, 422, 'base64');

expect_exception(function () {
	LabelOcr::imageFromEncoded(str_repeat('A', LabelOcr::MAX_BASE64_CHARS + 1));
}, 422, 'demasiado grande');

$per100 = LabelOcr::interpret(array(
	'readable' => true,
	'name' => "  Yogurt\nnatural  ",
	'serving_grams' => 0,
	'has_per_100g' => true,
	'kcal_100g' => '62',
	'protein_100g' => '3,5',
	'carbs_100g' => 4.7,
	'fat_100g' => 3.3,
	'has_per_serving' => false,
));
check($per100['name'] === 'Yogurt natural', 'ocr name');
check($per100['serving_grams'] === null, 'serving 0 is null');
near($per100['protein_per_100g'], 3.5, 'comma decimal');

$converted = LabelOcr::interpret(array(
	'readable' => true,
	'name' => 'Galleta',
	'serving_grams' => 30,
	'has_per_100g' => false,
	'has_per_serving' => true,
	'kcal_serving' => 144,
	'protein_serving' => 1.8,
	'carbs_serving' => 21,
	'fat_serving' => 5.4,
));
near($converted['kcal_per_100g'], 480, 'per serving kcal to 100 g');
near($converted['protein_per_100g'], 6, 'per serving protein to 100 g');
near($converted['carbs_per_100g'], 70, 'per serving carbs to 100 g');
near($converted['fat_per_100g'], 18, 'per serving fat to 100 g');
check($converted['serving_grams'] === 30.0, 'serving kept');

$preferred = LabelOcr::interpret(array(
	'readable' => true,
	'name' => 'Mixto',
	'serving_grams' => 30,
	'has_per_100g' => true,
	'kcal_100g' => 100,
	'protein_100g' => 1,
	'carbs_100g' => 2,
	'fat_100g' => 3,
	'has_per_serving' => true,
	'kcal_serving' => 999,
	'protein_serving' => 1,
	'carbs_serving' => 1,
	'fat_serving' => 1,
));
near($preferred['kcal_per_100g'], 100, 'per 100 g wins over per serving');

expect_exception(function () {
	LabelOcr::interpret(array('readable' => false, 'name' => 'Nada'));
}, 422, 'tabla nutrimental');

expect_exception(function () {
	LabelOcr::interpret(array(
		'readable' => true,
		'has_per_serving' => true,
		'serving_grams' => 0,
		'kcal_serving' => 10,
		'protein_serving' => 1,
		'carbs_serving' => 1,
		'fat_serving' => 1,
	));
}, 422, 'por porción');

expect_exception(function () {
	LabelOcr::interpret(array(
		'readable' => true,
		'has_per_serving' => true,
		'serving_grams' => 10,
		'kcal_serving' => 500,
		'protein_serving' => 1,
		'carbs_serving' => 1,
		'fat_serving' => 1,
	));
}, 422, 'no cuadran');

$fenced = LabelOcr::decodeJsonText("```json\n{\"readable\":true,\"kcal_per_100g\":1,\"protein_per_100g\":0,\"carbs_per_100g\":0,\"fat_per_100g\":0}\n```");
check(($fenced['kcal_per_100g'] ?? null) === 1, 'fenced json');

$calls = 0;
with_env(array('GEMINI_API_KEY' => null, 'GEMINI_MODEL' => null), function () use (&$calls) {
	$ocr = new LabelOcr(function () use (&$calls) {
		$calls++;
		return array('status' => 200, 'body' => '{}', 'timeout' => false);
	});
	expect_exception(function () use ($ocr) {
		$ocr->read('not-used', 'image/png');
	}, 503, 'GEMINI_API_KEY');
});
check($calls === 0, 'missing key must not call Gemini');

with_env(array('GEMINI_API_KEY' => LabelOcr::PLACEHOLDER_KEY, 'GEMINI_MODEL' => null), function () use ($png) {
	$ocr = new LabelOcr(function () {
		throw new Exception('transport should not run');
	});
	expect_exception(function () use ($ocr, $png) {
		$ocr->read($png, 'image/png');
	}, 503, 'GEMINI_API_KEY');
});

with_env(array('GEMINI_API_KEY' => 'test-gemini-key-not-real', 'GEMINI_MODEL' => 'gemini-2.0-flash'), function () use ($png) {
	$seen = null;
	$ocr = new LabelOcr(function (array $request) use (&$seen) {
		$seen = $request;
		$payload = json_encode(array(
			'readable' => true,
			'name' => 'Yogurt',
			'serving_grams' => 125,
			'has_per_100g' => true,
			'kcal_100g' => 62,
			'protein_100g' => 3.5,
			'carbs_100g' => 4.7,
			'fat_100g' => 3.3,
			'has_per_serving' => false,
			'kcal_serving' => 0,
			'protein_serving' => 0,
			'carbs_serving' => 0,
			'fat_serving' => 0,
		));
		return array(
			'status' => 200,
			'timeout' => false,
			'body' => json_encode(array(
				'candidates' => array(array(
					'content' => array('parts' => array(array('text' => $payload))),
				)),
			)),
		);
	});
	$result = $ocr->read($png, 'image/png');
	check(is_array($seen), 'transport called');
	check(is_array($seen) && str_contains($seen['url'], '/models/gemini-2.0-flash:generateContent'), 'model in url');
	check(is_array($seen) && !str_contains($seen['url'], 'test-gemini-key-not-real'), 'key stays out of the url');
	check(is_array($seen) && !str_contains($seen['body'], 'test-gemini-key-not-real'), 'key stays out of the body');
	check(is_array($seen) && str_contains($seen['body'], 'responseMimeType'), 'strict json requested');
	check(is_array($seen) && str_contains($seen['body'], 'inlineData'), 'image part');
	check($result['name'] === 'Yogurt', 'parsed name');
	near($result['kcal_per_100g'], 62, 'parsed kcal');
	check($result['serving_grams'] === 125.0, 'parsed serving');
});

with_env(array('GEMINI_API_KEY' => 'test-gemini-key-not-real', 'GEMINI_MODEL' => null), function () use ($png) {
	$timeout = new LabelOcr(function () {
		return array('status' => 0, 'body' => '', 'timeout' => true);
	});
	expect_exception(function () use ($timeout, $png) {
		$timeout->read($png, 'image/png');
	}, 504, 'tardó demasiado');

	$down = new LabelOcr(function () {
		return array('status' => 500, 'body' => '{"error":"leaked SECRET-KEY"}', 'timeout' => false);
	});
	try {
		$down->read($png, 'image/png');
		check(false, 'gemini error should throw');
	} catch (Exception $e) {
		check((int) $e->getCode() === 502, 'gemini status');
		check(!str_contains($e->getMessage(), 'SECRET'), 'gemini body is not returned');
		check(str_contains($e->getMessage(), 'Gemini'), $e->getMessage());
	}
});

with_env(array('APP_DEBUG' => 'false'), function () {
	ob_start();
	HTTP::fromException(new Exception(LabelOcr::MSG_MISSING_KEY, 503));
	$missing = ob_get_clean();
	$decoded = json_decode((string) $missing, true);
	check(is_array($decoded) && ($decoded['body'] ?? '') === LabelOcr::MSG_MISSING_KEY, '503 keeps the missing-key message');
	check(http_response_code() === 503, '503 status');

	ob_start();
	HTTP::fromException(new Exception('SQLSTATE secret password', 500));
	$hidden = ob_get_clean();
	$decoded = json_decode((string) $hidden, true);
	check(is_array($decoded) && ($decoded['body'] ?? '') === 'Error interno del servidor', '500 hides internals');
	check(is_array($decoded) && !str_contains((string) $hidden, 'SQLSTATE'), '500 body has no sql');
});

if ($failures > 0) {
	fwrite(STDERR, "$failures failed\n");
	exit(1);
}

echo "php tests ok\n";
