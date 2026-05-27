// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AI Instructions chat interface AMD module.
 *
 * @module     bookingextension_agent/aiinstructions
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Fragment from 'core/fragment';
import Notification from 'core/notification';
import Templates from 'core/templates';

/** Pending commands waiting for user confirmation. */
let pendingCommands = null;
let pendingQueueItemId = '';
let currentThreadId = 0;
let currentContextId = 0;
let debugModeEnabled = false;
let sessionAutoConfirmEnabled = false;
let privacyCheckRunningLabel = 'Privacy check running...';
let privacyAnswerNoteLabel = 'Privacy note: personal data in this response was de-anonymized for display.';
let stepPlanningLabel = 'Planning...';
let stepExecutingLabel = 'Executing...';
let defaultThinkingLabel = '';
let forceNewThreadOnFirstMessage = true;
let trialTokenInvalidMessageLabel = '';
let bodyHandlersBound = false;
/** @type {HTMLElement|null} */
let activePlanBubble = null;

/** Step-progress polling: interval handle, last-seen message id, active step bubble elements. */
let stepPollInterval = null;
let lastSeenStepId = 0;
/** @type {Array<HTMLElement>} */
let activeStepBubbles = [];
/** Set to true when the user clicks Stop to discard the pending LLM response. */
let sendAborted = false;

/** @type {Array<string>} */
const TRIAL_TOKEN_ISSUE_CODES = [
    'TRIAL_TOKEN_INVALID',
    'TRIAL_TOKEN_EXPIRED',
    'SUBSCRIPTION_REQUIRED',
    'AI_PROVIDER_AUTH_FAILED',
    'AI_PROVIDER_QUOTA_EXCEEDED',
];

/**
 * Execute collected JavaScript returned by Moodle web service responses.
 *
 * @param {string} javascript
 */
const runCollectedJavascript = (javascript) => {
    const js = Fragment.processCollectedJavascript(String(javascript || ''));
    if (js && js.trim() !== '') {
        Templates.runTemplateJS(js);
    }
};

/** @type {Array<string>} */
const READ_ONLY_TASKS = [
    'booking.search_options',
    'booking.search_users',
    'booking.search_courses',
    'booking.get_current_user',
    'booking.list_option_properties',
    'booking.list_actions',
    'entities.search',
    'entities.list_all_entities',
    'shopping_cart.get_items',
    'shopping_cart.get_totals',
];

/**
 * Returns true when all commands are read-only and can run without confirm button.
 *
 * @param {Array} commands
 * @returns {boolean}
 */
const shouldAutoExecuteReadOnly = (commands) => {
    if (!Array.isArray(commands) || commands.length === 0) {
        return false;
    }

    return commands.every((cmd) => {
        const task = String((cmd && cmd.task) || '');
        return READ_ONLY_TASKS.includes(task);
    });
};

/**
 * Render compact debug metadata below a message bubble.
 *
 * @param {Object|null} meta
 * @returns {string}
 */
const renderMessageDebugMeta = (meta) => {
    if (!debugModeEnabled || !meta || typeof meta !== 'object') {
        return '';
    }

    const keys = [
        'response_type',
        'threadid',
        'runid',
        'commands_count',
        'results_count',
        'loop_step',
        'loop_max_steps',
        'llm_commands_json',
        'attempted_tasks',
        'issue_codes',
        'pending_confirmation_code',
        'errors',
        'status',
        'source',
        'time',
    ];
    const parts = [];
    keys.forEach((key) => {
        if (Object.prototype.hasOwnProperty.call(meta, key) && meta[key] !== null && meta[key] !== '') {
            parts.push(`${key}=${String(meta[key])}`);
        }
    });

    if (parts.length === 0) {
        return '';
    }

    return `<div class="booking-ai-msg-debug">${escapeHtml(parts.join(' | '))}</div>`;
};

/**
 * Render an optional pretty JSON block for debug command payloads.
 *
 * @param {Object|null} meta
 * @returns {string}
 */
const renderMessageDebugJson = (meta) => {
    if (!debugModeEnabled || !meta || typeof meta !== 'object') {
        return '';
    }

    const raw = String(meta.llm_commands_json || '').trim();
    if (raw === '') {
        return '';
    }

    let pretty = raw;
    try {
        pretty = JSON.stringify(JSON.parse(raw), null, 2);
    } catch (e) {
        // Keep raw if parsing fails.
    }

    return '<details class="booking-ai-debug-json">'
        + '<summary>LLM Task JSON</summary>'
        + `<pre>${escapeHtml(pretty)}</pre>`
        + '</details>';
};

/**
 * Render collapsible LLM debug logs (source, request, response).
 *
 * @param {Array} debugLogs Array of debug log objects {id, timecreated, source, success, requesttext, responsetext}
 * @returns {string}
 */
const renderDebugLogs = (debugLogs) => {
    if (!debugModeEnabled || !Array.isArray(debugLogs) || debugLogs.length === 0) {
        return '';
    }

    const plainTextLogs = formatDebugLogsForClipboard(debugLogs);

    let html = '<details class="booking-ai-debug-logs">'
        + `<summary>LLM Debug Logs (${debugLogs.length})</summary>`
        + '<div class="p-3">';

    // Add copy button
    html += '<button type="button" '
        + 'class="btn btn-sm btn-outline-secondary mb-2 booking-ai-copy-debug-logs" '
        + 'title="Copy all debug logs to clipboard">'
        + '📋 Copy All Logs'
        + '</button>'
        + `<pre class="booking-ai-debug-logs-plain d-none">${escapeHtml(plainTextLogs)}</pre>`;

    debugLogs.forEach((entry, index) => {
        const source = String(entry.source || '').trim();
        const success = Number(entry.success) === 1 ? '✓' : '✗';
        const timestamp = entry.timecreated
            ? new Date(entry.timecreated * 1000).toLocaleTimeString()
            : 'N/A';
        const borderClass = Number(entry.success) === 1 ? 'border-success' : 'border-danger';

        html += `<div class="booking-ai-debug-log-entry mb-3 p-2 border rounded ${borderClass}">`;
        html += `<strong>Entry ${index + 1}</strong> [${success}] <code>${escapeHtml(source)}</code> `
            + `<small class="text-muted">${timestamp}</small>`;

        const requesttext = String(entry.requesttext || '').trim();
        if (requesttext) {
            html += '<details class="ml-3 mt-2">'
                + '<summary>Request</summary>'
                + `<pre class="bg-light p-2 small mb-0"><code>${escapeHtml(requesttext)}</code></pre>`
                + '</details>';
        }

        const responsetext = String(entry.responsetext || '').trim();
        if (responsetext) {
            html += '<details class="ml-3 mt-2">'
                + '<summary>Response</summary>'
                + `<pre class="bg-light p-2 small mb-0"><code>${escapeHtml(responsetext)}</code></pre>`
                + '</details>';
        }

        html += '</div>';
    });

    html += '</div></details>';
    return html;
};

/**
 * Format debug entries into a plain-text export.
 *
 * @param {Array} debugLogs
 * @returns {string}
 */
const formatDebugLogsForClipboard = (debugLogs) => {
    if (!Array.isArray(debugLogs) || debugLogs.length === 0) {
        return '';
    }

    let text = 'LLM DEBUG LOGS\n';
    text += '='.repeat(60) + '\n\n';

    debugLogs.forEach((entry, index) => {
        text += `Entry ${index + 1}:\n`;
        text += `  Source: ${String(entry.source || '')}\n`;
        text += `  Success: ${Number(entry.success) === 1 ? 'Yes' : 'No'}\n`;
        const timestamp = entry.timecreated ? new Date(entry.timecreated * 1000).toISOString() : 'N/A';
        text += `  Time: ${timestamp}\n`;

        const requestText = String(entry.requesttext || '').trim();
        if (requestText !== '') {
            text += `  Request:\n${requestText}\n`;
        }

        const responseText = String(entry.responsetext || '').trim();
        if (responseText !== '') {
            text += `  Response:\n${responseText}\n`;
        }

        const errorMessage = String(entry.errormessage || '').trim();
        if (errorMessage !== '') {
            text += `  Error: ${errorMessage}\n`;
        }

        text += '\n' + '-'.repeat(60) + '\n\n';
    });

    return text;
};

/**
 * Parse JSON-encoded list safely.
 *
 * @param {string} raw
 * @returns {Array}
 */
