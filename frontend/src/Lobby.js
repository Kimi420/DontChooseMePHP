import React from 'react';
import './LobbyStyle.css';

function Lobby({ players, gameId, error, onJoin, onStart, onLeave }) {
  return (
    <div className="lobby-container">
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
      {error && <div className="lobby-error">⚠️ {error}</div>}
      <button className="lobby-btn" onClick={onJoin}>🚀 Raum beitreten</button>
      <button className="lobby-btn" onClick={onStart} disabled={players.length < 3}>🎮 Spiel starten!</button>
      <button className="lobby-btn" onClick={onLeave}>🚪 Verlassen</button>
    </div>
  );
}

export default Lobby;

