const { DEFAULT_CAPACITIES } = require('../config/constants');

// In-memory store: resets on restart.
// Kept as a module so you can later swap it for Redis/SQLite without touching routes.
const sectionCapacities = {};

function getCapacity(sectionId, sectionType) {
  return sectionCapacities[sectionId] ?? DEFAULT_CAPACITIES[sectionType] ?? 'Not set';
}

function setCapacity(sectionId, capacity) {
  sectionCapacities[sectionId] = capacity;
}

module.exports = { getCapacity, setCapacity };
