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
    const accessToken = req.session.accessToken;
    let groupName = '4th Ashby de la Zouch';
    let mainMembers = [];
    let youthDuplicates = [];
    let ylMembers = [];
    let leaderMembers = [];
    let errorMessage = null;

    try {
      const sections = await osmApi.getDynamicSections(accessToken, req.session);
      console.log('Sections fetched:', sections.length);

      // More reliable group name (skip Adults section)
      if (sections.length > 0) {
        const primarySection = sections.find(s => s.section_type !== 'adults') || sections[0];
        try {
          groupName = await osmApi.getGroupName(accessToken, primarySection.section_id || primarySection.id, req.session);
        } catch (e) {
          console.warn('Group name fetch failed, using fallback');
          groupName = primarySection.group_name || '4th Ashby de la Zouch';
        }
      }

      const allMembers = new Map(); // scoutid → member data
      const withLimit = limit(6);   // slightly higher but safer than before

      for (const sec of sections) {
        const sectionId = sec.section_id || sec.id;
        const sectionName = sec.section_name || sec.name;
        const sectionType = sec.section_type || sec.type || 'unknown';
        const termId = sec.current_term_id || -1;

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
            ttlMs: 90_000,
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

          let member = { ...raw };

          // Only fetch individual if missing important fields
          if (!member.dob || !member.patrol) {
            try {
              const indParams = {
                action: 'getIndividual',
                sectionid: sectionId,
                scoutid,
                termid: termId,
                context: 'members',
              };
              const indRes = await withLimit(() => osmApi.get(accessToken, '/ext/members/contact/', {
                params: indParams,
                session: req.session,
                ttlMs: 300_000,
              }));
              member = { ...member, ...(indRes?.data?.data || indRes?.data || {}) };
            } catch (err) {
              console.warn(`Individual fetch skipped for ${scoutid}`);
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
              scoutid: scoutid
            });
          }

          allMembers.get(key).sections.push({
            name: sectionName,
            type: sectionType,
            patrol: member.patrol || 'None',
          });
        }
      }

      // Process YL and Leader mismatches (unchanged)
      allMembers.forEach((m) => {
        const hasYL = m.sections.some(s => s.patrol === 'Young Leaders');
        if (hasYL) {
          let issue = '';
          if (!m.sections.some(s => s.type === 'explorers')) issue += 'Not in Explorers; ';
          if (m.sections.length !== 2) issue += `In ${m.sections.length} sections; `;
          issue = issue.trim().replace(/; $/, '');
          ylMembers.push({
            firstname: m.firstname,
            lastname: m.lastname,
            sections: m.sections.map(s => s.name).join(', '),
            issue: issue || 'OK',
          });
        }

        const hasLeader = m.sections.some(s => s.patrol === 'Leaders');
        if (hasLeader) {
          const issue = m.sections.some(s => s.type === 'adults') ? '' : 'Not in Adults';
          leaderMembers.push({
            firstname: m.firstname,
            lastname: m.lastname,
            sections: m.sections.map(s => s.name).join(', '),
            issue: issue || 'OK',
          });
        }
      });

      ylMembers = [...new Map(ylMembers.map(item => [`${item.firstname}${item.lastname}`, item])).values()];
      leaderMembers = [...new Map(leaderMembers.map(item => [`${item.firstname}${item.lastname}`, item])).values()];

      allMembers.forEach((m) => {
        if (m.age < 18 && m.sections.length > 1 && !m.sections.some(s => s.patrol === 'Young Leaders')) {
          youthDuplicates.push(m);
        }
      });

      mainMembers = Array.from(allMembers.values()).sort((a, b) =>
        `${a.firstname} ${a.lastname}`.localeCompare(`${b.firstname} ${b.lastname}`)
      );

      // === Flatten for the EJS template ===
      const flatMembers = [];
      allMembers.forEach((m) => {
        m.sections.forEach((sec) => {
          flatMembers.push({
            section_type: sec.type,
            section_name: sec.name,
            scoutid: m.scoutid || '',
            firstname: m.firstname || '',
            lastname: m.lastname || '',
            dob: m.dob || '',
            patrol: sec.patrol,
            started: '',
            joined: '',
            age: m.age.toFixed(1)
          });
        });
      });

    } catch (err) {
      console.error('Critical error in /members route:', err);
      errorMessage = 'Could not load membership data. Please try again later.';
    }

    // Always render something (even on error)
    const flatMembers = []; // fallback
    res.render('members', {
      mainMembers: mainMembers || [],
      youthDuplicates: youthDuplicates || [],
      ylMembers: ylMembers || [],
      leaderMembers: leaderMembers || [],
      groupName: groupName,
      errorMessage: errorMessage || null,
      fetchedAt: new Date().toLocaleString('en-GB', { timeZone: 'Europe/London', hour12: false }),
      members: flatMembers,                    // for table
      membersJSON: JSON.stringify(flatMembers) // for client-side JS
    });
  })
);

module.exports = router;
