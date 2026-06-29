import { test } from 'node:test';
import assert from 'node:assert/strict';
import { columnsFor, relationshipsFor, typeOf, optionsFor } from '../src/core/metadata.js';

const META = {
  columns: [
    { name: 'first_name', type: 'string' },
    { name: 'callrail_number_verification_status', type: 'enum', options: ['Verified', 'Unknown', 'Unverified'] },
    { name: 'created_at', type: 'date' },
  ],
  relationships: [
    { name: 'communicationLogs', columns: [{ name: 'type', type: 'enum', options: ['Call', 'Email'] }, { name: 'duration_in_seconds', type: 'number' }] },
  ],
};

test('columnsFor returns base columns, or a relationship\'s columns', () => {
  assert.equal(columnsFor(META).length, 3);
  assert.deepEqual(columnsFor(META, 'communicationLogs').map((c) => c.name), ['type', 'duration_in_seconds']);
  assert.deepEqual(columnsFor(META, 'missing'), []);
});

test('relationshipsFor lists the relationships', () => {
  assert.deepEqual(relationshipsFor(META).map((r) => r.name), ['communicationLogs']);
});

test('typeOf resolves a column type, defaulting to string', () => {
  assert.equal(typeOf(META, 'created_at'), 'date');
  assert.equal(typeOf(META, 'duration_in_seconds', 'communicationLogs'), 'number');
  assert.equal(typeOf(META, 'unknown_column'), 'string', 'sparse metadata still works');
});

test('optionsFor returns enum options, or null for free-form columns', () => {
  assert.deepEqual(optionsFor(META, 'callrail_number_verification_status'), ['Verified', 'Unknown', 'Unverified']);
  assert.deepEqual(optionsFor(META, 'type', 'communicationLogs'), ['Call', 'Email']);
  assert.equal(optionsFor(META, 'first_name'), null);
});

test('accessors tolerate missing metadata', () => {
  assert.deepEqual(columnsFor(null), []);
  assert.deepEqual(relationshipsFor(undefined), []);
  assert.equal(typeOf(null, 'x'), 'string');
});
