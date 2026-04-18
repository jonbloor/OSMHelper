// src/app.js
'use strict';

const express = require('express');
const session = require('express-session');
const bodyParser = require('body-parser');
const path = require('path');
const helmet = require('helmet');
const cookieParser = require('cookie-parser');

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

  // Security middleware first (Helmet adds all the important headers)
  app.use(helmet({
    contentSecurityPolicy: false, // You can tighten this later if you add more scripts
  }));

  app.set('view engine', 'ejs');
  app.set('view cache', false);
  app.set('views', path.join(__dirname, '../views'));

  app.use(cookieParser());
  app.use(bodyParser.urlencoded({ extended: true }));
  app.use(express.static(path.join(__dirname, '../public')));

  // Secure session configuration
  app.use(session({
    secret: process.env.SESSION_SECRET,
    resave: false,
    saveUninitialized: false,
    cookie: {
      httpOnly: true,
      // secure: process.env.NODE_ENV === 'production', // auto true on HTTPS
      // sameSite: 'strict',
      maxAge: 24 * 60 * 60 * 1000 // 24 hours
    }
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

  // 404 & global error handler
  app.use((req, res) => res.status(404).render('error', { message: 'Page not found' }));
  app.use((err, req, res, next) => {
    console.error('Error:', err.stack);
    res.status(500).render('error', { message: 'Something went wrong. Please try again.' });
  });

  return app;
}

module.exports = { createApp };
