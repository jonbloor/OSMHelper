const OSM_API_BASE = 'https://www.onlinescoutmanager.co.uk';

const SECTION_TYPE_ORDER = [
  'Squirrels',
  'Beavers',
  'Cubs',
  'Scouts',
  'Explorers',
  'Adults / Leaders',
];

const FRIENDLY_SECTION_TYPES = {
  earlyyears: 'Squirrels',
  beavers: 'Beavers',
  cubs: 'Cubs',
  scouts: 'Scouts',
  explorers: 'Explorers',
  adults: 'Adults / Leaders',
  waiting: 'Waiting List',
  unknown: 'Other',
};

const DEFAULT_CAPACITIES = {
  earlyyears: 18,
  beavers: 24,
  cubs: 30,
  scouts: 36,
};

const DEFAULT_CUTOFFS = {
  squirrels: 4.0,
  beavers: 5.75,
  cubs: 7.5,
  scouts: 10.0,
  explorers: 13.5,
};

module.exports = {
  OSM_API_BASE,
  SECTION_TYPE_ORDER,
  FRIENDLY_SECTION_TYPES,
  DEFAULT_CAPACITIES,
  DEFAULT_CUTOFFS,
};
