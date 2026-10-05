/**
 * Database Optimizer – Admin JavaScript (v3.0)
 *
 * Handles:
 *  - Tab switching (with unsaved-settings warning)
 *  - Database scan (AJAX) with progress bar, loader and locked buttons
 *  - Health score card
 *  - Per-row cleanup with progress bar
 *  - Optimize All (sequential batch runner)
 *  - Confirm modal + result modal
 *  - Database tables AJAX scan
 *  - Clear history
 *  - Floating optimize-all bar visibility
 *  - Inline scan of unused tables in the Tables tab
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */
(function ($) {
    'use strict';

    /* ============================================================
       UTILITIES
       ============================================================ */

    function post(action, data) {
        data = data || {};
        data.action = action;
        data.nonce  = ccdcoAdmin.nonce;
        return $.post(ccdcoAdmin.ajaxUrl, data);
    }

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    /** Fallback (only used if the server sends no label): current time in the browser's timezone. */
    function localNow() {
        return new Date().toLocaleString();
    }

    function formatBytes(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes <= 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var pow   = Math.min(Math.floor(Math.log(bytes) / Math.log(1000)), units.length - 1);
        return (bytes / Math.pow(1000, pow)).toFixed(2) + ' ' + units[pow];
    }

    /* ============================================================
       TAB SWITCHING (with unsaved-settings guard)
       ============================================================ */

    var $settingsForm     = $('#ccdco-panel-settings form');
    var settingsSnapshot  = $settingsForm.length ? $settingsForm.serialize() : '';
    var settingsSubmitting = false;
    var pendingTab        = null;

    function settingsDirty() {
        return $settingsForm.length > 0 && !settingsSubmitting && $settingsForm.serialize() !== settingsSnapshot;
    }

    /** Switch tabs with no checks (used by the guard once the user has decided, and by scripts). */
    function switchTab(tab) {
        $('.ccdco-tab').removeClass('ccdco-tab-active').attr('aria-selected', 'false');
        $('.ccdco-tab[data-tab="' + tab + '"]').addClass('ccdco-tab-active').attr('aria-selected', 'true');

        $('.ccdco-panel').attr('hidden', true);
        $('#ccdco-panel-' + tab).removeAttr('hidden');
    }

    function openUnsavedModal() {
        $('#ccdco-unsaved-modal').removeAttr('hidden').attr('aria-hidden', 'false');
        $('body').addClass('ccdco-modal-open');
        $('#ccdco-unsaved-save').focus();
    }

    function closeUnsavedModal() {
        $('#ccdco-unsaved-modal').attr('hidden', true).attr('aria-hidden', 'true');
        $('body').removeClass('ccdco-modal-open');
        pendingTab = null;
    }

    $(document).on('click', '.ccdco-tab', function () {
        var tab     = String($(this).data('tab'));
        var current = String($('.ccdco-tab-active').data('tab'));

        if (tab === current) return;

        if (current === 'settings' && settingsDirty()) {
            pendingTab = tab;
            openUnsavedModal();
            return;
        }

        switchTab(tab);
    });

    // Stay on Settings (close X, backdrop, "Keep Editing")
    $(document).on('click', '#ccdco-unsaved-stay, #ccdco-unsaved-close, #ccdco-unsaved-modal .ccdco-modal-backdrop', function () {
        closeUnsavedModal();
    });

    // Leave without saving: put the form back to its saved values, then switch.
    $(document).on('click', '#ccdco-unsaved-discard', function () {
        var tab = pendingTab;
        if ($settingsForm.length) {
            $settingsForm[0].reset();
            settingsSnapshot = $settingsForm.serialize();
        }
        closeUnsavedModal();
        if (tab) switchTab(tab);
    });

    // Save, then continue to the tab the user clicked (the save reloads the page).
    $(document).on('click', '#ccdco-unsaved-save', function () {
        if (!$settingsForm.length || !pendingTab) return;

        try { window.sessionStorage.setItem('ccdcoTabAfterSave', pendingTab); } catch (e) { /* storage unavailable */ }

        $(this).prop('disabled', true).html(
            '<span class="ccdco-spinner ccdco-spinner-sm" aria-hidden="true"></span> ' + esc(ccdcoAdmin.i18n.saving || 'Saving…')
        );
        settingsSubmitting = true;
        // The WP submit button is named "submit", which shadows form.submit(); call the native method.
        HTMLFormElement.prototype.submit.call($settingsForm[0]);
    });

    // Normal "Save Settings" button
    $(document).on('submit', '#ccdco-panel-settings form', function () {
        settingsSubmitting = true;
    });

    // Also warn if the user leaves the page entirely (WP menu, browser back, closing the tab).
    window.addEventListener('beforeunload', function (e) {
        if (settingsDirty()) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // After "Save & Continue" reloads the page, open the tab the user wanted.
    (function () {
        var target = null;
        try {
            target = window.sessionStorage.getItem('ccdcoTabAfterSave');
            window.sessionStorage.removeItem('ccdcoTabAfterSave');
        } catch (e) { /* storage unavailable */ }
        if (target && $('#ccdco-panel-' + target).length) {
            switchTab(target);
        }
    })();

    /* ============================================================
       SCAN DATABASE (with progress indicator + loader)
       ============================================================ */

    var scanRunning   = false;
    var scanTimer     = null;
    var scanHideTimer = null;
    var scanMsgTimer  = null;
    var scanPct       = 0;

    /**
     * Lock/unlock every scan trigger. While running, the buttons are disabled
     * and show a spinner so a second scan can't be started.
     */
    function setScanButtonsRunning(running) {
        $('#ccdco-run-scan, #ccdco-run-scan-empty').each(function () {
            var $b = $(this);
            if (running) {
                if (typeof $b.data('ccdcoOrig') === 'undefined') {
                    $b.data('ccdcoOrig', $b.html());
                }
                $b.prop('disabled', true)
                  .attr('aria-busy', 'true')
                  .addClass('ccdco-is-busy')
                  .html('<span class="ccdco-spinner ccdco-spinner-sm" aria-hidden="true"></span> ' +
                        esc(ccdcoAdmin.i18n.scanningShort || 'Scanning…'));
            } else {
                $b.prop('disabled', false).removeAttr('aria-busy').removeClass('ccdco-is-busy');
                if (typeof $b.data('ccdcoOrig') !== 'undefined') {
                    $b.html($b.data('ccdcoOrig'));
                    $b.removeData('ccdcoOrig');
                }
            }
        });
    }

    function renderScanProgress(pct, stepText) {
        pct = Math.max(0, Math.min(100, Math.round(pct)));
        $('#ccdco-scan-fill').css('width', pct + '%');
        $('#ccdco-scan-track').attr('aria-valuenow', pct);
        $('#ccdco-scan-progress-pct').text(pct + '%');
        if (stepText) {
            $('#ccdco-scan-progress-step').text(stepText);
        }
    }

    function scanStepText(pct) {
        var steps = (ccdcoAdmin.i18n && $.isArray(ccdcoAdmin.i18n.scanSteps)) ? ccdcoAdmin.i18n.scanSteps : [];
        if (!steps.length) return ccdcoAdmin.i18n.scanning;
        var idx = Math.min(steps.length - 1, Math.floor((pct / 100) * steps.length));
        return steps[idx];
    }

    /**
     * The scan is one server request, so the bar is an estimate: it eases toward 92%
     * while the request runs and jumps to 100% when the server responds. The moving
     * stripes keep animating even if the server is slow, so it never looks frozen.
     */
    function startScanProgress() {
        clearInterval(scanTimer);
        clearTimeout(scanHideTimer);
        scanPct = 0;

        clearTimeout(scanMsgTimer);
        $('#ccdco-scan-message').stop(true, true).attr('hidden', true).removeClass('success error').text('').css('display', '');
        $('#ccdco-scan-progress-title').text(ccdcoAdmin.i18n.scanning);
        $('#ccdco-scan-progress').removeAttr('hidden');
        renderScanProgress(2, scanStepText(0));

        // Skeleton on stat cards + spinner overlay on the results panel.
        $('.ccdco-stats-row, #ccdco-panel-cleanup').addClass('ccdco-is-loading');

        scanTimer = setInterval(function () {
            scanPct += (92 - scanPct) * 0.035 + 0.12;
            if (scanPct > 92) scanPct = 92;
            renderScanProgress(scanPct, scanStepText(scanPct));
        }, 250);
    }

    function stopScanProgress(success) {
        clearInterval(scanTimer);
        $('.ccdco-stats-row, #ccdco-panel-cleanup').removeClass('ccdco-is-loading');

        if (success) {
            $('#ccdco-scan-progress-title').text(ccdcoAdmin.i18n.scanComplete || 'Scan complete');
            renderScanProgress(100, ccdcoAdmin.i18n.scanDone);
            scanHideTimer = setTimeout(function () {
                $('#ccdco-scan-progress').attr('hidden', true);
            }, 900);
        } else {
            $('#ccdco-scan-progress').attr('hidden', true);
        }
    }

    function showScanMessage(type, text) {
        var $msg = $('#ccdco-scan-message');
        clearTimeout(scanMsgTimer);
        $msg.stop(true, true).show().removeAttr('hidden').removeClass('success error').addClass(type).text(text);

        // Success message disappears by itself after 5 seconds. Errors stay until the next scan.
        if (type === 'success') {
            scanMsgTimer = setTimeout(function () {
                $msg.fadeOut(300, function () {
                    $msg.attr('hidden', true).removeClass('success error').text('').css('display', '');
                });
            }, 5000);
        }
    }

    function runScan() {
        if (scanRunning) return; // a scan is already in progress
        scanRunning = true;

        setScanButtonsRunning(true);
        $('#ccdco-optimize-all').prop('disabled', true);
        startScanProgress();

        post('ccdco_run_scan', {}).done(function (response) {
            if (response && response.success) {
                var data    = response.data || {};
                var results = data.results || {};

                renderCleanupList(results);
                updateStatCards(results, data.db_size || 0);
                updateHealthCard(data.health);
                prependHistoryRow(results, data);
                $('#ccdco-stat-last-scan').text(data.scan_time_label || localNow());
                stopScanProgress(true);
                showScanMessage('success', ccdcoAdmin.i18n.scanDone);
                // Activate the cleanup tab
                switchTab('cleanup');
            } else {
                stopScanProgress(false);
                showScanMessage('error',
                    (response && response.data && response.data.message) || ccdcoAdmin.i18n.error
                );
                $('#ccdco-optimize-all').prop('disabled', $('.ccdco-start-cleanup').length === 0);
            }
        }).fail(function (xhr) {
            stopScanProgress(false);
            showScanMessage('error',
                (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || ccdcoAdmin.i18n.error
            );
            $('#ccdco-optimize-all').prop('disabled', $('.ccdco-start-cleanup').length === 0);
        }).always(function () {
            scanRunning = false;
            setScanButtonsRunning(false);
        });
    }

    $(document).on('click', '#ccdco-run-scan, #ccdco-run-scan-empty', function (e) {
        e.preventDefault();
        runScan();
    });

    /* ============================================================
       HEALTH SCORE CARD (always clamped to 0-100)
       ============================================================ */

    function healthGrade(score) {
        return score >= 90 ? 'excellent' : (score >= 75 ? 'good' : (score >= 50 ? 'notice' : 'warning'));
    }

    function updateHealthCard(health) {
        var $card = $('#ccdco-health-card');
        if (!$card.length || !health || typeof health.score === 'undefined') return;

        var score = Math.max(0, Math.min(100, Math.round(Number(health.score) || 0)));
        var grade = healthGrade(score);

        $card.attr('data-grade', grade);
        $('#ccdco-stat-health').text(score);
        $('#ccdco-stat-health-suffix').removeAttr('hidden');
        $('#ccdco-stat-health-label').text(health.label || '');
        $('#ccdco-health-bar-fill').css('width', score + '%');
    }

    /* ============================================================
       RENDER CLEANUP LIST (after AJAX scan)
       ============================================================ */

    function iconForId(id) {
        var icons = {
            post_revision:       'dashicons-backup',
            auto_draft:          'dashicons-edit',
            transient:           'dashicons-clock',
            orphaned_postmeta:   'dashicons-admin-generic',
            orphaned_termmeta:   'dashicons-tag',
            orphaned_commentmeta:'dashicons-admin-comments',
            autoloaded_option:   'dashicons-performance',
            action_scheduler:    'dashicons-calendar-alt',
        };
        for (var key in icons) {
            if (id.indexOf(key) !== -1) return icons[key];
        }
        return 'dashicons-database-view';
    }

    function severityIconClass(severity) {
        if (severity === 'warning') return 'ccdco-row-icon-warning';
        if (severity === 'notice')  return 'ccdco-row-icon-notice';
        return 'ccdco-row-icon-info';
    }

    function renderCleanupList(results) {
        var $list = $('#ccdco-cleanup-list');
        if (!$list.length) return;

        // First scan on a fresh install: the "No scan results yet" box is showing.
        // Swap it for the results area so findings appear without a page refresh.
        $('#ccdco-cleanup-empty').attr('hidden', true);
        $('#ccdco-cleanup-results').removeAttr('hidden');

        var html = '';
        var hasItems = false;

        $.each(results, function (id, result) {
            if (!result || id === 'table_size') return;
            hasItems = true;

            var title      = result.title || result.label || id;
            var desc       = result.description || '';
            var severity   = result.severity || 'info';
            var canCleanup = !!result.can_cleanup;
            var count      = Number(result.count || 0);
            var size       = Number(result.size || 0);
            var icon       = iconForId(id);
            var isOrphan   = id.indexOf('orphaned') !== -1;

            var countBadge = (canCleanup && count > 0)
                ? '<span class="ccdco-count-badge">' + count.toLocaleString() + '</span>'
                : '<span class="ccdco-count-badge ccdco-count-zero">0</span>';

            var actionBtn = (canCleanup && count > 0)
                ? '<button type="button" class="ccdco-btn ccdco-btn-clean ccdco-start-cleanup"' +
                  ' data-scanner="' + esc(id) + '"' +
                  ' data-count="' + count + '"' +
                  ' data-size="' + esc(formatBytes(size)) + '"' +
                  ' data-size-bytes="' + size + '">' +
                  '<span class="dashicons dashicons-trash" aria-hidden="true"></span>Clean</button>'
                : '<span class="ccdco-clean-done"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>Clean</span>';

            var details      = $.isArray(result.details) ? result.details : [];
            var hasPreview   = isOrphan && details.length > 0;

            var orphanPill = hasPreview
                ? '<button type="button" class="ccdco-pill ccdco-pill-warning ccdco-toggle-preview" aria-expanded="false">Review</button>'
                : (isOrphan ? '<span class="ccdco-pill ccdco-pill-warning">Review</span>' : '');

            var previewHtml = '';
            if (hasPreview) {
                var rowsHtml = '';
                $.each(details, function (i, item) {
                    item = item || {};
                    rowsHtml +=
                        '<tr>' +
                            '<td>' + esc(item.id) + '</td>' +
                            '<td>' + esc(item.parent_id) + '</td>' +
                            '<td><code>' + esc(item.meta_key_name) + '</code></td>' +
                            '<td>' + esc(item.meta_preview) + '</td>' +
                        '</tr>';
                });

                var moreHtml = (count > details.length)
                    ? '<p class="ccdco-preview-more">Showing ' + details.length.toLocaleString() + ' of ' + count.toLocaleString() + ' eligible records.</p>'
                    : '';

                previewHtml =
                    '<div class="ccdco-row-preview" hidden>' +
                        '<div class="ccdco-table-wrap">' +
                            '<table class="ccdco-preview-table">' +
                                '<thead><tr>' +
                                    '<th>Meta ID</th><th>Missing Parent ID</th><th>Meta Key</th><th>Meta Value (preview)</th>' +
                                '</tr></thead>' +
                                '<tbody>' + rowsHtml + '</tbody>' +
                            '</table>' +
                        '</div>' +
                        moreHtml +
                    '</div>';
            }

            html +=
                '<div class="ccdco-cleanup-row' + (canCleanup && count > 0 ? ' ccdco-has-action' : '') + '"' +
                ' data-scanner="' + esc(id) + '" data-count="' + count + '" data-size="' + size + '">' +
                    '<div class="ccdco-row-icon ' + esc(severityIconClass(severity)) + '">' +
                        '<span class="dashicons ' + esc(icon) + '" aria-hidden="true"></span>' +
                    '</div>' +
                    '<div class="ccdco-row-info">' +
                        '<div class="ccdco-row-title-line">' +
                            '<strong class="ccdco-row-title">' + esc(title) + '</strong>' + orphanPill +
                        '</div>' +
                        (desc ? '<span class="ccdco-row-desc">' + esc(desc) + '</span>' : '') +
                    '</div>' +
                    '<div class="ccdco-row-meta">' +
                        countBadge +
                        '<span class="ccdco-size-label">' + esc(formatBytes(size)) + '</span>' +
                    '</div>' +
                    '<div class="ccdco-row-action">' + actionBtn + '</div>' +
                    '<div class="ccdco-row-progress" hidden aria-live="polite">' +
                        '<div class="ccdco-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">' +
                            '<div class="ccdco-progress-fill"></div>' +
                        '</div>' +
                        '<span class="ccdco-progress-label"></span>' +
                    '</div>' +
                    previewHtml +
                '</div>';
        });

        if (!hasItems) {
            html = '<div class="ccdco-empty-panel">' +
                   '<span class="dashicons dashicons-yes-alt ccdco-empty-icon" aria-hidden="true"></span>' +
                   '<p>Your database looks clean — no items need optimization.</p>' +
                   '</div>';
        }

        $list.html(html);
    }


    /* ============================================================
       HISTORY TAB – add the new scan without a reload
       ============================================================ */

    function prependHistoryRow(results, data) {
        if (!ccdcoAdmin.historyEnabled) return;

        var issues = 0, cleanable = 0;
        $.each(results || {}, function (id, r) {
            if (!r) return;
            if (r.severity && r.severity !== 'info') issues++;
            if (r.can_cleanup) cleanable += Number(r.size) || 0;
        });

        var score = Math.max(0, Math.min(100, Number((data.health && data.health.score) || 0)));
        var grade = healthGrade(score);
        var row =
            '<tr>' +
                '<td>' + esc(data.scan_time_label || localNow()) + '</td>' +
                '<td>' + esc(formatBytes(data.db_size || 0)) + '</td>' +
                '<td><span class="ccdco-score-chip ccdco-score-' + grade + '">' + score + '/100</span></td>' +
                '<td>' + issues.toLocaleString() + '</td>' +
                '<td>' + esc(formatBytes(cleanable)) + '</td>' +
                '<td>' + esc(Number(data.duration || 0)) + 's</td>' +
            '</tr>';

        var $panel = $('#ccdco-panel-history');
        var $tbody = $panel.find('tbody');
        if ($tbody.length) {
            $tbody.prepend(row);
            return;
        }

        // History was empty: replace the placeholder with a real table.
        $panel.find('.ccdco-empty-panel').replaceWith(
            '<div class="ccdco-table-wrap"><table class="ccdco-table widefat striped"><thead><tr>' +
            '<th>' + esc(ccdcoAdmin.i18n.colDate) + '</th><th>' + esc(ccdcoAdmin.i18n.colDbSize) + '</th>' +
            '<th>' + esc(ccdcoAdmin.i18n.colScore) + '</th><th>' + esc(ccdcoAdmin.i18n.colIssues) + '</th>' +
            '<th>' + esc(ccdcoAdmin.i18n.colReclaimable) + '</th><th>' + esc(ccdcoAdmin.i18n.colDuration) + '</th>' +
            '</tr></thead><tbody>' + row + '</tbody></table></div>'
        );
    }

    /* ============================================================
       TOGGLE ORPHAN "REVIEW" PREVIEW
       ============================================================ */

    $(document).on('click', '.ccdco-toggle-preview', function (e) {
        e.stopPropagation();
        var $btn     = $(this);
        var $row     = $btn.closest('.ccdco-cleanup-row');
        var $preview = $row.find('.ccdco-row-preview');
        if (!$preview.length) return;

        var expanded = $btn.attr('aria-expanded') === 'true';
        $btn.attr('aria-expanded', expanded ? 'false' : 'true');
        $preview.prop('hidden', expanded);
    });

    /* ============================================================
       STAT CARDS UPDATE
       ============================================================ */

    function updateStatCards(results, dbSize) {
        var items         = 0;
        var cleanableSize = 0;

        $.each(results || {}, function (id, result) {
            if (!result || id === 'table_size') return;
            if (result.can_cleanup && Number(result.count) > 0) {
                items++;
                cleanableSize += Number(result.size) || 0;
            }
        });

        $('#ccdco-stat-db-size').text(formatBytes(Number(dbSize) || 0));
        $('#ccdco-stat-items').text(items.toLocaleString());
        $('#ccdco-stat-cleanable').text(formatBytes(cleanableSize));

        // Update tab badge
        if (items > 0) {
            var $badge = $('#ccdco-tab-badge-cleanup');
            if (!$badge.length) {
                $('.ccdco-tab[data-tab="cleanup"]').append(
                    '<span class="ccdco-tab-badge" id="ccdco-tab-badge-cleanup">' + items + '</span>'
                );
            } else {
                $badge.text(items);
            }
        } else {
            $('#ccdco-tab-badge-cleanup').remove();
        }

        // Enable optimize-all hero button
        $('#ccdco-optimize-all').prop('disabled', items === 0);
    }

    /**
     * Live-update stat cards from server-provided aggregate stats.
     * Called after each cleanup completes so cards stay current
     * without requiring a manual rescan.
     */
    function updateStatCardsFromServer(stats) {
        if (typeof stats.db_size !== 'undefined') {
            $('#ccdco-stat-db-size').text(formatBytes(Number(stats.db_size) || 0));
        }

        if (typeof stats.cleanable_count !== 'undefined') {
            var items = Number(stats.cleanable_count) || 0;
            $('#ccdco-stat-items').text(items.toLocaleString());

            if (items > 0) {
                var $badge = $('#ccdco-tab-badge-cleanup');
                if (!$badge.length) {
                    $('.ccdco-tab[data-tab="cleanup"]').append(
                        '<span class="ccdco-tab-badge" id="ccdco-tab-badge-cleanup">' + items + '</span>'
                    );
                } else {
                    $badge.text(items);
                }
            } else {
                $('#ccdco-tab-badge-cleanup').remove();
            }

            $('#ccdco-optimize-all').prop('disabled', items === 0);
        }

        if (typeof stats.cleanable_size !== 'undefined') {
            $('#ccdco-stat-cleanable').text(formatBytes(Number(stats.cleanable_size) || 0));
        }
    }

    /* ============================================================
       CONFIRM MODAL
       ============================================================ */

    var pendingCleanupButton = null;
    var pendingIsOptimizeAll = false;

    function openConfirmModal($btn, count, size) {
        pendingCleanupButton = $btn;
        $('#ccdco-modal-count').text(Number(count !== undefined ? count : ($btn.data('count') || 0)).toLocaleString());
        $('#ccdco-modal-size').text(String(size !== undefined ? size : ($btn.data('size') || '0 B')));

        $('#ccdco-modal-confirm').prop('disabled', false);
        $('#ccdco-confirm-modal').removeAttr('hidden').attr('aria-hidden', 'false');
        $('body').addClass('ccdco-modal-open');
        $('#ccdco-modal-cancel').focus();
    }

    function closeConfirmModal() {
        $('#ccdco-confirm-modal').attr('hidden', true).attr('aria-hidden', 'true');
        $('body').removeClass('ccdco-modal-open');
        $('#ccdco-modal-confirm').prop('disabled', false);
        pendingCleanupButton = null;
        pendingIsOptimizeAll = false;
    }

    function openResultModal(processed, remaining, payload) {
        $('#ccdco-result-processed').text(Number(processed || 0).toLocaleString());
        $('#ccdco-result-remaining').text(Number(remaining || 0).toLocaleString());
        $('#ccdco-result-payload').text(formatBytes(Number(payload || 0)));
        $('#ccdco-result-modal').removeAttr('hidden').attr('aria-hidden', 'false');
        $('body').addClass('ccdco-modal-open');
    }

    function closeResultModal() {
        $('#ccdco-result-modal').attr('hidden', true).attr('aria-hidden', 'true');
        $('body').removeClass('ccdco-modal-open');
    }

    $(document).on('click', '#ccdco-modal-cancel, #ccdco-modal-close, #ccdco-confirm-modal .ccdco-modal-backdrop', function () {
        closeConfirmModal();
    });
    $(document).on('click', '[data-close-result], #ccdco-result-modal .ccdco-modal-backdrop', function () {
        closeResultModal();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            closeConfirmModal();
            closeResultModal();
            closeUnsavedModal();
        }
    });

    /* ============================================================
       SINGLE-ROW CLEANUP
       ============================================================ */

    function cleanupScanner($btn, onDone) {
        var scannerId = String($btn.data('scanner') || '');
        if (!scannerId) return;

        var $row      = $btn.closest('.ccdco-cleanup-row');
        var $progress = $row.find('.ccdco-row-progress');
        var $fill     = $progress.find('.ccdco-progress-fill');
        var $label    = $progress.find('.ccdco-progress-label');
        var $action   = $row.find('.ccdco-row-action');

        // Visual: button → spinner state
        $btn.prop('disabled', true).html(
            '<span class="dashicons dashicons-update-alt" aria-hidden="true" style="animation:spin 1s linear infinite"></span> Cleaning…'
        );
        $progress.removeAttr('hidden');
        $fill.css('width', '5%');
        $label.text('Starting…');

        var totalFromBtn = Math.max(1, Number($btn.data('count') || 1));
        var totalProcessed = 0;

        function nextBatch() {
            post('ccdco_cleanup_batch', { scanner: scannerId, confirm: '1' })
                .done(function (response) {
                    if (!response || !response.success) {
                        var msg = (response && response.data && response.data.message) || ccdcoAdmin.i18n.error;
                        $label.text('Error: ' + msg).css('color', 'var(--ccdco-red)');
                        $btn.prop('disabled', false).html(
                            '<span class="dashicons dashicons-trash" aria-hidden="true"></span> Clean'
                        );
                        if (typeof onDone === 'function') onDone(false);
                        return;
                    }

                    var data      = response.data || {};
                    var remaining = Number(data.remaining || 0);
                    var processed = Number(data.processed || 0);
                    var done      = !!data.done;

                    totalProcessed += processed;
                    var pct = done ? 100 : Math.min(95, Math.max(10,
                        ((totalFromBtn - remaining) / totalFromBtn) * 100
                    ));

                    $fill.css('width', pct + '%').closest('[role="progressbar"]').attr('aria-valuenow', Math.round(pct));
                    $label.text(totalProcessed.toLocaleString() + ' records cleaned' + (done ? '.' : '…'));

                    if (!done) {
                        nextBatch();
                        return;
                    }

                    // Done — update row to "clean" state
                    $action.html(
                        '<span class="ccdco-clean-done">' +
                        '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>Done' +
                        '</span>'
                    );
                    $row.find('.ccdco-count-badge').text('0').addClass('ccdco-count-zero').removeClass('ccdco-start-cleanup');
                    $progress.attr('hidden', true);

                    // Auto-update stat cards from server-provided aggregate stats
                    if (data.updated_stats) {
                        updateStatCardsFromServer(data.updated_stats);
                    }

                    if (typeof onDone === 'function') onDone(true, data);
                })
                .fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || ccdcoAdmin.i18n.error;
                    $label.text('Error: ' + msg).css('color', 'var(--ccdco-red)');
                    $btn.prop('disabled', false).html(
                        '<span class="dashicons dashicons-trash" aria-hidden="true"></span> Clean'
                    );
                    if (typeof onDone === 'function') onDone(false);
                });
        }

        nextBatch();
    }

    // Single clean button click → confirm modal
    $(document).on('click', '.ccdco-start-cleanup', function () {
        pendingIsOptimizeAll = false;
        openConfirmModal($(this));
    });

    // Confirm → run cleanup
    $(document).on('click', '#ccdco-modal-confirm', function () {
        if (!pendingCleanupButton) return;

        var $btn         = pendingCleanupButton;
        var isOptimizeAll = pendingIsOptimizeAll;
        closeConfirmModal();

        if (isOptimizeAll) {
            runOptimizeAll();
            return;
        }

        cleanupScanner($btn, function (success, data) {
            if (success && data) {
                openResultModal(data.processed, data.remaining, data.payload_bytes || 0);
            }
        });
    });

    /* ============================================================
       OPTIMIZE ALL
       ============================================================ */

    function runOptimizeAll() {
        var $btns = $('.ccdco-start-cleanup:not(:disabled)').toArray();
        if (!$btns.length) return;

        var totalCleaned = 0;
        var totalPayload = 0;

        // Disable the optimize-all button during run
        $('#ccdco-optimize-all').prop('disabled', true).text('Optimizing…');

        function runNext(index) {
            if (index >= $btns.length) {
                // All done
                $('#ccdco-optimize-all').prop('disabled', false).html(
                    '<span class="dashicons dashicons-superhero" aria-hidden="true"></span> Optimize All'
                );
                openResultModal(totalCleaned, 0, totalPayload);
                return;
            }

            var $btn = $($btns[index]);
            if ($btn.prop('disabled') || !$btn.hasClass('ccdco-start-cleanup')) {
                runNext(index + 1);
                return;
            }

            cleanupScanner($btn, function (success, data) {
                if (success && data) {
                    totalCleaned += Number(data.processed || 0);
                    totalPayload += Number(data.payload_bytes || 0);
                }
                runNext(index + 1);
            });
        }

        runNext(0);
    }

    // Optimize All → confirm modal
    $(document).on('click', '#ccdco-optimize-all:not(:disabled)', function () {
        var $btns = $('.ccdco-start-cleanup:not(:disabled)');
        if (!$btns.length) return;

        var totalCount = 0;
        var totalSize  = 0;
        $btns.each(function () {
            totalCount += Number($(this).data('count') || 0);
            totalSize  += Number($(this).data('size-bytes') || 0);
        });

        pendingIsOptimizeAll = true;
        openConfirmModal(
            $btns.first(),
            totalCount,
            totalSize ? formatBytes(totalSize) : String($btns.first().data('size') || '0 B')
        );
    });

    /* ============================================================
       DATABASE TABLES TAB – PAGINATION, SEARCH, FILTER & SCAN
       ============================================================ */

    var tablesList = (window.ccdcoAdmin && window.ccdcoAdmin.initialTables && Array.isArray(window.ccdcoAdmin.initialTables))
        ? window.ccdcoAdmin.initialTables.filter(function (row) { return row && row.status === 'possible_orphan'; })
        : [];

    var tablesState = {
        page: 1,
        pageSize: 10,
        search: ''
    };

    function getFilteredTables() {
        var q = (tablesState.search || '').trim().toLowerCase();
        return tablesList.filter(function (row) {
            if (!row || row.status !== 'possible_orphan') {
                return false;
            }
            if (!q) {
                return true;
            }
            var name = String(row.table_name || row.name || '').toLowerCase();
            var source = String(row.possible_source || row.source_name || '').toLowerCase();
            var detection = String(row.source_detection || row.source_note || '').toLowerCase();
            return name.indexOf(q) !== -1 || source.indexOf(q) !== -1 || detection.indexOf(q) !== -1;
        });
    }

    function renderTablesManager() {
        var $wrap = $('#ccdco-tables-wrap');
        if (!$wrap.length) return;

        if (!tablesList.length) {
            $wrap.html(
                '<div class="ccdco-empty-panel">' +
                '<span class="dashicons dashicons-database-view ccdco-empty-icon" aria-hidden="true"></span>' +
                '<p>No possible orphaned tables are currently recorded.</p>' +
                '<p style="font-size:12px;color:var(--ccdco-ink-muted);max-width:620px;margin:0 auto">Run a fresh scan. The scanner automatically inspects installed plugins and themes, learns their table names, and keeps the source relationship for future orphan detection.</p>' +
                '<button type="button" class="ccdco-btn ccdco-btn-primary" id="ccdco-tables-scan-trigger" style="margin-top:14px">' +
                '<span class="dashicons dashicons-search" aria-hidden="true"></span> Scan Tables Now' +
                '</button>' +
                '</div>'
            );
            return;
        }

        var filtered = getFilteredTables();
        var totalFiltered = filtered.length;
        var pageSize = tablesState.pageSize > 0 ? tablesState.pageSize : totalFiltered;
        var totalPages = pageSize > 0 ? Math.max(1, Math.ceil(totalFiltered / pageSize)) : 1;
        tablesState.page = Math.min(Math.max(1, tablesState.page), totalPages);

        var startIndex = pageSize > 0 ? (tablesState.page - 1) * pageSize : 0;
        var endIndex = pageSize > 0 ? Math.min(startIndex + pageSize, totalFiltered) : totalFiltered;
        var currentRows = pageSize > 0 ? filtered.slice(startIndex, endIndex) : filtered;
        var totalSize = 0;
        $.each(tablesList, function (i, row) {
            totalSize += Number(row.total_size !== undefined ? row.total_size : (row.data_bytes || 0)) || 0;
        });

        var html = '';
        html += '<div class="ccdco-tables-toolbar">';
        html += '<div class="ccdco-tables-toolbar-left">';
        html += '<div class="ccdco-tables-search-wrap">';
        html += '<span class="dashicons dashicons-search" aria-hidden="true"></span>';
        html += '<input type="search" id="ccdco-table-search" class="ccdco-tables-search-input" placeholder="Search table, plugin, or detection…" value="' + esc(tablesState.search) + '">';
        html += '</div></div>';
        html += '<div class="ccdco-tables-toolbar-right"><div class="ccdco-tables-page-size-wrap">';
        html += '<span>Show</span><select id="ccdco-table-page-size" class="ccdco-tables-page-size-select">';
        [10,25,50,100,-1].forEach(function (size) {
            html += '<option value="' + size + '"' + (tablesState.pageSize === size ? ' selected' : '') + '>' + (size === -1 ? 'All' : size) + '</option>';
        });
        html += '</select><span>per page</span></div></div></div>';

        html += '<div class="ccdco-tables-meta-bar">';
        html += '<span>' + (totalFiltered ? 'Showing <strong>' + (startIndex + 1) + '–' + endIndex + '</strong> of <strong>' + totalFiltered + '</strong> orphaned tables' : 'No matching orphaned tables') + '</span>';
        html += '<span>Orphaned tables: <strong>' + tablesList.length + '</strong> · Estimated size: <strong>' + formatBytes(totalSize) + '</strong></span>';
        html += '</div>';

        html += '<div class="ccdco-table-wrap"><table class="ccdco-table widefat striped">';
        html += '<thead><tr><th>Table Name</th><th>Possible Source</th><th>Detection</th><th>Confidence</th><th>Rows</th><th>Size</th><th style="text-align:right">Action</th></tr></thead><tbody>';

        if (!currentRows.length) {
            html += '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--ccdco-ink-muted)">No orphaned tables match the current search.</td></tr>';
        } else {
            $.each(currentRows, function (i, row) {
                var tableName = row.table_name || row.name || '';
                var source = row.possible_source || row.source_name || 'Source not recorded';
                var detection = row.source_detection || row.source_note || row.reason || '';
                var confidence = row.source_confidence || 'none';
                var rowCount = Number(row.rows !== undefined ? row.rows : row.row_count || 0);
                var sizeBytes = Number(row.total_size !== undefined ? row.total_size : row.data_bytes || 0);
                var actionBtn = '<button type="button" class="ccdco-btn ccdco-btn-danger ccdco-delete-table" style="padding:4px 10px;font-size:12px" data-table="' + esc(tableName) + '"><span class="dashicons dashicons-trash" aria-hidden="true"></span> Delete</button>';
                html += '<tr>';
                html += '<td><code>' + esc(tableName) + '</code></td>';
                html += '<td><strong>' + esc(source) + '</strong></td>';
                html += '<td style="font-size:12px;color:var(--ccdco-ink-muted)">' + esc(detection) + '</td>';
                html += '<td>' + esc(confidence.charAt(0).toUpperCase() + confidence.slice(1)) + '</td>';
                html += '<td>' + rowCount.toLocaleString() + '</td>';
                html += '<td>' + esc(formatBytes(sizeBytes)) + '</td>';
                html += '<td style="text-align:right">' + actionBtn + '</td></tr>';
            });
        }
        html += '</tbody></table></div>';

        if (totalPages > 1) {
            html += '<div class="ccdco-pagination-footer"><div class="ccdco-pagination-info">Page ' + tablesState.page + ' of ' + totalPages + ' (' + totalFiltered + ' items)</div><div class="ccdco-pagination-inner">';
            html += '<button type="button" class="ccdco-page-btn ccdco-table-page-btn" data-page="1"' + (tablesState.page === 1 ? ' disabled' : '') + '>«</button>';
            html += '<button type="button" class="ccdco-page-btn ccdco-table-page-btn" data-page="' + (tablesState.page - 1) + '"' + (tablesState.page === 1 ? ' disabled' : '') + '>‹</button>';
            for (var pg = 1; pg <= totalPages; pg++) {
                if (pg > 5 && pg < totalPages - 1) continue;
                html += '<button type="button" class="ccdco-page-btn ccdco-table-page-btn' + (pg === tablesState.page ? ' ccdco-page-current' : '') + '" data-page="' + pg + '">' + pg + '</button>';
            }
            html += '<button type="button" class="ccdco-page-btn ccdco-table-page-btn" data-page="' + (tablesState.page + 1) + '"' + (tablesState.page === totalPages ? ' disabled' : '') + '>›</button>';
            html += '<button type="button" class="ccdco-page-btn ccdco-table-page-btn" data-page="' + totalPages + '"' + (tablesState.page === totalPages ? ' disabled' : '') + '>»</button></div></div>';
        }
        $wrap.html(html);
    }

    $(document).on('input', '#ccdco-table-search', function () {
        tablesState.search = $(this).val();
        tablesState.page = 1;
        renderTablesManager();
        var $input = $('#ccdco-table-search');
        if ($input.length) {
            var val = $input.val();
            $input.focus().val('').val(val);
        }
    });

    $(document).on('change', '#ccdco-table-page-size', function () {
        tablesState.pageSize = parseInt($(this).val(), 10);
        tablesState.page = 1;
        renderTablesManager();
    });

    $(document).on('click', '.ccdco-table-page-btn:not(:disabled)', function () {
        var targetPage = parseInt($(this).data('page'), 10);
        if (targetPage && targetPage !== tablesState.page) {
            tablesState.page = targetPage;
            renderTablesManager();
        }
    });

    $(document).on('click', '#ccdco-tables-scan, #ccdco-tables-scan-trigger', function () {
        var $btn   = $('#ccdco-tables-scan');
        var $wrap  = $('#ccdco-tables-wrap');
        var force  = $('#ccdco-tables-force-refresh').is(':checked');
        var loadingText = force
            ? 'Scanning installed plugins and themes for table definitions and references, then checking which tables have no current source. A full rescan can take a little while on sites with many plugins.'
            : 'Checking your database tables against the cached plugin/theme ownership index for a quick result.';
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt" aria-hidden="true" style="animation:spin 1s linear infinite"></span> Scanning…');
        $wrap.html('<div class="ccdco-empty-panel" style="padding:48px 24px"><span class="dashicons dashicons-update-alt ccdco-empty-icon" style="font-size:32px;width:32px;height:32px;animation:spin 1s linear infinite;color:var(--ccdco-blue);display:block;margin:0 auto 12px"></span><h3 style="margin:0 0 6px;font-size:15px;color:var(--ccdco-ink)">Learning plugin and table ownership…</h3><p style="margin:0;color:var(--ccdco-ink-muted);font-size:13px">' + loadingText + '</p></div>');
        post('ccdco_unused_tables_scan', { force: force ? '1' : '0' }).done(function (response) {
            if (response && response.success) {
                tablesList = ((response.data && response.data.rows) || []).filter(function (row) { return row && row.status === 'possible_orphan'; });
                tablesState.page = 1;
                renderTablesManager();
            } else {
                $wrap.html('<div class="ccdco-empty-panel"><p style="color:var(--ccdco-red);font-weight:600">Error scanning tables. Please try again.</p><button type="button" class="ccdco-btn ccdco-btn-secondary" id="ccdco-tables-scan-trigger">Retry Scan</button></div>');
            }
        }).fail(function () {
            $wrap.html('<div class="ccdco-empty-panel"><p style="color:var(--ccdco-red);font-weight:600">Request failed or timed out. Please try again.</p><button type="button" class="ccdco-btn ccdco-btn-secondary" id="ccdco-tables-scan-trigger">Retry Scan</button></div>');
        }).always(function () {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-search" aria-hidden="true"></span> Scan Tables');
        });
    });

    $(document).on('click', '.ccdco-delete-table', function () {
        var $btn = $(this);
        var table = String($btn.data('table') || '');
        if (!table) return;
        if (!window.confirm(ccdcoAdmin.i18n.confirmDelete + '\n\n' + table)) return;
        var typed = window.prompt(ccdcoAdmin.i18n.typeTableName);
        if (!typed || typed.trim() !== table) {
            window.alert(ccdcoAdmin.i18n.tableNameMismatch);
            return;
        }
        // Disable every delete button while this is in flight so a stray click on another
        // row can't fire a second overlapping request.
        var $allDeleteBtns = $('.ccdco-delete-table');
        $allDeleteBtns.prop('disabled', true);
        $btn.html('<span class="dashicons dashicons-update-alt" aria-hidden="true" style="animation:spin 1s linear infinite"></span> Deleting…');
        post('ccdco_delete_unused_table', { table: table, confirm: '1' }).done(function (response) {
            if (response && response.success) {
                // The server removes exactly the deleted row from the list it already had
                // (rather than re-deriving the whole list from a fresh scan), so applying
                // rows here and re-rendering is enough — no page reload needed, and the
                // count always drops by exactly one.
                tablesList = (((response.data && response.data.rows) || []).filter(function (row) { return row && row.status === 'possible_orphan'; }));
                renderTablesManager();
            } else {
                window.alert((response && response.data && response.data.message) || ccdcoAdmin.i18n.error);
                $allDeleteBtns.prop('disabled', false);
                $btn.html('<span class="dashicons dashicons-trash" aria-hidden="true"></span> Delete');
            }
        }).fail(function () {
            window.alert(ccdcoAdmin.i18n.error);
            $allDeleteBtns.prop('disabled', false);
            $btn.html('<span class="dashicons dashicons-trash" aria-hidden="true"></span> Delete');
        });
    });

    if (tablesList && tablesList.length) {
        renderTablesManager();
    }

    /* ============================================================
       CLEAR HISTORY
       ============================================================ */

    $(document).on('click', '#ccdco-clear-history', function () {
        if (!window.confirm('Clear all scan history? This cannot be undone.')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Clearing…');

        post('ccdco_clear_history', {}).done(function (response) {
            if (response && response.success) {
                $btn.closest('.ccdco-panel').find('tbody').closest('.ccdco-table-wrap').fadeOut(300, function () {
                    $(this).replaceWith(
                        '<div class="ccdco-empty-panel">' +
                        '<span class="dashicons dashicons-chart-line ccdco-empty-icon"></span>' +
                        '<p>History cleared. Run a scan to start tracking again.</p>' +
                        '</div>'
                    );
                });
                $btn.remove();
            } else {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-trash" aria-hidden="true"></span> Clear History'
                );
            }
        }).fail(function () {
            $btn.prop('disabled', false).html(
                '<span class="dashicons dashicons-trash" aria-hidden="true"></span> Clear History'
            );
        });
    });

    /* ============================================================
       SPIN ANIMATION (injected for loading states)
       ============================================================ */
    if (!$('#ccdco-spin-style').length) {
        $('head').append(
            '<style id="ccdco-spin-style">' +
            '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }' +
            '</style>'
        );
    }

    /* ============================================================
       INIT
       ============================================================ */

    // Legacy pagination (scan-results standalone page no longer needed,
    // but keep initPagination in case the view references it)
    if (typeof initPagination === 'function') {
        initPagination();
    }

})(jQuery);
