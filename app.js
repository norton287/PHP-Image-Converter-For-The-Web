/**
 * app.js — Image Converter front-end logic
 *
 * Runtime config is injected from PHP via window.AppConfig before this
 * script runs:
 *   maxFiles     {number}  Maximum files per conversion request
 *   maxBytes     {number}  Maximum bytes per file
 *   formats      {object}  { value: label } map for per-file dropdowns
 *   csrfToken    {string}  CSRF token for POST requests
 *   purgeSeconds {number}  Seconds until a download expires
 */
(function () {
    'use strict';

    // ── Config from PHP ──────────────────────────────────────────────────────
    const cfg = window.AppConfig || {};
    const MAX_FILES     = cfg.maxFiles     || 10;
    const MAX_BYTES     = cfg.maxBytes     || 20971520;
    const FORMATS       = cfg.formats      || {};
    const CSRF_TOKEN    = cfg.csrfToken    || '';
    const PURGE_SECONDS = cfg.purgeSeconds || 900;

    // ── DOM refs ─────────────────────────────────────────────────────────────
    const form         = document.getElementById('convertForm');
    const submitBtn    = document.getElementById('submitBtn');
    const waiting      = document.getElementById('waiting');
    const result       = document.getElementById('result');
    const fileInput    = document.getElementById('imageInput');
    const dropZone     = document.getElementById('dropZone');
    const dropPrompt   = document.getElementById('dropPrompt');
    const fileList     = document.getElementById('fileList');
    const fileControls = document.getElementById('fileControls');
    const addMoreBtn   = document.getElementById('addMoreBtn');
    const clearBtn     = document.getElementById('clearFilesBtn');
    const globalFmt    = document.getElementById('globalFormat');
    const errorBanner  = document.getElementById('errorBanner');
    const errorMsg     = document.getElementById('errorMessage');
    const qualSlider   = document.getElementById('qualitySlider');
    const qualVal      = document.getElementById('qualityVal');

    if (!form || !fileInput || !dropZone) return; // guard: not on the convert page

    // ── Master file list ──────────────────────────────────────────────────────
    let storedFiles = [];

    // ── Restore persisted advanced-option values ──────────────────────────────
    function restoreSettings() {
        try {
            const q = localStorage.getItem('conv-quality');
            if (q !== null && qualSlider) {
                qualSlider.value = q;
                syncQualitySlider();
            }
            const rw = localStorage.getItem('conv-resize-w');
            const rh = localStorage.getItem('conv-resize-h');
            const rm = localStorage.getItem('conv-resize-mode');
            const rw_el = document.getElementById('resizeWidth');
            const rh_el = document.getElementById('resizeHeight');
            if (rw !== null && rw_el) rw_el.value = rw;
            if (rh !== null && rh_el) rh_el.value = rh;
            if (rm !== null) {
                const radio = document.querySelector('input[name="resize_mode"][value="' + rm + '"]');
                if (radio) radio.checked = true;
            }
            const gf = localStorage.getItem('conv-global-format');
            if (gf !== null && globalFmt) globalFmt.value = gf;
        } catch (e) {}
    }

    function persistSettings() {
        try {
            if (qualSlider) localStorage.setItem('conv-quality', qualSlider.value);
            const rw_el = document.getElementById('resizeWidth');
            const rh_el = document.getElementById('resizeHeight');
            if (rw_el) localStorage.setItem('conv-resize-w', rw_el.value);
            if (rh_el) localStorage.setItem('conv-resize-h', rh_el.value);
            const modeChecked = document.querySelector('input[name="resize_mode"]:checked');
            if (modeChecked) localStorage.setItem('conv-resize-mode', modeChecked.value);
            if (globalFmt) localStorage.setItem('conv-global-format', globalFmt.value);
        } catch (e) {}
    }

    // ── Theme toggle ──────────────────────────────────────────────────────────
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') || 'dark';
            const next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('app-theme', next); } catch (e) {}
        });
    }

    // ── Quality slider live update ────────────────────────────────────────────
    function syncQualitySlider() {
        if (!qualSlider || !qualVal) return;
        qualVal.textContent = qualSlider.value;
        qualSlider.style.setProperty('--quality-pct', qualSlider.value + '%');
    }
    if (qualSlider) {
        qualSlider.addEventListener('input', () => { syncQualitySlider(); persistSettings(); });
        syncQualitySlider();
    }

    // Persist other advanced-option changes
    document.querySelectorAll('input[name="resize_mode"]').forEach(r =>
        r.addEventListener('change', persistSettings)
    );
    const rw_el = document.getElementById('resizeWidth');
    const rh_el = document.getElementById('resizeHeight');
    if (rw_el) rw_el.addEventListener('change', persistSettings);
    if (rh_el) rh_el.addEventListener('change', persistSettings);
    if (globalFmt) globalFmt.addEventListener('change', persistSettings);

    restoreSettings();

    // ── Drop zone — click to open file picker (no-files state only) ──────────
    dropZone.addEventListener('click', (e) => {
        if (dropZone.classList.contains('has-files')) return;
        fileInput.click();
    });

    // ── Drag events ───────────────────────────────────────────────────────────
    ['dragenter', 'dragover'].forEach(ev => {
        dropZone.addEventListener(ev, (e) => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });
    });
    ['dragleave', 'drop'].forEach(ev => {
        dropZone.addEventListener(ev, (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
        });
    });
    dropZone.addEventListener('drop', (e) => {
        addFiles(Array.from(e.dataTransfer.files));
    });

    // ── File input change ─────────────────────────────────────────────────────
    fileInput.addEventListener('change', () => {
        const selected = Array.from(fileInput.files);
        if (selected.length > 0) addFiles(selected);
    });

    // ── Add More / Clear buttons ──────────────────────────────────────────────
    if (addMoreBtn) {
        addMoreBtn.addEventListener('click', (e) => { e.stopPropagation(); fileInput.click(); });
    }
    if (clearBtn) {
        clearBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            storedFiles = [];
            renderFileList();
        });
    }

    // ── Global format "apply to all" ──────────────────────────────────────────
    if (globalFmt) {
        globalFmt.addEventListener('change', () => {
            document.querySelectorAll('.per-file-format').forEach(sel => {
                if (globalFmt.value) sel.value = globalFmt.value;
            });
            persistSettings();
        });
    }

    // ── addFiles — merge into storedFiles, skip duplicates by name+size ───────
    function addFiles(files) {
        const existing = new Set(storedFiles.map(f => f.name + f.size));
        files.forEach(f => {
            if (!existing.has(f.name + f.size)) storedFiles.push(f);
        });
        renderFileList();
    }

    // ── renderFileList — rebuild the visible file grid from storedFiles ────────
    function renderFileList() {
        fileList.innerHTML = '';

        if (storedFiles.length === 0) {
            dropPrompt.classList.remove('hidden');
            fileList.classList.add('hidden');
            fileControls.classList.add('hidden');
            dropZone.classList.remove('has-files');
            return;
        }

        dropPrompt.classList.add('hidden');
        fileList.classList.remove('hidden');
        fileControls.classList.remove('hidden');
        dropZone.classList.add('has-files');

        storedFiles.forEach((file, index) => {
            const card = document.createElement('div');
            card.className = 'file-card';

            // ── Thumbnail (or placeholder for non-image types) ───────────────
            const thumbWrap = document.createElement('div');
            if (file.type.startsWith('image/') && file.type !== 'image/svg+xml') {
                const img = document.createElement('img');
                img.className = 'file-thumb';
                img.alt = file.name;
                const reader = new FileReader();
                reader.onload = e => { img.src = e.target.result; };
                reader.readAsDataURL(file);
                thumbWrap.appendChild(img);
            } else {
                const ph = document.createElement('div');
                ph.className = 'file-thumb-placeholder';
                ph.textContent = file.name.split('.').pop().toUpperCase().slice(0, 4);
                thumbWrap.appendChild(ph);
            }

            // ── File info ────────────────────────────────────────────────────
            const info = document.createElement('div');
            info.className = 'min-w-0';
            const name = document.createElement('p');
            name.className = 'file-name';
            name.title = file.name;
            name.textContent = file.name;
            const size = document.createElement('p');
            size.className = 'file-size';
            size.textContent = formatBytes(file.size);
            info.appendChild(name);
            info.appendChild(size);

            // ── Per-file format select ────────────────────────────────────────
            const sel = document.createElement('select');
            sel.name = 'file_format[]';
            sel.className = 'per-file-format';
            Object.entries(FORMATS).forEach(([val, lbl]) => {
                const opt = document.createElement('option');
                opt.value = val;
                opt.textContent = lbl;
                if (globalFmt && globalFmt.value === val) opt.selected = true;
                sel.appendChild(opt);
            });

            // ── Remove button ─────────────────────────────────────────────────
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'file-remove';
            removeBtn.innerHTML = '&times;';
            removeBtn.title = 'Remove';
            removeBtn.setAttribute('aria-label', 'Remove ' + file.name);
            removeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                storedFiles.splice(index, 1);
                renderFileList();
            });

            const actions = document.createElement('div');
            actions.className = 'flex items-center gap-1';
            actions.appendChild(sel);
            actions.appendChild(removeBtn);

            card.appendChild(thumbWrap);
            card.appendChild(info);
            card.appendChild(actions);
            fileList.appendChild(card);
        });

        // Sync hidden file input for non-async fallback
        try {
            const syncDt = new DataTransfer();
            storedFiles.forEach(f => syncDt.items.add(f));
            fileInput.files = syncDt.files;
        } catch (e) {
            try { fileInput.value = ''; } catch (e2) {}
        }
    }

    // ── Form submission ───────────────────────────────────────────────────────
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        hideError();

        const files = storedFiles.slice();
        if (files.length === 0)      { showError('Please select at least one image.'); return; }
        if (files.length > MAX_FILES) { showError('Please select no more than ' + MAX_FILES + ' images.'); return; }
        for (const f of files) {
            if (f.size > MAX_BYTES) {
                showError('"' + f.name + '" exceeds the 20 MB size limit.');
                return;
            }
        }
        if (globalFmt && !globalFmt.value &&
            document.querySelectorAll('.per-file-format').length === 0) {
            showError('Please select a target format.');
            return;
        }

        waiting.style.display = 'flex';
        result.innerHTML = '';
        submitBtn.disabled = true;
        submitBtn.textContent = 'Converting\u2026';

        const formData = new FormData(form);
        formData.delete('images[]');
        files.forEach(f => formData.append('images[]', f));

        try {
            const res  = await fetch(form.action || window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-Token': CSRF_TOKEN },
            });
            const json = await res.json();

            if (!json.success) {
                showError(json.error || 'An unknown error occurred.');
                return;
            }

            // ── Download button with success animation ────────────────────────
            result.innerHTML = buildDownloadButton(json.url, json.label, json.output_size);
            const dlBtn = result.querySelector('#downloadButton');
            if (dlBtn) {
                // Trigger entrance animation
                void dlBtn.offsetWidth; // reflow
                dlBtn.classList.add('success-enter');
                dlBtn.addEventListener('animationend', () => dlBtn.classList.remove('success-enter'), { once: true });

                dlBtn.addEventListener('click', () => {
                    setTimeout(() => {
                        result.innerHTML = '';
                        form.reset();
                        storedFiles = [];
                        renderFileList();
                    }, 500);
                });
            }

            // ── Per-file failure list ──────────────────────────────────────────
            if (json.failures && json.failures.length > 0) {
                const failDiv = document.createElement('div');
                failDiv.className = 'partial-failure';
                const n = json.failures.length;
                failDiv.innerHTML =
                    '<p class="partial-fail-title">'
                    + n + ' file' + (n !== 1 ? 's' : '') + ' could not be converted:</p>'
                    + '<ul>' + json.failures.map(f =>
                        '<li class="partial-fail-item"><strong>' + escapeHtml(f.name) + '</strong>: '
                        + escapeHtml(f.error) + '</li>'
                    ).join('') + '</ul>';
                result.appendChild(failDiv);
            }

            // ── Refresh download history ──────────────────────────────────────
            if (json.history && json.history.length) {
                renderHistory(json.history);
            }

        } catch (err) {
            showError('Request failed. Please check your connection and try again.');
            console.error(err);
        } finally {
            waiting.style.display = 'none';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Convert';
        }
    });

    // ── History rendering ─────────────────────────────────────────────────────
    function renderHistory(history) {
        let section = document.getElementById('historySection');
        if (!section) {
            section = document.createElement('div');
            section.id        = 'historySection';
            section.className = 'history-section';
            section.innerHTML = '<h2 class="history-title">Recent Downloads</h2>'
                + '<ul class="space-y-2" id="historyList"></ul>';
            const card = document.querySelector('.app-card');
            const footer = card && card.querySelector('.footer-text');
            if (footer) {
                card.insertBefore(section, footer);
            } else if (card) {
                card.appendChild(section);
            }
        }
        const list = document.getElementById('historyList');
        if (!list) return;
        const nowSec = Math.floor(Date.now() / 1000);
        list.innerHTML = history.map(e => {
            const expired = (nowSec - e.time) > PURGE_SECONDS;
            const t  = new Date(e.time * 1000);
            const ts = t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            const sizeStr = e.output_size
                ? ' &middot; ' + formatBytes(e.output_size)
                : '';
            return '<li class="history-item' + (expired ? ' expired' : '') + '">'
                + '<span class="history-item-text">'
                + e.count + ' file' + (e.count !== 1 ? 's' : '')
                + ' &rarr; <strong>' + escapeHtml(String(e.format).toUpperCase()) + '</strong>'
                + sizeStr
                + '<span class="ts ml-1">' + ts + '</span></span>'
                + (expired
                    ? '<span class="history-expired">Expired</span>'
                    : '<a href="' + escapeHtml(e.url) + '" class="history-link">Re-download</a>'
                )
                + '</li>';
        }).join('');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function showError(msg) {
        if (errorMsg)    errorMsg.textContent = msg;
        if (errorBanner) {
            errorBanner.classList.remove('hidden');
            errorBanner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        waiting.style.display = 'none';
        submitBtn.disabled    = false;
        submitBtn.textContent = 'Convert';
    }

    function hideError() {
        if (errorBanner) errorBanner.classList.add('hidden');
    }

    function formatBytes(bytes) {
        if (bytes < 1024)    return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g,  '&amp;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;')
            .replace(/"/g,  '&quot;')
            .replace(/'/g,  '&#39;');
    }

    function buildDownloadButton(url, label, outputSize) {
        const sizeHint = outputSize
            ? ' <span class="dl-size">(' + formatBytes(outputSize) + ')</span>'
            : '';
        return '<a href="' + escapeHtml(url) + '" id="downloadButton" class="btn-download">'
            + '<div class="btn-download-icon">'
            + '<svg class="w-5 h-5" fill="none" stroke-linecap="round" stroke-linejoin="round" '
            + 'stroke-width="2" viewBox="0 0 24 24" stroke="currentColor">'
            + '<path d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg>'
            + '</div>'
            + escapeHtml(label) + sizeHint
            + '</a>';
    }

}());
