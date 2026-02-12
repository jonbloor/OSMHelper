'use strict';

const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const osmApi = require('../services/osmApi');

const router = express.Router();

// Intro page (NOT protected)
router.get('/', (req, res) => {
  const authorized = Boolean(req.session && req.session.accessToken);

  res.render('index', {
    authorized,
    groupName: req.session.groupName || '4th Ashby Scout Group',
    sections: [],
    rateLimit: authorized ? osmApi.getRateLimitSnapshot(req.session.accessToken) : null,
  });
});

// Real dashboard (protected)
router.get(
  '/dashboard',
  requireAuth,
  asyncHandler(async (req, res) => {
    const accessToken = req.session.accessToken;

    let sections = [];
    try {
      const resource = await osmApi.get(accessToken, '/oauth/resource', {
        session: req.session,
        ttlMs: 5 * 60 * 1000,
      });

      sections =
        resource?.sections ||
        resource?.data?.sections ||
        resource?.items ||
        resource?.data?.items ||
        [];
      if (!Array.isArray(sections)) sections = [];
    } catch {
      sections = [];
    }

    res.render('index', {
      authorized: true,
      groupName: req.session.groupName || '4th Ashby Scout Group',
      sections,
      rateLimit: osmApi.getRateLimitSnapshot(accessToken),
    });
  })
);

module.exports = router;
