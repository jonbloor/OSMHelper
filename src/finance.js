const express = require('express');
const axios = require('axios');
const { OSM_API_BASE } = require('./config');

const router = express.Router();

// Fetch Bank Transfers
router.get('/bank-transfers', async (req, res) => {
  try {
    const sectionId = 71168;

    const accountsUrl = `${OSM_API_BASE}/v3/finances/accounting/bank_accounts/section/${sectionId}`;
    const accountsResponse = await axios.get(accountsUrl, {
      headers: { Authorization: `Bearer ${req.session.accessToken}` },
    });
    const accountsData = accountsResponse.data.data || [];

    let transfers = [];

    for (const account of accountsData) {
      const accountId = account.id;
      const accountName = account.name || `Account ${accountId}`;

      let page = 1;
      const perPage = 100;
      let hasMore = true;

      while (hasMore) {
        const transUrl = `${OSM_API_BASE}/v3/finances/accounting/bank_accounts/${accountId}/transactions?page=${page}&per_page=${perPage}&expense_cardholder_id=0&mode=all`;
        const transResponse = await axios.get(transUrl, {
          headers: { Authorization: `Bearer ${req.session.accessToken}` },
        });
        const transData = transResponse.data.data || [];

        transData.forEach(trans => {
          if (trans.is_transfer) {
            transfers.push({
              accountName,
              date: trans.date,
              reference: trans.reference || 'N/A',
              description: trans.description || '',
              amount: (trans.amount / 100).toFixed(2),
            });
          }
        });

        hasMore = transData.length === perPage;
        page++;
      }
    }

    // Sort: newest first, then by account name
    transfers.sort((a, b) => {
      if (b.date !== a.date) return b.date.localeCompare(a.date);
      return a.accountName.localeCompare(b.accountName);
    });

    res.render('bank-transfers', {
      transfers,
      fetchedAt: new Date().toLocaleString('en-GB')
    });
  } catch (error) {
    console.error('Bank transfers error:', error.message);
    res.status(500).render('error', { message: 'Could not fetch bank transfers. Check permissions or try again later.' });
  }
});

module.exports = router;
