import { newCondition, newGroup, defaultOperatorFor } from './operators.js';

// The condition tree + its mutations — PURE, no DOM. The visualizer kept this state in a global and
// re-rendered after every change; here it's an instance and the view layer drives re-render + dirty.
// Nodes are addressed by dash-delimited path strings ("0", "1-2-0") exactly as in the visualizer.
export class QueryTree {
  constructor(conditions = []) {
    this.conditions = conditions;
    this.normalize(); // a loaded tree gets the same legality guarantee as an edited one
  }

  // ── Path addressing (ported from getNodeByPath / getParentArray / removeNodeByPath) ──
  getNodeByPath(path) {
    const parts = path.split('-').map(Number);
    let arr = this.conditions;
    for (let i = 0; i < parts.length - 1; i++) {
      arr = arr[parts[i]].children;
    }
    return arr[parts[parts.length - 1]];
  }

  getParentArray(path) {
    const parts = path.split('-').map(Number);
    let arr = this.conditions;
    for (let i = 0; i < parts.length - 1; i++) {
      arr = arr[parts[i]].children;
    }
    return { arr, index: parts[parts.length - 1] };
  }

  removeNodeByPath(path) {
    const { arr, index } = this.getParentArray(path);
    arr.splice(index, 1);
  }

  // The relationship path that scopes a position in the tree — the whereHas relationships you've
  // descended through to get here (groups don't change the model; whereHas do). A condition's column
  // picker uses this so it only offers the columns of the schema it's actually filtering. Walks every
  // segment of `path`: for editing pass the condition's path (its own node isn't a whereHas, so it's
  // ignored); for adding pass the parent path (a whereHas parent IS part of the context).
  relationshipContext(path) {
    if (!path) return [];
    const parts = path.split('-').map(Number);
    const relPath = [];
    let arr = this.conditions;
    for (let i = 0; i < parts.length; i++) {
      const node = arr[parts[i]];
      if (!node) break;
      if (node.type === 'whereHas') relPath.push(node.relationship);
      if (!Array.isArray(node.children)) break;
      arr = node.children;
    }
    return relPath;
  }

  // ── Top-level mutations (ported from addCondition / addGroup / addConditionFromColumn / removeCondition) ──
  // Every mutation ends with normalize() so the booleans stay legal: top level all-AND, each group
  // homogeneous. The unambiguous-query rule is enforced HERE, in what the builder emits — the
  // serialized ConditionNode shape is unchanged, so the parity-locked PHP side needs nothing.
  addCondition() {
    this.conditions.push(newCondition());
    this.normalize();
  }

  addGroup() {
    this.conditions.push(newGroup('or'));
    this.normalize();
  }

  addConditionFromColumn(column, columnType) {
    this.conditions.push(newCondition({ column, operator: defaultOperatorFor(columnType) }));
    this.normalize();
  }

  removeAt(index) {
    this.conditions.splice(index, 1);
    this.normalize();
  }

  // ── Nested mutations (ported from addChildCondition / addChildGroup) ──
  // Groups and whereHas nodes can BOTH host conditions, nested groups, and nested whereHas — full
  // nesting, all parity-tested on the PHP side. (The old "whereHas can't host a group" guard was wrong.)
  addChildCondition(path) {
    const node = this.getNodeByPath(path);
    if (node.type === 'group' || node.type === 'whereHas') {
      node.children.push(newCondition());
      this.normalize();
    }
  }

  addChildGroup(path) {
    const node = this.getNodeByPath(path);
    if (node.type === 'group' || node.type === 'whereHas') {
      node.children.push(newGroup('or'));
      this.normalize();
    }
  }

  // Add a relationship constraint (has / doesn't have / has-where). Empty parentPath = top level;
  // otherwise into a group. The model already supported whereHas — this is the missing UI affordance.
  addWhereHas(parentPath, values = {}) {
    const node = {
      type: 'whereHas',
      relationship: values.relationship || '',
      hasType: values.hasType || 'has',
      boolean: 'and',
      children: [],
    };
    if (parentPath) {
      const parent = this.getNodeByPath(parentPath);
      if (parent && Array.isArray(parent.children)) parent.children.push(node);
    } else {
      this.conditions.push(node);
    }
    this.normalize();
  }

