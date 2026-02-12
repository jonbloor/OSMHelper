// src/routes/members.routes.js
'use strict';

const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const osmApi = require('../services/osmApi');
const { limit } = require('../utils/concurrency');
const { FRIENDLY_SECTION_TYPES } = require('../config/constants');

const router = express.Router();

router.get(
  '/members',
  requireAuth,
  asyncHandler(async (req, res) => {
    const accessToken = req.session.accessToken;
    const sections = await osmApi.getDynamicSections(accessToken, req.session);

    console.log('Sections fetched:', sections.length);
    sections.forEach(sec => {
      console.log(`Section: ${sec.section_name} (type: ${sec.section_type}, id: ${sec.section_id}, current_term_id: ${sec.current_term_id})`);
    });

    const members = [];

    const withLimit = limit(5);

    for (const sec of sections) {
      const type = sec.section_type || 'unknown';
      if (['waiting', 'unknown', 'adults'].includes(type)) continue; // Skip non-youth

      const termId = sec.current_term_id || -1; // Simplified to match original code

      const listPath = '/ext/members/contact/';
      const listParams = {
        action: 'getListOfMembers',
        sectionid: sec.section_id,
        termid: termId,
        section: type,
        sort: 'patrol',
      };

      console.log(`Fetching list for section ${sec.section_name}: ${listPath}?${new URLSearchParams(listParams).toString()}`);

      let listData = [];
      try {
        const listResponse = await osmApi.get(accessToken, listPath, {
          params: listParams,
          session: req.session,
          ttlMs: 60_000,
        });

        listData = listResponse?.data?.items ||
                   listResponse?.data?.data ||
                   listResponse?.items ||
                   listResponse?.data ||
                   [];
        if (!Array.isArray(listData)) listData = [];

        console.log(`Members list for section ${sec.section_name}: ${listData.length} items`);
      } catch (err) {
        console.error(`List fetch failed for section ${sec.section_id}:`, err.message);
      }

      const detailed = await Promise.all(
        listData.map(member => withLimit(async () => {
          const scoutId = member.scoutid || member.id;
          if (!scoutId) return null;

          const individualPath = '/ext/members/contact/';
          const individualParams = {
            action: 'getIndividual',
            sectionid: sec.section_id,
            scoutid: scoutId,
            termid: termId,
            context: 'members',
          };

          try {
            const individualResponse = await osmApi.get(accessToken, individualPath, {
              params: individualParams,
              session: req.session,
              ttlMs: 2 * 60 * 1000,
            });

            const individualData = individualResponse?.data?.data ||
                                    individualResponse?.data ||
                                    individualResponse ||
                                    {};

            return {
              sectionType: FRIENDLY_SECTION_TYPES[type] || type,
              sectionName: sec.section_name,
              memberId: scoutId,
              firstName: member.firstname || individualData.firstname || '',
              lastName: member.lastname || individualData.lastname || '',
              dob: individualData.dob || member.dob || '',
              patrol: member.patrol || individualData.patrol || '',
              started: individualData.startedsection || individualData.started || '',
              joined: individualData.started || individualData.joinedgroup || individualData.joined || '',
              age: member.age || individualData.age || '',
            };
          } catch (err) {
            console.warn(`Individual fetch failed for ${scoutId}:`, err.message);
            return {
              sectionType: FRIENDLY_SECTION_TYPES[type] || type,
              sectionName: sec.section_name,
              memberId: scoutId,
              firstName: member.firstname || '',
              lastName: member.lastname || '',
              dob: '',
              patrol: member.patrol || '',
              started: '',
              joined: '',
              age: member.age || '',
            };
          }
        }))
      );

      detailed.filter(Boolean).forEach(row => members.push(row));
    }

    const rateLimit = osmApi.getRateLimitSnapshot(accessToken);

    res.render('members', {
      members,
      rateLimit,
    });
  })
);

module.exports = router;
