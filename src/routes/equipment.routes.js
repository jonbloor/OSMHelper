const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const { osm, authHeader, getDynamicSections } = require('../services/osmApi');

const router = express.Router();

const ADULTS_SECTION_TYPE = 'adults';

function toForm(payload) {
  return new URLSearchParams(payload);
}

/**
 * Find Adults/Leaders section id dynamically if possible.
 */
async function resolveAdultsSectionId(accessToken) {
  const sections = await getDynamicSections(accessToken);

  // Prefer Adults/Leaders if present
  const adults = sections.find(s => s.section_type === 'adults');
  if (adults?.section_id) return adults.section_id;

  // Otherwise pick the first section that exists (keeps app usable)
  const first = sections[0];
  if (first?.section_id) return first.section_id;

  throw new Error('No sections available to resolve a section id for equipment.');
}

router.get('/equipment', requireAuth, asyncHandler(async (req, res) => {
  const accessToken = req.session.accessToken;
  const sectionId = await resolveAdultsSectionId(accessToken);

  const listsUrl =
    `/ext/quartermaster/?action=getListOfLists&section=${ADULTS_SECTION_TYPE}&sectionid=${sectionId}`;

  const listsResponse = await osm.get(listsUrl, { headers: authHeader(accessToken) });
  const listsData = listsResponse.data?.data || [];

  const equipment = [];

  for (const equipList of listsData) {
    const listId = equipList.id;
    const listName = equipList.name;

    const itemsUrl =
      `/ext/quartermaster/?action=getList&listid=${listId}&section=${ADULTS_SECTION_TYPE}&sectionid=${sectionId}`;

    const itemsResponse = await osm.get(itemsUrl, { headers: authHeader(accessToken) });
    const itemsData = itemsResponse.data?.data;

    if (!itemsData?.rows) continue;

    for (const rowId in itemsData.rows) {
      const item = itemsData.rows[rowId];

      equipment.push({
        itemRowId: rowId,
        listId,
        listName,
        itemName: item['1'] || '',
        description: item['2'] || '',
        location: item['3'] || '',
        notes: item['4'] || '',
        quantity: item['6'] || '',
        condition: item['5'] || '',
        broken: item['7'] || '',
        purchaseDate: item['8'] || '',
        renewalPrice: item['9'] || '',
      });
    }
  }

  res.render('equipment', { equipment });
}));

router.post('/add-equipment', requireAuth, asyncHandler(async (req, res) => {
  const accessToken = req.session.accessToken;
  const sectionId = await resolveAdultsSectionId(accessToken);

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

  // Map request fields to OSM column ids
  for (const field in req.body) {
    const columnId = columnMap[field];
    if (columnId) payload[`_${columnId}`] = req.body[field];
  }

  await osm.post(
    '/ext/quartermaster/?action=saveItemRowDetails',
    toForm(payload),
    {
      headers: {
        ...authHeader(accessToken),
        'Content-Type': 'application/x-www-form-urlencoded',
      },
    }
  );

  res.redirect('/equipment');
}));

router.post('/update-equipment', requireAuth, asyncHandler(async (req, res) => {
  const accessToken = req.session.accessToken;
  const sectionId = await resolveAdultsSectionId(accessToken);

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

    // Keep original behaviour: only send non-empty updates
    if (columnId && req.body[field]) {
      payload[`_${columnId}`] = req.body[field];
      updated = true;
    }
  }

  if (updated) {
    await osm.post(
      '/ext/quartermaster/?action=saveItemRowDetails',
      toForm(payload),
      {
        headers: {
          ...authHeader(accessToken),
          'Content-Type': 'application/x-www-form-urlencoded',
        },
      }
    );
  }

  res.redirect('/equipment');
}));

module.exports = router;
