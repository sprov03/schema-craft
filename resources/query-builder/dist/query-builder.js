var SchemaCraftQueryBuilder = (() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __export = (target, all) => {
    for (var name in all)
      __defProp(target, name, { get: all[name], enumerable: true });
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

  // src/index.js
  var src_exports = {};
  __export(src_exports, {
    OPERATORS: () => OPERATORS,
    QueryTree: () => QueryTree,
    buildQueryDefinition: () => buildQueryDefinition,
    columnMeta: () => columnMeta,
    columnsFor: () => columnsFor,
    defaultOperatorFor: () => defaultOperatorFor,
    flattenGraph: () => flattenGraph,
    fuzzyMatch: () => fuzzyMatch,
    init: () => init,
    initChartBuilder: () => initChartBuilder,
    levelAt: () => levelAt,
    newCondition: () => newCondition,
    newGroup: () => newGroup,
    optionsFor: () => optionsFor,
    relationshipsFor: () => relationshipsFor,
    searchFields: () => searchFields,
    serializeConditionNodes: () => serializeConditionNodes,
    typeOf: () => typeOf,
    unflattenConditions: () => unflattenConditions
  });

  // src/core/operators.js
  var OPERATORS = ["=", "!=", ">", "<", ">=", "<=", "like", "in", "not_in", "is_null", "is_not_null", "between"];
  function defaultOperatorFor(columnType) {
    const t = (columnType || "").toLowerCase();
    if (t.includes("date") || t.includes("timestamp") || t === "datetime") return "between";
    if (t === "string" || t === "text" || t === "varchar" || t === "char") return "like";
    if (t === "boolean" || t === "bool" || t === "tinyint") return "=";
    return "=";
  }
  function newCondition(overrides = {}) {
    return { type: "condition", column: "", operator: "=", value: "", boolean: "and", valueType: "dynamic", ...overrides };
  }
  function newGroup(connector = "or") {
    return { type: "group", boolean: connector, children: [newCondition({ boolean: connector })] };
  }

  // src/core/tree.js
  var QueryTree = class {
    constructor(conditions = []) {
      this.conditions = conditions;
      this.normalize();
    }
    // ── Path addressing (ported from getNodeByPath / getParentArray / removeNodeByPath) ──
    getNodeByPath(path) {
      const parts = path.split("-").map(Number);
      let arr = this.conditions;
      for (let i = 0; i < parts.length - 1; i++) {
        arr = arr[parts[i]].children;
      }
      return arr[parts[parts.length - 1]];
    }
    getParentArray(path) {
      const parts = path.split("-").map(Number);
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
      const parts = path.split("-").map(Number);
      const relPath = [];
      let arr = this.conditions;
      for (let i = 0; i < parts.length; i++) {
        const node = arr[parts[i]];
        if (!node) break;
        if (node.type === "whereHas") relPath.push(node.relationship);
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
      this.conditions.push(newGroup("or"));
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
      if (node.type === "group" || node.type === "whereHas") {
        node.children.push(newCondition());
        this.normalize();
      }
    }
    addChildGroup(path) {
      const node = this.getNodeByPath(path);
      if (node.type === "group" || node.type === "whereHas") {
        node.children.push(newGroup("or"));
        this.normalize();
      }
    }
    // Add a relationship constraint (has / doesn't have / has-where). Empty parentPath = top level;
    // otherwise into a group. The model already supported whereHas — this is the missing UI affordance.
    addWhereHas(parentPath, values = {}) {
      const node = {
        type: "whereHas",
        relationship: values.relationship || "",
        hasType: values.hasType || "has",
        boolean: "and",
        children: []
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
    addWhereHasPath(parentPath, relationshipPath, hasType = "has") {
      if (!relationshipPath || !relationshipPath.length) return;
      let node = { type: "whereHas", relationship: relationshipPath[relationshipPath.length - 1], hasType, boolean: "and", children: [] };
      for (let i = relationshipPath.length - 2; i >= 0; i--) {
        node = { type: "whereHas", relationship: relationshipPath[i], hasType: "whereHas", boolean: "and", children: [node] };
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
      const node = { type: "predicateRef", ref, label, boolean: "and" };
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
    addFieldPath(parentPath, field2, conditionValues = {}) {
      let node = newCondition({
        column: field2.column,
        operator: conditionValues.operator || defaultOperatorFor(field2.type),
        value: conditionValues.value ?? "",
        valueType: "hardcoded"
      });
      const relPath = field2.relationshipPath || [];
      for (let i = relPath.length - 1; i >= 0; i--) {
        node = { type: "whereHas", relationship: relPath[i], hasType: "whereHas", boolean: "and", children: [node] };
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
        node.children.forEach((child) => {
          child.boolean = connector;
        });
        this.normalize();
      }
    }
    /** Read a group's connector ('and' = Match ALL, 'or' = Match ANY). */
    groupConnector(path) {
      const node = this.getNodeByPath(path);
      return node && node.children && node.children[0] ? node.children[0].boolean === "or" ? "or" : "and" : "and";
    }
    /** A group's mode: 'all' (AND), 'any' (OR), or 'none' (negated OR → "Match NONE of"). */
    groupMode(path) {
      const node = this.getNodeByPath(path);
      if (!node) return "all";
      if (node.negate) return "none";
      return this.groupConnector(path) === "or" ? "any" : "all";
    }
    // Cycle a group ALL → ANY → NONE → ALL. NONE is OR-joined + negated (whereNot over "any of"), so it
    // reads as "none of these match". Only the toggle the UI exposes for a group's mode.
    cycleGroupMode(path) {
      const node = this.getNodeByPath(path);
      if (!node || node.type !== "group") return;
      const mode = this.groupMode(path);
      if (mode === "all") {
        node.negate = false;
        this.setGroupConnector(path, "or");
      } else if (mode === "any") {
        node.negate = true;
        this.setGroupConnector(path, "or");
      } else {
        node.negate = false;
        this.setGroupConnector(path, "and");
      }
    }
    // Enforce: top level is all-AND; within any group/whereHas, all children share ONE connector
    // (the first child's, preserved). Mixing and/or at one level is impossible — you nest to combine.
    normalize() {
      this.#normalize(this.conditions, "and");
    }
    #normalize(nodes, connector) {
      for (const node of nodes) {
        node.boolean = connector;
        if (Array.isArray(node.children) && node.children.length) {
          const childConnector = node.children[0].boolean === "or" ? "or" : "and";
          this.#normalize(node.children, childConnector);
        }
      }
    }
  };

  // src/core/serialize.js
  function serializeConditionNodes(nodes) {
    return (nodes || []).filter((node) => {
      if (node.type === "condition" && !node.column) return false;
      return true;
    }).map((node) => {
      if (node.type === "condition") {
        const obj = {
          type: "condition",
          column: node.column,
          operator: node.operator,
          value: node.value,
          boolean: node.boolean,
          valueType: node.valueType || "hardcoded"
        };
        if (node.referenceColumn) obj.referenceColumn = node.referenceColumn;
        if (node.referenceRelationship) obj.referenceRelationship = node.referenceRelationship;
        if (node.aggregateFunction) obj.aggregateFunction = node.aggregateFunction;
        if (node.referenceComputedValue) obj.referenceComputedValue = node.referenceComputedValue;
        return obj;
      }
      if (node.type === "predicateRef") {
        return { type: "predicateRef", ref: node.ref, boolean: node.boolean || "and" };
      }
      if (node.type === "group") {
        const group = {
          type: "group",
          boolean: node.boolean || "and",
          children: serializeConditionNodes(node.children || [])
        };
        if (node.negate) group.negate = true;
        return group;
      }
      if (node.type === "whereHas") {
        const wh = {
          type: "whereHas",
          relationship: node.relationship,
          sourceModel: node.sourceModel || "",
          boolean: node.boolean || "and",
          hasType: node.hasType || "has",
          children: serializeConditionNodes(node.children || [])
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
  function buildQueryDefinition(state) {
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
        relationshipName: j.relationshipName
      })),
      conditions: serializeConditionNodes(state.conditions),
      sorts: (state.sorts || []).filter((s) => s.column).map((s) => ({ column: s.column, direction: s.direction })),
      output: {
        scopeOnModel: state.output?.scopeOnModel,
        apiEndpoint: state.output?.apiEndpoint,
        inlineController: state.output?.inlineController
      }
    };
    if (state.selectedApi) def.api = state.selectedApi;
    return def;
  }

  // src/core/fuzzy.js
  function fuzzyMatch(query, text) {
    if (!query) return { score: 0, indices: [] };
    let qi = 0;
    const indices = [];
    let score = 0;
    let lastIdx = -1;
    const qLower = query.toLowerCase();
    const tLower = text.toLowerCase();
    for (let ti = 0; ti < tLower.length && qi < qLower.length; ti++) {
      if (tLower[ti] === qLower[qi]) {
        indices.push(ti);
        if (ti === lastIdx + 1) score += 5;
        if (ti === 0 || text[ti - 1] === "." || text[ti - 1] === "_" || text[ti - 1] === " ") score += 10;
        score += 1;
        lastIdx = ti;
        qi++;
      }
    }
    return qi === qLower.length ? { score, indices } : null;
  }
  function searchFields(query, fields, limit = 25) {
    if (!query) return fields.slice(0, limit);
    return fields.map((f) => {
      const m = fuzzyMatch(query, f.label);
      return m ? { ...f, score: m.score, indices: m.indices } : null;
    }).filter(Boolean).sort((a, b) => b.score - a.score).slice(0, limit);
  }

  // src/core/graph.js
  function modelShortName(fqcn) {
    if (!fqcn) return "";
    const parts = String(fqcn).split("\\");
    return parts[parts.length - 1];
  }
  function modelAtPath(adjacency, relPath = []) {
    if (!adjacency || !adjacency.models || !adjacency.base) return null;
    let model = adjacency.base;
    for (const rel of relPath) {
      const edge = (adjacency.models[model] || []).find((e) => e.name === rel);
      if (!edge || !edge.target) return null;
      model = edge.target;
    }
    return model;
  }
  function shortestPaths(adjacency, fromModel) {
    const out = /* @__PURE__ */ new Map();
    if (!adjacency || !adjacency.models) return out;
    const start = fromModel || adjacency.base;
    if (!start || !adjacency.models[start]) return out;
    const queue = [[start, []]];
    const seen = /* @__PURE__ */ new Set([start]);
    while (queue.length) {
      const [model, path] = queue.shift();
      for (const e of adjacency.models[model] || []) {
        if (!e.target || seen.has(e.target)) continue;
        const next = [...path, e];
        out.set(e.target, next);
        seen.add(e.target);
        queue.push([e.target, next]);
      }
    }
    return out;
  }
  function searchModelPaths(adjacency, fromModel, query, limit = 50) {
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
  function relatedColumnOptions(metadata, adjacency, contextModel) {
    const out = [];
    if (!adjacency || !adjacency.models || !contextModel) return out;
    for (const e of adjacency.models[contextModel] || []) {
      if (e.many || !e.target) continue;
      const cols = metadata && metadata.models && metadata.models[e.target] && metadata.models[e.target].columns || [];
      for (const c of cols) {
        out.push({ relationship: e.name, column: c.name, label: e.name + " \u2192 " + c.name, type: c.type });
      }
    }
    return out;
  }
  function aggregateColumnOptions(metadata, adjacency, contextModel) {
    const out = [];
    if (!adjacency || !adjacency.models || !contextModel) return out;
    for (const e of adjacency.models[contextModel] || []) {
      if (e.kind !== "hasMany" || !e.target) continue;
      const cols = metadata && metadata.models && metadata.models[e.target] && metadata.models[e.target].columns || [];
      for (const c of cols) {
        out.push({ relationship: e.name, column: c.name, label: e.name + " \u2192 " + c.name, type: c.type });
      }
    }
    return out;
  }
  function flattenGraph(metadata, maxDepth = 3) {
    const results = [];
    if (!metadata || !metadata.models || !metadata.base) return results;
    function walk(modelName, relPath, visited, depth) {
      const model = metadata.models[modelName];
      if (!model) return;
      for (const col of model.columns || []) {
        results.push({
          relationshipPath: relPath,
          column: col.name,
          type: col.type || "string",
          options: col.options || null,
          label: [...relPath, col.name].join(" \u2192 ")
        });
      }
      if (depth >= maxDepth) return;
      for (const rel of model.relationships || []) {
        if (visited.has(rel.target)) continue;
        walk(rel.target, [...relPath, rel.name], /* @__PURE__ */ new Set([...visited, rel.target]), depth + 1);
      }
    }
    walk(metadata.base, [], /* @__PURE__ */ new Set([metadata.base]), 0);
    return results;
  }
  function levelAt(metadata, relPath = []) {
    if (!metadata || !metadata.models || !metadata.base) return { columns: [], relationships: [] };
    let modelName = metadata.base;
    for (const rel of relPath) {
      const model2 = metadata.models[modelName];
      const edge = model2 && (model2.relationships || []).find((r) => r.name === rel);
      if (!edge) return { columns: [], relationships: [] };
      modelName = edge.target;
    }
    const model = metadata.models[modelName] || {};
    return { model: modelName, columns: model.columns || [], relationships: model.relationships || [] };
  }
  function columnTypeInfo(columns, columnName) {
    return (columns || []).find((c) => c.name === columnName) || { name: columnName, type: "string", options: null };
  }
  function allRelationships(metadata) {
    const seen = /* @__PURE__ */ new Map();
    if (metadata && metadata.models) {
      for (const model of Object.values(metadata.models)) {
        for (const rel of model.relationships || []) {
          if (!seen.has(rel.name)) seen.set(rel.name, { name: rel.name, target: rel.target || null });
        }
      }
    }
    return [...seen.values()];
  }

  // src/view/combobox.js
  function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === "class") node.className = v;
      else if (k === "text") node.textContent = v;
      else node[k] = v;
    }
    for (const child of children) node.appendChild(child);
    return node;
  }
  function createCombobox({ items = [], placeholder = "", initialValue = "", grouped = false, onSelect } = {}) {
    const input = el("input", { type: "text", class: "qb-combo-input", value: initialValue, placeholder });
    const list = el("div", { class: "qb-combo-list" });
    const wrap = el("div", { class: "qb-combo" }, [input, list]);
    let highlighted = -1;
    function filter() {
      const q = input.value.trim();
      if (!q) return items;
      return items.map((it) => {
        const m = fuzzyMatch(q, it.label + " " + (it.sublabel || "") + " " + (it.group || ""));
        return m ? { it, score: m.score } : null;
      }).filter(Boolean).sort((a, b) => b.score - a.score).map((x) => x.it);
    }
    function itemEl(it) {
      const node = el("button", { type: "button", class: "qb-combo-item" }, [
        el("span", { class: "qb-combo-label", text: it.label }),
        ...it.sublabel ? [el("span", { class: "qb-combo-sub", text: it.sublabel })] : []
      ]);
      node.__item = it;
      node.addEventListener("mousedown", (e) => {
        e.preventDefault();
        select(it);
      });
      return node;
    }
    function render() {
      const matched = filter();
      list.innerHTML = "";
      highlighted = -1;
      const CAP = 100;
      const shown = matched.slice(0, CAP);
      if (grouped) {
        const groups = /* @__PURE__ */ new Map();
        for (const it of shown) {
          const g = it.group || "";
          if (!groups.has(g)) groups.set(g, []);
          groups.get(g).push(it);
        }
        for (const [g, rows] of groups) {
          if (g) list.appendChild(el("div", { class: "qb-combo-group", text: g }));
          for (const it of rows) list.appendChild(itemEl(it));
        }
      } else {
        for (const it of shown) list.appendChild(itemEl(it));
      }
      if (!matched.length) list.appendChild(el("div", { class: "qb-combo-empty", text: "No matches" }));
      else if (matched.length > CAP) list.appendChild(el("div", { class: "qb-combo-more", text: `+${matched.length - CAP} more \u2014 keep typing to narrow` }));
    }
    function select(it) {
      input.value = it.label;
      hide();
      if (onSelect) onSelect(it);
    }
    const open = () => {
      wrap.classList.add("qb-combo-open");
      render();
    };
    const hide = () => wrap.classList.remove("qb-combo-open");
    function highlight(nodes, idx) {
      nodes.forEach((n, i) => n.classList.toggle("qb-combo-hl", i === idx));
      if (nodes[idx]) nodes[idx].scrollIntoView({ block: "nearest" });
    }
    input.addEventListener("focus", open);
    input.addEventListener("input", () => wrap.classList.contains("qb-combo-open") ? render() : open());
    input.addEventListener("keydown", (e) => {
      const nodes = [...list.querySelectorAll(".qb-combo-item")];
      if (e.key === "ArrowDown") {
        e.preventDefault();
        highlighted = Math.min(highlighted + 1, nodes.length - 1);
        highlight(nodes, highlighted);
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        highlighted = Math.max(highlighted - 1, 0);
        highlight(nodes, highlighted);
      } else if (e.key === "Enter") {
        e.preventDefault();
        if (nodes[highlighted]) select(nodes[highlighted].__item);
      } else if (e.key === "Escape") {
        hide();
      }
    });
    input.addEventListener("blur", () => setTimeout(hide, 150));
    return { el: wrap, input, getValue: () => input.value, focus: () => input.focus(), open };
  }

  // src/view/modal.js
  function shortName(fqcn) {
    if (!fqcn) return "";
    const parts = String(fqcn).split("\\");
    return parts[parts.length - 1];
  }
  function el2(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === "class") node.className = v;
      else if (k === "text") node.textContent = v;
      else if (k.startsWith("data-") || k === "list") node.setAttribute(k, v);
      else node[k] = v;
    }
    for (const child of children) node.appendChild(child);
    return node;
  }
  function field(labelText, control) {
    return el2("label", { class: "qb-field" }, [el2("span", { class: "qb-field-label", text: labelText }), control]);
  }
  function selectControl(value, options, cls = "qb-modal-select") {
    return el2("select", { class: cls }, options.map((o) => {
      const label = typeof o === "string" ? o : o.label;
      const val = typeof o === "string" ? o : o.value;
      return el2("option", { value: val, text: label, selected: val === value });
    }));
  }
  function operatorsForType(type) {
    const NULLS = ["is_null", "is_not_null"];
    if (type === "number") return ["=", "!=", ">", "<", ">=", "<=", "between", "in", "not_in", ...NULLS];
    if (type === "date") return ["=", "!=", ">", "<", ">=", "<=", "between", ...NULLS];
    if (type === "enum" || type === "boolean") return ["=", "!=", "in", "not_in", ...NULLS];
    return ["=", "!=", "like", "in", "not_in", ...NULLS, "is_empty", "is_not_empty"];
  }
  function valueArity(op) {
    if (op === "is_null" || op === "is_not_null" || op === "is_empty" || op === "is_not_empty") return "none";
    if (op === "in" || op === "not_in") return "multi";
    if (op === "between") return "range";
    return "single";
  }
  var isUnary = (op) => valueArity(op) === "none";
  function typedInput(info, value, onInput) {
    let node;
    if (info.options && info.options.length) node = selectControl(value, info.options, "qb-modal-input");
    else if (info.type === "number") node = el2("input", { type: "number", class: "qb-modal-input", value: value ?? "" });
    else if (info.type === "date") node = el2("input", { type: "date", class: "qb-modal-input", value: value ?? "" });
    else if (info.type === "boolean") node = selectControl(value || "true", ["true", "false"], "qb-modal-input");
    else node = el2("input", { type: "text", class: "qb-modal-input", value: value ?? "" });
    if (onInput) {
      node.addEventListener("input", onInput);
      node.addEventListener("change", onInput);
    }
    return node;
  }
  function buildValueControl(op, info, currentValue, onInput) {
    const arity = valueArity(op);
    if (arity === "none") return null;
    if (arity === "single") {
      const input = typedInput(info, currentValue, onInput);
      return { control: input, getValue: () => input.value, isFilled: () => String(input.value).trim() !== "" };
    }
    if (arity === "range") {
      const arr = Array.isArray(currentValue) ? currentValue : [];
      const from = typedInput(info, arr[0] ?? "", onInput);
      const to = typedInput(info, arr[1] ?? "", onInput);
      const control2 = el2("div", { class: "qb-range" }, [from, el2("span", { class: "qb-range-sep", text: "and" }), to]);
      return { control: control2, getValue: () => [from.value, to.value], isFilled: () => String(from.value).trim() !== "" && String(to.value).trim() !== "" };
    }
    const seed = Array.isArray(currentValue) ? currentValue : currentValue != null && currentValue !== "" ? [currentValue] : [];
    const inputs = [];
    const list = el2("div", { class: "qb-multi" });
    const addRow = (val) => {
      const input = typedInput(info, val ?? "", onInput);
      const x = el2("button", { type: "button", class: "qb-multi-x", text: "\xD7" });
      const row = el2("div", { class: "qb-multi-row" }, [input, x]);
      x.addEventListener("click", () => {
        const i = inputs.indexOf(input);
        if (i >= 0) inputs.splice(i, 1);
        list.removeChild(row);
        if (onInput) onInput();
      });
      inputs.push(input);
      list.appendChild(row);
      if (onInput) onInput();
    };
    (seed.length ? seed : [""]).forEach(addRow);
    const addBtn = el2("button", { type: "button", class: "qb-multi-add", text: "+ value" });
    addBtn.addEventListener("click", () => addRow(""));
    const control = el2("div", { class: "qb-multi-wrap" }, [list, addBtn]);
    return { control, getValue: () => inputs.map((i) => i.value).filter((v) => String(v).trim() !== ""), isFilled: () => inputs.some((i) => String(i.value).trim() !== "") };
  }
  function openEditor(node, { onSave, onCancel, columns = [], relationships = [], relatedOptions = [], aggregateOptions = [], computedValues = [] } = {}) {
    const isWhereHas = node.type === "whereHas";
    const controls = {};
    const rows = [];
    let valueControl;
    let refreshValidity = () => {
    };
    if (isWhereHas) {
      controls.hasType = selectControl(node.hasType || "has", [
        { value: "has", label: "has" },
        { value: "doesntHave", label: "doesn't have" }
      ]);
      controls.relationship = createCombobox({
        items: relationships.map((r) => ({ label: r.name, sublabel: r.target ? shortName(r.target) : "", value: r.name })),
        placeholder: "relationship",
        initialValue: node.relationship ?? ""
      });
      controls.relationship.input.addEventListener("input", () => refreshValidity());
      const COUNT_OPS = [
        { value: "", label: "at least one" },
        { value: ">=", label: "at least" },
        { value: ">", label: "more than" },
        { value: "=", label: "exactly" },
        { value: "<=", label: "at most" },
        { value: "<", label: "fewer than" }
      ];
      controls.countOp = selectControl(node.countOperator || "", COUNT_OPS);
      controls.countNum = el2("input", { type: "number", class: "qb-modal-input", min: "0", value: node.countValue ?? "" });
      const countField = el2("div", {});
      const renderCount = () => {
        countField.innerHTML = "";
        if (controls.hasType.value === "doesntHave") return;
        const row = el2("div", { class: "qb-count-row" }, [controls.countOp, ...controls.countOp.value ? [controls.countNum] : []]);
        countField.appendChild(field("How many", row));
      };
      controls.countOp.addEventListener("change", () => {
        renderCount();
        refreshValidity();
      });
      controls.hasType.addEventListener("change", () => {
        renderCount();
        refreshValidity();
      });
      controls.countNum.addEventListener("input", () => refreshValidity());
      renderCount();
      rows.push(field("Match", controls.hasType), field("Relationship", controls.relationship.el), countField);
    } else {
      const operatorField = el2("div", {});
      const valueField = el2("div", {});
      let curInfo = columnTypeInfo(columns, node.column || "");
      let valueMode = node.valueType === "reference" ? "column" : node.valueType === "relatedColumn" ? "related" : node.valueType === "aggregate" ? "aggregate" : "value";
      let cvRef = node.valueType === "computedValue" ? node.referenceComputedValue || null : null;
      const cvAffordance = (op, slotKind) => {
        const fit = computedValues.filter((c) => c.kind === slotKind);
        if (!fit.length) return null;
        const picker = createCombobox({
          items: fit.map((c) => ({ label: c.label || c.name, sublabel: c.kind, name: c.name })),
          placeholder: "computed value\u2026",
          onSelect: (it) => {
            cvRef = it.name;
            renderValue(op, "");
          }
        });
        const drop = el2("div", { class: "qb-cv-picker", style: "display:none" }, [picker.el]);
        const fx = el2("button", { type: "button", class: "qb-cv-btn", title: "Insert a computed value", text: "\u0192" });
        fx.addEventListener("click", () => {
          const open = drop.style.display === "none";
          drop.style.display = open ? "block" : "none";
          if (open) picker.focus();
        });
        return el2("div", {}, [fx, drop]);
      };
      const renderValue = (op, currentValue) => {
        valueField.innerHTML = "";
        const arity = valueArity(op);
        if (arity === "none") {
          valueControl = null;
          refreshValidity();
          return;
        }
        if (cvRef) {
          const cv = computedValues.find((c) => c.name === cvRef);
          const x = el2("button", { type: "button", class: "qb-cv-x", text: "\xD7" });
          x.addEventListener("click", () => {
            cvRef = null;
            renderValue(op, "");
          });
          valueControl = { isComputedValue: true, getValue: () => cvRef, isFilled: () => true };
          valueField.appendChild(field("Value", el2("div", { class: "qb-cv-chip" }, [el2("span", { text: "\u0192 " + (cv ? cv.label || cv.name : cvRef) }), x])));
          refreshValidity();
          return;
        }
        const modeBtn = (label, mode) => {
          const b = el2("button", { type: "button", class: "qb-valmode-btn" + (valueMode === mode ? " qb-valmode-on" : ""), text: label });
          b.addEventListener("click", () => {
            if (valueMode !== mode) {
              valueMode = mode;
              renderValue(op, "");
            }
          });
          return b;
        };
        if (arity === "single") {
          const modes = [modeBtn("a value", "value"), modeBtn("another column", "column")];
          if (relatedOptions.length) modes.push(modeBtn("another table", "related"));
          if (aggregateOptions.length) modes.push(modeBtn("an aggregate", "aggregate"));
          valueField.appendChild(el2("div", { class: "qb-valmode" }, modes));
        } else {
          valueMode = "value";
        }
        if (valueMode === "column") {
          const refCombo = createCombobox({
            items: columns.map((c) => ({ label: c.name, sublabel: c.type || "", value: c.name })),
            placeholder: "column",
            initialValue: node.referenceColumn ?? ""
          });
          refCombo.input.addEventListener("input", () => refreshValidity());
          valueControl = { isReference: true, getValue: () => refCombo.getValue(), isFilled: () => String(refCombo.getValue() || "").trim() !== "" };
          valueField.appendChild(field("Compare to", el2("div", { class: "qb-value-wrap" }, [refCombo.el])));
        } else if (valueMode === "related") {
          let chosen = node.valueType === "relatedColumn" && node.referenceRelationship ? { relationship: node.referenceRelationship, column: node.referenceColumn } : null;
          const relCombo = createCombobox({
            items: relatedOptions.map((o) => ({ label: o.label, sublabel: o.type || "", relationship: o.relationship, column: o.column })),
            placeholder: "related table \u2192 column",
            initialValue: chosen ? chosen.relationship + " \u2192 " + chosen.column : "",
            onSelect: (it) => {
              chosen = { relationship: it.relationship, column: it.column };
              refreshValidity();
            }
          });
          valueControl = { isRelated: true, getValue: () => chosen, isFilled: () => chosen != null };
          valueField.appendChild(field("Compare to", el2("div", { class: "qb-value-wrap" }, [relCombo.el])));
        } else if (valueMode === "aggregate") {
          let chosen = node.valueType === "aggregate" && node.referenceRelationship ? { relationship: node.referenceRelationship, column: node.referenceColumn } : null;
          const fnSel = selectControl(node.aggregateFunction || "avg", ["avg", "sum", "min", "max", "count"]);
          fnSel.addEventListener("change", () => refreshValidity());
          const aggCombo = createCombobox({
            items: aggregateOptions.map((o) => ({ label: o.label, sublabel: o.type || "", relationship: o.relationship, column: o.column })),
            placeholder: "relation \u2192 column",
            initialValue: chosen ? chosen.relationship + " \u2192 " + chosen.column : "",
            onSelect: (it) => {
              chosen = { relationship: it.relationship, column: it.column };
              refreshValidity();
            }
          });
          valueControl = {
            isAggregate: true,
            getValue: () => chosen ? { function: fnSel.value, relationship: chosen.relationship, column: chosen.column } : null,
            isFilled: () => chosen != null
          };
          valueField.appendChild(field("Aggregate", fnSel));
          valueField.appendChild(field("Of", el2("div", { class: "qb-value-wrap" }, [aggCombo.el])));
        } else {
          valueControl = buildValueControl(op, curInfo, currentValue, () => refreshValidity());
          if (valueControl) valueField.appendChild(field("Value", el2("div", { class: "qb-value-wrap" }, [valueControl.control])));
        }
        if (valueMode === "value") {
          const aff = cvAffordance(op, arity === "multi" ? "list" : "scalar");
          if (aff) valueField.appendChild(aff);
        }
        refreshValidity();
      };
      const renderOperator = (info, currentOp, currentValue) => {
        operatorField.innerHTML = "";
        curInfo = info;
        const ops = operatorsForType(info.type);
        const op = currentOp && ops.includes(currentOp) ? currentOp : ops[0];
        controls.operator = selectControl(op, ops);
        controls.operator.addEventListener("change", () => renderValue(controls.operator.value, ""));
        operatorField.appendChild(field("Operator", controls.operator));
        renderValue(op, currentValue);
      };
      controls.column = createCombobox({
        items: columns.map((c) => ({ label: c.name, sublabel: c.type || "", value: c.name, col: c })),
        placeholder: "column",
        initialValue: node.column ?? "",
        onSelect: (it) => renderOperator(it.col, null, "")
      });
      controls.column.input.addEventListener("change", () => renderOperator(columnTypeInfo(columns, controls.column.getValue()), null, ""));
      controls.column.input.addEventListener("input", () => refreshValidity());
      rows.push(field("Column", controls.column.el), operatorField, valueField);
      if (node.column) renderOperator(columnTypeInfo(columns, node.column), node.operator, node.value);
    }
    const saveBtn = el2("button", { type: "button", class: "qb-modal-save", text: "Save" });
    const cancelBtn = el2("button", { type: "button", class: "qb-modal-cancel", text: "Cancel" });
    const panel = el2("div", { class: "qb-modal" }, [
      el2("div", { class: "qb-modal-title", text: isWhereHas ? "Edit relationship" : "Edit condition" }),
      ...rows,
      el2("div", { class: "qb-modal-actions" }, [cancelBtn, saveBtn])
    ]);
    const overlay = el2("div", { class: "qb-modal-overlay" }, [panel]);
    const close = () => overlay.remove();
    const isValid = () => {
      if (isWhereHas) {
        if (!String(controls.relationship.getValue() || "").trim()) return false;
        if (controls.hasType.value === "has" && controls.countOp.value && String(controls.countNum.value).trim() === "") return false;
        return true;
      }
      if (!String(controls.column.getValue() || "").trim()) return false;
      if (!controls.operator) return false;
      if (isUnary(controls.operator.value)) return true;
      return valueControl != null && valueControl.isFilled();
    };
    refreshValidity = () => {
      saveBtn.disabled = !isValid();
    };
    saveBtn.addEventListener("click", () => {
      if (!isValid()) return;
      const useCount = controls.hasType && controls.hasType.value === "has" && controls.countOp && controls.countOp.value;
      const values = isWhereHas ? {
        hasType: controls.hasType.value,
        relationship: controls.relationship.getValue(),
        countOperator: useCount ? controls.countOp.value : null,
        countValue: useCount ? Number(controls.countNum.value) : null
      } : (() => {
        const vc = valueControl;
        const isRel = vc != null && vc.isRelated === true;
        const isRef = vc != null && vc.isReference === true;
        const isAgg = vc != null && vc.isAggregate === true;
        const isCV = vc != null && vc.isComputedValue === true;
        const base = {
          column: controls.column.getValue(),
          operator: controls.operator ? controls.operator.value : "=",
          referenceColumn: null,
          referenceRelationship: null,
          aggregateFunction: null,
          referenceComputedValue: null,
          value: null
        };
        if (isCV) return { ...base, valueType: "computedValue", referenceComputedValue: vc.getValue() };
        if (isAgg) {
          const a = vc.getValue() || {};
          return { ...base, valueType: "aggregate", aggregateFunction: a.function, referenceRelationship: a.relationship, referenceColumn: a.column };
        }
        if (isRel) {
          const r = vc.getValue() || {};
          return { ...base, valueType: "relatedColumn", referenceRelationship: r.relationship, referenceColumn: r.column };
        }
        if (isRef) return { ...base, valueType: "reference", referenceColumn: vc.getValue() };
        return { ...base, valueType: "hardcoded", value: vc ? vc.getValue() : "" };
      })();
      if (onSave) onSave(values);
      close();
    });
    cancelBtn.addEventListener("click", () => {
      if (onCancel) onCancel();
      close();
    });
    document.body.appendChild(overlay);
    refreshValidity();
    return { close, overlay };
  }
  function openSearch(fields, { onSelect, onCancel } = {}) {
    const close = () => overlay.remove();
    const items = fields.map((f) => ({
      label: f.column,
      sublabel: f.type || "",
      group: f.relationshipPath.length ? f.relationshipPath.join(" \u2192 ") : "This record",
      field: f
    }));
    const combo = createCombobox({
      items,
      placeholder: "Search fields across relationships\u2026",
      grouped: true,
      onSelect: (it) => {
        if (onSelect) onSelect(it.field);
        close();
      }
    });
    const cancelBtn = el2("button", { type: "button", class: "qb-modal-cancel", text: "Close" });
    const panel = el2("div", { class: "qb-modal" }, [
      el2("div", { class: "qb-modal-title", text: "Find a field" }),
      combo.el,
      el2("div", { class: "qb-modal-actions" }, [cancelBtn])
    ]);
    const overlay = el2("div", { class: "qb-modal-overlay" }, [panel]);
    cancelBtn.addEventListener("click", () => {
      if (onCancel) onCancel();
      close();
    });
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) close();
    });
    document.body.appendChild(overlay);
    combo.open();
    return { close, overlay };
  }
  function openComputedValueDefinition({ models = [], fetchSchema, mountBuilder, onSave, onCancel } = {}) {
    const close = () => overlay.remove();
    const nameInput = el2("input", { type: "text", class: "qb-modal-input", placeholder: "name (e.g. CA counties)" });
    const builderHost = el2("div", { class: "qb-setbuilder" });
    const kindField = el2("div", {});
    const selectField = el2("div", {});
    const aggSel = selectControl("avg", ["avg", "sum", "min", "max", "count"]);
    let selectedModel = null;
    let schemaColumns = [];
    let builderHandle = null;
    let columnControl = null;
    let kind = "list";
    const renderSelect = () => {
      selectField.innerHTML = "";
      columnControl = createCombobox({
        items: schemaColumns.map((c) => ({ label: c.name, sublabel: c.type || "", value: c.name })),
        placeholder: "column"
      });
      if (kind === "scalar") {
        selectField.appendChild(field("Aggregate", aggSel));
        if (aggSel.value !== "count") selectField.appendChild(field("Of column", el2("div", { class: "qb-value-wrap" }, [columnControl.el])));
      } else {
        selectField.appendChild(field("Take column (gives a list)", el2("div", { class: "qb-value-wrap" }, [columnControl.el])));
      }
    };
    aggSel.addEventListener("change", renderSelect);
    const kindBtn = (label, k) => {
      const b = el2("button", { type: "button", class: "qb-valmode-btn" + (kind === k ? " qb-valmode-on" : ""), text: label });
      b.addEventListener("click", () => {
        if (kind !== k) {
          kind = k;
          renderKind();
        }
      });
      return b;
    };
    const renderKind = () => {
      kindField.innerHTML = "";
      kindField.appendChild(el2("div", { class: "qb-valmode" }, [kindBtn("a list", "list"), kindBtn("a single value", "scalar")]));
      renderSelect();
    };
    renderKind();
    const modelCombo = createCombobox({
      items: models.map((m) => ({ label: m.label, sublabel: "", key: m.key })),
      placeholder: "start from any model\u2026",
      onSelect: async (it) => {
        selectedModel = it.key;
        builderHost.textContent = "Loading schema\u2026";
        const schema = (fetchSchema ? await fetchSchema(it.key) : {}) || {};
        builderHost.innerHTML = "";
        const g = schema.graph;
        schemaColumns = g && g.base && g.models && g.models[g.base] && g.models[g.base].columns || [];
        builderHandle = mountBuilder ? mountBuilder(builderHost, schema) : null;
        renderSelect();
      }
    });
    const saveLibCheck = el2("input", { type: "checkbox", class: "qb-cv-savelib" });
    const visSel = selectControl("private", [{ value: "private", label: "private (just me)" }, { value: "account", label: "shared (my account)" }]);
    const saveBtn = el2("button", { type: "button", class: "qb-modal-save", text: "Save" });
    const cancelBtn = el2("button", { type: "button", class: "qb-modal-cancel", text: "Cancel" });
    saveBtn.addEventListener("click", () => {
      const name = nameInput.value.trim();
      if (!name || !selectedModel) return;
      const useColumn = !(kind === "scalar" && aggSel.value === "count");
      if (onSave) onSave({
        name,
        model: selectedModel,
        kind,
        column: useColumn && columnControl ? columnControl.getValue() || "id" : "id",
        aggregateFunction: kind === "scalar" ? aggSel.value : null,
        conditions: builderHandle ? builderHandle.getTree() : [],
        saveToLibrary: saveLibCheck.checked,
        visibility: visSel.value
      });
      close();
    });
    const panel = el2("div", { class: "qb-modal qb-modal-wide" }, [
      el2("div", { class: "qb-modal-title", text: "Define a Computed Value" }),
      field("Name", nameInput),
      field("Start model", modelCombo.el),
      field("Result is", kindField),
      selectField,
      field("Filter it (optional)", builderHost),
      el2("div", { class: "qb-cv-savelib-row" }, [el2("label", {}, [saveLibCheck, el2("span", { text: " Save to Library" })]), visSel]),
      el2("div", { class: "qb-modal-actions" }, [cancelBtn, saveBtn])
    ]);
    const overlay = el2("div", { class: "qb-modal-overlay" }, [panel]);
    cancelBtn.addEventListener("click", () => {
      if (onCancel) onCancel();
      close();
    });
    document.body.appendChild(overlay);
    return { close, overlay };
  }
  function openPredicateDefinition({ schema, mountBuilder, onSave, onCancel } = {}) {
    const close = () => overlay.remove();
    const nameInput = el2("input", { type: "text", class: "qb-modal-input", placeholder: "name (e.g. Engaged caller)" });
    const visSel = selectControl("private", [{ value: "private", label: "private (just me)" }, { value: "account", label: "shared (my account)" }]);
    const builderHost = el2("div", { class: "qb-setbuilder" });
    const builderHandle = mountBuilder ? mountBuilder(builderHost, schema || {}) : null;
    const saveBtn = el2("button", { type: "button", class: "qb-modal-save", text: "Save predicate" });
    const cancelBtn = el2("button", { type: "button", class: "qb-modal-cancel", text: "Cancel" });
    saveBtn.addEventListener("click", () => {
      const name = nameInput.value.trim();
      if (!name) return;
      if (onSave) onSave({ name, visibility: visSel.value, conditions: builderHandle ? builderHandle.getTree() : [] });
      close();
    });
    const panel = el2("div", { class: "qb-modal qb-modal-wide" }, [
      el2("div", { class: "qb-modal-title", text: "Define a Predicate" }),
      field("Name", nameInput),
      field("Conditions", builderHost),
      el2("div", { class: "qb-cv-savelib-row" }, [el2("span", { text: "Visibility" }), visSel]),
      el2("div", { class: "qb-modal-actions" }, [cancelBtn, saveBtn])
    ]);
    const overlay = el2("div", { class: "qb-modal-overlay" }, [panel]);
    cancelBtn.addEventListener("click", () => {
      if (onCancel) onCancel();
      close();
    });
    document.body.appendChild(overlay);
    return { close, overlay };
  }
  function openPredicatePicker(predicates, { onSelect, onCancel } = {}) {
    const close = () => overlay.remove();
    const combo = createCombobox({
      items: predicates.map((p) => ({ label: p.label || p.name, sublabel: "predicate", name: p.name })),
      placeholder: "choose a predicate\u2026",
      onSelect: (it) => {
        if (onSelect) onSelect(it);
        close();
      }
    });
    const cancelBtn = el2("button", { type: "button", class: "qb-modal-cancel", text: "Close" });
    const panel = el2("div", { class: "qb-modal" }, [
      el2("div", { class: "qb-modal-title", text: "Insert a Predicate" }),
      combo.el,
      el2("div", { class: "qb-modal-actions" }, [cancelBtn])
    ]);
    const overlay = el2("div", { class: "qb-modal-overlay" }, [panel]);
    cancelBtn.addEventListener("click", () => {
      if (onCancel) onCancel();
      close();
    });
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) close();
    });
    document.body.appendChild(overlay);
    combo.open();
    return { close, overlay };
  }
  function openGraduate({ scope, count } = {}, { onSave, onCancel } = {}) {
    const close = () => overlay.remove();
    const nameInput = el2("input", { type: "text", class: "qb-modal-input", placeholder: "name (e.g. Engaged caller)" });
    const visSel = selectControl("private", [{ value: "private", label: "private (just me)" }, { value: "account", label: "shared (my account)" }]);
    const saveBtn = el2("button", { type: "button", class: "qb-modal-save", text: "Graduate to Library" });
    const cancelBtn = el2("button", { type: "button", class: "qb-modal-cancel", text: "Cancel" });
    saveBtn.addEventListener("click", () => {
      const name = nameInput.value.trim();
      if (!name) return;
      if (onSave) onSave({ name, visibility: visSel.value });
      close();
    });
    cancelBtn.addEventListener("click", () => {
      if (onCancel) onCancel();
      close();
    });
    const scopeLabel = scope ? String(scope).split("\\").pop() : "base";
    const panel = el2("div", { class: "qb-modal" }, [
      el2("div", { class: "qb-modal-title", text: "Graduate to Library" }),
      el2("div", { class: "qb-grad-note", text: count + " condition(s) \xB7 scope: " + scopeLabel }),
      field("Name", nameInput),
      el2("div", { class: "qb-cv-savelib-row" }, [el2("span", { text: "Visibility" }), visSel]),
      el2("div", { class: "qb-modal-actions" }, [cancelBtn, saveBtn])
    ]);
    const overlay = el2("div", { class: "qb-modal-overlay" }, [panel]);
    document.body.appendChild(overlay);
    return { close, overlay };
  }
  function openRelationshipSearch(adjacency, fromModel, { onSelect, onCancel } = {}) {
    const close = () => overlay.remove();
    const reachable = searchModelPaths(adjacency, fromModel, "", 1e3);
    const items = reachable.map((r) => ({
      label: r.name,
      // chain with cardinality — a "»" marks a one-to-many hop (where the query fans out).
      sublabel: r.edges.length ? "via " + r.edges.map((e) => e.name + (e.many ? " \xBB" : "")).join(" \u2192 ") : "direct",
      path: r.path
    }));
    const combo = createCombobox({
      items,
      placeholder: "Search for a model (e.g. lead)\u2026",
      onSelect: (it) => {
        if (onSelect) onSelect(it.path);
        close();
      }
    });
    const cancelBtn = el2("button", { type: "button", class: "qb-modal-cancel", text: "Close" });
    const panel = el2("div", { class: "qb-modal" }, [
      el2("div", { class: "qb-modal-title", text: "Find a relationship \u2014 from " + (fromModel ? shortName(fromModel) : "base") }),
      combo.el,
      el2("div", { class: "qb-modal-actions" }, [cancelBtn])
    ]);
    const overlay = el2("div", { class: "qb-modal-overlay" }, [panel]);
    cancelBtn.addEventListener("click", () => {
      if (onCancel) onCancel();
      close();
    });
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) close();
    });
    document.body.appendChild(overlay);
    combo.open();
    return { close, overlay };
  }
  function openNavigator(metadata, { onSelect, onCancel, mode = "field" } = {}) {
    const isRelMode = mode === "relationship";
    let relPath = [];
    const crumb = el2("div", { class: "qb-nav-crumb" });
    const body = el2("div", { class: "qb-nav-body" });
    const close = () => overlay.remove();
    const hasTypeSelect = selectControl("has", [
      { value: "has", label: "has" },
      { value: "doesntHave", label: "doesn't have" }
    ]);
    function render() {
      crumb.innerHTML = "";
      const base = el2("button", { type: "button", class: "qb-crumb", text: shortName(metadata && metadata.base) || "base" });
      base.addEventListener("click", () => {
        relPath = [];
        render();
      });
      crumb.appendChild(base);
      relPath.forEach((rel, i) => {
        crumb.appendChild(el2("span", { class: "qb-crumb-sep", text: " \u203A " }));
        const c = el2("button", { type: "button", class: "qb-crumb", text: rel });
        c.addEventListener("click", () => {
          relPath = relPath.slice(0, i + 1);
          render();
        });
        crumb.appendChild(c);
      });
      const level = levelAt(metadata, relPath);
      body.innerHTML = "";
      for (const rel of level.relationships) {
        const inGraph = !!(rel.target && metadata && metadata.models && metadata.models[rel.target]);
        const modelTag = rel.target ? "  (" + shortName(rel.target) + ")" : "  (varies)";
        const open = el2("button", { type: "button", class: "qb-nav-rel" + (inGraph ? "" : " qb-nav-edge"), text: "\u21B3 " + rel.name + modelTag });
        if (inGraph) open.addEventListener("click", () => {
          relPath = [...relPath, rel.name];
          render();
        });
        else open.disabled = true;
        if (isRelMode) {
          const pick = el2("button", { type: "button", class: "qb-nav-pick", text: "Select" });
          pick.addEventListener("click", () => {
            if (onSelect) onSelect({ relationshipPath: [...relPath, rel.name], hasType: hasTypeSelect.value });
            close();
          });
          body.appendChild(el2("div", { class: "qb-nav-row" }, [open, pick]));
        } else {
          body.appendChild(open);
        }
      }
      if (!isRelMode) {
        for (const col of level.columns) {
          const item = el2("button", { type: "button", class: "qb-nav-col", text: col.name });
          item.addEventListener("click", () => {
            if (onSelect) onSelect({ relationshipPath: [...relPath], column: col.name, type: col.type, options: col.options || null });
            close();
          });
          body.appendChild(item);
        }
      }
    }
    const cancelBtn = el2("button", { type: "button", class: "qb-modal-cancel", text: "Close" });
    const titleRow = isRelMode ? el2("div", { class: "qb-modal-title" }, [el2("span", { text: "Pick a relationship \u2014 " }), hasTypeSelect]) : el2("div", { class: "qb-modal-title", text: "Browse schema" });
    const panel = el2("div", { class: "qb-modal" }, [
      titleRow,
      crumb,
      body,
      el2("div", { class: "qb-modal-actions" }, [cancelBtn])
    ]);
    const overlay = el2("div", { class: "qb-modal-overlay" }, [panel]);
    cancelBtn.addEventListener("click", () => {
      if (onCancel) onCancel();
      close();
    });
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) close();
    });
    document.body.appendChild(overlay);
    render();
    return { close, overlay };
  }
  function openActionMenu(options, { onPick, onCancel } = {}) {
    const close = () => overlay.remove();
    const list = el2("div", { class: "qb-addmenu-list" });
    options.forEach((o) => {
      const btn = el2("button", { type: "button", class: "qb-addmenu-item", text: o.label });
      btn.addEventListener("click", () => {
        if (onPick) onPick(o.action);
        close();
      });
      list.appendChild(btn);
    });
    const cancelBtn = el2("button", { type: "button", class: "qb-modal-cancel", text: "Cancel" });
    cancelBtn.addEventListener("click", () => {
      if (onCancel) onCancel();
      close();
    });
    const panel = el2("div", { class: "qb-modal" }, [
      el2("div", { class: "qb-modal-title", text: "Add to filter" }),
      list,
      el2("div", { class: "qb-modal-actions" }, [cancelBtn])
    ]);
    const overlay = el2("div", { class: "qb-modal-overlay" }, [panel]);
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) close();
    });
    document.body.appendChild(overlay);
    return { close, overlay };
  }

  // src/view/chart-builder.js
  function el3(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === "class") node.className = v;
      else if (k === "text") node.textContent = v;
      else node[k] = v;
    }
    for (const child of children) node.appendChild(child);
    return node;
  }
  function chartForm({
    catalog = [],
    metadata = null,
    mountFilter = null,
    initialType = null,
    initialValues = {},
    saveLabel = "Add chart",
    onSave = () => {
    },
    onCancel = () => {
    }
  } = {}) {
    const raw = {};
    let currentType = initialType ? catalog.find((c) => c.type === initialType) || null : null;
    const knobsHost = el3("div", { class: "qb-chart-knobs" });
    function renderKnobs() {
      knobsHost.innerHTML = "";
      if (!currentType) return;
      (currentType.inputs || []).forEach((input) => knobsHost.appendChild(renderKnob(input)));
    }
    function renderKnob(input) {
      const row = el3("div", { class: "qb-knob" }, [el3("label", { class: "qb-knob-label", text: input.label })]);
      const seed = initialValues[input.key];
      if (input.type === "schemaField") {
        const label = seed && seed.column ? (seed.relationship ? seed.relationship + "." : "") + seed.column : "(pick a column)";
        const chip = el3("span", { class: "qb-knob-value", text: label });
        if (seed && seed.column) raw[input.key] = { relationship: seed.relationship || null, column: seed.column };
        const pick = el3("button", { type: "button", class: "qb-knob-pick", text: "Pick column" });
        pick.addEventListener("click", () => {
          openNavigator(metadata, {
            mode: "field",
            onSelect: (field2) => {
              const path = field2.relationshipPath || [];
              const relationship = path.length ? path[path.length - 1] : null;
              raw[input.key] = { relationship, column: field2.column };
              chip.textContent = (relationship ? relationship + "." : "") + field2.column;
            }
          });
        });
        row.appendChild(chip);
        row.appendChild(pick);
      } else if (input.type === "filter") {
        const container = el3("div", { class: "qb-knob-filter" });
        const initialTree = Array.isArray(seed) ? seed : [];
        if (initialTree.length) raw[input.key] = initialTree;
        if (mountFilter) {
          mountFilter(container, { initialTree, onChange: (tree) => {
            raw[input.key] = tree;
          } });
        }
        row.appendChild(container);
      } else if (input.type === "select") {
        const sel = el3("select", { class: "qb-knob-select" });
        Object.entries(input.options || {}).forEach(([val, label]) => sel.appendChild(el3("option", { value: val, text: label })));
        if (seed != null) sel.value = seed;
        sel.addEventListener("change", () => {
          raw[input.key] = sel.value;
        });
        raw[input.key] = sel.value;
        row.appendChild(sel);
      } else {
        const initial = seed != null ? seed : input.default;
        const inp = el3("input", { type: "text", class: "qb-knob-text", value: initial != null ? String(initial) : "" });
        inp.addEventListener("input", () => {
          raw[input.key] = inp.value;
        });
        if (initial != null) raw[input.key] = initial;
        row.appendChild(inp);
      }
      return row;
    }
    let typeControl;
    let openTypePicker = null;
    if (initialType && currentType) {
      typeControl = el3("div", { class: "qb-chart-type-fixed", text: currentType.label });
    } else {
      const typeCombo = createCombobox({
        items: catalog.map((c) => ({ label: c.label, name: c.type })),
        placeholder: "choose a chart type\u2026",
        onSelect: (it) => {
          currentType = catalog.find((c) => c.type === it.name) || null;
          Object.keys(raw).forEach((k) => delete raw[k]);
          renderKnobs();
        }
      });
      typeControl = typeCombo.el;
      openTypePicker = typeCombo.open || null;
    }
    const saveBtn = el3("button", { type: "button", class: "qb-modal-save", text: saveLabel });
    saveBtn.addEventListener("click", () => {
      if (currentType) onSave(currentType.type, raw);
    });
    const cancelBtn = el3("button", { type: "button", class: "qb-modal-cancel", text: "Cancel" });
    cancelBtn.addEventListener("click", () => onCancel());
    const root = el3("div", { class: "qb-chart-form" }, [
      el3("div", { class: "qb-modal-title", text: initialType ? "Edit chart" : "Add a chart" }),
      typeControl,
      knobsHost,
      el3("div", { class: "qb-modal-actions" }, [cancelBtn, saveBtn])
    ]);
    renderKnobs();
    return { el: root, openTypePicker };
  }
  function initChartBuilder(host, { catalog = [], metadata = null, mountFilter = null, loadCharts = null, onSave = () => {
  }, onUpdate = () => {
  } } = {}) {
    const toggle = el3("button", { type: "button", class: "qb-chart-toggle", text: "\u270E Edit" });
    const section = el3("div", { class: "qb-chart-section" }, [
      el3("div", { class: "qb-chart-section-head" }, [
        el3("div", { class: "qb-chart-section-label", text: "\u{1F4CA} Charts" }),
        toggle
      ])
    ]);
    const listHost = el3("div", { class: "qb-chart-list" });
    const addBtn = el3("button", { type: "button", class: "qb-add-chart", text: "+ Add Chart" });
    addBtn.addEventListener("click", openAdd);
    const body = el3("div", { class: "qb-chart-body qb-collapsed" }, [listHost, addBtn]);
    toggle.addEventListener("click", () => body.classList.toggle("qb-collapsed"));
    section.appendChild(body);
    host.appendChild(section);
    async function renderList() {
      if (!loadCharts) return;
      const charts = await loadCharts() || [];
      listHost.innerHTML = "";
      charts.forEach((chart) => {
        const editBtn = el3("button", { type: "button", class: "qb-chart-edit", title: "Edit chart", text: "\u270E Edit" });
        editBtn.addEventListener("click", () => openEdit(chart));
        listHost.appendChild(el3("div", { class: "qb-chart-item" }, [
          el3("span", { class: "qb-chart-item-name", text: chart.name || chart.label }),
          editBtn
        ]));
      });
    }
    function openForm(formOptions) {
      const overlay = el3("div", { class: "qb-modal-overlay" });
      const close = () => overlay.remove();
      const form = chartForm({
        catalog,
        metadata,
        mountFilter,
        ...formOptions(close)
      });
      const panel = el3("div", { class: "qb-modal qb-chart-builder-modal" }, [form.el]);
      overlay.appendChild(panel);
      overlay.addEventListener("click", (e) => {
        if (e.target === overlay) close();
      });
      document.body.appendChild(overlay);
      return form;
    }
    function openAdd() {
      const form = openForm((close) => ({
        saveLabel: "Add chart",
        onSave: async (type, raw) => {
          await onSave(type, raw);
          close();
          renderList();
        },
        onCancel: close
      }));
      if (form.openTypePicker) form.openTypePicker();
    }
    function openEdit(chart) {
      openForm((close) => ({
        initialType: chart.type,
        initialValues: chart.raw || {},
        saveLabel: "Save chart",
        onSave: async (type, raw) => {
          await onUpdate(chart.index, raw);
          close();
          renderList();
        },
        onCancel: close
      }));
    }
    renderList();
    return { open: openAdd, refresh: renderList };
  }

  // src/view/render.js
  function el4(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === "class") node.className = v;
      else if (k === "text") node.textContent = v;
      else if (k.startsWith("data-")) node.setAttribute(k, v);
      else node[k] = v;
    }
    for (const child of children) node.appendChild(child);
    return node;
  }
  function actionBtn(label, action, path, cls = "qb-btn") {
    return el4("button", { type: "button", class: cls, text: label, "data-action": action, "data-path": path ?? "" });
  }
  var OP_LABEL = {
    "=": "is",
    "!=": "is not",
    ">": ">",
    "<": "<",
    ">=": "\u2265",
    "<=": "\u2264",
    like: "contains",
    in: "is any of",
    not_in: "is none of",
    is_null: "is null",
    is_not_null: "is not null",
    is_empty: "is empty",
    is_not_empty: "is not empty",
    between: "between"
  };
  var UNARY_OPS = ["is_null", "is_not_null", "is_empty", "is_not_empty"];
  function conditionText(node) {
    if (!node.column) return "(tap to configure)";
    const op = OP_LABEL[node.operator] || node.operator;
    if (UNARY_OPS.includes(node.operator)) return `${node.column} ${op}`;
    if (node.valueType === "computedValue" && node.referenceComputedValue) return `${node.column} ${op} \u0192{${node.referenceComputedValue}}`;
    if (node.valueType === "aggregate" && node.referenceRelationship) {
      const agg = node.aggregateFunction === "count" ? `count(${node.referenceRelationship})` : `${node.aggregateFunction}(${node.referenceRelationship} \u2192 ${node.referenceColumn})`;
      return `${node.column} ${op} ${agg}`;
    }
    if (node.valueType === "relatedColumn" && node.referenceRelationship) return `${node.column} ${op} ${node.referenceRelationship} \u2192 ${node.referenceColumn}`;
    if (node.valueType === "reference" && node.referenceColumn) return `${node.column} ${op} ${node.referenceColumn}`;
    const val = Array.isArray(node.value) ? node.operator === "between" ? node.value.join(" and ") : node.value.join(", ") : node.value ?? "";
    return `${node.column} ${op} ${val}`.trim();
  }
  function renderCondition(node, path) {
    return el4("div", { class: "qb-row", "data-path": path }, [
      el4("button", { type: "button", class: "qb-chip", text: conditionText(node), "data-action": "edit", "data-path": path }),
      actionBtn("\xD7", "remove", path, "qb-x")
    ]);
  }
  function renderGroup(node, path, flags) {
    const mode = node.negate ? "none" : node.children?.[0]?.boolean === "or" ? "any" : "all";
    const matchLabel = mode === "none" ? "Match NONE of" : mode === "any" ? "Match ANY of" : "Match ALL of";
    const header = el4("div", { class: "qb-group-head", "data-path": path }, [
      el4("button", {
        type: "button",
        class: "qb-match" + (mode === "none" ? " qb-match-none" : ""),
        "data-action": "toggle-connector",
        "data-path": path,
        text: matchLabel
      }),
      // graduate this group → a reusable Library item at its scope (only when the host can persist).
      ...flags.graduate === false ? [] : [actionBtn("\u2934 graduate", "graduate", path, "qb-grad")],
      actionBtn("\xD7", "remove", path, "qb-x")
    ]);
    const children = (node.children || []).map((c, i) => renderNode(c, path + "-" + i, flags));
    const add = el4("div", { class: "qb-add" }, [actionBtn("+ Add", "open-add", path)]);
    return el4("div", { class: "qb-group", "data-path": path }, [header, ...children, add]);
  }
  var COUNT_LABEL = { ">=": "at least", ">": "more than", "=": "exactly", "<=": "at most", "<": "fewer than" };
  function countPhrase(node) {
    if (node.hasType === "doesntHave" || !node.countOperator || node.countValue == null) return "";
    return `${COUNT_LABEL[node.countOperator] || node.countOperator} ${node.countValue} `;
  }
  function renderWhereHas(node, path, flags) {
    const verb = node.hasType === "doesntHave" ? "doesn't have" : "has";
    const header = el4("div", { class: "qb-wherehas-head", "data-path": path }, [
      el4("button", {
        type: "button",
        class: "qb-rel",
        "data-action": "edit",
        "data-path": path,
        text: `${verb} ${countPhrase(node)}${node.relationship || "(relationship)"} where`
      }),
      actionBtn("\xD7", "remove", path, "qb-x")
    ]);
    const children = (node.children || []).map((c, i) => renderNode(c, path + "-" + i, flags));
    const add = el4("div", { class: "qb-add" }, [actionBtn("+ Add", "open-add", path)]);
    return el4("div", { class: "qb-wherehas", "data-path": path }, [header, ...children, add]);
  }
  function renderPredicateRef(node, path) {
    return el4("div", { class: "qb-row", "data-path": path }, [
      el4("span", { class: "qb-chip qb-pred-chip", text: "\u25A3 " + (node.label || node.ref) }),
      actionBtn("\xD7", "remove", path, "qb-x")
    ]);
  }
  function renderNode(node, path, flags) {
    if (node.type === "group") return renderGroup(node, path, flags);
    if (node.type === "whereHas") return renderWhereHas(node, path, flags);
    if (node.type === "predicateRef") return renderPredicateRef(node, path);
    return renderCondition(node, path);
  }
  function renderTree(tree, flags = {}) {
    const nodes = (tree.conditions || []).map((n, i) => renderNode(n, String(i), flags));
    const top = el4("div", { class: "qb-top" }, [
      el4("span", { class: "qb-match-static", text: "Match ALL of" }),
      ...flags.search === false ? [] : [actionBtn("\u{1F50D} Find a field", "search", "", "qb-find")],
      ...flags.navigate === false ? [] : [actionBtn("\u{1F9ED} Browse", "navigate", "", "qb-find")],
      ...flags.defineSet === false ? [] : [actionBtn("\u{1F5C2} Computed Value", "define-computed-value", "", "qb-find")],
      ...flags.definePredicate === false ? [] : [actionBtn("\u25A3 Predicate", "define-predicate", "", "qb-find")]
    ]);
    const add = el4("div", { class: "qb-add" }, [actionBtn("+ Add", "open-add", "")]);
    return el4("div", { class: "qb-root" }, [top, ...nodes, add]);
  }

  // src/core/legacy.js
  function unflattenConditions(conditions, conditionGroups, whereHasArr) {
    let result = [];
    const toCondition = (c) => {
      const node = {
        type: "condition",
        column: c.column,
        operator: c.operator || "=",
        value: c.value || "",
        boolean: c.boolean || "and",
        valueType: c.valueType || (c.parameter ? "dynamic" : "hardcoded")
      };
      if (c.referenceColumn) node.referenceColumn = c.referenceColumn;
      return node;
    };
    if (!conditionGroups || conditionGroups.length === 0) {
      result = (conditions || []).map(toCondition);
    } else {
      const groupMap = {};
      conditionGroups.forEach((g) => {
        groupMap[g.id] = { type: "group", boolean: g.boolean || "and", parentGroupId: g.parentGroupId || null, children: [] };
      });
      (conditions || []).forEach((c) => {
        if (c.groupId && groupMap[c.groupId]) {
          groupMap[c.groupId].children.push(toCondition(c));
        }
      });
      conditionGroups.forEach((g) => {
        if (g.parentGroupId && groupMap[g.parentGroupId]) {
          groupMap[g.parentGroupId].children.push(groupMap[g.id]);
        }
      });
      (conditions || []).forEach((c) => {
        if (!c.groupId) result.push(toCondition(c));
      });
      conditionGroups.forEach((g) => {
        if (!g.parentGroupId) result.push(groupMap[g.id]);
      });
    }
    (whereHasArr || []).forEach((wh) => {
      result.push({
        type: "whereHas",
        relationship: wh.relationship,
        sourceModel: wh.sourceModel || "",
        sourceSchema: wh.sourceSchema || null,
        relatedSchema: wh.relatedSchema || null,
        boolean: wh.boolean || "and",
        hasType: wh.hasType || "has",
        countOperator: wh.countOperator || null,
        countValue: wh.countValue || null,
        children: (wh.conditions || []).map(toCondition)
      });
    });
    return result;
  }

  // src/core/metadata.js
  function columnsFor(metadata, relationshipName = null) {
    if (!metadata) return [];
    if (relationshipName) {
      const rel = (metadata.relationships || []).find((r) => r.name === relationshipName);
      return rel ? rel.columns || [] : [];
    }
    return metadata.columns || [];
  }
  function relationshipsFor(metadata) {
    return metadata ? metadata.relationships || [] : [];
  }
  function columnMeta(metadata, columnName, relationshipName = null) {
    return columnsFor(metadata, relationshipName).find((c) => c.name === columnName) || null;
  }
  function typeOf(metadata, columnName, relationshipName = null) {
    const c = columnMeta(metadata, columnName, relationshipName);
    return c && c.type ? c.type : "string";
  }
  function optionsFor(metadata, columnName, relationshipName = null) {
    const c = columnMeta(metadata, columnName, relationshipName);
    return c && Array.isArray(c.options) ? c.options : null;
  }

  // src/index.js
  function init(el5, config = {}) {
    const flags = config.flags || {};
    const onChange = config.onChange || (() => {
    });
    const onSave = config.onSave || (() => {
    });
    const onPreview = config.onPreview || (() => {
    });
    const metadata = config.metadata || null;
    const adjacency = config.adjacency || null;
    const userComputedValues = [];
    const computedValuesList = () => [
      ...config.computedValues || [],
      ...userComputedValues.map((c) => ({ name: c.name, label: c.label || c.name, kind: c.kind || "list" }))
    ];
    const userPredicates = [];
    const predicateList = () => [...config.predicates || [], ...userPredicates];
    const relationships = allRelationships(metadata);
    const fields = flattenGraph(metadata, config.maxDepth || 2);
    const tree = new QueryTree(config.initialTree ? structuredClone(config.initialTree) : []);
    const serialize = () => serializeConditionNodes(tree.conditions);
    const emit = () => onChange(serialize());
    const commit = () => {
      render();
      emit();
    };
    const contextColumns = (path) => levelAt(metadata, tree.relationshipContext(path)).columns;
    const contextModelFor = (path) => modelAtPath(adjacency, tree.relationshipContext(path)) || adjacency && adjacency.base;
    const relatedOptionsFor = (path) => relatedColumnOptions(metadata, adjacency, contextModelFor(path));
    const aggregateOptionsFor = (path) => aggregateColumnOptions(metadata, adjacency, contextModelFor(path));
    function editNode(path) {
      const node = tree.getNodeByPath(path);
      if (node) openEditor(node, { columns: contextColumns(path), relationships, relatedOptions: relatedOptionsFor(path), aggregateOptions: aggregateOptionsFor(path), computedValues: computedValuesList(), onSave: (values) => {
        Object.assign(node, values);
        commit();
      } });
    }
    function findField() {
      openSearch(fields, { onSelect: (hit) => {
        tree.addFieldPath("", hit);
        commit();
      } });
    }
    function browseSchema() {
      openNavigator(metadata, { onSelect: (field2) => {
        tree.addFieldPath("", field2);
        commit();
      } });
    }
    function relSearch(parentPath) {
      const ctxModel = modelAtPath(adjacency, tree.relationshipContext(parentPath)) || adjacency && adjacency.base;
      openRelationshipSearch(adjacency, ctxModel, { onSelect: (path) => {
        tree.addWhereHasPath(parentPath, path, "has");
        commit();
      } });
    }
    function addCondition(parentPath) {
      openEditor({ type: "condition" }, {
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
        }
      });
    }
    function addWhereHas(parentPath) {
      openNavigator(metadata, {
        mode: "relationship",
        onSelect: (sel) => {
          tree.addWhereHasPath(parentPath, sel.relationshipPath, sel.hasType);
          commit();
        }
      });
    }
    function defineComputedValue() {
      openComputedValueDefinition({
        models: config.models || [],
        fetchSchema: config.fetchSchema || (async () => ({})),
        mountBuilder: (host, schema) => init(host, {
          metadata: schema && schema.graph || null,
          adjacency: schema && schema.adjacency || null,
          flags: { allowGroups: true, defineSet: false, definePredicate: false, relSearch: false }
        }),
        onSave: async (def) => {
          if (def.saveToLibrary && config.onSaveToLibrary) {
            const res = await config.onSaveToLibrary({
              name: def.name,
              visibility: def.visibility,
              definition: { model: def.model, kind: def.kind, column: def.column, aggregateFunction: def.aggregateFunction, conditions: def.conditions }
            });
            if (res && res.ok) {
              userComputedValues.push({ name: res.slug, label: def.name, model: def.model, kind: res.kind, column: def.column, aggregateFunction: def.aggregateFunction, conditions: def.conditions });
            }
          } else {
            userComputedValues.push({ ...def, label: def.name });
          }
          commit();
        }
      });
    }
    function definePredicate() {
      const scope = contextModelFor("");
      openPredicateDefinition({
        schema: { graph: metadata, adjacency },
        mountBuilder: (host, schema) => init(host, {
          metadata: schema && schema.graph || null,
          adjacency: schema && schema.adjacency || null,
          flags: { allowGroups: true, defineSet: false, definePredicate: false, relSearch: true }
        }),
        onSave: async (def) => {
          if (config.onSaveToLibrary) {
            const res = await config.onSaveToLibrary({ kind: "predicate", name: def.name, visibility: def.visibility, definition: { conditions: def.conditions, scope } });
            if (res && res.ok) userPredicates.push({ name: res.slug, label: def.name, scope });
          }
          commit();
        }
      });
    }
    function graduateNode(path) {
      const node = tree.getNodeByPath(path);
      if (!node) return;
      const conditions = serializeConditionNodes([node]);
      const scope = contextModelFor(path);
      openGraduate({ scope, count: (node.children || []).length || 1 }, {
        onSave: async (info) => {
          if (config.onSaveToLibrary) {
            const res = await config.onSaveToLibrary({ kind: "predicate", name: info.name, visibility: info.visibility, definition: { conditions, scope } });
            if (res && res.ok) userPredicates.push({ name: res.slug, label: info.name, scope });
          }
          commit();
        }
      });
    }
    function insertPredicate(parentPath) {
      const scope = contextModelFor(parentPath);
      const base = adjacency && adjacency.base;
      const inScope = predicateList().filter((p) => (p.scope || base) === scope);
      openPredicatePicker(inScope, { onSelect: (it) => {
        tree.addPredicateRef(parentPath, it.name, it.label);
        commit();
      } });
    }
    function runAction(action, path) {
      switch (action) {
        case "edit":
          editNode(path);
          break;
        case "search":
          findField();
          break;
        case "navigate":
          browseSchema();
          break;
        case "rel-search":
          relSearch(path);
          break;
        case "define-computed-value":
          defineComputedValue();
          break;
        case "define-predicate":
          definePredicate();
          break;
        case "add-predicate":
          insertPredicate(path);
          break;
        case "graduate":
          graduateNode(path);
          break;
        case "add-condition":
          addCondition("");
          break;
        case "add-child-condition":
          addCondition(path);
          break;
        case "add-wherehas":
          addWhereHas("");
          break;
        case "add-child-wherehas":
          addWhereHas(path);
          break;
        case "add-group":
          tree.addGroup();
          commit();
          break;
        case "add-child-group":
          tree.addChildGroup(path);
          commit();
          break;
        case "toggle-connector":
          tree.cycleGroupMode(path);
          commit();
          break;
        case "remove":
          tree.removeNodeByPath(path);
          commit();
          break;
        case "save-query":
          onSave(serialize());
          break;
        case "preview":
          onPreview(serialize());
          break;
        case "open-add":
          openAddMenu(path);
          break;
        default:
          break;
      }
    }
    function openAddMenu(path) {
      const atRoot = path === "";
      const options = [
        { label: "+ Condition", action: atRoot ? "add-condition" : "add-child-condition" },
        { label: "+ Relationship", action: atRoot ? "add-wherehas" : "add-child-wherehas" },
        ...flags.relSearch === false ? [] : [{ label: "\u{1F517} Find related", action: "rel-search" }],
        ...flags.definePredicate === false ? [] : [{ label: "\u25A3 Predicate", action: "add-predicate" }],
        ...flags.allowGroups === false ? [] : [{ label: "+ Group", action: atRoot ? "add-group" : "add-child-group" }]
      ];
      openActionMenu(options, { onPick: (action) => runAction(action, path) });
    }
    function bindEvents() {
      el5.querySelectorAll("[data-action]").forEach((node) => {
        node.addEventListener("click", (event) => {
          event.preventDefault();
          runAction(node.getAttribute("data-action"), node.getAttribute("data-path") || "");
        });
      });
    }
    function render() {
      el5.innerHTML = "";
      el5.appendChild(renderTree(tree, flags));
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
      setComputedValueDefinitions: (defs) => {
        userComputedValues.length = 0;
        (defs || []).forEach((d) => userComputedValues.push(d));
        render();
      },
      getDefinition: (meta = {}) => buildQueryDefinition({ ...meta, conditions: tree.conditions }),
      setTree: (nextTree) => {
        tree.conditions = nextTree ? structuredClone(nextTree) : [];
        render();
        emit();
      },
      destroy: () => {
        el5.innerHTML = "";
      }
    };
  }
  return __toCommonJS(src_exports);
})();
