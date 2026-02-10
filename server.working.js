require('dotenv').config();
const express = require('express');
const session = require('express-session');
const { AuthorizationCode } = require('simple-oauth2');
const axios = require('axios');
const bodyParser = require('body-parser');
const path = require('path');

const app = express();
const port = process.env.PORT || 3000;

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));
app.use(bodyParser.urlencoded({ extended: true }));
app.use(express.static('public'));
app.use(session({
  secret: process.env.SESSION_SECRET,
  resave: false,
  saveUninitialized: true,
}));

const OSM_API_BASE = 'https://www.onlinescoutmanager.co.uk';
const REDIRECT_URI = process.env.REDIRECT_URI;

// Friendly section type names
const FRIENDLY_SECTION_TYPES = {
  'earlyyears': 'Squirrels',
  'beavers':    'Beavers',
  'cubs':       'Cubs',
  'scouts':     'Scouts',
  'explorers':  'Explorers',
  'adults':     'Adults / Leaders',
  'waiting':    'Waiting List',
  'unknown':    'Other'
};

// Default capacities per type (user can override per section)
const DEFAULT_CAPACITIES = {
  'earlyyears': 18,
  'beavers':    24,
  'cubs':       30,
  'scouts':     36
};

// In-memory user overrides (resets on restart)
let sectionCapacities = {};

// OAuth client
const client = new AuthorizationCode({
  client: {
    id: process.env.CLIENT_ID,
    secret: process.env.CLIENT_SECRET,
  },
  auth: {
    tokenHost: OSM_API_BASE,
    authorizePath: '/oauth/authorize',
    tokenPath: '/oauth/token',
  },
});

const requireAuth = (req, res, next) => {
  if (!req.session.accessToken) {
    return res.redirect('/auth');
  }
  next();
};

// Helpers
async function getRateLimitInfo(accessToken) {
  try {
    const res = await axios.get(`${OSM_API_BASE}/oauth/resource`, {
      headers: { Authorization: `Bearer ${accessToken}` },
    });
    const h = res.headers;
    return {
      limit: h['x-ratelimit-limit'] || 'Unknown',
      remaining: h['x-ratelimit-remaining'] || 'Unknown',
      reset: h['x-ratelimit-reset']
        ? new Date(h['x-ratelimit-reset'] * 1000).toLocaleString('en-GB')
        : 'Unknown',
    };
  } catch (err) {
    console.warn('Rate limit fetch failed:', err.message);
    return { limit: 'Error', remaining: 'Error', reset: 'Error' };
  }
}

async function getDynamicSections(accessToken) {
  try {
    const res = await axios.get(`${OSM_API_BASE}/oauth/resource`, {
      headers: { Authorization: `Bearer ${accessToken}` },
    });
    return res.data.data.sections || [];
  } catch (err) {
    console.error('Sections fetch error:', err.message);
    return [];
  }
}

// Main Dashboard
app.get('/', requireAuth, async (req, res) => {
  let rateInfo = { limit: 'N/A', remaining: 'N/A', reset: 'N/A' };
  let sectionsWithCapacity = [];

  if (req.session.accessToken) {
    rateInfo = await getRateLimitInfo(req.session.accessToken);
    const sections = await getDynamicSections(req.session.accessToken);

    sectionsWithCapacity = sections.map(sec => ({
      id: sec.section_id,
      name: sec.section_name,
      type: sec.section_type,
      friendlyType: FRIENDLY_SECTION_TYPES[sec.section_type] || sec.section_type || 'Unknown',
      capacity: sectionCapacities[sec.section_id] ?? DEFAULT_CAPACITIES[sec.section_type] ?? 'Not set'
    }));
  }

  res.render('index', {
    authorized: !!req.session.accessToken,
    rateInfo,
    sections: sectionsWithCapacity
  });
});

