<?php

class HTTP {
	private static $warnings = [];

	public static function isDebug() {
		$value = getenv('APP_DEBUG');
		if ($value === false) {
			$value = isset($_ENV['APP_DEBUG']) ? $_ENV['APP_DEBUG'] : 'false';
		}
		return filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}

	public static function json($status, $data) {
		if (!headers_sent()) {
			http_response_code($status);
			header('Content-Type: application/json; charset=UTF-8');
		}
		echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
	}

	public static function _200($body = '') {
		self::json(200, array("success" => true, "body" => $body));
	}

	public static function _200List($body = '') {
		self::json(200, $body);
	}

	public static function _201($body = '') {
		self::json(201, array("success" => true, "body" => $body));
	}

	// Por especificación HTTP un 204 no lleva cuerpo
	public static function _204() {
		if (!headers_sent()) {
			http_response_code(204);
		}
	}

	public static function _400($errorMsg, $e = null) {
		self::error(400, $errorMsg, $e);
	}

	public static function _401($errorMsg = 'No authorized', $e = null) {
		self::error(401, $errorMsg, $e);
	}

	public static function _403($errorMsg = 'Forbidden', $e = null) {
		self::error(403, $errorMsg, $e);
	}

	public static function _404($errorMsg, $e = null) {
		self::error(404, $errorMsg, $e);
	}

	public static function _405($errorMsg, $e = null) {
		self::error(405, $errorMsg, $e);
	}

	public static function _409($errorMsg, $e = null) {
		self::error(409, $errorMsg, $e);
	}

	public static function _422($errorMsg, $e = null) {
		self::error(422, $errorMsg, $e);
	}

	public static function _500($errorMsg = 'Error interno del servidor', $e = null) {
		self::error(500, $errorMsg, $e);
	}

	// Excepciones con código 4xx (throw new Exception('...', 404)) se respetan; el resto es 500
	public static function fromException($e, $errorMsg = null) {
		$code = (int) $e->getCode();
		$status = ($code >= 400 && $code < 500) ? $code : 500;
		self::error($status, $errorMsg !== null ? $errorMsg : $e->getMessage(), $e);
	}

	public static function error($status, $errorMsg, $e = null) {
		$debug = self::isDebug();
		$response = array("success" => false, "body" => $errorMsg);

		if ($status >= 500) {
			$errorId = bin2hex(random_bytes(4));
			error_log("[$errorId] $errorMsg" . ($e ? ' | ' . self::summary($e) : ''));
			$response["error_id"] = $errorId;
			if (!$debug) {
				// En producción nunca se expone el detalle (SQL, rutas, credenciales)
				$response["body"] = 'Error interno del servidor';
			}
		}

		if ($debug && ($e || self::$warnings)) {
			$response["debug"] = array(
				"exception" => $e ? self::describe($e) : null,
				"warnings" => self::$warnings,
			);
			self::$warnings = [];
		}

		self::json($status, $response);
	}

	public static function registerErrorHandlers() {
		ini_set('display_errors', '0');
		ini_set('log_errors', '1');
		error_reporting(E_ALL);
		ob_start();

		// Warnings / notices / deprecated: no detienen la ejecución, se acumulan para el frontend
		set_error_handler(function ($severity, $message, $file, $line) {
			if (!(error_reporting() & $severity)) {
				return false;
			}
			$warning = array(
				"type" => self::severityName($severity),
				"message" => $message,
				"file" => self::relativePath($file),
				"line" => $line,
			);
			self::$warnings[] = $warning;
			error_log("PHP {$warning['type']}: $message in {$warning['file']}:$line");
			return true;
		});

		// Excepciones no capturadas en controladores/modelos
		set_exception_handler(function ($e) {
			self::discardOutput();
			self::fromException($e);
		});

		// Fatal errors (sintaxis, memoria, clase no encontrada...) y warnings pendientes
		register_shutdown_function(function () {
			$error = error_get_last();
			if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
				self::discardOutput();
				self::_500($error['message'], new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
				return;
			}
			self::flushWarnings();
		});
	}

	private static function flushWarnings() {
		if (!self::$warnings || !self::isDebug() || ob_get_level() === 0) {
			return;
		}
		if (!headers_sent()) {
			header('X-Debug-Warnings: ' . substr(json_encode(self::$warnings), 0, 4000));
		}
		$data = json_decode(ob_get_contents(), true);
		if (is_array($data) && !array_is_list($data)) {
			$data["debug"]["warnings"] = self::$warnings;
			ob_clean();
			echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
		}
	}

	private static function discardOutput() {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
	}

	private static function describe($e) {
		$info = array(
			"type" => get_class($e),
			"message" => $e->getMessage(),
			"code" => $e->getCode(),
			"file" => self::relativePath($e->getFile()),
			"line" => $e->getLine(),
			"trace" => array_map(function ($frame) {
				$location = isset($frame['file']) ? self::relativePath($frame['file']) . ':' . ($frame['line'] ?? '?') . ' ' : '';
				$function = (isset($frame['class']) ? $frame['class'] . $frame['type'] : '') . $frame['function'] . '()';
				return $location . $function;
			}, array_slice($e->getTrace(), 0, 15)),
		);
		if ($e->getPrevious()) {
			$info["previous"] = self::describe($e->getPrevious());
		}
		return $info;
	}

	private static function summary($e) {
		return get_class($e) . ': ' . $e->getMessage() . ' @ ' . self::relativePath($e->getFile()) . ':' . $e->getLine();
	}

	private static function relativePath($path) {
		return str_replace(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR, '', $path);
	}

	private static function severityName($severity) {
		$names = array(
			E_WARNING => 'Warning',
			E_NOTICE => 'Notice',
			E_DEPRECATED => 'Deprecated',
			E_USER_WARNING => 'User Warning',
			E_USER_NOTICE => 'User Notice',
			E_USER_DEPRECATED => 'User Deprecated',
			E_RECOVERABLE_ERROR => 'Recoverable Error',
		);
		return isset($names[$severity]) ? $names[$severity] : "Error ($severity)";
	}
}
