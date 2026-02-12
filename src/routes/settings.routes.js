// src/routes/settings.routes.js
const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { DEFAULT_CUTOFFS } = require('../config/constants');

const router = express.Router();

router.get('/settings', requireAuth, (req, res) => {
  const cutoffs = { ...DEFAULT_CUTOFFS, ...(req.session.cutoffs || {}) };

  const displayCutoffs = {};
  ['squirrels', 'beavers', 'cubs', 'scouts', 'explorers'].forEach(key => {
    const decimal = cutoffs[key];
    const years = Math.floor(decimal);
    const months = Math.round((decimal - years) * 12);
    displayCutoffs[key] = { years, months };
  });

  res.render('settings', { displayCutoffs });
});

router.post('/update-cutoffs', requireAuth, (req, res) => {
  const cutoffs = {};
  ['squirrels', 'beavers', 'cubs', 'scouts', 'explorers'].forEach(type => {
    const years = Number.parseInt(req.body[`${type}_years`] || 0, 10);
    const months = Number.parseInt(req.body[`${type}_months`] || 0, 10);
    cutoffs[type] = (Number.isFinite(years) ? years : 0) + ((Number.isFinite(months) ? months : 0) / 12);
  });
  req.session.cutoffs = cutoffs;
  res.redirect('/settings'); // Redirect back to settings for confirmation
});

module.exports = router;
