<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/core/http.php';

// Primero los manejadores de errores: así hasta un .env faltante responde JSON
HTTP::registerErrorHandlers();

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

require_once __DIR__ . '/src/core/Utils.php';
require_once __DIR__ . '/src/core/Database.php';
require_once __DIR__ . '/src/core/JWT.php';
require_once __DIR__ . '/src/core/auth.php';
require_once __DIR__ . '/router.php';

// CORS: '*' por defecto; opcional CORS_ALLOWED_ORIGINS="https://app.com,https://admin.app.com"
$allowedOrigins = array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*'));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', $allowedOrigins, true)) {
	header("Access-Control-Allow-Origin: *");
} else if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
	header("Access-Control-Allow-Origin: $origin");
	header("Vary: Origin");
}
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
header("Access-Control-Expose-Headers: X-Debug-Warnings");
header("Access-Control-Max-Age: 3600");

// Headers de seguridad
header("Content-Type: application/json; charset=UTF-8");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: no-referrer");
header_remove("X-Powered-By");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	exit(0);
}

// ===================== RUTAS =====================
// Las rutas requieren JWT por defecto. Usa ['public' => true] para rutas sin token.
// Parámetros dinámicos con $: llegan al controlador como variables ($user_id).
//
// examples
// POST pública: post('/user/login', 'src/controllers/user/login.php', ['public' => true]);
// GET protegida: get('/user/$user_id/get_user', 'src/controllers/user/get_user.php');
// Otros archivos de rutas: require_once __DIR__ . '/routes_admin.php';

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Mexico_City');

post('/auth/register', 'src/controllers/auth/register.php', ['public' => true]);
post('/auth/login', 'src/controllers/auth/login.php', ['public' => true]);

get('/profile', 'src/controllers/profile/get_profile.php');
put('/profile', 'src/controllers/profile/update_profile.php');
post('/profile/onboarding', 'src/controllers/profile/onboarding.php');
put('/profile/macros', 'src/controllers/profile/update_macros.php');

get('/foods/search', 'src/controllers/food/search.php');
get('/foods/barcode/$barcode', 'src/controllers/food/barcode.php');
get('/foods/$food_id', 'src/controllers/food/get.php');
post('/foods', 'src/controllers/food/create.php');

get('/recipes', 'src/controllers/recipe/list.php');
post('/recipes', 'src/controllers/recipe/create.php');
get('/recipes/$recipe_id', 'src/controllers/recipe/get.php');
put('/recipes/$recipe_id', 'src/controllers/recipe/update.php');
delete('/recipes/$recipe_id', 'src/controllers/recipe/delete.php');

get('/logs/summary', 'src/controllers/log/summary.php');
get('/logs', 'src/controllers/log/list.php');
post('/logs', 'src/controllers/log/create.php');
delete('/logs/$log_id', 'src/controllers/log/delete.php');

// Debe ser SIEMPRE la última línea
Router::dispatch();
