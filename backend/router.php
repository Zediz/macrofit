<?php

class Router {
	private static $routes = [];
	private static $reservedParams = ['this', 'GLOBALS', 'params'];

	public static function add($method, $route, $handler, $options = []) {
		$route = '/' . trim($route, '/');
		$params = [];
		$segments = [];

		foreach (explode('/', trim($route, '/')) as $segment) {
			if (str_starts_with($segment, '$')) {
				$name = substr($segment, 1);
				// Sin "_" inicial: evita pisar superglobales ($_SERVER, $_ENV...)
				if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) || in_array($name, self::$reservedParams, true)) {
					throw new InvalidArgumentException("Parámetro inválido '$segment' en la ruta $route");
				}
				$params[] = $name;
				$segments[] = '([^/]+)';
			} else {
				$segments[] = preg_quote($segment, '#');
			}
		}

		self::$routes[] = array(
			"method" => strtoupper($method),
			"route" => $route,
			"regex" => '#^/' . implode('/', $segments) . '$#',
			"params" => $params,
			"handler" => $handler,
			"public" => !empty($options['public']),
		);
	}

	public static function dispatch() {
		$method = $_SERVER['REQUEST_METHOD'] === 'HEAD' ? 'GET' : $_SERVER['REQUEST_METHOD'];
		$path = self::currentPath();
		$allowed = [];

		foreach (self::$routes as $route) {
			if (!preg_match($route['regex'], $path, $matches)) {
				continue;
			}
			if ($route['method'] !== 'ANY' && $route['method'] !== $method) {
				$allowed[] = $route['method'];
				continue;
			}

			$values = array_map('rawurldecode', array_slice($matches, 1));
			foreach ($values as $value) {
				if (str_contains($value, "\0")) {
					HTTP::_400('Parámetro inválido');
					return;
				}
			}
			$params = array_combine($route['params'], $values);

			if (!$route['public'] && !JWT::excludedRoutes($route['route'])) {
				Auth::check();
			}

			self::run($route['handler'], $params);
			return;
		}

		if ($allowed) {
			header('Allow: ' . implode(', ', array_unique($allowed)));
			HTTP::_405('Método no permitido');
			return;
		}

		HTTP::_404('Ruta no encontrada');
	}

	// Path sin query string; soporta que el proyecto viva en una subcarpeta del hosting
	private static function currentPath() {
		$path = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
		$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
		if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
			$path = substr($path, strlen($base));
		}
		$path = preg_replace('#/+#', '/', $path);
		return '/' . trim($path, '/');
	}

	private static function run($handler, $params) {
		if ($handler instanceof Closure) {
			$handler(...array_values($params));
			return;
		}

		$__file = self::resolve($handler);
		// Scope aislado: el controlador solo ve sus parámetros de URL (y $params)
		(static function () use ($__file, $params) {
			extract($params, EXTR_SKIP);
			require $__file;
		})();
	}

	// Solo se pueden incluir archivos .php que existan dentro de src/
	private static function resolve($file) {
		if (!str_ends_with($file, '.php')) {
			$file .= '.php';
		}
		$file = ltrim($file, '/');
		$src = realpath(__DIR__ . '/src');

		foreach (array(__DIR__ . "/$file", __DIR__ . "/src/$file") as $candidate) {
			$real = realpath($candidate);
			if ($real !== false && is_file($real) && str_starts_with($real, $src . DIRECTORY_SEPARATOR)) {
				return $real;
			}
		}
		throw new RuntimeException("Controlador no encontrado: $file");
	}
}

function get($route, $handler, $options = []) {
	Router::add('GET', $route, $handler, $options);
}

function post($route, $handler, $options = []) {
	Router::add('POST', $route, $handler, $options);
}

function put($route, $handler, $options = []) {
	Router::add('PUT', $route, $handler, $options);
}

function patch($route, $handler, $options = []) {
	Router::add('PATCH', $route, $handler, $options);
}

function delete($route, $handler, $options = []) {
	Router::add('DELETE', $route, $handler, $options);
}

function any($route, $handler, $options = []) {
	Router::add('ANY', $route, $handler, $options);
}
