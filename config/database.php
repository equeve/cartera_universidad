<?php
// ============================================================
// config/database.php - Configuración de base de datos
// ============================================================

// Detecta las variables de entorno de Render; si no existen, usa tus datos locales por defecto
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '5432'); // En Render/Neon se usará el puerto que pases por variable (usualmente 5432)
define('DB_NAME', getenv('DB_NAME') ?: 'cartera_universidad');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASSWORD') ?: '12345'); // Nota: cambiamos a DB_PASSWORD para que coincida con Render
define('DB_SCHEMA', getenv('DB_SCHEMA') ?: 'public');

// Configuración de la aplicación
define('APP_NAME', 'SisCartera - Universidad');
define('APP_VERSION', '1.0.0');

// Si estás en Render, usa la URL de Render, si no, usa localhost
define('APP_URL', getenv('RENDER_EXTERNAL_URL') ?: 'http://localhost/cartera_universidad');
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
        // Neon.tech y Render requieren SSL para conexiones seguras en producción
        // Agregamos condicionalmente los parámetros SSL si estamos en producción
        $sslMode = getenv('DB_HOST') ? ";sslmode=require" : "";

        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s%s;options='--client_encoding=UTF8'",
            DB_HOST, DB_PORT, DB_NAME, $sslMode
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
            // En producción devolvemos un mensaje genérico, pero dejamos el log interno
            throw new Exception("Error de conexión a la base de datos: " . $e->getMessage());
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
