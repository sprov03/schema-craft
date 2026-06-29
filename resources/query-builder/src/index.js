// Public entry point for the query-builder package.
//
// CORE (pure, tested, the ConditionNode contract) + VIEW (DOM) + the init(el, config) host boundary.
// All I/O stays in the HOST: the builder emits onChange(tree) / onSave(tree) / onPreview(tree); the
// host (visualizer or Filament) does any fetch/persistence. That keeps the builder fully testable and
// host-agnostic. esbuild bundles this into dist/query-builder.js (global SchemaCraftQueryBuilder),
// served locally and loaded by both hosts.

import { QueryTree } from './core/tree.js';
import { serializeConditionNodes, buildQueryDefinition } from './core/serialize.js';

// The chart builder (sibling to the filter builder) — pick a chart type + configure it against the schema.
export { initChartBuilder } from './view/chart-builder.js';
import { renderTree } from './view/render.js';
import { openEditor, openSearch, openNavigator, openRelationshipSearch, openComputedValueDefinition, openPredicateDefinition, openPredicatePicker, openGraduate, openActionMenu } from './view/modal.js';
import { flattenGraph, allRelationships, levelAt, modelAtPath, relatedColumnOptions, aggregateColumnOptions } from './core/graph.js';

export { OPERATORS, defaultOperatorFor, newCondition, newGroup } from './core/operators.js';
export { QueryTree } from './core/tree.js';
export { serializeConditionNodes, buildQueryDefinition } from './core/serialize.js';
export { unflattenConditions } from './core/legacy.js';
export { columnsFor, relationshipsFor, columnMeta, typeOf, optionsFor } from './core/metadata.js';
export { fuzzyMatch, searchFields } from './core/fuzzy.js';
export { flattenGraph, levelAt } from './core/graph.js';

/**
 * Mount the builder into `el`.
 *
 * config:
 *   flags        { allowGroups?: bool, ... }  — gate UI options (the "comment out joins/subqueries")
 *   initialTree  ConditionNode[]               — tree to edit (deep-cloned; we never mutate the host's)
 *   onChange(tree)   emitted after every edit  — the serialized ConditionNode[] for the host to store
 *   onSave(tree) / onPreview(tree)             — host-handled actions (host owns the fetch)
 *
 * Returns a handle: getTree(), setTree(tree), destroy().
 */
