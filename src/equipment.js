const express = require('express');
const axios = require('axios');
const { OSM_API_BASE } = require('./config');

const router = express.Router();

// Fetch Equipment
router.get('/equipment', async (req, res) => {
  try {
    const sectionType = 'adults';
    const sectionId = 71168;

    const listsUrl = `${OSM_API_BASE}/ext/quartermaster/?action=getListOfLists&section=${sectionType}&sectionid=${sectionId}`;
    const listsResponse = await axios.get(listsUrl, {
      headers: { Authorization: `Bearer ${req.session.accessToken}` },
    });
    const listsData = listsResponse.data.data;

    const equipment = [];

    for (const equipList of listsData) {
      const listId = equipList.id;
      const listName = equipList.name;

      const itemsUrl = `${OSM_API_BASE}/ext/quartermaster/?action=getList&listid=${listId}&section=${sectionType}&sectionid=${sectionId}`;
      const itemsResponse = await axios.get(itemsUrl, {
        headers: { Authorization: `Bearer ${req.session.accessToken}` },
      });
      const itemsData = itemsResponse.data.data;

      const columnsMap = {};
      itemsData.columns.forEach(col => {
        columnsMap[col.columnid] = col.columnname;
      });

      for (const rowId in itemsData.rows) {
        const item = itemsData.rows[rowId];
        equipment.push({
          itemRowId: rowId,
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
  } catch (error) {
    console.error('Equipment fetch error:', error.message);
    res.status(500).send('Error fetching equipment');
  }
});

// Add Equipment Item
router.post('/add-equipment', async (req, res) => {
  const { listId, name, description, location, notes, condition, quantity, broken, purchaseDate, renewalPrice } = req.body;

  try {
    const sectionType = 'adults';
    const sectionId = 71168;

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
      section: sectionType,
      sectionid: sectionId,
    };

    for (const field in req.body) {
      const columnId = columnMap[field];
      if (columnId) {
        payload[`_${columnId}`] = req.body[field];
      }
    }

    await axios.post(`${OSM_API_BASE}/ext/quartermaster/?action=saveItemRowDetails`, new URLSearchParams(payload), {
      headers: {
        Authorization: `Bearer ${req.session.accessToken}`,
        'Content-Type': 'application/x-www-form-urlencoded',
      },
    });

    res.redirect('/equipment');
  } catch (error) {
    console.error('Add equipment error:', error.message);
    res.status(500).send('Error adding equipment');
  }
});

// Update Equipment Item
router.post('/update-equipment', async (req, res) => {
  const { listId, itemRowId, name, description, location, notes, condition, quantity, broken, purchaseDate, renewalPrice } = req.body;

  try {
    const sectionType = 'adults';
    const sectionId = 71168;

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
      section: sectionType,
      sectionid: sectionId,
      itemrowidentifier: itemRowId,
    };

    let updated = false;

    for (const field in req.body) {
      if (['listId', 'itemRowId'].includes(field)) continue;
      const columnId = columnMap[field];
      if (columnId && req.body[field]) {
        payload[`_${columnId}`] = req.body[field];
        updated = true;
      }
    }

    if (updated) {
      await axios.post(`${OSM_API_BASE}/ext/quartermaster/?action=saveItemRowDetails`, new URLSearchParams(payload), {
        headers: {
          Authorization: `Bearer ${req.session.accessToken}`,
          'Content-Type': 'application/x-www-form-urlencoded',
        },
      });
    }

    res.redirect('/equipment');
  } catch (error) {
    console.error('Update equipment error:', error.message);
    res.status(500).send('Error updating equipment');
  }
});

module.exports = router;
