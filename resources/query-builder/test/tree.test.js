import { test } from 'node:test';
import assert from 'node:assert/strict';
import { QueryTree } from '../src/core/tree.js';

test('addCondition appends a condition node', () => {
  const t = new QueryTree();
  t.addCondition();
  assert.equal(t.conditions.length, 1);
  assert.equal(t.conditions[0].type, 'condition');
});

test('addGroup appends a Match ANY group: children or-joined, group itself and-joined at top level', () => {
  const t = new QueryTree();
  t.addGroup();
  assert.equal(t.conditions[0].type, 'group');
  assert.equal(t.conditions[0].boolean, 'and', 'the group joins top-level siblings via AND');
  assert.equal(t.groupConnector('0'), 'or', 'its children are Match ANY (or)');
  assert.equal(t.conditions[0].children.length, 1);
});

test('addConditionFromColumn picks a smart operator by column type', () => {
  const t = new QueryTree();
  t.addConditionFromColumn('first_name', 'string');    // -> like
  t.addConditionFromColumn('created_at', 'timestamp'); // -> between
  t.addConditionFromColumn('is_active', 'boolean');    // -> =
  assert.equal(t.conditions[0].operator, 'like');
  assert.equal(t.conditions[1].operator, 'between');
  assert.equal(t.conditions[2].operator, '=');
});

test('getNodeByPath walks the tree by dash-delimited path', () => {
  const t = new QueryTree([
    { type: 'group', boolean: 'and', children: [
      { type: 'condition', column: 'a', operator: '=', value: '1', boolean: 'and' },
      { type: 'group', boolean: 'or', children: [{ type: 'condition', column: 'b', operator: '=', value: '2', boolean: 'and' }] },
    ] },
  ]);
  assert.equal(t.getNodeByPath('0-1-0').column, 'b');
});

test('addChildGroup nests into a group AND a whereHas (full nesting)', () => {
  const t = new QueryTree([
    { type: 'group', boolean: 'and', children: [] },
    { type: 'whereHas', relationship: 'r', children: [] },
  ]);
  t.addChildGroup('0');
  t.addChildGroup('1'); // whereHas can host a group too — parity-tested on the PHP side
  assert.equal(t.conditions[0].children[0].type, 'group');
  assert.equal(t.conditions[1].children[0].type, 'group');
});

test('relationshipContext resolves the whereHas chain scoping a path (groups are transparent)', () => {
  const t = new QueryTree([
    { type: 'whereHas', relationship: 'deals', hasType: 'whereHas', boolean: 'and', children: [
      { type: 'group', boolean: 'or', children: [
        { type: 'condition', column: 'x', operator: '=', value: '1', boolean: 'or' },
      ] },
    ] },
  ]);
  assert.deepEqual(t.relationshipContext(''), [], 'top level → base model');
  assert.deepEqual(t.relationshipContext('0'), ['deals'], 'the whereHas as a parent path includes its relationship');
  assert.deepEqual(t.relationshipContext('0-0-0'), ['deals'], 'a condition inside the group still scoped to deals');
});

test('addChildCondition adds into both a group and a whereHas', () => {
  const t = new QueryTree([
    { type: 'group', boolean: 'and', children: [] },
    { type: 'whereHas', relationship: 'r', children: [] },
  ]);
  t.addChildCondition('0');
  t.addChildCondition('1');
  assert.equal(t.conditions[0].children.length, 1);
  assert.equal(t.conditions[1].children.length, 1);
});

// ── Unambiguous-boolean constraint (Match ALL / Match ANY, top level AND-only) ──

test('top-level conditions are always AND', () => {
  const t = new QueryTree();
  t.addCondition();
  t.addCondition();
  t.addGroup();
  assert.deepEqual(t.conditions.map((n) => n.boolean), ['and', 'and', 'and']);
});

test('a new group is Match ANY (its children share the or connector)', () => {
  const t = new QueryTree();
  t.addGroup();
  t.addChildCondition('0');
  const group = t.conditions[0];
  assert.deepEqual(group.children.map((c) => c.boolean), ['or', 'or'], 'all children homogeneous = or');
});

test('setGroupConnector flips a group between Match ALL and Match ANY', () => {
  const t = new QueryTree();
  t.addGroup();
  t.addChildCondition('0');
  t.setGroupConnector('0', 'and');
  assert.deepEqual(t.conditions[0].children.map((c) => c.boolean), ['and', 'and']);
  assert.equal(t.groupConnector('0'), 'and');
  t.setGroupConnector('0', 'or');
  assert.equal(t.groupConnector('0'), 'or');
});

test('cycleGroupMode cycles ANY → NONE → ALL → ANY (negate flag follows)', () => {
  const t = new QueryTree();
  t.addGroup(); // seeded OR → ANY
  assert.equal(t.groupMode('0'), 'any');

  t.cycleGroupMode('0'); // ANY → NONE
  assert.equal(t.groupMode('0'), 'none');
  assert.equal(t.conditions[0].negate, true, 'NONE sets the negate flag');

  t.cycleGroupMode('0'); // NONE → ALL
  assert.equal(t.groupMode('0'), 'all');
  assert.equal(t.conditions[0].negate, false);

  t.cycleGroupMode('0'); // ALL → ANY
  assert.equal(t.groupMode('0'), 'any');
});

test('a child added to a Match ANY group inherits or; nested groups keep their own connector', () => {
  const t = new QueryTree([
    { type: 'group', boolean: 'or', children: [
      { type: 'condition', column: 'a', operator: '=', value: '1', boolean: 'or' },
      { type: 'group', boolean: 'and', children: [
        { type: 'condition', column: 'b', operator: '=', value: '2', boolean: 'and' },
      ] },
    ] },
  ]);
  // outer group is ANY (or); its nested group is ALL (and) — independent.
  assert.equal(t.groupConnector('0'), 'or');
  assert.equal(t.groupConnector('0-1'), 'and');

  t.addChildCondition('0');
  const outer = t.conditions[0];
  assert.equal(outer.children[outer.children.length - 1].boolean, 'or', 'new child took the ANY connector');
  assert.equal(t.groupConnector('0-1'), 'and', 'nested ALL group untouched');
});

test('normalize forces homogeneity — a mixed level cannot survive', () => {
  // Construct an illegal mixed-boolean group; the constructor normalizes it.
  const t = new QueryTree([
    { type: 'group', boolean: 'or', children: [
      { type: 'condition', column: 'a', operator: '=', value: '1', boolean: 'or' },
      { type: 'condition', column: 'b', operator: '=', value: '2', boolean: 'and' }, // mixed!
    ] },
  ]);
  const booleans = t.conditions[0].children.map((c) => c.boolean);
  assert.equal(new Set(booleans).size, 1, 'children forced to one connector');
});

test('removeNodeByPath removes a nested node', () => {
  const t = new QueryTree([
    { type: 'group', boolean: 'and', children: [
      { type: 'condition', column: 'a', operator: '=', value: '1', boolean: 'and' },
      { type: 'condition', column: 'b', operator: '=', value: '2', boolean: 'and' },
    ] },
  ]);
  t.removeNodeByPath('0-0');
  assert.equal(t.conditions[0].children.length, 1);
  assert.equal(t.conditions[0].children[0].column, 'b');
});
