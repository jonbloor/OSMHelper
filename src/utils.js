
const axios = require('axios');
const { OSM_API_BASE } = require('./config');

async function getRateLimitInfo(accessToken) {
  try {
    const res = await axios.get(`${OSM_API_BASE}/oauth/resource`, {
      headers: { Authorization: `Bearer ${accessToken}` }
    });
    const h = res.headers;
    return {
      limit: h['x-ratelimit-limit'] || 'Unknown',
      remaining: h['x-ratelimit-remaining'] || 'Unknown',
      reset: h['x-ratelimit-reset']
        ? new Date(h['x-ratelimit-reset'] * 1000).toLocaleString('en-GB')
        : 'Unknown'
    };
  } catch (err) {
    console.warn('Rate limit fetch failed:', err.message);
    return { limit: 'Error', remaining: 'Error', reset: 'Error' };
  }
}

async function getDynamicSections(accessToken) {
  try {
    const res = await axios.get(`${OSM_API_BASE}/oauth/resource`, {
      headers: { Authorization: `Bearer ${accessToken}` }
    });
    return res.data.data.sections || [];
  } catch (err) {
    console.error('Sections fetch error:', err.message);
    return [];
  }
}

module.exports = { getRateLimitInfo, getDynamicSections };
