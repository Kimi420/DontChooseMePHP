import React, { useEffect, useState } from 'react';
import { getGameState, giveHint, chooseCard, vote, nextRound } from './api';
import './GameStyle.css';
import audioManager from './AudioManager';

const SOUND_PATH = 'frontend/sounds/';

function Game({ gameId, playerName, onLeaveGame, volume, setVolume }) {
  const [gameState, setGameState] = useState(null);
  const [hint, setHint] = useState('');
  const [selectedCard, setSelectedCard] = useState(null); // Für Storyteller & Auswahl
  const [submittedSelection, setSubmittedSelection] = useState(false);
  const [votedCard, setVotedCard] = useState(null);
  const [error, setError] = useState('');
  const [lastPhase, setLastPhase] = useState(null);

  useEffect(() => {
    const interval = setInterval(() => {
      getGameState(gameId, playerName).then(setGameState).catch(() => {});
    }, 1500);
    return () => clearInterval(interval);
  }, [gameId, playerName]);

  useEffect(() => {
    if (gameState) {
      if (lastPhase && lastPhase !== gameState.phase) {
        audioManager.playEffect(`${SOUND_PATH}phase-change.mp3`);
        setError('');
        setSelectedCard(null);
        setSubmittedSelection(false);
        setVotedCard(null);
      }
      setLastPhase(gameState.phase);
      const isStoryteller = gameState.players.find(p => p.name === playerName)?.isStoryteller;
      if (gameState.phase === 'storytelling' && isStoryteller) {
        audioManager.playEffect(`${SOUND_PATH}storyteller.mp3`);
      }
      // Hint aus State anzeigen (falls gesetzt)
      if (gameState.hint && gameState.hint !== hint) {
        // Storyteller behält seine lokale Eingabe nicht zwingend
      }
    }
  }, [gameState]);

  if (!gameState) return <div>Spiel wird geladen...</div>;

  const phase = gameState.phase;
  const me = gameState.players.find(p => p.name === playerName);
  const isStoryteller = me?.isStoryteller;
  const myHand = me?.cards || [];

  // Hilfsfunktionen
  const getCardMeta = (cardId) => {
    if (!gameState.cardData) return null;
    return gameState.cardData.find(c => parseInt(c.id) === parseInt(cardId));
  };

  const handleGiveHint = async () => {
    if (!hint.trim()) {
      setError('Bitte einen Hinweis eingeben.');
      return;
    }
    if (!selectedCard) {
      setError('Bitte eine Karte auswählen.');
      return;
    }
    const res = await giveHint(gameId, playerName, selectedCard, hint.trim());
    if (!res.success) {
      setError('Hinweis konnte nicht gesendet werden (Karte muss aus deiner Hand sein).');
    } else {
      setError('');
    }
  };

  const handleChooseCard = async (cardId) => {
    if (submittedSelection) return; // schon gesendet
    setSelectedCard(cardId);
    const res = await chooseCard(gameId, playerName, cardId);
    if (!res.success) {
      setError('Karte konnte nicht gewählt werden (muss aus deiner Hand sein oder bereits gewählt).');
    } else {
      setSubmittedSelection(true);
      setError('');
    }
  };

  const handleVote = async (cardId) => {
    if (votedCard) return;
    setVotedCard(cardId);
    const res = await vote(gameId, playerName, cardId);
    if (!res.success) {
      setError('Abstimmung fehlgeschlagen (eigene Karte? oder schon abgestimmt).');
    } else {
      setError('');
    }
  };

  const handleNextRound = async () => {
    await nextRound(gameId);
  };

  // UI Helfer
  const renderHand = () => {
    if (!myHand.length) return <div style={{ opacity: 0.7 }}>Keine Karten (Deck leer?)</div>;
    return (
      <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap', marginTop: '10px' }}>
        {myHand.map(id => {
          const meta = getCardMeta(id) || { image: '', title: 'Karte ' + id };
          const selected = id === selectedCard;
          return (
            <div key={id}
                 onClick={() => {
                   if (phase === 'storytelling' && isStoryteller) {
                     setSelectedCard(id);
                   } else if (phase === 'selectCards' && !isStoryteller && !submittedSelection) {
                     handleChooseCard(id);
                   }
                 }}
                 style={{
                   width: '90px',
                   height: '130px',
                   background: `url(/${meta.image}) center/cover no-repeat`,
                   border: selected ? '3px solid #ffd700' : '2px solid #fff4',
                   borderRadius: '8px',
                   cursor: (phase === 'storytelling' && isStoryteller) || (phase === 'selectCards' && !isStoryteller && !submittedSelection) ? 'pointer' : 'default',
                   position: 'relative',
                   boxShadow: selected ? '0 0 8px #ffd700aa' : '0 2px 6px rgba(0,0,0,0.4)'
                 }}
                 title={meta.title}>
              {submittedSelection && !isStoryteller && selected && (
                <div style={{
                  position: 'absolute', top: 4, right: 4,
                  background: '#28a745', color: '#fff', fontSize: '10px', padding: '2px 4px', borderRadius: '4px'
                }}>✔</div>
              )}
            </div>
          );
        })}
      </div>
    );
  };

  const renderStorytelling = () => {
    if (!isStoryteller) {
      return <div>Der Erzähler wählt eine Karte und gibt einen Hinweis...</div>;
    }
    return (
      <div style={{ marginTop: '10px' }}>
        <h3>Du bist der Erzähler</h3>
        <div>
          <input
            value={hint}
            onChange={e => setHint(e.target.value)}
            placeholder="Hinweis eingeben..."
            style={{ padding: '6px', width: '250px', marginRight: '8px' }}
          />
        </div>
        <div style={{ marginTop: '8px' }}>Wähle eine Karte aus deiner Hand:</div>
        {renderHand()}
        <button
          style={{ marginTop: '12px' }}
          disabled={!hint.trim() || !selectedCard}
          onClick={handleGiveHint}
        >Hinweis bestätigen</button>
      </div>
    );
  };

  const renderSelectCards = () => {
    if (isStoryteller) {
      return <div>Warten bis alle Spieler eine Karte ausgewählt haben...</div>;
    }
    return (
      <div style={{ marginTop: '10px' }}>
        <h3>Karte auswählen</h3>
        <div>Tippe auf eine deiner Karten – sie wird sofort gesendet.</div>
        {renderHand()}
        {submittedSelection && <div style={{ color: '#7dff9c', marginTop: '8px' }}>Karte gesendet ✔</div>}
      </div>
    );
  };

  const renderVoting = () => {
    const cards = gameState.mixedCards || [];
    if (!cards.length) return <div>Karten werden vorbereitet...</div>;
    return (
      <div style={{ marginTop: '10px' }}>
        <h3>Abstimmen – wähle die Karte des Erzählers</h3>
        <div style={{ display: 'flex', gap: '12px', flexWrap: 'wrap' }}>
          {cards.map(id => {
            const meta = getCardMeta(id) || { image: '', title: 'Karte ' + id };
            const voted = id === votedCard;
            return (
              <div key={id}
                   onClick={() => { if (!isStoryteller && !votedCard) handleVote(id); }}
                   style={{
                     width: '110px', height: '155px', background: `url(/${meta.image}) center/cover no-repeat`,
                     border: voted ? '3px solid #28a745' : '2px solid #fff6',
                     borderRadius: '10px', cursor: (!isStoryteller && !votedCard) ? 'pointer' : 'default',
                     position: 'relative', boxShadow: voted ? '0 0 10px #28a745aa' : '0 2px 6px rgba(0,0,0,0.4)'
                   }}
                   title={meta.title}>
                {voted && <div style={{ position: 'absolute', top: 4, right: 4, background: '#28a745', color:'#fff', fontSize:'11px', padding: '2px 4px', borderRadius:'4px' }}>Deine Stimme</div>}
              </div>
            );
          })}
        </div>
        {isStoryteller && <div style={{ marginTop: '8px', opacity:0.7 }}>Du stimmst nicht ab.</div>}
      </div>
    );
  };

  const renderReveal = () => {
    return (
      <div style={{ marginTop: '10px' }}>
        <h3>Ergebnis</h3>
        {gameState.hint && <div>Hinweis war: <strong>{gameState.hint}</strong></div>}
        {gameState.storytellerCard && (
          <div style={{ marginTop: '8px' }}>
            Erzählerkarte:
            <div style={{
              width: '120px', height: '170px', marginTop: '6px',
              background: `url(/${(getCardMeta(gameState.storytellerCard)?.image) || ''}) center/cover no-repeat`,
              border: '3px solid #ffd700', borderRadius: '10px'
            }}/>
          </div>
        )}
        <button style={{ marginTop: '14px' }} onClick={handleNextRound}>Nächste Runde</button>
      </div>
    );
  };

  return (
    <div className="game-container">
      <h2>Spiel: {gameId}</h2>
      <div>Phase: {phase}</div>
      <div>Hint: {gameState.hint ? <strong>{gameState.hint}</strong> : (phase === 'storytelling' ? '—' : 'Vergeben')}</div>
      <div style={{ marginTop: '10px' }}>Spieler:
        <ul>
          {gameState.players.map(p => (
            <li key={p.id} style={{ fontWeight: p.name === playerName ? 'bold' : 'normal' }}>
              {p.name} {p.isStoryteller ? '(Erzähler)' : ''} – Punkte: {p.score}
              {phase === 'selectCards' && !p.isStoryteller && (
                p.hasSelectedCard ? ' ✅' : ' ⏳'
              )}
            </li>
          ))}
        </ul>
      </div>

      {error && <div className="game-error" style={{ marginTop: '8px' }}>⚠️ {error}</div>}

      {phase === 'storytelling' && renderStorytelling()}
      {phase === 'selectCards' && renderSelectCards()}
      {phase === 'voting' && renderVoting()}
      {phase === 'reveal' && renderReveal()}

      {phase !== 'storytelling' && isStoryteller && !gameState.hint && (
        <div style={{ color: '#ffc107', marginTop: '10px' }}>Du musst zuerst einen Hinweis geben.</div>
      )}

      {phase === 'storytelling' && !isStoryteller && (
        <div style={{ marginTop: '10px', opacity: 0.8 }}>Warten auf Hinweis...</div>
      )}

      <div style={{ marginTop: '24px' }}>
        <button onClick={onLeaveGame}>Spiel verlassen</button>
      </div>
    </div>
  );
}

export default Game;
