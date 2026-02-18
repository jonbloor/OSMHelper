'use strict';
const axios = require('axios');
const { OSM_API_BASE } = require('../config/constants');
const { limit } = require('../utils/concurrency');
const { TTLCache } = require('../utils/cache');
const DEFAULT_TIMEOUT_MS = 20_000;
const apiCache = new TTLCache({ defaultTtlMs: 30_000, maxItems: 2_500 });
const rateStateByToken = new Map();
function tokenKey(accessToken) {
  return String(accessToken || '');
}
function updateRateState(accessToken, headers = {}) {
  const key = tokenKey(accessToken);
  if (!key) return;
  const limit = headers['x-ratelimit-limit'];
  const remaining = headers['x-ratelimit-remaining'];
  const reset = headers['x-ratelimit-reset'];
  const parsed = {
    limit: limit != null ? Number(limit) : null,
    remaining: remaining != null ? Number(remaining) : null,
    resetInSec: reset != null ? Number(reset) : null,
    lastUpdatedMs: Date.now(),
  };
  if (parsed.limit !== null || parsed.remaining !== null || parsed.resetInSec !== null) {
    rateStateByToken.set(key, parsed);
  }
}
function getRateState(accessToken) {
  const key = tokenKey(accessToken);
  if (!key) return null;
  return rateStateByToken.get(key) || null;
}
function secondsUntilReset(accessToken) {
  const st = getRateState(accessToken);
  if (!st || st.resetInSec == null) return null;
  const elapsedMs = Date.now() - st.lastUpdatedMs;
  const elapsedSec = Math.floor(elapsedMs / 1000);
  return Math.max(0, st.resetInSec - elapsedSec);
}
async function request(accessToken, method, path, { params, data } = {}) {
  if (!accessToken) throw new Error('No access token');
  const url = `${OSM_API_BASE}${path.startsWith('/') ? '' : '/'}${path}`;
  const config = {
    method,
    url,
    params,
    data,
    headers: {
      Authorization: `Bearer ${accessToken}`,
      'Content-Type': 'application/json',
    },
    timeout: DEFAULT_TIMEOUT_MS,
  };
  try {
    const response = await axios(config);
    updateRateState(accessToken, response.headers);
    return response;
  } catch (error) {
    if (error.response) {
      updateRateState(accessToken, error.response.headers);
      if (error.response.status === 429) {
        throw new Error(`Rate limited. Try again in ${secondsUntilReset(accessToken)} seconds.`);
      }
    }
    throw error;
  }
}
function makeCacheKey({ userKey, method, path, params }) {
  const p = params ? Object.keys(params).sort().map(k => `${k}=${params[k]}`).join('&') : '';
  return `${userKey}::${method.toUpperCase()}::${path}::${p}`;
}
function getUserKeyFromSession(session) {
  return String(session.osmUserId || session.userId || session.id || session.sessionId || 'user');
}
async function get(accessToken, path, { params, ttlMs, session } = {}) {
  const userKey = getUserKeyFromSession(session);
  const key = makeCacheKey({ userKey, method: 'GET', path, params });
  return apiCache.wrap(key, ttlMs ?? 30_000, async () => {
    return request(accessToken, 'GET', path, { params });
  });
}
async function post(accessToken, path, { params, data } = {}) {
  return request(accessToken, 'POST', path, { params, data });
}
async function put(accessToken, path, { params, data } = {}) {
  return request(accessToken, 'PUT', path, { params, data });
}
async function del(accessToken, path, { params } = {}) {
  return request(accessToken, 'DELETE', path, { params });
}
function getRateLimitSnapshot(accessToken) {
  const st = getRateState(accessToken);
  if (!st) return null;
  const until = secondsUntilReset(accessToken);
  return {
    limit: st.limit,
    remaining: st.remaining,
    resetInSec: st.resetInSec,
    secondsUntilReset: typeof until === 'number' ? until : null,
  };
}
function authHeader(accessToken) {
  return { Authorization: `Bearer ${accessToken}` };
}
async function getDynamicSections(accessToken, session = null) {
  const response = await get(accessToken, '/oauth/resource', {
    session,
    ttlMs: 5 * 60 * 1000,
  });
  const data = response.data; // Assuming the data is in response.data
console.log('Raw /oauth/resource full structure:', JSON.stringify(data, null, 2));
console.log('Raw keys at top level:', Object.keys(data || {}));
if (data?.data) console.log('Keys inside data.data:', Object.keys(data.data || {}));
  let rawSections = data?.sections || data?.data?.sections || data?.roles || data?.data?.roles || [];
  if (!Array.isArray(rawSections)) rawSections = [];
  // Compute current_term_id if not present
  const now = new Date();
  const sections = rawSections.map(sec => {
    let current_term_id;
    if (sec.terms && Array.isArray(sec.terms)) {
      const currentTerm = sec.terms.find(t => {
        const start = new Date(t.startdate);
        const end = new Date(t.enddate);
        return start <= now && end >= now;
      });
      current_term_id = currentTerm ? currentTerm.term_id : (sec.terms[sec.terms.length - 1]?.term_id || -1);
    } else {
      current_term_id = sec.current_term_id || -1;
    }
    return {
      ...sec,
      current_term_id,
    };
  });
  console.log('Parsed sections:', sections); // Debug log
  return sections;
}
async function getGroupName(accessToken, sectionId, session = null) {
  const params = {
    action: 'getData',
    section_id: sectionId,
    associated_id: session?.osmUserId || '1', // Use osmUserId if available; fallback to '1' if testing (adjust if needed)
    associated_type: 'member',
    context: 'members'
  };
  console.log('Fetching group name with params:', params); // Debug log
  try {
    const response = await get(accessToken, '/ext/customdata/', { params, session });
    console.log('Group name response:', response.data); // Debug log for structure
    return response.data.meta?.group_name || 'OSM Helper';
  } catch (error) {
    console.error('Group name fetch error:', error.message);
    return 'OSM Helper';
  }
}

module.exports = {
  get,
  post,
  put,
  del,
  request,
  getRateLimitSnapshot,
  authHeader,
  getDynamicSections,
  getGroupName, // Don't forget to export it here!
  _cache: apiCache,
};
