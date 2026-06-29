import { test } from 'node:test';
import assert from 'node:assert/strict';
import { shortestPaths, searchModelPaths, modelAtPath } from '../src/core/graph.js';

// Lightweight adjacency: model → [{ name, target, kind, many }]. Contact → deals(»many) → lead.
const ADJ = {
  base: 'Contact',
  models: {
    Contact: [
      { name: 'deals', target: 'Deal', kind: 'hasMany', many: true },
      { name: 'account', target: 'Account', kind: 'belongsTo', many: false },
    ],
    Deal: [{ name: 'lead', target: 'Lead', kind: 'belongsTo', many: false }],
    Account: [],
    Lead: [],
  },
};

test('shortestPaths returns the fewest-hop chain to each model (as edges)', () => {
  const p = shortestPaths(ADJ, 'Contact');
  assert.deepEqual(p.get('Lead').map((e) => e.name), ['deals', 'lead']);
  assert.deepEqual(p.get('Account').map((e) => e.name), ['account']);
  assert.equal(p.get('Lead')[0].many, true, 'edges carry cardinality');
});

test('searchModelPaths fuzzy-matches a model name and returns its chain', () => {
  const r = searchModelPaths(ADJ, 'Contact', 'lead');
  assert.equal(r[0].name, 'Lead');
  assert.deepEqual(r[0].path, ['deals', 'lead'], 'names for building the whereHas chain');
});

test('searchModelPaths resolves from a NESTED context model (Deal), not just the base', () => {
  const r = searchModelPaths(ADJ, 'Deal', 'lead');
  assert.deepEqual(r[0].path, ['lead'], 'from Deal, Lead is one hop');
});

test('an unreachable model is absent → "no connection from here"', () => {
  const r = searchModelPaths(ADJ, 'Account', 'lead'); // Account has no outgoing edges
  assert.equal(r.length, 0);
});

test('modelAtPath follows relationship names to the context model', () => {
  assert.equal(modelAtPath(ADJ, []), 'Contact');
  assert.equal(modelAtPath(ADJ, ['deals']), 'Deal');
  assert.equal(modelAtPath(ADJ, ['deals', 'lead']), 'Lead');
});
