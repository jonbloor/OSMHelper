// src/routes/bankTransfers.routes.js
'use strict';

const express = require('express');
const { requireAuth } = require('../middleware/requireAuth');
const { asyncHandler } = require('../utils/asyncHandler');
const { limit } = require('../utils/concurrency');
const osmApi = require('../services/osmApi');

const router = express.Router();

/**
 * Bank Transfers
 * - Find a section we have finance access to (no hard-coded section id)
 * - Fetch bank accounts for that section
 * - Iterate transactions (paged)
 * - Filter to transfers
 * - Concurrency limit applied per-account fetch
 */

function extractSectionsFromOauthResource(data) {
  const sections =
    data?.sections ||
    data?.data?.sections ||
    data?.items ||
    data?.data?.items ||
    data ||
    [];

  return Array.isArray(sections) ? sections : [];
}

async function getDynamicSections(accessToken, req) {
  const data = await osmApi.get(accessToken, '/oauth/resource', {
    session: req.session,
    ttlMs: 5 * 60 * 1000, // 5 minutes
  });

  return extractSectionsFromOauthResource(data);
}

async function resolveFinanceSectionId(accessToken, req) {
  const sections = await getDynamicSections(accessToken, req);

  // Candidate ordering: adults first, then everything else
  const candidates = [
    ...sections.filter(s => s.section_type === 'adults'),
    ...sections.filter(s => s.section_type !== 'adults'),
  ]
    .map(s => s.section_id)
    .filter(Boolean)
    .map(String);

  // Cache the *result* for a short period so we don’t probe every time
  // This uses osmApi.get cache wrapper with a synthetic key by calling a harmless cached GET.
  // Simpler: do our own memo via osmApi cache store is possible later, but keep it route-local.
  for (const sectionId of candidates) {
    try {
      const path = `/v3/finances/accounting/bank_accounts/section/${sectionId}`;

      // Short TTL here so it’s not constantly re-checking permissions
      await osmApi.get(accessToken, path, {
        session: req.session,
        ttlMs: 2 * 60 * 1000, // 2 minutes
      });

      return sectionId; // first one that works
    } catch {
      // Try next section
    }
  }

  const err = new Error('Could not find a section id with finance access.');
  err.status = 403;
  throw err;
}

router.get(
  '/bank-transfers',
  requireAuth,
  asyncHandler(async (req, res) => {
    const accessToken = req.session.accessToken;

    const sectionId = await resolveFinanceSectionId(accessToken, req);

    const accountsPath = `/v3/finances/accounting/bank_accounts/section/${sectionId}`;

    const accountsResponse = await osmApi.get(accessToken, accountsPath, {
      session: req.session,
      ttlMs: 60 * 1000, // 1 minute
    });

    const accountsData = accountsResponse?.data || accountsResponse?.data?.data || accountsResponse || [];
    const accounts = Array.isArray(accountsData) ? accountsData : [];

    const transfers = [];

    // Fetch transactions for up to 3 accounts at once
    const runLimited = limit(3);

    await Promise.all(
      accounts.map(account =>
        runLimited(async () => {
          const accountId = account.id;
          if (!accountId) return;

          const accountName = account.name || `Account ${accountId}`;

          let page = 1;
          const perPage = 100;

          while (true) {
            const transPath = `/v3/finances/accounting/bank_accounts/${accountId}/transactions`;

            // IMPORTANT: don’t cache paged transaction pulls aggressively
            // (we’re already rate-limit protected + concurrency limited)
            const transResponse = await osmApi.get(accessToken, transPath, {
              params: {
                page,
                per_page: perPage,
                expense_cardholder_id: 0,
                mode: 'all',
              },
              session: req.session,
              ttlMs: 15 * 1000, // tiny cache window to smooth refresh/reloads
            });

            const transData =
              transResponse?.data ||
              transResponse?.data?.data ||
              transResponse ||
              [];

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
        })
      )
    );

    transfers.sort(
      (a, b) =>
        String(b.date).localeCompare(String(a.date)) ||
        String(a.accountName).localeCompare(String(b.accountName))
    );

    const rateLimit = osmApi.getRateLimitSnapshot(accessToken);

    res.render('bank-transfers', {
      groupName: req.session.groupName || '4th Ashby Scout Group',
      transfers,
      fetchedAt: new Date().toLocaleString('en-GB'),
      rateLimit, // optional if you want to show it
    });
  })
);

module.exports = router;

