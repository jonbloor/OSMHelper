// src/app.js
'use strict';

const express = require('express');
const session = require('express-session');
const bodyParser = require('body-parser');
const path = require('path');

const { assertEnv } = require('./config/env');
const { requireAuth } = require('./middleware/requireAuth');

// Import all route handlers
const authRoutes = require('./routes/auth.routes');
const dashboardRoutes = require('./routes/dashboard.routes');
const bankTransfersRoutes = require('./routes/bankTransfers.routes');
const equipmentRoutes = require('./routes/equipment.routes');
const membershipDashboardRoutes = require('./routes/membershipDashboard.routes');
const membersRoutes = require('./routes/members.routes');
const waitingListRoutes = require('./routes/waitingList.routes');
const settingsRoutes = require('./routes/settings.routes');

function createApp() {
  assertEnv(); // Ensure required env vars are set

  const app = express();

  app.set('view engine', 'ejs');
  app.set('views', path.join(__dirname, '../views'));
  app.use(bodyParser.urlencoded({ extended: true }));
  app.use(express.static(path.join(__dirname, '../public')));

  app.use(session({
    secret: process.env.SESSION_SECRET,
    resave: false,
    saveUninitialized: true,
  }));

  // Mount routes
  app.use(authRoutes);
  app.use(dashboardRoutes);
  app.use(bankTransfersRoutes);
  app.use(equipmentRoutes);
  app.use(membershipDashboardRoutes);
  app.use(membersRoutes);
  app.use(waitingListRoutes);
  app.use(settingsRoutes);

  // Global error handler
  app.use((err, req, res, next) => {
    console.error('Error:', err.stack);
    res.status(500).render('error', { message: 'Something went wrong. Please try again.' });
  });

  return app;
}

module.exports = { createApp };
