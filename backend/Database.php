<?php

class Database {
    private static ?PDO $connection = null;
    
    public static function getConnection(): PDO {
        if (self::$connection === null) {
            // Konfiguration außerhalb des Web-Verzeichnisses laden
            $configPath = __DIR__ . '/../config/database.php';

            if (!file_exists($configPath)) {
                throw new Exception("Konfigurationsdatei nicht gefunden: " . $configPath);
            }

            $config = require_once $configPath;

            $dsn = "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset={$config['DB_CHARSET']}";
            
            try {
                self::$connection = new PDO(
                    $dsn,
                    $config['DB_USER'],
                    $config['DB_PASS'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    ]
                );
                
                // Tabellen erstellen falls sie nicht existieren
                self::createTables();
                
            } catch (PDOException $e) {
                error_log("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
                throw new Exception("Datenbankverbindung fehlgeschlagen");
            }
        }
        
        return self::$connection;
    }
    
    private static function createTables(): void {
        $pdo = self::$connection;
        
        // Games Tabelle
        $pdo->exec("CREATE TABLE IF NOT EXISTS games (
            id VARCHAR(10) PRIMARY KEY,
            state TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        
        // Players Tabelle
        $pdo->exec("CREATE TABLE IF NOT EXISTS players (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_id VARCHAR(10) NOT NULL,
            player_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            cards TEXT,
            score INT DEFAULT 0,
            FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
            UNIQUE KEY unique_player_game (game_id, player_id)
        )");
    }
}
?>
