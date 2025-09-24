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

        // Normalisierte neue Struktur (präfix g_)
        $pdo->exec("CREATE TABLE IF NOT EXISTS g_games (
            id VARCHAR(10) PRIMARY KEY,
            phase VARCHAR(30) DEFAULT 'waiting',
            storyteller_player_id INT NULL,
            current_round INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase (phase)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS g_players (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_id VARCHAR(10) NOT NULL,
            seat INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            score INT DEFAULT 0,
            active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_game_seat (game_id, seat),
            UNIQUE KEY uq_game_name (game_id, name),
            FOREIGN KEY (game_id) REFERENCES g_games(id) ON DELETE CASCADE,
            INDEX idx_game (game_id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS g_rounds (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_id VARCHAR(10) NOT NULL,
            round_number INT NOT NULL,
            storyteller_player_id INT NOT NULL,
            hint TEXT NULL,
            storyteller_card_id INT NULL,
            phase VARCHAR(30) DEFAULT 'storytelling',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            closed_at TIMESTAMP NULL,
            UNIQUE KEY uq_game_round (game_id, round_number),
            FOREIGN KEY (game_id) REFERENCES g_games(id) ON DELETE CASCADE,
            INDEX idx_game_round (game_id, round_number)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS g_player_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_player_id INT NOT NULL,
            card_id INT NOT NULL,
            in_hand TINYINT(1) DEFAULT 1,
            used_in_round INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (game_player_id) REFERENCES g_players(id) ON DELETE CASCADE,
            INDEX idx_player_inhand (game_player_id, in_hand)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS g_round_submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            round_id INT NOT NULL,
            game_player_id INT NOT NULL,
            card_id INT NOT NULL,
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_round_player (round_id, game_player_id),
            FOREIGN KEY (round_id) REFERENCES g_rounds(id) ON DELETE CASCADE,
            FOREIGN KEY (game_player_id) REFERENCES g_players(id) ON DELETE CASCADE,
            INDEX idx_round (round_id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS g_round_votes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            round_id INT NOT NULL,
            voter_player_id INT NOT NULL,
            card_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_round_voter (round_id, voter_player_id),
            FOREIGN KEY (round_id) REFERENCES g_rounds(id) ON DELETE CASCADE,
            FOREIGN KEY (voter_player_id) REFERENCES g_players(id) ON DELETE CASCADE,
            INDEX idx_round (round_id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS g_round_scores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            round_id INT NOT NULL,
            game_player_id INT NOT NULL,
            delta_score INT NOT NULL,
            total_after INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (round_id) REFERENCES g_rounds(id) ON DELETE CASCADE,
            FOREIGN KEY (game_player_id) REFERENCES g_players(id) ON DELETE CASCADE,
            INDEX idx_round (round_id),
            INDEX idx_player (game_player_id)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS g_deck_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_id VARCHAR(10) NOT NULL,
            position INT NOT NULL,
            card_id INT NOT NULL,
            consumed TINYINT(1) DEFAULT 0,
            UNIQUE KEY uq_game_position (game_id, position),
            FOREIGN KEY (game_id) REFERENCES g_games(id) ON DELETE CASCADE,
            INDEX idx_game (game_id),
            INDEX idx_game_consumed (game_id, consumed)
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
