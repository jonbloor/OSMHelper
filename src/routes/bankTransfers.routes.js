// src/routes/bankTransfers.routes.js
'use strict';

const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const { limit } = require('../utils/concurrency');
const osmApi = require('../services/osmApi');

const router = express.Router();

async function resolveFinanceSection(accessToken, session) {
  const sections = await osmApi.getDynamicSections(accessToken, session);
  console.log('Sections with upgrades:', sections.map(s => ({ name: s.section_name, upgrades: s.upgrades })));
  const candidates = sections.filter(s => s.upgrades?.accounts === true).filter(s => s.section_id);

  if (candidates.length === 0) {
    console.log('No sections with accounts permission found in /oauth/resource.');
    return null;
  }

  for (const sec of candidates) {
    const sectionId = sec.section_id;
    const sectionType = sec.section_type || 'adults'; // Fallback to 'adults' if unknown

    try {
      const path = '/ext/finances/bank/';
      const params = {
        action: 'getBankAccounts',
        section: sectionType,
        sectionid: sectionId,
      };
      await osmApi.get(accessToken, path, { params, session, ttlMs: 60_000 });
      console.log(`Finance access confirmed for section: ${sectionType} (ID: ${sectionId})`);
      return { sectionId, sectionType };
    } catch (err) {
      const status = err.response?.status;
      if (![403, 404].includes(status)) throw err;
      console.log(`Access denied (status ${status}) for section: ${sectionType} (ID: ${sectionId}) – skipping.`);
    }
  }

  console.log('No accessible finance section found after checks.');
  return null;
}

router.post('/bank-transfers/select', requireAuth, asyncHandler(async (req, res) => {
  const sectionId = req.body.sectionId;
  const sectionType = req.body.sectionType || 'adults';
  req.session.financeSection = { sectionId, sectionType }; // Save for future
  res.redirect('/bank-transfers');
}));

router.get(
  '/bank-transfers',
  requireAuth,
  asyncHandler(async (req, res) => {
    const accessToken = req.session.accessToken;
    const financeSection = await resolveFinanceSection(accessToken, req.session);

    if (!financeSection) {
      const sections = await osmApi.getDynamicSections(accessToken, req.session);
      return res.render('bank-transfers-select', { sections }); // New EJS for selection
    }

    const { sectionId, sectionType } = financeSection;

    // Fetch bank accounts
    const accountsPath = '/ext/finances/bank/';
    const accountsParams = {
      action: 'getBankAccounts',
      section: sectionType,
      sectionid: sectionId,
    };
    const accountsResponse = await osmApi.get(accessToken, accountsPath, { params: accountsParams, session: req.session });
    console.log('Accounts response data:', JSON.stringify(accountsResponse?.data)); // Debug for structure
    const accountsData = Array.isArray(accountsResponse?.data?.items) ? accountsResponse.data.items : [];
    console.log('Found bank accounts:', accountsData.length);

    const transfers = [];
    const withLimit = limit(3); // Keep concurrency limit for safety

    // Date range: From a sensible start (e.g., 5 years ago) to today
    const today = new Date().toISOString().split('T')[0];
    const dateFrom = '2020-01-01'; // Adjust if needed; OSM might limit history

    await Promise.all(
      accountsData.map(account => withLimit(async () => {
        const accountId = account.bankaccountid;
        if (!accountId) return;

        const accountName = account.name || `Account ${accountId}`;
        console.log(`Fetching transactions for account: ${accountName} (ID: ${accountId})`);

        const transPath = '/ext/finances/bank/';
        const transParams = {
          action: 'getTransactions',
          bankaccountid: accountId,
          date_from: dateFrom,
          date_to: today,
        };

        const transResponse = await osmApi.get(accessToken, transPath, {
          params: transParams,
          session: req.session,
          ttlMs: 15 * 1000,
        });

        const transData = transResponse?.data?.items || [];
        console.log(`Transactions fetched for ${accountName}: ${transData.length}`);

        transData.forEach(trans => {
          if (trans.type === 'T') { // 'T' for transfer
            transfers.push({
              accountName,
              date: trans.date || 'N/A',
              reference: trans.reference || 'N/A',
              amount: Number(trans.amount).toFixed(2), // Assume pounds, format to 2 decimals
            });
          }
        });
      }))
    );

    // Sort by date descending, then account name
    transfers.sort((a, b) => b.date.localeCompare(a.date) || a.accountName.localeCompare(b.accountName));
    console.log('Total transfers collected:', transfers.length);

    res.render('bank-transfers', {
      transfers,
      fetchedAt: new Date().toLocaleString('en-GB'),
      transfersJSON: JSON.stringify(transfers), // For caching
    });
  })
);

module.exports = router;
