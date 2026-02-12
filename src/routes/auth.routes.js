// src/routes/auth.routes.js
const express = require('express');
const { asyncHandler } = require('../utils/asyncHandler');
const { createOAuthClient } = require('../services/oauthClient');
const osmApi = require('../services/osmApi');

const router = express.Router();

const client = createOAuthClient();
const REDIRECT_URI = process.env.REDIRECT_URI;

router.get('/auth', (req, res) => {
  const uri = client.authorizeURL({
    redirect_uri: REDIRECT_URI,
    scope: 'section:member:read section:quartermaster:write section:finance:read',
    access_type: 'offline',
  });
  res.redirect(uri);
});

router.get('/callback', asyncHandler(async (req, res) => {
  const { code } = req.query;
  if (!code) return res.status(400).send('Missing code parameter.');

  try {
    const result = await client.getToken({ code, redirect_uri: REDIRECT_URI });
    req.session.accessToken = result.token.access_token;

    // Fetch user details from /oauth/resource and store in session
    const resourceResponse = await osmApi.get(req.session.accessToken, '/oauth/resource', { session: req.session });
    console.log('Full resourceResponse.data:', JSON.stringify(resourceResponse.data, null, 2)); // Added log
    const data = resourceResponse.data?.data || {};
    req.session.email = data.email || 'Unknown Email';
    req.session.groupName = data.sections?.[0]?.group_name || 'OSM Helper';
    req.session.fullName = data.full_name || 'Unknown User';

    return res.redirect('/dashboard');
  } catch (error) {
    console.error('OAuth callback failed:', error.response?.status, error.response?.data || error.message);
    return res.status(500).send(`Authentication failed: ${error.response?.data?.error_description || error.message}`);
  }
}));

module.exports = router;
