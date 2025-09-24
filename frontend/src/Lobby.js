import React, { useState } from 'react';
import './LobbyStyle.css';

function Lobby({ players, playerName, gameId, error, onJoin, onStart, onLeave }) {
  const [inputPlayerName, setInputPlayerName] = useState('');
  const [roomId, setRoomId] = useState(gameId || '');
  const [localError, setLocalError] = useState(error || '');

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

  const handleCreate = () => {
    if (!inputPlayerName) {
      setLocalError('Bitte einen Namen eingeben!');
      return;
    }

    onStart(inputPlayerName);
  };

  const hostPlayer = players && players.length > 0 ? players.reduce((min, p) => (p.id < min.id ? p : min), players[0]) : null;
  const isHost = !!hostPlayer && hostPlayer.name === playerName;
  const enoughPlayers = players.length >= 3;

  return (
    <div className="lobby-container">
      {gameId ? (
        // Wenn ein Raum existiert, aber das Spiel noch nicht gestartet wurde
        <>
          <h2>🏠 Raum: {gameId}</h2>
          <div className="lobby-players">
            <h3>👥 Spieler ({players.length})</h3>
            <div style={{ display: 'grid', gap: '12px', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
              {players.map((player) => (
                <div key={player.id} className="lobby-player-card">
                  <div style={{ fontSize: '24px', marginBottom: '8px' }}>
                    {hostPlayer && player.id === hostPlayer.id ? '👑' : '🎮'}
                  </div>
                  <div style={{ fontWeight: 'bold', fontSize: '16px' }}>{player.name}</div>
                  {hostPlayer && player.id === hostPlayer.id && (
                    <div style={{ color: '#FFD700', fontSize: '12px', marginTop: '4px', fontWeight: 'bold' }}>Raumleiter</div>
                  )}
                </div>
              ))}
            </div>
          </div>

          <div style={{ marginTop: '24px', padding: '16px', background: 'rgba(255,255,255,0.08)', borderRadius: '12px', border: '1px solid rgba(255,255,255,0.15)' }}>
            <h3 style={{ marginTop: 0 }}>Spielstart</h3>
            {!enoughPlayers && (
              <div style={{ color: '#ffeb99', fontSize: '14px', marginBottom: '8px' }}>⏳ Warten... Mindestens 3 Spieler benötigt (aktuell {players.length}).</div>
            )}
            {enoughPlayers && !isHost && (
              <div style={{ color: '#9fd4ff', fontSize: '14px', marginBottom: '8px' }}>Der Raumleiter ({hostPlayer?.name}) kann das Spiel jetzt starten.</div>
            )}
            {enoughPlayers && isHost && (
              <div style={{ color: '#b6ffb3', fontSize: '14px', marginBottom: '8px' }}>Bereit! Starte das Spiel, wenn alle soweit sind.</div>
            )}
            <button
              className="lobby-btn"
              style={{ opacity: enoughPlayers ? 1 : 0.6, cursor: enoughPlayers && isHost ? 'pointer' : 'not-allowed' }}
              onClick={() => isHost && enoughPlayers && onStart()}
              disabled={!enoughPlayers || !isHost}
            >
              🎮 Spiel starten
            </button>
          </div>

          <button className="lobby-btn" style={{ marginTop: '16px' }} onClick={onLeave}>🚪 Verlassen</button>
        </>
      ) : (
        // Wenn noch kein Spiel aktiv ist, zeige Eingabefelder
        <>
          <h2>Willkommen bei "Don't Choose Me"</h2>

          <div className="form-group">
            <label htmlFor="playerName">Dein Name:</label>
            <input
              type="text"
              id="playerName"
              value={inputPlayerName}
              onChange={handlePlayerNameChange}
              placeholder="Namen eingeben..."
              className="form-control"
            />
          </div>

          <div className="form-group">
            <label htmlFor="roomId">Raum-ID:</label>
            <div className="input-group">
              <input
                type="text"
                id="roomId"
                value={roomId}
                onChange={handleRoomIdChange}
                placeholder="z.B. ABC123"
                className="form-control"
              />
            </div>
          </div>

          <button className="lobby-btn" onClick={handleJoin}>🚀 Raum beitreten</button>
          <button className="lobby-btn" onClick={handleCreate}>🎮 Neues Spiel erstellen</button>
        </>
      )}

      {(localError || error) && <div className="lobby-error">⚠️ {localError || error}</div>}
    </div>
  );
}

export default Lobby;
