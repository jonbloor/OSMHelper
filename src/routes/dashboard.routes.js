// src/routes/dashboard.routes.js
'use strict';

const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const osmApi = require('../services/osmApi');

const router = express.Router();

// Pre-auth intro page
router.get('/', (req, res) => {
  const authorized = Boolean(req.session && req.session.accessToken);
  const rateLimit = authorized ? osmApi.getRateLimitSnapshot(req.session.accessToken) : null;

  res.render('index', {
    authorized,
    groupName: req.session.groupName || '4th Ashby Scout Group',
    email: req.session.email || 'Unknown Email', // Added
    fullName: req.session.fullName || 'Unknown User', // Optional, if needed
    sections: [],
    rateLimit,
  });
});

// Authenticated dashboard
router.get(
  '/dashboard',
  requireAuth,
  asyncHandler(async (req, res) => {
    const accessToken = req.session.accessToken;

    let sections = [];
    try {
      sections = await osmApi.getDynamicSections(accessToken, req.session);
    } catch (err) {
      console.warn('Sections fetch failed:', err.message);
    }

    const rateLimit = osmApi.getRateLimitSnapshot(accessToken);

    res.render('index', {
      authorized: true,
      groupName: req.session.groupName || '4th Ashby Scout Group',
      email: req.session.email || 'Unknown Email', // Added
      fullName: req.session.fullName || 'Unknown User', // Optional
      sections,
      rateLimit,
    });
  })
);

// Logout / Reset
router.get('/reset', (req, res) => {
  req.session.destroy(err => {
    if (err) {
      console.error('Session destroy error:', err);
      return res.status(500).send('Logout failed');
    }
    res.redirect('/');
  });
});

module.exports = router;
