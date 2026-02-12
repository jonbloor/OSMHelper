const express = require('express');
const { asyncHandler } = require('../utils/asyncHandler');
const { createOAuthClient } = require('../services/oauthClient');

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
    return res.redirect('/dashboard');
  } catch (error) {
    console.error('OAuth callback failed:', error.response?.status, error.response?.data || error.message);
    return res.status(500).send(`Authentication failed: ${error.response?.data?.error_description || error.message}`);
  }
}));

module.exports = router;
