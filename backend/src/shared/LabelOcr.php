<?php

namespace Zediz\Macrofit\shared;

use Exception;
use finfo;

/**
 * Lee una tabla nutrimental con Gemini. La clave sale de GEMINI_API_KEY y no se
 * escribe en la URL, en el JSON ni en los mensajes de error.
 */
class LabelOcr {
	public const DEFAULT_MODEL = 'gemini-2.5-flash';
	public const PLACEHOLDER_KEY = 'replace-with-a-google-ai-studio-key';
	public const TIMEOUT = 20;
	public const MAX_BYTES = 3500000;
	public const MAX_BASE64_CHARS = 5000000;

	public const MSG_MISSING_KEY = 'Falta GEMINI_API_KEY. Créala en Google AI Studio y ponla en el entorno del servidor.';
	public const MSG_UNREADABLE = 'No pudimos leer la tabla nutrimental. Intenta con una foto más nítida o captura los valores a mano.';
	public const MSG_SERVING_ONLY = 'La etiqueta solo muestra valores por porción y no indica los gramos. Complétalos a mano.';
	public const MSG_INCOHERENT = 'Los valores de la etiqueta no cuadran por 100 g. Revísalos a mano.';
	public const MSG_GEMINI = 'Gemini no pudo leer la etiqueta. Inténtalo de nuevo.';
	public const MSG_TIMEOUT = 'Gemini tardó demasiado en responder. Inténtalo de nuevo.';
	public const MSG_NO_IMAGE = 'Adjunta la foto de la etiqueta.';

	private const MIMES = array('image/jpeg', 'image/png', 'image/webp', 'image/gif');

	private const PROMPT = <<<'TXT'
You extract nutrition facts from a photo of a food label. Reply with a single JSON object and nothing else.

Schema:
{
  "readable": boolean,
  "name": string,
  "serving_grams": number,
  "has_per_100g": boolean,
  "kcal_100g": number,
  "protein_100g": number,
  "carbs_100g": number,
  "fat_100g": number,
  "has_per_serving": boolean,
  "kcal_serving": number,
  "protein_serving": number,
  "carbs_serving": number,
  "fat_serving": number
}

Rules:
- readable is false when the image is not a nutrition label or the numbers cannot be read. Do not guess.
- name is the product name exactly as printed, or "" if it is not visible. Do not invent a name.
- serving_grams is the serving size in grams when the label states it (for example "porción 30 g" or "serving size 30g"). Use 0 when it is not stated. Do not convert millilitres to grams.
- Energy must be kcal, not kJ. If the label shows kJ, divide by 4.184.
- If the label shows values per 100 g, set has_per_100g true and fill kcal_100g, protein_100g, carbs_100g and fat_100g. Otherwise set has_per_100g false and those four numbers to 0.
- If the label shows values per serving, set has_per_serving true and fill the *_serving numbers with the per-serving amounts. Otherwise set has_per_serving false and those numbers to 0.
- Protein, carbohydrates and fat are grams of each nutrient.
- Never infer a nutrient that is not printed. Do not convert per-serving values yourself; return them in the *_serving fields.
TXT;

	/** @var callable|null */
	private $transport;

	public function __construct(?callable $transport = null) {
		$this->transport = $transport;
	}

	public static function assertConfigured(): void {
		if (self::apiKey() === '') {
			throw new Exception(self::MSG_MISSING_KEY, 503);
		}
	}

	/**
	 * @param array<string, mixed>|null $json
	 * @param array<string, mixed>|null $files
	 * @return array{bytes: string, mime: string}
	 */
	public static function imageFromRequest(?array $json = null, ?array $files = null): array {
		if ($files === null) {
			$files = $_FILES;
		}
		if (isset($files['image']) && is_array($files['image'])) {
			$error = (int) ($files['image']['error'] ?? UPLOAD_ERR_NO_FILE);
			if ($error !== UPLOAD_ERR_NO_FILE) {
				return self::imageFromUpload($files['image']);
			}
		}
		if ($json === null) {
			$json = array();
		}
		$raw = $json['image'] ?? $json['photo'] ?? $json['image_base64'] ?? null;
		if (!is_string($raw) || trim($raw) === '') {
			throw new Exception(self::MSG_NO_IMAGE, 422);
		}
		return self::imageFromEncoded($raw);
	}

	/** @return array{bytes: string, mime: string} */
	public static function imageFromEncoded(string $raw): array {
		$hint = null;
		if (preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,#', $raw, $matches)) {
			$hint = strtolower($matches[1]);
			$raw = substr($raw, strlen($matches[0]));
		}
		$raw = preg_replace('/\s+/', '', $raw) ?? '';
		if ($raw === '') {
			throw new Exception(self::MSG_NO_IMAGE, 422);
		}
		if (strlen($raw) > self::MAX_BASE64_CHARS) {
			throw new Exception('La foto es demasiado grande. Prueba con una más pequeña.', 422);
		}
		$bytes = base64_decode($raw, true);
		if ($bytes === false) {
			throw new Exception('La foto no viene en base64 válido.', 422);
		}
		return self::normalize($bytes, $hint);
	}

