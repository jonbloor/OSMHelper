# OSM Helper

Tools and dashboards to extend and simplify workflows with Online Scout Manager data.

## Features

- Membership dashboard
- Bank transfer listing
- API integration
- Per-user OAuth connection
- Minimal stored data for privacy

## Stack

- Node.js
- Express
- EJS
- CloudPanel VPS (You may find other platforms work ok)
- PM2 / CloudPanel Node manager

## Setup

```bash
npm install
cp .env.example .env
npm start
```

## Currently it
- fetches members from all sections you have access to
- fetches anyone in a waiting list you have access to
- Lists equipment from any section you have access to
- Lists bank transfers for any sections with finance you have access to.

## Worth noting
- Rate limits are highlighted/observed.  Data is cached in your browser for an hour.
- You can define the age cut off for section membership
- You can define custom capacities for sections and whether to include them in the group dashboard

## Future plans
- List of members that are duplicated
- Add/Edit Equipment
- Waiting list scoring (currently simply caclulated on hard coded rules)
- Future section capacity
- Personal Challenge Badge Printout
- Section Overview (possibly - this was in the osmtools.oldwokingscouts.uk that disappeared :( )
- Calculation and update of badges for top awards (like the old OSM Extender - https://github.com/osm-extender/osm-extender)
- your suggestions.
