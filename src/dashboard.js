const express = require('express');
const { getRateLimitInfo, getDynamicSections } = require('./utils');
const { FRIENDLY_SECTION_TYPES, DEFAULT_CAPACITIES } = require('./config');

const router = express.Router();

// In-memory capacities (reset on restart)
let sectionCapacities = {};

// Main dashboard
router.get('/', async (req, res) => {
  let rateInfo = { limit: 'N/A', remaining: 'N/A', reset: 'N/A' };
  let sectionsWithCapacity = [];

  if (req.session.accessToken) {
    rateInfo = await getRateLimitInfo(req.session.accessToken);
    const sections = await getDynamicSections(req.session.accessToken);

    sectionsWithCapacity = sections.map(sec => ({
      id: sec.section_id,
      name: sec.section_name,
      type: sec.section_type,
      friendlyType: FRIENDLY_SECTION_TYPES[sec.section_type] || sec.section_type || 'Unknown',
      capacity: sectionCapacities[sec.section_id] ?? DEFAULT_CAPACITIES[sec.section_type] ?? 'Not set'
    }));
  }

  res.render('index', {
    authorized: !!req.session.accessToken,
    rateInfo,
    sections: sectionsWithCapacity
  });
});

// Update capacity
router.post('/update-capacity', (req, res) => {
  const { sectionId, capacity } = req.body;
  if (sectionId && capacity !== '') {
    sectionCapacities[sectionId] = parseInt(capacity, 10);
  }
  res.redirect('/');
});

// Membership dashboard (placeholder – expand later)
router.get('/membership-dashboard', async (req, res) => {
  try {
    const sections = await getDynamicSections(req.session.accessToken);

    res.render('membership-dashboard', {
      sectionsData: sections,
      membersUpdated: new Date().toLocaleString('en-GB'),
      waitingUpdated: new Date().toLocaleString('en-GB'),
      message: 'Dashboard loading – full grouping coming soon'
    });
  } catch (error) {
    console.error('Membership dashboard error:', error.message);
    res.status(500).render('error', { message: 'Dashboard load failed.' });
  }
});

module.exports = router;
