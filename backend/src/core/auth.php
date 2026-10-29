<?php

class Auth {
	private static $payload = null;

	// Valida el Bearer token; si falta o no es válido responde 401 y termina
	public static function check() {
		$token = self::bearerToken();
		if ($token === null) {
			HTTP::_401('No authorized');
			exit(0);
		}
		self::$payload = JWT::validateJwt($token);
		return self::$payload;
	}

	// Payload del token de la petición actual (null en rutas públicas)
	public static function user() {
		return self::$payload;
	}

	public static function bearerToken() {
		$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		if ($header === '' && function_exists('getallheaders')) {
			foreach (getallheaders() as $name => $value) {
				if (strcasecmp($name, 'Authorization') === 0) {
					$header = $value;
				}
			}
		}
		return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) ? $matches[1] : null;
	}
}
