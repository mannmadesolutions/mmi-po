/**
 * MMI Purchase Orders — admin UI.
 *
 * One file, one entry per tab (orders, edit, suppliers, activity, settings), chosen by mmiPo.tab.
 * The editor keeps its line items in the DOM (the table is the source of truth) and reads them
 * back on save; totals shown here are a preview, the server recomputes them.
 *
 * @package MannMade\PurchaseOrders
 */
(function ($) {
    'use strict';

    const CFG = window.mmiPo || {};
    const esc = window.MMIEscapeHtml;

    /* ── Constants ─────────────────────────────────────────────────────── */

    /** Box-guess heuristic: inches of padding added on each side of the packed contents. */
    const BOX = {
        PADDING_IN: 1,
    };

    const TIMING = {
        SEARCH_DEBOUNCE_MS: 400,
        MESSAGE_HIDE_MS: 6000,
    };

    const STATUS_BADGES = {
        draft: 'warning',
        sent: 'info',
        received: 'success',
        cancelled: 'error',
    };

    const SELECTORS = {
        // Orders / Activity
        ORDERS_TABLE: '#mmi-po-orders',
        ACTIVITY_TABLE: '#mmi-po-activity',
        ROW_DUPLICATE: '.mmi-po-duplicate',
        // Editor
        EDITOR: '#mmi-po-editor',
        NUMBER: '#mmi-po-number',
        NUMBER_INPUT: '#mmi-po-number-input',
        EXPIRY_BADGE: '#mmi-po-expiry-badge',
        STATUS: '#mmi-po-status',
        DIRTY: '#mmi-po-dirty',
        MESSAGE: '#mmi-po-message',
        SAVE: '#mmi-po-save',
        PREVIEW: '#mmi-po-preview',
        DOWNLOAD: '#mmi-po-download',
        EMAIL: '#mmi-po-email',
        STATUS_SELECT: '#mmi-po-status-select',
        DUPLICATE: '#mmi-po-duplicate',
        DELETE: '#mmi-po-delete',
        SUPPLIER: '#mmi-po-supplier',
        SUPPLIER_CARD: '#mmi-po-supplier-card',
        DATE: '#mmi-po-date',
        SHIP_TO: '#mmi-po-ship-to',
        ADDR_INPUT: '#mmi-po-ship-to [data-addr]',
        DEST_RADIO: 'input[name="mmi-po-dest"]',
        DROPSHIP: '#mmi-po-dropship',
        WC_ORDER: '#mmi-po-wc-order',
        WC_LOAD: '#mmi-po-wc-load',
        WC_LINK: '#mmi-po-wc-link',
        LABEL_MODE_RADIO: 'input[name="mmi-po-label-mode"]',
        LABEL_OURS: '#mmi-po-label-ours',
        LABEL_SUPPLIER: '#mmi-po-label-supplier',
        LABEL_POLICY: '#mmi-po-label-policy',
        LABEL_PANEL: '#mmi-po-label-panel',
        PKG_INPUT: '#mmi-po-label-panel [data-pkg]',
        LABEL_CURRENT: '#mmi-po-label-current',
        CONTENTS_BODY: '#mmi-po-contents-body',
        CONTENTS_SUMMARY: '#mmi-po-contents-summary',
        USE_WEIGHT: '#mmi-po-use-weight',
        USE_BOX: '#mmi-po-use-box',
        LABEL_BLOCKER: '#mmi-po-label-blocker',
        LABEL_HISTORY: '#mmi-po-label-history',
        BROWSE_RATES: '#mmi-po-browse-rates',
        RB: '#mmi-po-rb',
        RB_FORM: '#mmi-po-rb-form',
        RB_Q: '#mmi-po-rb-form [data-q]',
        RB_QW: '#mmi-po-rb-form [data-qw]',
        RB_FROM_NAME: '#mmi-po-rb-from-name',
        RB_TO_NAME: '#mmi-po-rb-to-name',
        RB_CLASS: '#mmi-po-rb-class',
        RB_VIEWBY: '#mmi-po-rb-viewby',
        RB_AVAILABLE: '#mmi-po-rb-available',
        RB_COLUMNS: '#mmi-po-rb-columns',
        RB_CARRIERS: '#mmi-po-rb-carriers',
        RB_CARRIER: '.mmi-po-rb-carrier',
        RB_RATES_TITLE: '#mmi-po-rb-rates-title',
        RB_RATE_LIST: '#mmi-po-rb-rate-list',
        RB_RATE: '.mmi-po-rb-rate',
        RB_SELECTION: '#mmi-po-rb-selection',
        RB_ERROR: '#mmi-po-rb-error',
        RB_BUY: '#mmi-po-rb-buy',
        RB_CONFIRM: '#mmi-po-rb-confirm',
        RB_CONFIRM_TEXT: '#mmi-po-rb-confirm-text',
        RB_BROWSE: '#mmi-po-rb-browse',
        LABEL_VOID: '.mmi-po-label-void',
        ATTACH_LABEL: '#mmi-po-attach-label',
        ATTACH_LABEL_WRAP: '#mmi-po-attach-label-wrap',
        ATTACH_LABEL_TEXT: '#mmi-po-attach-label-text',
        SS_TEST: '#mmi-po-ss-test',
        SS_CARRIERS: '#mmi-po-ss-carriers',
        SS_STATUS: '#mmi-po-ss-status',
        INSTRUCTIONS: '#mmi-po-instructions',
        NOTES: '#mmi-po-notes',
        SEARCH: '#mmi-po-search',
        SEARCH_SPINNER: '#mmi-po-search-spinner',
        RESULTS: '#mmi-po-results',
        RESULT: '.mmi-po-result',
        BRAND_ONLY: '#mmi-po-brand-only',
        BRAND_ONLY_WRAP: '#mmi-po-brand-only-wrap',
        BRAND_ONLY_LABEL: '#mmi-po-brand-only-label',
        LINES_BODY: '#mmi-po-lines-body',
        LINES_EMPTY: '#mmi-po-lines-empty',
        LINES_WRAP: '.mmi-po-lines-wrap',
        LINE: 'tr.mmi-po-line',
        LINE_REMOVE: '.mmi-po-line-remove',
        LINE_INPUT: '.mmi-po-line input, .mmi-po-line textarea',
        ADD_LINE: '#mmi-po-add-line',
        SUBTOTAL: '#mmi-po-subtotal',
        TAX: '#mmi-po-tax',
        SHIPPING: '#mmi-po-shipping',
        TOTAL: '#mmi-po-total',
        HISTORY_SECTION: '#mmi-po-history-section',
        HISTORY: '#mmi-po-history',
        EMAIL_MODAL: '#mmi-po-email-modal',
        EMAIL_TO: '#mmi-po-email-to',
        EMAIL_CC: '#mmi-po-email-cc',
        EMAIL_SUBJECT: '#mmi-po-email-subject',
        EMAIL_BODY: '#mmi-po-email-body',
        EMAIL_ERROR: '#mmi-po-email-error',
        EMAIL_SEND: '#mmi-po-email-send',
        EMAIL_REMEMBER: '#mmi-po-email-remember',
        EMAIL_REMEMBER_WRAP: '#mmi-po-remember-wrap',
        // Suppliers
        SUPPLIERS_BODY: '#mmi-po-suppliers-body',
        SUPPLIERS_TABLE: '#mmi-po-suppliers',
        SUPPLIER_ADD: '#mmi-po-supplier-add',
        SUPPLIER_EDIT: '.mmi-po-supplier-edit',
        SUPPLIER_MODAL: '#mmi-po-supplier-modal',
        SUPPLIER_FORM: '#mmi-po-supplier-form',
        SUPPLIER_SAVE: '#mmi-po-supplier-save',
        SUPPLIER_ERROR: '#mmi-po-supplier-error',
        SUPPLIER_TITLE: '#mmi-po-supplier-title',
        BRAND_FILTER: '#mmi-po-s-brand-filter',
        BRAND_LIST: '#mmi-po-s-brands',
        BRAND_COUNT: '#mmi-po-s-brand-count',
        BRAND_MS: '#mmi-po-s-brand-ms',
        BRAND_TRIGGER: '#mmi-po-s-brand-ms .mmi-ms-trigger',
        BRAND_MENU: '#mmi-po-s-brand-ms .mmi-ms-menu',
        BRAND_SUMMARY: '#mmi-po-s-brand-summary',
        BRAND_OVERLAP: '#mmi-po-brand-overlap',
        // Settings
        LOGO_PICK: '#mmi-po-logo-pick',
        LOGO_RESET: '#mmi-po-logo-reset',
        LOGO_ID: '#mmi-po-logo-id',
        LOGO_IMG: '#mmi-po-logo-img',
    };

    const MESSAGES = {
        saved: 'Saved %number%.',
        sent: 'Emailed %number% to %to%.',
        statusChanged: 'Status set to %status%.',
        networkError: 'The request failed. Check your connection and try again.',
        confirmDelete: 'Delete draft %number%? This cannot be undone.',
        confirmLeave: 'You have unsaved changes on this purchase order.',
        noResults: 'No products match "%term%".',
        noEmail: 'No email on file. Add one in Suppliers, or type it when sending.',
        suggest: 'Usually from %supplier%',
        lastPo: 'Last PO %cost% (%po%)',
        brandOnly: 'Only %supplier%\'s brands (%count%)',
        noBrands: 'No brands',
        moreBrands: '%names% +%more% more',
        overlap: 'Brands claimed by more than one active supplier: %brands%. The first supplier by name is suggested.',
        noSuppliers: 'No suppliers yet.',
        needSupplier: 'Choose a supplier before emailing.',
        needLines: 'Add at least one line first.',
        historyFile: 'PDF sent',
        policyOnly: '%supplier% ships on their own labels only, so this PO cannot include a prepaid label.',
        wcLoaded: 'Loaded order #%number%: shipping address and %count% product line(s).',
        wcLink: 'Order #%number% ↗',
        rbAllCarriers: 'All carriers',
        rbAvailable: '%n% out of %total% carriers available',
        rbLoading: 'Getting rates…',
        rbNoRates: 'No rates for this shipment.',
        rbNoMatch: 'No %class% rates. Change the Service Class filter.',
        rbPick: 'Pick a rate to buy its label.',
        rbSelected: '%carrier% · %service% · %cost%',
        rbEstimate: 'Estimate only: the postal codes differ from this PO\'s addresses. Labels always use the PO\'s addresses, so set them back (or edit the PO) to buy.',
        rbHasLabel: 'This PO already has an active label. Void it before buying another.',
        rbConfirm: 'Buy for %cost%',
        rbCharge: 'Real postage: %cost% (plus any carrier adjustments) is charged to your ShipStation account. A label can be voided afterwards for a refund.',
        rbNeedsSave: 'Save the PO first.',
        buySummary: '%service% for %cost%, from %from% to %to%.',
        confirmVoid: 'Void this label? ShipStation will request a postage refund where the carrier allows it.',
        labelBought: 'Label bought: %service%, tracking %tracking%.',
        labelVoided: 'Label voided.',
        attachLabel: 'Attach shipping label (%service%, tracking %tracking%)',
        ssConnected: 'Connected. %count% carrier(s) on the account. Tick the ones to quote, then Save settings.',
        ssNoCarriers: 'Connected, but no carriers are set up in ShipStation yet.',
        noSize: 'no size on file',
        sizeCaption: '%dims% in · %weight% lb each (%source%)',
        contentsEmpty: 'Add lines to see what goes in the box.',
        totalWeight: 'Total %weight% lb',
        totalWeightPartial: 'At least %weight% lb (%missing% line(s) have no weight)',
        largestItem: 'Largest item %dims% in',
        suggestedBox: 'Suggested box ≈ %dims% in',
        noDims: 'No line has dimensions on file: measure or estimate the box.',
        useWeight: 'Use weight',
        useBox: 'Use box size',
    };

    const LABELS = {
        SAVE_IDLE: '<span class="dashicons dashicons-saved"></span> Save',
        SAVE_BUSY: '<span class="mmi-loading"></span> Saving…',
        SEND_IDLE: '<span class="dashicons dashicons-email-alt"></span> Send',
        SEND_BUSY: '<span class="mmi-loading"></span> Sending…',
        SUPPLIER_IDLE: '<span class="dashicons dashicons-saved"></span> Save supplier',
        RB_BUY_BUSY: '<span class="mmi-loading"></span> Buying…',
        LOAD_IDLE: '<span class="dashicons dashicons-download"></span> Load address & items',
        LOAD_BUSY: '<span class="mmi-loading"></span> Loading…',
        TEST_IDLE: '<span class="dashicons dashicons-yes-alt"></span> Test connection',
        TEST_BUSY: '<span class="mmi-loading"></span> Testing…',
        SUPPLIER_BUSY: '<span class="mmi-loading"></span> Saving…',
    };

    /* ── Helpers ───────────────────────────────────────────────────────── */

    function fmt(template, values) {
        return Object.keys(values).reduce(
            (out, key) => out.split(`%${key}%`).join(values[key]),
            template
        );
    }

    function money(amount) {
        const n = Number(amount) || 0;
        return `${CFG.currency}${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }

    function num(value) {
        const n = parseFloat(value);
        return Number.isFinite(n) ? n : 0;
    }

    /** POSTs to admin-ajax; resolves with `data`, rejects with a readable message. */
    function ajax(action, data) {
        return new Promise((resolve, reject) => {
            $.post(CFG.ajaxUrl, Object.assign({ action: `mmi_po_${action}`, nonce: CFG.nonce }, data))
                .done((res) => {
                    if (res && res.success) {
                        resolve(res.data);
                    } else {
                        reject((res && res.data && res.data.message) || MESSAGES.networkError);
                    }
                })
                .fail((xhr) => {
                    const msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                    reject(msg || MESSAGES.networkError);
                });
        });
    }

    function setBusy($button, busy, idleHtml, busyHtml) {
        $button.prop('disabled', busy).toggleClass('mmi-is-loading', busy).html(busy ? busyHtml : idleHtml);
    }

    function initSort(selector) {
        if (window.MMI_TableManager && $(selector).length) {
            window.MMI_TableManager.initDomSort(selector);
        }
    }

    /* ── Orders list ───────────────────────────────────────────────────── */

    function initOrders() {
        initSort(SELECTORS.ORDERS_TABLE);
        $(document).on('click', SELECTORS.ROW_DUPLICATE, function () {
            const $btn = $(this).prop('disabled', true);
            ajax('duplicate', { id: $btn.data('id') })
                .then((data) => { window.location.href = data.editUrl; })
                .catch((msg) => { $btn.prop('disabled', false); window.alert(msg); });
        });
    }

    /* ── Editor ────────────────────────────────────────────────────────── */

    const Editor = {
        order: null,
        suppliers: {},
        dirty: false,
        searchTimer: null,
        searchXhr: 0,
        lastResults: [],
        rb: { carriers: null, results: {}, selected: 'all', rate: null, run: 0 },

        init() {
            (CFG.suppliers || []).forEach((s) => { this.suppliers[s.id] = s; });
            this.order = CFG.order || {
                id: 0,
                po_number: '',
                status: 'draft',
                supplier_id: 0,
                po_date: CFG.defaults.po_date,
                ship_to_fields: Object.assign({}, CFG.defaults.ship_to_fields),
                wc_order_id: 0,
                label_mode: 'supplier',
                package: CFG.defaults.package,
                labels: [],
                label_blocker: '',
                instructions: CFG.defaults.instructions,
                notes: '',
                tax: 0,
                shipping: 0,
                items: [],
                history: [],
            };
            this.renderSupplierOptions();
            this.fill();
            this.bind();
            if (window.MMIModal) {
                window.MMIModal.init();
            }
        },

        fill() {
            const o = this.order;
            $(SELECTORS.SUPPLIER).val(String(o.supplier_id || 0));
            $(SELECTORS.DATE).val(o.po_date);
            $(SELECTORS.NUMBER_INPUT).val(o.po_number || '').prop('disabled', !!o.sent_at);
            this.fillAddress(o.ship_to_fields);
            $(SELECTORS.DEST_RADIO).filter(`[value="${o.wc_order_id || !this.sameAsStore(o.ship_to_fields) ? 'customer' : 'store'}"]`).prop('checked', true);
            this.renderDest();
            $(SELECTORS.LABEL_MODE_RADIO).filter(`[value="${o.label_mode}"]`).prop('checked', true);
            Object.keys(o.package || {}).forEach((key) => {
                $(SELECTORS.PKG_INPUT).filter(`[data-pkg="${key}"]`).val(o.package[key]);
            });
            $(SELECTORS.INSTRUCTIONS).val(o.instructions);
            $(SELECTORS.NOTES).val(o.notes);
            $(SELECTORS.TAX).val(num(o.tax).toFixed(2));
            $(SELECTORS.SHIPPING).val(num(o.shipping).toFixed(2));
            $(SELECTORS.LINES_BODY).empty();
            o.items.forEach((item) => this.appendLine(item));
            this.renderHeader();
            this.renderSupplierCard();
            this.renderHistory();
            this.renderShipping();
            this.recalc();
        },

        /* Deliver to */

        fillAddress(a) {
            $(SELECTORS.ADDR_INPUT).each(function () {
                const key = this.dataset.addr;
                if (this.type === 'checkbox') {
                    this.checked = !!(a && a[key]);
                } else {
                    this.value = (a && a[key]) || '';
                }
            });
        },

        collectAddress() {
            const a = {};
            $(SELECTORS.ADDR_INPUT).each(function () {
                a[this.dataset.addr] = this.type === 'checkbox' ? (this.checked ? 1 : 0) : this.value;
            });
            return a;
        },

        sameAsStore(a) {
            const s = CFG.defaults.ship_to_fields || {};
            return ['street1', 'postal_code'].every((k) => String((a || {})[k] || '').trim().toLowerCase() === String(s[k] || '').trim().toLowerCase());
        },

        dest() {
            return $(SELECTORS.DEST_RADIO).filter(':checked').val() || 'store';
        },

        renderDest() {
            const customer = this.dest() === 'customer';
            $(SELECTORS.DROPSHIP).prop('hidden', !customer);
            const id = Number(this.order.wc_order_id) || 0;
            $(SELECTORS.WC_LINK)
                .prop('hidden', !id)
                .attr('href', id ? CFG.wcOrderUrl.replace('%id%', id) : '#')
                .text(id ? fmt(MESSAGES.wcLink, { number: id }) : '');
            if (id && !$(SELECTORS.WC_ORDER).val()) {
                $(SELECTORS.WC_ORDER).val(id);
            }
        },

        onDestChange() {
            if (this.dest() === 'store') {
                this.fillAddress(CFG.defaults.ship_to_fields);
                this.order.wc_order_id = 0;
                $(SELECTORS.WC_ORDER).val('');
            } else if (this.sameAsStore(this.collectAddress())) {
                this.fillAddress({ country: 'US', residential: 1 });
            }
            this.renderDest();
            this.markDirty();
        },

        loadWcOrder() {
            const number = $(SELECTORS.WC_ORDER).val().trim();
            if (!number) {
                return;
            }
            const $btn = $(SELECTORS.WC_LOAD);
            setBusy($btn, true, LABELS.LOAD_IDLE, LABELS.LOAD_BUSY);
            ajax('load_wc_order', { order: number })
                .then((data) => {
                    this.fillAddress(data.address);
                    this.order.wc_order_id = data.id;
                    data.lines.forEach((line) => this.addResult(line, true, Math.max(1, line.qty)));
                    this.renderDest();
                    this.recalc();
                    this.markDirty();
                    this.message(fmt(MESSAGES.wcLoaded, { number: data.number, count: data.lines.length }));
                })
                .catch((msg) => this.message(msg, 'error'))
                .finally(() => setBusy($btn, false, LABELS.LOAD_IDLE, LABELS.LOAD_BUSY));
        },

        /* Shipping */

        labelMode() {
            return $(SELECTORS.LABEL_MODE_RADIO).filter(':checked').val() || 'supplier';
        },

        collectPackage() {
            const p = {};
            $(SELECTORS.PKG_INPUT).each(function () {
                p[this.dataset.pkg] = this.value;
            });
            return p;
        },

        activeLabel() {
            return (this.order.labels || []).find((l) => l.status === 'active') || null;
        },

        renderShipping() {
            const s = this.supplier();
            const supplierOnly = !!(s && s.label_policy === 'supplier_only');
            if (supplierOnly) {
                $(SELECTORS.LABEL_SUPPLIER).prop('checked', true);
            }
            $(SELECTORS.LABEL_OURS).prop('disabled', supplierOnly);
            $(SELECTORS.LABEL_POLICY).text(supplierOnly ? fmt(MESSAGES.policyOnly, { supplier: s.name }) : '').prop('hidden', !supplierOnly);

            const ours = this.labelMode() === 'ours';
            $(SELECTORS.LABEL_PANEL).prop('hidden', !ours);
            const expiry = CFG.shipstation ? CFG.shipstation.expiry : 'none';
            $(SELECTORS.EXPIRY_BADGE)
                .attr('class', `mmi-badge ${expiry === 'expired' ? 'error' : 'warning'}`)
                .text(CFG.shipstation ? CFG.shipstation.expiryText : '')
                .prop('hidden', !(expiry === 'warning' || expiry === 'expired'));

            const active = this.activeLabel();
            const blocker = this.dirty ? '' : (this.order.id ? this.order.label_blocker : '');
            $(SELECTORS.LABEL_BLOCKER).text(blocker || '').prop('hidden', !blocker);
            $(SELECTORS.BROWSE_RATES).prop('disabled', !CFG.shipstation || !CFG.shipstation.configured);

            $(SELECTORS.LABEL_CURRENT).html(active ? `
                <span class="dashicons dashicons-yes-alt mmi-text-success"></span>
                <strong>${esc(active.service_name)}</strong> · ${esc(money(active.cost))}
                · tracking <code>${esc(active.tracking_number || '—')}</code>
                ${active.is_test ? '<span class="mmi-badge warning">Test label</span>' : ''}
                ${active.label_url ? `<a class="button button-small" target="_blank" rel="noopener" href="${esc(active.label_url)}"><span class="dashicons dashicons-printer"></span> Print label</a>` : ''}
                <button type="button" class="button button-small button-link-delete mmi-po-label-void" data-id="${active.id}">Void</button>
            ` : '').prop('hidden', !active);

            const past = (this.order.labels || []).filter((l) => l.status !== 'active');
            $(SELECTORS.LABEL_HISTORY).html(past.map((l) => `<li class="mmi-text-muted">${esc(l.created_at)} · ${esc(l.service_name)} · ${esc(l.tracking_number || '—')} · ${esc(money(l.cost))} · ${esc(l.status)}${l.is_test ? ' (test)' : ''}</li>`).join(''));
        },

        /* ── Rate Browser (modeled on ShipStation's) ─────────────────────── */

        /** Opens with the saved PO's addresses and package; saves first so the quote matches the PO. */
        openRateBrowser() {
            this.saveIfDirty()
                .then(() => {
                    const q = this.order.quote || {};
                    const oz = Number(q.weight_oz) || 0;
                    this.rbFill(q);
                    $(SELECTORS.RB_QW).filter('[data-qw="lb"]').val(Math.floor(oz / 16));
                    $(SELECTORS.RB_QW).filter('[data-qw="oz"]').val(Math.round((oz % 16) * 10) / 10);
                    const s = this.supplier();
                    const to = this.order.ship_to_fields || {};
                    $(SELECTORS.RB_FROM_NAME).text(s ? s.name : '');
                    $(SELECTORS.RB_TO_NAME).text(to.company || to.name || '');
                    $(SELECTORS.RB_ERROR).prop('hidden', true).empty();
                    this.rb.rate = null;
                    this.rbStage('pick');
                    window.MMIModal.open($(SELECTORS.RB));
                    this.rbBrowse();
                })
                .catch(() => {});
        },

        rbFill(q) {
            $(SELECTORS.RB_Q).each(function () {
                const key = this.dataset.q;
                if (this.type === 'checkbox') {
                    this.checked = !!q[key];
                } else {
                    this.value = q[key] === undefined || q[key] === null ? '' : q[key];
                }
            });
        },

        rbQuote() {
            const q = {};
            $(SELECTORS.RB_Q).each(function () {
                q[this.dataset.q] = this.type === 'checkbox' ? (this.checked ? 1 : 0) : this.value;
            });
            const lb = num($(SELECTORS.RB_QW).filter('[data-qw="lb"]').val());
            const oz = num($(SELECTORS.RB_QW).filter('[data-qw="oz"]').val());
            q.weight_oz = Math.round((lb * 16 + oz) * 100) / 100;
            const base = this.order.quote || {};
            ['from_city', 'from_state', 'to_city', 'to_state'].forEach((k) => { q[k] = base[k] || ''; });
            return q;
        },

        /** Quoting a different ZIP/country is allowed, but only the PO's own addresses can be bought. */
        rbEstimateOnly(q) {
            const base = this.order.quote || {};
            return String(q.from_postal).trim() !== String(base.from_postal || '').trim()
                || String(q.to_postal).trim() !== String(base.to_postal || '').trim()
                || String(q.to_country) !== String(base.to_country || 'US');
        },

        /** Carriers one at a time: counts fill in progressively, one ShipStation request in flight. */
        async rbBrowse() {
            const run = ++this.rb.run;
            const quote = this.rbQuote();
            this.rb.results = {};
            this.rb.rate = null;
            this.rbStage('pick');
            $(SELECTORS.RB_ERROR).prop('hidden', true).empty();
            try {
                if (!this.rb.carriers) {
                    this.rb.carriers = (await ajax('rate_carriers', {})).carriers;
                }
            } catch (msg) {
                $(SELECTORS.RB_ERROR).text(msg).prop('hidden', false);
                return;
            }
            this.rb.carriers.forEach((c) => { this.rb.results[c.code] = { loading: true, rates: [], error: '' }; });
            this.rbRender();
            for (const c of this.rb.carriers) {
                try {
                    const data = await ajax('rates', { carrier_code: c.code, quote });
                    if (run !== this.rb.run) {
                        return; // A newer Browse superseded this one.
                    }
                    this.rb.results[c.code] = { loading: false, rates: data.rates, error: data.error };
                } catch (msg) {
                    if (run !== this.rb.run) {
                        return;
                    }
                    this.rb.results[c.code] = { loading: false, rates: [], error: msg };
                }
                this.rbRender();
            }
        },

        /** ShipStation-style service classes, from the service name (the v1 API has no class field). */
        rbClass(name) {
            const n = name.toLowerCase();
            if (/next day|overnight|express(?! saver)|1 day|early/.test(n)) {
                return 'overnight';
            }
            if (/2nd day|2 day|two day|priority mail(?! express)/.test(n)) {
                return '2day';
            }
            if (/3 day|three day|express saver/.test(n)) {
                return '3day';
            }
            return 'ground';
        },

        rbFiltered(code) {
            const cls = $(SELECTORS.RB_CLASS).val();
            const list = code === 'all'
                ? this.rb.carriers.flatMap((c) => this.rb.results[c.code].rates.map((r) => Object.assign({ carrier_code: c.code, carrier_name: c.name }, r)))
                : this.rb.results[code].rates.map((r) => Object.assign({ carrier_code: code, carrier_name: (this.rb.carriers.find((c) => c.code === code) || {}).name }, r));
            return list.filter((r) => !cls || this.rbClass(r.service_name) === cls).sort((a, b) => a.cost - b.cost);
        },

        rbBadge(code) {
            const family = (/^(ups|fedex|dhl|stamps|usps|globalpost|seko)/.exec(code) || ['other'])[0];
            return `<span class="mmi-po-rb-logo mmi-po-rb-logo--${family}" aria-hidden="true">${esc(family === 'stamps' ? 'USPS' : family.slice(0, 5).toUpperCase())}</span>`;
        },

        rbRender() {
            const carriers = this.rb.carriers || [];
            const done = carriers.filter((c) => !this.rb.results[c.code].loading);
            const available = done.filter((c) => this.rb.results[c.code].rates.length).length;
            const loading = done.length < carriers.length;
            $(SELECTORS.RB_AVAILABLE).html(`${esc(fmt(MESSAGES.rbAvailable, { n: available, total: carriers.length }))}${loading ? ' <span class="mmi-loading"></span>' : ''}`);

            const viewByPrice = $(SELECTORS.RB_VIEWBY).val() === 'price';
            $(SELECTORS.RB_COLUMNS).toggleClass('is-price-view', viewByPrice);
            if (viewByPrice) {
                this.rb.selected = 'all';
            }
            const allCount = done.reduce((n, c) => n + this.rbFiltered(c.code).length, 0);
            const card = (code, label, count, extra) => `<button type="button" class="mmi-po-rb-carrier${this.rb.selected === code ? ' is-selected' : ''}" data-code="${esc(code)}" ${extra || ''}>
                ${code === 'all' ? '<span class="mmi-po-rb-logo mmi-po-rb-logo--all dashicons dashicons-car" aria-hidden="true"></span>' : this.rbBadge(code)}
                <span class="mmi-po-rb-carrier-name">${esc(label)}</span>
                <span class="mmi-po-rb-count${count ? '' : ' is-zero'}">${count === null ? '<span class="mmi-loading"></span>' : count}</span>
            </button>`;
            $(SELECTORS.RB_CARRIERS).html(
                `<div class="mmi-po-rb-group">Automated Rates</div>${card('all', MESSAGES.rbAllCarriers, allCount)}<div class="mmi-po-rb-group">Carrier Accounts</div>`
                + carriers.map((c) => {
                    const r = this.rb.results[c.code];
                    return card(c.code, c.name, r.loading ? null : this.rbFiltered(c.code).length, r.error ? `title="${esc(r.error)}"` : '');
                }).join('')
            );

            const code = this.rb.selected;
            const res = code === 'all' ? null : this.rb.results[code];
            $(SELECTORS.RB_RATES_TITLE).text(code === 'all' ? MESSAGES.rbAllCarriers : (carriers.find((c) => c.code === code) || {}).name || '');
            const rates = this.rbFiltered(code);
            let html;
            if (res && res.loading) {
                html = `<p class="mmi-po-rb-empty"><span class="mmi-loading"></span> ${esc(MESSAGES.rbLoading)}</p>`;
            } else if (res && res.error) {
                html = `<p class="mmi-po-rb-empty">${esc(res.error)}</p>`;
            } else if (!rates.length) {
                const cls = $(SELECTORS.RB_CLASS).find('option:selected').text();
                html = `<p class="mmi-po-rb-empty">${esc(loading && code === 'all' ? MESSAGES.rbLoading : ($(SELECTORS.RB_CLASS).val() ? fmt(MESSAGES.rbNoMatch, { class: cls }) : MESSAGES.rbNoRates))}</p>`;
            } else {
                const pkg = $(SELECTORS.RB_Q).filter('[data-q="package_code"]').find('option:selected').text();
                html = rates.map((r) => {
                    const key = `${r.carrier_code}|${r.service_code}`;
                    const sel = this.rb.rate && `${this.rb.rate.carrier_code}|${this.rb.rate.service_code}` === key;
                    return `<button type="button" role="option" aria-selected="${sel ? 'true' : 'false'}" class="mmi-po-rb-rate${sel ? ' is-selected' : ''}" data-key="${esc(key)}">
                        <span class="mmi-po-rb-rate-main"><strong>${esc(r.service_name)}</strong>
                        <span class="mmi-po-rb-rate-sub">${esc(code === 'all' ? `${r.carrier_name} · ${pkg}` : pkg)}${r.other_cost ? ` · incl. ${esc(money(r.other_cost))} other` : ''}</span></span>
                        <span class="mmi-po-rb-price">${esc(money(r.cost))}</span>
                    </button>`;
                }).join('');
            }
            $(SELECTORS.RB_RATE_LIST).html(html);
            this.rbFooter();
        },

        rbFooter() {
            const rate = this.rb.rate;
            const quote = this.rbQuote();
            let note = MESSAGES.rbPick;
            let canBuy = false;
            if (rate) {
                note = fmt(MESSAGES.rbSelected, { carrier: rate.carrier_name, service: rate.service_name, cost: money(rate.cost) });
                if (this.activeLabel()) {
                    note += ` — ${MESSAGES.rbHasLabel}`;
                } else if (this.rbEstimateOnly(quote)) {
                    note += ` — ${MESSAGES.rbEstimate}`;
                } else {
                    canBuy = true;
                }
            }
            $(SELECTORS.RB_SELECTION).text(note);
            $(SELECTORS.RB_BUY).prop('disabled', !canBuy);
        },

        /** 'pick' → choose a rate; 'confirm' → one more explicit click, with the charge spelled out. */
        rbStage(stage) {
            const confirm = stage === 'confirm';
            $(SELECTORS.RB_BUY).prop('hidden', confirm);
            $(SELECTORS.RB_CONFIRM).prop('hidden', !confirm).prop('disabled', false).removeClass('mmi-is-loading');
            if (confirm && this.rb.rate) {
                $(SELECTORS.RB_CONFIRM_TEXT).text(fmt(MESSAGES.rbConfirm, { cost: money(this.rb.rate.cost) }));
                $(SELECTORS.RB_SELECTION).text(fmt(MESSAGES.rbCharge, { cost: money(this.rb.rate.cost) }));
            }
        },

        /** Copies the browser's package into the PO (so the label matches what was quoted), saves, buys. */
        rbPurchase() {
            const rate = this.rb.rate;
            if (!rate) {
                return;
            }
            const q = this.rbQuote();
            $(SELECTORS.PKG_INPUT).filter('[data-pkg="weight_lb"]').val(Math.floor(q.weight_oz / 16));
            $(SELECTORS.PKG_INPUT).filter('[data-pkg="weight_oz_part"]').val(Math.round((q.weight_oz % 16) * 10) / 10);
            ['length', 'width', 'height', 'confirmation'].forEach((k) => $(SELECTORS.PKG_INPUT).filter(`[data-pkg="${k}"]`).val(q[k]));
            $(SELECTORS.ADDR_INPUT).filter('[data-addr="residential"]').prop('checked', !!q.residential);
            this.markDirty();

            const $btn = $(SELECTORS.RB_CONFIRM);
            $btn.prop('disabled', true).addClass('mmi-is-loading');
            $(SELECTORS.RB_CONFIRM_TEXT).html(LABELS.RB_BUY_BUSY);
            this.save()
                .then(() => {
                    if (this.order.label_blocker) {
                        throw this.order.label_blocker;
                    }
                    return ajax('buy_label', { id: this.order.id, carrier_code: rate.carrier_code, service_code: rate.service_code, service_name: `${rate.carrier_name} ${rate.service_name}` });
                })
                .then((data) => {
                    window.MMIModal.close($(SELECTORS.RB));
                    this.applyResponse(data);
                    const label = this.activeLabel();
                    this.message(fmt(MESSAGES.labelBought, { service: label ? label.service_name : '', tracking: label ? label.tracking_number : '' }));
                })
                .catch((msg) => {
                    $(SELECTORS.RB_ERROR).text(msg).prop('hidden', false);
                    this.rbStage('pick');
                    this.rbFooter();
                });
        },

        voidLabel(shipmentId) {
            if (!window.confirm(MESSAGES.confirmVoid)) {
                return;
            }
            ajax('void_label', { id: this.order.id, shipment_id: shipmentId })
                .then((data) => { this.applyResponse(data); this.message(MESSAGES.labelVoided); })
                .catch((msg) => this.message(msg, 'error'));
        },

        bind() {
            const self = this;
            $(SELECTORS.SUPPLIER).on('change', () => this.onSupplierChange());
            $([SELECTORS.DATE, SELECTORS.NUMBER_INPUT, SELECTORS.ADDR_INPUT, SELECTORS.INSTRUCTIONS, SELECTORS.NOTES, SELECTORS.PKG_INPUT].join(',')).on('input change', () => this.markDirty());
            $(SELECTORS.DEST_RADIO).on('change', () => this.onDestChange());
            $(SELECTORS.WC_LOAD).on('click', () => this.loadWcOrder());
            $(SELECTORS.WC_ORDER).on('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); this.loadWcOrder(); } });
            $(SELECTORS.LABEL_MODE_RADIO).on('change', () => { this.markDirty(); this.renderShipping(); });
            $(SELECTORS.BROWSE_RATES).on('click', () => this.openRateBrowser());
            $(SELECTORS.RB_FORM).on('submit', (e) => { e.preventDefault(); this.rbBrowse(); });
            $(SELECTORS.RB_CLASS).add(SELECTORS.RB_VIEWBY).on('change', () => { this.rb.rate = null; this.rbStage('pick'); this.rbRender(); });
            $(SELECTORS.RB_Q).add(SELECTORS.RB_QW).on('input change', () => { this.rbStage('pick'); this.rbFooter(); });
            $(SELECTORS.RB_CARRIERS).on('click', SELECTORS.RB_CARRIER, function () {
                self.rb.selected = String($(this).data('code'));
                self.rbRender();
            });
            $(SELECTORS.RB_RATE_LIST).on('click', SELECTORS.RB_RATE, function () {
                const key = String($(this).data('key'));
                self.rb.rate = self.rbFiltered(self.rb.selected).find((r) => `${r.carrier_code}|${r.service_code}` === key) || null;
                self.rbStage('pick');
                self.rbRender();
            });
            $(SELECTORS.RB_BUY).on('click', () => this.rbStage('confirm'));
            $(SELECTORS.RB_CONFIRM).on('click', () => this.rbPurchase());
            $(SELECTORS.CONTENTS_SUMMARY)
                .on('click', SELECTORS.USE_WEIGHT, () => this.applyWeightGuess())
                .on('click', SELECTORS.USE_BOX, () => this.applyBoxGuess());
            $(SELECTORS.LABEL_CURRENT).on('click', SELECTORS.LABEL_VOID, function () { self.voidLabel($(this).data('id')); });
            $([SELECTORS.TAX, SELECTORS.SHIPPING].join(',')).on('input', () => { this.recalc(); this.markDirty(); });

            $(SELECTORS.LINES_BODY)
                .on('input', 'input, textarea', function () {
                    self.recalcRow($(this).closest(SELECTORS.LINE));
                    self.recalc();
                    self.markDirty();
                })
                .on('click', SELECTORS.LINE_REMOVE, function () {
                    $(this).closest(SELECTORS.LINE).remove();
                    self.recalc();
                    self.markDirty();
                });
            if ($.fn.sortable) {
                $(SELECTORS.LINES_BODY).sortable({ handle: '.mmi-po-handle', axis: 'y', update: () => this.markDirty() });
            }

            $(SELECTORS.ADD_LINE).on('click', () => {
                const $row = this.appendLine({ qty: 1, unit_cost: 0 });
                this.recalc();
                this.markDirty();
                $row.find('input').first().trigger('focus');
            });

            $(SELECTORS.SEARCH)
                .on('input', () => this.queueSearch())
                .on('keydown', (e) => this.onSearchKey(e));
            $(SELECTORS.BRAND_ONLY).on('change', () => this.runSearch());
            $(SELECTORS.RESULTS).on('click', SELECTORS.RESULT, function () {
                self.addResult(self.lastResults[$(this).data('index')]);
            });
            $(document).on('click', (e) => {
                if (!$(e.target).closest('.mmi-po-search').length) {
                    $(SELECTORS.RESULTS).prop('hidden', true);
                }
            });

            $(SELECTORS.SAVE).on('click', () => this.save().catch(() => {}));
            $(SELECTORS.PREVIEW).on('click', () => this.openPdf(false));
            $(SELECTORS.DOWNLOAD).on('click', () => this.openPdf(true));
            $(SELECTORS.EMAIL).on('click', () => this.openEmail());
            $(SELECTORS.EMAIL_SEND).on('click', () => this.send());
            $(SELECTORS.STATUS_SELECT).on('change', () => this.changeStatus());
            $(SELECTORS.DUPLICATE).on('click', () => this.duplicate());
            $(SELECTORS.DELETE).on('click', () => this.remove());

            window.addEventListener('beforeunload', (e) => {
                if (this.dirty) {
                    e.preventDefault();
                    e.returnValue = MESSAGES.confirmLeave;
                }
            });
        },

        /* Supplier */

        renderSupplierOptions() {
            const current = Number(this.order.supplier_id || 0);
            const $select = $(SELECTORS.SUPPLIER);
            $select.find('option:not([value="0"])').remove();
            Object.values(this.suppliers)
                .filter((s) => s.active || s.id === current)
                .forEach((s) => $select.append($('<option>').val(String(s.id)).text(s.name)));
        },

        supplier() {
            return this.suppliers[Number($(SELECTORS.SUPPLIER).val())] || null;
        },

        onSupplierChange() {
            const previous = this.suppliers[this.order.supplier_id] || null;
            const next = this.supplier();
            const $instr = $(SELECTORS.INSTRUCTIONS);
            const current = $instr.val().trim();
            const previousDefault = previous && previous.instructions ? previous.instructions.trim() : CFG.defaults.instructions.trim();
            // Only replace instructions nobody has typed over.
            if (current === '' || current === previousDefault) {
                $instr.val(next && next.instructions ? next.instructions : CFG.defaults.instructions);
            }
            this.order.supplier_id = next ? next.id : 0;
            this.renderSupplierCard();
            this.markDirty();
            this.renderShipping();
            if ($(SELECTORS.SEARCH).val().trim().length >= CFG.minSearch) {
                this.runSearch();
            }
        },

        renderSupplierCard() {
            const s = this.supplier();
            const $card = $(SELECTORS.SUPPLIER_CARD);
            const $wrap = $(SELECTORS.BRAND_ONLY_WRAP);
            if (!s) {
                $card.prop('hidden', true).empty();
                $wrap.prop('hidden', true);
                return;
            }
            const email = s.email
                ? `<div><span class="dashicons dashicons-email"></span> ${esc(s.email)}</div>`
                : `<div class="mmi-text-warning"><span class="dashicons dashicons-warning"></span> ${esc(MESSAGES.noEmail)}</div>`;
            const brands = s.brands.length ? `<div class="mmi-text-muted">${esc(s.brands.join(', '))}</div>` : '';
            $card.html(`<div class="mmi-po-address">${esc(s.address)}</div>${email}${brands}`).prop('hidden', false);

            $wrap.prop('hidden', s.brand_ids.length === 0);
            $(SELECTORS.BRAND_ONLY_LABEL).text(fmt(MESSAGES.brandOnly, { supplier: s.name, count: s.brand_ids.length }));
        },

        /* Search */

        queueSearch() {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.runSearch(), TIMING.SEARCH_DEBOUNCE_MS);
        },

        runSearch() {
            const term = $(SELECTORS.SEARCH).val().trim();
            const $results = $(SELECTORS.RESULTS);
            if (term.length < CFG.minSearch) {
                $results.prop('hidden', true).empty();
                return;
            }
            const s = this.supplier();
            const requestId = ++this.searchXhr;
            $(SELECTORS.SEARCH_SPINNER).prop('hidden', false);
            ajax('search_products', {
                term,
                supplier_id: s ? s.id : 0,
                brand_only: s && s.brand_ids.length && $(SELECTORS.BRAND_ONLY).is(':checked') ? 1 : 0,
            })
                .then((data) => {
                    if (requestId !== this.searchXhr) {
                        return; // A newer search superseded this one.
                    }
                    this.lastResults = data.results;
                    this.renderResults(term);
                })
                .catch((msg) => {
                    if (requestId === this.searchXhr) {
                        $results.html(`<div class="mmi-po-results-empty">${esc(msg)}</div>`).prop('hidden', false);
                    }
                })
                .finally(() => {
                    if (requestId === this.searchXhr) {
                        $(SELECTORS.SEARCH_SPINNER).prop('hidden', true);
                    }
                });
        },

        renderResults(term) {
            const $results = $(SELECTORS.RESULTS);
            if (!this.lastResults.length) {
                $results.html(`<div class="mmi-po-results-empty">${esc(fmt(MESSAGES.noResults, { term }))}</div>`).prop('hidden', false);
                return;
            }
            const current = this.supplier();
            const html = this.lastResults.map((r, i) => {
                const codes = [r.item_number && `Item ${r.item_number}`, r.sku && r.sku !== r.item_number && `SKU ${r.sku}`]
                    .filter(Boolean).map(esc).join(' · ');
                const cost = r.cost !== null ? `<strong>${esc(money(r.cost))}</strong>` : '<span class="mmi-text-muted">no cost</span>';
                const last = r.last ? `<span class="mmi-text-muted">${esc(fmt(MESSAGES.lastPo, { cost: money(r.last.cost), po: r.last.po_number }))}</span>` : '';
                const stock = r.stock_status
                    ? `<span class="mmi-badge ${r.stock_status === 'instock' ? 'success' : 'warning'}">${esc(r.stock_status === 'instock' ? `In stock${r.stock_qty !== null ? ` (${r.stock_qty})` : ''}` : r.stock_status)}</span>`
                    : '';
                const suggested = r.supplier_id && (!current || current.id !== r.supplier_id) && this.suppliers[r.supplier_id]
                    ? `<span class="mmi-badge info">${esc(fmt(MESSAGES.suggest, { supplier: this.suppliers[r.supplier_id].name }))}</span>`
                    : '';
                const draft = r.status !== 'publish' ? `<span class="mmi-badge">${esc(r.status)}</span>` : '';
                return `<button type="button" class="mmi-po-result" data-index="${i}">
                    <span class="mmi-po-result-main"><span class="mmi-po-result-title">${esc(r.title)}</span>
                    <span class="mmi-po-result-meta">${esc(r.brand)}${r.brand && codes ? ' · ' : ''}${codes}</span></span>
                    <span class="mmi-po-result-side">${cost} ${last} ${stock} ${suggested} ${draft}</span>
                </button>`;
            }).join('');
            $results.html(html).prop('hidden', false);
        },

        onSearchKey(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (this.lastResults.length && !$(SELECTORS.RESULTS).prop('hidden')) {
                    this.addResult(this.lastResults[0]);
                }
            } else if (e.key === 'Escape') {
                $(SELECTORS.RESULTS).prop('hidden', true);
            }
        },

        addResult(r, fromOrder, qty = 1) {
            if (!r) {
                return;
            }
            const $existing = $(SELECTORS.LINES_BODY).find(`${SELECTORS.LINE}[data-product-id="${r.product_id}"]`);
            if ($existing.length) {
                const $qty = $existing.find('[data-field="qty"]');
                $qty.val(Math.max(1, parseInt($qty.val(), 10) || 0) + qty);
                this.recalcRow($existing);
                this.flash($existing);
            } else {
                const cost = r.cost !== null ? r.cost : (r.last ? r.last.cost : 0);
                this.flash(this.appendLine({
                    product_id: r.product_id,
                    item_number: r.item_number,
                    sku: r.sku,
                    description: r.title,
                    qty,
                    dims: r.dims,
                    weight_lb: r.weight_lb,
                    size_source: r.size_source,
                    unit_cost: cost,
                }));
            }
            if (!this.supplier() && r.supplier_id && this.suppliers[r.supplier_id]) {
                $(SELECTORS.SUPPLIER).val(String(r.supplier_id));
                this.onSupplierChange();
            }
            this.recalc();
            this.markDirty();
            if (fromOrder) {
                return;
            }
            $(SELECTORS.SEARCH).val('').trigger('focus');
            $(SELECTORS.RESULTS).prop('hidden', true).empty();
            this.lastResults = [];
        },

        /* Lines */

        appendLine(item) {
            const $row = $(`<tr class="mmi-po-line" data-product-id="${Number(item.product_id) || 0}">
                <td class="mmi-po-col-handle"><span class="mmi-po-handle dashicons dashicons-menu" title="Drag to reorder"></span></td>
                <td class="mmi-po-col-item"><input type="text" data-field="item_number" aria-label="Item number"></td>
                <td><textarea data-field="description" rows="1" aria-label="Description"></textarea>
                    <input type="hidden" data-field="sku"></td>
                <td class="mmi-po-col-qty"><input type="number" data-field="qty" min="1" step="1" aria-label="Quantity"></td>
                <td class="mmi-po-col-money"><input type="number" data-field="unit_cost" min="0" step="0.01" aria-label="Unit cost"></td>
                <td class="mmi-po-col-money mmi-po-amount"></td>
                <td class="mmi-po-col-remove"><button type="button" class="button-link mmi-po-line-remove" aria-label="Remove line"><span class="dashicons dashicons-no-alt"></span></button></td>
            </tr>`);
            $row.find('[data-field="item_number"]').val(item.item_number || '');
            $row.find('[data-field="description"]').val(item.description || '');
            $row.find('[data-field="sku"]').val(item.sku || '');
            $row.find('[data-field="qty"]').val(Math.max(1, parseInt(item.qty, 10) || 1));
            $row.find('[data-field="unit_cost"]').val(num(item.unit_cost).toFixed(2));
            $row.data('size', { dims: item.dims || null, weight_lb: item.weight_lb === undefined ? null : item.weight_lb, source: item.size_source || '' });
            $row.find('td').eq(2).append(`<span class="mmi-po-line-size">${esc(this.sizeCaption(item))}</span>`);
            $(SELECTORS.LINES_BODY).append($row);
            this.recalcRow($row);
            return $row;
        },

        recalcRow($row) {
            const qty = Math.max(0, parseInt($row.find('[data-field="qty"]').val(), 10) || 0);
            const cost = num($row.find('[data-field="unit_cost"]').val());
            $row.find('.mmi-po-amount').text(money(qty * cost));
        },

        collectItems() {
            return $(SELECTORS.LINES_BODY).find(SELECTORS.LINE).get().map((row) => {
                const $row = $(row);
                return {
                    product_id: Number($row.data('product-id')) || 0,
                    item_number: $row.find('[data-field="item_number"]').val(),
                    sku: $row.find('[data-field="sku"]').val(),
                    description: $row.find('[data-field="description"]').val(),
                    qty: parseInt($row.find('[data-field="qty"]').val(), 10) || 1,
                    unit_cost: num($row.find('[data-field="unit_cost"]').val()),
                };
            });
        },

        recalc() {
            const items = this.collectItems();
            const subtotal = items.reduce((sum, i) => sum + Math.round(i.qty * i.unit_cost * 100) / 100, 0);
            const total = subtotal + num($(SELECTORS.TAX).val()) + num($(SELECTORS.SHIPPING).val());
            $(SELECTORS.SUBTOTAL).text(money(subtotal));
            $(SELECTORS.TOTAL).text(money(total));
            $(SELECTORS.LINES_EMPTY).prop('hidden', items.length > 0);
            this.renderContents();
            $(SELECTORS.LINES_WRAP).prop('hidden', items.length === 0);
        },

        sizeCaption(item) {
            if (!item.product_id) {
                return '';
            }
            if (!item.dims && (item.weight_lb === null || item.weight_lb === undefined)) {
                return MESSAGES.noSize;
            }
            return fmt(MESSAGES.sizeCaption, {
                dims: item.dims ? item.dims.map((n) => Number(n)).join(' × ') : '?',
                weight: item.weight_lb !== null && item.weight_lb !== undefined ? Number(item.weight_lb) : '?',
                source: item.size_source || 'catalog',
            });
        },

        /**
         * Contents table plus a box guess: the largest item (each item's sides sorted longest
         * first, then the max of each position), deepened until the combined volume fits, plus
         * padding. A starting point to type over, not a packing algorithm.
         */
        renderContents() {
            const lines = $(SELECTORS.LINES_BODY).find(SELECTORS.LINE).get().map((row) => {
                const $row = $(row);
                const size = $row.data('size') || {};
                return {
                    item: $row.find('[data-field="item_number"]').val(),
                    description: $row.find('[data-field="description"]').val(),
                    qty: Math.max(1, parseInt($row.find('[data-field="qty"]').val(), 10) || 1),
                    dims: size.dims || null,
                    weight: size.weight_lb === null || size.weight_lb === undefined ? null : Number(size.weight_lb),
                    source: size.source || '',
                };
            });
            const $body = $(SELECTORS.CONTENTS_BODY);
            const $summary = $(SELECTORS.CONTENTS_SUMMARY);
            if (!lines.length) {
                $body.html(`<tr><td colspan="7">${esc(MESSAGES.contentsEmpty)}</td></tr>`);
                $summary.empty();
                return;
            }
            $body.html(lines.map((l) => `<tr>
                <td>${esc(l.item)}</td><td>${esc(l.description)}</td><td class="mmi-po-col-num">${l.qty}</td>
                <td class="mmi-po-col-dims">${l.dims ? esc(l.dims.join(' × ')) : '<span class="mmi-text-muted">—</span>'}</td>
                <td class="mmi-po-col-num">${l.weight !== null ? esc(String(l.weight)) : '<span class="mmi-text-muted">—</span>'}</td>
                <td class="mmi-po-col-num">${l.weight !== null ? esc((l.weight * l.qty).toFixed(2)) : '<span class="mmi-text-muted">—</span>'}</td>
                <td class="mmi-text-muted">${esc(l.source)}</td>
            </tr>`).join(''));

            const weighed = lines.filter((l) => l.weight !== null);
            const totalLb = weighed.reduce((sum, l) => sum + l.weight * l.qty, 0);
            const missingWeight = lines.length - weighed.length;
            const sized = lines.filter((l) => l.dims);
            const parts = [];
            this.boxGuess = null;
            this.weightGuess = weighed.length ? Math.round(totalLb * 100) / 100 : null;

            if (weighed.length) {
                parts.push(`<strong>${esc(missingWeight
                    ? fmt(MESSAGES.totalWeightPartial, { weight: totalLb.toFixed(2), missing: missingWeight })
                    : fmt(MESSAGES.totalWeight, { weight: totalLb.toFixed(2) }))}</strong>`);
                parts.push(`<button type="button" class="button button-small" id="mmi-po-use-weight">${esc(MESSAGES.useWeight)}</button>`);
            }
            if (sized.length) {
                const largest = [0, 0, 0];
                let volume = 0;
                sized.forEach((l) => {
                    const sides = l.dims.map(Number).sort((a, b) => b - a);
                    sides.forEach((s, i) => { largest[i] = Math.max(largest[i], s); });
                    volume += sides[0] * sides[1] * sides[2] * l.qty;
                });
                const depth = Math.max(largest[2], volume / (largest[0] * largest[1]));
                const pad = 2 * BOX.PADDING_IN;
                this.boxGuess = [largest[0] + pad, largest[1] + pad, Math.ceil(depth) + pad];
                parts.push(esc(fmt(MESSAGES.largestItem, { dims: largest.join(' × ') })));
                parts.push(`<strong>${esc(fmt(MESSAGES.suggestedBox, { dims: this.boxGuess.join(' × ') }))}</strong>`);
                parts.push(`<button type="button" class="button button-small" id="mmi-po-use-box">${esc(MESSAGES.useBox)}</button>`);
            } else {
                parts.push(`<span class="mmi-text-muted">${esc(MESSAGES.noDims)}</span>`);
            }
            $summary.html(parts.join(''));
        },

        applyWeightGuess() {
            if (this.weightGuess === null) {
                return;
            }
            const oz = Math.round(this.weightGuess * 16 * 10) / 10;
            $(SELECTORS.PKG_INPUT).filter('[data-pkg="weight_lb"]').val(Math.floor(oz / 16));
            $(SELECTORS.PKG_INPUT).filter('[data-pkg="weight_oz_part"]').val(Math.round((oz % 16) * 10) / 10);
            this.markDirty();
        },

        applyBoxGuess() {
            if (!this.boxGuess) {
                return;
            }
            ['length', 'width', 'height'].forEach((key, i) => {
                $(SELECTORS.PKG_INPUT).filter(`[data-pkg="${key}"]`).val(this.boxGuess[i]);
            });
            this.markDirty();
        },

        flash($row) {
            $row.addClass('mmi-po-flash');
            setTimeout(() => $row.removeClass('mmi-po-flash'), 1200);
        },

        /* Header, history, messages */

        renderHeader() {
            const o = this.order;
            const saved = o.id > 0;
            $(SELECTORS.NUMBER).text(saved ? o.po_number : 'New purchase order');
            $(SELECTORS.STATUS)
                .attr('class', `mmi-badge ${STATUS_BADGES[o.status] || 'info'}`)
                .text(o.status_label || 'Draft');
            $([SELECTORS.PREVIEW, SELECTORS.DOWNLOAD, SELECTORS.EMAIL, SELECTORS.STATUS_SELECT, SELECTORS.DUPLICATE].join(',')).prop('disabled', !saved);
            $(SELECTORS.DELETE).prop('hidden', !(saved && o.status === 'draft' && !o.sent_at));
            $(SELECTORS.STATUS_SELECT).val('');
        },

        renderHistory() {
            const rows = this.order.history || [];
            $(SELECTORS.HISTORY_SECTION).prop('hidden', rows.length === 0);
            $(SELECTORS.HISTORY).html(rows.map((r) => {
                const file = r.file_url
                    ? ` <a target="_blank" rel="noopener" href="${esc(r.file_url)}"><span class="dashicons dashicons-media-document"></span> ${esc(MESSAGES.historyFile)}</a>`
                    : '';
                return `<li><span class="mmi-po-history-when">${esc(r.created_at)}</span>
                    <strong>${esc(r.label)}</strong> ${esc(r.message || '')}${file}
                    <span class="mmi-text-muted">${esc(r.user_name || '')}</span></li>`;
            }).join(''));
        },

        message(text, type) {
            const $msg = $(SELECTORS.MESSAGE);
            $msg.attr('class', `mmi-po-message mmi-result-card mmi-result-card--${type || 'success'}`).text(text).prop('hidden', false);
            clearTimeout(this.messageTimer);
            if (type !== 'error') {
                this.messageTimer = setTimeout(() => $msg.prop('hidden', true), TIMING.MESSAGE_HIDE_MS);
            }
        },

        markDirty() {
            this.dirty = true;
            $(SELECTORS.DIRTY).prop('hidden', false);
            $(SELECTORS.LABEL_BLOCKER).prop('hidden', true);
        },

        applyResponse(data) {
            this.order = data.order;
            CFG.pdfUrl = data.pdfUrl;
            CFG.downloadUrl = data.downloadUrl;
            this.dirty = false;
            $(SELECTORS.DIRTY).prop('hidden', true);
            if (window.location.href !== data.editUrl) {
                window.history.replaceState(null, '', data.editUrl);
            }
            $(SELECTORS.NUMBER_INPUT).val(this.order.po_number).prop('disabled', !!this.order.sent_at);
            this.renderHeader();
            this.renderHistory();
            this.renderShipping();
        },

        /* Actions */

        save() {
            const $btn = $(SELECTORS.SAVE);
            setBusy($btn, true, LABELS.SAVE_IDLE, LABELS.SAVE_BUSY);
            const payload = {
                id: this.order.id,
                supplier_id: Number($(SELECTORS.SUPPLIER).val()) || 0,
                po_date: $(SELECTORS.DATE).val(),
                po_number: $(SELECTORS.NUMBER_INPUT).val().trim(),
                ship_to_fields: this.collectAddress(),
                wc_order_id: this.dest() === 'customer' ? (Number(this.order.wc_order_id) || 0) : 0,
                label_mode: this.labelMode(),
                package: this.collectPackage(),
                instructions: $(SELECTORS.INSTRUCTIONS).val(),
                notes: $(SELECTORS.NOTES).val(),
                tax: num($(SELECTORS.TAX).val()),
                shipping: num($(SELECTORS.SHIPPING).val()),
                items: this.collectItems(),
            };
            return ajax('save', { payload: JSON.stringify(payload) })
                .then((data) => {
                    this.applyResponse(data);
                    this.message(fmt(MESSAGES.saved, { number: data.order.po_number }));
                    return data;
                })
                .catch((msg) => {
                    this.message(msg, 'error');
                    throw msg;
                })
                .finally(() => setBusy($btn, false, LABELS.SAVE_IDLE, LABELS.SAVE_BUSY));
        },

        saveIfDirty() {
            return this.dirty || !this.order.id ? this.save() : Promise.resolve();
        },

        /** Opens the tab synchronously (popup blockers allow only that), then points it at the PDF. */
        openPdf(download) {
            const win = download ? null : window.open('', '_blank');
            this.saveIfDirty()
                .then(() => {
                    const url = download ? CFG.downloadUrl : CFG.pdfUrl;
                    if (win) {
                        win.location.href = url;
                    } else {
                        window.location.href = url;
                    }
                })
                .catch(() => { if (win) { win.close(); } });
        },

        openEmail() {
            if (!this.supplier()) {
                this.message(MESSAGES.needSupplier, 'error');
                return;
            }
            if (!this.collectItems().length) {
                this.message(MESSAGES.needLines, 'error');
                return;
            }
            this.saveIfDirty()
                .then(() => ajax('email_draft', { id: this.order.id }))
                .then((draft) => {
                    $(SELECTORS.EMAIL_TO).val(draft.to);
                    $(SELECTORS.EMAIL_CC).val(draft.cc);
                    $(SELECTORS.EMAIL_SUBJECT).val(draft.subject);
                    $(SELECTORS.EMAIL_BODY).val(draft.body);
                    $(SELECTORS.EMAIL_REMEMBER_WRAP).prop('hidden', draft.to !== '');
                    $(SELECTORS.ATTACH_LABEL_WRAP).prop('hidden', !draft.label);
                    $(SELECTORS.ATTACH_LABEL).prop('checked', !!draft.label);
                    $(SELECTORS.ATTACH_LABEL_TEXT).text(draft.label
                        ? fmt(MESSAGES.attachLabel, { service: draft.label.service_name, tracking: draft.label.tracking_number || '—' })
                        : '');
                    $(SELECTORS.EMAIL_ERROR).prop('hidden', true).empty();
                    window.MMIModal.open($(SELECTORS.EMAIL_MODAL));
                })
                .catch((msg) => this.message(msg, 'error'));
        },

        send() {
            const $btn = $(SELECTORS.EMAIL_SEND);
            const $error = $(SELECTORS.EMAIL_ERROR).prop('hidden', true);
            setBusy($btn, true, LABELS.SEND_IDLE, LABELS.SEND_BUSY);
            ajax('send', {
                id: this.order.id,
                to: $(SELECTORS.EMAIL_TO).val(),
                cc: $(SELECTORS.EMAIL_CC).val(),
                subject: $(SELECTORS.EMAIL_SUBJECT).val(),
                body: $(SELECTORS.EMAIL_BODY).val(),
                remember: !$(SELECTORS.EMAIL_REMEMBER_WRAP).prop('hidden') && $(SELECTORS.EMAIL_REMEMBER).is(':checked') ? 1 : 0,
                attach_label: !$(SELECTORS.ATTACH_LABEL_WRAP).prop('hidden') && $(SELECTORS.ATTACH_LABEL).is(':checked') ? 1 : 0,
            })
                .then((data) => {
                    window.MMIModal.close($(SELECTORS.EMAIL_MODAL));
                    this.applyResponse(data);
                    this.message(fmt(MESSAGES.sent, { number: data.order.po_number, to: data.order.sent_to }));
                })
                .catch((msg) => $error.text(msg).prop('hidden', false))
                .finally(() => setBusy($btn, false, LABELS.SEND_IDLE, LABELS.SEND_BUSY));
        },

        changeStatus() {
            const status = $(SELECTORS.STATUS_SELECT).val();
            if (!status) {
                return;
            }
            ajax('set_status', { id: this.order.id, status })
                .then((data) => {
                    const dirty = this.dirty;
                    this.applyResponse(data);
                    if (dirty) {
                        this.markDirty(); // Status change does not save pending edits.
                    }
                    this.message(fmt(MESSAGES.statusChanged, { status: data.order.status_label }));
                })
                .catch((msg) => { this.message(msg, 'error'); $(SELECTORS.STATUS_SELECT).val(''); });
        },

        duplicate() {
            this.saveIfDirty()
                .then(() => ajax('duplicate', { id: this.order.id }))
                .then((data) => { window.location.href = data.editUrl; })
                .catch((msg) => this.message(msg, 'error'));
        },

        remove() {
            if (!window.confirm(fmt(MESSAGES.confirmDelete, { number: this.order.po_number }))) {
                return;
            }
            ajax('delete', { id: this.order.id })
                .then((data) => { this.dirty = false; window.location.href = data.redirect; })
                .catch((msg) => this.message(msg, 'error'));
        },
    };

    /* ── Suppliers ─────────────────────────────────────────────────────── */

    const Suppliers = {
        init() {
            this.render();
            if (window.MMIModal) {
                window.MMIModal.init();
            }
            initSort(SELECTORS.SUPPLIERS_TABLE);
            $(SELECTORS.SUPPLIER_ADD).on('click', () => this.open(null));
            $(document).on('click', SELECTORS.SUPPLIER_EDIT, (e) => {
                const id = Number($(e.currentTarget).data('id'));
                this.open(CFG.suppliers.find((s) => s.id === id) || null);
            });
            $(SELECTORS.BRAND_FILTER).on('input', () => this.filterBrands());
            $(SELECTORS.BRAND_LIST).on('change', 'input', () => this.countBrands());
            $(SELECTORS.BRAND_TRIGGER).on('click', () => this.toggleBrands());
            $(document).on('click', (e) => {
                if (!$(e.target).closest(SELECTORS.BRAND_MS).length) {
                    this.toggleBrands(false);
                }
            });
            $(SELECTORS.BRAND_MS).on('keydown', (e) => {
                if (e.key === 'Escape' && !$(SELECTORS.BRAND_MENU).prop('hidden')) {
                    e.stopPropagation(); // Close the dropdown, not the modal.
                    this.toggleBrands(false);
                    $(SELECTORS.BRAND_TRIGGER).trigger('focus');
                }
            });
            $(SELECTORS.SUPPLIER_SAVE).on('click', () => this.save());
        },

        render() {
            const list = CFG.suppliers || [];
            if (!list.length) {
                $(SELECTORS.SUPPLIERS_BODY).html(`<tr><td colspan="7">${esc(MESSAGES.noSuppliers)}</td></tr>`);
            } else {
                $(SELECTORS.SUPPLIERS_BODY).html(list.map((s) => `<tr>
                    <td data-sort-key="name"><strong>${esc(s.name)}</strong>${s.contact_name ? `<br><span class="mmi-text-muted">${esc(s.contact_name)}</span>` : ''}</td>
                    <td data-sort-key="email">${s.email ? esc(s.email) : '<span class="mmi-text-warning">—</span>'}</td>
                    <td class="mmi-po-address" data-sort-key="address">${esc(s.address)}</td>
                    <td data-sort-key="brands">${esc(s.brands.join(', '))}</td>
                    <td data-sort-key="labels">${s.label_policy === 'supplier_only' ? '<span class="mmi-badge warning">Their own</span>' : '<span class="mmi-badge info">Ours OK</span>'}</td>
                    <td data-sort-key="status"><span class="mmi-badge ${s.active ? 'success' : 'warning'}">${s.active ? 'Active' : 'Inactive'}</span></td>
                    <td><button type="button" class="button button-small mmi-po-supplier-edit" data-id="${s.id}">Edit</button></td>
                </tr>`).join(''));
            }
            this.renderOverlap();
        },

        renderOverlap() {
            const owners = {};
            (CFG.suppliers || []).filter((s) => s.active).forEach((s) => {
                s.brand_ids.forEach((id, i) => {
                    owners[id] = owners[id] || { name: s.brands[i] || String(id), count: 0 };
                    owners[id].count += 1;
                });
            });
            const shared = Object.values(owners).filter((o) => o.count > 1).map((o) => o.name);
            $(SELECTORS.BRAND_OVERLAP)
                .text(shared.length ? fmt(MESSAGES.overlap, { brands: shared.join(', ') }) : '')
                .prop('hidden', shared.length === 0);
        },

        open(supplier) {
            const s = supplier || { id: 0, name: '', contact_name: '', email: '', cc: '', phone: '', account_number: '', instructions: '', brand_ids: [], active: true, label_policy: 'any', address_fields: { country: 'US' } };
            const $form = $(SELECTORS.SUPPLIER_FORM);
            $form.get(0).reset();
            ['id', 'name', 'contact_name', 'email', 'cc', 'phone', 'account_number', 'instructions'].forEach((key) => {
                $form.find(`[name="${key}"]`).val(s[key] || (key === 'id' ? 0 : ''));
            });
            ['street1', 'street2', 'city', 'state', 'postal_code', 'country'].forEach((key) => {
                $form.find(`[name="address_fields[${key}]"]`).val((s.address_fields || {})[key] || (key === 'country' ? 'US' : ''));
            });
            $form.find('[name="label_policy"]').val(s.label_policy || 'any');
            $form.find('[name="active"]').prop('checked', !!s.active);
            $(SELECTORS.SUPPLIER_TITLE).text(s.id ? s.name : 'Add supplier');
            $(SELECTORS.SUPPLIER_ERROR).prop('hidden', true).empty();
            $(SELECTORS.BRAND_FILTER).val('');
            this.renderBrands(s.brand_ids);
            window.MMIModal.open($(SELECTORS.SUPPLIER_MODAL));
        },

        /** Checked brands first, then the rest alphabetically. */
        renderBrands(selectedIds) {
            const selected = new Set(selectedIds.map(Number));
            const brands = (CFG.brands || []).slice().sort((a, b) => (selected.has(b.id) - selected.has(a.id)) || a.name.localeCompare(b.name));
            $(SELECTORS.BRAND_LIST).html(brands.map((b) => `<label class="mmi-ms-option mmi-ms-option--check" data-name="${esc(b.name.toLowerCase())}">
                <input type="checkbox" name="brand_ids[]" value="${b.id}" ${selected.has(b.id) ? 'checked' : ''}>
                <span class="mmi-ms-opt-label">${esc(b.name)}</span></label>`).join(''));
            this.toggleBrands(false);
            this.countBrands();
        },

        toggleBrands(open) {
            const $menu = $(SELECTORS.BRAND_MENU);
            const show = typeof open === 'boolean' ? open : $menu.prop('hidden');
            $menu.prop('hidden', !show);
            $(SELECTORS.BRAND_MS).toggleClass('is-open', show);
            $(SELECTORS.BRAND_TRIGGER).attr('aria-expanded', show ? 'true' : 'false');
            if (show) {
                $(SELECTORS.BRAND_FILTER).trigger('focus');
            }
        },

        filterBrands() {
            const q = $(SELECTORS.BRAND_FILTER).val().trim().toLowerCase();
            $(SELECTORS.BRAND_LIST).find('.mmi-ms-option').each(function () {
                this.hidden = q !== '' && this.dataset.name.indexOf(q) === -1;
            });
        },

        /** Trigger shows the chosen brand names (first three, then "+N more") and a count badge. */
        countBrands() {
            const names = $(SELECTORS.BRAND_LIST).find('input:checked').get()
                .map((el) => $(el).siblings('.mmi-ms-opt-label').text());
            const shown = names.slice(0, 3).join(', ');
            $(SELECTORS.BRAND_SUMMARY).text(!names.length ? MESSAGES.noBrands
                : (names.length > 3 ? fmt(MESSAGES.moreBrands, { names: shown, more: names.length - 3 }) : shown));
            $(SELECTORS.BRAND_COUNT).text(names.length).prop('hidden', names.length === 0);
        },

        save() {
            const $btn = $(SELECTORS.SUPPLIER_SAVE);
            const data = {};
            $(SELECTORS.SUPPLIER_FORM).serializeArray().forEach(({ name, value }) => {
                if (name === 'brand_ids[]') {
                    (data.brand_ids = data.brand_ids || []).push(value);
                } else {
                    data[name] = value;
                }
            });
            setBusy($btn, true, LABELS.SUPPLIER_IDLE, LABELS.SUPPLIER_BUSY);
            ajax('save_supplier', data)
                .then((res) => {
                    CFG.suppliers = res.suppliers;
                    this.render();
                    window.MMIModal.close($(SELECTORS.SUPPLIER_MODAL));
                })
                .catch((msg) => $(SELECTORS.SUPPLIER_ERROR).text(msg).prop('hidden', false))
                .finally(() => setBusy($btn, false, LABELS.SUPPLIER_IDLE, LABELS.SUPPLIER_BUSY));
        },
    };

    /* ── Settings ──────────────────────────────────────────────────────── */

    function initSettings() {
        let frame = null;
        $(SELECTORS.LOGO_PICK).on('click', () => {
            if (!window.wp || !window.wp.media) {
                return;
            }
            if (!frame) {
                frame = window.wp.media({ title: 'Purchase order logo', library: { type: 'image' }, multiple: false });
                frame.on('select', () => {
                    const file = frame.state().get('selection').first().toJSON();
                    $(SELECTORS.LOGO_ID).val(file.id);
                    $(SELECTORS.LOGO_IMG).attr('src', (file.sizes && file.sizes.medium ? file.sizes.medium.url : file.url));
                });
            }
            frame.open();
        });
        $(SELECTORS.SS_TEST).on('click', function () {
            const $btn = $(this);
            const $box = $(SELECTORS.SS_CARRIERS);
            setBusy($btn, true, LABELS.TEST_IDLE, LABELS.TEST_BUSY);
            ajax('ss_carriers', {})
                .then((data) => {
                    const selected = new Set(data.selected);
                    $box.html(data.carriers.map((c) => `<label class="mmi-po-inline"><input type="checkbox" name="ss_carriers[]" value="${esc(c.code)}" ${selected.has(c.code) ? 'checked' : ''}> ${esc(c.name)} <span class="mmi-text-muted">${esc(c.code)}${c.balance !== null && c.funded ? ` · balance ${esc(money(c.balance))}` : ''}</span></label>`).join(''));
                    $(SELECTORS.SS_STATUS).removeClass('mmi-text-error').text(data.carriers.length ? fmt(MESSAGES.ssConnected, { count: data.carriers.length }) : MESSAGES.ssNoCarriers);
                })
                .catch((msg) => $(SELECTORS.SS_STATUS).addClass('mmi-text-error').text(msg))
                .finally(() => setBusy($btn, false, LABELS.TEST_IDLE, LABELS.TEST_BUSY));
        });
        $(SELECTORS.LOGO_RESET).on('click', function () {
            $(SELECTORS.LOGO_ID).val(0);
            $(SELECTORS.LOGO_IMG).attr('src', $(this).data('default'));
        });
    }

    /* ── Boot ──────────────────────────────────────────────────────────── */

    $(function () {
        switch (CFG.tab) {
            case 'edit':
                if ($(SELECTORS.EDITOR).length) {
                    Editor.init();
                }
                break;
            case 'suppliers':
                Suppliers.init();
                break;
            case 'activity':
                initSort(SELECTORS.ACTIVITY_TABLE);
                break;
            case 'settings':
                initSettings();
                break;
            default:
                initOrders();
        }
    });
})(jQuery);
