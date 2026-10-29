<?php
class Database {
	private static $instance = null;
	private static $sharedConnection = null;
	private $connection;
	private $host;
	private $username;
	private $password;
	private $db_name;

	public function __construct() {
		$this->host = isset($_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : 'mysql';
		$this->db_name = $_ENV['DB_NAME'];
		$this->username = $_ENV['DB_USER'];
		$this->password = $_ENV['DB_PASSWORD'];

		if (self::$sharedConnection === null) {
			try {
				self::$sharedConnection = new PDO(
					"mysql:host=$this->host;dbname=$this->db_name;charset=utf8mb4",
					$this->username,
					$this->password,
					array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
				);
			} catch (PDOException $e) {
				error_log('Database connection failed: ' . $e->getMessage());
				// Se lanza en vez de die(): el manejador global responde JSON (con detalle solo en debug)
				throw new RuntimeException('No se pudo conectar a la base de datos.', 500, $e);
			}
		}
		$this->connection = self::$sharedConnection;
	}

	public static function getInstance() {
		if (!self::$instance) {
			self::$instance = new Database();
		}
		return self::$instance;
	}

	public function getConnection() {
		return $this->connection;
	}
}
