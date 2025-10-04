<?php
/**
 * Voll normalisierte Game Service Implementierung.
 * Liest und schreibt ausschließlich in die g_* Tabellen.
 * Stellt eine API kompatible State-Struktur zur Verfügung wie bisher.
 */

require_once __DIR__ . '/Database.php';

class NormalizedGameService {
    private PDO $db;
    private int $HAND_SIZE = 6;

    public function __construct() {
        $this->db = Database::getConnection();
        $this->ensureWinsColumn();
        $this->ensureRerollTable();
    }

    // deckId ist optional, Standardwert = null
    public function createGame(string $playerName, ?int $deckId = null): array {
        $gameId = $this->generateGameId();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO g_games (id, phase, current_round, deck_id) VALUES (?, 'waiting', 0, ?)");
            $stmt->execute([$gameId, $deckId]);
            // Ersten Spieler anlegen (seat=1)
            $stmt = $this->db->prepare("INSERT INTO g_players (game_id, seat, name) VALUES (?, ?, ?)");
            $stmt->execute([$gameId, 1, $playerName]);
            $this->db->commit();
            return [
                'success' => true,
                'gameId' => $gameId,
                'player' => ['id' => 1, 'name' => $playerName]
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('createGame error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Fehler beim Erstellen des Spiels'];
        }
    }

