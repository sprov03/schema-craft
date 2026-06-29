// Schema-graph traversal — PURE, no DOM, no fetch. The host provides a NORMALIZED model map; this
// flattens it into the list of reachable FIELD-PATHS a few hops out, which the fuzzy search ranks and
// the navigator browses. Bounded depth + cycle-skip keep it from exploding (Contact → deals → contact
// → … never loops).
//
// Metadata graph shape (host-supplied):
//   {
//     base: 'App\\Models\\Contact',
//     models: {
//       'App\\Models\\Contact': { columns: [{name,type,options?}], relationships: [{name, target}] },
//       'App\\Models\\Deal':    { columns: [...], relationships: [{name:'products', target:'App\\Models\\Product'}] },
//       ...
//     }
//   }
//
// A field-path: { relationshipPath: ['deals','products'], column: 'name', type, options, label: 'deals → products → name' }.

import { fuzzyMatch } from './fuzzy.js';

function modelShortName(fqcn) {
  if (!fqcn) return '';
  const parts = String(fqcn).split('\\');
  return parts[parts.length - 1];
}

// ── Relationship RESOLVER (over the lightweight full adjacency: model → [{name, target}]) ──
// The model you reach by following a sequence of relationship names from the base. Returns null if any
// hop doesn't resolve (e.g. a polymorphic edge). Used to find the CONTEXT model at a nested position.
export function modelAtPath(adjacency, relPath = []) {
  if (!adjacency || !adjacency.models || !adjacency.base) return null;
  let model = adjacency.base;
  for (const rel of relPath) {
    const edge = (adjacency.models[model] || []).find((e) => e.name === rel);
    if (!edge || !edge.target) return null;
    model = edge.target;
  }
  return model;
}

// BFS from a starting model → Map(targetModel → SHORTEST path as an array of EDGES {name,target,kind,
// many}). First time a model is reached IS the shortest path (fewest hops = simplest whereHas chain =
// "most effective"). Cycle-safe. whereHas works on every relationship type, so type never gates the
// path — it rides along only for cardinality/clarity in the display.
export function shortestPaths(adjacency, fromModel) {
  const out = new Map();
  if (!adjacency || !adjacency.models) return out;
  const start = fromModel || adjacency.base;
  if (!start || !adjacency.models[start]) return out;
  const queue = [[start, []]];
  const seen = new Set([start]);
  while (queue.length) {
    const [model, path] = queue.shift();
    for (const e of adjacency.models[model] || []) {
      if (!e.target || seen.has(e.target)) continue; // polymorphic (no target) can't be a hop
      const next = [...path, e];
      out.set(e.target, next);
      seen.add(e.target);
      queue.push([e.target, next]);
    }
  }
  return out;
}

// Search reachable models by name (fuzzy) FROM a given context model. Each result carries the shortest
// path (edges, with kind/cardinality) + just the names for building. Sorted by match, then fewest hops.
// Unreachable models simply don't appear → "no connection from here".
export function searchModelPaths(adjacency, fromModel, query, limit = 50) {
  const paths = shortestPaths(adjacency, fromModel);
  const results = [];
  for (const [target, edges] of paths) {
    const name = modelShortName(target);
    const m = query ? fuzzyMatch(query, name) : null;
    if (query && !m) continue;
    results.push({ target, name, edges, path: edges.map((e) => e.name), hops: edges.length, score: m ? m.score : 0 });
  }
  results.sort((a, b) => b.score - a.score || a.hops - b.hops || a.name.localeCompare(b.name));
  return results.slice(0, limit);
}

// To-ONE related columns reachable from a context model — the targets for a relatedColumn reference
// (compare to a column on a belongsTo/hasOne record). Uses the adjacency for cardinality (many=false)
// and the rich metadata for the related model's columns. Flat list of { relationship, column, label }.
export function relatedColumnOptions(metadata, adjacency, contextModel) {
  const out = [];
  if (!adjacency || !adjacency.models || !contextModel) return out;
  for (const e of adjacency.models[contextModel] || []) {
    if (e.many || !e.target) continue; // to-one only — a to-many would be an aggregate, not a single value
    const cols = (metadata && metadata.models && metadata.models[e.target] && metadata.models[e.target].columns) || [];
    for (const c of cols) {
      out.push({ relationship: e.name, column: c.name, label: e.name + ' → ' + c.name, type: c.type });
    }
  }
  return out;
}