const parseJsonList = (raw) => {
    try {
        const parsed = JSON.parse(String(raw || '[]'));
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
};

/**
 * Parse JSON-encoded object list safely.
 *
 * @param {string} raw
 * @returns {Array<Object>}
 */
const parseJsonObjectList = (raw) => {
    try {
        const parsed = JSON.parse(String(raw || '[]'));
        if (!Array.isArray(parsed)) {
            return [];
        }
        return parsed.filter((entry) => entry && typeof entry === 'object');
    } catch (e) {
        return [];
    }
};

/**
 * Parse a command payload that may be a JSON array or a single command object.
 *
 * @param {string} raw
 * @returns {Array}
 */
const parseCommandPayload = (raw) => {
    try {
        const parsed = JSON.parse(String(raw || '[]'));
        if (Array.isArray(parsed)) {
            return parsed;
        }
        if (parsed && typeof parsed === 'object' && parsed.task) {
            return [parsed];
        }
        return [];
    } catch (e) {
        return [];
    }
};

/**
 * Force error bubble colors as runtime fallback when theme/CSS cache overrides occur.
 *
 * @param {HTMLElement|null} bubble
 */
const enforceErrorBubbleStyleFallback = (bubble) => {
    if (!bubble) {
        return;
    }

    bubble.style.backgroundColor = '#f8d7da';
    bubble.style.color = '#721c24';
    bubble.style.borderColor = '#f5c6cb';
    bubble.style.borderStyle = 'solid';
    bubble.style.borderWidth = '1px';
};

/**
 * Detect whether an AI error indicates an invalid/expired trial token.
 *
 * @param {Object|null} response
 * @param {Array<string>} errors
 * @param {Array<string>} issueCodes
 * @returns {boolean}
 */
const isTrialTokenInvalidError = (response, errors = [], issueCodes = []) => {
    const normalizedCodes = (Array.isArray(issueCodes) ? issueCodes : []).map((code) => String(code || '').trim().toUpperCase());
    if (normalizedCodes.some((code) => TRIAL_TOKEN_ISSUE_CODES.includes(code))) {
        return true;
    }

    const haystack = [
        String((response && response.displaymessage) || ''),
        String((response && response.message) || ''),
        ...(Array.isArray(errors) ? errors : []),
    ].join(' ').toLowerCase();

    if (!haystack) {
        return false;
    }

    const markers = [
        'invalid token',
        'token is invalid',
        'token expired',
        'expired token',
        'invalid api key',
        'incorrect api key',
        'authenticationerror',
        'rate limit exceeded for api_key',
        'unauthorized',
        '429: rate limit exceeded',
        'limit type: tokens',
        'current limit: 0',
        'remaining: 0',
        'insufficient_quota',
        'insufficient quota',
        'insufficient credits',
        'max budget',
        'budget exceeded',
        'credit balance is too low',
    ];

    return markers.some((marker) => haystack.includes(marker));
};

/**
 * Show a chat bubble when the trial token is no longer valid.
 * Displayed on every message while the token remains invalid.
 *
 * @param {Object|null} response
 * @param {Array<string>} errors
 * @param {Array<string>} issueCodes
 */
const maybeShowTrialTokenInvalidAlert = (response, errors = [], issueCodes = []) => {
    if (!isTrialTokenInvalidError(response, errors, issueCodes)) {
        return;
    }

    const messageText = String(
        (response && (response.displaymessage || response.message))
        || trialTokenInvalidMessageLabel
        || ''
    ).trim();

    if (messageText === '') {
        return;
    }

    appendMessageHtml('assistant', renderAssistantMessageHtml(messageText));
};

/**
 * Render clickable ambiguity options below a clarification message.
 *
 * @param {Array<Object>} options
 * @returns {string}
 */
const renderAmbiguityOptionsHtml = (options = []) => {
    const entries = Array.isArray(options) ? options : [];
    if (entries.length === 0) {
        return '';
    }

    const buttons = entries.map((entry) => {
        const query = String((entry && entry.query) || '').trim();
        const title = String((entry && entry.title) || '').trim();
        const label = String((entry && entry.label) || title || query).trim();
        if (query === '' || label === '') {
            return '';
        }

        return '<button type="button" class="btn btn-sm btn-outline-primary mr-2 mb-2 booking-ai-ambiguity-option"'
            + ` data-query="${escapeHtml(query)}"`
            + ` title="${escapeHtml(query)}">${escapeHtml(label)}</button>`;
    }).filter((button) => button !== '').join('');

    if (buttons === '') {
        return '';
    }

    return '<div class="booking-ai-ambiguity-options mt-3 p-3 border rounded bg-light">'
        + '<div class="font-weight-bold mb-1">Please select the topic you mean</div>'
        + '<div class="small text-muted mb-2">I found multiple matching documentation entries.</div>'
        + `<div class="d-flex flex-wrap">${buttons}</div>`
        + '</div>';
};

/**
 * Render clickable follow-up suggestions below a completed assistant response.
 *
 * @param {Array<Object>} results
 * @returns {string}
 */
const renderFollowUpSuggestionsHtml = (results = []) => {
    const entries = Array.isArray(results) ? results : [];
    const resultWithSuggestions = entries.find(
        (entry) => Array.isArray(entry && entry.suggestions) && entry.suggestions.length > 0
    );
    if (!resultWithSuggestions) {
        return '';
    }

    const suggestions = Array.isArray(resultWithSuggestions.suggestions) ? resultWithSuggestions.suggestions : [];
    const buttons = suggestions.map((entry) => {
        const query = String((entry && entry.query) || '').trim();
        const label = String((entry && entry.label) || query).trim();
        if (query === '' || label === '') {
            return '';
        }

        return '<button type="button" class="btn btn-sm btn-outline-secondary mr-2 mb-2 booking-ai-followup-option"'
            + ` data-query="${escapeHtml(query)}"`
            + ` title="${escapeHtml(query)}">${escapeHtml(label)}</button>`;
    }).filter((button) => button !== '').join('');

    if (buttons === '') {
        return '';
    }

    const followUpMessage = String((resultWithSuggestions && resultWithSuggestions.followupmessage) || '').trim();
    const introHtml = followUpMessage !== ''
        ? `<div class="font-weight-bold mb-2">${escapeHtml(followUpMessage)}</div>`
        : '';

    return '<div class="booking-ai-followup-options mt-3 p-3 border rounded bg-light">'
        + introHtml
        + `<div class="d-flex flex-wrap">${buttons}</div>`
        + '</div>';
};

/**
 * Append a chat bubble to the message list.
 *
 * @param {string} role      'user' | 'assistant'
 * @param {string} content   Message text.
 * @param {Object|null} meta Compact debug metadata.
 * @returns {HTMLElement|null}
 */
const appendMessage = (role, content, meta = null) => {
    const list = document.getElementById('booking-ai-messages');
    if (!list) {
        return null;
    }
    const div = document.createElement('div');
    div.classList.add('booking-ai-msg', role);
    const bubbleContent = role === 'assistant'
        ? renderAssistantMessageHtml(content)
        : escapeHtml(content);
    div.innerHTML = `<div class="bubble">${bubbleContent}</div>`
        + `${renderMessageDebugMeta(meta)}${renderMessageDebugJson(meta)}`;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
    return div;
};

/**
 * Append a small privacy status line (not a normal chat bubble).
 *
 * @param {string} content
 * @param {Object|null} meta
 */
const appendPrivacyNote = (content, meta = null) => {
    const list = document.getElementById('booking-ai-messages');
    if (!list) {
        return;
    }
    const div = document.createElement('div');
    div.classList.add('booking-ai-privacy-note');
    div.innerHTML = `<span>${escapeHtml(content)}</span>${renderMessageDebugMeta(meta)}`;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
};

/**
 * Append the privacy note for assistant responses when display de-masking was applied.
 *
 * @param {Object|null} response
 * @param {string} source
 */
const appendAssistantPrivacyNote = (response, source = 'ai_send_message') => {
    if (!response || Number(response.privacyapplied || 0) !== 1) {
        return;
    }

    appendPrivacyNote(privacyAnswerNoteLabel, {
        response_type: 'privacy_response',
        threadid: Number(response.threadid || currentThreadId || 0),
        runid: Number(response.runid || 0),
        status: 'privacy_applied',
        source,
        time: (new Date()).toISOString(),
    });
};

/**
 * Append a chat bubble with trusted HTML content.
 *
 * @param {string} role      'user' | 'assistant'
 * @param {string} html      Trusted HTML.
 * @param {Object|null} meta Compact debug metadata.
 * @returns {HTMLElement|null}
 */
const appendMessageHtml = (role, html, meta = null) => {
    const list = document.getElementById('booking-ai-messages');
    if (!list) {
        return null;
    }
    const div = document.createElement('div');
    div.classList.add('booking-ai-msg', role);
    div.innerHTML = `<div class="bubble">${String(html || '')}</div>`
        + `${renderMessageDebugMeta(meta)}${renderMessageDebugJson(meta)}`;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
    return div;
};

/**
 * Replace content of the dedicated side preview panel.
 *
 * @param {string} html Trusted HTML.
 */
const setSidePreviewHtml = (html) => {
    const preview = document.getElementById('booking-ai-side-preview');
    if (!preview) {
        return;
    }
    preview.innerHTML = String(html || '');
};

/**
 * Initialize drag-to-resize behavior for chat and preview panes on desktop.
 */
const initResizableLayout = () => {
    const layout = document.getElementById('booking-ai-body-layout');
    const splitter = document.getElementById('booking-ai-splitter');
    if (!layout || !splitter) {
        return;
    }

    const desktopMedia = window.matchMedia('(min-width: 992px)');
    const storageKey = 'bookingextension_agent_ai_preview_width';

    const applyColumns = (previewPercent) => {
        const safePreview = Math.min(90, Math.max(20, Number(previewPercent || 42)));
        const mainPercent = 100 - safePreview;
        layout.style.gridTemplateColumns = `minmax(0, ${mainPercent}%) 10px minmax(0, ${safePreview}%)`;
        splitter.setAttribute('aria-valuenow', String(Math.round(safePreview)));
    };

    const restoreOrDefault = () => {
        if (!desktopMedia.matches) {
            layout.style.gridTemplateColumns = '';
            return;
        }
        const stored = Number(window.localStorage.getItem(storageKey) || 42);
        applyColumns(stored);
    };

    restoreOrDefault();

    let dragging = false;

    const onPointerMove = (clientX) => {
        if (!dragging || !desktopMedia.matches) {
            return;
        }
        const rect = layout.getBoundingClientRect();
        if (rect.width <= 0) {
            return;
        }
        const previewPercent = ((rect.right - clientX) / rect.width) * 100;
        applyColumns(previewPercent);
        window.localStorage.setItem(storageKey, String(Math.min(90, Math.max(20, previewPercent))));
    };

    const onMouseMove = (event) => {
        onPointerMove(event.clientX);
    };

    const onTouchMove = (event) => {
        const touch = event.touches && event.touches[0];
        if (!touch) {
            return;
        }
        onPointerMove(touch.clientX);
    };

    const stopDragging = () => {
        dragging = false;
        document.body.classList.remove('booking-ai-resizing');
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', stopDragging);
        document.removeEventListener('touchmove', onTouchMove);
        document.removeEventListener('touchend', stopDragging);
    };

    const startDragging = (event) => {
        if (!desktopMedia.matches) {
            return;
        }
        dragging = true;
        document.body.classList.add('booking-ai-resizing');
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', stopDragging);
        document.addEventListener('touchmove', onTouchMove, {passive: true});
        document.addEventListener('touchend', stopDragging);

        const touch = event.touches && event.touches[0];
        if (touch) {
            onPointerMove(touch.clientX);
            return;
        }
        onPointerMove(event.clientX);
    };

    splitter.addEventListener('mousedown', startDragging);
    splitter.addEventListener('touchstart', startDragging, {passive: true});

    desktopMedia.addEventListener('change', () => {
        restoreOrDefault();
    });
};

/**
 * Initialize mobile preview toggle and horizontal swipe gesture.
 */
const initMobilePreviewSwitch = () => {
    const layout = document.getElementById('booking-ai-body-layout');
    const toggle = document.getElementById('booking-ai-mobile-toggle');
    if (!layout || !toggle) {
        return;
    }

    const mobileMedia = window.matchMedia('(max-width: 991.98px)');
    const label = toggle.querySelector('.booking-ai-mobile-toggle-label');

    const setPreviewActive = (active) => {
        const previewActive = Boolean(active);
        layout.classList.toggle('mobile-preview-active', previewActive);
        toggle.setAttribute('aria-pressed', previewActive ? 'true' : 'false');
        if (label) {
            label.textContent = previewActive ? 'Chat' : 'Preview';
        }
    };

    setPreviewActive(false);

    let startX = 0;
    let startY = 0;

    layout.addEventListener('touchstart', (event) => {
        const touch = event.touches && event.touches[0];
        if (!touch || !mobileMedia.matches) {
            return;
        }
        startX = touch.clientX;
        startY = touch.clientY;
    }, {passive: true});

    layout.addEventListener('touchend', (event) => {
        const touch = event.changedTouches && event.changedTouches[0];
        if (!touch || !mobileMedia.matches) {
            return;
        }

        const deltaX = touch.clientX - startX;
        const deltaY = touch.clientY - startY;
        if (Math.abs(deltaX) < 50 || Math.abs(deltaY) > 40) {
            return;
        }

        if (deltaX < 0) {
            setPreviewActive(true);
        } else {
            setPreviewActive(false);
        }
    }, {passive: true});

    mobileMedia.addEventListener('change', () => {
        if (!mobileMedia.matches) {
            layout.classList.remove('mobile-preview-active');
        }
    });
};

/**
 * Minimal HTML escape.
 *
 * @param  {string} str
 * @return {string}
 */
const escapeHtml = (str) => {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
};

/**
 * Safely update the thinking label text without destroying HTML structure.
 *
 * @param {string} label
 */
const updateThinkingLabel = (label) => {
    const labelEl = document.getElementById('booking-ai-thinking-label');
    if (labelEl) {
        labelEl.textContent = String(label || '');
    }
};

/**
 * Copy a text string to clipboard with fallback for older browsers.
 *
 * @param {string} text
 * @returns {Promise<void>}
 */
const copyTextToClipboard = (text) => {
    const value = String(text || '');
    if (value.trim() === '') {
        return Promise.reject(new Error('Empty clipboard payload.'));
    }

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        return navigator.clipboard.writeText(value);
    }

    return new Promise((resolve, reject) => {
        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.top = '-9999px';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        try {
            const copied = document.execCommand('copy');
            document.body.removeChild(textarea);
            if (copied) {
                resolve();
            } else {
                reject(new Error('Copy command failed.'));
            }
        } catch (e) {
            document.body.removeChild(textarea);
            reject(e);
        }
    });
};

