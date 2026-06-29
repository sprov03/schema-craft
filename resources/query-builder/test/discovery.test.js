import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fuzzyMatch, searchFields } from '../src/core/fuzzy.js';
import { flattenGraph, levelAt } from '../src/core/graph.js';
import { QueryTree } from '../src/core/tree.js';

// A small schema graph: Contact → deals → products, with a cycle (Deal → contact) to prove cycle-skip.
const GRAPH = {
  base: 'Contact',
  models: {
    Contact: { columns: [{ name: 'first_name', type: 'string' }, { name: 'status', type: 'enum', options: ['a'] }], relationships: [{ name: 'deals', target: 'Deal' }] },
    Deal: { columns: [{ name: 'total', type: 'number' }], relationships: [{ name: 'products', target: 'Product' }, { name: 'contact', target: 'Contact' }] },
    Product: { columns: [{ name: 'name', type: 'string' }, { name: 'sku', type: 'string' }], relationships: [] },
  },
};

// ── fuzzy ──
test('fuzzyMatch scores subsequence hits, rewards word starts, rejects non-subsequences', () => {
  assert.ok(fuzzyMatch('name', 'products → name'));
  assert.equal(fuzzyMatch('zzz', 'products → name'), null);
  const wordStart = fuzzyMatch('p', 'products → name').score;
  const mid = fuzzyMatch('r', 'products → name').score; // 'r' is mid-word
  assert.ok(wordStart > mid, 'word-start match scores higher');
});

test('searchFields ranks matches and returns all on empty query', () => {
  const fields = flattenGraph(GRAPH, 3);
  const hits = searchFields('name', fields);
  assert.ok(hits[0].label.includes('name'), 'best hit is a name field');
  assert.equal(searchFields('', fields).length, fields.length || fields.length);
});

// ── graph ──
test('flattenGraph reaches columns several hops out, skipping cycles', () => {
  const fields = flattenGraph(GRAPH, 3);
  const labels = fields.map((f) => f.label);
  assert.ok(labels.includes('first_name'), 'base column');
  assert.ok(labels.includes('deals → total'), '1 hop');
  assert.ok(labels.includes('deals → products → name'), '2 hops');
  // The Deal → contact cycle must NOT produce deals → contact → first_name.
  assert.ok(!labels.some((l) => l.includes('deals → contact')), 'cycle skipped');
});

test('flattenGraph respects maxDepth', () => {
  const shallow = flattenGraph(GRAPH, 1).map((f) => f.label);
  assert.ok(shallow.includes('deals → total'));
  assert.ok(!shallow.some((l) => l.includes('products')), 'depth 1 stops before products');
});

test('levelAt returns the columns + relationships at a path (for the navigator)', () => {
  const base = levelAt(GRAPH, []);
  assert.deepEqual(base.relationships.map((r) => r.name), ['deals']);
  const atDeals = levelAt(GRAPH, ['deals']);
  assert.deepEqual(atDeals.columns.map((c) => c.name), ['total']);
  assert.deepEqual(atDeals.relationships.map((r) => r.name), ['products', 'contact']);
});

// ── auto-path build ──
test('addFieldPath builds the nested whereHas chain to a deep field', () => {
  const t = new QueryTree();
  const field = { relationshipPath: ['deals', 'products'], column: 'name', type: 'string' };
  t.addFieldPath('', field, { operator: '=', value: 'Widget' });

  const outer = t.conditions[0];
  assert.equal(outer.type, 'whereHas');
  assert.equal(outer.relationship, 'deals');
  const inner = outer.children[0];
  assert.equal(inner.type, 'whereHas');
  assert.equal(inner.relationship, 'products');
  assert.equal(inner.children[0].column, 'name');
  assert.equal(inner.children[0].value, 'Widget');
});

test('addWhereHasPath builds a whereHas (nested for a deep path), innermost carries hasType', () => {
  const t = new QueryTree();
  t.addWhereHasPath('', ['communicationLogs'], 'doesntHave');
  assert.equal(t.conditions[0].type, 'whereHas');
  assert.equal(t.conditions[0].relationship, 'communicationLogs');
  assert.equal(t.conditions[0].hasType, 'doesntHave');

  const t2 = new QueryTree();
  t2.addWhereHasPath('', ['deals', 'products'], 'doesntHave');
  assert.equal(t2.conditions[0].relationship, 'deals');
  assert.equal(t2.conditions[0].hasType, 'whereHas', 'outer hop is a plain wrapper');
  assert.equal(t2.conditions[0].children[0].relationship, 'products');
  assert.equal(t2.conditions[0].children[0].hasType, 'doesntHave', 'innermost (endpoint) carries hasType');
});

test('addFieldPath with no relationship path adds a plain base condition', () => {
  const t = new QueryTree();
  t.addFieldPath('', { relationshipPath: [], column: 'first_name', type: 'string' }, { value: 'Bob' });
  assert.equal(t.conditions[0].type, 'condition');
  assert.equal(t.conditions[0].column, 'first_name');
});
