// src/routes/members.routes.js
'use strict';

const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const osmApi = require('../services/osmApi');
const { limit } = require('../utils/concurrency');

const router = express.Router();

function calculateAge(dob) {
  if (!dob) return 0;
  const birthDate = new Date(dob);
  const ageDifMs = Date.now() - birthDate.getTime();
  return Math.abs(ageDifMs / (1000 * 60 * 60 * 24 * 365.25));
}

router.get(
  '/members',
  requireAuth,
  asyncHandler(async (req, res) => {
    let groupName = 'Your Group'; // default right at top
if (sections.length > 0) {
    groupName = await osmApi.getGroupName(accessToken, sections[0].section_id || sections[0].id, req.session);
}
    let mainMembers = [];
    let youthDuplicates = [];
    let ylMembers = [];
    let leaderMembers = [];

    try {
      const accessToken = req.session.accessToken;
      const sections = await osmApi.getDynamicSections(accessToken, req.session);

      console.log('Sections fetched:', sections.length); // diagnostic

      if (sections.length > 0) {
        groupName = await osmApi.getGroupName(accessToken, sections[0].section_id || sections[0].id, req.session);
      }

      const allMembers = new Map(); // scoutid → { member details + sections: [] }

      const withLimit = limit(4); // gentle on API

      for (const sec of sections) {
        const sectionId = sec.section_id || sec.id;
        const sectionName = sec.section_name || sec.name;
        const sectionType = sec.section_type || sec.type || 'unknown';
        const termId = sec.current_term_id || -1;

        // Skip non-member sections
        if (['waiting', 'unknown'].includes(sectionType)) continue;

        const params = {
          action: 'getListOfMembers',
          sectionid: sectionId,
          termid: termId,
          section: sectionType,
          sort: 'patrol',
        };

        let listData = [];
        try {
          const response = await osmApi.get(accessToken, '/ext/members/contact/', {
            params,
            session: req.session,
            ttlMs: 90_000, // 1.5 min – lists change slowly
          });

          listData = response?.data?.items || response?.data?.data || response?.data || [];
          if (!Array.isArray(listData)) listData = [];
        } catch (err) {
          console.error(`Members list failed for ${sectionName}:`, err.message);
          continue;
        }

        for (const raw of listData) {
          const scoutid = raw.scoutid || raw.id;
          if (!scoutid) continue;

          // Minimal individual fetch only if needed (patrol/dob often in list)
          let member = { ...raw };
          if (!member.dob || !member.patrol) {
            try {
              const indParams = {
                action: 'getIndividual',
                sectionid: sectionId,
                scoutid,
                termid: termId,
                context: 'members',
              };
              const indRes = await withLimit(async () => osmApi.get(accessToken, '/ext/members/contact/', {
                params: indParams,
                session: req.session,
                ttlMs: 300_000,
              }));
              member = { ...member, ...indRes?.data?.data || {}, ...indRes?.data || {} };
            } catch (err) {
              console.warn(`Individual fetch skipped for ${scoutid}: ${err.message}`);
            }
          }

          const age = calculateAge(member.dob);
          const key = scoutid;

          if (!allMembers.has(key)) {
            allMembers.set(key, {
              firstname: member.firstname,
              lastname: member.lastname,
              dob: member.dob,
              age,
              sections: [],
            });
          }
          allMembers.get(key).sections.push({
            name: sectionName,
            type: sectionType,
            patrol: member.patrol || 'None',
          });
        }
      }

      // Now perform validations and collect lists after all data is gathered
      const ylMismatches = [];
      const leaderMismatches = [];
      allMembers.forEach((m) => {
        const hasYL = m.sections.some((s) => s.patrol === 'Young Leaders');
        if (hasYL) {
          let issue = '';
          const hasExplorer = m.sections.some((s) => s.type === 'explorers');
          if (!hasExplorer) {
            issue += 'Not in Explorers; ';
          }
          if (m.sections.length !== 2) {
            issue += `In ${m.sections.length} sections instead of 2; `;
          }
          issue = issue.trim().replace(/; $/, '');
          ylMembers.push({
            firstname: m.firstname,
            lastname: m.lastname,
            sections: m.sections.map((s) => s.name).join(', '),
            issue,
          });
        }

        const hasLeader = m.sections.some((s) => s.patrol === 'Leaders');
        if (hasLeader) {
          let issue = '';
          const hasAdult = m.sections.some((s) => s.type === 'adults');
          if (!hasAdult) {
            issue = 'Not in Adults';
          }
          leaderMembers.push({
            firstname: m.firstname,
            lastname: m.lastname,
            sections: m.sections.map((s) => s.name).join(', '),
            issue,
          });
        }
      });

      // Dedupe mismatches (if same person flagged multiple times) - though unlikely now
      ylMembers = [...new Map(ylMembers.map((m) => [m.firstname + m.lastname, m])).values()];
      leaderMembers = [...new Map(leaderMembers.map((m) => [m.firstname + m.lastname, m])).values()];

      // Youth duplicates, excluding YLs
      allMembers.forEach((m) => {
        if (
          m.age < 18 &&
          m.sections.length > 1 &&
          !m.sections.some((s) => s.patrol === 'Young Leaders')
        ) {
          youthDuplicates.push(m);
        }
      });

      mainMembers = Array.from(allMembers.values()).sort((a, b) =>
        `${a.firstname} ${a.lastname}`.localeCompare(`${b.firstname} ${b.lastname}`)
      );

      // If we get here, all good
res.render('members', {
  mainMembers:        mainMembers        || [],
  youthDuplicates:    youthDuplicates    || [],
  ylMembers:          ylMembers          || [],     // ← note: your code uses ylMembers
  leaderMembers:      leaderMembers      || [],
  groupName:          groupName          || 'Group name unavailable',
  errorMessage:       errorMessage       || null
});
    } catch (err) {
      console.error('Critical error in members route:', err.message, err.stack);
      res.status(503).render('members', {  // or 'error' if you prefer
        mainMembers: [],
        youthDuplicates: [],
        ylMembers: [],
        leaderMembers: [],
        groupName: 'Unavailable (try again soon)',
        errorMessage: 'Could not load data from OSM right now – possibly rate limited or auth issue.',
      });
    }
  })
);

module.exports = router;
