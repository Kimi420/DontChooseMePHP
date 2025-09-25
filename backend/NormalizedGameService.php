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
        // Prüfen ob Spiel existiert
        $stmt = $this->db->prepare("SELECT id FROM g_games WHERE id=?");
        $stmt->execute([$gameId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'message' => 'Spiel nicht gefunden'];
        }
        // Name schon vergeben?
        $stmt = $this->db->prepare("SELECT 1 FROM g_players WHERE game_id=? AND name=?");
        $stmt->execute([$gameId, $playerName]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Name bereits vergeben'];
        }
        // Höchsten seat bestimmen
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(seat),0)+1 AS nextSeat FROM g_players WHERE game_id=?");
        $stmt->execute([$gameId]);
        $nextSeat = (int)$stmt->fetch()['nextSeat'];
        $stmt = $this->db->prepare("INSERT INTO g_players (game_id, seat, name) VALUES (?,?,?)");
        $stmt->execute([$gameId, $nextSeat, $playerName]);
        return [
            'success' => true,
            'gameId' => $gameId,
            'player' => ['id' => $nextSeat, 'name' => $playerName]
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
        $this->db->beginTransaction();
        try {
            // Storyteller = seat 1 initial
            $storytellerSeat = 1;
            $storytellerId = $this->playerDbIdBySeat($gameId, $storytellerSeat);
            // Runde 1 anlegen
            $stmt = $this->db->prepare("INSERT INTO g_rounds (game_id, round_number, storyteller_player_id) VALUES (?,?,?)");
            $stmt->execute([$gameId, 1, $storytellerId]);
            $roundId = (int)$this->db->lastInsertId();
            // g_games updaten
            $stmt = $this->db->prepare("UPDATE g_games SET phase='storytelling', storyteller_player_id=?, current_round=1 WHERE id=?");
            $stmt->execute([$storytellerId, $gameId]);
            // Deck aufbauen & mischen
            $cardData = $this->loadCardData();
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

    public function getGameState(string $gameId, ?string $playerName=null): array {
        $state = $this->internalState($gameId);
        if (!$state) {
            return [ 'success'=>false,'message'=>'Spiel nicht gefunden','players'=>[], 'gameId'=>$gameId,'phase'=>'waiting','state'=>'waiting'];
        }
        $players = $this->fetchPlayers($gameId);
        $submissions = $state['round_id'] ? $this->roundSubmissions($state['round_id']) : [];
        $votes = $state['round_id'] ? $this->roundVotes($state['round_id']) : [];
        $mixed = $this->mixedCardsInternal($state);
        $playerDtos = [];
        foreach ($players as $p) {
            $cards = $this->playerHand($p['db_id']);
            $hasSelected = false;
            if ($state['phase'] !== 'storytelling' && $state['phase'] !== 'waiting' && !$p['isStoryteller']) {
                if ($state['round_id']) {
                    $hasSelected = $this->submissionExists($state['round_id'], $p['db_id']);
                }
            }
            $playerDtos[] = [
                'id' => $p['seat'],
                'name' => $p['name'],
                'score' => (int)$p['score'],
                'isStoryteller' => (bool)$p['isStoryteller'],
                'hasSelectedCard' => $hasSelected,
                'cards' => ($playerName === $p['name']) ? $cards : []
            ];
        }
        $cardData = $this->loadCardData();
        // Rundenscores der aktuellen Runde laden (falls vorhanden)
        $roundScores = [];
        if ($state['round_id']) {
            $stmt = $this->db->prepare("SELECT game_player_id, delta_score, total_after FROM g_round_scores WHERE round_id=?");
            $stmt->execute([$state['round_id']]);
            $roundScores = $stmt->fetchAll();
        }
        return [
            'success'=>true,
            'gameId'=>$gameId,
            'players'=>$playerDtos,
            'phase'=>$state['phase'],
            'storytellerIndex'=> max(0,$state['storytellerSeat'] - 1),
            'hint'=>$state['hint'],
            'storytellerCard'=>$state['storyteller_card_id'],
            'mixedCards'=>$mixed,
            'selectedCards'=>$submissions,
            'votes'=>$votes,
            'state'=>$state['phase']==='waiting'?'waiting':'playing',
            'cardData'=>$cardData,
            'roundScores'=>$roundScores
        ];
    }

    /* ---------------- interne Hilfen ---------------- */

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
        if (!$allCorrect && !$noneCorrect) {
            $scoreChanges[$storyOwnerDbId] = ($scoreChanges[$storyOwnerDbId] ?? 0) + 3;
        }
        foreach ($correctVoters as $dbid) {
            $scoreChanges[$dbid] = ($scoreChanges[$dbid] ?? 0) + 3;
        }
        foreach ($votes as $v) {
            $cid=(int)$v['card_id'];
            $owner = $owners[$cid] ?? null;
            if ($owner && $cid !== $storyCard) {
                $scoreChanges[$owner] = ($scoreChanges[$owner] ?? 0) + 1;
            }
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
}
