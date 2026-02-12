// src/routes/members.routes.js
'use strict';

const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const osmApi = require('../services/osmApi');
const { mapLimit } = require('../utils/concurrency');

const router = express.Router();

/**
 * GET /members
 *
 * Behaviour:
 * - Fetch sections via /oauth/resource (cached)
 * - For each youth/adults section:
 *   - Pick current term, else last term
 *   - Get list of members (cached)
 *   - For each member, fetch individual details (cached + concurrency limited)
 *
 * Notes:
 * - This still does a lot of calls, but caching + concurrency limits + rate-limit protection
 *   should make it much safer and faster for repeat loads.
 */

function pickTermId(terms, today) {
  if (!Array.isArray(terms) || !terms.length) return null;

  const currentTerm = terms.find(t => {
    const start = new Date(t.startdate);
    const end = new Date(t.enddate);
    return start <= today && end >= today;
  });

  return (currentTerm && currentTerm.term_id) || terms[terms.length - 1]?.term_id || null;
}

async function getDynamicSections(accessToken, req) {
  // Cache these for a while — they don’t change often
  // Uses osmApi.get caching (keyed per user via session)
  // Endpoint returns sections/terms etc.
  const data = await osmApi.get(accessToken, '/oauth/resource', {
    session: req.session,
    ttlMs: 5 * 60 * 1000, // 5 minutes
  });

  // Depending on OSM response shape, sections can appear in slightly different places.
  // Your previous helper likely normalised this already; this keeps it defensive.
  const sections =
    data?.sections ||
    data?.data?.sections ||
    data?.items ||
    data?.data?.items ||
    data ||
    [];

  if (!Array.isArray(sections)) return [];
  return sections;
}

router.get(
  '/members',
  requireAuth,
  asyncHandler(async (req, res) => {
    const accessToken = req.session.accessToken;

    const sections = await getDynamicSections(accessToken, req);

    // Map sections by id for easy access
    const sectionsMap = {};
    sections.forEach(sec => {
      // Expecting same fields you used previously
      const sectionId = sec.section_id ?? sec.sectionid ?? sec.id;
      if (!sectionId) return;

      sectionsMap[String(sectionId)] = {
        name: sec.section_name ?? sec.name ?? '',
        type: sec.section_type ?? sec.type ?? '',
        terms: Array.isArray(sec.terms) ? sec.terms : [],
        group_id: sec.group_id ?? sec.groupid ?? null,
      };
    });

    // Original behaviour included adults too
    const allowedTypes = ['earlyyears', 'beavers', 'cubs', 'scouts', 'explorers', 'adults'];

    const sectionIds = Object.keys(sectionsMap).filter(id =>
      allowedTypes.includes(String(sectionsMap[id].type))
    );

    const members = [];
    const today = new Date();

    // Concurrency for individual member fetches (tune as needed)
    const INDIVIDUAL_CONCURRENCY = 6;

    for (const sectionId of sectionIds) {
      const sec = sectionsMap[sectionId];

      const termId = pickTermId(sec.terms, today);
      if (!termId) continue;

      // List members (cached briefly)
      const listPath = '/ext/members/contact/';
      const listParams = {
        action: 'getListOfMembers',
        sectionid: sectionId,
        termid: termId,
        section: sec.type,
        sort: 'lastname',
      };

      const listResponse = await osmApi.get(accessToken, listPath, {
        params: listParams,
        session: req.session,
        ttlMs: 60 * 1000, // 60 seconds
      });

      const listData = listResponse?.items || listResponse?.data?.items || [];

      // Fetch individual details with concurrency limit (and cached)
      const detailed = await mapLimit(listData, INDIVIDUAL_CONCURRENCY, async member => {
        const scoutId = member.scoutid ?? member.scout_id ?? member.id;
        if (!scoutId) {
          // If the list item is malformed, just skip it
          return null;
        }

        try {
          const individualPath = '/ext/members/contact/';
          const individualParams = {
            action: 'getIndividual',
            sectionid: sectionId,
            scoutid: scoutId,
            termid: termId,
            context: 'members',
          };

          const individualResponse = await osmApi.get(accessToken, individualPath, {
            params: individualParams,
            session: req.session,
            ttlMs: 2 * 60 * 1000, // 2 minutes
          });

          const individualData =
            individualResponse?.data ||
            individualResponse?.data?.data ||
            individualResponse?.data?.items ||
            individualResponse ||
            {};

          return {
            sectionType: sec.type,
            sectionName: sec.name,
            memberId: scoutId,
            firstName: member.firstname || '',
            lastName: member.lastname || '',
            dob: individualData.dob || '',
            patrol: member.patrol || '',
            started: individualData.startedsection || '',
            joined: individualData.started || individualData.joinedgroup || '',
            age: member.age || individualData.age || '',
          };
        } catch (err) {
          // Fall back to list data (like original)
          return {
            sectionType: sec.type,
            sectionName: sec.name,
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
      });

      for (const row of detailed) {
        if (row) members.push(row);
      }
    }

    const rateLimit = osmApi.getRateLimitSnapshot(accessToken);

    res.render('members', {
      members,
      rateLimit, // optional: display remaining/reset in your layout or page
    });
  })
);

module.exports = router;
