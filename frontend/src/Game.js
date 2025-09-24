import React, { useEffect, useState, useCallback } from 'react';
import { getGameState, giveHint, chooseCard, vote, nextRound } from './api';
import './AppLayout.css';

const SOUND_PATH = 'frontend/sounds/';

function Game({ gameId, playerName, onLeaveGame }) {
  const [gameState, setGameState] = useState(null);
  const [hintInput, setHintInput] = useState('');
  const [selectedCard, setSelectedCard] = useState(null);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  const [lastPhase, setLastPhase] = useState(null);
  const [votedCard, setVotedCard] = useState(null);

  const fetchState = useCallback(() => {
    getGameState(gameId, playerName).then(state => {
      if (state && state.success) {
        setGameState(state);
      }
    }).catch(() => {});
  }, [gameId, playerName]);

  useEffect(() => {
    fetchState();
    const id = setInterval(fetchState, 1200);
    return () => clearInterval(id);
  }, [fetchState]);

  useEffect(() => {
    if (!gameState) return;
    if (lastPhase && lastPhase !== gameState.phase) {
      // Phasewechsel Reset
      setError('');
      setSelectedCard(null);
      setVotedCard(null);
      if (gameState.phase === 'storytelling') {
        setHintInput('');
      }
    }
    setLastPhase(gameState.phase);
  }, [gameState, lastPhase]);

  if (!gameState) return <div style={{padding:20}}>Spielstatus wird geladen...</div>;

  const phase = gameState.phase;
  const me = gameState.players.find(p => p.name === playerName);
  const isStoryteller = me?.isStoryteller;
  const myHand = me?.cards || [];
  const submissions = gameState.selectedCards || [];
  const hasSubmitted = submissions.some(s => s.playerId === me?.id);
  const allSubmitted = gameState.players.filter(p => !p.isStoryteller).every(p => submissions.some(s => s.playerId === p.id));
  const votes = gameState.votes || [];
  const hasVoted = votes.some(v => v.playerId === me?.id);

  const getCardMeta = (id) => (gameState.cardData || []).find(c => parseInt(c.id) === parseInt(id));

  /* --- Aktionen --- */
  const handleConfirmHint = async () => {
    if (!hintInput.trim()) { setError('Bitte einen Hinweis eingeben.'); return; }
    if (!selectedCard) { setError('Bitte eine Karte auswählen.'); return; }
    setSending(true);
    const res = await giveHint(gameId, playerName, selectedCard, hintInput.trim());
    setSending(false);
    if (!res.success) setError(res.message || 'Hinweis fehlgeschlagen');
  };

  const handleSelectHandCard = async (cardId) => {
    if (phase === 'storytelling' && isStoryteller) {
      setSelectedCard(cardId);
    } else if (phase === 'selectCards' && !isStoryteller && !hasSubmitted) {
      setSending(true);
      const res = await chooseCard(gameId, playerName, cardId);
      setSending(false);
      if (!res.success) setError(res.message || 'Kartenwahl fehlgeschlagen');
      else setSelectedCard(cardId);
    }
  };

  const handleVote = async (cardId) => {
    if (isStoryteller || hasVoted || phase !== 'voting') return;
    setVotedCard(cardId);
    setSending(true);
    const res = await vote(gameId, playerName, cardId);
    setSending(false);
    if (!res.success) setError(res.message || 'Abstimmung fehlgeschlagen');
  };

  const handleNextRound = async () => {
    if (phase !== 'reveal') return;
    setSending(true);
    const res = await nextRound(gameId);
    setSending(false);
    if (!res.success) setError(res.message || 'Nächste Runde fehlgeschlagen');
  };

  /* --- Karten Grids --- */
  const renderHand = (opts = {}) => (
    <div className="hand-grid">
      {myHand.map(cid => {
        const meta = getCardMeta(cid) || { image: '', title: 'Karte '+cid };
        const selectable = opts.forceLocked ? false : (phase === 'storytelling' && isStoryteller) || (phase === 'selectCards' && !isStoryteller && !hasSubmitted);
        const isSel = selectedCard === cid;
        return (
          <div key={cid}
               className={`card-tile ${isSel ? 'selected' : ''} ${!selectable ? 'locked' : ''}`}
               onClick={() => selectable && handleSelectHandCard(cid)}
               title={meta.title}>
            {meta.image && <img src={`/${meta.image}`} alt={meta.title} />}
            {isSel && selectable && <div className="card-check">✔</div>}
            {hasSubmitted && !isStoryteller && isSel && <div className="card-check">✔</div>}
          </div>
        );
      })}
    </div>
  );

  const mixedCards = (phase === 'voting' || phase === 'reveal') ? (gameState.mixedCards || []) : [];
  const storytellerCardId = gameState.storytellerCard;

  const renderMixed = (interactive = true) => (
    <div className="mixed-grid">
      {mixedCards.map(cid => {
        const meta = getCardMeta(cid) || { image:'', title:'Karte '+cid };
        const isMyVote = votedCard === cid || votes.some(v => v.playerId === me?.id && v.cardId === cid);
        const canBaseVote = interactive && phase === 'voting' && !isStoryteller && !hasVoted;
        // Eigene eingereichte Karte identifizieren (Storyteller-Karte zählt nicht, da Erzähler nicht abstimmt)
        const isOwnSubmission = submissions.some(s => s.cardId === cid && s.playerId === me?.id);
        const disabledSelf = phase === 'voting' && isOwnSubmission && !isStoryteller; // darf nicht für eigene Karte stimmen
        const canVote = canBaseVote && !disabledSelf;
        return (
          <div key={cid}
               className={`card-tile ${isMyVote ? 'selected' : ''} ${disabledSelf ? 'locked self-lock' : ''}`}
               onClick={() => canVote && handleVote(cid)}
               title={disabledSelf ? 'Eigene Karte – nicht wählbar' : meta.title}>
            {meta.image && <img src={`/${meta.image}`} alt={meta.title} />}
            {isMyVote && <div className="card-check">🗳</div>}
            {disabledSelf && <div className="card-badge self-badge">Eigene</div>}
            {phase === 'reveal' && cid === storytellerCardId && <div className="card-badge" style={{background:'rgba(255,215,0,0.75)'}}>Erzähler</div>}
          </div>
        );
      })}
    </div>
  );

  /* --- Sidebar: Spieler & Status --- */
  const renderPlayers = () => (
    <div className="sidebar-section">
      <h3>Spieler</h3>
      <div className="player-grid" style={{gridTemplateColumns:'repeat(auto-fill,minmax(120px,1fr))'}}>
        {gameState.players.map(p => {
          const waitingSelect = (phase === 'selectCards' && !p.isStoryteller && !p.hasSelectedCard);
          const doneSelect = (phase === 'selectCards' && !p.isStoryteller && p.hasSelectedCard);
          const voted = (phase === 'voting' || phase === 'reveal') && votes.some(v => v.playerId === p.id);
          return (
            <div key={p.id} className="player-card" style={{padding:'10px 8px'}}>
              <div style={{fontSize:'.95rem', fontWeight: p.name===playerName ? 600:500}}>{p.isStoryteller ? '👑 ' : '🎮 '}{p.name}</div>
              <div style={{fontSize:'.65rem', letterSpacing:'.5px', opacity:.85}}>Punkte: {p.score}</div>
              {p.isStoryteller && <div className="role">Erzähler</div>}
              {waitingSelect && <div className="phase-tag" style={{background:'#d97706'}}>Wählt…</div>}
              {doneSelect && <div className="phase-tag" style={{background:'#059669'}}>Bereit</div>}
              {phase === 'voting' && !p.isStoryteller && !voted && p.name===playerName && <div className="phase-tag" style={{background:'#7c3aed'}}>Stimme</div>}
              {voted && <div className="phase-tag" style={{background:'#2563eb'}}>Abgestimmt</div>}
            </div>
          );
        })}
      </div>
    </div>
  );

  const renderPhaseInfo = () => (
    <div className="sidebar-section">
      <h3>Phase</h3>
      <div style={{display:'flex', flexDirection:'column', gap:8}}>
        <div style={{fontSize:'.85rem', display:'flex', flexWrap:'wrap', gap:8, alignItems:'center'}}>
          <strong>{phaseLabel(phase)}</strong>
          <span className="phase-tag">{phase}</span>
        </div>
        {gameState.hint && <div style={{fontSize:'.8rem', lineHeight:1.3}}>Hinweis: <strong>{gameState.hint}</strong></div>}
        {isStoryteller && phase === 'storytelling' && <div className="notice">Gib einen Hinweis & wähle eine Karte</div>}
        {!isStoryteller && phase === 'storytelling' && <div className="notice">Warte auf den Hinweis…</div>}
        {phase === 'selectCards' && !isStoryteller && !hasSubmitted && <div className="notice">Wähle eine Karte aus deiner Hand</div>}
        {phase === 'voting' && !isStoryteller && !hasVoted && <div className="notice">Welche Karte gehört dem Erzähler?</div>}
        {phase === 'reveal' && <div className="notice">Ergebnis ansehen & nächste Runde</div>}
      </div>
    </div>
  );

  const phaseLabel = (ph) => {
    switch (ph) {
      case 'storytelling': return 'Hinweis geben';
      case 'selectCards': return 'Karten wählen';
      case 'voting': return 'Abstimmung';
      case 'reveal': return 'Auflösung';
      default: return ph;
    }
  };

  /* --- Hauptbereich je Phase --- */
  const renderMainPhase = () => {
    if (phase === 'storytelling') {
      if (isStoryteller) {
        return (
          <div className="stack">
            {gameState.hint && (
              <div className="hint-banner"><span className="label">Hinweis</span>{gameState.hint}</div>
            )}
            <div className="hint-form">
              <input
                value={hintInput}
                onChange={e => setHintInput(e.target.value)}
                maxLength={60}
                placeholder="Hinweis eingeben…"
                disabled={sending}
              />
              <div style={{fontSize:'.7rem', opacity:.6}}>Kurzer, nicht zu offensichtlicher Hinweis (max. 60 Zeichen)</div>
            </div>
            <div>
              <h3 style={{margin:'4px 0 8px', fontSize:'.9rem', letterSpacing:'.5px', textTransform:'uppercase'}}>Deine Hand</h3>
              {renderHand()}
            </div>
          </div>
        );
      }
      // Nicht-Erzähler sehen ihre (gesperrte) Hand bereits
      return (
        <div className="stack">
          {gameState.hint && (
            <div className="hint-banner"><span className="label">Hinweis</span>{gameState.hint}</div>
          )}
          {!gameState.hint && <div className="notice">Der Erzähler formuliert einen Hinweis…</div>}
          <div>
            <h3 style={{margin:'4px 0 8px', fontSize:'.9rem', letterSpacing:'.5px', textTransform:'uppercase'}}>Deine Hand (Vorschau)</h3>
            {renderHand({forceLocked:true})}
          </div>
        </div>
      );
    }
    if (phase === 'selectCards') {
      if (isStoryteller) {
        return <div className="stack">{gameState.hint && <div className="hint-banner"><span className="label">Hinweis</span>{gameState.hint}</div>}<div style={{padding:'8px 4px'}}>Warten bis alle Spieler eine Karte abgelegt haben… ({submissions.length}/{gameState.players.length -1})</div></div>;
      }
      return (
        <div className="stack">
          {gameState.hint && <div className="hint-banner"><span className="label">Hinweis</span>{gameState.hint}</div>}
          <div>
            <h3 style={{margin:'4px 0 8px', fontSize:'.9rem', letterSpacing:'.5px', textTransform:'uppercase'}}>Wähle eine Karte</h3>
            {renderHand()}
          </div>
          {hasSubmitted && <div className="notice">✅ Karte übermittelt – warte auf die anderen</div>}
        </div>
      );
    }
    if (phase === 'voting') {
      return (
        <div className="stack">
          {gameState.hint && <div className="hint-banner"><span className="label">Hinweis</span>{gameState.hint}</div>}
          <h3 style={{margin:'0 0 4px', fontSize:'.9rem', letterSpacing:'.5px', textTransform:'uppercase'}}>Abstimmung</h3>
          {renderMixed(true)}
          {hasVoted && <div className="notice">🗳 Stimme abgegeben</div>}
        </div>
      );
    }
    if (phase === 'reveal') {
      const meta = storytellerCardId ? getCardMeta(storytellerCardId) : null;

      // Karte -> Owner bestimmen
      const storytellerSeat = gameState.storytellerIndex + 1;
      const ownerMap = {};
      if (storytellerCardId) ownerMap[storytellerCardId] = storytellerSeat;
      submissions.forEach(s => { ownerMap[s.cardId] = s.playerId; });

      // Card -> Voter Liste
      const voteMap = {};
      votes.forEach(v => {
        if (!voteMap[v.cardId]) voteMap[v.cardId] = [];
        voteMap[v.cardId].push(v.playerId);
      });

      // Helfer: Name zu ID
      const nameById = Object.fromEntries(gameState.players.map(p => [p.id, p.name]));

      return (
        <div className="stack">
          {gameState.hint && <div className="hint-banner"><span className="label">Hinweis</span>{gameState.hint}</div>}
          <h3 style={{margin:'0 0 4px', fontSize:'.9rem', letterSpacing:'.5px', textTransform:'uppercase'}}>Auflösung</h3>
          {renderMixed(false)}
          {storytellerCardId && (
            <div style={{marginTop:4, fontSize:'.8rem'}}>Erzählerkarte war: <strong>{meta?.title || storytellerCardId}</strong></div>
          )}
          <div className="reveal-details">
            {mixedCards.map(cid => {
              const ownerId = ownerMap[cid];
              const ownerName = ownerId ? nameById[ownerId] : 'Unbekannt';
              const voterIds = voteMap[cid] || [];
              const isStoryCard = cid === storytellerCardId;
              return (
                <div key={cid} className="reveal-card-row">
                  <div style={{display:'flex',justifyContent:'space-between',alignItems:'center'}}>
                    <strong style={{fontSize:'.85rem'}}>{getCardMeta(cid)?.title || ('Karte '+cid)}</strong>
                    <div className="meta">
                      <span className={`reveal-chip owner`}>👤 {ownerName}{isStoryCard ? ' (Erzähler)' : ''}</span>
                      {isStoryCard && <span className="reveal-chip story">Hinweis-Ziel</span>}
                      {voterIds.length === 0 && <span className="reveal-chip">Keine Stimmen</span>}
                      {voterIds.length > 0 && (
                        <span className={`reveal-chip ${isStoryCard ? 'good':''}`}>🗳 {voterIds.map(id=>nameById[id]).join(', ')}</span>
                      )}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      );
    }
    return null;
  };

  /* --- Bottom Action Buttons --- */
  const renderActions = () => {
    const actions = [];
    if (phase === 'storytelling' && isStoryteller) {
      actions.push(<button key="confirm" className="btn" disabled={!hintInput.trim() || !selectedCard || sending} onClick={handleConfirmHint}>💬 Hinweis bestätigen</button>);
    }
    if (phase === 'selectCards' && !isStoryteller && !hasSubmitted) {
      actions.push(<button key="info" className="btn outline" disabled>Wähle oben eine Karte</button>);
    }
    if (phase === 'reveal' && isStoryteller) {
      actions.push(<button key="next" className="btn" disabled={sending} onClick={handleNextRound}>➡️ Nächste Runde</button>);
    }
    actions.push(<button key="leave" className="btn danger" onClick={onLeaveGame}>🚪 Verlassen</button>);
    return actions;
  };

  return (
    <div className={`game-layout phase-${phase}`}>
      <aside className="sidebar">
        {renderPhaseInfo()}
        {renderPlayers()}
        {error && <div className="alert">⚠️ {error}</div>}
      </aside>
      <section className="stack" style={{minHeight:400}}>
        {renderMainPhase()}
      </section>
      <div className="bottom-bar">
        <div className="bottom-bar-inner">
          {renderActions()}
        </div>
      </div>
    </div>
  );
}

export default Game;
