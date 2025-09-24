import React, { useState, useEffect } from 'react';
import { createGame, joinGame, getGameState, startGame } from './api';
import Lobby from './Lobby';
import Game from './Game';
import VolumeControl from './components/VolumeControl';
import audioManager from './AudioManager';

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

    const handleLeaveGame = () => {
        setInSession(false);
        setGameId('');
        setPlayers([]);
        setGamePhase('waiting');
        audioManager.playTrack('sounds/lobby.mp3', true, 1000);
    };

    const handleVolumeChange = (newVolume) => {
        setVolume(newVolume);
    };

    return (
        <ErrorBoundary>
            <div className="App">
                {/* VolumeControl immer sichtbar machen */}
                <VolumeControl volume={volume} onChange={handleVolumeChange} />

                <div style={{
                    minHeight: '100vh',
                    background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                    padding: '20px'
                }}>
                    <div style={{ maxWidth: '800px', margin: '0 auto' }}>
                        {/* App Header */}
                        <div style={{
                            textAlign: 'center',
                            marginBottom: '40px',
                            background: 'rgba(255,255,255,0.1)',
                            padding: '30px',
                            borderRadius: '20px',
                            backdropFilter: 'blur(10px)',
                            color: 'white',
                            boxShadow: '0 8px 32px rgba(0,0,0,0.1)',
                            position: 'relative'
                        }}>
                            <h1 style={{
                                margin: '0 0 15px 0',
                                fontSize: '42px',
                                fontWeight: 'bold',
                                textShadow: '3px 3px 6px rgba(0,0,0,0.3)',
                                background: 'linear-gradient(45deg, #ffd700, #ffed4e)',
                                WebkitBackgroundClip: 'text',
                                WebkitTextFillColor: 'transparent',
                                backgroundClip: 'text'
                            }}>
                                🎨 Don't Choose Me
                            </h1>
                            <p style={{
                                margin: 0,
                                fontSize: '18px',
                                opacity: 0.9,
                                fontWeight: '300'
                            }}>
                                Das kreative Ratespiel für Freunde und Familie
                            </p>

                            {/* Audio Indicator für Startseite */}
                            {!inSession && (
                                <div style={{
                                    position: 'absolute',
                                    top: '15px',
                                    right: '15px',
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: '8px',
                                    background: 'rgba(255,255,255,0.1)',
                                    padding: '6px 12px',
                                    borderRadius: '20px',
                                    fontSize: '12px',
                                    opacity: 0.7
                                }}>
                                    🎵 Willkommensmusik
                                </div>
                            )}
                        </div>

                        {/* Main Content */}
                        <div style={{
                            background: 'rgba(255,255,255,0.1)',
                            borderRadius: '20px',
                            padding: '30px',
                            backdropFilter: 'blur(10px)',
                            boxShadow: '0 8px 32px rgba(0,0,0,0.1)',
                            border: '1px solid rgba(255,255,255,0.2)'
                        }}>
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
                                />
                            )}
                        </div>

                        {/* Footer */}
                        <div style={{
                            textAlign: 'center',
                            marginTop: '30px',
                            color: 'rgba(255,255,255,0.7)',
                            fontSize: '14px'
                        }}>
                            <p style={{ margin: 0 }}>
                                💡 Ein Erzähler gibt einen Hinweis zu seiner Karte<br/>
                                🃏 Andere wählen passende Karten aus ihrer Hand<br/>
                                🗳️ Alle raten, welche Karte vom Erzähler stammt<br/>
                                🏆 Erste Person mit 30 Punkten gewinnt!
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </ErrorBoundary>
    );
}

export default App;
