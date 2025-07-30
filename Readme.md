# Don't Choose Me - Card Game

A digital implementation of the popular card game "Dixit" where players use their imagination to give creative hints about picture cards.

## 🎮 Game Overview

Don't Choose Me is a multiplayer storytelling game where:
- One player acts as the storyteller and gives a creative hint about their chosen card
- Other players select cards from their hand that best match the hint
- All selected cards are shuffled and presented for voting
- Players vote for which card they think belongs to the storyteller
- Points are awarded based on voting results

## 🏗️ Project Structure

```
PicMe/
├── frontend/                 # React frontend application
│   ├── public/
│   │   ├── images/          # Card images storage
│   │   └── index.html
│   ├── src/
│   │   ├── components/      # React components
│   │   │   ├── App.js      # Main application component
│   │   │   ├── Lobby.js    # Game lobby management
│   │   │   ├── Game.js     # Main game logic
│   │   │   └── Card.js     # Card display component
│   │   └── index.js        # Application entry point
│   └── package.json
├── backend/                 # Node.js backend server
│   ├── server.js           # Express server and game logic
│   ├── cards.json          # Card database
│   ├── AddCards.js         # Card import utility
│   └── import/             # Directory for new card imports
└── README.md
```

## 🚀 Installation & Setup

### Prerequisites
- Node.js (v14 or higher)
- npm or yarn package manager

### Local Development Setup

#### Backend Setup
```bash
cd backend
npm install
node server.js
```
The backend server will start on port 3001.

#### Frontend Setup
```bash
cd frontend
npm install
npm start
```
The frontend will start on port 3000.

### Production Deployment on Render

#### Root Directory
```bash
./backend
```

#### Build Command
```bash
npm run render:build
```

#### Start Command
```bash
npm run render:start
```

#### Environment Variables
- Set `NODE_ENV=production` for production builds
- The server will automatically serve static files in production mode
- Frontend build files should be placed in the `backend/build` directory

## 🎯 Game Rules

### Setup
- Minimum 3 players required to start a game
- Each player receives 6 cards at the beginning
- Players take turns being the storyteller

### Gameplay Phases

1. **Storytelling Phase**
    - The storyteller chooses a card from their hand
    - They provide a creative hint (word, phrase, or story)
    - The hint should be neither too obvious nor too obscure

2. **Card Selection Phase**
    - Other players choose a card from their hand that matches the hint
    - Players cannot see which cards others have chosen

3. **Voting Phase**
    - All chosen cards are shuffled and displayed
    - Players (except the storyteller) vote for the card they think belongs to the storyteller
    - Players cannot vote for their own card

4. **Scoring Phase**
    - Points are awarded based on voting results

### Scoring System

**Storyteller Scoring:**
- Gets 3 points if some (but not all) players guess correctly
- Gets 0 points if everyone or no one guesses correctly

**Other Players:**
- Get 3 points for correctly guessing the storyteller's card
- Get 1 point for each vote their card receives from other players

**Winning:**
- First player to reach 30 points wins the game

## 🔧 Technical Features

### Frontend (React)
- **Responsive Design**: Works on desktop and mobile devices
- **Real-time Updates**: Polling-based game state synchronization
- **Interactive UI**: Smooth animations and hover effects
- **Game Phases**: Distinct interfaces for each game phase
- **Lobby System**: Room-based multiplayer with custom room codes

### Backend (Node.js/Express)
- **REST API**: Game state management via HTTP endpoints
- **Memory Storage**: In-memory game state (resets on server restart)
- **Room Management**: Multiple concurrent games supported
- **Card Management**: Dynamic card loading and validation
- **Game Logic**: Complete implementation of scoring rules

## 📁 Card Management

### Adding New Cards
1. Place image files in `backend/import/` directory
2. Run the card import utility:
   ```bash
   cd backend
   node AddCards.js
   ```
3. Images will be moved to `frontend/public/images/`
4. Card entries will be added to `cards.json`

### Supported Formats
- JPG, JPEG, PNG image files
- Recommended size: 300x200 pixels
- Files are automatically renamed and cataloged

## 🚀 Upcoming Features

### Next Development Phase
The following features are planned for the next development iteration:

#### ✨ UI/UX Improvements
- **Modern Design Language**: Updated visual design with improved aesthetics
- **Enhanced Animations**: Smooth transitions between game phases
- **Responsive Mobile Design**: Optimized interface for mobile devices
- **Accessibility Features**: Better contrast, keyboard navigation, screen reader support
- **Loading States**: Improved feedback during game state changes
- **Error Handling**: Better user feedback for connection issues

## 🌐 API Endpoints

### Game Management
- `POST /api/game` - Main game endpoint for all actions
    - `joinLobby` - Join a game room
    - `startGame` - Start the game (requires 3+ players)
    - `getState` - Get current game state
    - `giveHint` - Storyteller gives hint and selects card
    - `chooseCard` - Player selects card during selection phase
    - `vote` - Player votes for a card
    - `nextRound` - Continue to next round
    - `restart` - Restart the game

### Card Management
- `GET /api/cards` - Retrieve all available cards

## 🎨 UI Components

### Lobby Component
- Player name and room ID input
- Quick join buttons for common room names
- Real-time player list with lobby leader indication
- Minimum player count validation

### Game Component
- Dynamic phase-based UI rendering
- Interactive card selection and voting
- Real-time scoreboard
- Detailed reveal phase with voting results
- Game end screen with final rankings

### Card Component
- Responsive card display
- Interactive hover effects
- Selection state visualization
- Fallback image handling

## 🔄 Game Flow

```
Lobby → Storytelling → Card Selection → Voting → Reveal → Next Round
  ↑                                                          ↓
  ←←←←←←←←←←←←← Game End (when player reaches 30 points) ←←←←←
```

## 🐛 Troubleshooting

### Common Issues

**Cards not displaying:**
- Check if images exist in `frontend/public/images/`
- Verify `cards.json` has correct image paths
- Run `node AddCards.js` to reimport cards

**Game state not updating:**
- Check browser console for network errors
- Verify backend server is running on port 3001
- Check if API proxy is configured correctly

**Players can't join lobby:**
- Verify room ID format (alphanumeric, hyphens, underscores only)
- Check player name format (letters, numbers, spaces only)
- Ensure minimum/maximum length requirements are met

## 📝 Development Notes

### State Management
- Game state is stored in memory on the backend
- Frontend polls every 2 seconds for updates
- No persistence - games reset on server restart

### Security Considerations
- Input validation on both client and server
- No authentication system (intended for private use)
- Room codes provide basic access control

### Performance
- Image optimization recommended for production
- Consider implementing WebSocket for real-time updates
- Database storage for game persistence

## 🚀 Future Enhancements

### Immediate Priorities
- [x] Basic game functionality
- [x] Multi-room support
- [ ] **Multiple card deck system**
- [ ] **Lobby deck selection interface**
- [ ] **UI/UX modernization**

### Medium-term Goals
- [ ] WebSocket implementation for real-time updates
- [ ] Database persistence for game history
- [ ] Player authentication and profiles
- [ ] Custom deck upload interface
- [ ] Advanced game statistics and analytics

### Long-term Vision
- [ ] Mobile app development
- [ ] AI players for single-player mode
- [ ] Tournament and competitive modes
- [ ] Community-generated content platform
- [ ] Cross-platform synchronization

## 📄 License & Disclaimer

### Open Source Project
This project is **open source** and released under the MIT License. You are free to:
- Use the code for personal and educational purposes
- Modify and distribute the code
- Contribute to the project development

### Non-Commercial Use
**Important**: This is **NOT a commercial product**. Don't Choose Me is developed as:
- An educational project to learn web development
- A personal implementation for private use with friends and family
- A demonstration of game development techniques

### Card Image Rights
**We do NOT own the rights to any card images used in this project.**
- Card images are used for demonstration and educational purposes only
- Users must ensure they have proper rights/licenses for any images they import
- Commercial use of the card images may require separate licensing
- Default images should be replaced with properly licensed content for any public deployment

### Trademark Notice
- "Dixit" is a trademark of Libellud
- This project is not affiliated with or endorsed by Libellud
- Don't Choose Me is an independent implementation inspired by similar storytelling card games

### Liability
- This software is provided "as is" without warranty of any kind
- The developers are not responsible for any misuse of copyrighted content
- Users are responsible for ensuring compliance with applicable laws and licenses