// Update capacity
app.post('/update-capacity', requireAuth, (req, res) => {
  const { sectionId, capacity } = req.body;
  if (sectionId && capacity !== '') {
    sectionCapacities[sectionId] = parseInt(capacity, 10);
  }
  res.redirect('/');
});

// Auth routes
app.get('/auth', (req, res) => {
  const uri = client.authorizeURL({
    redirect_uri: REDIRECT_URI,
    scope: 'section:member:read section:quartermaster:write section:finance:read',
    access_type: 'offline',
  });
  res.redirect(uri);
});

app.get('/callback', async (req, res) => {
  const { code } = req.query;
  try {
    const result = await client.getToken({ code, redirect_uri: REDIRECT_URI });
    req.session.accessToken = result.token.access_token;
    res.redirect('/');
  } catch (error) {
    console.error('Auth callback error:', error.message, error.response?.data);
    res.status(500).send('Authentication failed: ' + (error.response?.data?.error_description || error.message));
  }
});

// Members list
app.get('/members', requireAuth, async (req, res) => {
  try {
    const sections = await getDynamicSections(req.session.accessToken);
    const sectionsMap = {};
    sections.forEach(sec => {
      sectionsMap[sec.section_id] = {
        name: sec.section_name,
        type: sec.section_type,
        terms: sec.terms,
        group_id: sec.group_id,
      };
    });

    const youthTypes = ['earlyyears', 'beavers', 'cubs', 'scouts', 'explorers', 'adults'];
    const sectionIds = Object.keys(sectionsMap).filter(id => youthTypes.includes(sectionsMap[id].type));

    const members = [];
    const today = new Date();

    for (const sectionId of sectionIds) {
      const sec = sectionsMap[sectionId];
      const currentTerm = sec.terms.find(t => new Date(t.startdate) <= today && new Date(t.enddate) >= today);
      const termId = currentTerm ? currentTerm.term_id : sec.terms[sec.terms.length - 1]?.term_id;
      if (!termId) continue;

      const listUrl = `${OSM_API_BASE}/ext/members/contact/?action=getListOfMembers&sectionid=${sectionId}&termid=${termId}&section=${sec.type}&sort=lastname`;
      const listResponse = await axios.get(listUrl, {
        headers: { Authorization: `Bearer ${req.session.accessToken}` },
      });
      const listData = listResponse.data.items || [];

      for (const member of listData) {
        try {
          const individualUrl = `${OSM_API_BASE}/ext/members/contact/?action=getIndividual&sectionid=${sectionId}&scoutid=${member.scoutid}&termid=${termId}&context=members`;
          const individualResponse = await axios.get(individualUrl, {
            headers: { Authorization: `Bearer ${req.session.accessToken}` },
          });
          const individualData = individualResponse.data.data;

          members.push({
            sectionType: sec.type,
            sectionName: sec.name,
            memberId: member.scoutid,
            firstName: member.firstname,
            lastName: member.lastname,
            dob: individualData.dob || '',
            patrol: member.patrol || '',
            started: individualData.startedsection || '',
            joined: individualData.started || individualData.joinedgroup || '',
            age: member.age || individualData.age || '',
          });
        } catch (e) {
          members.push({
            sectionType: sec.type,
            sectionName: sec.name,
            memberId: member.scoutid,
            firstName: member.firstname,
            lastName: member.lastname,
            dob: '',
            patrol: member.patrol || '',
            started: '',
            joined: '',
            age: member.age || '',
          });
        }
      }
    }

    res.render('members', { members });
  } catch (error) {
    console.error('Members fetch error:', error.message);
    res.status(500).send('Error fetching members');
  }
});