/**
 * Show temporary visual feedback on a button.
 *
 * @param {HTMLElement} button
 * @param {string} label
 * @param {string} cssClass
 */
const showButtonFeedback = (button, label, cssClass) => {
    if (!(button instanceof HTMLElement)) {
        return;
    }

    const originalLabel = String(button.dataset.originalLabel || button.textContent || '');
    if (!button.dataset.originalLabel) {
        button.dataset.originalLabel = originalLabel;
    }

    button.textContent = label;
    button.classList.add(cssClass);

    window.setTimeout(() => {
        button.textContent = button.dataset.originalLabel || originalLabel;
        button.classList.remove('btn-success', 'btn-danger', 'btn-outline-secondary');
        button.classList.add('btn-outline-secondary');
    }, 1500);
};

/**
 * Extract docs preview metadata from a link href.
 *
 * @param {string} href
 * @returns {{docpath: string, fragment: string}}
 */
const getDocLinkMeta = (href) => {
    const raw = String(href || '').trim();
    if (raw === '') {
        return {docpath: '', fragment: ''};
    }

    let normalized = raw;
    const absoluteMatch = normalized.match(/^https?:\/\/[^/]+(\/.*)$/i);
    if (absoluteMatch) {
        normalized = String(absoluteMatch[1] || '');
    }

    const hashIndex = normalized.indexOf('#');
    const fragment = hashIndex >= 0 ? normalized.slice(hashIndex + 1).trim() : '';
    const withoutHash = hashIndex >= 0 ? normalized.slice(0, hashIndex) : normalized;
    const queryIndex = withoutHash.indexOf('?');
    const withoutQuery = queryIndex >= 0 ? withoutHash.slice(0, queryIndex) : withoutHash;

    if (/^\/mod\/booking\/docs\//i.test(withoutQuery)) {
        const docpath = withoutQuery.replace(/^\/mod\/booking\/docs\//i, '').trim();
        return {docpath, fragment};
    }

    if (/\.md$/i.test(withoutQuery) && !/^\//.test(withoutQuery)) {
        return {docpath: withoutQuery.trim(), fragment};
    }

    return {docpath: '', fragment: ''};
};

/**
 * Render one hyperlink with the correct behavior attributes.
 *
 * @param {string} href
 * @param {string} label
 * @returns {string}
 */
const renderSmartLink = (href, label) => {
    const safeHref = escapeHtml(href);
    const safeLabel = escapeHtml(label);
    const meta = getDocLinkMeta(href);

    if (meta.docpath !== '') {
        const dataDocPath = escapeHtml(meta.docpath);
        const dataDocFragment = escapeHtml(meta.fragment);
        return `<a href="${safeHref}" class="booking-doc-link"`
            + ` data-docpath="${dataDocPath}" data-docfragment="${dataDocFragment}">`
            + `${safeLabel}</a>`;
    }

    return `<a href="${safeHref}" target="_blank" rel="noopener noreferrer">${safeLabel}</a>`;
};

/**
 * Escape text and convert URLs/newlines for rich status rendering.
 *
 * @param {string} text
 * @returns {string}
 */
const renderTextWithLinks = (text) => {
    const input = String(text || '');

    // Combined regex: markdown links [label](url) first, then bare URLs.
    // Supports absolute http(s) and Moodle-relative paths like /mod/booking/....
    // Markdown pattern must come first so bare-URL branch never fires inside [...](...)
    const combinedRegex = new RegExp(
        '\\[([^\\]]+)\\]\\(((?:https?:\\/\\/|\\/(?:mod|admin|course|local)\\/)[^)]+)\\)'
        + '|((?:https?:\\/\\/|\\/(?:mod|admin|course|local)\\/)[^\\s)`"\'<]+)',
        'g'
    );

    let html = '';
    let lastIndex = 0;
    let match;

    while ((match = combinedRegex.exec(input)) !== null) {
        html += escapeHtml(input.slice(lastIndex, match.index));

        if (match[1] !== undefined) {
            // Markdown link: [label](url)
            const label = match[1];
            const url = match[2];
            html += renderSmartLink(url, label);
        } else {
            // Bare URL or Moodle-relative path.
            const url = match[3];
            html += renderSmartLink(url, url);
        }

        lastIndex = match.index + match[0].length;
    }

    html += escapeHtml(input.slice(lastIndex));
    return html.replace(/\n/g, '<br>');
};

/**
 * Render assistant content as HTML.
 *
 * If the backend already returns HTML, keep it as-is. Otherwise render a
 * plain-text fallback with escaped text, links and line breaks.
 *
 * @param {string} content
 * @returns {string}
 */
const renderAssistantMessageHtml = (content) => {
    const raw = String(content || '').trim();
    if (raw === '') {
        return '';
    }

    if (/<\/?[a-z][\s\S]*>/i.test(raw)) {
        return raw;
    }

    return renderTextWithLinks(raw);
};

/**
 * Extract the first doc URL from structured task results (docs array).
 *
 * @param {Array} results
 * @returns {string}
 */
/**
 * Extract the first doc entry (path + url) from explain_docs_topic results.
 *
 * @param {Array} results
 * @returns {{path:string, url:string}} — both may be empty strings when no doc is found.
 */
const extractFirstDoc = (results) => {
    const safeResults = Array.isArray(results) ? results : [];
    for (const result of safeResults) {
        if (!result || typeof result !== 'object') {
            continue;
        }
        const docs = Array.isArray(result.docs) ? result.docs : [];
        for (const doc of docs) {
            const url  = String((doc && doc.url)  || '').trim();
            const path = String((doc && doc.path) || '').trim();
            if (url !== '' || path !== '') {
                return {path, url};
            }
        }
    }
    return {path: '', url: ''};
};

/**
 * Extract the first absolute URL from text.
 *
 * @param {string} text
 * @returns {string}
 */
const extractFirstUrl = (text) => {
    const input = String(text || '');
    // Prefer markdown link URL, fall back to bare URL.
    const mdMatch = input.match(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/);
    if (mdMatch) {
        return String(mdMatch[2]);
    }
    const bareMatch = input.match(/https?:\/\/[^\s)]+/);
    return bareMatch ? String(bareMatch[0]) : '';
};

/**
 * Load a URL into the side preview pane using an iframe.
 *
 * @param {string} url
 */
const loadUrlInSidePreview = (url) => {
    const safeUrl = String(url || '').trim();
    if (safeUrl === '') {
        return;
    }

    setSidePreviewHtml(
        '<iframe class="booking-ai-side-preview-frame"'
        + ` src="${escapeHtml(safeUrl)}"`
        + ' loading="lazy" referrerpolicy="no-referrer"'
        + ' style="width:100%;min-height:420px;border:0;" title="Documentation preview"></iframe>'
    );
};

/**
 * Escape a string for use in querySelector id selector.
 *
 * @param {string} value
 * @returns {string}
 */
const escapeCssIdentifier = (value) => {
    if (window.CSS && typeof window.CSS.escape === 'function') {
        return window.CSS.escape(String(value || ''));
    }
    return String(value || '').replace(/[^a-zA-Z0-9_-]/g, '\\$&');
};

/**
 * Scroll the side preview to a fragment id after doc content is rendered.
 *
 * @param {string} fragment
 */
