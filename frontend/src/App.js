import React, { useState, useEffect } from 'react';
import { createGame, joinGame, getGameState, startGame, rejoinGame } from './api';
import Lobby from './Lobby';
import Game from './Game';
import VolumeControl from './components/VolumeControl';
import audioManager from './AudioManager';
import './AppLayout.css';

class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null };
    }
    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }
    componentDidCatch(error, errorInfo) {
        console.error('ErrorBoundary caught:', error, errorInfo);
    }
    render() {
        if (this.state.hasError) {
            return (
                <div style={{ color: 'red', padding: '40px', background: '#fff' }}>
                    <h2>Ein Fehler ist aufgetreten!</h2>
                    <pre>{this.state.error && this.state.error.toString()}</pre>
                </div>
            );
        }
        return this.props.children;
    }
}

function App() {
    const [gameId, setGameId] = useState('');
    const [playerName, setPlayerName] = useState('');
    const [inSession, setInSession] = useState(false); // in Lobby oder Spiel
    const [players, setPlayers] = useState([]);
    const [error, setError] = useState('');
    const [volume, setVolume] = useState(0.3);
    const [gamePhase, setGamePhase] = useState('waiting');
    const [deckId, setDeckId] = useState(null);
    const [deckName, setDeckName] = useState(null);
    // Neu: Autoplay-Status
    const [autoplayBlocked, setAutoplayBlocked] = useState(false);
    const [resuming, setResuming] = useState(false);

    // Initialisiere AudioManager beim App-Start
    useEffect(() => {
        audioManager.setVolume(volume);
        audioManager.requestBackgroundMusic('sounds/lobby.mp3', true, 2000);
        return () => { audioManager.stopTrack(500); };
    }, []);

    // Listener für Statusänderungen (Autoplay)
    useEffect(() => {
        const listener = ({ autoplayBlocked: blocked }) => {
            setAutoplayBlocked(!!blocked);
            if (!blocked) setResuming(false);
        };
        audioManager.addStatusListener(listener);
        // Initial übernehmen, falls schon gesetzt
        setAutoplayBlocked(!!audioManager.autoplayBlocked);
        return () => audioManager.removeStatusListener(listener);
    }, []);

    // Volume änderungen an AudioManager weiterleiten
    useEffect(() => {
        audioManager.setVolume(volume);
    }, [volume]);

    useEffect(() => {
        let interval;
        if (gameId) {
            const fetchState = () => {
                getGameState(gameId, playerName).then(state => {
                    if (state && state.success) {
                        setPlayers(state.players || []);
                        setGamePhase(state.phase || 'waiting');
                        if (state.deckId !== undefined) setDeckId(state.deckId);
                        if (state.deckName !== undefined) setDeckName(state.deckName);
                    }
                }).catch(err => console.error('Fehler beim Abrufen des Spielstatus:', err));
            };
            fetchState();
            interval = setInterval(fetchState, 1500);
        }
        return () => interval && clearInterval(interval);
    }, [gameId, playerName]);

    // Auto-Rejoin beim Laden
    useEffect(() => {
        try {
            const raw = localStorage.getItem('dcm_session');
            if (raw) {
                const saved = JSON.parse(raw);
                if (saved.gameId && saved.playerName) {
                    rejoinGame(saved.gameId, saved.playerName).then(r => {
                        if (r && r.success) {
                            setGameId(saved.gameId);
                            setPlayerName(saved.playerName);
                            setInSession(true);
                            if (r.phase) setGamePhase(r.phase);
                        } else {
                            // Ungültig -> löschen
                            localStorage.removeItem('dcm_session');
                        }
                    }).catch(()=>{});
                }
            }
        } catch(e) { /* ignore */ }
    }, []);

    const persistSession = (gid, name) => {
        try { localStorage.setItem('dcm_session', JSON.stringify({ gameId: gid, playerName: name })); } catch(_){}
    };
    const clearSession = () => { try { localStorage.removeItem('dcm_session'); } catch(_){} };

    const handleJoin = async (roomId, name) => {
        if (!roomId || !name) {
            setError('Bitte Raum-ID und Namen eingeben!');
            return;
        }
        try {
            // Erst versuchen zu rejoinen (falls bereits registriert)
            const attempt = await rejoinGame(roomId, name);
            if (attempt && attempt.success) {
                setGameId(roomId);
                setPlayerName(name);
                setInSession(true);
                if (attempt.phase) setGamePhase(attempt.phase);
                setError('');
                persistSession(roomId, name);
                return;
            }
            // Regulärer Join
            const res = await joinGame(roomId, name);
            if (res.success) {
                setGameId(roomId);
                setPlayerName(name);
                setInSession(true);
                setError('');
                persistSession(roomId, name);
                // Sofort Zustand abrufen um Phase (laufendes Spiel) schneller zu sehen
                getGameState(roomId, name).then(s => { if (s && s.success && s.phase) setGamePhase(s.phase); });
            } else {
                setError(res.message || 'Beitritt fehlgeschlagen');
            }
        } catch (e) {
            setError('Serverfehler');
            console.error(e);
        }
    };

    const handleCreateGame = async (name) => {
        if (!name) {
            setError('Bitte Namen eingeben!');
            return;
        }
        try {
            const res = await createGame(name);
            if (res.success) {
                setGameId(res.gameId);
                setPlayerName(name);
                setInSession(true); // Bleibt in Lobby bis Start
                setError('');
                persistSession(res.gameId, name);
            } else {
                setError(res.message || 'Start fehlgeschlagen');
            }
        } catch (e) {
            setError('Serverfehler');
            console.error(e);
        }
    };

    const handleStartGame = async () => {
        if (!gameId) return;
        const res = await startGame(gameId);
        if (!res.success) {
            setError(res.message || 'Spielstart fehlgeschlagen');
        }
    };

    const handleCreatedGame = (newGameId, name) => {
        setGameId(newGameId);
        setPlayerName(name);
        setInSession(true);
        setDeckId(null); setDeckName(null);
        setError('');
        persistSession(newGameId, name);
    };

    const handleLeaveGame = () => {
        setInSession(false);
        setGameId('');
        setPlayers([]);
        setGamePhase('waiting');
        setDeckId(null); setDeckName(null);
        audioManager.requestBackgroundMusic('sounds/lobby.mp3', true, 1000);
        clearSession();
    };

    const handleVolumeChange = (newVolume) => {
        setVolume(newVolume);
    };

    return (
        <ErrorBoundary>
            <div className="app-root">
                <div className="app-shell">
                    <header className="app-header">
                        <h1>🎨 Don't Choose Me</h1>
                        <p>Das kreative Ratespiel für Freunde & Familie</p>
                        {!inSession && !autoplayBlocked && (
                            <div className="badge-music">🎵 Lobby Musik aktiv</div>
                        )}
                        <div className="header-left">
                            <VolumeControl volume={volume} onChange={handleVolumeChange} />
                            <details className="info-tab">
                              <summary className="info-tab-summary" aria-label="Punkteverteilung anzeigen/ausblenden">ℹ️ Punkte</summary>
                              <div className="info-tab-panel" role="region" aria-label="Punkteverteilung – so funktioniert's">
                                <ul>
                                  <li><strong>Erzähler</strong>: +3 Punkte, wenn einige (aber nicht alle) die richtige Karte wählen. 0 Punkte, wenn alle oder keiner richtig liegt.</li>
                                  <li><strong>Richtige Wahl</strong>: +3 Punkte pro Spieler, der die Erzähler-Karte korrekt wählt.</li>
                                  <li><strong>Täuschpunkte</strong>: +1 Punkt pro Stimme, die auf deine (falsche) Karte fällt.</li>
                                </ul>
                                <div className="info-tab-example">
                                  Beispiel: Spieler A ist Erzähler. Wenn B und C richtig raten, D aber nicht → A +3, B +3, C +3. Bekommt D eine oder mehrere Stimmen auf seine Karte, erhält er zusätzlich +1 pro Stimme.
                                </div>
                              </div>
                            </details>
                        </div>
                        {autoplayBlocked && (
                          <div className="autoplay-cta" role="alert" aria-live="polite">
                            <button
                              className="btn autoplay-btn"
                              onClick={() => { if (!resuming) { setResuming(true); audioManager.attemptResume(); } }}
                              disabled={resuming}
                              aria-label="Musik aktivieren (Autoplay war blockiert)"
                            >{resuming ? 'Aktiviere…' : 'Musik aktivieren'}</button>
                          </div>
                        )}
                    </header>

                    <main className={`app-panel phase-${gamePhase}`}>
                        {inSession && gamePhase !== 'waiting' ? (
                            <Game
                                gameId={gameId}
                                playerName={playerName}
                                onLeaveGame={handleLeaveGame}
                                volume={volume}
                                setVolume={setVolume}
                            />
                        ) : (
                            <Lobby
                                players={players}
                                playerName={playerName}
                                gameId={gameId}
                                error={error}
                                onJoin={handleJoin}
                                onStart={gameId ? handleStartGame : handleCreateGame}
                                onLeave={handleLeaveGame}
                                onCreated={handleCreatedGame}
                                deckId={deckId}
                                deckName={deckName}
                            />
                        )}
                    </main>

                    <footer className="app-footer">
                        💡 Erzähler gibt einen Hinweis • 🃏 Andere legen passende Karten • 🗳️ Alle raten • 🏆 30 Punkte für den Sieg
                    </footer>
                </div>
            </div>
        </ErrorBoundary>
    );
}

export default App;
