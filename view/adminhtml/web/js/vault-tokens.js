/*
 * Copyright © Mago Assistant
 */

/**
 * Puts the real values back in place of privacy-mode vault tokens (mago://name_1), always as text.
 *
 * The server sends an answer with its tokens intact and the value behind each one beside it. The
 * answer is rendered and sanitized first, and only then is each token swapped for its value, in a
 * text node or an attribute value. A value is whatever a customer or the store holds (a review, a
 * name), so it can never become markup, a link or an image this way, however it is written.
 *
 * A mago://url_N token is the one exception: it stands for an admin URL the server built itself, so
 * where the answer uses it as a link target it resolves to that URL, and the link rules still apply.
 * Written bare in the text, it becomes a link to that URL when the URL is on this store.
 *
 * The model sometimes escapes a token as markdown ("mago://url\_3"); it is matched and looked up as
 * the token it stands for, never shown as raw token grammar (#160).
 */
define([], function () {
    'use strict';

    const TOKEN = /mago\\?:\\?\/\\?\/[a-z]+\\?_\d+|\\?\[[a-z]+\\?_\d+\\?\]/g;
    const URL_TOKEN = /^(?:mago\\?:\\?\/\\?\/url\\?_\d+|\\?\[url\\?_\d+\\?\])$/;
    const NO_LINK_INSIDE = /^(?:a|code|pre|button)$/i;
    const UNKNOWN_VALUE = '[earlier record]';
    // The attributes a value may go into, because there it is only ever read as text. In any other
    // attribute it could decide what loads, where a click goes, how the element is styled or what
    // a script finds by id, so an attribute carrying a token anywhere else is dropped.
    const TEXT_ATTRIBUTE = /^(?:title|alt|aria-label|placeholder|data-mago-send)$/i;

    function canonical(token) {
        return token.replace(/\\/g, '');
    }

    function hasValue(tokens, token) {
        return !!tokens && Object.prototype.hasOwnProperty.call(tokens, canonical(token));
    }

    function valueOf(tokens, token) {
        return String(tokens[canonical(token)]);
    }

    function containsToken(text) {
        return String(text).search(TOKEN) !== -1;
    }

    // A token the conversation cannot resolve (deleted vault rows, a forged or foreign token) shows
    // a neutral label instead of token grammar the admin never typed.
    function replaceTokens(text, tokens) {
        return String(text).replace(TOKEN, function (token) {
            return hasValue(tokens, token) ? valueOf(tokens, token) : UNKNOWN_VALUE;
        });
    }

    function resolveUrl(value, tokens) {
        const trimmed = typeof value === 'string' ? value.trim() : '';
        return URL_TOKEN.test(trimmed) && hasValue(tokens, trimmed) ? valueOf(tokens, trimmed) : value;
    }

    function resolveUrlsDeep(value, tokens) {
        if (typeof value === 'string') {
            return resolveUrl(value, tokens);
        }
        if (Array.isArray(value)) {
            return value.map(function (item) { return resolveUrlsDeep(item, tokens); });
        }
        if (value && typeof value === 'object') {
            return Object.keys(value).reduce(function (resolved, key) {
                resolved[key] = resolveUrlsDeep(value[key], tokens);
                return resolved;
            }, {});
        }
        return value;
    }

    function rehydrateTextNodes(root, tokens) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const textNodes = [];
        while (walker.nextNode()) {
            textNodes.push(walker.currentNode);
        }
        textNodes.filter(function (node) { return containsToken(node.nodeValue); }).forEach(function (node) {
            if (mayLink(node)) {
                linkUrlTokens(node, tokens);
                return;
            }
            node.nodeValue = replaceTokens(node.nodeValue, tokens);
        });
    }

    function mayLink(node) {
        for (let parent = node.parentNode; parent && parent.nodeType === 1; parent = parent.parentNode) {
            if (NO_LINK_INSIDE.test(parent.nodeName)) {
                return false;
            }
        }
        return true;
    }

    // Sanitizing ran before the values went in, so a link made here gets the link rules itself:
    // http(s) on this store only, anything else stays text.
    function storeUrl(value) {
        try {
            const url = new URL(value, window.location.href);
            return /^https?:$/.test(url.protocol) && url.origin === window.location.origin ? url.href : null;
        } catch (e) {
            return null;
        }
    }

    // A bare url token in running text becomes a link; every other token in the node stays text.
    function linkUrlTokens(node, tokens) {
        const text = node.nodeValue;
        const fragment = document.createDocumentFragment();
        let last = 0;
        TOKEN.lastIndex = 0;
        Array.from(text.matchAll(TOKEN)).forEach(function (match) {
            const token = match[0];
            const href = URL_TOKEN.test(token) && hasValue(tokens, token) ? storeUrl(valueOf(tokens, token)) : null;
            if (href === null) {
                return;
            }
            fragment.appendChild(document.createTextNode(replaceTokens(text.slice(last, match.index), tokens)));
            const link = document.createElement('a');
            link.setAttribute('href', href);
            link.setAttribute('target', '_self');
            link.textContent = href;
            fragment.appendChild(link);
            last = match.index + token.length;
        });
        if (last === 0) {
            node.nodeValue = replaceTokens(text, tokens);
            return;
        }
        fragment.appendChild(document.createTextNode(replaceTokens(text.slice(last), tokens)));
        node.parentNode.replaceChild(fragment, node);
    }

    function rehydrateAttributes(element, tokens) {
        Array.prototype.slice.call(element.attributes)
            .filter(function (attribute) { return containsToken(attribute.value); })
            .forEach(function (attribute) {
                if (TEXT_ATTRIBUTE.test(attribute.name)) {
                    element.setAttribute(attribute.name, replaceTokens(attribute.value, tokens));
                    return;
                }
                element.removeAttribute(attribute.name);
            });
    }

    function rehydrateNode(root, tokens) {
        rehydrateTextNodes(root, tokens);
        Array.prototype.slice.call(root.querySelectorAll('*')).forEach(function (element) {
            rehydrateAttributes(element, tokens);
        });
        return root;
    }

    // Sanitized HTML in, the same HTML with every token replaced by its value as text out. Parsed in
    // a template, which is inert: nothing in it loads or runs while the values go in.
    function rehydrateHtml(html, tokens) {
        if (!containsToken(html)) {
            return html;
        }
        const template = document.createElement('template');
        template.innerHTML = html;
        rehydrateNode(template.content, tokens);
        return template.innerHTML;
    }

    function merge(tokens, more) {
        return Object.assign({}, tokens || {}, more || {});
    }

    return {
        replaceTokens: replaceTokens,
        resolveUrl: resolveUrl,
        resolveUrlsDeep: resolveUrlsDeep,
        rehydrateHtml: rehydrateHtml,
        merge: merge
    };
});
