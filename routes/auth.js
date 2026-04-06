const router = require('express').Router();
const bcrypt = require('bcryptjs');

// GET /login
router.get('/login', (req, res) => {
    res.render('login', { error: null });
});

// GET /signup
router.get('/signup', (req, res) => {
    res.render('signup', { error: null });
});

// POST /login (Basic version)
router.post('/login', async (req, res) => {
    const db = req.app.locals.db;
    const { username, password } = req.body;
    const user = db.prepare('SELECT * FROM users WHERE username = ?').get(username);

    if (user && bcrypt.compareSync(password, user.password_hash)) {
        req.session.user = user;
        return res.redirect('/');
    }
    res.render('login', { error: 'Invalid credentials' });
});

module.exports = router;
