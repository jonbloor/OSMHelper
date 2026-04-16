// src/routes/membershipDashboard.routes.js
const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const osmApi = require('../services/osmApi');
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
  const accessToken = req.session.accessToken;
  const debugRef = `md-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;

  const sections = await osmApi.getDynamicSections(accessToken, req.session);
  const excludedTypes = ['explorers', 'adults', 'waiting'];
  const capacities = req.session.capacities || {};
  const visibleSections = req.session.visibleSections || {};
  const filteredSections = sections.filter(sec => !excludedTypes.includes(sec.section_type) && visibleSections[sec.section_id] !== false);
  const cutoffs = { ...DEFAULT_CUTOFFS, ...(req.session.cutoffs || {}) };

  const debugSummary = {
    debugRef,
    sectionsTotal: sections.length,
    sectionsIncluded: filteredSections.length,
    waitingSectionFound: false,
    waitingApplicantsFetched: 0,
    sectionFetchFailures: [],
  };

  let waitingCounts = { tooYoung: 0, squirrels: 0, beavers: 0, cubs: 0, scouts: 0, explorers: 0 };

  try {
    const waitingSection = sections.find(sec => sec.section_type === 'waiting' || (sec.section_name || '').toLowerCase().includes('waiting'));
    if (waitingSection) {
      debugSummary.waitingSectionFound = true;
      const waitingId = waitingSection.section_id;
      const waitingType = waitingSection.section_type || 'waiting';
      const listUrl = '/ext/members/contact/';
      const listRes = await osmApi.get(accessToken, listUrl, {
        params: {
          action: 'getListOfMembers',
          sectionid: waitingId,
          termid: -1,
          section: waitingType,
          sort: 'dob',
        },
        session: req.session,
      });

      const waitingList = listRes.data?.items || [];
      debugSummary.waitingApplicantsFetched = waitingList.length;

      const today = new Date();
      const todayMillis = today.getTime();
      for (const applicant of waitingList) {
        const indRes = await osmApi.get(accessToken, '/ext/members/contact/', {
          params: {
            action: 'getIndividual',
            sectionid: waitingId,
            scoutid: applicant.scoutid,
            termid: -1,
            context: 'members',
          },
          session: req.session,
        });

        const indData = indRes.data?.data || {};
        const dob = new Date(indData.dob);
        const age = Number.isFinite(dob.getTime()) ? (todayMillis - dob.getTime()) / (365.25 * 24 * 60 * 60 * 1000) : null;
        if (age == null) continue;

        if (age < cutoffs.squirrels) waitingCounts.tooYoung++;
        else if (age < cutoffs.beavers) waitingCounts.squirrels++;
        else if (age < cutoffs.cubs) waitingCounts.beavers++;
        else if (age < cutoffs.scouts) waitingCounts.cubs++;
        else if (age < cutoffs.explorers) waitingCounts.scouts++;
        else waitingCounts.explorers++;
      }
    }
  } catch (e) {
    console.warn(`[membership-dashboard:${debugRef}] Waiting list fetch failed:`, e.message);
  }

  const grouped = {};
  let totalMembers = 0;
  let totalLeaders = 0;
  let totalYLs = 0;
  let totalCapacity = 0;
  let totalSpaces = 0;

  for (const sec of filteredSections) {
    const type = sec.section_type || 'unknown';
    const friendly = FRIENDLY_SECTION_TYPES[type] || 'Other';

    if (!grouped[friendly]) {
      grouped[friendly] = {
        sections: [],
        subtotalMembers: 0,
        subtotalLeaders: 0,
        subtotalYLs: 0,
        subtotalCapacity: 0,
        subtotalSpaces: 0,
        subtotalWaiting: 0,
      };
    }

    const termId = sec.current_term_id || -1;

    try {
      const listRes = await osmApi.get(accessToken, '/ext/members/contact/', {
        params: {
          action: 'getListOfMembers',
          sectionid: sec.section_id,
          termid: termId,
          section: type,
          sort: 'patrol',
        },
        session: req.session,
      });

      const members = listRes.data?.items || [];
      let secMembers = 0;
      let secLeaders = 0;
      let secYLs = 0;
      const leaderInitials = [];
      const ylInitials = [];

      members.forEach(m => {
        const patrol = m.patrol || '';
        const initials = [(m.firstname || '')[0]?.toUpperCase() || '', (m.lastname || '')[0]?.toUpperCase() || ''].join('').trim();
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

      const capacity = capacities[sec.section_id] || getCapacity(sec.section_id, type);
      const spaces = (typeof capacity === 'number') ? (capacity - secMembers) : '-';

      grouped[friendly].sections.push({
        name: sec.section_name,
        members: secMembers,
        leaders: secLeaders,
        youngLeaders: secYLs,
        leaderInitials: leaderInitials.join(', ') || ' ',
        youngLeaderInitials: ylInitials.join(', ') || ' ',
        capacity,
        spaces,
        waiting: 0,
      });

      grouped[friendly].subtotalMembers += secMembers;
      grouped[friendly].subtotalLeaders += secLeaders;
      grouped[friendly].subtotalYLs += secYLs;
      grouped[friendly].subtotalCapacity += typeof capacity === 'number' ? capacity : 0;
      grouped[friendly].subtotalSpaces += typeof spaces === 'number' ? spaces : 0;

      totalMembers += secMembers;
      totalLeaders += secLeaders;
      totalYLs += secYLs;
      totalCapacity += typeof capacity === 'number' ? capacity : 0;
      totalSpaces += typeof spaces === 'number' ? spaces : 0;
    } catch (err) {
      debugSummary.sectionFetchFailures.push({
        sectionId: sec.section_id,
        sectionName: sec.section_name,
        message: err.message,
      });
      console.warn(`[membership-dashboard:${debugRef}] Section fetch failed for ${sec.section_name} (${sec.section_id}):`, err.message);
    }
  }

  Object.keys(grouped).forEach(type => {
    grouped[type].subtotalWaiting = waitingCounts[type.toLowerCase().replace(/ /g, '')] || 0;
  });

  const totalWaiting = Object.values(grouped).reduce((sum, g) => sum + (g.subtotalWaiting || 0), 0);
  const totalWaitingFull = totalWaiting + waitingCounts.tooYoung + waitingCounts.explorers;

  const sortedGrouped = {};
  SECTION_TYPE_ORDER.forEach(typeName => {
    if (grouped[typeName]) sortedGrouped[typeName] = grouped[typeName];
  });

  const latestUpdate = new Date().toLocaleString('en-GB', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });

  res.render('membership-dashboard', {
    grouped: Object.keys(sortedGrouped).length ? sortedGrouped : grouped,
    totalMembers,
    totalLeaders,
    totalYLs,
    totalCapacity,
    totalSpaces,
    totalWaiting,
    tooYoungWaiting: waitingCounts.tooYoung,
    ofAge: totalWaiting,
    dataUpdated: latestUpdate,
    groupName: req.session.groupName || 'OSMHelper.co.uk',
    totalWaitingFull,
    debugSummary,
  });
}));

module.exports = router;