// To-MANY (hasMany) related columns reachable from a context model — the targets for an aggregate
// (avg/sum/min/max over a relation's column). Restricted to hasMany (the backend builds an FK-correlated
// subquery). Flat list of { relationship, column, label }.
export function aggregateColumnOptions(metadata, adjacency, contextModel) {
  const out = [];
  if (!adjacency || !adjacency.models || !contextModel) return out;
  for (const e of adjacency.models[contextModel] || []) {
    if (e.kind !== 'hasMany' || !e.target) continue; // FK-correlated aggregate only
    const cols = (metadata && metadata.models && metadata.models[e.target] && metadata.models[e.target].columns) || [];
    for (const c of cols) {
      out.push({ relationship: e.name, column: c.name, label: e.name + ' → ' + c.name, type: c.type });
    }
  }
  return out;
}

export function flattenGraph(metadata, maxDepth = 3) {
  const results = [];
  if (!metadata || !metadata.models || !metadata.base) return results;

  function walk(modelName, relPath, visited, depth) {
    const model = metadata.models[modelName];
    if (!model) return;

    for (const col of model.columns || []) {
      results.push({
        relationshipPath: relPath,
        column: col.name,
        type: col.type || 'string',
        options: col.options || null,
        label: [...relPath, col.name].join(' → '),
      });
    }

    if (depth >= maxDepth) return;
    for (const rel of model.relationships || []) {
      if (visited.has(rel.target)) continue; // cycle — don't revisit a model already in this path
      walk(rel.target, [...relPath, rel.name], new Set([...visited, rel.target]), depth + 1);
    }
  }

  walk(metadata.base, [], new Set([metadata.base]), 0);
  return results;
}

// One level of the graph for the NAVIGATOR (browse, don't search): the columns + relationships of the
// model reached by following `relPath` from the base. Cycle-safe by construction (it just walks the path).
export function levelAt(metadata, relPath = []) {
  if (!metadata || !metadata.models || !metadata.base) return { columns: [], relationships: [] };
  let modelName = metadata.base;
  for (const rel of relPath) {
    const model = metadata.models[modelName];
    const edge = model && (model.relationships || []).find((r) => r.name === rel);
    if (!edge) return { columns: [], relationships: [] };
    modelName = edge.target;
  }
  const model = metadata.models[modelName] || {};
  return { model: modelName, columns: model.columns || [], relationships: model.relationships || [] };
}

// All columns across every model, deduped by name — feeds the editor modal's column autocomplete +
// type lookup. (Pragmatic: a name appearing in two models takes the first type seen; good enough for
// the editor, while the cross-hop SEARCH uses full field-paths so it never loses the relationship.)
export function allColumns(metadata) {
  const seen = new Map();
  if (metadata && metadata.models) {
    for (const model of Object.values(metadata.models)) {
      for (const col of model.columns || []) {
        if (!seen.has(col.name)) seen.set(col.name, { name: col.name, type: col.type || 'string', options: col.options || null });
      }
    }
  }
  return [...seen.values()];
}

export function columnTypeInfo(columns, columnName) {
  return (columns || []).find((c) => c.name === columnName) || { name: columnName, type: 'string', options: null };
}

// All relationships across the graph (deduped by name, first target kept) — feeds the relationship
// combobox: { name, target } so it can show the related model as a sublabel.
export function allRelationships(metadata) {
  const seen = new Map();
  if (metadata && metadata.models) {
    for (const model of Object.values(metadata.models)) {
      for (const rel of model.relationships || []) {
        if (!seen.has(rel.name)) seen.set(rel.name, { name: rel.name, target: rel.target || null });
      }
    }
  }
  return [...seen.values()];
}
