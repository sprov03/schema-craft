import { test } from 'node:test';
import assert from 'node:assert/strict';
import { unflattenConditions } from '../src/core/legacy.js';

test('flat conditions with no groups become a flat tree', () => {
  const out = unflattenConditions([{ column: 'a', operator: '=', value: '1' }], [], []);
  assert.equal(out.length, 1);
  assert.equal(out[0].type, 'condition');
  assert.equal(out[0].column, 'a');
});

test('grouped conditions nest under their group', () => {
  const out = unflattenConditions(
    [{ column: 'a', operator: '=', value: '1', groupId: 'g1' }],
    [{ id: 'g1', boolean: 'or' }],
    [],
  );
  assert.equal(out[0].type, 'group');
  assert.equal(out[0].boolean, 'or');
  assert.equal(out[0].children[0].column, 'a');
});

test('child groups nest into their parent group', () => {
  const out = unflattenConditions(
    [{ column: 'a', operator: '=', value: '1', groupId: 'child' }],
    [{ id: 'parent', boolean: 'and' }, { id: 'child', boolean: 'or', parentGroupId: 'parent' }],
    [],
  );
  assert.equal(out.length, 1, 'only the parent is top-level');
  assert.equal(out[0].children[0].type, 'group', 'child group nested under parent');
});

test('whereHas entries are reconstructed with their children', () => {
  const out = unflattenConditions([], [], [
    { relationship: 'logs', hasType: 'whereHas', conditions: [{ column: 'type', operator: '=', value: '1' }] },
  ]);
  assert.equal(out[0].type, 'whereHas');
  assert.equal(out[0].relationship, 'logs');
  assert.equal(out[0].children[0].column, 'type');
});

test('legacy parameter flag maps to valueType dynamic', () => {
  const out = unflattenConditions([{ column: 'a', operator: '=', value: '', parameter: true }], [], []);
  assert.equal(out[0].valueType, 'dynamic');
});