const scrollPreviewToFragment = (fragment) => {
    const raw = String(fragment || '').trim().replace(/^#/, '');
    if (raw === '') {
        return;
    }

    const preview = document.getElementById('booking-ai-side-preview');
    if (!preview) {
        return;
    }

    const decoded = (() => {
        try {
            return decodeURIComponent(raw);
        } catch (e) {
            return raw;
        }
    })();

    const candidates = [raw, decoded, `doc-${raw}`, `doc-${decoded}`];
    const uniqueCandidates = [...new Set(candidates.map((id) => String(id || '').trim()).filter((id) => id !== ''))];

    for (const candidate of uniqueCandidates) {
        const selector = `#${escapeCssIdentifier(candidate)}`;
        const target = preview.querySelector(selector);
        if (target instanceof HTMLElement) {
            target.scrollIntoView({behavior: 'smooth', block: 'start'});
            return;
        }
    }
};

/**
 * Load a booking/docs markdown file into the side preview via the webservice renderer.
 *
 * Falls back to loadUrlInSidePreview() when the webservice call fails.
 *
 * @param {string} docpath  Relative path inside booking/docs, e.g. "booking_rules/README.md".
 * @param {string} fallbackUrl  Optional bare URL to use as iframe fallback.
 * @param {string} fragment Optional fragment id (without #) to scroll to after load.
 */
const loadDocInPreview = (docpath, fallbackUrl = '', fragment = '') => {
    const safePath = String(docpath || '').trim();
    if (safePath === '') {
        if (fallbackUrl !== '') {
            loadUrlInSidePreview(fallbackUrl);
        }
        return;
    }

    setSidePreviewHtml('<div class="booking-doc-loading p-3 text-muted">Loading documentation…</div>');

    Ajax.call([{
        methodname: 'bookingextension_agent_ai_get_doc_content',
        args: {contextid: currentContextId, path: safePath},
    }])[0].then((resp) => {
        if (resp && resp.success && String(resp.html || '').trim() !== '') {
            const title = String(resp.title || '').trim();
            const titleHtml = title !== ''
                ? `<h2 class="booking-doc-preview-title">${escapeHtml(title)}</h2>`
                : '';
            setSidePreviewHtml(
                '<div class="booking-doc-preview p-3">'
                + titleHtml
                + String(resp.html)
                + '</div>'
            );
            if (String(fragment || '').trim() !== '') {
                window.requestAnimationFrame(() => scrollPreviewToFragment(fragment));
            }
        } else if (fallbackUrl !== '') {
            loadUrlInSidePreview(fallbackUrl);
        } else {
            setSidePreviewHtml('<div class="p-3 text-muted small">No content available.</div>');
        }
        return resp;
    }).catch(() => {
        if (fallbackUrl !== '') {
            loadUrlInSidePreview(fallbackUrl);
        }
    });
};

/**
 * Detect generic status placeholders that are not user-friendly final answers.
 *
 * @param {string} message
 * @returns {boolean}
 */
const isGenericStatusMessage = (message) => {
    const normalized = String(message || '').trim().toLowerCase();
    if (!normalized) {
        return true;
    }

    const generic = [
        'completed',
        'queued',
        'running',
        'failed',
        'executing',
        'executing the action.',
        'fuehre die aktion aus.',
        'fuehre die aktion aus',
    ];

    return generic.includes(normalized);
};

/**
 * Read the first non-empty string field from structured results.
 *
 * @param {Array} results
 * @param {Array<string>} fields
 * @returns {string}
 */
const getFirstResultField = (results, fields) => {
    const safeResults = Array.isArray(results) ? results : [];
    for (const result of safeResults) {
        if (!result || typeof result !== 'object') {
            continue;
        }

        for (const field of fields) {
            const value = String(result[field] || '').trim();
            if (value !== '') {
                return value;
            }
        }
    }

    return '';
};

/**
 * Build a user-friendly chat message from structured run results.
 *
 * @param {string} status
 * @param {string} message
 * @param {Array} results
 * @returns {string}
 */
const buildFriendlyRunMessage = (status, message, results = []) => {
    const safeStatus = String(status || '').toLowerCase();
    const safeMessage = String(message || '').trim();

    if (safeStatus !== 'completed' && safeStatus !== 'failed') {
        return isGenericStatusMessage(safeMessage) ? '' : safeMessage;
    }

    // For final run states, prefer the top-level backend message first.
    // It already reflects output language and final summarization policy.
    if (!isGenericStatusMessage(safeMessage)) {
        return safeMessage;
    }

    const first = Array.isArray(results) && results.length > 0 ? (results[0] || {}) : {};

    const userMessage = String(first.usermessage || '').trim();
    if (!isGenericStatusMessage(userMessage)) {
        return userMessage;
    }

    const summary = String(first.summary || '').trim();
    if (!isGenericStatusMessage(summary)) {
        return summary;
    }

    const detail = String(first.detail || '').trim();
    if (!isGenericStatusMessage(detail)) {
        return detail;
    }

    return safeStatus === 'failed'
        ? 'The request failed. Please check the details below.'
        : 'Done.';
};

/**
 * Build a task-authored debug bubble for developer mode.
 *
 * @param {string} status
 * @param {string} message
 * @param {Array} results
 * @returns {string}
 */
const buildDebugRunHtml = (status, message, results = []) => {
    if (!debugModeEnabled) {
        return '';
    }

    const debugMessages = [];
    (Array.isArray(results) ? results : []).forEach((result) => {
        const debugMessage = String((result && result.debugmessage) || '').trim();
        if (debugMessage !== '') {
            debugMessages.push(debugMessage);
        }
    });

    if (debugMessages.length > 0) {
        const items = debugMessages.map((debugMessage) => `<li>${renderTextWithLinks(debugMessage)}</li>`).join('');
        return '<div class="booking-ai-run-status-inline alert alert-secondary mb-0">'
            + '<strong>Debug</strong>'
            + `<ul class="mb-0">${items}</ul>`
            + '</div>';
    }

    const fallback = getFirstResultField(results, ['detail']);
    const safeMessage = String(message || fallback || status).trim();
    if (safeMessage === '') {
        return '';
    }

    return '<div class="booking-ai-run-status-inline alert alert-secondary mb-0">'
        + `<strong>${escapeHtml(String(status || 'debug'))}</strong>: ${renderTextWithLinks(safeMessage)}`
        + '</div>';
};

/**
 * Append user-friendly assistant text while preserving line breaks.
 *
 * @param {string} content
 */
const appendFriendlyAssistantMessage = (content) => {
    const text = String(content || '').trim();
    if (!text) {
        return;
    }
    appendMessageHtml('assistant', renderAssistantMessageHtml(text));

    const firstUrl = extractFirstUrl(text);
    if (firstUrl !== '') {
        loadUrlInSidePreview(firstUrl);
    }
};

/**
 * Handle an agent response by either auto-confirming, showing the panel, or
 * rendering the final assistant output.
 *
 * @param {Object} resp
 * @param {string} source
 * @param {Object} extra
 * @returns {void}
 */
const buildAgentResponseMeta = (resp, source, extra = {}) => ({
    response_type: String(resp.response_type || ''),
    threadid: Number(resp.threadid || currentThreadId || 0),
    runid: Number(resp.runid || 0),
    source,
    time: (new Date()).toISOString(),
    ...extra,
});

const handleFinalAgentResponse = (resp, source, responseType, messageText) => {
    clearActivePlanBubble();

    let results = [];
    try {
        results = JSON.parse(resp.resultsjson || '[]');
    } catch (e) {
        // Keep empty results on parse errors.
    }

    if (responseType === 'execution_result' || (source === 'ai_confirm_run' && Array.isArray(results) && results.length > 0)) {
        const runStatus = responseType === 'error' ? 'failed' : 'completed';
        showRunStatus(runStatus, messageText || responseType, results);
        return;
    }

    appendMessage('assistant', renderAssistantMessageHtml(messageText), buildAgentResponseMeta(resp, source, {
        response_type: responseType,
        attempted_tasks: parseJsonList(resp.attemptedtasksjson).join(', '),
        issue_codes: parseJsonList(resp.issuecodesjson).join(', '),
        pending_confirmation_code: String(resp.pendingconfirmationcode || ''),
        errors: parseJsonList(resp.errorsjson).join(' || '),
    }));
};

const handleAgentCommandResponse = (resp, source, responseType, cmds, messageText) => {
    const attemptedTasks = parseJsonList(resp.attemptedtasksjson);
    const errors = parseJsonList(resp.errorsjson);
    const issueCodes = parseJsonList(resp.issuecodesjson);
    const planBubble = appendMessage('assistant', messageText, buildAgentResponseMeta(resp, source, {
        commands_count: Array.isArray(cmds) ? cmds.length : 0,
        llm_commands_json: String(resp.commands || ''),
        attempted_tasks: attemptedTasks.join(', '),
        issue_codes: issueCodes.join(', '),
        pending_confirmation_code: String(resp.pendingconfirmationcode || ''),
        errors: errors.join(' || '),
    }));

    if (cmds.length === 0) {
        return;
    }

    activePlanBubble = planBubble;

    const autoconfirm = Number(resp.autoconfirm || 0) === 1;
    if (autoconfirm) {
        sessionAutoConfirmEnabled = true;
    }

    if (responseType === 'confirmation_request' && (autoconfirm || sessionAutoConfirmEnabled)) {
        pendingCommands = cmds;
        confirmRun(true);
        return;
    }

    if (responseType === 'task_call' && shouldAutoExecuteReadOnly(cmds)) {
        pendingCommands = cmds;
        confirmRun(sessionAutoConfirmEnabled);
        return;
    }

    showConfirmPanel(messageText, cmds);
};

const handleConfirmationResponse = (resp, source = 'ai_send_message') => {
    const responseType = String(resp.response_type || '');
    const cmds = parseCommandPayload(resp.commands || '[]');
    const messageText = String(resp.displaymessage || resp.message || '').trim();
    pendingQueueItemId = String(resp.queueitemid || '').trim();

    appendAssistantPrivacyNote(resp, source);

    if (
        responseType === 'execution_result'
        || responseType === 'sufficient'
        || responseType === 'queued'
        || responseType === 'clarification'
        || responseType === 'error'
    ) {
        pendingQueueItemId = '';
        handleFinalAgentResponse(resp, source, responseType, messageText);
        return;
    }

    handleAgentCommandResponse(resp, source, responseType, cmds, messageText);
};

/**
 * Show the confirmation panel with a preview of the proposed commands.
 *
 * @param {string} message   AI summary message.
 * @param {Array}  commands  Validated command objects.
 */
const showConfirmPanel = (message, commands) => {
    pendingCommands = commands;
    if (pendingQueueItemId === '' && Array.isArray(commands) && commands.length > 0) {
        pendingQueueItemId = String((commands[0] && commands[0].queue_item_id) || '').trim();
    }

    const panel = document.getElementById('booking-ai-confirm-panel');
    const preview = document.getElementById('booking-ai-confirm-preview');
    if (!panel || !preview) {
        return;
    }

    const messageHtml = renderAssistantMessageHtml(String(message || '').trim());
    preview.innerHTML = messageHtml !== ''
        ? `<div class="booking-ai-confirm-message mb-2">${messageHtml}</div>`
        : '';

    if (debugModeEnabled) {
        let previewHtml = `${preview.innerHTML}<ul>`;
        commands.forEach((cmd) => {
            previewHtml += `<li><strong>${escapeHtml(cmd.task)}</strong>: ${escapeHtml(JSON.stringify(cmd.input))}`;
            previewHtml += '</li>';
        });
        previewHtml += '</ul>';
        preview.innerHTML = previewHtml;
        setSidePreviewHtml(previewHtml);
    }

    Ajax.call([{
        methodname: 'bookingextension_agent_ai_render_command_preview',
        args: {
            contextid: currentContextId,
            commands: JSON.stringify(commands),
        },
    }])[0].then((resp) => {
        if (resp && resp.success && resp.html && resp.html.trim() !== '') {
            setSidePreviewHtml(resp.html);
            runCollectedJavascript(resp.javascript);
        } else if (resp && resp.message) {
            setSidePreviewHtml(`<div class="text-muted small">${escapeHtml(String(resp.message))}</div>`);
        }
        return resp;
    }).catch((err) => {
        setSidePreviewHtml(`<div class="text-danger small">${escapeHtml(String(err.message || ''))}</div>`);
    });

    panel.classList.remove('d-none');
};

/**
 * Render booking option previews in the side preview panel.
 *
 * @param {number} contextid
 * @param {Array<number>} optionIds
 * @returns {Promise<void>}
 */
const renderOptionPreviewsInline = (contextid, optionIds) => {
    const uniqueIds = [...new Set((optionIds || []).map((id) => Number(id || 0)).filter((id) => id > 0))].slice(0, 100);

    if (uniqueIds.length === 0) {
        return Promise.resolve();
    }

    return Ajax.call([{
        methodname: 'bookingextension_agent_ai_render_command_preview',
        args: {
            contextid,
            optionids: JSON.stringify(uniqueIds),
        },
    }])[0].then((resp) => {
        if (resp && resp.success && resp.html && resp.html.trim() !== '') {
            setSidePreviewHtml(resp.html);
            runCollectedJavascript(resp.javascript);
        }
        return resp;
    }).catch((err) => {
        Notification.exception(err);
    });
};

/**
 * Build task-authored side preview HTML when provided by results.
 *
 * @param {Array} results
 * @returns {string}
 */
const buildTaskPreviewHtml = (results = []) => {
    const entries = Array.isArray(results) ? results : [];
    if (entries.length === 0) {
        return '';
    }

    // Preferred path: task explicitly requests a user-profile preview.
    for (const result of entries) {
        const previewMode = String((result && result.previewmode) || '').trim();
        if (previewMode === 'user_profile') {
            const payload = (result && typeof result.previewdata === 'object' && result.previewdata)
                ? result.previewdata
                : result;
            const fullname = escapeHtml(String((payload && payload.fullname) || result.fullname || '-'));
            const email = escapeHtml(String((payload && payload.email) || result.email || '-'));
            const userid = Number((payload && payload.userid) || result.userid || 0);
            const userIdText = userid > 0 ? String(userid) : '-';

            return '<div class="booking-ai-run-status-inline card mb-0">'
                + '<div class="card-body p-3">'
                + '<h6 class="mb-2">User profile</h6>'
                + `<div><strong>Name:</strong> ${fullname}</div>`
                + `<div><strong>E-Mail:</strong> ${email}</div>`
                + `<div><strong>User ID:</strong> ${escapeHtml(userIdText)}</div>`
                + '</div></div>';
        }
    }

    // Backward-compatible fallback: infer user-profile preview from result fields.
    const userResult = entries.find((result) => Number((result && result.userid) || 0) > 0);
    if (!userResult) {
        return '';
    }

    const fullname = escapeHtml(String(userResult.fullname || '-'));
    const email = escapeHtml(String(userResult.email || '-'));
    const userid = Number(userResult.userid || 0);
    const userIdText = userid > 0 ? String(userid) : '-';

    return '<div class="booking-ai-run-status-inline card mb-0">'
        + '<div class="card-body p-3">'
        + '<h6 class="mb-2">User profile</h6>'
        + `<div><strong>Name:</strong> ${fullname}</div>`
        + `<div><strong>E-Mail:</strong> ${email}</div>`
        + `<div><strong>User ID:</strong> ${escapeHtml(userIdText)}</div>`
        + '</div></div>';
};

/**
 * Hide the confirmation panel.
 *
 * @param {boolean} clearPendingState Whether pending confirmation state should be reset.
 */
const hideConfirmPanel = (clearPendingState = true) => {
    if (clearPendingState) {
        pendingCommands = null;
        pendingQueueItemId = '';
    }
    const panel = document.getElementById('booking-ai-confirm-panel');
    if (panel) {
        panel.classList.add('d-none');
    }
};

/**
 * Remove the assistant bubble that announced the command plan once execution finishes.
 */
const clearActivePlanBubble = () => {
    if (!activePlanBubble) {
        return;
    }
    if (activePlanBubble.parentNode) {
        activePlanBubble.parentNode.removeChild(activePlanBubble);
    }
    activePlanBubble = null;
};

/**
 * Show a run status message.
 *
 * @param {string} status  'queued' | 'running' | 'completed' | 'failed'
 * @param {string} message Optional detail.
 * @param {Array} results Optional structured per-command results.
 */
const showRunStatus = (status, message, results = []) => {
    // Notify the page that AI has finished so other components (e.g. booking list) can reload.
    if (status === 'completed') {
        document.dispatchEvent(new CustomEvent('bookingextension_agent_ai_run_completed', {bubbles: true}));
    }

    const friendlyMessage = buildFriendlyRunMessage(status, message, results);
    if (friendlyMessage) {
        appendFriendlyAssistantMessage(friendlyMessage);
    }

    const debugHtml = buildDebugRunHtml(status, message, results);
    if (debugHtml) {
        appendMessageHtml('assistant', debugHtml, {
            response_type: 'execution_debug',
            status: String(status || ''),
            source: 'showRunStatus',
            time: (new Date()).toISOString(),
        });
    }

    const followUpHtml = renderFollowUpSuggestionsHtml(results);
    if (followUpHtml && (status === 'completed' || status === 'failed')) {
        appendMessageHtml('assistant', followUpHtml, {
            response_type: 'execution_followup',
            status: String(status || ''),
            source: 'showRunStatus_followup',
            time: (new Date()).toISOString(),
        });
    }

    const taskPreviewHtml = buildTaskPreviewHtml(results);
    if (taskPreviewHtml && (status === 'completed' || status === 'failed')) {
        setSidePreviewHtml(taskPreviewHtml);
        return;
    }

    if (friendlyMessage && (status === 'completed' || status === 'failed')) {
        setSidePreviewHtml(
            `<div class="booking-ai-run-status-inline alert alert-light mb-0">`
            + `${renderTextWithLinks(friendlyMessage)}</div>`
        );
        return;
    }

    const alertClass = (status === 'completed') ? 'alert-success'
                     : (status === 'failed')    ? 'alert-danger'
                     : 'alert-info';
    const statusLabel = escapeHtml(String(status || 'info'));
    let html = `<div class="booking-ai-run-status-inline alert ${alertClass} mb-0">`;
    if (Array.isArray(results) && results.length > 0) {
        const items = results.map((result) => {
            const properties = Array.isArray(result.properties) ? result.properties : [];
            if (properties.length > 0) {
                return properties.map((property) => {
                    const label = escapeHtml(String(property.label || property.name || '-'));
                    return `<li>${label}</li>`;
                }).join('');
            }

            const actions = Array.isArray(result.actions) ? result.actions : [];
            if (actions.length > 0) {
                return actions.map((action) => {
                    const label = escapeHtml(String(action.label || action.task || '-'));
                    return `<li>${label}</li>`;
                }).join('');
            }

            const resultStatus = escapeHtml(String(result.status || status));
            const resultDetail = renderTextWithLinks(String(result.detail || ''));

            const options = Array.isArray(result.options) ? result.options : [];
            let optionsHtml = '';
            if (options.length > 0) {
                optionsHtml = options.map((option) => {
                    const optionName = escapeHtml(String(option.name || '-'));
                    const optionId = Number(option.id || 0);
                    const optionLink = escapeHtml(String(option.link || '#'));
                    return `<a href="${optionLink}" target="_blank" rel="noopener noreferrer">${optionName} (${optionId})</a>`;
                }).join('<br>');
            }

            let extraHtml = '';
            if (result.fullname) {
                extraHtml += `<div><strong>Name:</strong> ${escapeHtml(String(result.fullname))}</div>`;
            }
            if (result.email) {
                extraHtml += `<div><strong>E-Mail:</strong> ${escapeHtml(String(result.email))}</div>`;
            }

            return `<li><strong>${resultStatus}</strong>${resultDetail ? `: ${resultDetail}` : ''}`
                + `${extraHtml ? `<div class="mt-1">${extraHtml}</div>` : ''}`
                + `${optionsHtml ? `<div class="mt-1">${optionsHtml}</div>` : ''}`
                + '</li>';
        }).join('');
        html += `<ul class="mb-0">${items}</ul>`;
    } else {
        html += `<strong>${statusLabel}</strong>: ${renderTextWithLinks(message || status)}`;
    }
    html += '</div>';
    appendMessageHtml('assistant', html);

    // Keep execution output visible in the dedicated preview pane.
    // If a richer option/table preview arrives later, it will replace this content.
    setSidePreviewHtml(html);
};

/**
 * Extract all preview option ids from run results.
 *
 * @param {Array} results
 * @returns {Array<number>}
 */
const extractPreviewOptionIds = (results) => {
    const ids = [];
    (Array.isArray(results) ? results : []).forEach((result) => {
        const isUserCentricResult = Number(result.userid || 0) > 0;
        const singleId = Number(result.resultid || 0);
        if (!isUserCentricResult && singleId > 0) {
            ids.push(singleId);
        }

        const many = Array.isArray(result.previewoptionids) ? result.previewoptionids : [];
        many.forEach((id) => {
            const normalized = Number(id || 0);
            if (normalized > 0) {
                ids.push(normalized);
            }
        });
    });

    return [...new Set(ids)];
};

/**
 * Collect all preview option ids from a WS response.
 *
 * Prefers the dedicated previewoptionidsjson field (populated server-side from
 * the full task result), then merges with extractPreviewOptionIds(results) as
 * a fallback, and finally falls back to the scalar previewoptionid.
 *
 * @param {Object} resp     WS response object.
 * @param {Array}  results  Parsed resultsjson array (may be empty).
 * @returns {Array<number>}
 */
const collectPreviewOptionIds = (resp, results = []) => {
    const ids = [];
    try {
        const fromJson = JSON.parse(String(resp.previewoptionidsjson || '[]'));
        if (Array.isArray(fromJson)) {
            fromJson.forEach((id) => {
                const n = Number(id || 0);
                if (n > 0) {
                    ids.push(n);
                }
            });
        }
    } catch (e) {
        // Ignore parse errors — fall through to other sources.
    }
    extractPreviewOptionIds(results).forEach((id) => {
        if (!ids.includes(id)) {
            ids.push(id);
        }
    });
    const scalar = Number(resp.previewoptionid || 0);
    if (scalar > 0 && !ids.includes(scalar)) {
        ids.push(scalar);
    }
    return ids;
};

/**
 * Append an ephemeral step-progress bubble to the message list.
 *
 * These bubbles are shown during the agentic loop and removed (with a short
 * fade transition) as soon as the final answer arrives.
 *
 * @param {string} label  Human-readable step label (e.g. "Step 1: booking.search_options").
 * @param {number} msgId  DB id of the step message — used to deduplicate.
 */
const appendStepBubble = (label, msgId) => {
    const list = document.getElementById('booking-ai-messages');
    if (!list) {
        return;
    }

    const div = document.createElement('div');
    div.classList.add('booking-ai-msg', 'booking-ai-msg-step');
    div.dataset.stepMsgId = String(msgId);
    const formattedLabel = renderTextWithLinks(String(label || ''));
    div.innerHTML = '<span class="booking-ai-step-spinner" aria-hidden="true"></span>'
        + `<span class="booking-ai-step-label">${formattedLabel}</span>`;
    list.appendChild(div);
    list.scrollTop = list.scrollHeight;
    activeStepBubbles.push(div);
};

/**
 * Fade out and remove all active step bubbles.
 */
const clearStepBubbles = () => {
    activeStepBubbles.forEach((el) => {
        el.classList.add('booking-ai-step-fade');
        setTimeout(() => {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        }, 350);
    });
    activeStepBubbles = [];
};

/**
 * Start polling the thread for new step-progress messages.
 *
 * Runs every 1.5 s and shows each new step as an ephemeral bubble.
 * Call stopStepPolling() to cancel.
 *
 * @param {number} threadid
 * @param {number} contextid
 */
const startStepPolling = (threadid, contextid) => {
    stopStepPolling();
    lastSeenStepId = 0;
    stepPollInterval = setInterval(() => {
        Ajax.call([{
            methodname: 'bookingextension_agent_ai_poll_thread',
            args: {contextid, threadid},
        }])[0].then((resp) => {
            const messages = Array.isArray(resp.messages) ? resp.messages : [];
            messages.forEach((msg) => {
                if (String(msg.role || '') !== 'step') {
                    return;
                }
                const msgId = Number(msg.id || 0);
                if (msgId <= lastSeenStepId) {
                    return;
                }
                lastSeenStepId = msgId;
                appendStepBubble(String(msg.content || ''), msgId);
            });
            return resp;
        }).catch(() => {
            // Polling errors are silently ignored — the main request surfaces real errors.
        });
    }, 1500);
};

/**
 * Fetch and display all LLM debug logs for the current thread.
 * Only available in debug mode.
 */
const refreshThreadDebugLogs = () => {
    if (!debugModeEnabled || currentThreadId <= 0 || currentContextId <= 0) {
        return;
    }

    Ajax.call([{
        methodname: 'bookingextension_agent_ai_get_thread_debug_logs',
        args: {contextid: currentContextId, threadid: currentThreadId, limit: 100},
    }])[0].then((resp) => {
        let debugLogs = [];
        try {
            debugLogs = JSON.parse(resp.debuglogsjson || '[]');
        } catch (e) {
            debugLogs = [];
        }

        if (Array.isArray(debugLogs) && debugLogs.length > 0) {
            const debugHtml = renderDebugLogs(debugLogs);
            if (debugHtml) {
                const list = document.getElementById('booking-ai-messages');
                if (list) {
                    // Remove any existing debug logs panel
                    const existingPanel = list.querySelector('.booking-ai-all-debug-logs');
                    if (existingPanel) {
                        existingPanel.remove();
                    }

                    // Add new debug logs panel
                    const div = document.createElement('div');
                    div.classList.add(
                        'booking-ai-msg', 'assistant',
                        'booking-ai-all-debug-logs', 'mt-3'
                    );
                    div.innerHTML = '<div class="alert alert-secondary mb-0">'
                        + '<strong>📋 All LLM Debug Logs (Thread)</strong>'
                        + `${debugHtml}`
                        + '</div>';
                    list.appendChild(div);
                    list.scrollTop = list.scrollHeight;
                }
            }
        }
    }).catch(() => {
        // Silently fail if API not available or debug mode off
    });
};

/**
 * Create a debug logs refresh button in the UI.
 */
const initDebugRefreshButton = () => {
    if (!debugModeEnabled) {
        return;
    }

    const wrapper = document.getElementById('booking-ai-wrapper');
    if (!wrapper) {
        return;
    }

    const inputGroup = wrapper.querySelector('.input-group');
    if (!inputGroup) {
        return;
    }

    const refreshBtn = document.createElement('button');
    refreshBtn.type = 'button';
    refreshBtn.className = 'btn btn-outline-secondary btn-sm';
    refreshBtn.id = 'booking-ai-debug-refresh';
    refreshBtn.title = 'Refresh all LLM debug logs for this thread';
    refreshBtn.textContent = '🔍 Debug Logs';

    const inputGroupAppend = inputGroup.querySelector('.input-group-append');
    if (inputGroupAppend) {
        inputGroupAppend.appendChild(refreshBtn);
    }
};

/**
 * Stop the step-progress polling interval.
 */
const stopStepPolling = () => {
    if (stepPollInterval !== null) {
        clearInterval(stepPollInterval);
        stepPollInterval = null;
    }
};

/**
 * Resume step polling for the active thread if a thread is available.
 */
const resumeStepPolling = () => {
    if (currentThreadId > 0 && currentContextId > 0) {
        startStepPolling(currentThreadId, currentContextId);
    }
};

/**
 * Send a message to the AI agent.
 *
 * @param {string} message
 */
const sendMessage = (message) => {
    if (!message.trim()) {
        return;
    }

    sendAborted = false;

    appendMessage('user', message, {
        source: 'chat_input',
        time: (new Date()).toISOString(),
    });

    const thinking = document.getElementById('booking-ai-thinking');
    const sendBtn  = document.getElementById('booking-ai-send');
    const stopBtn  = document.getElementById('booking-ai-btn-stop');
    if (thinking) {
        updateThinkingLabel(privacyCheckRunningLabel);
        thinking.classList.remove('d-none');
    }
    if (stopBtn) {
        stopBtn.classList.remove('d-none');
    }
    if (sendBtn) {
        sendBtn.disabled = true;
    }

    Ajax.call([{
        methodname: 'bookingextension_agent_ai_privacy_precheck',
        args: {
            contextid: currentContextId,
            message,
            forcenewthread: forceNewThreadOnFirstMessage ? 1 : 0,
        },
    }])[0].then((precheck) => {
        forceNewThreadOnFirstMessage = false;

        if (precheck.threadid && precheck.threadid > 0) {
            currentThreadId = precheck.threadid;
        }

        const strictMode = Number(precheck.strictmode || 0) === 1;
        const anonymizedCount = Number(precheck.anonymizedcount || 0);
        if (strictMode || anonymizedCount > 0) {
            appendPrivacyNote(precheck.message || '', {
                response_type: 'privacy_precheck',
                threadid: Number(precheck.threadid || currentThreadId || 0),
                status: String(precheck.status || ''),
                source: 'ai_privacy_precheck',
                time: (new Date()).toISOString(),
            });
        }

        if (String(precheck.status || '') !== 'ok') {
            if (thinking) {
                thinking.classList.add('d-none');
                updateThinkingLabel(defaultThinkingLabel);
            }
            if (stopBtn) {
                stopBtn.classList.add('d-none');
            }
            if (sendBtn) {
                sendBtn.disabled = false;
            }
            return precheck;
        }

        const sanitizedMessage = String(precheck.sanitizedmessage || message);
        if (thinking) {
            updateThinkingLabel(defaultThinkingLabel);
        }

        // Start polling for intermediate step updates while the LLM is processing.
        startStepPolling(currentThreadId, currentContextId);
        appendStepBubble(stepPlanningLabel, 0);

        return Ajax.call([{
        methodname: 'bookingextension_agent_ai_send_message',
        args: {
            contextid: currentContextId,
            message: sanitizedMessage,
            threadid: Number(currentThreadId || 0),
        },
    }])[0].then((resp) => {
        // Stop step polling and remove ephemeral step bubbles before showing final answer.
        stopStepPolling();
        clearStepBubbles();

        // If the user clicked Stop while waiting, discard the response silently.
        if (sendAborted) {
            sendAborted = false;
            if (thinking) {
                thinking.classList.add('d-none');
                updateThinkingLabel(defaultThinkingLabel);
            }
            if (stopBtn) {
                stopBtn.classList.add('d-none');
            }
            if (sendBtn) {
                sendBtn.disabled = false;
            }
            return resp;
        }

        if (thinking) {
            thinking.classList.add('d-none');
            updateThinkingLabel(defaultThinkingLabel);
        }
        if (stopBtn) {
            stopBtn.classList.add('d-none');
        }
        if (sendBtn) {
            sendBtn.disabled = false;
        }

        if (resp.threadid && resp.threadid > 0) {
            currentThreadId = resp.threadid;
        }

        // Trigger option preview for all non-command response types.
        // previewoptionidsjson carries the full id list; falls back to scalar + results.
        const earlyPreviewIds = collectPreviewOptionIds(resp, []);
        if (earlyPreviewIds.length > 0) {
            renderOptionPreviewsInline(currentContextId, earlyPreviewIds);
        }

        if (resp.response_type === 'clarification' || resp.response_type === 'sufficient' || resp.response_type === 'error') {
            // Steps are ephemeral loading indicators — clear them when any response arrives.
            stopStepPolling();
            clearStepBubbles();
            appendAssistantPrivacyNote(resp, 'ai_send_message');
            const attemptedTasks = parseJsonList(resp.attemptedtasksjson);
            const errors = parseJsonList(resp.errorsjson);
            const issueCodes = parseJsonList(resp.issuecodesjson);
            let results = [];
            try {
                results = JSON.parse(resp.resultsjson || '[]');
            } catch (e) {
                // Keep empty results on parse errors.
            }
            if (isTrialTokenInvalidError(resp, errors, issueCodes)) {
                maybeShowTrialTokenInvalidAlert(resp, errors, issueCodes);
                return resp;
            }
            const ambiguityOptions = parseJsonObjectList(resp.ambiguityoptionsjson || '[]');
            const messageText = String(resp.displaymessage || resp.message || '');
            const ambiguityOptionsHtml = renderAmbiguityOptionsHtml(ambiguityOptions);

            if (ambiguityOptionsHtml !== '' && resp.response_type === 'clarification') {
                appendMessageHtml(
                    'assistant',
                    `${renderAssistantMessageHtml(messageText)}${ambiguityOptionsHtml}`,
                    {
                        response_type: resp.response_type || '',
                        threadid: Number(resp.threadid || currentThreadId || 0),
                        runid: Number(resp.runid || 0),
                        attempted_tasks: attemptedTasks.join(', '),
                        issue_codes: issueCodes.join(', '),
                        results_count: Array.isArray(results) ? results.length : 0,
                        pending_confirmation_code: String(resp.pendingconfirmationcode || ''),
                        errors: errors.join(' || '),
                        source: 'ai_send_message',
                        time: (new Date()).toISOString(),
                    }
                );

                const debugHtml = buildDebugRunHtml('completed', messageText, results);
                if (debugHtml) {
                    appendMessageHtml('assistant', debugHtml, {
                        response_type: 'execution_debug',
                        status: 'completed',
                        source: 'ai_send_message',
                        time: (new Date()).toISOString(),
                    });
                }

                const followUpHtml = renderFollowUpSuggestionsHtml(results);
                if (followUpHtml) {
                    appendMessageHtml('assistant', followUpHtml, {
                        response_type: 'execution_followup',
                        status: 'completed',
                        source: 'ai_send_message_followup',
                        time: (new Date()).toISOString(),
                    });
                }

                return resp;
            }

            const isError = resp.response_type === 'error';
            const meta = {
                response_type: resp.response_type || '',
                threadid: Number(resp.threadid || currentThreadId || 0),
                runid: Number(resp.runid || 0),
                attempted_tasks: attemptedTasks.join(', '),
                issue_codes: issueCodes.join(', '),
                results_count: Array.isArray(results) ? results.length : 0,
                pending_confirmation_code: String(resp.pendingconfirmationcode || ''),
                errors: errors.join(' || '),
                source: 'ai_send_message',
                time: (new Date()).toISOString(),
            };
            const list = document.getElementById('booking-ai-messages');
            if (isError && list) {
                const div = document.createElement('div');
                div.classList.add('booking-ai-msg', 'assistant', 'error');
                div.innerHTML = `<div class="bubble">${renderAssistantMessageHtml(resp.displaymessage || resp.message)}</div>`
                    + `${renderMessageDebugMeta(meta)}${renderMessageDebugJson(meta)}`;
                list.appendChild(div);
                list.scrollTop = list.scrollHeight;

                const bubble = div.querySelector('.bubble');
                enforceErrorBubbleStyleFallback(bubble);
            } else {
                appendMessageHtml(
                    'assistant',
                    renderAssistantMessageHtml(String(resp.displaymessage || resp.message || '')),
                    meta
                );
            }

            const debugHtml = buildDebugRunHtml(
                isError ? 'failed' : 'completed',
                String(resp.displaymessage || resp.message || ''),
                results
            );
            if (debugHtml) {
                appendMessageHtml('assistant', debugHtml, {
                    response_type: 'execution_debug',
                    status: isError ? 'failed' : 'completed',
                    source: 'ai_send_message',
                    time: (new Date()).toISOString(),
                });
            }

            const followUpHtml = renderFollowUpSuggestionsHtml(results);
            if (followUpHtml) {
                appendMessageHtml('assistant', followUpHtml, {
                    response_type: 'execution_followup',
                    status: isError ? 'failed' : 'completed',
                    source: 'ai_send_message_followup',
                    time: (new Date()).toISOString(),
                });
            }

            // Auto-load the first doc from explain_docs_topic results into the side preview.
            const firstDoc = extractFirstDoc(results);
            if (firstDoc.path !== '' || firstDoc.url !== '') {
                loadDocInPreview(firstDoc.path, firstDoc.url);
            }
        } else if (resp.response_type === 'execution_result') {
            appendAssistantPrivacyNote(resp, 'ai_send_message');
            let results = [];
            try {
                results = JSON.parse(resp.resultsjson || '[]');
            } catch (e) {
                // Keep empty results on parse errors.
            }
            showRunStatus(resp.status || 'completed', resp.displaymessage || resp.message || '', results);

            resumeStepPolling();
        } else if (resp.response_type === 'confirmation_request' || resp.response_type === 'task_call') {
            try {
                handleConfirmationResponse(resp, 'ai_send_message');
            } catch (e) {
                appendAssistantPrivacyNote(resp, 'ai_send_message');
                appendMessage('assistant', resp.commands || '', {
                    response_type: resp.response_type || '',
                    threadid: Number(resp.threadid || currentThreadId || 0),
                    runid: Number(resp.runid || 0),
                    source: 'ai_send_message',
                    time: (new Date()).toISOString(),
                });
            }
        } else {
            appendAssistantPrivacyNote(resp, 'ai_send_message');
            appendMessage('assistant', resp.displaymessage || resp.message || '', {
                response_type: resp.response_type || '',
                threadid: Number(resp.threadid || currentThreadId || 0),
                runid: Number(resp.runid || 0),
                source: 'ai_send_message',
                time: (new Date()).toISOString(),
            });
        }
        return resp;
    });
    }).catch((err) => {
        stopStepPolling();
        clearStepBubbles();
        if (thinking) {
            thinking.classList.add('d-none');
            updateThinkingLabel(defaultThinkingLabel);
        }
        if (stopBtn) {
            stopBtn.classList.add('d-none');
        }
        if (sendBtn) {
            sendBtn.disabled = false;
        }
        Notification.exception(err);
    });
};

/**
 * Confirm and submit the pending commands for execution.
 *
 * @param {boolean} allowSession Whether to allow confirmation for this thread in this session.
 */
const confirmRun = (allowSession = false) => {
    if (!pendingCommands) {
        return;
    }

    if (pendingQueueItemId === '') {
        showRunStatus('failed', 'Missing queue item id. Please confirm the latest assistant proposal again.');
        return;
    }

    clearStepBubbles();
    appendStepBubble(stepExecutingLabel, 0);

    const effectiveAllowSession = Boolean(allowSession || sessionAutoConfirmEnabled);
    if (effectiveAllowSession) {
        sessionAutoConfirmEnabled = true;
    }
    hideConfirmPanel(false);

    Ajax.call([{
        methodname: 'bookingextension_agent_ai_confirm_run',
        args: {
            contextid: currentContextId,
            threadid: currentThreadId,
            queue_item_id: pendingQueueItemId,
            allow_session: effectiveAllowSession,
        },
    }])[0].then((resp) => {
        if (resp.success) {
            const responseType = String(resp.response_type || '');
            // Steps are ephemeral loading indicators. Clear them and stop polling when response arrives.
            stopStepPolling();
            clearStepBubbles();
            // After confirmation, resume polling only if agent loop continues (execution_result, task_call).
            // For final responses (sufficient, error) or clarification, polling already stopped above.
            if (responseType === 'execution_result' || responseType === 'task_call') {
                appendStepBubble(stepExecutingLabel, 0);
                resumeStepPolling();
            }
            const confirmPreviewIds = collectPreviewOptionIds(resp, []);
            if (confirmPreviewIds.length > 0) {
                renderOptionPreviewsInline(currentContextId, confirmPreviewIds);
            }
            handleConfirmationResponse(resp, 'ai_confirm_run');
        } else {
            clearActivePlanBubble();
            showRunStatus('failed', resp.message);
        }
        pendingCommands = null;
        return resp;
    }).catch((err) => {
        Notification.exception(err);
    });
};

/**
 * Collect trial UI elements and labels from the wrapper.
 *
 * @returns {Object}
 */
const getTrialUiContext = () => {
    const trialBtn     = document.getElementById('booking-ai-trial-btn');
    const activateBtn  = document.getElementById('booking-ai-activate-btn');
    const activateWrap = document.getElementById('booking-ai-trial-activate-wrap');
    const trialSpinner = document.getElementById('booking-ai-trial-spinner');
    const trialResult  = document.getElementById('booking-ai-trial-result');
    const wrapper = document.getElementById('booking-ai-wrapper');
    const trialSuccessDefault = String((wrapper && wrapper.dataset.trialSuccessDefault) || '');
    const trialReloadingLabel = String((wrapper && wrapper.dataset.trialReloadingLabel) || '');
    const trialFailedDefault = String((wrapper && wrapper.dataset.trialFailedDefault) || '');
    const trialUnexpectedError = String((wrapper && wrapper.dataset.trialUnexpectedError) || '');
    const trialActivateSuccess = String((wrapper && wrapper.dataset.trialActivateSuccess) || trialSuccessDefault);

    return {
        trialBtn,
        activateBtn,
        activateWrap,
        trialSpinner,
        trialResult,
        wrapper,
        trialSuccessDefault,
        trialReloadingLabel,
        trialFailedDefault,
        trialUnexpectedError,
        trialActivateSuccess,
    };
};

/**
 * Request a trial key and update onboarding UI.
 */
const requestTrialKey = () => {
    const ctx = getTrialUiContext();
    if (!ctx.trialBtn) {
        return;
    }

    ctx.trialBtn.disabled = true;
    if (ctx.trialSpinner) {
        ctx.trialSpinner.classList.remove('d-none');
    }
    if (ctx.trialResult) {
        ctx.trialResult.classList.add('d-none');
        ctx.trialResult.innerHTML = '';
    }

    Ajax.call([{
        methodname: 'bookingextension_agent_request_trial_key',
        args: {contextid: Number(ctx.trialBtn.dataset.contextid || 0)},
    }])[0].then((resp) => {
        if (ctx.trialSpinner) {
            ctx.trialSpinner.classList.add('d-none');
        }
        if (ctx.trialResult) {
            ctx.trialResult.classList.remove('d-none');
            if (resp && resp.success) {
                ctx.trialResult.innerHTML =
                    '<div class="alert alert-success mb-0">'
                    + '<i class="fa fa-check-circle mr-2" aria-hidden="true"></i>'
                    + renderTextWithLinks(resp.message || ctx.trialSuccessDefault)
                    + '</div>';
                if (ctx.activateWrap) {
                    ctx.activateWrap.classList.remove('d-none');
                }
                if (ctx.activateBtn) {
                    ctx.activateBtn.disabled = false;
                }
            } else {
                ctx.trialResult.innerHTML =
                    '<div class="alert alert-danger mb-0">'
                    + '<i class="fa fa-exclamation-circle mr-2" aria-hidden="true"></i>'
                    + renderTextWithLinks((resp && resp.message) || ctx.trialFailedDefault)
                    + '</div>';
                ctx.trialBtn.disabled = false;
            }
        }
        return resp;
    }).catch((err) => {
        if (ctx.trialSpinner) {
            ctx.trialSpinner.classList.add('d-none');
        }
        if (ctx.trialResult) {
            ctx.trialResult.classList.remove('d-none');
            ctx.trialResult.innerHTML =
                '<div class="alert alert-danger mb-0">'
                    + renderTextWithLinks(err.message || ctx.trialUnexpectedError)
                + '</div>';
        }
        ctx.trialBtn.disabled = false;
        Notification.exception(err);
    });
};

/**
 * Activate trial context and refresh page on success.
 */
const activateTrialContext = () => {
    const ctx = getTrialUiContext();
    if (!ctx.activateBtn) {
        return;
    }

    ctx.activateBtn.disabled = true;
    if (ctx.trialSpinner) {
        ctx.trialSpinner.classList.remove('d-none');
    }

    Ajax.call([{
        methodname: 'bookingextension_agent_activate_trial_context',
        args: {
            contextid: Number(
                (ctx.trialBtn && ctx.trialBtn.dataset.contextid)
                || (ctx.wrapper && ctx.wrapper.dataset.contextid)
                || 0
            ),
        },
    }])[0].then((resp) => {
        if (ctx.trialSpinner) {
            ctx.trialSpinner.classList.add('d-none');
        }
        if (ctx.trialResult) {
            ctx.trialResult.classList.remove('d-none');
            if (resp && resp.success) {
                ctx.trialResult.innerHTML =
                    '<div class="alert alert-success mb-0">'
                    + '<i class="fa fa-check-circle mr-2" aria-hidden="true"></i>'
                    + renderTextWithLinks(resp.message || ctx.trialActivateSuccess)
                    + ' <strong>' + escapeHtml(ctx.trialReloadingLabel) + '</strong></div>';
                setTimeout(() => window.location.reload(), 1800);
            } else {
                ctx.trialResult.innerHTML =
                    '<div class="alert alert-danger mb-0">'
                    + '<i class="fa fa-exclamation-circle mr-2" aria-hidden="true"></i>'
                    + renderTextWithLinks((resp && resp.message) || ctx.trialFailedDefault)
                    + '</div>';
                ctx.activateBtn.disabled = false;
            }
        }
        return resp;
    }).catch((err) => {
        if (ctx.trialSpinner) {
            ctx.trialSpinner.classList.add('d-none');
        }
        if (ctx.trialResult) {
            ctx.trialResult.classList.remove('d-none');
            ctx.trialResult.innerHTML =
                '<div class="alert alert-danger mb-0">'
                + renderTextWithLinks(err.message || ctx.trialUnexpectedError)
                + '</div>';
        }
        ctx.activateBtn.disabled = false;
        Notification.exception(err);
    });
};

/**
 * Kept for backwards compatibility with existing init flow.
 */
const bindTrialButton = () => {
    // Interaction handlers are delegated centrally on document.body.
};

/**
 * Display the welcome message based on booking statistics.
 *
 * @param {number} numOptions
 * @param {number} numBooked
 */
const displayWelcomeMessage = (numOptions, numBooked) => {
    const welcomeText = document.getElementById('booking-ai-welcome-text');
    if (!welcomeText) {
        return;
    }

    // The template already renders a server-side welcome text.
    // Only inject via JS when the container is still empty.
    if (String(welcomeText.textContent || '').trim() !== '') {
        return;
    }

    let message = '';
    if (numOptions === 0) {
        message = 'Welcome! Would you like me to help you create your first booking option?';
    } else {
        message = `Welcome! You have ${numOptions} booking options here, and ${numBooked} people are already booked. ` +
            'How can I help you?';
    }

    const p = document.createElement('p');
    p.textContent = message;
    welcomeText.appendChild(p);
};

/**
 * Stop active request UI and discard pending response.
 */
const stopCurrentRun = () => {
    sendAborted = true;
    stopStepPolling();
    clearStepBubbles();
    const thinkingEl = document.getElementById('booking-ai-thinking');
    if (thinkingEl) {
        thinkingEl.classList.add('d-none');
        updateThinkingLabel(defaultThinkingLabel);
    }
    const stopBtnEl = document.getElementById('booking-ai-btn-stop');
    if (stopBtnEl) {
        stopBtnEl.classList.add('d-none');
    }
    const sendBtnEl = document.getElementById('booking-ai-send');
    if (sendBtnEl) {
        sendBtnEl.disabled = false;
    }
};

/**
 * Delegated body click handler for all aiinstructions interactions.
 *
 * @param {MouseEvent} event
 */
const handleBodyClick = (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) {
        return;
    }

    const wrapper = target.closest('#booking-ai-wrapper');
    if (!(wrapper instanceof HTMLElement)) {
        return;
    }

    const mobileToggle = target.closest('#booking-ai-mobile-toggle');
    if (mobileToggle instanceof HTMLElement) {
        const layout = document.getElementById('booking-ai-body-layout');
        const label = mobileToggle.querySelector('.booking-ai-mobile-toggle-label');
        if (layout) {
            const previewActive = !layout.classList.contains('mobile-preview-active');
            layout.classList.toggle('mobile-preview-active', previewActive);
            mobileToggle.setAttribute('aria-pressed', previewActive ? 'true' : 'false');
            if (label) {
                label.textContent = previewActive ? 'Chat' : 'Preview';
            }
        }
        return;
    }

    const copyBtn = target.closest('.booking-ai-copy-debug-logs');
    if (copyBtn instanceof HTMLElement) {
        const panel = copyBtn.closest('.booking-ai-all-debug-logs, .booking-ai-debug-logs');
        const plain = panel ? panel.querySelector('.booking-ai-debug-logs-plain') : null;
        const plainText = plain ? String(plain.textContent || '') : '';

        copyTextToClipboard(plainText).then(() => {
            showButtonFeedback(copyBtn, 'Copied!', 'btn-success');
        }).catch(() => {
            showButtonFeedback(copyBtn, 'Copy failed', 'btn-danger');
            Notification.alert('Failed to copy debug logs to clipboard.');
        });
        return;
    }

    const refreshBtn = target.closest('#booking-ai-debug-refresh');
    if (refreshBtn instanceof HTMLElement) {
        refreshThreadDebugLogs();
        return;
    }

    const sendBtn = target.closest('#booking-ai-send');
    if (sendBtn instanceof HTMLElement) {
        const inputEl = document.getElementById('booking-ai-input');
        if (inputEl instanceof HTMLTextAreaElement || inputEl instanceof HTMLInputElement) {
            const msg = inputEl.value;
            inputEl.value = '';
            sendMessage(msg);
        }
        return;
    }

    const confirmBtn = target.closest('#booking-ai-btn-confirm');
    if (confirmBtn instanceof HTMLElement) {
        confirmRun();
        return;
    }

    const confirmSessionBtn = target.closest('#booking-ai-btn-confirm-session');
    if (confirmSessionBtn instanceof HTMLElement) {
        confirmRun(true);
        return;
    }

    const cancelBtn = target.closest('#booking-ai-btn-cancel');
    if (cancelBtn instanceof HTMLElement) {
        hideConfirmPanel();
        return;
    }

    const stopBtn = target.closest('#booking-ai-btn-stop');
    if (stopBtn instanceof HTMLElement) {
        stopCurrentRun();
        return;
    }

    const trialBtn = target.closest('#booking-ai-trial-btn');
    if (trialBtn instanceof HTMLElement) {
        requestTrialKey();
        return;
    }

    const activateBtn = target.closest('#booking-ai-activate-btn');
    if (activateBtn instanceof HTMLElement) {
        activateTrialContext();
        return;
    }

    const anchor = target.closest('a');
    if (anchor instanceof HTMLAnchorElement) {
        const href = String(anchor.getAttribute('href') || '').trim();
        const inlineDocPath = String(anchor.getAttribute('data-docpath') || '').trim();
        const inlineDocFragment = String(anchor.getAttribute('data-docfragment') || '').trim();
        if (inlineDocPath !== '') {
            event.preventDefault();
            loadDocInPreview(inlineDocPath, '', inlineDocFragment);
            return;
        }

        const resolvedDocMeta = getDocLinkMeta(href);
        if (resolvedDocMeta.docpath !== '') {
            event.preventDefault();
            loadDocInPreview(resolvedDocMeta.docpath, '', resolvedDocMeta.fragment);
            return;
        }

        if (href !== '' && !href.startsWith('#')) {
            event.preventDefault();
            window.open(href, '_blank', 'noopener,noreferrer');
            return;
        }
    }

    const optionBtn = target.closest('.booking-ai-ambiguity-option, .booking-ai-followup-option');
    if (!(optionBtn instanceof HTMLElement)) {
        return;
    }

    const query = String(optionBtn.getAttribute('data-query') || '').trim();
    if (query === '') {
        return;
    }

    optionBtn.classList.remove('btn-outline-primary', 'btn-outline-secondary');
    optionBtn.classList.add('btn-primary');

    const isFollowUp = optionBtn.classList.contains('booking-ai-followup-option');
    if (isFollowUp) {
        const inputElement = document.getElementById('booking-ai-input');
        if (inputElement instanceof HTMLTextAreaElement || inputElement instanceof HTMLInputElement) {
            inputElement.value = query;
            inputElement.focus();
            inputElement.setSelectionRange(inputElement.value.length, inputElement.value.length);
        }
        return;
    }

    optionBtn.setAttribute('aria-disabled', 'true');
    sendMessage(query);
};

