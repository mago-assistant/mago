/**
 * Copyright © Mago Assistant
 *
 * Which URLs a model-written answer may point at. Model output is attacker-influenceable (a guest
 * review or product description can carry injected instructions), so a link is only ever http(s)
 * or a path on this store, and anything that loads by itself (an image, a thumb) only ever comes
 * from this store: an off-site image would leak the admin's visit, and whatever its URL carries,
 * without a single click.
 */
define([], function () {
    'use strict';

    // Browsers drop tabs and newlines inside a URL and read a backslash as a slash, so "/\evil.com"
    // and "/<tab>/evil.com" both end up as the protocol-relative "//evil.com".
    var UNSAFE_CHARACTERS = /[\u0000-\u001F\u007F\\]/;
    var ALLOWED_LINK = /^(https?:\/\/|\/(?!\/)|[#?])/i;

    function normalize(url) {
        return typeof url === 'string' ? url.trim() : '';
    }

    function isSafeLink(url) {
        var normalized = normalize(url);
        return normalized !== '' && !UNSAFE_CHARACTERS.test(normalized) && ALLOWED_LINK.test(normalized);
    }

    function isSameOrigin(url) {
        if (!isSafeLink(url)) {
            return false;
        }
        try {
            return new URL(normalize(url), window.location.href).origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    // A path given as "/admin/" also covers the bare "/admin" it starts with.
    function isUnderPath(url, path) {
        if (!path || !isSameOrigin(url)) {
            return false;
        }
        return (new URL(normalize(url), window.location.href).pathname + '/').indexOf(path) === 0;
    }

    function safeHref(url) {
        return isSafeLink(url) ? normalize(url) : null;
    }

    return {
        isSafeLink: isSafeLink,
        isSameOrigin: isSameOrigin,
        isUnderPath: isUnderPath,
        safeHref: safeHref
    };
});
