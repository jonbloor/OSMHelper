const express = require('express');

const router = express.Router();

// Placeholder routes to prevent MODULE_NOT_FOUND crash
router.get('/members', (req, res) => {
  res.render('members', { members: [], message: 'Members loading...' });
});

router.get('/waiting-list', (req, res) => {
  res.render('waiting-list', {
    applicants: [],
    fetchedAt: new Date().toLocaleString('en-GB'),
    message: 'Waiting list loading...'
  });
});

module.exports = router;
