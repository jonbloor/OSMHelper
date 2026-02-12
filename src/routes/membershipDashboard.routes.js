const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const { osm, authHeader, getDynamicSections } = require('../services/osmApi');
const {
  FRIENDLY_SECTION_TYPES,
  SECTION_TYPE_ORDER,
  DEFAULT_CUTOFFS,
} = require('../config/constants');
const { getCapacity } = require('../store/capacitiesStore');

const router = express.Router();

router.post('/update-cutoffs', requireAuth, (req, res) => {
  const cutoffs = {};
  ['squirrels', 'beavers', 'cubs', 'scouts', 'explorers'].forEach(type => {
    const years = Number.parseInt(req.body[`${type}_years`] || 0, 10);
    const months = Number.parseInt(req.body[`${type}_months`] || 0, 10);
    cutoffs[type] = (Number.isFinite(years) ? years : 0) + ((Number.isFinite(months) ? months : 0) / 12);
  });
  req.session.cutoffs = cutoffs;
  res.redirect('/membership-dashboard');
});

router.get('/membership-dashboard', requireAuth, asyncHandler(async (req, res) => {
  const sections = await getDynamicSections(req.session.accessToken);

  const groupName =
    req.session.groupName ||
    sections[0]?.group_name ||
    '4th Ashby Scout Group';

  // Use session overrides if present, else defaults.
  const cutoffs = { ...DEFAULT_CUTOFFS, ...(req.session.cutoffs || {}) };

  // Prepare cut-offs for the view (years + months)
  const displayCutoffs = {};
  ['squirrels', 'beavers', 'cubs', 'scouts', 'explorers'].forEach(key => {
    const decimal = cutoffs[key];
    const years = Math.floor(decimal);
    const months = Math.round((decimal - years) * 12);
    displayCutoffs[key] = { years, months };
  });

  // If no sections returned, render an empty dashboard safely.
  if (!sections.length) {
    const projected = { tooYoung: 0, squirrels: 0, beavers: 0, cubs: 0, scouts: 0, explorers: 0 };
    return res.render('membership-dashboard', {
      groupName,
      grouped: {},
      totalMembers: 0,
      totalLeaders: 0,
      totalYLs: 0,
      projected,
      totalProjected: 0,
      displayCutoffs,
      membersUpdated: new Date().toLocaleString('en-GB'),
      waitingUpdated: new Date().toLocaleString('en-GB'),
    });
  }

  const today = new Date();
  const todayMillis = today.getTime();

  // Fetch waiting list and project ages
  let waitingApplicants = [];

  const waitingSection = sections.find(s =>
    s.section_type === 'waiting' || String(s.section_name || '').toLowerCase().includes('waiting')
  );

  if (waitingSection) {
    const wId = waitingSection.section_id;
    const wType = waitingSection.section_type || 'waiting';

    const wUrl = `/ext/members/contact/?action=getListOfMembers&sectionid=${wId}&termid=-1&section=${wType}&sort=dob`;
    const wRes = await osm.get(wUrl, { headers: authHeader(req.session.accessToken) });
    const wItems = wRes.data?.items || [];

    for (const app of wItems) {
      try {
        const indUrl = `/ext/members/contact/?action=getIndividual&sectionid=${wId}&scoutid=${app.scoutid}&termid=-1&context=members`;
        const indRes = await osm.get(indUrl, { headers: authHeader(req.session.accessToken) });
        const data = indRes.data?.data || {};
        const dobDate = new Date(data.dob);
        const dobMillis = dobDate.getTime();
        if (!Number.isFinite(dobMillis)) continue;

        const ageYears = (todayMillis - dobMillis) / (365.25 * 24 * 60 * 60 * 1000);

        const initials = [
          (data.firstname || '')[0]?.toUpperCase() || '',
          (data.lastname || '')[0]?.toUpperCase() || '',
        ].join('').trim();

        waitingApplicants.push({ ageYears, initials, dob: data.dob });
      } catch {
        // Ignore single-record failures to keep dashboard usable.
      }
    }
  }

  const projected = { tooYoung: 0, squirrels: 0, beavers: 0, cubs: 0, scouts: 0, explorers: 0 };
  waitingApplicants.forEach(app => {
    const age = app.ageYears;
    if (age < cutoffs.squirrels) projected.tooYoung++;
    else if (age < cutoffs.beavers) projected.squirrels++;
    else if (age < cutoffs.cubs) projected.beavers++;
    else if (age < cutoffs.scouts) projected.cubs++;
    else if (age < cutoffs.explorers) projected.scouts++;
    else projected.explorers++;
  });
  const totalProjected = Object.values(projected).reduce((a, b) => a + b, 0);

  // Grouped section stats
  const grouped = {};
  let totalMembers = 0;
  let totalLeaders = 0;
  let totalYLs = 0;

  for (const sec of sections) {
    const type = sec.section_type;
    if (!['earlyyears', 'beavers', 'cubs', 'scouts', 'explorers'].includes(type)) continue;

    const friendly = FRIENDLY_SECTION_TYPES[type] || type;
    if (!grouped[friendly]) {
      grouped[friendly] = { sections: [], subtotalMembers: 0, subtotalLeaders: 0, subtotalYLs: 0 };
    }

    const currentTerm = sec.terms?.find(t => new Date(t.startdate) <= today && new Date(t.enddate) >= today);
    const termId = currentTerm?.term_id || sec.terms?.[sec.terms.length - 1]?.term_id;
    if (!termId) continue;

    const listUrl =
      `/ext/members/contact/?action=getListOfMembers&sectionid=${sec.section_id}&termid=${termId}&section=${type}&sort=lastname`;

    const listRes = await osm.get(listUrl, { headers: authHeader(req.session.accessToken) });
    const items = listRes.data?.items || [];

    let secMembers = 0;
    let secLeaders = 0;
    let secYLs = 0;
    const leaderInitials = [];
    const ylInitials = [];

    items.forEach(m => {
      const patrol = String(m.patrol || '').trim();

      const initials = [
        (m.firstname || '')[0]?.toUpperCase() || '',
        (m.lastname || '')[0]?.toUpperCase() || '',
      ].join('').trim();

      if (patrol === 'Leaders') {
        secLeaders++;
        if (initials) leaderInitials.push(initials);
      } else if (patrol === 'Young Leaders (YLs)') {
        secYLs++;
        if (initials) ylInitials.push(initials);
      } else {
        secMembers++;
      }
    });

    leaderInitials.sort();
    ylInitials.sort();

    const capacity = getCapacity(sec.section_id, type);
    const spaces = (typeof capacity === 'number') ? (capacity - secMembers) : '-';

    grouped[friendly].sections.push({
      name: sec.section_name,
      members: secMembers,
      leaders: secLeaders,
      youngLeaders: secYLs,
      leaderInitials: leaderInitials.join(', ') || '-',
      youngLeaderInitials: ylInitials.join(', ') || '-',
      capacity,
      spaces,
    });

    grouped[friendly].subtotalMembers += secMembers;
    grouped[friendly].subtotalLeaders += secLeaders;
    grouped[friendly].subtotalYLs += secYLs;

    totalMembers += secMembers;
    totalLeaders += secLeaders;
    totalYLs += secYLs;
  }

  // Optional: render in your preferred order (Squirrels → Explorers)
  const sortedGrouped = {};
  SECTION_TYPE_ORDER.forEach(typeName => {
    if (grouped[typeName]) sortedGrouped[typeName] = grouped[typeName];
  });

  res.render('membership-dashboard', {
    groupName,
    grouped: Object.keys(sortedGrouped).length ? sortedGrouped : grouped,
    totalMembers,
    totalLeaders,
    totalYLs,
    projected,
    totalProjected,
    displayCutoffs,
    membersUpdated: new Date().toLocaleString('en-GB'),
    waitingUpdated: new Date().toLocaleString('en-GB'),
  });
}));

module.exports = router;