	/** @return array{name: ?string, serving_grams: ?float, kcal_per_100g: float, protein_per_100g: float, carbs_per_100g: float, fat_per_100g: float} */
	public function read(string $bytes, string $mime): array {
		self::assertConfigured();
		$image = self::normalize($bytes, $mime);
		$model = self::model();
		$key = self::apiKey();
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
		$body = json_encode(array(
			'contents' => array(array(
				'role' => 'user',
				'parts' => array(
					array('text' => self::PROMPT),
					array('inlineData' => array(
						'mimeType' => $image['mime'],
						'data' => base64_encode($image['bytes']),
					)),
				),
			)),
			'generationConfig' => array(
				'temperature' => 0,
				'responseMimeType' => 'application/json',
				'responseSchema' => self::responseSchema(),
			),
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($body === false) {
			throw new Exception(self::MSG_GEMINI, 502);
		}
		$response = $this->dispatch($url, $body, $key);
		if (!empty($response['timeout'])) {
			throw new Exception(self::MSG_TIMEOUT, 504);
		}
		$status = (int) ($response['status'] ?? 0);
		if ($status < 200 || $status >= 300) {
			error_log('Gemini HTTP ' . $status . ' ' . self::logSnippet((string) ($response['body'] ?? '')));
			throw new Exception(self::MSG_GEMINI, 502);
		}
		return self::interpret(self::decodeJsonText(self::candidateText((string) ($response['body'] ?? ''))));
	}

	/** @param array<string, mixed> $data */
	public static function interpret(array $data): array {
		if (self::asBool($data['readable'] ?? null) === false) {
			throw new Exception(self::MSG_UNREADABLE, 422);
		}
		$name = self::cleanName($data['name'] ?? null);
		$serving = self::servingOrNull($data['serving_grams'] ?? null);
		$per100 = self::per100FromPayload($data);
		if ($per100 === null) {
			$perServing = self::perServingFromPayload($data);
			if ($perServing !== null) {
				if ($serving === null) {
					throw new Exception(self::MSG_SERVING_ONLY, 422);
				}
				$factor = 100 / $serving;
				$per100 = self::macroBlock(array(
					'kcal' => $perServing['kcal'] * $factor,
					'protein' => $perServing['protein'] * $factor,
					'carbs' => $perServing['carbs'] * $factor,
					'fat' => $perServing['fat'] * $factor,
				));
				if ($per100 === null) {
					throw new Exception(self::MSG_INCOHERENT, 422);
				}
			}
		}
		if ($per100 === null) {
			throw new Exception(self::MSG_UNREADABLE, 422);
		}
		return array(
			'name' => $name,
			'serving_grams' => $serving,
			'kcal_per_100g' => $per100['kcal'],
			'protein_per_100g' => $per100['protein'],
			'carbs_per_100g' => $per100['carbs'],
			'fat_per_100g' => $per100['fat'],
		);
	}

	public static function decodeJsonText(string $text): array {
		$text = trim($text);
		if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $matches)) {
			$text = trim($matches[1]);
		}
		$data = json_decode($text, true);
		if (!is_array($data) || array_is_list($data)) {
			throw new Exception(self::MSG_UNREADABLE, 422);
		}
		return $data;
	}

