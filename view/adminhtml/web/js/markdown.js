/**
 * Copyright © Mago Assistant
 *
 * The one markdown renderer for every place an answer is shown: the chat panel and the
 * conversation view. Model output is attacker-influenceable, so every rendered answer goes
 * through DOMPurify before it reaches innerHTML, links only ever point at http(s) or this store,
 * and an image only renders when it comes from this store; any other image shows its alt text.
 *
 * Each create() gets its own marked instance and its own DOMPurify instance, so nothing here
 * changes the global marked or DOMPurify another module may use, and they cannot change ours.
 *
 * Given the conversation's vault values, privacy-mode tokens are rendered as they are and replaced
 * by their values only after sanitizing, as text (js/vault-tokens.js). Without them the tokens stay.
 */
define([
    'MagoAssistant_Mago/js/marked.min',
    'MagoAssistant_Mago/js/purify.min',
    'MagoAssistant_Mago/js/safe-url',
    'MagoAssistant_Mago/js/vault-tokens'
], function (markedLibrary, createPurifier, safeUrl, vaultTokens) {
    'use strict';

    var PURIFY_CONFIG = {
        ADD_ATTR: ['target'],
        FORBID_TAGS: ['style', 'form'],
        FORBID_ATTR: ['srcset']
    };
    // Attributes that make the browser fetch something on its own: same-origin only.
    var RESOURCE_ATTRIBUTES = ['src', 'poster', 'background', 'xlink:href', 'action', 'formaction'];
    var STYLE_FETCH = /url\s*\(|image-set|@import/i;
    var ELEMENT_NODE = 1;

    // Values reach the renderer still carrying entities (the panel escapes before parsing), and a
    // browser decodes those in an attribute, so "javascript&#58;" is checked as what it becomes.
    function decodeEntities(value) {
        var textarea = document.createElement('textarea');
        textarea.innerHTML = String(value || '');
        return textarea.value;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function titleAttribute(title) {
        return title ? ' title="' + escapeHtml(decodeEntities(title)) + '"' : '';
    }

    // An admin page opens in the same tab, where the panel picks the conversation up again from
    // sessionStorage. Anything else, the storefront included, has no panel and opens in a new tab.
    function applyLinkPolicy(node, adminPath) {
        var href = node.getAttribute('href');
        if (!safeUrl.isSafeLink(href)) {
            node.removeAttribute('href');
            node.removeAttribute('target');
            return;
        }
        if (safeUrl.isUnderPath(href, adminPath)) {
            node.setAttribute('target', '_self');
            return;
        }
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer');
    }

    function enforceUrlPolicy(node, adminPath) {
        if (node.nodeType !== ELEMENT_NODE) {
            return;
        }
        RESOURCE_ATTRIBUTES.forEach(function (name) {
            if (node.hasAttribute(name) && !safeUrl.isSameOrigin(node.getAttribute(name))) {
                node.removeAttribute(name);
            }
        });
        if (node.hasAttribute('style') && STYLE_FETCH.test(node.getAttribute('style'))) {
            node.removeAttribute('style');
        }
        if (node.hasAttribute('href')) {
            applyLinkPolicy(node, adminPath);
            return;
        }
        node.removeAttribute('target');
    }

    function createSanitizer(adminPath) {
        var purifier = createPurifier(window);
        purifier.addHook('afterSanitizeAttributes', function (node) {
            enforceUrlPolicy(node, adminPath);
        });
        return function (html) {
            return purifier.sanitize(html, PURIFY_CONFIG);
        };
    }

    /**
     * options.adminPath         path every admin page lives under (Service\Url\AdminPath), with
     *                           a trailing slash; links below it open in the same tab
     * options.tableLabel        accessible name of the scrollable wrapper around a table
     * options.renderFencedBlock (lang, code, resolveUrls) => html string, or null for the default
     *                           code block; resolveUrls(spec) swaps the admin URL tokens in a parsed
     *                           spec for their URLs
     */
    function createRenderer(options) {
        var tableLabel = options.tableLabel || 'Table';
        var renderFencedBlock = options.renderFencedBlock || function () { return null; };
        var defaults = markedLibrary.Renderer.prototype;
        // The vault values of the render in progress; marked calls the renderers below synchronously.
        let currentTokens = null;

        function resolveUrls(spec) {
            return vaultTokens.resolveUrlsDeep(spec, currentTokens);
        }

        var parser = new markedLibrary.Marked({
            gfm: true,
            breaks: true,
            renderer: {
                link: function (token) {
                    var text = this.parser.parseInline(token.tokens);
                    var href = vaultTokens.resolveUrl(decodeEntities(token.href), currentTokens);
                    if (!safeUrl.isSafeLink(href)) {
                        return text;
                    }
                    return '<a href="' + escapeHtml(href) + '"' + titleAttribute(token.title) + '>' + text + '</a>';
                },
                image: function (token) {
                    var src = vaultTokens.resolveUrl(decodeEntities(token.href), currentTokens);
                    var alt = escapeHtml(decodeEntities(token.text));
                    if (!safeUrl.isSameOrigin(src)) {
                        return alt;
                    }
                    return '<img src="' + escapeHtml(src) + '" alt="' + alt + '"' + titleAttribute(token.title) + '>';
                },
                // Wide tables scroll inside a wrapper that is focusable, so the keyboard can scroll it too.
                table: function (token) {
                    return '<div class="mago-table-wrap" tabindex="0" role="region" aria-label="'
                        + escapeHtml(tableLabel) + '">' + defaults.table.call(this, token) + '</div>';
                },
                code: function (token) {
                    var html = renderFencedBlock(token.lang, token.text, resolveUrls);
                    return html === null ? defaults.code.call(this, token) : html;
                }
            }
        });
        var sanitize = createSanitizer(options.adminPath || '');

        function renderMarkup(markdown, tokens) {
            currentTokens = tokens || null;
            try {
                const html = sanitize(parser.parse(markdown));
                return tokens ? vaultTokens.rehydrateHtml(html, tokens) : html;
            } finally {
                currentTokens = null;
            }
        }

        return {
            // Text from the model: raw HTML in it shows as text, never as markup. tokens, when given,
            // maps each vault token to its value, which goes in as text once the HTML is sanitized.
            renderText: function (text, tokens) {
                return text ? renderMarkup(escapeText(text), tokens) : '';
            }
        };
    }

    function escapeText(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    return {
        create: createRenderer
    };
});
