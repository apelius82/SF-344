<?php
// assets/lib/Database.php
// Keskitetty tietokantapalvelu (Singleton-pattern)

declare(strict_types=1);

class Database
{
    private static ? PDO $instance = null;
    private static array $config = [];

    /**
     * Aseta tietokanta-asetukset
     */
    public static function setConfig(array $dbConfig): void
    {
        self::$config = $dbConfig;
    }

    /**
     * Hae PDO-instanssi (singleton)
     *
     * Tekee defensiivisen ping-kyselyn ja luo uuden yhteyden automaattisesti
     * jos MySQL on sulkenut vanhan yhteyden (wait_timeout / error 2006/2013).
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
            return self::$instance;
        }

        // Defensiivinen ping: jos yhteys on katkennut, luo uusi automaattisesti.
        try {
            self::$instance->query('SELECT 1');
        } catch (PDOException $e) {
            if (self::isGoneAwayError($e)) {
                error_log('Database: connection lost (' . $e->getMessage() . '), reconnecting');
                self::$instance = null;
                self::$instance = self::createConnection();
            } else {
                throw $e;
            }
        }

        return self::$instance;
    }

    /**
     * Tarkistaa onko poikkeus MySQL "gone away" / "lost connection" -virhe.
     */
    public static function isGoneAwayError(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return (strpos($msg, '2006') !== false)
            || (strpos($msg, '2013') !== false)
            || (stripos($msg, 'server has gone away') !== false)
            || (stripos($msg, 'lost connection') !== false);
    }

    /**
     * Pakottaa uuden tietokantayhteyden.
     * Käytä ennen pitkäkestoisten operaatioiden jälkeen suoritettavia workereita.
     */
    public static function reconnect(): PDO
    {
        self::$instance = null;
        return self::getInstance();
    }

    /**
     * Luo uusi PDO-yhteys
     */
    private static function createConnection(): PDO
    {
        if (empty(self::$config)) {
            throw new RuntimeException('Database config not set. Call Database::setConfig() first.');
        }

        $host    = self::$config['host'] ?? 'localhost';
        $name    = self::$config['name'] ??  '';
        $user    = self::$config['user'] ?? '';
        $pass    = self::$config['pass'] ??  '';
        $charset = self::$config['charset'] ?? 'utf8mb4';

        if (empty($name) || empty($user)) {
            throw new RuntimeException('Database name and user are required.');
        }

        $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
        ];

        try {
            return new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            error_log('Database connection error: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed.');
        }
    }

    /**
     * Sulje yhteys (valinnainen, PHP sulkee automaattisesti)
     */
    public static function close(): void
    {
        self::$instance = null;
    }

    /**
     * Aloita transaktio
     */
    public static function beginTransaction(): bool
    {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Vahvista transaktio
     */
    public static function commit(): bool
    {
        return self::getInstance()->commit();
    }

    /**
     * Peru transaktio
     */
    public static function rollBack(): bool
    {
        return self::getInstance()->rollBack();
    }

    /**
     * Onko transaktio aktiivinen
     */
    public static function inTransaction(): bool
    {
        return self::getInstance()->inTransaction();
    }

    /**
     * Valmistele kysely
     */
    public static function prepare(string $sql): PDOStatement
    {
        return self::getInstance()->prepare($sql);
    }

    /**
     * Suorita kysely suoraan (SELECT)
     */
    public static function query(string $sql): PDOStatement
    {
        return self::getInstance()->query($sql);
    }

    /**
     * Hae viimeisin lisätty ID
     */
    public static function lastInsertId(): string
    {
        return self::getInstance()->lastInsertId();
    }

    /**
     * Hae yksi rivi
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Hae kaikki rivit
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Suorita INSERT/UPDATE/DELETE
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}