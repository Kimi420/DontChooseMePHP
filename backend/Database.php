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
        
        // Games Tabelle - erweitert für vollständigen Spielstatus
        $pdo->exec("CREATE TABLE IF NOT EXISTS games (
            id VARCHAR(10) PRIMARY KEY,
            state LONGTEXT NOT NULL,
            phase VARCHAR(50) DEFAULT 'waiting',
            player_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_created_at (created_at),
            INDEX idx_phase (phase)
        )");
        
        // Players Tabelle - für zusätzliche Spielerinformationen
        $pdo->exec("CREATE TABLE IF NOT EXISTS players (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_id VARCHAR(10) NOT NULL,
            player_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            cards TEXT,
            score INT DEFAULT 0,
            is_storyteller BOOLEAN DEFAULT FALSE,
            has_selected_card BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
            UNIQUE KEY unique_player_game (game_id, player_id),
            INDEX idx_game_id (game_id)
        )");

        // Game_actions Tabelle - für Audit-Log der Spielaktionen
        $pdo->exec("CREATE TABLE IF NOT EXISTS game_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_id VARCHAR(10) NOT NULL,
            player_name VARCHAR(100),
            action VARCHAR(50) NOT NULL,
            action_data TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
            INDEX idx_game_id_created (game_id, created_at)
        )");
    }

    /**
     * Bereinigt alte Spiele (älter als 24 Stunden)
     */
    public static function cleanupOldGames(): void {
        try {
            $pdo = self::getConnection();
            $stmt = $pdo->prepare("DELETE FROM games WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $stmt->execute();
        } catch (Exception $e) {
            error_log('Fehler bei der Bereinigung alter Spiele: ' . $e->getMessage());
        }
    }

    /**
     * Protokolliert eine Spielaktion
     */
    public static function logGameAction(string $gameId, string $action, ?string $playerName = null, ?array $actionData = null): void {
        try {
            $pdo = self::getConnection();
            $stmt = $pdo->prepare("INSERT INTO game_actions (game_id, player_name, action, action_data) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $gameId,
                $playerName,
                $action,
                $actionData ? json_encode($actionData) : null
            ]);
        } catch (Exception $e) {
            error_log('Fehler beim Protokollieren der Spielaktion: ' . $e->getMessage());
        }
    }
}
?>