	/** @return array{bytes: string, mime: string} */
	public static function normalize(string $bytes, ?string $hint): array {
		$length = strlen($bytes);
		if ($length < 24) {
			throw new Exception('La foto no es una imagen válida.', 422);
		}
		if ($length > self::MAX_BYTES) {
			throw new Exception('La foto es demasiado grande. Prueba con una más pequeña.', 422);
		}
		$mime = null;
		if (class_exists(finfo::class)) {
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			$detected = $finfo->buffer($bytes);
			if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
				$mime = strtolower($detected);
			}
		}
		if ($mime === null && $hint !== null) {
			$mime = strtolower(trim($hint));
		}
		if ($mime === 'image/jpg') {
			$mime = 'image/jpeg';
		}
		if (!in_array($mime, self::MIMES, true)) {
			throw new Exception('La foto tiene que ser JPEG, PNG, WebP o GIF.', 422);
		}
		return array('bytes' => $bytes, 'mime' => $mime);
	}

	/** @param array<string, mixed> $request */
	private function dispatch(string $url, string $body, string $apiKey): array {
		if ($this->transport !== null) {
			$result = ($this->transport)(array(
				'url' => $url,
				'body' => $body,
				'api_key' => $apiKey,
				'timeout' => self::TIMEOUT,
			));
			if (!is_array($result)) {
				throw new Exception(self::MSG_GEMINI, 502);
			}
			return $result;
		}
		return $this->httpPost($url, $body, $apiKey, self::TIMEOUT);
	}

	private function httpPost(string $url, string $body, string $apiKey, int $timeout): array {
		if (function_exists('curl_init')) {
			return $this->httpPostCurl($url, $body, $apiKey, $timeout);
		}
		if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
			throw new Exception('El servidor no puede llamar a Gemini (hace falta cURL o allow_url_fopen).', 502);
		}
		return $this->httpPostStream($url, $body, $apiKey, $timeout);
	}

	private function httpPostCurl(string $url, string $body, string $apiKey, int $timeout): array {
		$ch = curl_init($url);
		if ($ch === false) {
			throw new Exception(self::MSG_GEMINI, 502);
		}
		curl_setopt_array($ch, array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => array(
				'Content-Type: application/json',
				'Accept: application/json',
				'x-goog-api-key: ' . $apiKey,
			),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
		));
		$raw = curl_exec($ch);
		$errno = curl_errno($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		if ($errno === CURLE_OPERATION_TIMEDOUT) {
			return array('status' => 0, 'body' => '', 'timeout' => true);
		}
		if ($raw === false) {
			return array('status' => 0, 'body' => '', 'timeout' => false);
		}
		return array('status' => $status, 'body' => (string) $raw, 'timeout' => false);
	}

	private function httpPostStream(string $url, string $body, string $apiKey, int $timeout): array {
		$context = stream_context_create(array(
			'http' => array(
				'method' => 'POST',
				'header' => "Content-Type: application/json\r\nAccept: application/json\r\nx-goog-api-key: {$apiKey}\r\n",
				'content' => $body,
				'timeout' => $timeout,
				'ignore_errors' => true,
				'follow_location' => 0,
				'max_redirects' => 0,
			),
			'ssl' => array(
				'verify_peer' => true,
				'verify_peer_name' => true,
			),
		));
		$started = microtime(true);
		$raw = @file_get_contents($url, false, $context);
		$elapsed = microtime(true) - $started;
		if ($raw === false) {
			$last = error_get_last();
			$message = is_array($last) ? (string) ($last['message'] ?? '') : '';
			$timedOut = $elapsed >= ($timeout - 1)
				|| stripos($message, 'timed out') !== false
				|| stripos($message, 'timeout') !== false;
			return array('status' => 0, 'body' => '', 'timeout' => $timedOut);
		}
		$status = 0;
		if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $matches)) {
			$status = (int) $matches[1];
		}
		return array('status' => $status, 'body' => $raw, 'timeout' => false);
	}

	private static function candidateText(string $raw): string {
		$payload = json_decode($raw, true);
		if (!is_array($payload)) {
			throw new Exception(self::MSG_UNREADABLE, 422);
		}
		$parts = $payload['candidates'][0]['content']['parts'] ?? null;
		$text = '';
		if (is_array($parts)) {
			foreach ($parts as $part) {
				if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
					$text .= $part['text'];
				}
			}
		}
		if (trim($text) === '') {
			throw new Exception(self::MSG_UNREADABLE, 422);
		}
		return $text;
	}

	/** @param array<string, mixed> $data */
	private static function per100FromPayload(array $data): ?array {
		if (self::asBool($data['has_per_100g'] ?? null) === true) {
			return self::macroBlock(array(
				'kcal' => $data['kcal_100g'] ?? null,
				'protein' => $data['protein_100g'] ?? null,
				'carbs' => $data['carbs_100g'] ?? null,
				'fat' => $data['fat_100g'] ?? null,
			));
		}
		if (isset($data['per_100g']) && is_array($data['per_100g'])) {
			return self::macroBlock($data['per_100g']);
		}
		if (array_key_exists('kcal_per_100g', $data)) {
			return self::macroBlock(array(
				'kcal' => $data['kcal_per_100g'],
				'protein' => $data['protein_per_100g'] ?? null,
				'carbs' => $data['carbs_per_100g'] ?? null,
				'fat' => $data['fat_per_100g'] ?? null,
			));
		}
		return null;
	}

	/** @param array<string, mixed> $data */
	private static function perServingFromPayload(array $data): ?array {
		$flag = self::asBool($data['has_per_serving'] ?? null);
		if ($flag === true) {
			return self::macroBlock(array(
				'kcal' => $data['kcal_serving'] ?? null,
				'protein' => $data['protein_serving'] ?? null,
				'carbs' => $data['carbs_serving'] ?? null,
				'fat' => $data['fat_serving'] ?? null,
			));
		}
		if ($flag === false) {
			return null;
		}
		if (isset($data['per_serving']) && is_array($data['per_serving'])) {
			return self::macroBlock($data['per_serving']);
		}
		return null;
	}

	/** @param array<string, mixed> $block */
	private static function macroBlock($block): ?array {
		if (!is_array($block)) {
			return null;
		}
		$kcal = self::nutrient($block['kcal'] ?? null, 1000);
		$protein = self::nutrient($block['protein'] ?? null, 100);
		$carbs = self::nutrient($block['carbs'] ?? null, 100);
		$fat = self::nutrient($block['fat'] ?? null, 100);
		if ($kcal === null || $protein === null || $carbs === null || $fat === null) {
			return null;
		}
		return array(
			'kcal' => $kcal,
			'protein' => $protein,
			'carbs' => $carbs,
			'fat' => $fat,
		);
	}

	private static function nutrient($value, float $max): ?float {
		if (is_string($value)) {
			$value = str_replace(',', '.', trim($value));
			if ($value === '') {
				return null;
			}
		}
		if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
			return null;
		}
		$number = (float) $value;
		if (!is_finite($number) || $number < 0 || $number > $max) {
			return null;
		}
		return round($number, 2);
	}

	private static function servingOrNull($value): ?float {
		$number = self::nutrient($value, 5000);
		if ($number === null || $number <= 0) {
			return null;
		}
		return $number;
	}

	private static function cleanName($value): ?string {
		if (!is_string($value)) {
			return null;
		}
		$name = strip_tags($value);
		$name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
		$name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
		if ($name === '') {
			return null;
		}
		if (mb_strlen($name) > 255) {
			$name = mb_substr($name, 0, 255);
		}
		return $name;
	}

	private static function asBool($value): ?bool {
		if (is_bool($value)) {
			return $value;
		}
		if ($value === 1 || $value === '1' || $value === 'true') {
			return true;
		}
		if ($value === 0 || $value === '0' || $value === 'false') {
			return false;
		}
		return null;
	}

	private static function apiKey(): string {
		$value = self::envString('GEMINI_API_KEY');
		if ($value === '' || $value === self::PLACEHOLDER_KEY || strlen($value) > 512 || preg_match("/[\r\n\0]/", $value)) {
			return '';
		}
		return $value;
	}

	private static function model(): string {
		$model = self::envString('GEMINI_MODEL');
		if ($model === '') {
			$model = self::DEFAULT_MODEL;
		}
		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/', $model)) {
			throw new Exception('GEMINI_MODEL no es un nombre de modelo válido.', 422);
		}
		return $model;
	}

	private static function envString(string $name): string {
		if (array_key_exists($name, $_ENV)) {
			return trim((string) $_ENV[$name]);
		}
		if (array_key_exists($name, $_SERVER)) {
			return trim((string) $_SERVER[$name]);
		}
		$value = getenv($name);
		return $value === false ? '' : trim($value);
	}

	/** @return array<string, mixed> */
	private static function responseSchema(): array {
		$number = array('type' => 'NUMBER');
		$boolean = array('type' => 'BOOLEAN');
		return array(
			'type' => 'OBJECT',
			'properties' => array(
				'readable' => $boolean,
				'name' => array('type' => 'STRING'),
				'serving_grams' => $number,
				'has_per_100g' => $boolean,
				'kcal_100g' => $number,
				'protein_100g' => $number,
				'carbs_100g' => $number,
				'fat_100g' => $number,
				'has_per_serving' => $boolean,
				'kcal_serving' => $number,
				'protein_serving' => $number,
				'carbs_serving' => $number,
				'fat_serving' => $number,
			),
			'required' => array(
				'readable',
				'name',
				'serving_grams',
				'has_per_100g',
				'kcal_100g',
				'protein_100g',
				'carbs_100g',
				'fat_100g',
				'has_per_serving',
				'kcal_serving',
				'protein_serving',
				'carbs_serving',
				'fat_serving',
			),
		);
	}

	/** @param array<string, mixed> $file */
	private static function imageFromUpload(array $file): array {
		$error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
		if ($error !== UPLOAD_ERR_OK) {
			throw new Exception('No se pudo leer la foto.', 400);
		}
		$tmp = (string) ($file['tmp_name'] ?? '');
		if ($tmp === '' || !is_uploaded_file($tmp)) {
			throw new Exception('No se pudo leer la foto.', 400);
		}
		$bytes = file_get_contents($tmp);
		if ($bytes === false) {
			throw new Exception('No se pudo leer la foto.', 400);
		}
		return self::normalize($bytes, isset($file['type']) ? (string) $file['type'] : null);
	}

	private static function logSnippet(string $body): string {
		$body = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '"[redacted]"', $body) ?? '';
		$body = preg_replace('/[A-Za-z0-9_\-]{12,}/', '[redacted]', $body) ?? '';
		return substr($body, 0, 180);
	}
}
