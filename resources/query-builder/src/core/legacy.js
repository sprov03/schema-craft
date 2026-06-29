// Legacy loader — PURE, no DOM. Ported from unflattenConditions in visualizer.html. Reconstructs a
// modern condition TREE from the old flat format (separate conditions[] + conditionGroups[] +
// whereHas[]). New saves are always tree-native; this only runs when loading a pre-tree definition.

export function unflattenConditions(conditions, conditionGroups, whereHasArr) {
  let result = [];

  const toCondition = (c) => {
    const node = {
      type: 'condition',
      column: c.column,
      operator: c.operator || '=',
      value: c.value || '',
      boolean: c.boolean || 'and',
      valueType: c.valueType || (c.parameter ? 'dynamic' : 'hardcoded'),
    };
    if (c.referenceColumn) node.referenceColumn = c.referenceColumn;
    return node;
  };

  if (!conditionGroups || conditionGroups.length === 0) {
    result = (conditions || []).map(toCondition);
  } else {
    const groupMap = {};
    conditionGroups.forEach((g) => {
      groupMap[g.id] = { type: 'group', boolean: g.boolean || 'and', parentGroupId: g.parentGroupId || null, children: [] };
    });

    // Assign conditions to their groups.
    (conditions || []).forEach((c) => {
      if (c.groupId && groupMap[c.groupId]) {
        groupMap[c.groupId].children.push(toCondition(c));
      }
    });

    // Nest child groups into parents.
    conditionGroups.forEach((g) => {
      if (g.parentGroupId && groupMap[g.parentGroupId]) {
        groupMap[g.parentGroupId].children.push(groupMap[g.id]);
      }
    });

    // Top-level: ungrouped conditions, then top-level groups.
    (conditions || []).forEach((c) => {
      if (!c.groupId) result.push(toCondition(c));
    });
    conditionGroups.forEach((g) => {
      if (!g.parentGroupId) result.push(groupMap[g.id]);
    });
  }

  // Reconstruct whereHas nodes.
  (whereHasArr || []).forEach((wh) => {
    result.push({
      type: 'whereHas',
      relationship: wh.relationship,
      sourceModel: wh.sourceModel || '',
      sourceSchema: wh.sourceSchema || null,
      relatedSchema: wh.relatedSchema || null,
      boolean: wh.boolean || 'and',
      hasType: wh.hasType || 'has',
      countOperator: wh.countOperator || null,
      countValue: wh.countValue || null,
      children: (wh.conditions || []).map(toCondition),
    });
  });

  return result;
}
