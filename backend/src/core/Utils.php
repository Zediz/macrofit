<?php
include_once __DIR__ . "/http.php";

use PHPMailer\PHPMailer\PHPMailer;

class Utils {
	public static function checkLogin() {
		if (!isset($_SESSION['user'])) {
			header('Location: /login');
			exit();
		}
	}

	public static function httpResponse($res) {
		if ($res["success"]) {
			if (isset($res['res'])) {
				HTTP::_200($res['res']);
			} else {
				HTTP::_204();
			}
		} else {
			HTTP::_500(isset($res["error"]) ? $res["error"] : 'Error interno del servidor');
		}
	}

	// Respeta códigos 4xx de la excepción (400, 401, 404, 422...); el resto responde 500
	static function manageException($e) {
		HTTP::fromException($e);
	}

	// Usado en los catch de los controladores: Utils::sendError($e->getMessage(), $e);
	public static function sendError($errorMsg, $e = null) {
		if ($e) {
			HTTP::fromException($e, $errorMsg);
		} else {
			HTTP::_500($errorMsg);
		}
	}

	static function debug($data, $texto = '') {
		if ($texto != '') {
			error_log($texto);
		}
		error_log(print_r($data, true));
	}

	public static function validateRes($res = null) {
		try {
			if ($res !== null && !empty($res)) {
				return array('success' => true, 'res' => $res);
			} else {
				return array('success' => true);
			}
		} catch (Exception $e) {
			throw new Exception($e->getMessage(), 500);
			return array('success' => false, "error" => $e->getMessage());
		}
	}

	public static function sendEmail($toEmails, $subject, $body) {
		$mail = new PHPMailer(true);
		try {
			$mail->isSMTP();
			$mail->Host = $_ENV['HOST'];
			$mail->SMTPAuth = true;
			$mail->Username = $_ENV['EMAIL'];
			$mail->Password = $_ENV['EMAIL_PASSWORD'];
			$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
			$mail->Port = $_ENV['PORT'];
			$mail->setFrom($_ENV['EMAIL'], $_ENV['SENDER_NAME']);
			$emails = explode(',', $toEmails);
			foreach ($emails as $email) {
				$mail->addAddress(trim($email), '');
			}
			$mail->isHTML(true);
			$mail->Subject = $subject;
			$mail->Body = $body;
			$mail->send();
		} catch (Exception $e) {
			echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
		}
	}

	public static function decodeJwt($jwt) {
		list($headerEncoded, $payloadEncoded, $signatureEncoded) = explode('.', $jwt);
		$payload = json_decode(base64_decode($payloadEncoded), true);
		return $payload;
	}
}
