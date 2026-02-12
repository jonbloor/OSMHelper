const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const { osm, authHeader, getDynamicSections } = require('../services/osmApi');

const router = express.Router();

router.get('/waiting-list', requireAuth, asyncHandler(async (req, res) => {
  const sections = await getDynamicSections(req.session.accessToken);

  const waitingSection = sections.find(sec =>
    sec.section_type === 'waiting' ||
    String(sec.section_name || '').toLowerCase().includes('waiting')
  );

  if (!waitingSection) {
    return res.status(404).render('error', { message: 'No waiting list section found.' });
  }

  const waitingSectionId = waitingSection.section_id;
  const waitingSectionType = waitingSection.section_type || 'waiting';

  const listUrl =
    `/ext/members/contact/?action=getListOfMembers&sectionid=${waitingSectionId}&termid=-1&section=${waitingSectionType}&sort=dob`;

  const listResponse = await osm.get(listUrl, { headers: authHeader(req.session.accessToken) });
  const listData = listResponse.data?.items || [];

  const applicants = [];
  const today = new Date();
  const todayMillis = today.getTime();

  for (const applicant of listData) {
    try {
      const individualUrl =
        `/ext/members/contact/?action=getIndividual&sectionid=${waitingSectionId}&scoutid=${applicant.scoutid}&termid=-1&context=members`;

      const individualResponse = await osm.get(individualUrl, { headers: authHeader(req.session.accessToken) });
      const individualData = individualResponse.data?.data || {};

      const dob = new Date(individualData.dob);
      const ageNum = Number.isFinite(dob.getTime())
        ? Math.floor((todayMillis - dob.getTime()) / (365.25 * 24 * 60 * 60 * 1000))
        : null;

      // NOTE: this custom field id is group-specific; keep it configurable later.
      const willingToHelp = individualData.customfields?.customfield_123 || 'N';

      const joinDate = new Date(individualData.joined || individualData.applicationdate || individualData.started);
      const joinTime = joinDate.getTime();
      const timeOnList = Number.isFinite(joinTime)
        ? Math.floor((todayMillis - joinTime) / (24 * 60 * 60 * 1000))
        : 0;

      const ageScore = ageNum ?? 0;
      const willingnessBonus = (willingToHelp === 'Y') ? 20 : 0;
      const timeScore = timeOnList / 30;

      const scoreNum = ageScore * 3 + willingnessBonus + timeScore;

      applicants.push({
        firstName: applicant.firstname,
        lastName: applicant.lastname,
        age: ageNum ?? 'Unknown',
        timeOnList,
        willingToHelp,
        scoreNum,
        score: scoreNum.toFixed(1),
        rank: 0,
      });
    } catch (e) {
      console.warn(`Individual fetch skipped for ${applicant.scoutid}:`, e.message);
      applicants.push({
        firstName: applicant.firstname,
        lastName: applicant.lastname,
        age: 'N/A',
        timeOnList: 'N/A',
        willingToHelp: 'N/A',
        scoreNum: -Infinity,
        score: 'N/A',
        rank: 0,
      });
    }
  }

  applicants.sort((a, b) => (b.scoreNum - a.scoreNum));
  applicants.forEach((app, idx) => { app.rank = idx + 1; });

  res.render('waiting-list', {
    applicants,
    fetchedAt: new Date().toLocaleString('en-GB'),
  });
}));

module.exports = router;