// Waiting List
app.get('/waiting-list', requireAuth, async (req, res) => {
  try {
    const sections = await getDynamicSections(req.session.accessToken);
    const waitingSection = sections.find(sec =>
      sec.section_type === 'waiting' ||
      sec.section_name.toLowerCase().includes('waiting')
    );

    if (!waitingSection) {
      throw new Error('No waiting list section found.');
    }

    const waitingSectionId = waitingSection.section_id;
    const waitingSectionType = waitingSection.section_type || 'waiting';

    const listUrl = `${OSM_API_BASE}/ext/members/contact/?action=getListOfMembers&sectionid=${waitingSectionId}&termid=-1&section=${waitingSectionType}&sort=dob`;

    const listResponse = await axios.get(listUrl, {
      headers: { Authorization: `Bearer ${req.session.accessToken}` },
    });

    const listData = listResponse.data.items || [];

    const applicants = [];
    const today = new Date();
    const todayMillis = today.getTime();

    for (const applicant of listData) {
      try {
        const individualUrl = `${OSM_API_BASE}/ext/members/contact/?action=getIndividual&sectionid=${waitingSectionId}&scoutid=${applicant.scoutid}&termid=-1&context=members`;
        const individualResponse = await axios.get(individualUrl, {
          headers: { Authorization: `Bearer ${req.session.accessToken}` },
        });
        const individualData = individualResponse.data.data;

        const dob = new Date(individualData.dob);
        const age = Math.floor((todayMillis - dob.getTime()) / (365.25 * 24 * 60 * 60 * 1000)) || 'Unknown';

        const willingToHelp = individualData.customfields?.customfield_123 || 'N';

        const joinDate = new Date(individualData.joined || individualData.applicationdate || individualData.started);
        const timeOnList = Math.floor((todayMillis - joinDate.getTime()) / (24 * 60 * 60 * 1000)) || 0;

        const ageScore = isNaN(age) ? 0 : age;
        const willingnessBonus = (willingToHelp === 'Y') ? 20 : 0;
        const timeScore = timeOnList / 30;
        const score = ageScore * 3 + willingnessBonus + timeScore;

        applicants.push({
          firstName: applicant.firstname,
          lastName: applicant.lastname,
          age,
          timeOnList,
          willingToHelp,
          score: score.toFixed(1),
          rank: 0
        });
      } catch (e) {
        console.warn(`Individual fetch skipped for ${applicant.scoutid}:`, e.message);
        applicants.push({
          firstName: applicant.firstname,
          lastName: applicant.lastname,
          age: 'N/A',
          timeOnList: 'N/A',
          willingToHelp: 'N/A',
          score: 'N/A',
          rank: 0
        });
      }
    }

    applicants.sort((a, b) => b.score - a.score);
    applicants.forEach((app, idx) => { app.rank = idx + 1; });

    res.render('waiting-list', {
      applicants,
      fetchedAt: new Date().toLocaleString('en-GB')
    });
  } catch (error) {
    console.error('Waiting list error:', error.message, error.response?.data);
    res.status(500).render('error', { message: 'Waiting list fetch failed.' });
  }
});

// Equipment routes (as previously working)
app.get('/equipment', requireAuth, async (req, res) => {
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
    res.status(500).send('Error fetching equipment');
  }
});

app.post('/add-equipment', requireAuth, async (req, res) => {
  const { listId, name, description, location, notes, condition, quantity, broken, purchaseDate, renewalPrice } = req.body;
  try {
    const sectionType = 'adults';
    const sectionId = 71168;
    const columnMap = {
      name: '1', description: '2', location: '3', notes: '4',
      condition: '5', quantity: '6', broken: '7', purchaseDate: '8', renewalPrice: '9',
    };
    const payload = { listid: listId, section: sectionType, sectionid: sectionId };
    for (const field in req.body) {
      const columnId = columnMap[field];
      if (columnId) payload[`_${columnId}`] = req.body[field];
    }
    await axios.post(`${OSM_API_BASE}/ext/quartermaster/?action=saveItemRowDetails`, new URLSearchParams(payload), {
      headers: {
        Authorization: `Bearer ${req.session.accessToken}`,
        'Content-Type': 'application/x-www-form-urlencoded',
      },
    });
    res.redirect('/equipment');
  } catch (error) {
    res.status(500).send('Error adding equipment');
  }
});

