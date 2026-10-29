<?php

use Zediz\Macrofit\models\User;
use Zediz\Macrofit\shared\Request;

try {
	$body = Request::json();
	$email = strtolower(trim((string) ($body['email'] ?? '')));
	$password = (string) ($body['password'] ?? '');
	if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
		throw new Exception('Correo o contraseña incorrectos', 401);
	}
	$users = new User();
	$row = $users->findRawByEmail($email);
	if (!$row || !password_verify($password, $row['password_hash'])) {
		throw new Exception('Correo o contraseña incorrectos', 401);
	}
	$user = User::present($row);
	$token = JWT::generateJwt(array(
		'id' => $user['id'],
		'email' => $user['email'],
		'name' => $user['name'],
	));
	HTTP::_200(array('token' => $token, 'user' => $user));
} catch (Exception $e) {
	Utils::sendError($e->getMessage(), $e);
}
