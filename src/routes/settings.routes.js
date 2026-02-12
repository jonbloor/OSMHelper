// src/routes/settings.routes.js
const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { DEFAULT_CUTOFFS, FRIENDLY_SECTION_TYPES, DEFAULT_CAPACITIES } = require('../config/constants');
const osmApi = require('../services/osmApi');
const { asyncHandler } = require('../utils/asyncHandler');

const router = express.Router();

router.get('/settings', requireAuth, asyncHandler(async (req, res) => {
  const accessToken = req.session.accessToken;
  const sections = await osmApi.getDynamicSections(accessToken, req.session);
  const excludedTypes = ['waiting', 'adults', 'unknown']; // Focus on youth sections
  const filteredSections = sections.filter(sec => !excludedTypes.includes(sec.section_type));

  const cutoffs = { ...DEFAULT_CUTOFFS, ...(req.session.cutoffs || {}) };
  const capacities = req.session.capacities || {};
  const visibleSections = req.session.visibleSections || {};

  const displayCutoffs = {};
  ['squirrels', 'beavers', 'cubs', 'scouts', 'explorers'].forEach(key => {
    const decimal = cutoffs[key];
    const years = Math.floor(decimal);
    const months = Math.round((decimal - years) * 12);
    displayCutoffs[key] = { years, months };
  });

  // Prepare sections for display with friendly names, default capacities, and visibility
  const displaySections = filteredSections.map(sec => ({
    id: sec.section_id,
    name: sec.section_name,
    type: FRIENDLY_SECTION_TYPES[sec.section_type] || sec.section_type,
    defaultCapacity: DEFAULT_CAPACITIES[sec.section_type] || 'Not set',
    capacity: capacities[sec.section_id] || DEFAULT_CAPACITIES[sec.section_type] || '',
    visible: visibleSections[sec.section_id] !== false, // Default true
  }));

  res.render('settings', { displayCutoffs, displaySections });
}));

router.post('/update-cutoffs', requireAuth, (req, res) => {
  const cutoffs = {};
  ['squirrels', 'beavers', 'cubs', 'scouts', 'explorers'].forEach(type => {
    const years = Number.parseInt(req.body[`${type}_years`] || 0, 10);
    const months = Number.parseInt(req.body[`${type}_months`] || 0, 10);
    cutoffs[type] = (Number.isFinite(years) ? years : 0) + ((Number.isFinite(months) ? months : 0) / 12);
  });
  req.session.cutoffs = cutoffs;
  res.redirect('/settings');
});

router.post('/update-sections', requireAuth, (req, res) => {
  const capacities = {};
  const visibleSections = {};
  for (const key in req.body) {
    if (key.startsWith('capacity_')) {
      const sectionId = key.replace('capacity_', '');
      const value = Number.parseInt(req.body[key], 10);
      if (Number.isFinite(value)) capacities[sectionId] = value;
    } else if (key.startsWith('visible_')) {
      const sectionId = key.replace('visible_', '');
      visibleSections[sectionId] = req.body[key] === 'on';
    }
  }
  req.session.capacities = capacities;
  req.session.visibleSections = visibleSections;
  res.redirect('/settings');
});

module.exports = router;
