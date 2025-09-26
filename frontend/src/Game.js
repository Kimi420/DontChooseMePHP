import React, { useEffect, useState, useCallback, useRef } from 'react';
import { getGameState, giveHint, chooseCard, vote, nextRound, resetMatch } from './api';
import './AppLayout.css';
import './GameStyle.css';
import audioManager from './AudioManager';

function Game({ gameId, playerName, onLeaveGame }) {
    const [gameState, setGameState] = useState(null);
    const [hintInput, setHintInput] = useState('');
    const [selectedCard, setSelectedCard] = useState(null);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');
    const [lastPhase, setLastPhase] = useState(null);
    const [votedCard, setVotedCard] = useState(null);
    const lastMusicRef = useRef(null);
    const revealDeadlineRef = useRef(null);
    const revealTimeoutRef = useRef(null);

    // Früh abgeleitete Werte für Hooks (können null sein)
    const phase = gameState?.phase;
    const meEarly = gameState?.players?.find(p => p.name === playerName);
    const isStorytellerEarly = !!meEarly?.isStoryteller;

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

    useEffect(() => {
        if (!gameState) return;
        let desiredKey = 'lobby';
        let track = 'sounds/lobby.mp3';
        if (phase === 'storytelling') { desiredKey = 'storyteller'; track = 'sounds/storyteller.mp3'; }
        else if (phase === 'voting') { desiredKey = 'voting'; track = 'sounds/voting.mp3'; }
        else if (phase === 'reveal') { desiredKey = 'lobby'; track = 'sounds/lobby.mp3'; }
        else if (phase === 'finished') { desiredKey = 'lobby'; track = 'sounds/lobby.mp3'; }

        // Nur überspringen, wenn derselbe Key aktiv ist, Musik wirklich spielt und kein Autoplay-Block vorliegt
        if (lastMusicRef.current === desiredKey && audioManager.isPlaying && audioManager.isPlaying() && !audioManager.autoplayBlocked) return;

        // Wechsel über requestBackgroundMusic, damit bei Autoplay-Block erneut versucht wird
        audioManager.requestBackgroundMusic(track, true, desiredKey === 'lobby' ? 800 : 600);

        // Optionaler Effekt beim echten Phasenwechsel
        if (lastMusicRef.current && lastMusicRef.current !== desiredKey) {
            audioManager.playEffect('sounds/phase-change.mp3', { volumeMultiplier: 0.6 }).catch(()=>{});
        }

        lastMusicRef.current = desiredKey;
    }, [gameState?.phase, phase]);

    // Handler stabil und vor Early-Return
    const handleNextRound = useCallback(async () => {
        if (phase !== 'reveal') return;
        setSending(true);
        const res = await nextRound(gameId);
        setSending(false);
        if (!res.success) setError(res.message || 'Nächste Runde fehlgeschlagen');
    }, [phase, gameId]);

    // Automatischer Rundenwechsel nach 10s in der reveal-Phase (nur Erzähler), ohne setInterval
    useEffect(() => {
        if (phase === 'reveal' && isStorytellerEarly) {
            // Reset bestehender Timeout
            if (revealTimeoutRef.current) clearTimeout(revealTimeoutRef.current);
            // Deadline in 10s setzen
            revealDeadlineRef.current = Date.now() + 10000;
            // Einmaligen Timeout starten
            revealTimeoutRef.current = setTimeout(() => {
                handleNextRound();
            }, 10000);
            // Cleanup für Phase-Wechsel
            return () => { if (revealTimeoutRef.current) clearTimeout(revealTimeoutRef.current); };
        } else {
            // Beim Verlassen der reveal-Phase aufräumen
            if (revealTimeoutRef.current) clearTimeout(revealTimeoutRef.current);
            revealTimeoutRef.current = null;
            revealDeadlineRef.current = null;
        }
    }, [phase, isStorytellerEarly, handleNextRound]);

    // Klick-Handler, der den Timeout vorher abbucht
    const handleNextRoundWithTimeout = useCallback(async () => {
        if (revealTimeoutRef.current) clearTimeout(revealTimeoutRef.current);
        revealTimeoutRef.current = null;
        revealDeadlineRef.current = null;
        await handleNextRound();
    }, [handleNextRound]);

    // Frühzeitiger Ladebildschirm NACH allen Hooks (Hooks-Reihenfolge bleibt stabil)
    if (!gameState) return <div style={{padding:20}}>Spielstatus wird geladen...</div>;

    // Ab hier nur noch reine Berechnungen/Render
    const me = gameState.players.find(p => p.name === playerName);
    const isStoryteller = me?.isStoryteller;
    const isHost = me?.id === 1; // Host hat seat 1
    const myHand = me?.cards || [];
    const submissions = gameState.selectedCards || [];
    const hasSubmitted = submissions.some(s => s.playerId === me?.id);
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
        if (gameState.phase === 'storytelling' && isStoryteller) {
            setSelectedCard(cardId);
        } else if (gameState.phase === 'selectCards' && !isStoryteller && !hasSubmitted) {
            setSending(true);
            const res = await chooseCard(gameId, playerName, cardId);
            setSending(false);
            if (!res.success) setError(res.message || 'Kartenwahl fehlgeschlagen');
            else setSelectedCard(cardId);
        }
    };

    const handleVote = async (cardId) => {
        if (isStoryteller || hasVoted || gameState.phase !== 'voting') return;
        setVotedCard(cardId);
        setSending(true);
        const res = await vote(gameId, playerName, cardId);
        setSending(false);
        if (!res.success) setError(res.message || 'Abstimmung fehlgeschlagen');
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

    const renderMixed = (interactive = true) => {
        // Für Reveal: Owner- und Vote-Mapping vorbereiten
        let ownerMap = {};
        let voteMap = {};
        if (phase === 'reveal') {
            const storytellerSeat = gameState.storytellerIndex + 1;
            if (storytellerCardId) ownerMap[storytellerCardId] = storytellerSeat;
            submissions.forEach(s => { ownerMap[s.cardId] = s.playerId; });
            votes.forEach(v => {
                if (!voteMap[v.cardId]) voteMap[v.cardId] = [];
                voteMap[v.cardId].push(v.playerId);
            });
        }
        // Mapping ID->Name für Anzeige
        const nameById = Object.fromEntries(gameState.players.map(p => [p.id, p.name]));
        return (
            <div className="mixed-grid">
                {mixedCards.map(cid => {
                    const meta = getCardMeta(cid) || { image:'', title:'Karte '+cid };
                    const isMyVote = votedCard === cid || votes.some(v => v.playerId === me?.id && v.cardId === cid);
                    const canBaseVote = interactive && phase === 'voting' && !isStoryteller && !hasVoted;
                    const isOwnSubmission = submissions.some(s => s.cardId === cid && s.playerId === me?.id);
                    const disabledSelf = phase === 'voting' && isOwnSubmission && !isStoryteller;
                    const canVote = canBaseVote && !disabledSelf;
                    const isStoryCard = cid === storytellerCardId;

                    // Reveal-Infos
                    let ownerName = null; let voterNames = []; let voterLine = '';
                    if (phase === 'reveal') {
                        const ownerId = ownerMap[cid];
                        ownerName = ownerId ? nameById[ownerId] || ownerId : 'Unbekannt';
                        voterNames = voteMap[cid] ? voteMap[cid].map(id => nameById[id] || id) : [];
                        if (voterNames.length === 0) voterLine = 'Keine Stimmen';
                        else if (voterNames.join(', ').length < 26) voterLine = voterNames.join(', ');
                        else voterLine = voterNames.length + ' Stimmen';
                    }

                    return (
                        <div key={cid}
                             className={`card-tile ${isMyVote ? 'selected' : ''} ${disabledSelf ? 'locked self-lock' : ''}`}
                             onClick={() => canVote && handleVote(cid)}
                             title={disabledSelf ? 'Eigene Karte – nicht wählbar' : meta.title}>
                            {meta.image && <img src={`/${meta.image}`} alt={meta.title} />}
                            {isMyVote && <div className="card-check">🗳</div>}
                            {disabledSelf && <div className="card-badge self-badge">Eigene</div>}
                            {phase === 'reveal' && isStoryCard && <div className="card-badge" style={{background:'rgba(255,215,0,0.75)'}}>Erzähler</div>}
                            {phase === 'reveal' && (
                                <div className="card-meta-bar">
                                    <div className={`owner-line ${isStoryCard ? 'story-owner':''}`}>👤 {ownerName || '–'}{isStoryCard ? ' (Erzähler)' : ''}</div>
                                    <div className="votes-line">🗳 {voterLine}</div>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        );
    };

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
                            <div style={{fontSize:'.55rem', letterSpacing:'.5px', opacity:.6}}>Wins: {p.wins ?? 0}</div>
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
                {phase === 'finished' && (
                    <div className="notice" style={{background:'rgba(16,185,129,0.15)',borderColor:'rgba(16,185,129,0.4)'}}>
                        {Array.isArray(gameState.winners) && gameState.winners.length>0 ? (
                            <>🏆 Sieger: {gameState.winners.map(w=>w.name + ' ('+w.score+')').join(', ')}<br/><span style={{fontSize:'.65rem'}}>Host kann ein neues Match vorbereiten.</span></>
                        ) : 'Spiel beendet'}
                    </div>
                )}
            </div>
        </div>
    );

    const phaseLabel = (ph) => {
        switch (ph) {
            case 'storytelling': return 'Hinweis geben';
            case 'selectCards': return 'Karten wählen';
            case 'voting': return 'Abstimmung';
            case 'reveal': return 'Auflösung';
            case 'finished': return 'Spiel beendet';
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

            // Countdown/Progress (nur für Erzähler sichtbar)
            const now = Date.now();
            const deadline = revealDeadlineRef.current;
            const remainMs = deadline ? Math.max(0, deadline - now) : 0;
            const remainSec = deadline ? Math.ceil(remainMs / 1000) : null;
            const doneRatio = deadline ? Math.min(1, Math.max(0, (10000 - remainMs) / 10000)) : 0;

            return (
                <div className="stack">
                    {gameState.hint && <div className="hint-banner"><span className="label">Hinweis</span>{gameState.hint}</div>}
                    <h3 style={{margin:'0 0 4px', fontSize:'.9rem', letterSpacing:'.5px', textTransform:'uppercase'}}>Auflösung</h3>

                    {isStoryteller && deadline && (
                        <div className="auto-next">
                            <div className="auto-next-header">
                                <div className="auto-next-title">Nächste Runde in {remainSec}s</div>
                                <button className="btn" disabled={sending} onClick={handleNextRoundWithTimeout}>Jetzt starten</button>
                            </div>
                            <div className="auto-next-bar" role="progressbar" aria-valuemin={0} aria-valuemax={10} aria-valuenow={remainSec || 0}>
                                <div className="auto-next-bar-fill" style={{width: `${Math.round(doneRatio*100)}%`}} />
                            </div>
                        </div>
                    )}

                    {renderMixed(false)}
                    {/* Punkteverteilung anzeigen */}
                    {Array.isArray(gameState.roundScores) && gameState.roundScores.length > 0 && (
                        <div className="reveal-details">
                            <h4 style={{margin:'8px 0 4px 0', fontSize:'.95rem'}}>Punkteverteilung dieser Runde</h4>
                            <ul className="round-score-list">
                                {gameState.roundScores.map(rs => {
                                    const pName = rs.player_name || (gameState.players.find(p => p.id === rs.game_player_id)?.name) || 'Spieler';
                                    const delta = parseInt(rs.delta_score, 10);
                                    const total = rs.total_after;
                                    const cls = 'round-score-item ' + (delta > 0 ? 'positive' : (delta < 0 ? 'negative' : ''));
                                    return (
                                        <li key={rs.game_player_id} className={cls}>
                                            <span className="rs-name">{pName}</span>
                                            <span className="rs-delta">{delta > 0 ? '+' : ''}{delta}</span>
                                            <span className="rs-total">gesamt {total}</span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}
                </div>
            );
        }
        if (phase === 'finished') {
            const winners = gameState.winners || [];
            return (
                <div className="stack" style={{alignItems:'flex-start'}}>
                    <h2 style={{margin:'4px 0 8px'}}>🏆 Spiel beendet</h2>
                    {winners.length > 0 ? (
                        <div className="notice" style={{background:'rgba(16,185,129,0.25)',borderColor:'rgba(16,185,129,0.5)'}}>
                            Sieger{winners.length>1?' (Unentschieden)':''}: {winners.map(w=>`${w.name} (${w.score})`).join(', ')}
                        </div>
                    ) : <div className="notice">Keine Gewinner ermittelt</div>}
                    <div style={{marginTop:8,width:'100%'}}>
                        <h4 style={{margin:'4px 0'}}>Endstand</h4>
                        <table className="score-table" style={{width:'100%',fontSize:'.8rem',borderCollapse:'collapse'}}>
                            <thead>
                            <tr style={{textAlign:'left'}}>
                                <th style={{padding:'4px 6px'}}>Spieler</th>
                                <th style={{padding:'4px 6px'}}>Punkte</th>
                                <th style={{padding:'4px 6px'}}>Wins</th>
                            </tr>
                            </thead>
                            <tbody>
                            {gameState.players.slice().sort((a,b)=>b.score-a.score).map(p=> (
                                <tr key={p.id} style={{background: winners.some(w=>w.name===p.name)?'rgba(16,185,129,0.15)':'transparent'}}>
                                    <td style={{padding:'4px 6px'}}>{p.name}</td>
                                    <td style={{padding:'4px 6px'}}>{p.score}</td>
                                    <td style={{padding:'4px 6px'}}>{p.wins ?? 0}</td>
                                </tr>
                            ))}
                            </tbody>
                        </table>
                    </div>
                    {isHost && (
                        <div className="stack" style={{marginTop:12}}>
                            <button className="btn" disabled={sending} onClick={async()=>{setSending(true); const r=await resetMatch(gameId, playerName); setSending(false); if(!r.success) setError(r.message||'Reset fehlgeschlagen');}}>🔄 Neues Match vorbereiten</button>
                            <div className="notice" style={{fontSize:'.65rem'}}>Nach dem Reset könnt ihr ein neues Deck wählen oder direkt starten.</div>
                        </div>
                    )}
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
            const secsLeft = revealDeadlineRef.current ? Math.max(0, Math.ceil((revealDeadlineRef.current - Date.now()) / 1000)) : null;
            const topPanelActive = !!revealDeadlineRef.current; // wenn oben aktiv, unten keine Doppelanzeige
            actions.push(
                <>
                    <button key="next" className="btn" disabled={sending} onClick={handleNextRoundWithTimeout}>➡️ Nächste Runde</button>
                    {!topPanelActive && secsLeft !== null && secsLeft > 0 && (
                        <span key="count" className="auto-next-inline">Automatisch in {secsLeft}s</span>
                    )}
                </>
            );
        }
        if (phase === 'finished' && isHost) {
            actions.push(<button key="reset" className="btn" disabled={sending} onClick={async()=>{setSending(true); const r=await resetMatch(gameId, playerName); setSending(false); if(!r.success) setError(r.message||'Reset fehlgeschlagen');}}>🔄 Reset Lobby</button>);
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

