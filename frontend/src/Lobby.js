import React, { useState, useEffect } from 'react';
import './LobbyStyle.css';
import './AppLayout.css';
import { fetchDecks, createGame } from './api';

function Lobby({ players, playerName, gameId, error, onJoin, onStart, onLeave }) {
  const [inputPlayerName, setInputPlayerName] = useState('');
  const [roomId, setRoomId] = useState(gameId || '');
  const [localError, setLocalError] = useState(error || '');
  const [decks, setDecks] = useState([]);
  const [selectedDeck, setSelectedDeck] = useState(null);
  const [loadingDecks, setLoadingDecks] = useState(false);

  useEffect(() => {
    setLoadingDecks(true);
    fetchDecks().then(res => {
      if (res.success && Array.isArray(res.decks)) {
        setDecks(res.decks);
        setSelectedDeck(res.decks[0]?.id || null);
      }
      setLoadingDecks(false);
    });
  }, []);

  const handlePlayerNameChange = (e) => {
    setInputPlayerName(e.target.value);
    setLocalError('');
  };

  const handleRoomIdChange = (e) => {
    setRoomId(e.target.value.toUpperCase());
    setLocalError('');
  };

  const handleJoin = () => {
    if (!inputPlayerName) {
      setLocalError('Bitte einen Namen eingeben!');
      return;
    }

    if (!roomId) {
      setLocalError('Bitte eine Raum-ID eingeben!');
      return;
    }

    onJoin(roomId, inputPlayerName);
  };

  const handleCreate = async () => {
    if (!inputPlayerName) {
      setLocalError('Bitte einen Namen eingeben!');
      return;
    }
    if (!selectedDeck) {
      setLocalError('Bitte ein Kartendeck auswählen!');
      return;
    }
    setLocalError('');
    // createGame direkt aufrufen, damit Deck übergeben wird
    const res = await createGame(inputPlayerName, selectedDeck);
    if (res.success && res.gameId) {
      onJoin(res.gameId, inputPlayerName);
    } else {
      setLocalError(res.message || 'Fehler beim Erstellen des Spiels');
    }
  };

  const hostPlayer = players && players.length > 0 ? players.reduce((min, p) => (p.id < min.id ? p : min), players[0]) : null;
  const isHost = !!hostPlayer && hostPlayer.name === playerName;
  const enoughPlayers = players.length >= 3;

  if (gameId) {
    // Lobby-Ansicht für existierendes Spiel (Pre-Start)
    return (
      <div className="stack" style={{ width: '100%' }}>
        <div className="space-between" style={{ flexWrap: 'wrap', gap: 12 }}>
          <h2 style={{ margin: '0 0 4px 0', fontSize: '1.3rem' }}>
            Raum <span style={{ fontWeight: 600 }}>{gameId}</span>
          </h2>
          <div className="notice">👥 Spieler: {players.length}</div>
        </div>

        <div className="player-grid">
          {players.map((p) => {
            const isHostCard = hostPlayer && p.id === hostPlayer.id;
            return (
              <div
                key={p.id}
                className="player-card"
                style={{
                  border: isHostCard ? '1px solid var(--color-accent)' : '1px solid var(--color-border)',
                }}
              >
                <div style={{ fontSize: '1.1rem' }}>{isHostCard ? '👑' : '🎮'} {p.name}</div>
                {isHostCard && <div className="role">RAUMLEITER</div>}
              </div>
            );
          })}
        </div>

        <div className="sidebar-section" style={{ marginTop: 4 }}>
          <h3 style={{ margin: '0 0 8px 0' }}>Spielstart</h3>
          {!enoughPlayers && <div className="notice">⏳ Warte auf weitere Spieler (min. 3)</div>}
          {enoughPlayers && !isHost && <div className="notice">Der Raumleiter ({hostPlayer?.name}) kann starten</div>}
          {enoughPlayers && isHost && (
            <div className="notice" style={{ background: 'rgba(16,185,129,0.25)', borderColor: 'rgba(16,185,129,0.5)' }}>
              Bereit zum Start ✅
            </div>
          )}
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginTop: 12 }}>
            <button
              className="btn"
              disabled={!enoughPlayers || !isHost}
              onClick={() => onStart()}
            >
              🎮 Spiel starten
            </button>
            <button className="btn outline" onClick={onLeave}>🚪 Verlassen</button>
          </div>
        </div>

        {(localError || error) && <div className="alert">⚠️ {localError || error}</div>}
      </div>
    );
  }

  // Initiale Ansicht (kein Spiel yet)
  return (
    <div className="stack" style={{ width: '100%' }}>
      <h2 style={{ margin: '0 0 8px 0', fontSize: '1.4rem' }}>Spiel erstellen oder beitreten</h2>
      <form className="form" onSubmit={(e) => { e.preventDefault(); handleJoin(); }}>
        <div className="field">
          <label htmlFor="playerName">Dein Name</label>
          <input
            id="playerName"
            value={inputPlayerName}
            onChange={handlePlayerNameChange}
            placeholder="z.B. Alex"
            maxLength={20}
          />
        </div>
        <div className="field">
          <label htmlFor="roomId">Raum-ID (falls beitreten)</label>
          <input
            id="roomId"
            value={roomId}
            onChange={handleRoomIdChange}
            placeholder="Z.B. ABC123"
            maxLength={10}
          />
        </div>
        <div className="field">
          <label htmlFor="deckSelect">Kartendeck</label>
          <select
            id="deckSelect"
            value={selectedDeck || ''}
            onChange={e => setSelectedDeck(e.target.value)}
            disabled={loadingDecks || decks.length === 0}
          >
            {decks.map(deck => (
              <option key={deck.id} value={deck.id}>{deck.name}</option>
            ))}
          </select>
          {loadingDecks && <div className="notice">Decks werden geladen...</div>}
        </div>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12 }}>
          <button
            type="button"
            className="btn"
            onClick={handleJoin}
            disabled={!inputPlayerName || !roomId}
          >
            🚀 Beitreten
          </button>
          <button
            type="button"
            className="btn outline"
            onClick={handleCreate}
            disabled={!inputPlayerName || !selectedDeck}
          >
            🎮 Neues Spiel
          </button>
        </div>
      </form>
      {(localError || error) && <div className="alert">⚠️ {localError || error}</div>}
      <div className="notice">💡 Tipp: Teile nach dem Erstellen einfach die Raum-ID mit deinen Freunden.</div>
    </div>
  );
}

export default Lobby;
