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

  return (
    <div className="lobby-container">
      {gameId ? (
        // Wenn ein Spiel bereits aktiv ist, zeige Spieler-Liste
        <>
          <h2>🏠 Raum: {gameId}</h2>
          <div className="lobby-players">
            <h3>👥 Spieler ({players.length})</h3>
            <div style={{ display: 'grid', gap: '12px', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
              {players.map((player, idx) => (
                <div key={player.id} className="lobby-player-card">
                  <div style={{ fontSize: '24px', marginBottom: '8px' }}>
                    {idx === 0 ? '👑' : '🎮'}
                  </div>
                  <div style={{ fontWeight: 'bold', fontSize: '16px' }}>{player.name}</div>
                  {idx === 0 && <div style={{ color: '#FFD700', fontSize: '12px', marginTop: '4px', fontWeight: 'bold' }}>Raumleiter</div>}
                </div>
              ))}
            </div>
          </div>
          <button
            className="lobby-btn"
            onClick={() => onStart()}
            disabled={players.length < 3 || (players[0] && players[0].name !== playerName)}
            title={players.length < 3 ? 'Mindestens 3 Spieler nötig' : (players[0] && players[0].name !== playerName ? 'Nur der Raumleiter kann starten' : 'Spiel starten')}
          >
            🎮 Spiel starten!
          </button>
          <button className="lobby-btn" onClick={onLeave}>🚪 Verlassen</button>
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