    public function joinGame(string $gameId, string $playerName): array {
        // Prüfen ob Spiel existiert und Phase holen
        $stmt = $this->db->prepare("SELECT phase FROM g_games WHERE id=?");
        $stmt->execute([$gameId]);
        $rowGame = $stmt->fetch();
        if (!$rowGame) {
            return ['success' => false, 'message' => 'Spiel nicht gefunden'];
        }
        $gamePhase = $rowGame['phase'];
        // Name schon vergeben?
        $stmt = $this->db->prepare("SELECT 1 FROM g_players WHERE game_id=? AND name=?");
        $stmt->execute([$gameId, $playerName]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Name bereits vergeben'];
        }
        // Nächsten seat bestimmen
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(seat),0)+1 AS nextSeat FROM g_players WHERE game_id=?");
        $stmt->execute([$gameId]);
        $nextSeat = (int)$stmt->fetch()['nextSeat'];
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO g_players (game_id, seat, name) VALUES (?,?,?)");
            $stmt->execute([$gameId, $nextSeat, $playerName]);
            $newPlayerDbId = (int)$this->db->lastInsertId();
            // Falls Spiel schon läuft -> sofort Hand auffüllen
            if ($gamePhase !== 'waiting') {
                // Spieler direkt Karten ziehen (falls Deck existiert)
                $this->drawCards($gameId, $newPlayerDbId, $this->HAND_SIZE);
            }
            $this->db->commit();
            return [
                'success' => true,
                'gameId' => $gameId,
                'player' => ['id' => $nextSeat, 'name' => $playerName]
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('joinGame error: '. $e->getMessage());
            return ['success'=>false,'message'=>'Beitritt fehlgeschlagen'];
        }
    }

    // NEU: Spieler wieder ins Spiel einloggen ohne neuen Eintrag anzulegen
    public function rejoinGame(string $gameId, string $playerName): array {
        $state = $this->internalState($gameId);
        if (!$state) {
            return ['success'=>false,'message'=>'Spiel nicht gefunden'];
        }
        $player = $this->playerByName($gameId, $playerName);
        if (!$player) {
            return ['success'=>false,'message'=>'Spielername nicht im Spiel'];
        }
        $hand = $this->playerHand($player['db_id']);
        return [
            'success'=>true,
            'gameId'=>$gameId,
            'player'=>[
                'id'=>$player['seat'],
                'name'=>$player['name'],
                'score'=>(int)$player['score'],
                'isStoryteller'=>(bool)$player['isStoryteller']
            ],
            'cards'=>$hand,
            'phase'=>$state['phase']
        ];
    }

    public function startGame(string $gameId): array {
        // Bereits gestartet?
        $state = $this->internalState($gameId);
        if ($state && $state['phase'] !== 'waiting') {
            return ['success' => false, 'message' => 'Spiel läuft bereits'];
        }
        // Mindest-Spieler prüfen
        $players = $this->fetchPlayers($gameId);
        if (count($players) < 3) {
            return ['success' => false, 'message' => 'Mindestens 3 Spieler benötigt'];
        }
        // Deck gewählt?
        $stmtDeck = $this->db->prepare("SELECT deck_id FROM g_games WHERE id=?");
        $stmtDeck->execute([$gameId]);
        $rowD = $stmtDeck->fetch();
        if (!$rowD || !$rowD['deck_id']) {
            return ['success'=>false,'message'=>'Kein Deck ausgewählt'];
        }
        $this->db->beginTransaction();
        try {
            // Storyteller = seat 1 initial
            $storytellerSeat = 1;
            $storytellerId = $this->playerDbIdBySeat($gameId, $storytellerSeat);
            // Runde 1 anlegen
            $stmt = $this->db->prepare("INSERT INTO g_rounds (game_id, round_number, storyteller_player_id) VALUES (?,?,?)");
            $stmt->execute([$gameId, 1, $storytellerId]);
            // g_games updaten
            $stmt = $this->db->prepare("UPDATE g_games SET phase='storytelling', storyteller_player_id=?, current_round=1 WHERE id=?");
            $stmt->execute([$storytellerId, $gameId]);
            // Deck aufbauen & mischen
            $cardData = $this->loadCardData($gameId);
            if (empty($cardData)) {
                $this->db->rollBack();
                return ['success'=>false,'message'=>'Gewähltes Deck hat keine Karten'];
            }
            $ids = array_map(fn($c)=> (int)$c['id'], $cardData);
            shuffle($ids);
            $ins = $this->db->prepare("INSERT INTO g_deck_cards (game_id, position, card_id) VALUES (?,?,?)");
            $pos = 1;
            foreach ($ids as $cid) {
                $ins->execute([$gameId, $pos++, $cid]);
            }
            // Hände austeilen
            $this->dealHands($gameId);
            $this->db->commit();
            return ['success' => true];
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('startGame error: '.$e->getMessage());
            return ['success' => false, 'message' => 'Start fehlgeschlagen'];
        }
    }

    public function giveHint(string $gameId, string $playerName, int $cardId, string $hint): array {
        if (trim($hint) === '') return ['success' => false, 'message' => 'Leerer Hinweis'];
        $state = $this->internalState($gameId);
        if (!$state) return ['success'=>false,'message'=>'Spiel nicht gefunden'];
        if ($state['phase'] !== 'storytelling') return ['success'=>false,'message'=>'Falsche Phase'];
        $story = $state['storytellerName'];
        if ($playerName !== $story) return ['success'=>false,'message'=>'Nicht Erzähler'];
        // Karte validieren
        $dbId = $this->playerDbIdBySeat($gameId, $state['storytellerSeat']);
        if (!$this->cardInHand($dbId, $cardId)) return ['success'=>false,'message'=>'Karte nicht in Hand'];
        $this->db->beginTransaction();
        try {
            // Karte als benutzt markieren
            $this->consumeCard($dbId, $cardId, $state['round_id']);
            // Hint setzen
            $stmt = $this->db->prepare("UPDATE g_rounds SET hint=?, storyteller_card_id=?, phase='selectCards' WHERE id=?");
            $stmt->execute([$hint, $cardId, $state['round_id']]);
            $stmt = $this->db->prepare("UPDATE g_games SET phase='selectCards' WHERE id=?");
            $stmt->execute([$gameId]);
            $this->db->commit();
            return ['success'=>true];
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('giveHint error: '.$e->getMessage());
            return ['success'=>false,'message'=>'Fehler beim Hinweis'];
        }
    }

    public function chooseCard(string $gameId, string $playerName, int $cardId): array {
        $state = $this->internalState($gameId);
        if (!$state) return ['success'=>false,'message'=>'Spiel nicht gefunden'];
        if ($state['phase'] !== 'selectCards') return ['success'=>false,'message'=>'Nicht Auswahlphase'];
        if ($playerName === $state['storytellerName']) return ['success'=>false,'message'=>'Erzähler wählt nicht'];
        $player = $this->playerByName($gameId, $playerName);
        if (!$player) return ['success'=>false,'message'=>'Spieler nicht gefunden'];
        if (!$this->cardInHand($player['db_id'], $cardId)) return ['success'=>false,'message'=>'Karte nicht in Hand'];
        // Bereits gewählt?
        if ($this->submissionExists($state['round_id'], $player['db_id'])) return ['success'=>false,'message'=>'Schon gewählt'];
        $this->db->beginTransaction();
        try {
            $this->consumeCard($player['db_id'], $cardId, $state['round_id']);
            $stmt = $this->db->prepare("INSERT INTO g_round_submissions (round_id, game_player_id, card_id) VALUES (?,?,?)");
            $stmt->execute([$state['round_id'], $player['db_id'], $cardId]);
            // Prüfen ob alle (außer Erzähler) gewählt haben
            if ($this->allSubmissionsDone($gameId, $state)) {
                $stmt = $this->db->prepare("UPDATE g_rounds SET phase='voting' WHERE id=?");
                $stmt->execute([$state['round_id']]);
                $stmt = $this->db->prepare("UPDATE g_games SET phase='voting' WHERE id=?");
                $stmt->execute([$gameId]);
            }
            $this->db->commit();
            return ['success'=>true];
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('chooseCard error: '.$e->getMessage());
            return ['success'=>false,'message'=>'Fehler bei Kartenwahl'];
        }
    }

    public function vote(string $gameId, string $playerName, int $cardId): array {
        $state = $this->internalState($gameId);
        if (!$state) return ['success'=>false,'message'=>'Spiel nicht gefunden'];
        if ($state['phase'] === 'finished') return ['success'=>false,'message'=>'Spiel ist beendet'];
        if ($state['phase'] !== 'voting') return ['success'=>false,'message'=>'Nicht Abstimmphase'];
        if ($playerName === $state['storytellerName']) return ['success'=>false,'message'=>'Erzähler stimmt nicht ab'];
        $player = $this->playerByName($gameId, $playerName);
        if (!$player) return ['success'=>false,'message'=>'Spieler nicht gefunden'];
        if ($this->voteExists($state['round_id'], $player['db_id'])) return ['success'=>false,'message'=>'Schon abgestimmt'];
        // Karte muss Teil der Mixed Cards sein
        $mixed = $this->mixedCardsInternal($state);
        if (!in_array($cardId, $mixed, true)) return ['success'=>false,'message'=>'Ungültige Karte'];
        // NEU: Spieler darf nicht für eigene eingereichte Karte stimmen
        $ownerDbId = null;
        if ($cardId === $state['storyteller_card_id']) {
            // Storyteller (stimmt nicht ab) wäre Besitzer – nur zur Vollständigkeit
            $ownerDbId = $this->playerDbIdBySeat($gameId, $state['storytellerSeat']);
        } else {
            $stmtOwner = $this->db->prepare("SELECT game_player_id FROM g_round_submissions WHERE round_id=? AND card_id=? LIMIT 1");
            $stmtOwner->execute([$state['round_id'], $cardId]);
            $rowOwner = $stmtOwner->fetch();
            if ($rowOwner) {
                $ownerDbId = (int)$rowOwner['game_player_id'];
            }
        }
        if ($ownerDbId !== null && $ownerDbId === (int)$player['db_id']) {
            return ['success'=>false,'message'=>'Du kannst nicht für deine eigene Karte stimmen'];
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO g_round_votes (round_id, voter_player_id, card_id) VALUES (?,?,?)");
            $stmt->execute([$state['round_id'], $player['db_id'], $cardId]);
            if ($this->allVotesDone($gameId, $state)) {
                // Phase -> reveal
                $stmt = $this->db->prepare("UPDATE g_rounds SET phase='reveal', closed_at=NOW() WHERE id=?");
                $stmt->execute([$state['round_id']]);
                $stmt = $this->db->prepare("UPDATE g_games SET phase='reveal' WHERE id=?");
                $stmt->execute([$gameId]);
                // Punkte berechnen
                $this->scoreRound($state);
            }
            $this->db->commit();
            return ['success'=>true];
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('vote error: '.$e->getMessage());
            return ['success'=>false,'message'=>'Fehler bei Abstimmung'];
        }
    }

    public function nextRound(string $gameId): array {
        $state = $this->internalState($gameId);
        if (!$state) return ['success'=>false,'message'=>'Spiel nicht gefunden'];
        if ($state['phase'] === 'finished') return ['success'=>false,'message'=>'Spiel ist beendet'];
        if ($state['phase'] !== 'reveal') return ['success'=>false,'message'=>'Runde noch nicht abgeschlossen'];
        $this->db->beginTransaction();
        try {
            $nextRound = $state['round_number'] + 1;
            // Nächster Erzähler = nächster Sitz (wrap)
            $players = $this->fetchPlayers($gameId);
            $seatList = array_column($players, 'seat');
            sort($seatList);
            $idx = array_search($state['storytellerSeat'], $seatList, true);
            $nextSeat = $seatList[($idx + 1) % count($seatList)];
            $nextDbId = $this->playerDbIdBySeat($gameId, $nextSeat);
            $stmt = $this->db->prepare("INSERT INTO g_rounds (game_id, round_number, storyteller_player_id) VALUES (?,?,?)");
            $stmt->execute([$gameId, $nextRound, $nextDbId]);
            $stmt = $this->db->prepare("UPDATE g_games SET phase='storytelling', storyteller_player_id=?, current_round=? WHERE id=?");
            $stmt->execute([$nextDbId, $nextRound, $gameId]);
            // Hände auffüllen
            $this->replenishHands($gameId);
            $this->db->commit();
            return ['success'=>true];
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('nextRound error: '.$e->getMessage());
            return ['success'=>false,'message'=>'Fehler nächste Runde'];
        }
    }

    public function setDeck(string $gameId, string $playerName, int $deckId): array {
        // Nur in waiting-Phase erlaubt
        $state = $this->internalState($gameId);
        if (!$state) return ['success'=>false,'message'=>'Spiel nicht gefunden'];
        if ($state['phase'] !== 'waiting') return ['success'=>false,'message'=>'Deckwechsel nur vor Spielstart'];
        // Prüfen: Spieler ist Host (seat 1)
        $hostDbId = $this->playerDbIdBySeat($gameId, 1);
        $player = $this->playerByName($gameId, $playerName);
        if (!$player) return ['success'=>false,'message'=>'Spieler nicht gefunden'];
        if ((int)$player['db_id'] !== (int)$hostDbId) return ['success'=>false,'message'=>'Nur Host darf Deck wählen'];
        // Deck existiert?
        $stmt = $this->db->prepare("SELECT id FROM g_decks WHERE id=?");
        $stmt->execute([$deckId]);
        if (!$stmt->fetch()) return ['success'=>false,'message'=>'Deck nicht gefunden'];
        $stmt = $this->db->prepare("UPDATE g_games SET deck_id=? WHERE id=?");
        $stmt->execute([$deckId,$gameId]);
        return ['success'=>true];
    }

    public function resetMatch(string $gameId, string $playerName): array {
        // Nur Host (seat 1) darf resetten, nur wenn Phase finished oder waiting
        $state = $this->internalState($gameId);
        if (!$state) return ['success'=>false,'message'=>'Spiel nicht gefunden'];
        if ($state['phase'] !== 'finished' && $state['phase'] !== 'waiting') return ['success'=>false,'message'=>'Reset nur nach Spielende'];
        $hostDbId = $this->playerDbIdBySeat($gameId, 1);
        $player = $this->playerByName($gameId, $playerName);
        if (!$player) return ['success'=>false,'message'=>'Spieler nicht gefunden'];
        if ((int)$player['db_id'] !== (int)$hostDbId) return ['success'=>false,'message'=>'Nur Host darf neu starten'];
        $this->resetGameForNewMatch($gameId);
        return ['success'=>true];
    }

    public function getGameState(string $gameId, ?string $playerName=null): array {
        $state = $this->internalState($gameId);
        if (!$state) {
            return [ 'success'=>false,'message'=>'Spiel nicht gefunden','players'=>[], 'gameId'=>$gameId,'phase'=>'waiting','state'=>'waiting'];
        }
        $players = $this->fetchPlayers($gameId);
        $submissions = $state['round_id'] ? $this->roundSubmissions($state['round_id']) : [];
        $votes = $state['round_id'] ? $this->roundVotes($state['round_id']) : [];
        $mixed = $this->mixedCardsInternal($state);

        // Bestimme anfragenden Spieler (für personenbezogene Einblicke)
        $requester = null;
        if ($playerName !== null) {
            $requester = $this->playerByName($gameId, $playerName);
        }

        $playerDtos = [];
        foreach ($players as $p) {
            $cards = $this->playerHand($p['db_id']);
            $hasSelected = false;
            if ($state['phase'] !== 'storytelling' && $state['phase'] !== 'waiting' && !$p['isStoryteller']) {
                if ($state['round_id']) {
                    $hasSelected = $this->submissionExists($state['round_id'], $p['db_id']);
                }
            }
            // Karten nur für den eigenen Spieler sichtbar – case-insensitive Abgleich
            $showCards = false;
            if ($playerName !== null) {
                if (strcasecmp($playerName, (string)$p['name']) === 0) {
                    $showCards = true;
                }
            }
            $playerDtos[] = [
                'id' => $p['seat'],
                'name' => $p['name'],
                'score' => (int)$p['score'],
                'wins' => isset($p['wins']) ? (int)$p['wins'] : 0,
                'isStoryteller' => (bool)$p['isStoryteller'],
                'hasSelectedCard' => $hasSelected,
                'cards' => $showCards ? $cards : []
            ];
        }
        // Deck Meta
        $deckId = null; $deckName = null;
        $stmtD = $this->db->prepare("SELECT g.deck_id, d.name FROM g_games g LEFT JOIN g_decks d ON d.id=g.deck_id WHERE g.id=?");
        $stmtD->execute([$gameId]);
        if ($rowD = $stmtD->fetch()) { $deckId = $rowD['deck_id']; $deckName = $rowD['name'] ?? null; }
        // Nur Karten des zum Spiel gehörenden Decks laden (vorher alle Decks)
        $cardData = $this->loadCardData($gameId);
        // Rundenscores der aktuellen Runde laden (falls vorhanden)
        $roundScores = [];
        if ($state['round_id']) {
            $stmt = $this->db->prepare("SELECT rs.game_player_id, p.seat AS player_seat, p.name AS player_name, rs.delta_score, rs.total_after FROM g_round_scores rs JOIN g_players p ON p.id=rs.game_player_id WHERE rs.round_id=? ORDER BY rs.id ASC");
            $stmt->execute([$state['round_id']]);
            $roundScores = $stmt->fetchAll();
        }
        $isFinished = $state['phase']==='finished';
        $winners = [];
        if ($isFinished) {
            $max = 0; foreach ($players as $p) { $max = max($max,(int)$p['score']); }
            foreach ($players as $p) { if ((int)$p['score'] === $max) { $winners[] = [ 'name'=>$p['name'], 'score'=>(int)$p['score'] ]; } }
        }

        // Datenschutz: Felder phasengerecht einschränken
        $phase = $state['phase'];

        // storytellerCard nur in reveal/finished offenlegen
        $storytellerCardOut = null;
        if ($phase === 'reveal' || $phase === 'finished') {
            $storytellerCardOut = $state['storyteller_card_id'];
        }

        // selectedCards: in reveal/finished komplett; in voting nur die eigene Einsendung; sonst leer
        $selectedOut = [];
        if ($phase === 'reveal' || $phase === 'finished') {
            $selectedOut = $submissions;
        } elseif ($phase === 'voting' && $requester && $state['round_id']) {
            // Finde eigene Einsendung (falls vorhanden)
            $reqSeat = (int)$requester['seat'];
            foreach ($submissions as $s) {
                if ((int)$s['playerId'] === $reqSeat) { $selectedOut[] = $s; break; }
            }
        }

        // votes: in reveal/finished komplett; in voting anonym (nur playerId); sonst leer
        $votesOut = [];
        if ($phase === 'reveal' || $phase === 'finished') {
            $votesOut = $votes;
        } elseif ($phase === 'voting') {
            foreach ($votes as $v) { $votesOut[] = ['playerId' => $v['playerId']]; }
        }

        return [
            'success'=>true,
            'gameId'=>$gameId,
            'players'=>$playerDtos,
            'phase'=>$phase,
            'storytellerIndex'=> max(0,$state['storytellerSeat'] - 1),
            'hint'=>$state['hint'],
            'storytellerCard'=>$storytellerCardOut,
            'mixedCards'=>$mixed,
            'selectedCards'=>$selectedOut,
            'votes'=>$votesOut,
            'state'=>$phase==='waiting'?'waiting':($isFinished?'finished':'playing'),
            'cardData'=>$cardData,
            'roundScores'=>$roundScores,
            'deckId'=>$deckId,
            'deckName'=>$deckName,
            'winners'=>$winners,
            // NEU
            'roundNumber'=>$state['round_number']
        ];
    }

    /**
     * Spieler verlässt Lobby/Spiel. Entfernt ihn vollständig und passt ggf. Phase/Storyteller an.
     */
    public function leaveGame(string $gameId, string $playerName): array {
        $player = $this->playerByName($gameId, $playerName);
        if (!$player) {
            // Idempotent: Spieler ist bereits nicht (mehr) in diesem Spiel
            return ['success' => true];
        }
        $playerDbId = (int)$player['db_id'];
        $playerSeat = (int)$player['seat'];
        $state = $this->internalState($gameId);
        $this->db->beginTransaction();
        try {
            // Abhängige Daten des Spielers entfernen
            $this->db->prepare("DELETE FROM g_player_cards WHERE game_player_id=?")->execute([$playerDbId]);
            if ($state && $state['round_id']) {
                $rid = (int)$state['round_id'];
                $this->db->prepare("DELETE FROM g_round_votes WHERE round_id=? AND voter_player_id=?")->execute([$rid, $playerDbId]);
                $this->db->prepare("DELETE FROM g_round_submissions WHERE round_id=? AND game_player_id=?")->execute([$rid, $playerDbId]);
            }

            // Aktuelle Spielparameter lesen
            $stmtST = $this->db->prepare("SELECT storyteller_player_id, phase, current_round FROM g_games WHERE id=?");
            $stmtST->execute([$gameId]);
            $rowG = $stmtST->fetch();
            $storyDbId = $rowG && $rowG['storyteller_player_id'] !== null ? (int)$rowG['storyteller_player_id'] : null;
            $phase = $rowG ? (string)$rowG['phase'] : 'waiting';
            $roundNumber = $rowG ? (int)$rowG['current_round'] : 0;
            $isStoryteller = ($storyDbId !== null) && ($storyDbId === $playerDbId);

            // Spieler entfernen
            $this->db->prepare("DELETE FROM g_players WHERE id=?")->execute([$playerDbId]);

            // Verbleibende Spieler ermitteln (Reihenfolge vor Reseating)
            $playersLeftBefore = $this->fetchPlayers($gameId);
            $remainingCount = count($playersLeftBefore);

            if ($remainingCount === 0) {
                // Kein Spieler mehr im Spiel -> zurück in Wartezustand
                $this->db->prepare("UPDATE g_games SET phase='waiting', current_round=0, storyteller_player_id=NULL WHERE id=?")->execute([$gameId]);
                $this->db->commit();
                return ['success' => true];
            }

            // NEU: Wenn <= 2 Spieler übrig, Spiel beenden und in Lobby zurücksetzen
            if ($remainingCount <= 2) {
                // Seats zuerst komprimieren, damit ein gültiger Host (seat 1) existiert
                $this->reseatPlayers($gameId);
                // Erst aktuelle Änderungen abschließen, dann Reset durchführen
                $this->db->commit();
                $this->resetGameForNewMatch($gameId);
                return ['success' => true];
            }

            // Bestimme potentiellen nächsten Erzähler anhand der Reihenfolge vor Reseating
            $nextStoryDbId = null;
            if ($isStoryteller && $phase !== 'waiting') {
                // Finde den ersten Spieler mit seat > alter seat, sonst den ersten in der Liste
                $candidate = null; $first = null;
                foreach ($playersLeftBefore as $p) {
                    if ($first === null) $first = (int)$p['db_id'];
                    if ((int)$p['seat'] > $playerSeat) { $candidate = (int)$p['db_id']; break; }
                }
                $nextStoryDbId = $candidate !== null ? $candidate : $first;
            }

            // Seats aufräumen, damit seat=1..n (wichtig für Host/Start)
            $this->reseatPlayers($gameId);

            // Sonderfall: Erzähler verlässt ein laufendes Spiel
            if ($isStoryteller && $phase !== 'waiting') {
                // Falls noch kein Hinweis/Karte gesetzt: nur Erzähler in aktueller Runde ersetzen
                if ($state && $state['round_id'] && $phase === 'storytelling' && empty($state['hint']) && empty($state['storyteller_card_id'])) {
                    if ($nextStoryDbId !== null) {
                        $this->db->prepare("UPDATE g_rounds SET storyteller_player_id=? WHERE id=?")->execute([$nextStoryDbId, $state['round_id']]);
                        $this->db->prepare("UPDATE g_games SET storyteller_player_id=? WHERE id=?")->execute([$nextStoryDbId, $gameId]);
                    }
                    $this->db->commit();
                    return ['success' => true];
                }
                // Sonst: Runde abbrechen und neue starten
                if ($roundNumber > 0 && $state && $state['round_id']) {
                    // Falls nach dem Reseating kein expliziter nextStory vorhanden ist, nimm den aktuellen seat 1
                    if ($nextStoryDbId === null) {
                        $stmtFirst = $this->db->prepare("SELECT id FROM g_players WHERE game_id=? ORDER BY seat ASC LIMIT 1");
                        $stmtFirst->execute([$gameId]);
                        $rowFirst = $stmtFirst->fetch();
                        $nextStoryDbId = $rowFirst ? (int)$rowFirst['id'] : null;
                    }
                    if ($nextStoryDbId !== null) {
                        $nextRoundNo = ((int)$state['round_number']) + 1;
                        $stmtIns = $this->db->prepare("INSERT INTO g_rounds (game_id, round_number, storyteller_player_id) VALUES (?,?,?)");
                        $stmtIns->execute([$gameId, $nextRoundNo, $nextStoryDbId]);
                        $this->db->prepare("UPDATE g_games SET phase='storytelling', storyteller_player_id=?, current_round=? WHERE id=?")
                            ->execute([$nextStoryDbId, $nextRoundNo, $gameId]);
                        $this->replenishHands($gameId);
                    }
                } else {
                    // Noch keine Runde aktiv -> nur Erzähler setzen
                    if ($nextStoryDbId !== null) {
                        $this->db->prepare("UPDATE g_games SET storyteller_player_id=? WHERE id=?")->execute([$nextStoryDbId, $gameId]);
                    }
                }
                $this->db->commit();
                return ['success' => true];
            }

            // Normales Verlassen (kein Erzähler): prüfen, ob dadurch Phasenübergänge möglich werden
            if ($phase === 'selectCards') {
                $state2 = $this->internalState($gameId);
                if ($state2 && $state2['round_id'] && $this->allSubmissionsDone($gameId, $state2)) {
                    $this->db->prepare("UPDATE g_rounds SET phase='voting' WHERE id=?")->execute([$state2['round_id']]);
                    $this->db->prepare("UPDATE g_games SET phase='voting' WHERE id=?")->execute([$gameId]);
                }
            } elseif ($phase === 'voting') {
                $state2 = $this->internalState($gameId);
                if ($state2 && $state2['round_id'] && $this->allVotesDone($gameId, $state2)) {
                    $this->db->prepare("UPDATE g_rounds SET phase='reveal', closed_at=NOW() WHERE id=?")->execute([$state2['round_id']]);
                    $this->db->prepare("UPDATE g_games SET phase='reveal' WHERE id=?")->execute([$gameId]);
                    $this->scoreRound($state2);
                }
            }

            $this->db->commit();
            return ['success' => true];
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('leaveGame error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Leave fehlgeschlagen'];
        }
    }

    /* ---------------- interne Hilfen ---------------- */

    private function reseatPlayers(string $gameId): void {
        // Seats auf 1..n komprimieren (stabile Reihenfolge nach seat ASC)
        $stmt = $this->db->prepare("SELECT id, seat FROM g_players WHERE game_id=? ORDER BY seat ASC, id ASC");
        $stmt->execute([$gameId]);
        $rows = $stmt->fetchAll();
        $newSeat = 1;
        $upd = $this->db->prepare("UPDATE g_players SET seat=? WHERE id=?");
        foreach ($rows as $r) {
            $currentSeat = (int)$r['seat'];
            $pid = (int)$r['id'];
            if ($currentSeat !== $newSeat) {
                $upd->execute([$newSeat, $pid]);
            }
            $newSeat++;
        }
    }

    private function generateGameId(int $length=6): string {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        do {
            $id='';
            for($i=0;$i<$length;$i++){ $id .= $chars[random_int(0, strlen($chars)-1)]; }
            $stmt = $this->db->prepare("SELECT id FROM g_games WHERE id=?");
            $stmt->execute([$id]);
        } while($stmt->fetch());
        return $id;
    }

    // Lädt alle Karten für das zum Spiel gehörende Deck aus der Datenbank
    private function loadCardData(?string $gameId = null): array {
        if ($gameId === null) {
            // Fallback: alle Karten aus allen Decks
            $stmt = $this->db->query("SELECT * FROM g_cards");
            return $stmt->fetchAll();
        }
        // Deck-ID für das Spiel abfragen
        $stmt = $this->db->prepare("SELECT deck_id FROM g_games WHERE id = ?");
        $stmt->execute([$gameId]);
        $row = $stmt->fetch();
        if (!$row || !$row['deck_id']) return [];
        $deckId = $row['deck_id'];
        $stmt = $this->db->prepare("SELECT * FROM g_cards WHERE deck_id = ?");
        $stmt->execute([$deckId]);
        return $stmt->fetchAll();
    }

    private function fetchPlayers(string $gameId): array {
        $stmt = $this->db->prepare("SELECT p.*, (p.id = g.storyteller_player_id) AS isStoryteller FROM g_players p JOIN g_games g ON g.id=p.game_id WHERE p.game_id=? ORDER BY p.seat ASC");
        $stmt->execute([$gameId]);
        return array_map(function($r){ $r['db_id']=$r['id']; return $r; }, $stmt->fetchAll());
    }

    private function playerDbIdBySeat(string $gameId, int $seat): ?int {
        $stmt = $this->db->prepare("SELECT id FROM g_players WHERE game_id=? AND seat=?");
        $stmt->execute([$gameId,$seat]);
        $row=$stmt->fetch(); return $row? (int)$row['id']:null;
    }
    private function playerByName(string $gameId, string $name): ?array {
        $stmt=$this->db->prepare("SELECT p.*, (p.id = g.storyteller_player_id) AS isStoryteller FROM g_players p JOIN g_games g ON g.id=p.game_id WHERE p.game_id=? AND p.name=?");
        $stmt->execute([$gameId,$name]);
        $r=$stmt->fetch(); if(!$r) return null; $r['db_id']=$r['id']; return $r;
    }

    private function internalState(string $gameId): ?array {
        $stmt = $this->db->prepare("SELECT g.*, r.id AS round_id, r.round_number, r.hint, r.storyteller_card_id, r.phase AS round_phase FROM g_games g LEFT JOIN g_rounds r ON (r.game_id=g.id AND r.round_number=g.current_round) WHERE g.id=?");
        $stmt->execute([$gameId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        if ((int)$row['current_round']===0) {
            return [
                'gameId'=>$gameId,
                'phase'=>'waiting', 'round_id'=>null,'round_number'=>0,'storytellerSeat'=>1,'storytellerName'=>null,
                'hint'=>null,'storyteller_card_id'=>null
            ];
        }
        if ($row['phase'] === 'finished') {
            return [
                'gameId'=>$gameId,
                'phase'=>'finished',
                'round_id'=> $row['round_id'] ? (int)$row['round_id'] : null,
                'round_number'=>(int)$row['round_number'],
                'storytellerSeat'=>1,
                'storytellerName'=>null,
                'hint'=>null,
                'storyteller_card_id'=>null
            ];
        }
        $storyId = (int)$row['storyteller_player_id'];
        $stmt2 = $this->db->prepare("SELECT seat, name FROM g_players WHERE id=?");
        $stmt2->execute([$storyId]);
        $p = $stmt2->fetch();
        return [
            'gameId'=>$gameId,
            'phase'=>$row['phase'] === 'waiting' ? 'waiting' : $row['round_phase'],
            'round_id'=> $row['round_id'] ? (int)$row['round_id'] : null,
            'round_number'=>(int)$row['round_number'],
            'storytellerSeat'=>$p ? (int)$p['seat'] : 1,
            'storytellerName'=>$p ? $p['name'] : null,
            'hint'=>$row['hint'],
            'storyteller_card_id'=>$row['storyteller_card_id']? (int)$row['storyteller_card_id']:null
        ];
    }

    private function playerHand(int $playerDbId): array {
        $stmt = $this->db->prepare("SELECT card_id FROM g_player_cards WHERE game_player_id=? AND in_hand=1 ORDER BY id ASC");
        $stmt->execute([$playerDbId]);
        return array_map(fn($r)=>(int)$r['card_id'],$stmt->fetchAll());
    }

    private function cardInHand(int $playerDbId, int $cardId): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM g_player_cards WHERE game_player_id=? AND card_id=? AND in_hand=1");
        $stmt->execute([$playerDbId,$cardId]);
        return (bool)$stmt->fetch();
    }

    private function consumeCard(int $playerDbId, int $cardId, int $roundId): void {
        $stmt = $this->db->prepare("UPDATE g_player_cards SET in_hand=0, used_in_round=? WHERE game_player_id=? AND card_id=? AND in_hand=1 LIMIT 1");
        $stmt->execute([$roundId,$playerDbId,$cardId]);
    }

    private function submissionExists(int $roundId, int $playerDbId): bool {
        $stmt=$this->db->prepare("SELECT 1 FROM g_round_submissions WHERE round_id=? AND game_player_id=?");
        $stmt->execute([$roundId,$playerDbId]);
        return (bool)$stmt->fetch();
    }

    private function voteExists(int $roundId, int $playerDbId): bool {
        $stmt=$this->db->prepare("SELECT 1 FROM g_round_votes WHERE round_id=? AND voter_player_id=?");
        $stmt->execute([$roundId,$playerDbId]);
        return (bool)$stmt->fetch();
    }

    private function allSubmissionsDone(string $gameId, array $state): bool {
        $players = $this->fetchPlayers($gameId);
        $nonStory = array_filter($players, fn($p)=>!$p['isStoryteller']);
        $needed = count($nonStory);
        $stmt=$this->db->prepare("SELECT COUNT(*) c FROM g_round_submissions WHERE round_id=?");
        $stmt->execute([$state['round_id']]);
        $c=(int)$stmt->fetch()['c'];
        return $c >= $needed;
    }

    private function allVotesDone(string $gameId, array $state): bool {
        $players = $this->fetchPlayers($gameId);
        $nonStory = array_filter($players, fn($p)=>!$p['isStoryteller']);
        $needed = count($nonStory);
        $stmt=$this->db->prepare("SELECT COUNT(*) c FROM g_round_votes WHERE round_id=?");
        $stmt->execute([$state['round_id']]);
        $c=(int)$stmt->fetch()['c'];
        return $c >= $needed;
    }

    private function roundSubmissions(int $roundId): array {
        $stmt=$this->db->prepare("SELECT game_player_id, card_id FROM g_round_submissions WHERE round_id=?");
        $stmt->execute([$roundId]);
        $rows=$stmt->fetchAll();
        return array_map(fn($r)=>['playerId'=>$this->seatByDbId((int)$r['game_player_id']),'cardId'=>(int)$r['card_id']],$rows);
    }

    private function seatByDbId(int $playerDbId): int {
        $stmt=$this->db->prepare("SELECT seat FROM g_players WHERE id=?");
        $stmt->execute([$playerDbId]);
        $row=$stmt->fetch(); return $row? (int)$row['seat']:0;
    }

    private function roundVotes(int $roundId): array {
        $stmt=$this->db->prepare("SELECT voter_player_id, card_id FROM g_round_votes WHERE round_id=?");
        $stmt->execute([$roundId]);
        $rows=$stmt->fetchAll();
        return array_map(fn($r)=>['playerId'=>$this->seatByDbId((int)$r['voter_player_id']),'cardId'=>(int)$r['card_id']],$rows);
    }

    private function mixedCardsInternal(array $state): array {
        if (!$state['round_id']) return [];
        // Storyteller-Karte + alle Submission-Karten mischen (stabilisierte Reihenfolge nach Insert darf gemischt sein)
        $cards=[];
        if ($state['storyteller_card_id']) $cards[] = (int)$state['storyteller_card_id'];
        $stmt=$this->db->prepare("SELECT card_id FROM g_round_submissions WHERE round_id=? ORDER BY id ASC");
        $stmt->execute([$state['round_id']]);
        foreach ($stmt->fetchAll() as $r) { $cards[]=(int)$r['card_id']; }
        $cards=array_values(array_unique($cards));
        if ($state['phase']==='selectCards') return []; // Noch nicht anzeigen
        // Voting & Reveal sollen gleiche Reihenfolge liefern -> pseudo shuffle nach round_id Hash
        if ($state['phase']==='voting') {
            // stabile Shuffle: sort nach hash(card_id . round_id)
            usort($cards, fn($a,$b)=> strcmp(sha1($a.'-'.$state['round_id']), sha1($b.'-'.$state['round_id'])));
        }
        if ($state['phase']==='reveal') {
            // gleiche Sortierung beibehalten
            usort($cards, fn($a,$b)=> strcmp(sha1($a.'-'.$state['round_id']), sha1($b.'-'.$state['round_id'])));
        }
        return $cards;
    }

    private function scoreRound(array $state): void {
        if (!$state['storyteller_card_id'] || !$state['round_id']) return;
        $roundId = $state['round_id'];
        $gameId = $state['gameId'];
        $storyCard = (int)$state['storyteller_card_id'];
        $stmt=$this->db->prepare("SELECT voter_player_id, card_id FROM g_round_votes WHERE round_id=?");
        $stmt->execute([$roundId]);
        $votes=$stmt->fetchAll();
        $owners=[];
        $stmt=$this->db->prepare("SELECT game_player_id, card_id FROM g_round_submissions WHERE round_id=?");
        $stmt->execute([$roundId]);
        foreach($stmt->fetchAll() as $r){ $owners[(int)$r['card_id']] = (int)$r['game_player_id']; }
        $storyOwnerDbId = $this->playerDbIdBySeat($gameId, $state['storytellerSeat']);
        $correctVoters=[]; $voteCountPerCard=[];
        foreach($votes as $v){
            $voteCountPerCard[(int)$v['card_id']] = ($voteCountPerCard[(int)$v['card_id']] ?? 0) + 1;
            if ((int)$v['card_id'] === $storyCard) { $correctVoters[] = (int)$v['voter_player_id']; }
        }
        $allVoters = array_column($votes,'voter_player_id');
        $noneCorrect = empty($correctVoters);
        $allCorrect = count($correctVoters) === count($allVoters) && !$noneCorrect;
        $scoreChanges = [];
        if (!$allCorrect && !$noneCorrect) { $scoreChanges[$storyOwnerDbId] = ($scoreChanges[$storyOwnerDbId] ?? 0) + 3; }
        foreach ($correctVoters as $dbid) { $scoreChanges[$dbid] = ($scoreChanges[$dbid] ?? 0) + 3; }
        foreach ($votes as $v) {
            $cid=(int)$v['card_id'];
            $owner = $owners[$cid] ?? null;
            if ($owner && $cid !== $storyCard) { $scoreChanges[$owner] = ($scoreChanges[$owner] ?? 0) + 1; }
        }
        foreach ($scoreChanges as $playerDbId=>$delta) {
            $stmt=$this->db->prepare("UPDATE g_players SET score=score+? WHERE id=?");
            $stmt->execute([$delta,$playerDbId]);
            $stmt=$this->db->prepare("SELECT score FROM g_players WHERE id=?");
            $stmt->execute([$playerDbId]);
            $total=(int)$stmt->fetch()['score'];
            $ins=$this->db->prepare("INSERT INTO g_round_scores (round_id, game_player_id, delta_score, total_after) VALUES (?,?,?,?)");
            $ins->execute([$roundId,$playerDbId,$delta,$total]);
        }
        // Siegbedingung prüfen (>=30 Punkte)
        $stmt=$this->db->prepare("SELECT MAX(score) AS maxScore FROM g_players WHERE game_id=?");
        $stmt->execute([$gameId]);
        $maxScore=(int)$stmt->fetch()['maxScore'];
        if ($maxScore >= 30) {
            $this->finishGame($gameId, $maxScore);
        }
    }

    private function finishGame(string $gameId, int $maxScore): void {
        // Gewinner bestimmen
        $stmt=$this->db->prepare("SELECT id FROM g_players WHERE game_id=? AND score=?");
        $stmt->execute([$gameId,$maxScore]);
        $winnerIds = array_map(fn($r)=>(int)$r['id'],$stmt->fetchAll());
        if ($winnerIds) {
            $in=implode(',',array_fill(0,count($winnerIds),'?'));
            $upd=$this->db->prepare("UPDATE g_players SET wins = wins + 1 WHERE id IN ($in)");
            $upd->execute($winnerIds);
        }
        // Phase auf finished setzen
        $this->db->prepare("UPDATE g_games SET phase='finished' WHERE id=?")->execute([$gameId]);
    }

    private function resetGameForNewMatch(string $gameId): void {
        $this->db->beginTransaction();
        try {
            // Alle Runden ermitteln
            $stmt=$this->db->prepare("SELECT id FROM g_rounds WHERE game_id=?");
            $stmt->execute([$gameId]);
            $roundIds = array_map(fn($r)=>(int)$r['id'],$stmt->fetchAll());
            if (!empty($roundIds)) {
                $in = implode(',', array_fill(0,count($roundIds),'?'));
                // Abhängige Tabellen in richtiger Reihenfolge löschen
                $delScores = $this->db->prepare("DELETE FROM g_round_scores WHERE round_id IN ($in)");
                $delScores->execute($roundIds);
                $delVotes = $this->db->prepare("DELETE FROM g_round_votes WHERE round_id IN ($in)");
                $delVotes->execute($roundIds);
                $delSubs = $this->db->prepare("DELETE FROM g_round_submissions WHERE round_id IN ($in)");
                $delSubs->execute($roundIds);
                // NEU: Rerolls löschen
                $delRerolls = $this->db->prepare("DELETE FROM g_round_rerolls WHERE round_id IN ($in)");
                $delRerolls->execute($roundIds);
                $delRounds = $this->db->prepare("DELETE FROM g_rounds WHERE id IN ($in)");
                $delRounds->execute($roundIds);
            }
            // Hände & Deck entfernen
            $stmt=$this->db->prepare("SELECT id FROM g_players WHERE game_id=?");
            $stmt->execute([$gameId]);
            $playerIds = array_map(fn($r)=>(int)$r['id'],$stmt->fetchAll());
            if (!empty($playerIds)) {
                $inPlayers = implode(',', array_fill(0, count($playerIds), '?'));
                $del = $this->db->prepare("DELETE FROM g_player_cards WHERE game_player_id IN ($inPlayers)");
                $del->execute($playerIds);
            }
            $delDeck = $this->db->prepare("DELETE FROM g_deck_cards WHERE game_id=?");
            $delDeck->execute([$gameId]);
            // Scores zurücksetzen, Phase auf waiting, Runde 0, Storyteller entfernen (Deck bleibt erhalten)
            $this->db->prepare("UPDATE g_players SET score=0 WHERE game_id=?")->execute([$gameId]);
            $this->db->prepare("UPDATE g_games SET phase='waiting', current_round=0, storyteller_player_id=NULL WHERE id=?")->execute([$gameId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('resetGameForNewMatch error: '.$e->getMessage());
        }
    }

    private function dealHands(string $gameId): void {
        // Für jeden Spieler HAND_SIZE Karten ziehen
        $players = $this->fetchPlayers($gameId);
        foreach ($players as $p) {
            $need = $this->HAND_SIZE - count($this->playerHand($p['db_id']));
            if ($need <= 0) continue;
            $this->drawCards($gameId, $p['db_id'], $need);
        }
    }

    private function replenishHands(string $gameId): void {
        $this->dealHands($gameId);
    }

    private function drawCards(string $gameId, int $playerDbId, int $count): void {
        $stmt = $this->db->prepare("SELECT id, card_id FROM g_deck_cards WHERE game_id=? AND consumed=0 ORDER BY position ASC LIMIT ?");
        $stmt->bindValue(1, $gameId);
        $stmt->bindValue(2, $count, PDO::PARAM_INT);
        $stmt->execute();
        $rows=$stmt->fetchAll();
        $upd = $this->db->prepare("UPDATE g_deck_cards SET consumed=1 WHERE id=?");
        $ins = $this->db->prepare("INSERT INTO g_player_cards (game_player_id, card_id) VALUES (?,?)");
        foreach ($rows as $r) {
            $upd->execute([$r['id']]);
            $ins->execute([$playerDbId, (int)$r['card_id']]);
        }
    }

    private function ensureWinsColumn(): void {
        try {
            $this->db->query("SELECT wins FROM g_players LIMIT 1");
        } catch (Throwable $e) {
            try { $this->db->exec("ALTER TABLE g_players ADD COLUMN wins INT NOT NULL DEFAULT 0"); } catch (Throwable $e2) { /* ignore */ }
        }
    }

    private function ensureRerollTable(): void {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS g_round_rerolls (
                id INT AUTO_INCREMENT PRIMARY KEY,
                round_id INT NOT NULL,
                game_player_id INT NOT NULL,
                old_card_id INT NOT NULL,
                new_card_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Throwable $e) { /* ignore */ }
    }

    /**
     * Einmal pro Runde darf ein Spieler eine Handkarte gegen eine neue aus dem Deck tauschen.
     * Storyteller: in storytelling-Phase, bevor Hinweis/Karte gesetzt ist.
     * Andere Spieler: in selectCards-Phase, solange noch nicht eingereicht wurde.
     */
    public function rerollCard(string $gameId, string $playerName, int $cardId): array {
        $state = $this->internalState($gameId);
        if (!$state) return ['success'=>false,'message'=>'Spiel nicht gefunden'];
        $phase = $state['phase'];
        if (!in_array($phase, ['storytelling','selectCards'], true)) {
            return ['success'=>false,'message'=>'Reroll in dieser Phase nicht erlaubt'];
        }
        $player = $this->playerByName($gameId, $playerName);
        if (!$player) return ['success'=>false,'message'=>'Spieler nicht gefunden'];
        $playerDbId = (int)$player['db_id'];
        $roundId = $state['round_id'];
        if (!$roundId) return ['success'=>false,'message'=>'Keine aktive Runde'];

        // Phasenregeln
        if ($phase === 'storytelling') {
            // Nur Erzähler und nur wenn noch kein Hinweis/Karte gesetzt
            if (!$player['isStoryteller']) return ['success'=>false,'message'=>'Nur Erzähler darf hier rerollen'];
            if (!empty($state['hint']) || !empty($state['storyteller_card_id'])) {
                return ['success'=>false,'message'=>'Reroll nach Hinweis nicht erlaubt'];
            }
        } else { // selectCards
            // Erzähler darf in selectCards nicht rerollen
            if ($player['isStoryteller']) return ['success'=>false,'message'=>'Erzähler kann jetzt nicht rerollen'];
            // Kein Reroll nach eigener Einreichung
            if ($this->submissionExists($roundId, $playerDbId)) return ['success'=>false,'message'=>'Bereits Karte eingereicht'];
        }

        // Hat der Spieler in dieser Runde bereits gererolled?
        $stmt = $this->db->prepare("SELECT 1 FROM g_round_rerolls WHERE round_id=? AND game_player_id=?");
        $stmt->execute([$roundId, $playerDbId]);
        if ($stmt->fetch()) return ['success'=>false,'message'=>'Reroll bereits genutzt'];

        // Karte muss in der Hand sein
        if (!$this->cardInHand($playerDbId, $cardId)) return ['success'=>false,'message'=>'Karte nicht in Hand'];

        $this->db->beginTransaction();
        try {
            // Alte Karte aus der Hand entfernen (ohne used_in_round zu setzen)
            $upd = $this->db->prepare("UPDATE g_player_cards SET in_hand=0 WHERE game_player_id=? AND card_id=? AND in_hand=1 LIMIT 1");
            $upd->execute([$playerDbId, $cardId]);
            if ($upd->rowCount() === 0) {
                $this->db->rollBack();
                return ['success'=>false,'message'=>'Kartenstatus unerwartet'];
            }
            // Neue Karte vom Deck ziehen (1 Stück)
            $pick = $this->db->prepare("SELECT id, card_id FROM g_deck_cards WHERE game_id=? AND consumed=0 ORDER BY position ASC LIMIT 1");
            $pick->execute([$gameId]);
            $row = $pick->fetch();
            if (!$row) { $this->db->rollBack(); return ['success'=>false,'message'=>'Keine Karten mehr im Deck']; }
            $deckRowId = (int)$row['id']; $newCardId = (int)$row['card_id'];
            $this->db->prepare("UPDATE g_deck_cards SET consumed=1 WHERE id=?")->execute([$deckRowId]);
            $this->db->prepare("INSERT INTO g_player_cards (game_player_id, card_id) VALUES (?,?)")->execute([$playerDbId, $newCardId]);
            // Reroll protokollieren
            $this->db->prepare("INSERT INTO g_round_rerolls (round_id, game_player_id, old_card_id, new_card_id) VALUES (?,?,?,?)")
                ->execute([$roundId, $playerDbId, $cardId, $newCardId]);
            $this->db->commit();
            return ['success'=>true, 'newCardId'=>$newCardId];
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('rerollCard error: '.$e->getMessage());
            return ['success'=>false,'message'=>'Reroll fehlgeschlagen'];
        }
    }
}
