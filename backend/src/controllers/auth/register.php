<?php

use Zediz\Macrofit\models\User;
use Zediz\Macrofit\shared\Request;

try {
	$body = Request::json();
	$name = trim((string) ($body['name'] ?? ''));
	$email = strtolower(trim((string) ($body['email'] ?? '')));
	$password = (string) ($body['password'] ?? '');

	if ($name === '' || mb_strlen($name) > 120) {
		throw new Exception('El nombre es obligatorio (máximo 120 caracteres)', 422);
	}
	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		throw new Exception('Correo inválido', 422);
	}
	if (strlen($password) < 8) {
		throw new Exception('La contraseña debe tener al menos 8 caracteres', 422);
	}

	$users = new User();
	if ($users->findRawByEmail($email)) {
		throw new Exception('Ese correo ya está registrado', 409);
	}
	$user = $users->create($email, password_hash($password, PASSWORD_DEFAULT), $name);
	$token = JWT::generateJwt(array(
		'id' => $user['id'],
		'email' => $user['email'],
		'name' => $user['name'],
	));
	HTTP::_201(array('token' => $token, 'user' => $user));
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
