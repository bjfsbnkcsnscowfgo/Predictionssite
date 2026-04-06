const express = require('express');
const session = require('express-session');
const SQLiteStore = require('connect-sqlite3')(session);
const Database = require('better-sqlite3');
const path = require('path');
const fs = require('fs');

const app = express();

// 1. Ensure Database Directory Exists (Crucial for Render)
const dbDir = path.join(__dirname, 'database');
if (!fs.existsSync(dbDir)) {
    fs.mkdirSync(dbDir);
}

// 2. Database Connection
// This connects to the file your routes expect: req.app.locals.db
app.locals.db = new Database(path.join(dbDir, 'predictions.db'));

// 3. View Engine Setup
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

// 4. Middleware
app.use(express.static(path.join(__dirname, 'public')));
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// 5. Session Configuration
app.use(session({
    store: new SQLiteStore({ 
        dir: dbDir, 
        db: 'sessions.db' 
    }),
    secret: process.env.SESSION_SECRET || 'dev-secret-key',
    resave: false,
    saveUninitialized: false,
    cookie: { maxAge: 7 * 24 * 60 * 60 * 1000 } // 1 week
}));

// 6. Routes
// Make sure these files exist in your /routes folder on GitHub!
app.use('/', require('./routes/auth'));
app.use('/predictions', require('./routes/predictions'));

// 7. Home Route (Fixes "Cannot GET /")
app.get('/', (req, res) => {
    res.render('home', { user: req.session.user || null });
});

// 8. Start Server
// Render provides the PORT automatically via process.env.PORT
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
    console.log(`Server is running on port ${PORT}`);
});
