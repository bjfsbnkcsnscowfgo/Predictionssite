const router = require('express').Router();

// GET /predictions/create
router.get('/create', (req, res) => {
    if (!req.session.user) return res.redirect('/login');
    res.render('create'); 
});

// List predictions
router.get('/', (req, res) => {
    const db = req.app.locals.db;
    const predictions = db.prepare('SELECT * FROM predictions').all();
    res.render('prediction', { predictions });
});

module.exports = router;
