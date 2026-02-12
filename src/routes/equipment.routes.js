// src/routes/equipment.routes.js
'use strict';

const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const osmApi = require('../services/osmApi');

const router = express.Router();

const ADULTS_SECTION_TYPE = 'adults';

function toForm(payload) {
  return new URLSearchParams(payload);
}

async function resolveAdultsSection(accessToken, session) {
  const sections = await osmApi.getDynamicSections(accessToken, session);
  const adults = sections.find(s => s.section_type === 'adults');
  if (adults?.section_id) return { sectionId: adults.section_id, sectionType: 'adults' };

  const first = sections[0];
  if (first?.section_id) return { sectionId: first.section_id, sectionType: first.section_type || 'adults' };

  return null;
}

// View/List Equipment
router.get('/equipment', requireAuth, asyncHandler(async (req, res) => {
  const accessToken = req.session.accessToken;
  const section = await resolveAdultsSection(accessToken, req.session);

  if (!section) {
    return res.render('error', { message: 'No suitable section found for equipment. Check your OSM permissions.' });
  }

  const { sectionId, sectionType } = section;

  const listsUrl = `/ext/quartermaster/?action=getListOfLists&section=${sectionType}&sectionid=${sectionId}`;
  const listsResponse = await osmApi.get(accessToken, listsUrl, { session: req.session });
  const listsData = listsResponse.data?.data || [];

  const equipment = [];

  for (const equipList of listsData) {
    const listId = equipList.listid;
    if (!listId) continue;

    const listName = equipList.name || `List ${listId}`;

    const itemsUrl = `/ext/quartermaster/?action=getItemsInList&section=${sectionType}&sectionid=${sectionId}&listid=${listId}`;
    const itemsResponse = await osmApi.get(accessToken, itemsUrl, { session: req.session });
    const itemsData = itemsResponse.data?.data?.items || [];

    itemsData.forEach(item => {
      equipment.push({
        itemRowId: item.rowid,
        listId,
        listName,
        itemName: item._1,
        description: item._2,
        location: item._3,
        notes: item._4,
        condition: item._5,
        quantity: item._6,
        broken: item._7,
        purchaseDate: item._8,
        renewalPrice: item._9,
      });
    });
  }

console.log('Lists response:', JSON.stringify(listsResponse.data, null, 2));
console.log('Items response for list', listId, ':', JSON.stringify(itemsResponse.data, null, 2));

  res.render('equipment-list', { equipment }); // New EJS for list
}));

// Add Equipment Form
router.get('/equipment/add', requireAuth, asyncHandler(async (req, res) => {
  const accessToken = req.session.accessToken;
  const section = await resolveAdultsSection(accessToken, req.session);

  if (!section) {
    return res.render('error', { message: 'No suitable section found for equipment.' });
  }

  // Fetch lists for dropdown
  const { sectionId, sectionType } = section;
  const listsUrl = `/ext/quartermaster/?action=getListOfLists&section=${sectionType}&sectionid=${sectionId}`;
  const listsResponse = await osmApi.get(accessToken, listsUrl, { session: req.session });
  const lists = listsResponse.data?.data || [];

  res.render('equipment-add', { lists }); // New EJS for add
}));

// Edit Equipment Form (with item ID)
router.get('/equipment/edit', requireAuth, asyncHandler(async (req, res) => {
  const accessToken = req.session.accessToken;
  const section = await resolveAdultsSection(accessToken, req.session);

  if (!section) {
    return res.render('error', { message: 'No suitable section found for equipment.' });
  }

  const { listId, itemRowId } = req.query;
  if (!listId || !itemRowId) return res.render('error', { message: 'Missing list or item ID.' });

  const { sectionId, sectionType } = section;
  const itemsUrl = `/ext/quartermaster/?action=getItemsInList&section=${sectionType}&sectionid=${sectionId}&listid=${listId}`;
  const itemsResponse = await osmApi.get(accessToken, itemsUrl, { session: req.session });
  const item = itemsResponse.data?.data?.items.find(i => i.rowid === itemRowId);

  if (!item) return res.render('error', { message: 'Item not found.' });

  const equipmentItem = {
    listId,
    itemRowId,
    itemName: item._1,
    description: item._2,
    location: item._3,
    notes: item._4,
    condition: item._5,
    quantity: item._6,
    broken: item._7,
    purchaseDate: item._8,
    renewalPrice: item._9,
  };

  res.render('equipment-edit', { item: equipmentItem }); // New EJS for edit
}));

// POST for Add
router.post('/equipment/add', requireAuth, asyncHandler(async (req, res) => {
  // Existing add logic from previous code
  res.redirect('/equipment');
}));

// POST for Edit
router.post('/equipment/edit', requireAuth, asyncHandler(async (req, res) => {
  // Existing update logic from previous code
  res.redirect('/equipment');
}));

module.exports = router;
