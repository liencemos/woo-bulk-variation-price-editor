/* Placeholder admin script for WBV pricer */
(function () {
    if (typeof window === 'undefined') return;

    const restRoot = (typeof wbvPricer !== 'undefined' && wbvPricer.rest_root) ? wbvPricer.rest_root : '/wp-json/wbvpricer/v1';
    const nonce = (typeof wbvPricer !== 'undefined' && wbvPricer.nonce) ? wbvPricer.nonce : '';

    function qs(selector) { return document.querySelector(selector); }

    function renderProducts(container, products) {
        container.innerHTML = '';
        if (!products || products.length === 0) {
            const p = document.createElement('p');
            p.textContent = (wbvPricer && wbvPricer.i18n && wbvPricer.i18n.noProducts) ? wbvPricer.i18n.noProducts : 'No products found';
            container.appendChild(p);
            return;
        }
        products.forEach(product => {
            const wrap = document.createElement('div');
            wrap.className = 'wbv-product';
            wrap.dataset.productId = product.product_id;

            const header = document.createElement('div');
            header.className = 'wbv-product-header';
            const productCheckbox = document.createElement('input');
            productCheckbox.type = 'checkbox';
            productCheckbox.className = 'wbv-select-product';
            productCheckbox.dataset.productId = product.product_id;
            header.appendChild(productCheckbox);

            const title = document.createElement('h3');
            title.textContent = product.title + (product.sku ? ' — ' + product.sku : '');
            header.appendChild(title);

            wrap.appendChild(header);

            const table = document.createElement('table');
            table.className = 'wbv-variations';
            const thead = document.createElement('thead');
            thead.innerHTML = '<tr><th></th><th>SKU</th><th>Attributes</th><th>Regular</th><th>Sale</th></tr>';
            table.appendChild(thead);
            const tbody = document.createElement('tbody');

            product.variations.forEach(v => {
                const tr = document.createElement('tr');

                const tdCheck = document.createElement('td');
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'wbv-select-variation';
                cb.dataset.vid = v.variation_id;
                cb.dataset.sku = v.sku || '';
                const attrsText = v.attributes.map(a => a.label + ': ' + a.value).join(', ');
                cb.dataset.attrs = encodeURIComponent(attrsText);
                cb.dataset.regular = v.regular_price || '';
                cb.dataset.sale = v.sale_price || '';
                cb.dataset.productId = product.product_id;
                tdCheck.appendChild(cb);
                tr.appendChild(tdCheck);

                const tdSku = document.createElement('td'); tdSku.textContent = v.sku || ''; tr.appendChild(tdSku);
                const tdAttrs = document.createElement('td'); tdAttrs.textContent = attrsText; tr.appendChild(tdAttrs);
                const tdRegular = document.createElement('td'); tdRegular.textContent = v.regular_price || ''; tr.appendChild(tdRegular);
                const tdSale = document.createElement('td'); tdSale.textContent = v.sale_price || ''; tr.appendChild(tdSale);

                tbody.appendChild(tr);
            });

            table.appendChild(tbody);
            wrap.appendChild(table);
            container.appendChild(wrap);
        });
    }

    function setupAttributeSelectChangeHandler(sel, valSel) {
        sel.addEventListener('change', function () {
            const selected = Array.from(sel.selectedOptions).map(o => o.value).filter(Boolean);
            valSel.innerHTML = '';
            if (!selected.length) {
                valSel.disabled = true;
                const o = document.createElement('option'); o.value = ''; o.textContent = 'All values'; o.disabled = true; valSel.appendChild(o);
                renderActiveFilters();
                return;
            }

            // Build optgroups for each selected attribute
            selected.forEach(tax => {
                // prefer canonical REST attribute list
                const found = wbvAttributes.find(x => (x.taxonomy === tax || x.attribute_name === tax));
                if (found) {
                    const group = document.createElement('optgroup'); group.label = found.label || found.attribute_name;
                    if (found.terms && found.terms.length) {
                        found.terms.forEach(t => {
                            const o = document.createElement('option');
                            o.value = (found.taxonomy || found.attribute_name) + '|' + t.slug; // encode pair
                            o.textContent = t.name;
                            group.appendChild(o);
                        });
                    }
                    valSel.appendChild(group);
                    return;
                }

                // fallback created from products
                if (wbvAttributeFallbackMap[tax]) {
                    const group = document.createElement('optgroup'); group.label = wbvAttributeFallbackMap[tax].label || tax;
                    Array.from(wbvAttributeFallbackMap[tax].values).forEach(v => {
                        const o = document.createElement('option');
                        // pass display value; server will resolve by name if needed
                        o.value = tax + '|' + v;
                        o.textContent = v;
                        group.appendChild(o);
                    });
                    valSel.appendChild(group);
                    return;
                }

                // No values for this taxonomy
                const o = document.createElement('option'); o.value = ''; o.textContent = 'No values'; o.disabled = true; valSel.appendChild(o);
            });

            valSel.disabled = false;
            if (qs('#wbv-attribute-value-text')) { qs('#wbv-attribute-value-text').disabled = false; }
            renderActiveFilters();
        });
    }

    function populateAttributeSelectorsFromProducts(products) {
        if (!Array.isArray(products)) return;
        const sel = qs('#wbv-attribute');
        const valSel = qs('#wbv-attribute-value');
        if (!sel) return;

        // build map taxonomy -> { label, values: Set }
        wbvAttributeFallbackMap = {};
        products.forEach(p => {
            if (!p.variations) return;
            p.variations.forEach(v => {
                if (!v.attributes) return;
                v.attributes.forEach(a => {
                    const tax = a.key || ''; const label = a.label || tax; const val = a.value || '';
                    if (!tax || !val) return;
                    if (!wbvAttributeFallbackMap[tax]) wbvAttributeFallbackMap[tax] = { label: label, values: new Set() };
                    wbvAttributeFallbackMap[tax].values.add(val);
                });
            });
        });

        // populate select with detected taxonomies
        sel.innerHTML = '<option value="" disabled>All attributes</option>';
        Object.keys(wbvAttributeFallbackMap).forEach(tax => {
            const opt = document.createElement('option'); opt.value = tax; opt.textContent = wbvAttributeFallbackMap[tax].label || tax; sel.appendChild(opt);
        });

        // attach change handler
        setupAttributeSelectChangeHandler(sel, valSel);
    }

    async function performSearch(q, per_page, page, attributePairs, attributeOp) {
        const params = new URLSearchParams();
        if (q) params.append('q', q);
        params.append('per_page', per_page);
        params.append('page', page);
        if (attributeOp) params.append('attribute_operator', attributeOp);
        if (Array.isArray(attributePairs)) {
            attributePairs.forEach(p => { params.append('attribute_pairs[]', p); });
        }
        const url = `${restRoot}/search?${params.toString()}`;
        const res = await fetch(url, {
            headers: {
                'X-WP-Nonce': nonce,
            }
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({ message: 'Network error' }));
            throw new Error(err.message || 'REST error');
        }

        return res.json();
    }

    function setupHandlers() {
        const btn = qs('#wbv-search-btn');
        const search = qs('#wbv-search');
        const per = qs('#wbv-per-page');
        const results = qs('#wbv-results');

        let currentPage = 1;

        async function doSearch(page) {
            const q = search.value.trim();
            const perPage = per.value;
            results.innerHTML = `<p>${(wbvPricer && wbvPricer.i18n && wbvPricer.i18n.searching) ? wbvPricer.i18n.searching : 'Searching…'}</p>`;
            try {
                const attributePairs = qs('#wbv-attribute-value') ? Array.from(qs('#wbv-attribute-value').selectedOptions).map(o => o.value).filter(Boolean) : [];
                const attributeText = qs('#wbv-attribute-value-text') ? qs('#wbv-attribute-value-text').value.trim() : '';
                const attributeOp = qs('#wbv-attribute-op') ? qs('#wbv-attribute-op').value : 'and';
                // If text provided, add taxonomy|text for each selected taxonomy
                if (attributeText) {
                    const selectedTax = qs('#wbv-attribute') ? Array.from(qs('#wbv-attribute').selectedOptions).map(o => o.value).filter(Boolean) : [];
                    if (selectedTax.length) {
                        selectedTax.forEach(t => {
                            attributePairs.push(t + '|' + attributeText);
                        });
                    } else {
                        // No taxonomy selected: search across all known attributes
                        (wbvAttributes || []).forEach(a => {
                            const tax = a.taxonomy || a.attribute_name;
                            if (tax) attributePairs.push(tax + '|' + attributeText);
                        });
                    }
                }
                const data = await performSearch(q, perPage, page, attributePairs, attributeOp);
                lastProducts = data.products || [];
                renderProducts(results, lastProducts);
                // After rendering products re-attach any event handlers for checkboxes
                attachProductHandlers();
                // If the attributes REST endpoint produced no attributes, populate selects from products
                if ((!wbvAttributes || wbvAttributes.length === 0) && lastProducts && lastProducts.length) {
                    populateAttributeSelectorsFromProducts(lastProducts);
                }
                // Re-attempt fetching attributes after search completes so any REST errors appear in DevTools
                if (!wbvAttributes || wbvAttributes.length === 0) {
                    loadAttributes();
                }
            } catch (e) {
                results.innerHTML = `<p class="wbv-error">${e.message}</p>`;
            }
        }

        btn.addEventListener('click', function (e) { e.preventDefault(); currentPage = 1; doSearch(currentPage); });
        search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); currentPage = 1; doSearch(currentPage); } });

        const previewBtn = qs('#wbv-preview-btn');
        const applyBtn = qs('#wbv-apply-btn');
        const quickCreateBtn = qs('#wbv-quick-create-variations-btn');
        const exportBtn = qs('#wbv-export-csv');
        const bulkFieldsBtn = qs('#wbv-bulk-fields-apply-btn');

        previewBtn.addEventListener('click', function (e) {
            e.preventDefault();
            handlePreview();
        });

        applyBtn.addEventListener('click', function (e) {
            e.preventDefault();
            handleApply();
        });
        if (quickCreateBtn) {
            quickCreateBtn.addEventListener('click', function (e) {
                e.preventDefault();
                handleQuickCreateVariations();
            });
        }

        exportBtn.addEventListener('click', function (e) {
            e.preventDefault();
            handleExportCSV();
        });
        if (bulkFieldsBtn) {
            bulkFieldsBtn.addEventListener('click', function (e) {
                e.preventDefault();
                handleBulkFieldUpdate();
            });
        }

        // Global select-all visible checkbox (attach once)
        const globalSelect = qs('#wbv-select-all-visible');
        if (globalSelect) {
            globalSelect.addEventListener('change', function () {
                const checked = this.checked;
                document.querySelectorAll('.wbv-select-variation').forEach(vcb => vcb.checked = checked);
                document.querySelectorAll('.wbv-select-product').forEach(pcb => pcb.checked = checked);
            });
        }

        // Attempt to load attributes first (so REST errors surface early), then operations and initial search
        loadAttributes();
        loadOperations();
        // initial empty search
        doSearch(currentPage);
    }

    // Cache attributes fetched from server
    let wbvAttributes = [];
    // Keep last search products for fallback population
    let lastProducts = [];
    // Fallback map built from search results: { taxonomy: { label: '', values: Set() } }
    let wbvAttributeFallbackMap = {};
    // How many times we've attempted to fetch attributes from REST
    let attributeFetchAttempts = 0;
    async function loadAttributes() {
        const sel = qs('#wbv-attribute');
        let valSel = qs('#wbv-attribute-value');
        if (!sel) {
            console.debug('WBV: attribute select not found in DOM; aborting loadAttributes');
            return;
        }
        // If value select is missing, create it next to attribute select so behavior is consistent
        if (!valSel) {
            valSel = document.createElement('select');
            valSel.id = 'wbv-attribute-value';
            valSel.multiple = true;
            valSel.disabled = true;
            valSel.size = 3;
            sel.parentNode.insertBefore(valSel, sel.nextSibling);
            console.debug('WBV: created missing attribute value select element');
        }

        try {
            console.debug('WBV: fetching attributes from', `${restRoot}/attributes`);
            const res = await fetch(`${restRoot}/attributes`, { headers: { 'X-WP-Nonce': nonce } });
            console.debug('WBV: attributes fetch status', res.status);
            if (!res.ok) {
                console.warn('WBV: attributes fetch failed', res.status);
                showAttributeFetchError(res.status);
                // Try to populate from last search results
                if (lastProducts && lastProducts.length) {
                    populateAttributeSelectorsFromProducts(lastProducts);
                }
                return;
            }
            const json = await res.json();
            wbvAttributes = json.attributes || [];

            // populate attribute select
            if (wbvAttributes.length) {
                sel.innerHTML = '<option value="" disabled>All attributes</option>';
                wbvAttributes.forEach(a => {
                    const opt = document.createElement('option');
                    opt.value = a.taxonomy || a.attribute_name;
                    opt.textContent = a.label || a.attribute_name;
                    sel.appendChild(opt);
                });
            } else {
                // No attributes from REST: fallback to products
                if (lastProducts && lastProducts.length) {
                    populateAttributeSelectorsFromProducts(lastProducts);
                } else {
                    sel.innerHTML = '<option value="" disabled>No attributes</option>';
                }
            }

            setupAttributeSelectChangeHandler(sel, valSel);
        } catch (e) {
            // ignore and try fallback
            console.error('WBV: attributes fetch exception', e);
            showAttributeFetchError(0);
            if (lastProducts && lastProducts.length) {
                populateAttributeSelectorsFromProducts(lastProducts);
            }
        }
    }

    function showAttributeFetchError(status) {
        const errEl = qs('#wbv-attribute-error');
        if (!errEl) return;
        let msg = wbvPricer && wbvPricer.i18n && wbvPricer.i18n.attributesFetchFailed ? wbvPricer.i18n.attributesFetchFailed : 'Could not fetch attributes.';
        if (status === 403 && wbvPricer && wbvPricer.i18n && wbvPricer.i18n.attributesFetchForbidden) {
            msg = wbvPricer.i18n.attributesFetchForbidden;
        } else if (status >= 500 && wbvPricer && wbvPricer.i18n && wbvPricer.i18n.attributesFetchServerError) {
            msg = wbvPricer.i18n.attributesFetchServerError.replace('%d', status);
        }

        errEl.innerHTML = '';
        const span = document.createElement('span'); span.textContent = msg; span.style.marginRight = '0.5rem'; errEl.appendChild(span);
        const retry = document.createElement('button'); retry.className = 'button'; retry.textContent = (wbvPricer && wbvPricer.i18n && wbvPricer.i18n.attributesFetchRetry) ? wbvPricer.i18n.attributesFetchRetry : 'Retry';
        retry.addEventListener('click', function (e) { e.preventDefault(); errEl.innerHTML = ''; loadAttributes(); });
        errEl.appendChild(retry);
        // Show admin notice (non-intrusive)
        showAdminNotice(msg);
    }

    function showAdminNotice(message) {
        // Create a small non-blocking admin notice at the top of the page
        try {
            const container = document.querySelector('#wpcontent') || document.body;
            const n = document.createElement('div');
            n.className = 'notice notice-warning inline wbv-attr-notice';
            n.style.marginBottom = '0.5rem';
            n.textContent = message;
            // add small dismiss button
            const btn = document.createElement('button'); btn.className = 'notice-dismiss'; btn.type = 'button'; btn.addEventListener('click', function () { n.remove(); });
            n.appendChild(btn);
            // Insert before our app if present
            const app = qs('#wbv-app');
            if (app && app.parentNode) app.parentNode.insertBefore(n, app); else container.insertBefore(n, container.firstChild);
            // Remove after 10s
            setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, 10000);
        } catch (ex) {
            // noop
        }
    }

    function renderActiveFilters() {
        const container = qs('#wbv-active-filters');
        if (!container) return;

        const pairs = qs('#wbv-attribute-value') ? Array.from(qs('#wbv-attribute-value').selectedOptions).map(o => o.value).filter(Boolean) : [];
        const op = qs('#wbv-attribute-op') ? qs('#wbv-attribute-op').value : 'and';

        container.innerHTML = '';
        if (!pairs.length) {
            return;
        }

        // Group pairs by taxonomy for readable labels
        const groups = {};
        pairs.forEach(p => {
            const parts = p.split('|');
            if (parts.length !== 2) return;
            const tax = parts[0]; const slug = parts[1];
            if (!groups[tax]) groups[tax] = [];
            // find term name via canonical attribute list or fallback map
            let termName = slug;
            const foundAttr = wbvAttributes.find(a => (a.taxonomy === tax || a.attribute_name === tax));
            if (foundAttr && foundAttr.terms) {
                const ft = foundAttr.terms.find(t => t.slug === slug || t.name === slug);
                if (ft) termName = ft.name;
            } else if (wbvAttributeFallbackMap[tax]) {
                if (wbvAttributeFallbackMap[tax].values.has(slug)) {
                    termName = slug;
                }
            }
            groups[tax].push(termName);
        });

        const frag = document.createDocumentFragment();
        const span = document.createElement('span'); span.textContent = 'Filters: '; frag.appendChild(span);
        Object.keys(groups).forEach(tax => {
            const attrObj = wbvAttributes.find(a => (a.taxonomy === tax || a.attribute_name === tax));
            const label = (attrObj && attrObj.label) || (wbvAttributeFallbackMap[tax] && wbvAttributeFallbackMap[tax].label) || tax;
            const s = document.createElement('strong'); s.textContent = label + ': '; frag.appendChild(s);
            const txt = document.createElement('span'); txt.textContent = groups[tax].join(', ') + ' '; frag.appendChild(txt);
        });
        const opText = document.createElement('span'); opText.textContent = `(${op.toUpperCase()}) `; frag.appendChild(opText);

        const attributeText = qs('#wbv-attribute-value-text') ? qs('#wbv-attribute-value-text').value.trim() : '';
        if (attributeText) {
            const t = document.createElement('em'); t.textContent = `Name contains: "${attributeText}" `; frag.appendChild(t);
        }

        const clearBtn = document.createElement('button'); clearBtn.className = 'button'; clearBtn.textContent = 'Clear filters';
        clearBtn.addEventListener('click', function (e) {
            e.preventDefault();
            // reset selects
            if (qs('#wbv-attribute')) {
                Array.from(qs('#wbv-attribute').options).forEach(o => o.selected = false);
            }
            if (qs('#wbv-attribute-value')) {
                qs('#wbv-attribute-value').innerHTML = '<option value="" disabled>All values</option>'; qs('#wbv-attribute-value').disabled = true;
            }
            if (qs('#wbv-attribute-value-text')) { qs('#wbv-attribute-value-text').value = ''; qs('#wbv-attribute-value-text').disabled = true; }
            if (qs('#wbv-attribute-op')) qs('#wbv-attribute-op').value = 'and';
            container.innerHTML = '';
            doSearch(1);
        });
        frag.appendChild(clearBtn);

        container.appendChild(frag);
    }

    function handleExportCSV() {
        const selected = getSelectedVariationIds();
        if (selected.length === 0) {
            alert((wbvPricer && wbvPricer.i18n && wbvPricer.i18n.noSelection) ? wbvPricer.i18n.noSelection : 'No variations selected');
            return;
        }

        const rows = [];
        document.querySelectorAll('.wbv-select-variation:checked').forEach(cb => {
            const vid = cb.getAttribute('data-vid');
            const sku = cb.getAttribute('data-sku') || '';
            const attrs = decodeURIComponent(cb.getAttribute('data-attrs') || '');
            const regular = cb.getAttribute('data-regular') || '';
            const sale = cb.getAttribute('data-sale') || '';
            rows.push([vid, sku, attrs, regular, sale]);
        });

        let csv = 'variation_id,sku,attributes,regular_price,sale_price\n';
        rows.forEach(r => {
            csv += r.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(',') + '\n';
        });

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'wbv-variations.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    function attachProductHandlers() {
        // Product-level checkboxes that toggle child variation checkboxes
        document.querySelectorAll('.wbv-select-product').forEach(cb => {
            cb.onclick = function () {
                const pid = this.dataset.productId;
                const checked = this.checked;
                document.querySelectorAll(`.wbv-select-variation[data-product-id="${pid}"]`).forEach(vcb => vcb.checked = checked);
            };
        });
    }

    function getSelectedVariationIds() {
        const checked = Array.from(document.querySelectorAll('.wbv-select-variation:checked'));
        return checked.map(c => parseInt(c.getAttribute('data-vid'), 10)).filter(Boolean);
    }

    function getSelectedProductIds() {
        const checked = Array.from(document.querySelectorAll('.wbv-select-product:checked'));
        return checked.map(c => parseInt(c.getAttribute('data-product-id'), 10)).filter(Boolean);
    }

    async function handleQuickCreateVariations() {
        const selectedProducts = getSelectedProductIds();
        if (selectedProducts.length === 0) {
            alert('Please select at least one product.');
            return;
        }

        const btn = qs('#wbv-quick-create-variations-btn');
        if (btn) {
            btn.disabled = true;
            btn.dataset._orig = btn.textContent;
            btn.textContent = 'Working…';
        }

        try {
            const attributeTaxonomy = (qs('#wbv-qc-attribute') && qs('#wbv-qc-attribute').value.trim()) ? qs('#wbv-qc-attribute').value.trim() : '';
            const valuesInput = (qs('#wbv-qc-values') && qs('#wbv-qc-values').value.trim()) ? qs('#wbv-qc-values').value.trim() : '';
            const regularPriceInput = qs('#wbv-qc-regular-price') ? qs('#wbv-qc-regular-price').value.trim() : '';
            const stockQtyInput = qs('#wbv-qc-stock-qty') ? qs('#wbv-qc-stock-qty').value.trim() : '';

            let endpoint = `${restRoot}/quick-create-variations`;
            let payload = { product_ids: selectedProducts, max_per_product: 50 };

            if (attributeTaxonomy && valuesInput) {
                const values = valuesInput.split(',').map(v => v.trim()).filter(Boolean);
                payload = {
                    product_ids: selectedProducts,
                    attribute_taxonomy: attributeTaxonomy,
                    values: values,
                };
                if (regularPriceInput !== '') payload.regular_price = regularPriceInput;
                if (stockQtyInput !== '') payload.stock_quantity = parseInt(stockQtyInput, 10);
                endpoint = `${restRoot}/quick-create-attribute-variations`;
            }

            const res = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (!res.ok) {
                throw new Error(data.message || 'Quick create failed');
            }
            alert(`Created ${data.created_total || 0} new variations.` + (data.results ? `\nProducts processed: ${data.results.length}` : ''));
            qs('#wbv-search-btn')?.click();
        } catch (e) {
            alert(e.message || 'Quick create failed');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = btn.dataset._orig || 'Quick Add Variations';
            }
        }
    }

    async function handleBulkFieldUpdate() {
        const selected = getSelectedVariationIds();
        if (selected.length === 0) {
            alert('Please select at least one variation.');
            return;
        }
        const fields = {
            sku_prefix: qs('#wbv-bf-sku-prefix') ? qs('#wbv-bf-sku-prefix').value.trim() : '',
            regular_price: qs('#wbv-bf-regular-price') ? qs('#wbv-bf-regular-price').value.trim() : '',
            sale_price: qs('#wbv-bf-sale-price') ? qs('#wbv-bf-sale-price').value.trim() : '',
            stock_quantity: qs('#wbv-bf-stock-qty') ? qs('#wbv-bf-stock-qty').value.trim() : '',
            stock_status: qs('#wbv-bf-stock-status') ? qs('#wbv-bf-stock-status').value : '',
            shipping_class_id: qs('#wbv-bf-shipping-class-id') ? qs('#wbv-bf-shipping-class-id').value.trim() : '',
            weight: qs('#wbv-bf-weight') ? qs('#wbv-bf-weight').value.trim() : '',
            length: qs('#wbv-bf-length') ? qs('#wbv-bf-length').value.trim() : '',
            width: qs('#wbv-bf-width') ? qs('#wbv-bf-width').value.trim() : '',
            height: qs('#wbv-bf-height') ? qs('#wbv-bf-height').value.trim() : '',
        };

        const btn = qs('#wbv-bulk-fields-apply-btn');
        if (btn) {
            btn.disabled = true;
            btn.dataset._orig = btn.textContent;
            btn.textContent = 'Working…';
        }

        try {
            const res = await fetch(`${restRoot}/bulk-update-fields`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify({ variation_ids: selected, fields: fields }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Bulk field update failed');
            alert(`Updated ${data.updated_count || 0} variations.` + (data.errors && data.errors.length ? ` Errors: ${data.errors.length}` : ''));
            qs('#wbv-search-btn')?.click();
        } catch (e) {
            alert(e.message || 'Bulk field update failed');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = btn.dataset._orig || 'Apply Fields';
            }
        }
    }

    async function handlePreview() {
        const previewBtn = qs('#wbv-preview-btn');
        if (previewBtn) {
            previewBtn.disabled = true;
            previewBtn.dataset._orig = previewBtn.textContent;
            previewBtn.textContent = (wbvPricer && wbvPricer.i18n && wbvPricer.i18n.searching) ? wbvPricer.i18n.searching : 'Searching…';
        }
        const selected = getSelectedVariationIds();
        if (selected.length === 0) {
            alert((wbvPricer && wbvPricer.i18n && wbvPricer.i18n.noSelection) ? wbvPricer.i18n.noSelection : 'No variations selected');
            return;
        }

        const mode = qs('#wbv-mode').value;
        const value = parseFloat(qs('#wbv-value').value || 0);
        const target = qs('#wbv-target').value;

        const opLabel = (qs('#wbv-operation-label') && qs('#wbv-operation-label').value) ? qs('#wbv-operation-label').value : '';
        const url = `${restRoot}/update`;
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce,
            },
            body: JSON.stringify({ variations: selected, mode: mode, value: value, target: target, dry_run: true, operation_label: opLabel }),
        });

        const data = await res.json();
        renderPreview(data);
        if (previewBtn) {
            previewBtn.disabled = false;
            previewBtn.textContent = previewBtn.dataset._orig || 'Preview';
        }
    }

    function renderPreview(data) {
        const container = qs('#wbv-preview');
        container.innerHTML = '';
        if (!data || !data.preview) {
            const p = document.createElement('p'); p.textContent = (wbvPricer && wbvPricer.i18n && wbvPricer.i18n.noPreview) ? wbvPricer.i18n.noPreview : 'No preview';
            container.appendChild(p);
            return;
        }

        const h = document.createElement('h3'); h.textContent = (wbvPricer && wbvPricer.i18n && wbvPricer.i18n.previewTitle) ? wbvPricer.i18n.previewTitle : 'Preview';
        container.appendChild(h);
        const table = document.createElement('table'); table.className = 'wbv-variations';
        const thead = document.createElement('thead'); thead.innerHTML = '<tr><th>Variation</th><th>Old</th><th>New</th></tr>';
        table.appendChild(thead);
        const tbody = document.createElement('tbody');

        data.preview.forEach(p => {
            const tr = document.createElement('tr');
            const tdId = document.createElement('td'); tdId.textContent = p.variation_id; tr.appendChild(tdId);
            const tdOld = document.createElement('td'); tdOld.textContent = p.old_price; tr.appendChild(tdOld);
            const tdNew = document.createElement('td'); tdNew.textContent = p.new_price; tdNew.style.color = 'green'; tr.appendChild(tdNew);
            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        container.appendChild(table);
    }

    async function handleApply() {
        const applyBtn = qs('#wbv-apply-btn');
        if (applyBtn) {
            applyBtn.disabled = true;
            applyBtn.dataset._orig = applyBtn.textContent;
            applyBtn.textContent = 'Working…';
        }
        const selected = getSelectedVariationIds();
        if (selected.length === 0) {
            alert((wbvPricer && wbvPricer.i18n && wbvPricer.i18n.noSelection) ? wbvPricer.i18n.noSelection : 'No variations selected');
            return;
        }

        const mode = qs('#wbv-mode').value;
        const value = parseFloat(qs('#wbv-value').value || 0);
        const target = qs('#wbv-target').value;

        const opLabel = (qs('#wbv-operation-label') && qs('#wbv-operation-label').value) ? qs('#wbv-operation-label').value : '';
        const url = `${restRoot}/update`;
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce,
            },
            body: JSON.stringify({ variations: selected, mode: mode, value: value, target: target, dry_run: false, operation_label: opLabel }),
        });

        const data = await res.json();
        if (data.status === 'scheduled') {
            const msg = `${(wbvPricer && wbvPricer.i18n && wbvPricer.i18n.updateScheduled) ? wbvPricer.i18n.updateScheduled : 'Update scheduled'}: ${data.operation_id} (${data.chunks} chunks)`;
            alert(msg);
            loadOperations();
            // Start watching operation progress
            watchOperation(data.operation_id, data.total);
        } else if (data.applied) {
            alert(`${(wbvPricer && wbvPricer.i18n && wbvPricer.i18n.updatedVariations) ? wbvPricer.i18n.updatedVariations : 'Updated variations'}: ${data.applied.length}`);
            loadOperations();
        } else if (data.errors && data.errors.length) {
            alert(`Completed with ${data.errors.length} errors`);
            loadOperations();
        }
        if (applyBtn) {
            applyBtn.disabled = false;
            applyBtn.textContent = applyBtn.dataset._orig || 'Apply Changes';
        }
    }

    let _wbvPollInterval = null;
    function watchOperation(operation_id, total) {
        const container = qs('#wbv-preview');
        container.innerHTML = '';
        const progressDiv = document.createElement('div'); progressDiv.className = 'wbv-progress';
        const progressBar = document.createElement('div'); progressBar.className = 'wbv-progress-bar'; progressBar.style.width = '0%'; progressBar.textContent = '0%';
        progressDiv.appendChild(progressBar);
        container.appendChild(progressDiv);
        const p = document.createElement('p'); p.textContent = 'Operation: ' + operation_id; container.appendChild(p);

        // Disable controls while watching
        qs('#wbv-preview-btn').disabled = true;
        qs('#wbv-apply-btn').disabled = true;
        container.setAttribute('aria-busy', 'true');

        _wbvPollInterval = setInterval(async function () {
            try {
                const res = await fetch(`${restRoot}/operations/${operation_id}`, { headers: { 'X-WP-Nonce': nonce } });
                if (!res.ok) return;
                const json = await res.json();
                const processed = (json.rows && json.rows.length) ? json.rows.length : 0;
                const percent = total > 0 ? Math.round((processed / total) * 100) : 0;
                const bar = container.querySelector('.wbv-progress-bar');
                if (bar) {
                    bar.style.width = percent + '%';
                    bar.textContent = percent + '%';
                }

                if (processed >= total) {
                    clearInterval(_wbvPollInterval);
                    _wbvPollInterval = null;
                    qs('#wbv-preview-btn').disabled = false;
                    qs('#wbv-apply-btn').disabled = false;
                    container.removeAttribute('aria-busy');
                    loadOperations();
                }
            } catch (e) {
                // ignore polling errors
            }
        }, 3000);
    }

    async function loadOperations() {
        const url = `${restRoot}/operations`;
        const res = await fetch(url, { headers: { 'X-WP-Nonce': nonce } });
        if (!res.ok) return;
        const json = await res.json();
        renderOperations(json.operations || []);
    }

    function renderOperations(ops) {
        const container = qs('#wbv-operations');
        container.innerHTML = '';
        if (!ops || ops.length === 0) {
            const p = document.createElement('p'); p.textContent = (wbvPricer && wbvPricer.i18n && wbvPricer.i18n.noOperations) ? wbvPricer.i18n.noOperations : 'No recent operations'; container.appendChild(p);
            return;
        }

        const table = document.createElement('table'); table.className = 'wbv-variations';
        const thead = document.createElement('thead'); thead.innerHTML = '<tr><th>Operation</th><th>Label</th><th>User</th><th>Date</th><th>Changes</th><th>Reverted</th><th>Actions</th></tr>';
        table.appendChild(thead);
        const tbody = document.createElement('tbody');

        ops.forEach(o => {
            const tr = document.createElement('tr');
            const tdOp = document.createElement('td'); tdOp.textContent = o.operation_id; tr.appendChild(tdOp);
            const tdLabel = document.createElement('td'); tdLabel.textContent = o.operation_label || ''; tr.appendChild(tdLabel);
            const tdUser = document.createElement('td'); tdUser.textContent = o.user_id; tr.appendChild(tdUser);
            const tdDate = document.createElement('td'); tdDate.textContent = o.created_at; tr.appendChild(tdDate);
            const tdChanges = document.createElement('td'); tdChanges.textContent = o.changes; tr.appendChild(tdChanges);
            const tdReverted = document.createElement('td'); tdReverted.textContent = o.is_reverted ? 'Yes' : 'No'; tr.appendChild(tdReverted);
            const tdActions = document.createElement('td');
            const viewBtn = document.createElement('button'); viewBtn.className = 'wbv-view-rows button'; viewBtn.dataset.op = o.operation_id; viewBtn.textContent = 'View';
            const undoBtn = document.createElement('button'); undoBtn.className = 'wbv-undo button'; undoBtn.dataset.op = o.operation_id; undoBtn.textContent = 'Undo';
            tdActions.appendChild(viewBtn); tdActions.appendChild(document.createTextNode(' ')); tdActions.appendChild(undoBtn); tr.appendChild(tdActions);
            tbody.appendChild(tr);
        });

        table.appendChild(tbody); container.appendChild(table);

        // Wire up buttons
        container.querySelectorAll('.wbv-view-rows').forEach(btn => {
            btn.addEventListener('click', async function (e) {
                const op = this.dataset.op;
                await loadOperationRows(op);
            });
        });

        container.querySelectorAll('.wbv-undo').forEach(btn => {
            btn.addEventListener('click', async function (e) {
                const op = this.dataset.op;
                await undoOperation(op);
            });
        });
    }

    async function loadOperationRows(operation_id) {
        const url = `${restRoot}/operations/${operation_id}`;
        const res = await fetch(url, { headers: { 'X-WP-Nonce': nonce } });
        if (!res.ok) return;
        const json = await res.json();
        const container = qs('#wbv-operation-rows');
        if (!json.rows || json.rows.length === 0) {
            const p = document.createElement('p'); p.textContent = (wbvPricer && wbvPricer.i18n && wbvPricer.i18n.noRows) ? wbvPricer.i18n.noRows : 'No rows'; container.appendChild(p);
            return;
        }

        const h = document.createElement('h3'); h.textContent = 'Operation rows'; container.appendChild(h);
        const table = document.createElement('table'); table.className = 'wbv-variations';
        const thead = document.createElement('thead'); thead.innerHTML = '<tr><th>Variation</th><th>Old</th><th>New</th><th>Target</th><th>Reverted</th></tr>';
        table.appendChild(thead);
        const tbody = document.createElement('tbody');
        json.rows.forEach(r => {
            const tr = document.createElement('tr');
            const tdV = document.createElement('td'); tdV.textContent = r.variation_id; tr.appendChild(tdV);
            const tdOld = document.createElement('td'); tdOld.textContent = r.old_price; tr.appendChild(tdOld);
            const tdNew = document.createElement('td'); tdNew.textContent = r.new_price; tr.appendChild(tdNew);
            const tdTarget = document.createElement('td'); tdTarget.textContent = r.target; tr.appendChild(tdTarget);
            const tdReverted = document.createElement('td'); tdReverted.textContent = r.reverted == 1 ? 'Yes' : 'No'; tr.appendChild(tdReverted);
            tbody.appendChild(tr);
        });
        table.appendChild(tbody); container.appendChild(table);
    }

    async function undoOperation(operation_id) {
        // Get preview first
        const previewRes = await fetch(`${restRoot}/undo`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({ operation_id: operation_id, dry_run: true }),
        });
        const preview = await previewRes.json();
        if (!preview || !preview.preview) {
            alert((wbvPricer && wbvPricer.i18n && wbvPricer.i18n.unablePreview) ? wbvPricer.i18n.unablePreview : 'Unable to compute revert preview');
            return;
        }

        // Show preview and ask confirmation
        if (!confirm(`Revert ${preview.preview.length} variations for operation ${operation_id}?`)) {
            return;
        }

        // Perform revert
        const res = await fetch(`${restRoot}/undo`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({ operation_id: operation_id, dry_run: false }),
        });
        const data = await res.json();
        if (data.status === 'scheduled') {
            alert(`Revert scheduled: ${data.revert_operation_id}`);
            loadOperations();
        } else if (data.status === 'completed') {
            alert(`Revert completed: ${data.revert_operation_id}`);
            loadOperations();
        } else {
            alert('Undo initiated');
            loadOperations();
        }
    }

    document.addEventListener('DOMContentLoaded', setupHandlers);

    // ========== Default Attributes Mode ==========
    let currentMode = 'prices'; // 'prices' or 'defaults'

    function setupModeHandlers() {
        const tabPrices = qs('#wbv-tab-prices');
        const tabDefaults = qs('#wbv-tab-defaults');
        const priceControls = qs('#wbv-price-controls'); // The price editor controls only
        const defaultsControls = qs('#wbv-defaults-controls');
        const defaultsInstructions = qs('#wbv-defaults-instructions');

        if (!tabPrices || !tabDefaults) return;

        tabPrices.addEventListener('click', function (e) {
            e.preventDefault();
            currentMode = 'prices';
            tabPrices.classList.add('nav-tab-active');
            tabDefaults.classList.remove('nav-tab-active');
            if (priceControls) priceControls.style.display = 'inline-block';
            if (defaultsControls) defaultsControls.style.display = 'none';
            if (defaultsInstructions) defaultsInstructions.style.display = 'none';

            // Re-render products in price mode if we have them
            const results = qs('#wbv-results');
            if (results && lastProducts && lastProducts.length > 0) {
                renderProducts(results, lastProducts);
            }
        });

        tabDefaults.addEventListener('click', function (e) {
            e.preventDefault();
            currentMode = 'defaults';
            tabDefaults.classList.add('nav-tab-active');
            tabPrices.classList.remove('nav-tab-active');
            if (priceControls) priceControls.style.display = 'none';
            if (defaultsControls) defaultsControls.style.display = 'block';
            if (defaultsInstructions) defaultsInstructions.style.display = 'block';

            // Re-render products in defaults mode if we have them
            const results = qs('#wbv-results');
            if (results && lastProducts && lastProducts.length > 0) {
                renderProductsForDefaults(results, lastProducts);
            } else if (results && !results.querySelector('.wbv-product')) {
                results.innerHTML = '<div style="text-align:center; padding:40px; color:#666;"><p style="font-size:16px;">👆 <strong>Start by searching for products above</strong></p><p>Use the search box to find variable products, then select them to set default attributes.</p></div>';
            }
        });
    } function renderProductsForDefaults(container, products) {
        container.innerHTML = '';
        if (!products || products.length === 0) {
            const p = document.createElement('p');
            p.textContent = 'No variable products found';
            container.appendChild(p);
            return;
        }

        products.forEach(product => {
            const wrap = document.createElement('div');
            wrap.className = 'wbv-product wbv-product-defaults';
            wrap.dataset.productId = product.product_id;

            const header = document.createElement('div');
            header.className = 'wbv-product-header';

            const productCheckbox = document.createElement('input');
            productCheckbox.type = 'checkbox';
            productCheckbox.className = 'wbv-select-product-default';
            productCheckbox.dataset.productId = product.product_id;
            header.appendChild(productCheckbox);

            const title = document.createElement('h3');
            title.textContent = product.title + (product.sku ? ' — ' + product.sku : '');
            header.appendChild(title);

            wrap.appendChild(header);

            // Display current default attributes
            const defaultsDiv = document.createElement('div');
            defaultsDiv.className = 'wbv-current-defaults';
            defaultsDiv.style.padding = '10px';
            defaultsDiv.style.background = '#f9f9f9';
            defaultsDiv.style.marginTop = '10px';

            const defaultsLabel = document.createElement('strong');
            defaultsLabel.textContent = 'Current Defaults: ';
            defaultsDiv.appendChild(defaultsLabel);

            if (product.default_attributes && product.default_attributes.length > 0) {
                const defaultsList = product.default_attributes.map(attr =>
                    `${attr.label}: ${attr.display_value}`
                ).join(', ');
                const span = document.createElement('span');
                span.textContent = defaultsList;
                defaultsDiv.appendChild(span);
            } else {
                const span = document.createElement('span');
                span.textContent = 'None set';
                span.style.fontStyle = 'italic';
                span.style.color = '#999';
                defaultsDiv.appendChild(span);
            }

            wrap.appendChild(defaultsDiv);
            container.appendChild(wrap);
        });

        // Show the defaults selector panel automatically with all available attributes
        showDefaultsSelector();
    }

    function showDefaultsSelector() {
        console.log('showDefaultsSelector() called');
        const selector = qs('#wbv-defaults-selector');
        const attributesDiv = qs('#wbv-defaults-attributes');

        console.log('selector element:', selector);
        console.log('attributesDiv element:', attributesDiv);
        console.log('lastProducts:', lastProducts);

        if (!selector || !attributesDiv || !lastProducts) {
            console.warn('Missing required elements or data:', {
                selector: !!selector,
                attributesDiv: !!attributesDiv,
                lastProducts: !!lastProducts
            });
            return;
        }

        // Show the panel
        selector.style.display = 'block';
        console.log('Set selector.style.display to block, computed style:', window.getComputedStyle(selector).display);

        // Collect ALL attributes from all products (not just selected ones)
        const attributesMap = new Map();

        lastProducts.forEach(product => {
            if (product && product.variations) {
                product.variations.forEach(v => {
                    if (v.attributes && Array.isArray(v.attributes)) {
                        v.attributes.forEach(attr => {
                            if (!attributesMap.has(attr.key)) {
                                attributesMap.set(attr.key, {
                                    key: attr.key,
                                    label: attr.label,
                                    values: new Set()
                                });
                            }
                            attributesMap.get(attr.key).values.add(attr.value);
                        });
                    }
                });
            }
        });

        console.log('Available attributes:', attributesMap.size);

        // Render attribute selectors
        attributesDiv.innerHTML = '';

        if (attributesMap.size === 0) {
            attributesDiv.innerHTML = '<p style="color:#999; font-style:italic;">No attributes found in the displayed products.</p>';
            return;
        }

        attributesMap.forEach((attrData, attrKey) => {
            const attrDiv = document.createElement('div');
            attrDiv.style.marginBottom = '10px';

            const label = document.createElement('label');
            label.textContent = attrData.label + ': ';
            label.style.display = 'inline-block';
            label.style.minWidth = '150px';
            label.style.fontWeight = 'bold';
            attrDiv.appendChild(label);

            const select = document.createElement('select');
            select.dataset.attributeKey = attrKey;
            select.style.minWidth = '200px';

            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = '— No change —';
            select.appendChild(defaultOption);

            Array.from(attrData.values).sort().forEach(value => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = value;
                select.appendChild(option);
            });

            attrDiv.appendChild(select);
            attributesDiv.appendChild(attrDiv);
        });
    }

    function updateDefaultsSelector() {
        const selected = Array.from(document.querySelectorAll('.wbv-select-product-default:checked'));
        const selector = qs('#wbv-defaults-selector');

        console.log('updateDefaultsSelector called, selected:', selected.length, 'products');

        if (!selector) return;

        // Update the description to show how many products are selected
        let descriptionElem = selector.querySelector('.wbv-selection-description');
        if (!descriptionElem) {
            descriptionElem = document.createElement('p');
            descriptionElem.className = 'wbv-selection-description';
            descriptionElem.style.fontWeight = 'bold';
            descriptionElem.style.color = '#2271b1';
            const firstPara = selector.querySelector('p');
            if (firstPara) {
                firstPara.parentNode.insertBefore(descriptionElem, firstPara.nextSibling);
            }
        }

        if (selected.length === 0) {
            descriptionElem.textContent = '⚠️  No products selected. Check the boxes above to select products.';
            descriptionElem.style.color = '#d63638';
        } else {
            descriptionElem.textContent = `✓ ${selected.length} product(s) selected for default attribute update`;
            descriptionElem.style.color = '#00a32a';
        }
    }

    function handleDefaultsPreview() {
        const selected = Array.from(document.querySelectorAll('.wbv-select-product-default:checked'));
        if (selected.length === 0) {
            alert('Please select at least one product');
            return;
        }

        const defaults = collectDefaultsFromUI();
        if (Object.keys(defaults).length === 0) {
            alert('Please set at least one default attribute value');
            return;
        }

        const productIds = selected.map(cb => parseInt(cb.dataset.productId));

        fetch(`${restRoot}/set-defaults`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({
                product_ids: productIds,
                defaults: defaults,
                dry_run: true
            })
        })
            .then(res => res.json())
            .then(data => {
                displayDefaultsPreview(data.preview || [], data.total_selected, data.preview_limit);
            })
            .catch(err => {
                alert('Error: ' + err.message);
            });
    }

    function handleDefaultsApply() {
        const selected = Array.from(document.querySelectorAll('.wbv-select-product-default:checked'));
        if (selected.length === 0) {
            alert('Please select at least one product');
            return;
        }

        const defaults = collectDefaultsFromUI();
        if (Object.keys(defaults).length === 0) {
            alert('Please set at least one default attribute value');
            return;
        }

        if (!confirm(`Apply default attributes to ${selected.length} product(s)?`)) {
            return;
        }

        const productIds = selected.map(cb => parseInt(cb.dataset.productId));
        const operationLabel = qs('#wbv-defaults-operation-label') ? qs('#wbv-defaults-operation-label').value : '';

        fetch(`${restRoot}/set-defaults`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({
                product_ids: productIds,
                defaults: defaults,
                dry_run: false,
                operation_label: operationLabel
            })
        })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'scheduled') {
                    // Background processing scheduled
                    const message = data.message || `Scheduled ${data.total} product(s) for background processing in ${data.chunks} batch(es)`;
                    alert(`✓ ${message}\n\nOperation ID: ${data.operation_id}\n\nThe update will run in the background. Large batches may take several minutes. Check the "Recent Operations" section below for progress.`);
                    // Optionally refresh operations list
                    if (typeof loadOperations === 'function') {
                        setTimeout(() => loadOperations(), 2000);
                    }
                } else if (data.applied && data.applied.length > 0) {
                    // Synchronous update completed
                    alert(`Successfully updated ${data.applied.length} product(s)`);
                    // Refresh the search to show updated defaults
                    qs('#wbv-search-btn').click();
                } else {
                    alert('No products were updated');
                }
            })
            .catch(err => {
                alert('Error: ' + err.message);
            });
    }

    function collectDefaultsFromUI() {
        const selected = Array.from(document.querySelectorAll('.wbv-select-product-default:checked'));
        const selects = Array.from(document.querySelectorAll('#wbv-defaults-attributes select'));

        const defaults = {};

        selected.forEach(cb => {
            const pid = parseInt(cb.dataset.productId);
            defaults[pid] = {};

            selects.forEach(select => {
                if (select.value) {
                    const attrKey = select.dataset.attributeKey;
                    defaults[pid][attrKey] = select.value;
                }
            });
        });

        return defaults;
    }

    function displayDefaultsPreview(preview, totalSelected, previewLimit) {
        const previewDiv = qs('#wbv-preview');
        if (!previewDiv) return;

        previewDiv.innerHTML = '';

        if (!preview || preview.length === 0) {
            previewDiv.innerHTML = '<p>No changes to preview</p>';
            return;
        }

        const heading = document.createElement('h3');
        heading.textContent = 'Preview: Default Attributes Changes';
        previewDiv.appendChild(heading);

        // Show warning if preview is limited
        if (totalSelected && previewLimit && totalSelected > previewLimit) {
            const warning = document.createElement('div');
            warning.style.padding = '10px';
            warning.style.background = '#fff3cd';
            warning.style.border = '1px solid #ffc107';
            warning.style.borderRadius = '4px';
            warning.style.marginBottom = '15px';
            warning.innerHTML = `<strong>⚠️ Preview Limited:</strong> Showing first ${previewLimit} of ${totalSelected} selected products. All ${totalSelected} products will be updated when you click "Apply Changes".`;
            previewDiv.appendChild(warning);
        }

        const table = document.createElement('table');
        table.className = 'wbv-preview-table';
        table.style.width = '100%';
        table.style.borderCollapse = 'collapse';

        const thead = document.createElement('thead');
        thead.innerHTML = '<tr><th>Product</th><th>Old Defaults</th><th>New Defaults</th></tr>';
        table.appendChild(thead);

        const tbody = document.createElement('tbody');

        preview.forEach(item => {
            const tr = document.createElement('tr');

            const tdProduct = document.createElement('td');
            tdProduct.textContent = item.product_name;
            tdProduct.style.padding = '8px';
            tdProduct.style.border = '1px solid #ddd';
            tr.appendChild(tdProduct);

            const tdOld = document.createElement('td');
            tdOld.textContent = formatDefaults(item.old_defaults);
            tdOld.style.padding = '8px';
            tdOld.style.border = '1px solid #ddd';
            tr.appendChild(tdOld);

            const tdNew = document.createElement('td');
            tdNew.textContent = formatDefaults(item.new_defaults);
            tdNew.style.padding = '8px';
            tdNew.style.border = '1px solid #ddd';
            tdNew.style.fontWeight = 'bold';
            tr.appendChild(tdNew);

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        previewDiv.appendChild(table);
    }

    function formatDefaults(defaults) {
        if (!defaults || Object.keys(defaults).length === 0) {
            return 'None';
        }
        return Object.entries(defaults).map(([key, value]) => {
            const label = key.replace(/^pa_/, '').replace(/_/g, ' ');
            return `${label}: ${value}`;
        }).join(', ');
    }

    // Override renderProducts to handle both modes
    const originalRenderProducts = renderProducts;
    renderProducts = function (container, products) {
        if (currentMode === 'defaults') {
            renderProductsForDefaults(container, products);
        } else {
            originalRenderProducts(container, products);
        }
    };

    // Setup mode handlers and defaults event listeners
    document.addEventListener('DOMContentLoaded', function () {
        setupModeHandlers();

        // Attach event listeners for defaults mode buttons
        const previewBtn = qs('#wbv-defaults-preview-btn');
        const applyBtn = qs('#wbv-defaults-apply-btn');

        if (previewBtn) {
            previewBtn.addEventListener('click', function (e) {
                e.preventDefault();
                handleDefaultsPreview();
            });
        }

        if (applyBtn) {
            applyBtn.addEventListener('click', function (e) {
                e.preventDefault();
                handleDefaultsApply();
            });
        }

        // Handle select-all for defaults mode
        const selectAllCheckbox = qs('#wbv-select-all-visible');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function () {
                if (currentMode === 'defaults') {
                    const checkboxes = document.querySelectorAll('.wbv-select-product-default');
                    checkboxes.forEach(cb => {
                        cb.checked = selectAllCheckbox.checked;
                    });
                    updateDefaultsSelector();
                }
            });
        }

        // Delegate change event for product checkboxes in defaults mode
        document.addEventListener('change', function (e) {
            if (e.target.classList.contains('wbv-select-product-default')) {
                console.log('Checkbox changed:', e.target.checked, 'Product ID:', e.target.value);
                console.log('Current mode:', currentMode);
                console.log('lastProducts exists:', !!lastProducts);
                updateDefaultsSelector();
            }
        });
    });

})();
