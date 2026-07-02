// Data Manager — admin-next plugin page.
//
// A three-level browser over Grav's flat-file data store (user/data):
//   types  → a grid of data-type folders with item counts
//   items  → a sortable table of the files inside a type
//   item   → the parsed contents of a single data file (+ raw source)
//
// All data comes from the plugin's own API routes (/data-manager/*). The
// component is fully self-contained (Shadow DOM) and themed with admin-next
// CSS custom properties so it tracks the active admin theme.

const TAG = window.__GRAV_PAGE_TAG;

class DataManagerPage extends HTMLElement {
    // ---- State ----
    _view = 'types';        // 'types' | 'items' | 'item'
    _type = null;           // active type slug
    _typeName = null;       // active type label
    _item = null;           // active item filename
    _loading = true;
    _error = null;

    _types = [];            // [{ type, name, count }]
    _columns = [];          // [{ key, label }]
    _items = [];            // [{ name, file, size, modified, values }]
    _detail = null;         // { name, file, extension, content, raw }

    _sortKey = null;
    _sortDir = 'asc';
    _showRaw = false;

    _config = { can_delete: false };

    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
    }

    connectedCallback() {
        this._fetchConfig().then(() => this._loadTypes());
    }

    // ---- API helpers ----
    get _baseUrl() {
        return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1');
    }

    _headers(json = false) {
        const h = {};
        const token = window.__GRAV_API_TOKEN;
        if (token) h['X-API-Token'] = token;
        if (json) h['Content-Type'] = 'application/json';
        return h;
    }

    async _api(method, path, body) {
        const opts = { method, headers: this._headers(!!body) };
        if (body) opts.body = JSON.stringify(body);
        const res = await fetch(this._baseUrl + path, opts);
        if (!res.ok) {
            let detail = `HTTP ${res.status}`;
            try {
                const j = await res.json();
                detail = j?.error?.detail || j?.message || detail;
            } catch (e) { /* ignore */ }
            throw new Error(detail);
        }
        const json = await res.json();
        return json.data ?? json;
    }

    async _fetchConfig() {
        try {
            this._config = { ...this._config, ...(await this._api('GET', '/data-manager/config')) };
        } catch (e) {
            console.warn('[data-manager] config load failed:', e.message);
        }
    }

    // ---- Data loading ----
    async _loadTypes() {
        this._view = 'types';
        this._type = this._item = this._detail = null;
        this._loading = true;
        this._error = null;
        this._render();
        try {
            const data = await this._api('GET', '/data-manager/types');
            this._types = data.types || [];
        } catch (e) {
            this._error = e.message;
        }
        this._loading = false;
        this._render();
    }

    async _loadItems(type, name) {
        this._view = 'items';
        this._type = type;
        this._typeName = name || type;
        this._item = this._detail = null;
        this._sortKey = null;
        this._loading = true;
        this._error = null;
        this._render();
        try {
            const data = await this._api('GET', `/data-manager/types/${encodeURIComponent(type)}`);
            this._columns = data.columns || [];
            this._items = data.items || [];
            this._typeName = data.name || this._typeName;
        } catch (e) {
            this._error = e.message;
        }
        this._loading = false;
        this._render();
    }

    async _loadItem(file) {
        this._view = 'item';
        this._item = file;
        this._detail = null;
        this._showRaw = false;
        this._loading = true;
        this._error = null;
        this._render();
        try {
            this._detail = await this._api(
                'GET',
                `/data-manager/types/${encodeURIComponent(this._type)}/items/${encodeURIComponent(file)}`,
            );
        } catch (e) {
            this._error = e.message;
        }
        this._loading = false;
        this._render();
    }

    // ---- Actions ----
    async _deleteItem(file) {
        const ok = await window.__GRAV_DIALOGS?.confirm({
            title: 'Delete data item?',
            message: `“${file}” will be permanently removed. This cannot be undone.`,
            confirmLabel: 'Delete',
            variant: 'destructive',
        });
        if (!ok) return;

        try {
            await this._api('DELETE', `/data-manager/types/${encodeURIComponent(this._type)}/items/${encodeURIComponent(file)}`);
            window.__GRAV_TOAST?.success(`Deleted ${file}`);
            if (this._view === 'item') {
                await this._loadItems(this._type, this._typeName);
            } else {
                this._items = this._items.filter((i) => i.file !== file);
                this._render();
            }
        } catch (e) {
            window.__GRAV_TOAST?.error(`Delete failed: ${e.message}`);
        }
    }

    async _exportCsv() {
        try {
            const res = await fetch(
                `${this._baseUrl}/data-manager/types/${encodeURIComponent(this._type)}/export`,
                { headers: this._headers() },
            );
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `${this._type}.csv`;
            a.click();
            URL.revokeObjectURL(url);
        } catch (e) {
            window.__GRAV_TOAST?.error(`Export failed: ${e.message}`);
        }
    }

    _sortBy(key) {
        if (this._sortKey === key) {
            this._sortDir = this._sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            this._sortKey = key;
            this._sortDir = 'asc';
        }
        const dir = this._sortDir === 'asc' ? 1 : -1;
        this._items.sort((a, b) => {
            const av = key === '__name' ? a.name : (a.values?.[key] ?? '');
            const bv = key === '__name' ? b.name : (b.values?.[key] ?? '');
            return String(av).localeCompare(String(bv), undefined, { numeric: true }) * dir;
        });
        this._render();
    }

    // ---- Rendering ----
    _esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }

    _fmtDate(ts) {
        if (!ts) return '';
        try { return new Date(ts * 1000).toLocaleString(); } catch (e) { return ''; }
    }

    _fmtSize(bytes) {
        if (!bytes) return '';
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    }

    _render() {
        this.shadowRoot.innerHTML = `<style>${this._css()}</style>${this._body()}`;
        this._bind();
    }

    _body() {
        if (this._error) return this._errorView();
        if (this._loading) return this._loadingView();
        if (this._view === 'types') return this._typesView();
        if (this._view === 'items') return this._itemsView();
        if (this._view === 'item') return this._itemView();
        return '';
    }

    _loadingView() {
        return `<div class="state"><div class="spinner"></div><p>Loading…</p></div>`;
    }

    _errorView() {
        return `
            <div class="state error">
                <p>Something went wrong</p>
                <p class="detail">${this._esc(this._error)}</p>
                <button class="btn btn-secondary" data-act="retry">Retry</button>
            </div>`;
    }

    _breadcrumb() {
        const crumbs = [`<button class="crumb" data-act="home">Data Types</button>`];
        if (this._view !== 'types') {
            crumbs.push(`<span class="sep">/</span>`);
            crumbs.push(this._view === 'items'
                ? `<span class="crumb current">${this._esc(this._typeName)}</span>`
                : `<button class="crumb" data-act="up">${this._esc(this._typeName)}</button>`);
        }
        if (this._view === 'item') {
            crumbs.push(`<span class="sep">/</span>`);
            crumbs.push(`<span class="crumb current">${this._esc(this._detail?.name || this._item)}</span>`);
        }
        return `<nav class="breadcrumb">${crumbs.join('')}</nav>`;
    }

    _typesView() {
        if (!this._types.length) {
            return `${this._breadcrumb()}
                <div class="state empty">
                    <p>No data types found</p>
                    <p class="detail">The <code>user/data</code> folder has no sub-folders to show.</p>
                </div>`;
        }
        const cards = this._types.map((t) => `
            <button class="card" data-act="open-type" data-type="${this._esc(t.type)}" data-name="${this._esc(t.name)}">
                <span class="card-icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg></span>
                <span class="card-body">
                    <span class="card-title">${this._esc(t.name)}</span>
                    <span class="card-sub">${t.count} item${t.count === 1 ? '' : 's'}</span>
                </span>
            </button>`).join('');
        return `${this._breadcrumb()}<div class="grid">${cards}</div>`;
    }

    _itemsView() {
        const del = this._config.can_delete;
        const header = `
            <div class="toolbar">
                <h2>${this._esc(this._typeName)}</h2>
                <div class="toolbar-actions">
                    <button class="btn btn-secondary" data-act="export"${this._items.length ? '' : ' disabled'}>Download CSV</button>
                </div>
            </div>`;

        if (!this._items.length) {
            return `${this._breadcrumb()}${header}
                <div class="state empty"><p>No items in this data type</p></div>`;
        }

        const arrow = (key) => (this._sortKey === key ? (this._sortDir === 'asc' ? ' ▲' : ' ▼') : '');
        const cols = this._columns.length
            ? this._columns
            : [{ key: '__name', label: 'Name' }];
        const useName = !this._columns.length;

        const thead = `
            <tr>
                ${useName ? '' : `<th class="th-sortable" data-sort="__name"><span>Name${arrow('__name')}</span></th>`}
                ${cols.map((c) => `<th class="th-sortable" data-sort="${this._esc(c.key)}"><span>${this._esc(c.label)}${arrow(c.key)}</span></th>`).join('')}
                <th class="th-actions"></th>
            </tr>`;

        const rows = this._items.map((item) => {
            const cells = cols.map((c) => {
                const v = c.key === '__name' ? item.name : (item.values?.[c.key] ?? '');
                return `<td title="${this._esc(v)}">${this._esc(v)}</td>`;
            }).join('');
            return `
                <tr data-act="open-item" data-file="${this._esc(item.file)}">
                    ${useName ? '' : `<td class="cell-name">${this._esc(item.name)}</td>`}
                    ${cells}
                    <td class="cell-actions">
                        ${del ? `<button class="btn-icon danger" data-act="delete" data-file="${this._esc(item.file)}" title="Delete">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>` : ''}
                    </td>
                </tr>`;
        }).join('');

        return `${this._breadcrumb()}${header}
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>${thead}</thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
            <p class="count">${this._items.length} item${this._items.length === 1 ? '' : 's'}</p>`;
    }

    _itemView() {
        const d = this._detail;
        if (!d) return `${this._breadcrumb()}<div class="state empty"><p>Item not found</p></div>`;

        const del = this._config.can_delete;
        const header = `
            <div class="toolbar">
                <h2>${this._esc(d.name)}<span class="ext">${this._esc(d.file)}</span></h2>
                <div class="toolbar-actions">
                    <button class="btn btn-secondary${this._showRaw ? ' active' : ''}" data-act="toggle-raw">${this._showRaw ? 'Formatted' : 'Raw source'}</button>
                    ${del ? `<button class="btn btn-danger" data-act="delete" data-file="${this._esc(d.file)}">Delete</button>` : ''}
                </div>
            </div>`;

        let content;
        if (this._showRaw) {
            content = `<pre class="raw">${this._esc(d.raw ?? '')}</pre>`;
        } else if (d.content === null || d.content === undefined) {
            content = `<pre class="raw">${this._esc(d.raw ?? '')}</pre>`;
        } else if (typeof d.content !== 'object') {
            content = `<pre class="raw">${this._esc(d.content)}</pre>`;
        } else {
            content = `<div class="detail">${this._renderTree(d.content)}</div>`;
        }

        return `${this._breadcrumb()}${header}${content}`;
    }

    _renderTree(data) {
        const rows = Object.entries(data).map(([key, value]) => {
            const label = this._esc(this._humanize(key));
            if (value !== null && typeof value === 'object') {
                const empty = Object.keys(value).length === 0;
                // Nested objects/arrays stack (label above an indented child
                // block) rather than adding another side-by-side column, so
                // deep structures don't squeeze the value to nothing.
                return `
                    <div class="tree-branch">
                        <div class="branch-key">${label}${empty ? ' <span class="muted">(empty)</span>' : ''}</div>
                        ${empty ? '' : `<div class="branch-children">${this._renderTree(value)}</div>`}
                    </div>`;
            }
            return `
                <div class="tree-leaf">
                    <span class="leaf-key">${label}</span>
                    <span class="leaf-val">${this._esc(this._scalar(value))}</span>
                </div>`;
        }).join('');
        return `<div class="tree">${rows}</div>`;
    }

    _humanize(key) {
        return String(key).replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    }

    _scalar(v) {
        if (v === true) return 'true';
        if (v === false) return 'false';
        if (v === null) return '';
        return String(v);
    }

    // ---- Event binding ----
    _bind() {
        const root = this.shadowRoot;

        root.querySelectorAll('[data-act="home"]').forEach((el) =>
            el.addEventListener('click', () => this._loadTypes()));
        root.querySelectorAll('[data-act="up"]').forEach((el) =>
            el.addEventListener('click', () => this._loadItems(this._type, this._typeName)));
        root.querySelectorAll('[data-act="retry"]').forEach((el) =>
            el.addEventListener('click', () => this._retry()));

        root.querySelectorAll('[data-act="open-type"]').forEach((el) =>
            el.addEventListener('click', () => this._loadItems(el.dataset.type, el.dataset.name)));

        root.querySelectorAll('[data-act="open-item"]').forEach((el) =>
            el.addEventListener('click', (e) => {
                if (e.target.closest('[data-act="delete"]')) return;
                this._loadItem(el.dataset.file);
            }));

        root.querySelectorAll('[data-act="delete"]').forEach((el) =>
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                this._deleteItem(el.dataset.file);
            }));

        root.querySelectorAll('[data-act="export"]').forEach((el) =>
            el.addEventListener('click', () => this._exportCsv()));
        root.querySelectorAll('[data-act="toggle-raw"]').forEach((el) =>
            el.addEventListener('click', () => { this._showRaw = !this._showRaw; this._render(); }));

        root.querySelectorAll('[data-sort]').forEach((el) =>
            el.addEventListener('click', () => this._sortBy(el.dataset.sort)));
    }

    _retry() {
        this._error = null;
        if (this._view === 'item') this._loadItem(this._item);
        else if (this._view === 'items') this._loadItems(this._type, this._typeName);
        else this._loadTypes();
    }

    _css() {
        return `
            :host { display: block; font-family: inherit; color: var(--foreground, #18181b); }
            * { box-sizing: border-box; }

            .breadcrumb { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; font-size: 13px; flex-wrap: wrap; }
            .crumb { background: none; border: none; padding: 0; font: inherit; cursor: pointer; color: var(--primary, #3b82f6); }
            .crumb.current { color: var(--muted-foreground, #71717a); cursor: default; }
            button.crumb:hover { text-decoration: underline; }
            .sep { color: var(--muted-foreground, #a1a1aa); }

            .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
            .toolbar h2 { margin: 0; font-size: 18px; font-weight: 600; display: flex; align-items: baseline; gap: 10px; }
            .toolbar h2 .ext, .toolbar h2 .ext { font-size: 12px; font-weight: 400; color: var(--muted-foreground, #71717a); }
            .toolbar-actions { display: flex; gap: 8px; }

            .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
            .card {
                display: flex; align-items: center; gap: 12px; text-align: left;
                padding: 16px; border: 1px solid var(--border, #e4e4e7); border-radius: 10px;
                background: var(--card, #fff); cursor: pointer; font: inherit; color: inherit;
                transition: border-color .12s, box-shadow .12s;
            }
            .card:hover { border-color: var(--primary, #3b82f6); box-shadow: 0 0 0 1px color-mix(in srgb, var(--primary, #3b82f6) 20%, transparent); }
            .card-icon { display: flex; color: var(--muted-foreground, #71717a); }
            .card-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
            .card-title { font-weight: 600; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .card-sub { font-size: 12px; color: var(--muted-foreground, #71717a); }

            .table-wrapper { border: 1px solid var(--border, #e4e4e7); border-radius: 10px; overflow: hidden; overflow-x: auto; }
            .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
            .data-table thead { background: var(--muted, #f4f4f5); }
            .data-table th {
                padding: 10px 14px; font-size: 12px; font-weight: 600; text-align: left;
                color: var(--muted-foreground, #71717a); text-transform: uppercase; letter-spacing: .03em;
                white-space: nowrap; border-bottom: 1px solid var(--border, #e4e4e7);
            }
            .th-sortable { cursor: pointer; user-select: none; }
            .th-sortable:hover { color: var(--foreground, #18181b); }
            .th-actions { width: 44px; }
            .data-table td {
                padding: 9px 14px; border-bottom: 1px solid var(--border, #f0f0f2);
                max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            }
            .data-table tbody tr { cursor: pointer; }
            .data-table tbody tr:hover { background: var(--accent, #f4f4f5); }
            .data-table tbody tr:last-child td { border-bottom: none; }
            .cell-name { font-weight: 600; }
            .cell-actions { text-align: right; width: 44px; }
            .count { margin: 10px 2px 0; font-size: 12px; color: var(--muted-foreground, #71717a); }

            .detail { border: 1px solid var(--border, #e4e4e7); border-radius: 10px; overflow: hidden; }
            .tree { display: flex; flex-direction: column; min-width: 0; }
            .tree-leaf { display: flex; gap: 16px; padding: 8px 16px; border-bottom: 1px solid var(--border, #f0f0f2); min-width: 0; }
            .tree-branch { padding: 8px 16px; border-bottom: 1px solid var(--border, #f0f0f2); min-width: 0; }
            .tree-leaf:last-child, .tree-branch:last-child { border-bottom: none; }
            .leaf-key, .branch-key { font-weight: 600; font-size: 13px; color: var(--foreground, #18181b); word-break: break-word; }
            .leaf-key { flex: 0 1 200px; min-width: 90px; }
            .leaf-val { flex: 1 1 auto; min-width: 0; font-size: 13px; color: var(--foreground, #27272a); white-space: pre-wrap; word-break: break-word; }
            .branch-key { margin-bottom: 6px; }
            .branch-key .muted { font-weight: 400; color: var(--muted-foreground, #71717a); }
            /* Nested content indents with a rule instead of a new column. */
            .branch-children { margin-inline-start: 12px; padding-inline-start: 12px; border-inline-start: 2px solid var(--border, #e4e4e7); }
            .branch-children .tree-leaf, .branch-children .tree-branch { padding-inline: 8px; }

            pre.raw {
                margin: 0; padding: 16px; border: 1px solid var(--border, #e4e4e7); border-radius: 10px;
                background: var(--muted, #f8f8f9); font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 12.5px; line-height: 1.55; overflow-x: auto; white-space: pre-wrap; word-break: break-word;
                color: var(--foreground, #18181b); direction: ltr;
            }

            .btn { padding: 7px 13px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 500; cursor: pointer; border: 1px solid transparent; }
            .btn:disabled { opacity: .5; cursor: not-allowed; }
            .btn-secondary { background: var(--muted, #f4f4f5); color: var(--foreground, #18181b); border-color: var(--border, #e4e4e7); }
            .btn-secondary:hover:not(:disabled) { background: var(--accent, #e4e4e7); }
            .btn-secondary.active { background: var(--primary, #3b82f6); color: var(--primary-foreground, #fff); border-color: transparent; }
            .btn-danger { background: var(--destructive, #ef4444); color: #fff; }
            .btn-danger:hover { opacity: .9; }
            .btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; padding: 0; border: none; border-radius: 7px; background: transparent; color: var(--muted-foreground, #71717a); cursor: pointer; }
            .btn-icon:hover { background: var(--accent, #f4f4f5); color: var(--foreground, #18181b); }
            .btn-icon.danger:hover { background: color-mix(in srgb, var(--destructive, #ef4444) 12%, transparent); color: var(--destructive, #ef4444); }

            .state { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; padding: 60px 20px; text-align: center; color: var(--muted-foreground, #71717a); }
            .state p { margin: 0; font-size: 14px; }
            .state .detail { font-size: 12.5px; }
            .state.error .detail { color: var(--destructive, #ef4444); }
            .state code { background: var(--muted, #f4f4f5); padding: 1px 5px; border-radius: 4px; font-size: 12px; }
            .spinner { width: 26px; height: 26px; border: 3px solid var(--border, #e4e4e7); border-top-color: var(--primary, #3b82f6); border-radius: 50%; animation: spin .7s linear infinite; }
            @keyframes spin { to { transform: rotate(360deg); } }
        `;
    }
}

customElements.define(TAG, DataManagerPage);
