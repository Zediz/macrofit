<?php

class JWT {
	const ALGORITHM = 'HS256';
	const DEFAULT_TTL = 28800; // 8 horas, se puede cambiar con JWT_TTL en el .env
	const LEEWAY = 60; // tolerancia de reloj en segundos

	// Valida el token y regresa su payload. Si no es válido responde 401 y termina.
	public static function validateJwt($jwt) {
		$secretKey = self::secret();
		$jwt = preg_replace('/^Bearer\s+/i', '', trim((string) $jwt));
		try {
			$parts = explode('.', $jwt);

			if (count($parts) !== 3) {
				throw new Exception('Formato de JWT inválido.');
			}

			list($headerB64, $payloadB64, $signatureB64) = $parts;

			// La firma se verifica ANTES de confiar en el contenido
			$expectedSignature = self::base64UrlEncode(hash_hmac('sha256', "$headerB64.$payloadB64", $secretKey, true));
			if (!hash_equals($expectedSignature, $signatureB64)) {
				throw new Exception('Firma del JWT no válida.');
			}

			$header = json_decode(self::base64UrlDecode($headerB64), true);
			$payload = json_decode(self::base64UrlDecode($payloadB64), true);

			if (!is_array($header) || !is_array($payload)) {
				throw new Exception('Error al decodificar JWT.');
			}

			if (($header['alg'] ?? null) !== self::ALGORITHM) {
				throw new Exception('Algoritmo de firma no válido.');
			}

			$now = time();
			if (!isset($payload['exp']) || !is_numeric($payload['exp'])) {
				throw new Exception('El JWT no tiene expiración.');
			}
			if ($now - self::LEEWAY >= $payload['exp']) {
				throw new Exception('El JWT ha expirado.');
			}
			if (isset($payload['nbf']) && $now + self::LEEWAY < $payload['nbf']) {
				throw new Exception('El JWT aún no es válido.');
			}

			return $payload;
		} catch (Exception $e) {
			error_log('Error al validar JWT: ' . $e->getMessage());
			HTTP::_401('No authorized', $e);
			exit(0);
		}
	}

	// Agrega iat y exp automáticamente si el payload no los trae
	public static function generateJwt($payload, $ttl = null) {
		$payload = (array) $payload;
		$now = time();
		$payload['iat'] = $payload['iat'] ?? $now;
		$payload['exp'] = $payload['exp'] ?? $now + ($ttl ?? (int) ($_ENV['JWT_TTL'] ?? self::DEFAULT_TTL));

		$headerEncoded = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => self::ALGORITHM]));
		$payloadEncoded = self::base64UrlEncode(json_encode($payload));
		$signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", self::secret(), true);
		return "$headerEncoded.$payloadEncoded." . self::base64UrlEncode($signature);
	}

	// Coincidencia EXACTA contra el patrón de la ruta (ej. "/user/login"), nunca por substring
	public static function excludedRoutes($route) {
		$excludedRoutes = explode(',', $_ENV['JWT_EXCLUDED_ROUTES'] ?? '');
		foreach ($excludedRoutes as $excludedRoute) {
			$excludedRoute = trim($excludedRoute);
			if ($excludedRoute !== '' && '/' . trim($excludedRoute, '/') === $route) {
				return true;
			}
		}
		return false;
	}

	private static function secret() {
		$secretKey = $_ENV['JWT_SECRET'] ?? '';
		if ($secretKey === '') {
			throw new RuntimeException('JWT_SECRET no está configurado en el .env');
		}
		if (strlen($secretKey) < 32) {
			error_log('ADVERTENCIA: JWT_SECRET tiene menos de 32 caracteres. Genera uno con: openssl rand -hex 32');
		}
		return $secretKey;
	}

	private static function base64UrlEncode($data) {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	private static function base64UrlDecode($data) {
		$data = strtr($data, '-_', '+/');
		return base64_decode(str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '='), true);
	}
}