  // Add a (possibly nested) whereHas from a relationship path picked in the navigator. The INNERMOST
  // hop is the endpoint and carries the chosen hasType (has / doesn't have) — that's the block you
  // then add conditions to; outer hops are plain whereHas wrappers. ['deals','products'] + doesntHave
  // → whereHas(deals → doesntHave(products)).
  addWhereHasPath(parentPath, relationshipPath, hasType = 'has') {
    if (!relationshipPath || !relationshipPath.length) return;
    let node = { type: 'whereHas', relationship: relationshipPath[relationshipPath.length - 1], hasType, boolean: 'and', children: [] };
    for (let i = relationshipPath.length - 2; i >= 0; i--) {
      node = { type: 'whereHas', relationship: relationshipPath[i], hasType: 'whereHas', boolean: 'and', children: [node] };
    }
    if (parentPath) {
      const parent = this.getNodeByPath(parentPath);
      if (parent && Array.isArray(parent.children)) parent.children.push(node);
    } else {
      this.conditions.push(node);
    }
    this.normalize();
  }

  // Insert a reference to a reusable Predicate (a named condition fragment from the Library). The host
  // expands it into its subtree at query time; here it's just a leaf reference node.
  addPredicateRef(parentPath, ref, label) {
    const node = { type: 'predicateRef', ref, label, boolean: 'and' };
    if (parentPath) {
      const parent = this.getNodeByPath(parentPath);
      if (parent && Array.isArray(parent.children)) parent.children.push(node);
    } else {
      this.conditions.push(node);
    }
    this.normalize();
  }

  // The payoff of the fuzzy search / navigator: pick a field reached via a relationship path and this
  // auto-builds the nested whereHas chain to it. ['deals','products'] + column 'name' becomes
  // whereHas(deals → whereHas(products → name …)). Empty relationshipPath = a plain base-model condition.
  addFieldPath(parentPath, field, conditionValues = {}) {
    let node = newCondition({
      column: field.column,
      operator: conditionValues.operator || defaultOperatorFor(field.type),
      value: conditionValues.value ?? '',
      valueType: 'hardcoded',
    });
    const relPath = field.relationshipPath || [];
    for (let i = relPath.length - 1; i >= 0; i--) {
      node = { type: 'whereHas', relationship: relPath[i], hasType: 'whereHas', boolean: 'and', children: [node] };
    }
    if (parentPath) {
      const parent = this.getNodeByPath(parentPath);
      if (parent && Array.isArray(parent.children)) parent.children.push(node);
    } else {
      this.conditions.push(node);
    }
    this.normalize();
  }

  // ── Unambiguous-boolean constraint ──
  // A group's Match ALL (and) / Match ANY (or) is carried by its children's shared boolean — there's
  // no separate field, so the serialized shape stays identical. setGroupConnector flips a group; it's
  // the only "boolean" control the UI exposes (per-condition booleans are gone).
  setGroupConnector(path, connector) {
    const node = this.getNodeByPath(path);
    if (node && Array.isArray(node.children)) {
      node.children.forEach((child) => { child.boolean = connector; });
      this.normalize();
    }
  }

  /** Read a group's connector ('and' = Match ALL, 'or' = Match ANY). */
  groupConnector(path) {
    const node = this.getNodeByPath(path);
    return node && node.children && node.children[0] ? (node.children[0].boolean === 'or' ? 'or' : 'and') : 'and';
  }

  /** A group's mode: 'all' (AND), 'any' (OR), or 'none' (negated OR → "Match NONE of"). */
  groupMode(path) {
    const node = this.getNodeByPath(path);
    if (!node) return 'all';
    if (node.negate) return 'none';
    return this.groupConnector(path) === 'or' ? 'any' : 'all';
  }

  // Cycle a group ALL → ANY → NONE → ALL. NONE is OR-joined + negated (whereNot over "any of"), so it
  // reads as "none of these match". Only the toggle the UI exposes for a group's mode.
  cycleGroupMode(path) {
    const node = this.getNodeByPath(path);
    if (!node || node.type !== 'group') return;
    const mode = this.groupMode(path);
    if (mode === 'all') { node.negate = false; this.setGroupConnector(path, 'or'); }       // ALL → ANY
    else if (mode === 'any') { node.negate = true; this.setGroupConnector(path, 'or'); }    // ANY → NONE
    else { node.negate = false; this.setGroupConnector(path, 'and'); }                       // NONE → ALL
  }

  // Enforce: top level is all-AND; within any group/whereHas, all children share ONE connector
  // (the first child's, preserved). Mixing and/or at one level is impossible — you nest to combine.
  normalize() {
    this.#normalize(this.conditions, 'and');
  }

  #normalize(nodes, connector) {
    for (const node of nodes) {
      node.boolean = connector;
      if (Array.isArray(node.children) && node.children.length) {
        const childConnector = node.children[0].boolean === 'or' ? 'or' : 'and';
        this.#normalize(node.children, childConnector);
      }
    }
  }
}
