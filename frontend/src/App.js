import React, { useState, useEffect } from 'react';
import { createGame, joinGame, getGameState, startGame } from './api';
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

    // Initialisiere AudioManager beim App-Start
    useEffect(() => {
        audioManager.setVolume(volume);

        // Auto-start Lobby-Musik mit reduzierter Lautstärke
        audioManager.playTrack('sounds/lobby.mp3', true, 2000);

        // Cleanup bei App-Beendigung
        return () => {
            audioManager.stopTrack(500);
        };
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

    const handleJoin = async (roomId, name) => {
        if (!roomId || !name) {
            setError('Bitte Raum-ID und Namen eingeben!');
            return;
        }
        try {
            const res = await joinGame(roomId, name);
            if (res.success) {
                setGameId(roomId);
                setPlayerName(name);
                setInSession(true);
                setError('');
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
    };

    const handleLeaveGame = () => {
        setInSession(false);
        setGameId('');
        setPlayers([]);
        setGamePhase('waiting');
        setDeckId(null); setDeckName(null);
        audioManager.playTrack('sounds/lobby.mp3', true, 1000);
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
                        {!inSession && (
                            <div className="badge-music">🎵 Lobby Musik aktiv</div>
                        )}
                        <div style={{position:'absolute',left:12,top:12}}>
                            <VolumeControl volume={volume} onChange={handleVolumeChange} />
                        </div>
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
