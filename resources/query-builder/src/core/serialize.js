// Serialization — PURE, no DOM. Ported VERBATIM from serializeConditionNodes / buildQueryDefinition
// in visualizer.html. This is THE contract: the output must be byte-identical to what PHP's
// SchemaCraft\QueryBuilder\ConditionNode::toArray() / QueryDefinition::fromArray() consume — which
// the PHP parity harness (QueryBuilderParityTest) validates produces correct, consistent SQL. The JS
// tests here pin the front-half ("UI → tree"); the PHP tests pin the back-half ("tree → SQL").

export function serializeConditionNodes(nodes) {
  return (nodes || [])
    .filter((node) => {
      // Drop incomplete condition rows (no column) — they're in-progress UI state, not real filters.
      if (node.type === 'condition' && !node.column) return false;
      return true;
    })
    .map((node) => {
      if (node.type === 'condition') {
        const obj = {
          type: 'condition',
          column: node.column,
          operator: node.operator,
          value: node.value,
          boolean: node.boolean,
          valueType: node.valueType || 'hardcoded',
        };
        if (node.referenceColumn) obj.referenceColumn = node.referenceColumn;
        if (node.referenceRelationship) obj.referenceRelationship = node.referenceRelationship;
        if (node.aggregateFunction) obj.aggregateFunction = node.aggregateFunction;
        if (node.referenceComputedValue) obj.referenceComputedValue = node.referenceComputedValue;
        return obj;
      }
      if (node.type === 'predicateRef') {
        return { type: 'predicateRef', ref: node.ref, boolean: node.boolean || 'and' };
      }
      if (node.type === 'group') {
        const group = {
          type: 'group',
          boolean: node.boolean || 'and',
          children: serializeConditionNodes(node.children || []),
        };
        if (node.negate) group.negate = true; // Match NONE of
        return group;
      }
      if (node.type === 'whereHas') {
        const wh = {
          type: 'whereHas',
          relationship: node.relationship,
          sourceModel: node.sourceModel || '',
          boolean: node.boolean || 'and',
          hasType: node.hasType || 'has',
          children: serializeConditionNodes(node.children || []),
        };
        if (node.countOperator) {
          wh.countOperator = node.countOperator;
          wh.countValue = node.countValue;
        }
        return wh;
      }
      return node;
    });
}

// The full QueryDefinition envelope (conditions tree + joins + sorts + base + output).
export function buildQueryDefinition(state) {
  const def = {
    name: state.name,
    baseModel: state.baseModel,
    baseSchema: state.baseSchema || null,
    baseTable: state.baseTable,
    joins: (state.joins || []).map((j) => ({
      type: j.type,
      table: j.table,
      model: j.model,
      localColumn: j.localColumn,
      foreignColumn: j.foreignColumn,
      alias: j.alias,
      relationshipName: j.relationshipName,
    })),
    conditions: serializeConditionNodes(state.conditions),
    sorts: (state.sorts || []).filter((s) => s.column).map((s) => ({ column: s.column, direction: s.direction })),
    output: {
      scopeOnModel: state.output?.scopeOnModel,
      apiEndpoint: state.output?.apiEndpoint,
      inlineController: state.output?.inlineController,
    },
  };
  if (state.selectedApi) def.api = state.selectedApi;
  return def;
}
