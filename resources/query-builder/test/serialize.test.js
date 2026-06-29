import { test } from 'node:test';
import assert from 'node:assert/strict';
import { serializeConditionNodes, buildQueryDefinition } from '../src/core/serialize.js';

// These mirror the ConditionNode shapes the PHP parity harness (QueryBuilderParityTest) validates.
// JS pins "UI → tree"; PHP pins "tree → SQL". Same contract, guarded on both sides.

test('a leaf condition serializes to the ConditionNode shape', () => {
  const out = serializeConditionNodes([
    { type: 'condition', column: 'status', operator: '=', value: 'active', boolean: 'and', valueType: 'hardcoded' },
  ]);
  assert.deepEqual(out, [
    { type: 'condition', column: 'status', operator: '=', value: 'active', boolean: 'and', valueType: 'hardcoded' },
  ]);
});

test('valueType falls back to hardcoded when missing', () => {
  const out = serializeConditionNodes([{ type: 'condition', column: 'x', operator: '=', value: '1', boolean: 'and' }]);
  assert.equal(out[0].valueType, 'hardcoded');
});

test('a reference condition keeps referenceColumn', () => {
  const out = serializeConditionNodes([
    { type: 'condition', column: 'updated_at', operator: '>', value: '', boolean: 'and', valueType: 'reference', referenceColumn: 'created_at' },
  ]);
  assert.equal(out[0].referenceColumn, 'created_at');
  assert.equal(out[0].valueType, 'reference');
});

test('incomplete condition rows (no column) are dropped', () => {
  const out = serializeConditionNodes([
    { type: 'condition', column: '', operator: '=', value: '', boolean: 'and' },
    { type: 'condition', column: 'name', operator: 'like', value: '%a%', boolean: 'and', valueType: 'hardcoded' },
  ]);
  assert.equal(out.length, 1);
  assert.equal(out[0].column, 'name');
});

test('a group serializes recursively with its boolean', () => {
  const out = serializeConditionNodes([
    { type: 'group', boolean: 'or', children: [
      { type: 'condition', column: 'a', operator: '=', value: '1', boolean: 'and', valueType: 'hardcoded' },
      { type: 'condition', column: 'b', operator: '=', value: '2', boolean: 'or', valueType: 'hardcoded' },
    ] },
  ]);
  assert.equal(out[0].type, 'group');
  assert.equal(out[0].boolean, 'or');
  assert.equal(out[0].children.length, 2);
});

test('a whereHas with a count constraint serializes the count keys', () => {
  const out = serializeConditionNodes([
    { type: 'whereHas', relationship: 'communicationLogs', sourceModel: 'App\\Models\\CommunicationLog', boolean: 'and', hasType: 'has', countOperator: '>=', countValue: 5, children: [] },
  ]);
  assert.deepEqual(out[0], {
    type: 'whereHas', relationship: 'communicationLogs', sourceModel: 'App\\Models\\CommunicationLog',
    boolean: 'and', hasType: 'has', children: [], countOperator: '>=', countValue: 5,
  });
});

test('a whereHas WITHOUT a count omits the count keys and recurses into children', () => {
  const out = serializeConditionNodes([
    { type: 'whereHas', relationship: 'communicationLogs', boolean: 'and', hasType: 'whereHas', children: [
      { type: 'condition', column: 'type', operator: '=', value: 1, boolean: 'and', valueType: 'hardcoded' },
    ] },
  ]);
  assert.equal('countOperator' in out[0], false);
  assert.equal(out[0].children.length, 1);
  assert.equal(out[0].children[0].column, 'type');
});

test('buildQueryDefinition assembles the full envelope', () => {
  const def = buildQueryDefinition({
    name: 'ActiveContacts', baseModel: 'App\\Models\\Contact', baseTable: 'contacts',
    joins: [], sorts: [{ column: 'created_at', direction: 'desc' }, { column: '', direction: 'asc' }],
    conditions: [{ type: 'condition', column: 'status', operator: '=', value: 'active', boolean: 'and', valueType: 'hardcoded' }],
    output: { scopeOnModel: true, apiEndpoint: false, inlineController: false },
  });
  assert.equal(def.name, 'ActiveContacts');
  assert.equal(def.baseModel, 'App\\Models\\Contact');
  assert.equal(def.conditions.length, 1);
  assert.deepEqual(def.sorts, [{ column: 'created_at', direction: 'desc' }], 'empty-column sorts are filtered out');
  assert.equal(def.output.scopeOnModel, true);
});
