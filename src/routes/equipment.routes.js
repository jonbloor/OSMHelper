// src/routes/equipment.routes.js
const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const osmApi = require('../services/osmApi');

const router = express.Router();

const ADULTS_SECTION_TYPE = 'adults';

function toForm(payload) {
  return new URLSearchParams(payload);
}

async function resolveAdultsSectionId(accessToken, session) {
  const sections = await osmApi.getDynamicSections(accessToken, session);
  console.log('Sections for equipment:', sections); // Debug log

  const adults = sections.find(s => s.section_type === 'adults');
  if (adults?.section_id) return adults.section_id;

  const first = sections[0];
  if (first?.section_id) return first.section_id;

  return null; // Changed: return null instead of throwing
}

router.get('/equipment', requireAuth, asyncHandler(async (req, res) => {
  const accessToken = req.session.accessToken;
  const sectionId = await resolveAdultsSectionId(accessToken, req.session);

  if (!sectionId) {
    return res.render('error', { message: 'Oops! No suitable section found for equipment. Check your OSM permissions or try again later.' });
  }

  const listsUrl = `/ext/quartermaster/?action=getListOfLists&section=${ADULTS_SECTION_TYPE}&sectionid=${sectionId}`;

  const listsResponse = await osmApi.get(accessToken, listsUrl, { session: req.session });
  const listsData = listsResponse.data?.data || [];

  const equipment = [];

  for (const equipList of listsData) {
    const listId = equipList.id;
    const listName = equipList.name;

    const itemsUrl = `/ext/quartermaster/?action=getList&listid=${listId}&section=${ADULTS_SECTION_TYPE}&sectionid=${sectionId}`;

    const itemsResponse = await osmApi.get(accessToken, itemsUrl, { session: req.session });
    const itemsData = itemsResponse.data?.data || [];

    itemsData.forEach(item => {
      equipment.push({
        itemRowId: item.rowidentifier,
        listName,
        itemName: item._1 || '',
        description: item._2 || '',
        location: item._3 || '',
        notes: item._4 || '',
        quantity: item._6 || '',
        condition: item._5 || '',
        broken: item._7 || '',
        purchaseDate: item._8 || '',
        renewalPrice: item._9 || '',
      });
    });
  }

  res.render('equipment', { equipment });
}));

router.post('/add-equipment', requireAuth, asyncHandler(async (req, res) => {
  const accessToken = req.session.accessToken;
  const sectionId = await resolveAdultsSectionId(accessToken, req.session);

  if (!sectionId) {
    return res.render('error', { message: 'Oops! No suitable section found for adding equipment. Check your OSM permissions.' });
  }

  const { listId } = req.body;

  const columnMap = {
    name: '1',
    description: '2',
    location: '3',
    notes: '4',
    condition: '5',
    quantity: '6',
    broken: '7',
    purchaseDate: '8',
    renewalPrice: '9',
  };

  const payload = { listid: listId, section: ADULTS_SECTION_TYPE, sectionid: sectionId };

  for (const field in req.body) {
    const columnId = columnMap[field];
    if (columnId) payload[`_${columnId}`] = req.body[field];
  }

  await osmApi.post(accessToken, '/ext/quartermaster/?action=saveItemRowDetails', { data: toForm(payload) });

  res.redirect('/equipment');
}));

router.post('/update-equipment', requireAuth, asyncHandler(async (req, res) => {
  const accessToken = req.session.accessToken;
  const sectionId = await resolveAdultsSectionId(accessToken, req.session);

  if (!sectionId) {
    return res.render('error', { message: 'Oops! No suitable section found for updating equipment. Check your OSM permissions.' });
  }

  const { listId, itemRowId } = req.body;

  const columnMap = {
    name: '1',
    description: '2',
    location: '3',
    notes: '4',
    condition: '5',
    quantity: '6',
    broken: '7',
    purchaseDate: '8',
    renewalPrice: '9',
  };

  const payload = {
    listid: listId,
    section: ADULTS_SECTION_TYPE,
    sectionid: sectionId,
    itemrowidentifier: itemRowId,
  };

  let updated = false;

  for (const field in req.body) {
    if (field === 'listId' || field === 'itemRowId') continue;
    const columnId = columnMap[field];
    if (columnId && req.body[field]) {
      payload[`_${columnId}`] = req.body[field];
      updated = true;
    }
  }

  if (updated) {
    await osmApi.post(accessToken, '/ext/quartermaster/?action=saveItemRowDetails', { data: toForm(payload) });
  }

  res.redirect('/equipment');
}));

module.exports = router;
