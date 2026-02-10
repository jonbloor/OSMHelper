const OSM_API_BASE = 'https://www.onlinescoutmanager.co.uk';

const FRIENDLY_SECTION_TYPES = {
  'earlyyears': 'Squirrels',
  'beavers':    'Beavers',
  'cubs':       'Cubs',
  'scouts':     'Scouts',
  'explorers':  'Explorers',
  'adults':     'Adults/Leaders',
  'waiting':    'Waiting List',
  'unknown':    'Other'
};

const DEFAULT_CAPACITIES = {
  'earlyyears': 18,
  'beavers':    24,
  'cubs':       30,
  'scouts':     36
};

module.exports = {
  OSM_API_BASE,
  FRIENDLY_SECTION_TYPES,
  DEFAULT_CAPACITIES
};
