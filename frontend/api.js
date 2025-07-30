import config from './config.json';

// API-Client für die Kommunikation mit dem PHP-Backend
const API_URL = config.API_URL; // Passe ggf. den Pfad an

export async function createGame(playerName) {
  const res = await fetch(`${API_URL}/Lobby.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ playerName })
  });
  return res.json();
}

export async function joinGame(gameId, playerName) {
  const res = await fetch(`${API_URL}/Lobby.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ gameId, playerName })
  });
  return res.json();
}

export async function getGameState(gameId) {
  const res = await fetch(`${API_URL}/Game.php?gameId=${gameId}`);
  return res.json();
}

export async function giveHint(gameId, playerName, cardId, hint) {
  const res = await fetch(`${API_URL}/Game.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ gameId, playerName, cardId, hint, action: 'giveHint' })
  });
  return res.json();
}

export async function chooseCard(gameId, playerName, cardId) {
  const res = await fetch(`${API_URL}/Game.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ gameId, playerName, cardId, action: 'chooseCard' })
  });
  return res.json();
}

export async function vote(gameId, playerName, cardId) {
  const res = await fetch(`${API_URL}/Game.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ gameId, playerName, cardId, action: 'vote' })
  });
  return res.json();
}

export async function nextRound(gameId) {
  const res = await fetch(`${API_URL}/Game.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ gameId, action: 'nextRound' })
  });
  return res.json();
}