/**
 * Delegated key handler for sending messages with Enter.
 *
 * @param {KeyboardEvent} event
 */
const handleBodyKeydown = (event) => {
    if (event.key !== 'Enter' || event.shiftKey) {
        return;
    }

    const target = event.target;
    if (!(target instanceof HTMLElement)) {
        return;
    }

    if (!target.matches('#booking-ai-input')) {
        return;
    }

    const wrapper = target.closest('#booking-ai-wrapper');
    if (!(wrapper instanceof HTMLElement)) {
        return;
    }

    event.preventDefault();
    if (target instanceof HTMLTextAreaElement || target instanceof HTMLInputElement) {
        const msg = target.value;
        target.value = '';
        sendMessage(msg);
    }
};

/**
 * Bind one central delegated handler on body for aiinstructions interactions.
 */
const initCentralBodyHandlers = () => {
    if (bodyHandlersBound || !(document.body instanceof HTMLElement)) {
        return;
    }

    document.body.addEventListener('click', handleBodyClick);
    document.body.addEventListener('keydown', handleBodyKeydown);
    bodyHandlersBound = true;
};

/**
 * Initialise the AI instructions interface.
 *
 * @param {Object|null} config Template data from DOM or explicit config.
 */
export const init = (config = null) => {
    let runtimeConfig = config;

    if (!runtimeConfig) {
        const wrapper = document.getElementById('booking-ai-wrapper');
        if (!wrapper) {
            return;
        }

        runtimeConfig = {
            contextid: Number(wrapper.dataset.contextid || 0),
            threadid: Number(wrapper.dataset.threadid || 0),
            ready_for_chat: String(wrapper.dataset.readyForChat || '0') === '1',
            num_options: Number(wrapper.dataset.numOptions || 0),
            num_booked: Number(wrapper.dataset.numBooked || 0),
            debug_mode: String(wrapper.dataset.debugMode || '0') === '1',
            privacy_check_running: String(wrapper.dataset.privacyCheckRunning || 'Privacy check running...'),
            privacy_answer_note: String(
                wrapper.dataset.privacyAnswerNote
                || 'Privacy note: personal data in this response was de-anonymized for display.'
            ),
            trial_token_invalid_message: String(wrapper.dataset.aiTrialTokenInvalidMessage || ''),
            step_planning_label: String(wrapper.dataset.stepPlanningLabel || ''),
            step_executing_label: String(wrapper.dataset.stepExecutingLabel || ''),
        };
    }

    currentContextId = runtimeConfig.contextid || 0;
    currentThreadId = runtimeConfig.threadid || 0;
    debugModeEnabled = Boolean(runtimeConfig.debug_mode);
    privacyCheckRunningLabel = String(runtimeConfig.privacy_check_running || privacyCheckRunningLabel);
    privacyAnswerNoteLabel = String(runtimeConfig.privacy_answer_note || privacyAnswerNoteLabel);
    trialTokenInvalidMessageLabel = String(runtimeConfig.trial_token_invalid_message || '');
    stepPlanningLabel = String(runtimeConfig.step_planning_label || stepPlanningLabel);
    stepExecutingLabel = String(runtimeConfig.step_executing_label || stepExecutingLabel);

    const thinking = document.getElementById('booking-ai-thinking');
    if (thinking) {
        const thinkingLabel = document.getElementById('booking-ai-thinking-label');
        if (thinkingLabel) {
            defaultThinkingLabel = String(thinkingLabel.textContent || '').trim() || 'Thinking...';
        } else {
            defaultThinkingLabel = String(thinking.textContent || '').trim() || 'Thinking...';
        }
    }

    // Must be available in onboarding mode where ready_for_chat is false.
    bindTrialButton();
    initCentralBodyHandlers();

    if (!runtimeConfig.ready_for_chat) {
        return;
    }

    initResizableLayout();
    initMobilePreviewSwitch();

    // Display welcome message based on booking statistics.
    displayWelcomeMessage(runtimeConfig.num_options || 0, runtimeConfig.num_booked || 0);

    initDebugRefreshButton();

};
