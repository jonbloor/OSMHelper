// src/routes/waitingList.routes.js
const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const osmApi = require('../services/osmApi');
const { limit } = require('../utils/concurrency');

const router = express.Router();

function calculateIdealSection(age) {
  if (age < 4) return 'Too Young';
  if (age < 5.75) return 'Squirrels';
  if (age < 7.5) return 'Beavers';
  if (age < 10) return 'Cubs';
  if (age < 13.5) return 'Scouts';
  return 'Explorers';
}

router.get('/waiting-list', requireAuth, asyncHandler(async (req, res) => {
  const accessToken = req.session.accessToken;
  const sections = await osmApi.getDynamicSections(accessToken, req.session);

  const waitingSection = sections.find(sec =>
    sec.section_type === 'waiting' ||
    String(sec.section_name || '').toLowerCase().includes('waiting')
  );

  if (!waitingSection) {
    return res.status(404).render('error', { message: 'No waiting list section found.' });
  }

  const waitingSectionId = waitingSection.section_id;
  const waitingSectionType = waitingSection.section_type || 'waiting';

  const listUrl = `/ext/members/contact/?action=getListOfMembers&sectionid=${waitingSectionId}&termid=-1&section=${waitingSectionType}&sort=dob`;

  const listResponse = await osmApi.get(accessToken, listUrl, { session: req.session });
  const listData = listResponse.data?.items || [];

  const applicants = [];
  const today = new Date();
  const todayMillis = today.getTime();

  const withLimit = limit(5);

  for (const applicant of listData) {
    await withLimit(async () => {
      try {
        const individualUrl = `/ext/members/contact/?action=getIndividual&sectionid=${waitingSectionId}&scoutid=${applicant.scoutid}&termid=-1&context=members`;

        const individualResponse = await osmApi.get(accessToken, individualUrl, { session: req.session });
        const individualData = individualResponse.data?.data || {};

        const dob = new Date(individualData.dob);
        const ageMillis = Number.isFinite(dob.getTime()) ? (todayMillis - dob.getTime()) : null;
        const ageMonths = ageMillis ? Math.floor(ageMillis / (30.4375 * 24 * 60 * 60 * 1000)) : null;
        const ageYears = ageMonths ? Math.floor(ageMonths / 12) : null;
        const ageRemainMonths = ageMonths ? ageMonths % 12 : null;
        const age = ageMonths ? ageMonths / 12 : null; // Fractional for scoring
        const ageDisplay = (ageYears != null) ? `${ageYears} y ${ageRemainMonths} m` : 'Unknown';

        const willingToHelp = individualData.customfields?.customfield_123 || 'N';

        const joinDate = new Date(individualData.joined || individualData.applicationdate || individualData.started);
        const joinTime = joinDate.getTime();
        const timeOnList = Number.isFinite(joinTime)
          ? Math.floor((todayMillis - joinTime) / (24 * 60 * 60 * 1000))
          : 0;

        const customUrl = `/ext/customdata/?action=getData&section_id=${waitingSectionId}&associated_id=${applicant.scoutid}&associated_type=member&context=members`;
        const customResponse = await osmApi.get(accessToken, customUrl, { session: req.session });
        const customGroups = customResponse.data?.data || [];

        let leadersNotes = '';
        let joiningComments = '';
        let placeAccepted = '';

        const customGroup = customGroups.find(group => group.identifier === 'customisable_data');
        if (customGroup && Array.isArray(customGroup.columns)) {
          customGroup.columns.forEach(col => {
            if (col.varname === 'cf_notes') leadersNotes = col.value || '';
            if (col.varname === 'cf_joining_comments') joiningComments = col.value || '';
            if (col.varname === 'cf_place_accepted_') placeAccepted = col.value || '';
          });
        }

        const targetJoinDateRaw = individualData.startedsection || '';
        const targetJoinDate = targetJoinDateRaw ? new Date(targetJoinDateRaw).toLocaleDateString('en-GB', {
          day: '2-digit',
          month: '2-digit',
          year: '2-digit'
        }) : '';

        const ageScore = age ?? 0;
        const willingnessBonus = (willingToHelp === 'Y') ? 20 : 0;
        const timeScore = timeOnList / 30;

        const scoreNum = ageScore * 3 + willingnessBonus + timeScore;

        applicants.push({
          firstName: applicant.firstname,
          lastName: applicant.lastname,
          age: ageDisplay,
          timeOnList,
          willingToHelp,
          leadersNotes,
          joiningComments,
          placeAccepted,
          targetJoinDate,
          idealSection: calculateIdealSection(age ?? 0),
          scoreNum,
          score: scoreNum.toFixed(1),
          rank: 0,
        });
      } catch (e) {
        console.warn(`Individual fetch skipped for ${applicant.scoutid}:`, e.message);
        applicants.push({
          firstName: applicant.firstname,
          lastName: applicant.lastname,
          age: 'Unknown',
          timeOnList: 'N/A',
          willingToHelp: 'N/A',
          leadersNotes: 'N/A',
          joiningComments: 'N/A',
          placeAccepted: 'N/A',
          targetJoinDate: '',
          idealSection: 'Unknown',
          scoreNum: -Infinity,
          score: 'N/A',
          rank: 0,
        });
      }
    });
  }

  applicants.sort((a, b) => (b.scoreNum - a.scoreNum));
  applicants.forEach((app, idx) => { app.rank = idx + 1; });

  res.render('waiting-list', {
    applicants,
    fetchedAt: new Date().toLocaleString('en-GB', {
      day: '2-digit',
      month: '2-digit',
      year: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false
    })
  });
}));

module.exports = router;
