// src/routes/bankTransfers.routes.js
'use strict';

const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const { limit } = require('../utils/concurrency');
const osmApi = require('../services/osmApi');

const router = express.Router();

async function resolveFinanceSectionId(accessToken, session) {
  const sections = await osmApi.getDynamicSections(accessToken, session);
  console.log('Sections for bank transfers:', sections); // Debug log

  const candidates = [
    ...sections.filter(s => s.section_type === 'adults'),
    ...sections.filter(s => s.section_type !== 'adults'),
  ].map(s => s.section_id).filter(Boolean).map(String);

  for (const sectionId of candidates) {
    try {
      const path = `/v3/finances/accounting/bank_accounts/section/${sectionId}`;
      await osmApi.get(accessToken, path, { session, ttlMs: 60_000 });
      return sectionId;
    } catch (err) {
      if (err.response?.status !== 403) throw err;
      console.warn(`Finance access denied for section ${sectionId}`);
    }
  }

  return null; // Changed: return null instead of throwing
}

router.get(
  '/bank-transfers',
  requireAuth,
  asyncHandler(async (req, res) => {
    const accessToken = req.session.accessToken;
    const sectionId = await resolveFinanceSectionId(accessToken, req.session);

    if (!sectionId) {
      return res.render('error', { message: 'Drat! No section with finance access found. Ensure your OSM account has bank permissions.' });
    }

    const accountsPath = `/v3/finances/accounting/bank_accounts/section/${sectionId}`;
    const accountsResponse = await osmApi.get(accessToken, accountsPath, { session: req.session });
    const accountsData = accountsResponse?.data || [];

    const transfers = [];
    const withLimit = limit(3);

    await Promise.all(
      accountsData.map(account => withLimit(async () => {
        const accountId = account.id;
        if (!accountId) return;

        const accountName = account.name || `Account ${accountId}`;

        let page = 1;
        const perPage = 100;

        while (true) {
          const transPath = `/v3/finances/accounting/bank_accounts/${accountId}/transactions`;
          const transResponse = await osmApi.get(accessToken, transPath, {
            params: {
              page,
              per_page: perPage,
              expense_cardholder_id: 0,
              mode: 'all',
            },
            session: req.session,
            ttlMs: 15 * 1000,
          });

          const transData = transResponse?.data || [];
          const rows = Array.isArray(transData) ? transData : [];

          for (const trans of rows) {
            if (trans && trans.is_transfer) {
              transfers.push({
                accountName,
                date: trans.date,
                reference: trans.reference || 'N/A',
                amount: (Number(trans.amount || 0) / 100).toFixed(2),
              });
            }
          }

          if (rows.length < perPage) break;
          page += 1;
        }
      }))
    );

    transfers.sort((a, b) => String(b.date).localeCompare(String(a.date)) || String(a.accountName).localeCompare(String(b.accountName)));

    res.render('bank-transfers', {
      groupName: req.session.groupName || '4th Ashby Scout Group',
      transfers,
      fetchedAt: new Date().toLocaleString('en-GB'),
    });
  })
);

module.exports = router;