app.post('/update-equipment', requireAuth, async (req, res) => {
  const { listId, itemRowId, name, description, location, notes, condition, quantity, broken, purchaseDate, renewalPrice } = req.body;
  try {
    const sectionType = 'adults';
    const sectionId = 71168;
    const columnMap = {
      name: '1', description: '2', location: '3', notes: '4',
      condition: '5', quantity: '6', broken: '7', purchaseDate: '8', renewalPrice: '9',
    };
    const payload = { listid: listId, section: sectionType, sectionid: sectionId, itemrowidentifier: itemRowId };
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
    res.status(500).send('Error updating equipment');
  }
});

// Bank Transfers
app.get('/bank-transfers', requireAuth, async (req, res) => {
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
    transfers.sort((a, b) => b.date.localeCompare(a.date) || a.accountName.localeCompare(b.accountName));
    res.render('bank-transfers', { transfers, fetchedAt: new Date().toLocaleString('en-GB') });
  } catch (error) {
    console.error('Bank transfers error:', error.message);
    res.status(500).render('error', { message: 'Could not fetch bank transfers.' });
  }
});

// Membership Dashboard (stub – expand next)
app.get('/membership-dashboard', requireAuth, async (req, res) => {
  try {
    const sections = await getDynamicSections(req.session.accessToken);
    if (sections.length === 0) {
      return res.render('membership-dashboard', {
        sectionsData: [],
        totalMembers: 0,
        totalWaiting: 0,
        membersUpdated: new Date().toLocaleString('en-GB'),
        waitingUpdated: new Date().toLocaleString('en-GB'),
        message: 'No sections accessible.'
      });
    }

    const today = new Date();
    const sectionsData = [];
    let totalMembers = 0;
    let totalWaiting = 0;

    for (const sec of sections) {
      const sectionId = sec.section_id;
      const sectionType = sec.section_type;
      const sectionName = sec.section_name;

      // Skip non-youth sections for now
      if (!['earlyyears', 'beavers', 'cubs', 'scouts', 'explorers'].includes(sectionType)) continue;

      const currentTerm = sec.terms?.find(t => new Date(t.startdate) <= today && new Date(t.enddate) >= today);
      const termId = currentTerm ? currentTerm.term_id : sec.terms?.[sec.terms.length - 1]?.term_id;

      if (!termId) continue;

      const listUrl = `${OSM_API_BASE}/ext/members/contact/?action=getListOfMembers&sectionid=${sectionId}&termid=${termId}&section=${sectionType}&sort=lastname`;
      const listResponse = await axios.get(listUrl, {
        headers: { Authorization: `Bearer ${req.session.accessToken}` },
      });
      const members = listResponse.data.items || [];
      const memberCount = members.length;
      totalMembers += memberCount;

      // Waiting count – if this is the waiting section
      let waitingCount = 0;
      if (sectionType === 'waiting' || sectionName.toLowerCase().includes('waiting')) {
        waitingCount = memberCount;
        totalWaiting += waitingCount;
      }

      sectionsData.push({
        sectionName,
        members: memberCount,
        waiting: waitingCount,
        // Add more fields as we expand
      });
    }

    res.render('membership-dashboard', {
      sectionsData,
      totalMembers,
      totalWaiting,
      membersUpdated: new Date().toLocaleString('en-GB'),
      waitingUpdated: new Date().toLocaleString('en-GB')
    });
  } catch (error) {
    console.error('Membership dashboard error:', error.message);
    res.status(500).render('error', { message: 'Unable to load dashboard.' });
  }
});


// Reset auth
app.get('/reset', (req, res) => {
  req.session.destroy();
  res.redirect('/');
});

app.listen(port, () => {
  console.log(`Server running on port ${port}`);
});