export function init(el, config = {}) {
  const flags = config.flags || {};
  const onChange = config.onChange || (() => {});
  const onSave = config.onSave || (() => {});
  const onPreview = config.onPreview || (() => {});

  // Schema metadata (the model graph) drives the column autocomplete + typed values + the cross-hop
  // search. The host supplies it; sparse/empty metadata just means plain text inputs and no search.
  const metadata = config.metadata || null;
  const adjacency = config.adjacency || null; // lightweight full relationship map for the path resolver
  const userComputedValues = []; // CVs DEFINED this session — { name, model, kind, column, aggregateFunction, conditions }
  // host-provided + user-defined Computed Values, as { name, label, kind } for the ƒ insertion picker.
  const computedValuesList = () => [
    ...(config.computedValues || []),
    ...userComputedValues.map((c) => ({ name: c.name, label: c.label || c.name, kind: c.kind || 'list' })),
  ];
  const userPredicates = []; // Predicates DEFINED this session — { name(slug), label }
  const predicateList = () => [...(config.predicates || []), ...userPredicates];
  const relationships = allRelationships(metadata);
  // Depth 2 = "a couple hops" for the field Find; depth 3 explodes to ~36k paths on the real graph
  // (slow per-keystroke filtering). Deeper discovery is the relationship search's job, not this one.
  const fields = flattenGraph(metadata, config.maxDepth || 2);

  // Deep clone so editing never mutates the host's array (structuredClone is in Node 17+ and browsers).
  const tree = new QueryTree(config.initialTree ? structuredClone(config.initialTree) : []);

  const serialize = () => serializeConditionNodes(tree.conditions);
  const emit = () => onChange(serialize());
  const commit = () => { render(); emit(); };

  // Columns for a position's SCHEMA — scoped to the relationship context, not the whole graph. (The
  // cross-hop Find search is the deliberate exception; it spans relationships.)
  const contextColumns = (path) => levelAt(metadata, tree.relationshipContext(path)).columns;
  // To-one related columns available at a position — targets for a "compare to another table" reference.
  const contextModelFor = (path) => modelAtPath(adjacency, tree.relationshipContext(path)) || (adjacency && adjacency.base);
  const relatedOptionsFor = (path) => relatedColumnOptions(metadata, adjacency, contextModelFor(path));
  const aggregateOptionsFor = (path) => aggregateColumnOptions(metadata, adjacency, contextModelFor(path));

  // Edit an existing node in the modal; apply on save.
  function editNode(path) {
    const node = tree.getNodeByPath(path);
    if (node) openEditor(node, { columns: contextColumns(path), relationships, relatedOptions: relatedOptionsFor(path), aggregateOptions: aggregateOptionsFor(path), computedValues: computedValuesList(), onSave: (values) => { Object.assign(node, values); commit(); } });
  }

  // Cross-hop discovery: search the schema graph, pick a field, auto-build the path to it.
  function findField() {
    openSearch(fields, { onSelect: (hit) => { tree.addFieldPath('', hit); commit(); } });
  }

  // Browse the schema graph (the navigator), pick a column, auto-build the path to it.
  function browseSchema() {
    openNavigator(metadata, { onSelect: (field) => { tree.addFieldPath('', field); commit(); } });
  }

  // Find-a-relationship: resolve from the CONTEXT model at parentPath (top level = base, nested = the
  // model you're inside) so the shortest path is correct wherever you stand. Select → build the chain.
  function relSearch(parentPath) {
    const ctxModel = modelAtPath(adjacency, tree.relationshipContext(parentPath)) || (adjacency && adjacency.base);
    openRelationshipSearch(adjacency, ctxModel, { onSelect: (path) => { tree.addWhereHasPath(parentPath, path, 'has'); commit(); } });
  }

  // Add a condition via the modal — only added to the tree when saved (cancel = nothing happens).
  function addCondition(parentPath) {
    openEditor({ type: 'condition' }, {
      columns: contextColumns(parentPath),
      relatedOptions: relatedOptionsFor(parentPath),
      aggregateOptions: aggregateOptionsFor(parentPath),
      computedValues: computedValuesList(),
      onSave: (values) => {
        if (parentPath) {
          tree.addChildCondition(parentPath);
          const parent = tree.getNodeByPath(parentPath);
          Object.assign(parent.children[parent.children.length - 1], values);
        } else {
          tree.addCondition();
          Object.assign(tree.conditions[tree.conditions.length - 1], values);
        }
        commit();
      },
    });
  }

  // Add a relationship constraint by BROWSING the schema (the navigator in relationship mode): drill
  // in, then Select a relationship at any depth (with a has / doesn't-have toggle) → build the whereHas.
  function addWhereHas(parentPath) {
    openNavigator(metadata, {
      mode: 'relationship',
      onSelect: (sel) => { tree.addWhereHasPath(parentPath, sel.relationshipPath, sel.hasType); commit(); },
    });
  }

  // Define a reusable COMPUTED VALUE: a mini-builder pointed at any model, yielding a typed result (list
  // or scalar). The model's schema is fetched on demand for the nested builder; registered locally and
  // surfaced in the ƒ insertion picker; the host sends userComputedValues to the resolver at preview.
  function defineComputedValue() {
    openComputedValueDefinition({
      models: config.models || [],
      fetchSchema: config.fetchSchema || (async () => ({})),
      mountBuilder: (host, schema) => init(host, {
        metadata: (schema && schema.graph) || null,
        adjacency: (schema && schema.adjacency) || null,
        flags: { allowGroups: true, defineSet: false, definePredicate: false, relSearch: false },
      }),
      onSave: async (def) => {
        // Save to the shared Library (persisted, referenced by slug) or keep it ad-hoc (this session).
        if (def.saveToLibrary && config.onSaveToLibrary) {
          const res = await config.onSaveToLibrary({
            name: def.name, visibility: def.visibility,
            definition: { model: def.model, kind: def.kind, column: def.column, aggregateFunction: def.aggregateFunction, conditions: def.conditions },
          });
          if (res && res.ok) {
            userComputedValues.push({ name: res.slug, label: def.name, model: def.model, kind: res.kind, column: def.column, aggregateFunction: def.aggregateFunction, conditions: def.conditions });
          }
        } else {
          userComputedValues.push({ ...def, label: def.name });
        }
        commit();
      },
    });
  }

  // Define a reusable PREDICATE: a named condition fragment built on the base model, saved to the Library.
  // SCOPE = the model it's authored against (the base here); the Library stores it so insertion is gated.
  function definePredicate() {
    const scope = contextModelFor('');
    openPredicateDefinition({
      schema: { graph: metadata, adjacency },
      mountBuilder: (host, schema) => init(host, {
        metadata: (schema && schema.graph) || null,
        adjacency: (schema && schema.adjacency) || null,
        flags: { allowGroups: true, defineSet: false, definePredicate: false, relSearch: true },
      }),
      onSave: async (def) => {
        if (config.onSaveToLibrary) {
          const res = await config.onSaveToLibrary({ kind: 'predicate', name: def.name, visibility: def.visibility, definition: { conditions: def.conditions, scope } });
          if (res && res.ok) userPredicates.push({ name: res.slug, label: def.name, scope });
        }
        commit();
      },
    });
  }

  // GRADUATE a node (a group/section you built inline) up to the Library at ITS scope — the node's
  // context model. Captures the node's subtree verbatim; reusable anywhere that scope matches.
  function graduateNode(path) {
    const node = tree.getNodeByPath(path);
    if (!node) return;
    const conditions = serializeConditionNodes([node]);
    const scope = contextModelFor(path);
    openGraduate({ scope, count: (node.children || []).length || 1 }, {
      onSave: async (info) => {
        if (config.onSaveToLibrary) {
          const res = await config.onSaveToLibrary({ kind: 'predicate', name: info.name, visibility: info.visibility, definition: { conditions, scope } });
          if (res && res.ok) userPredicates.push({ name: res.slug, label: info.name, scope });
        }
        commit();
      },
    });
  }

  // Insert a Library Predicate at parentPath — only ones whose SCOPE matches THIS node's context model
  // (a CommunicationLog predicate only inside a comm-logs whereHas; a base predicate only at base level).
  // Legacy null-scope predicates were base-built, so they fall back to the base model.
  function insertPredicate(parentPath) {
    const scope = contextModelFor(parentPath);
    const base = adjacency && adjacency.base;
    const inScope = predicateList().filter((p) => (p.scope || base) === scope);
    openPredicatePicker(inScope, { onSelect: (it) => { tree.addPredicateRef(parentPath, it.name, it.label); commit(); } });
  }

  // ONE dispatcher so the click handler AND the "+ Add" menu run the same actions.
  function runAction(action, path) {
    switch (action) {
      case 'edit': editNode(path); break;
      case 'search': findField(); break;
      case 'navigate': browseSchema(); break;
      case 'rel-search': relSearch(path); break;
      case 'define-computed-value': defineComputedValue(); break;
      case 'define-predicate': definePredicate(); break;
      case 'add-predicate': insertPredicate(path); break;
      case 'graduate': graduateNode(path); break;
      case 'add-condition': addCondition(''); break;
      case 'add-child-condition': addCondition(path); break;
      case 'add-wherehas': addWhereHas(''); break;
      case 'add-child-wherehas': addWhereHas(path); break;
      case 'add-group': tree.addGroup(); commit(); break;
      case 'add-child-group': tree.addChildGroup(path); commit(); break;
      case 'toggle-connector': tree.cycleGroupMode(path); commit(); break;
      case 'remove': tree.removeNodeByPath(path); commit(); break;
      case 'save-query': onSave(serialize()); break;
      case 'preview': onPreview(serialize()); break;
      case 'open-add': openAddMenu(path); break;
      default: break;
    }
  }

  // The consolidated "+ Add" menu — replaces the button soup. One entry point lists what's addable HERE;
  // at the root the top-level add actions, inside a group the child ones. Scope-aware via runAction.
  function openAddMenu(path) {
    const atRoot = path === '';
    const options = [
      { label: '+ Condition', action: atRoot ? 'add-condition' : 'add-child-condition' },
      { label: '+ Relationship', action: atRoot ? 'add-wherehas' : 'add-child-wherehas' },
      ...(flags.relSearch === false ? [] : [{ label: '🔗 Find related', action: 'rel-search' }]),
      ...(flags.definePredicate === false ? [] : [{ label: '▣ Predicate', action: 'add-predicate' }]),
      ...(flags.allowGroups === false ? [] : [{ label: '+ Group', action: atRoot ? 'add-group' : 'add-child-group' }]),
    ];
    openActionMenu(options, { onPick: (action) => runAction(action, path) });
  }

  function bindEvents() {
    el.querySelectorAll('[data-action]').forEach((node) => {
      node.addEventListener('click', (event) => {
        event.preventDefault();
        runAction(node.getAttribute('data-action'), node.getAttribute('data-path') || '');
      });
    });
  }

  function render() {
    el.innerHTML = '';
    el.appendChild(renderTree(tree, flags));
    bindEvents();
  }

  render();

  return {
    getTree: () => serialize(),
    // The session's user-defined set definitions — the host sends these to previewFilter so the resolver
    // can turn each referenced set into a whereIn array.
    // session's user-defined Computed Values — the host sends these to previewFilter to resolve them.
    getComputedValueDefinitions: () => userComputedValues.map((c) => ({ name: c.name, model: c.model, kind: c.kind, column: c.column, aggregateFunction: c.aggregateFunction, conditions: c.conditions })),
    // allow restoring persisted CVs (from the Report) into this session.
    setComputedValueDefinitions: (defs) => { userComputedValues.length = 0; (defs || []).forEach((d) => userComputedValues.push(d)); render(); },
    getDefinition: (meta = {}) => buildQueryDefinition({ ...meta, conditions: tree.conditions }),
    setTree: (nextTree) => {
      tree.conditions = nextTree ? structuredClone(nextTree) : [];
      render();
      emit();
    },
    destroy: () => { el.innerHTML = ''; },
  };
}
