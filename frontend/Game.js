import React, { useEffect, useState } from 'react';
import { getGameState, giveHint, chooseCard, vote, nextRound } from './api';
import './GameStyle.css';
import audioManager from './utils/AudioManager';

const SOUND_PATH = 'frontend/sounds/';

function Game({ gameId, playerName, onLeaveGame, volume, setVolume }) {
  const [gameState, setGameState] = useState(null);
  const [hint, setHint] = useState('');
  const [selectedCard, setSelectedCard] = useState(null);
  const [voteCard, setVoteCard] = useState(null);
  const [error, setError] = useState('');
  const [lastPhase, setLastPhase] = useState(null);

  useEffect(() => {
    const interval = setInterval(() => {
      getGameState(gameId).then(setGameState);
    }, 1500);
    return () => clearInterval(interval);
  }, [gameId]);

  useEffect(() => {
    if (gameState) {
      // Phasenwechsel erkennen
      if (lastPhase && lastPhase !== gameState.phase) {
        audioManager.playEffect(`${SOUND_PATH}phase-change.mp3`);
      }
      setLastPhase(gameState.phase);
      // Storyteller-Sound nur für Erzähler in Storytelling-Phase
      const isStoryteller = gameState.players.find(p => p.name === playerName)?.isStoryteller;
      if (gameState.phase === 'storytelling' && isStoryteller) {
        audioManager.playEffect(`${SOUND_PATH}storyteller.mp3`);
      }
    }
  }, [gameState]);

  if (!gameState) return <div>Spiel wird geladen...</div>;

  const phase = gameState.phase;
  const isStoryteller = gameState.players.find(p => p.name === playerName)?.isStoryteller;

  const handleGiveHint = async () => {
    if (!hint || !selectedCard) {
      setError('Bitte Hinweis und Karte wählen!');
      return;
    }
    await giveHint(gameId, playerName, selectedCard, hint);
    setHint('');
    setSelectedCard(null);
  };

  const handleChooseCard = async () => {
    if (!selectedCard) {
      setError('Bitte eine Karte wählen!');
      return;
    }
    await chooseCard(gameId, playerName, selectedCard);
    setSelectedCard(null);
  };

  const handleVote = async () => {
    if (!voteCard) {
      setError('Bitte eine Karte zum Abstimmen wählen!');
      return;
    }
    await vote(gameId, playerName, voteCard);
    setVoteCard(null);
  };

  const handleNextRound = async () => {
    await nextRound(gameId);
  };

  return (
    <div className="game-container">
      <h2>Spiel: {gameId}</h2>
      <div>Phase: {phase}</div>
      <div>Spieler:
        <ul>
          {gameState.players.map(p => (
            <li key={p.id} style={{ fontWeight: p.name === playerName ? 'bold' : 'normal' }}>
              {p.name} {p.isStoryteller ? '(Erzähler)' : ''} - Punkte: {p.score}
            </li>
          ))}
        </ul>
      </div>
      {error && <div className="game-error">{error}</div>}
      {phase === 'storytelling' && isStoryteller && (
        <div>
          <h3>Du bist der Erzähler!</h3>
          <input value={hint} onChange={e => setHint(e.target.value)} placeholder="Hinweis eingeben..." />
          <input type="number" value={selectedCard || ''} onChange={e => setSelectedCard(Number(e.target.value))} placeholder="Karten-ID wählen" />
          <button onClick={handleGiveHint}>Hinweis geben</button>
        </div>
      )}
      {phase === 'selectCards' && !isStoryteller && (
        <div>
          <h3>Karte auswählen</h3>
          <input type="number" value={selectedCard || ''} onChange={e => setSelectedCard(Number(e.target.value))} placeholder="Karten-ID wählen" />
          <button onClick={handleChooseCard}>Karte wählen</button>
        </div>
      )}
      {phase === 'voting' && (
        <div>
          <h3>Abstimmen</h3>
          <input type="number" value={voteCard || ''} onChange={e => setVoteCard(Number(e.target.value))} placeholder="Karten-ID wählen" />
          <button onClick={handleVote}>Abstimmen</button>
        </div>
      )}
      {phase === 'reveal' && (
        <div>
          <h3>Ergebnis</h3>
          <button onClick={handleNextRound}>Nächste Runde</button>
        </div>
      )}
      <button onClick={onLeaveGame}>Spiel verlassen</button>
    </div>
  );
}

export default Game;
