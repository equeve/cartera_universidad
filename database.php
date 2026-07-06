<?php
// ============================================================
// config/database.php - Configuración de base de datos
// ============================================================

define('DB_HOST', 'localhost');
define('DB_PORT', '5433');
define('DB_NAME', 'cartera_universidad');
define('DB_USER', 'postgres');
define('DB_PASS', '12345');
define('DB_SCHEMA', 'public');

// Configuración de la aplicación
define('APP_NAME', 'SisCartera - Universidad');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/cartera_universidad');
define('APP_TIMEZONE', 'America/Bogota');
define('APP_LOCALE', 'es_CO');

// SMMLV Colombia vigente
define('SMMLV_VIGENTE', 1423500); // 2025
define('AUXILIO_TRANSPORTE', 200000); // 2025

// Configuración de sesión
define('SESSION_TIMEOUT', 3600); // 1 hora
define('SESSION_NAME', 'cartera_univ_sess');

// Configuración de paginación
define('REGISTROS_POR_PAGINA', 20);

date_default_timezone_set(APP_TIMEZONE);

class Database {
    private static $instance = null;
    private $connection = null;

    private function __construct() {
        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=UTF8'",
            DB_HOST, DB_PORT, DB_NAME
        );
        
        try {
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $this->connection->exec("SET search_path TO " . DB_SCHEMA);
            $this->connection->exec("SET datestyle TO 'ISO, DMY'");
        } catch (PDOException $e) {
            error_log("DB Connection Error: " . $e->getMessage());
            throw new Exception("Error de conexión a la base de datos");
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->connection;
    }

    public function query(string $sql, array $params = []): PDOStatement {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    public function fetchValue(string $sql, array $params = []) {
        $result = $this->query($sql, $params)->fetch(PDO::FETCH_NUM);
        return $result ? $result[0] : null;
    }

    public function lastInsertId(string $sequence = null): string {
        return $this->connection->lastInsertId($sequence);
    }

    public function beginTransaction(): void {
        $this->connection->beginTransaction();
    }

    public function commit(): void {
        $this->connection->commit();
    }

    public function rollback(): void {
        $this->connection->rollBack();
    }
}
