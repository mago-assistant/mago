/*
 * Copyright © Mago Assistant
 */

/**
 * Command matching for voice input (#141): the yes, no and stop words (the shop's own on top of
 * built-in English, see Model\Voice\CommandWords) and the numbers or texts that pick a suggestion.
 * Text in, answer out; the chat panel wires the answers to the bar, cards and suggestions.
 *
 * Yes and no answer the hands-free bar and confirmation cards alike, spoken or typed as a short
 * reply. A longer reply is a real message for the model.
 */
define([], function () {
    'use strict';

    var MAX_BAR_REPLY_WORDS = 4;
    var MAX_CARD_REPLY_WORDS = 5;
    var MAX_NUMBER_REPLY_WORDS = 3;
    var NUMBER_WORDS = {
        one: 1, two: 2, three: 3, four: 4, five: 5, six: 6, seven: 7, eight: 8, nine: 9, ten: 10,
        first: 1, second: 2, third: 3, fourth: 4, fifth: 5, sixth: 6, seventh: 7, eighth: 8, ninth: 9, tenth: 10
    };

    // Lower case with punctuation and a free-standing dash dropped, so "Ja, toestaan." and
    // "ja toestaan" are the same reply.
    function normalize(text) {
        return String(text || '').toLowerCase().replace(/[.!?,;:…]/g, ' ').replace(/\s+-\s+|\s+/g, ' ').trim();
    }

    function withinOneEdit(a, b) {
        if (Math.abs(a.length - b.length) > 1) return false;
        var i = 0, j = 0, edits = 0;
        while (i < a.length && j < b.length) {
            if (a[i] === b[j]) { i++; j++; continue; }
            if (++edits > 1) return false;
            if (a.length > b.length) i++;
            else if (a.length < b.length) j++;
            else { i++; j++; }
        }
        return edits + (a.length - i) + (b.length - j) <= 1;
    }

    // Whole words only, so "bestuur" never reads as "stuur" and "look" never as "ok". The whole
    // reply also counts with its spaces and hyphens taken out, as recognisers write some words
    // joined or split ("toe staan", "start-over"). With misheard set, a lone word may be one
    // letter off a word of four letters or more ("sent" for "send") without letting "is" pass for
    // "wis"; only for the bar, where a short reply is meant as a command, never for a card a
    // typed "note" could otherwise reject as "nope".
    function saysAny(reply, words, misheard) {
        var padded = ' ' + reply + ' ';
        var single = reply.indexOf(' ') === -1;
        var compactReply = reply.replace(/[\s-]+/g, '');
        return words.some(function (w) {
            if (!w) return false;
            if (padded.indexOf(' ' + w + ' ') !== -1) return true;
            if (misheard && single && w.length >= 4 && w.indexOf(' ') === -1 && withinOneEdit(reply, w)) return true;
            return compactReply === w.replace(/[\s-]+/g, '');
        });
    }

    /**
     * @param {Object} options
     * @param {Object<string, string[]>} [options.words] lower-cased words per command: yes, no, stop
     * @param {Object<string, number>} [options.numberWords] the shop's own number words
     */
    return function createVoiceCommands(options) {
        var words = options.words || {};
        var numberWords = options.numberWords || {};

        function wordsFor(command) {
            return words[command] || [];
        }

        function yesOrNo(text, maxWords, misheard) {
            var reply = normalize(text);
            if (!reply || reply.split(' ').length > maxWords) return null;
            var matches = ['yes', 'no'].filter(function (command) {
                return saysAny(reply, wordsFor(command), misheard);
            });
            return matches.length === 1 ? matches[0] : null;
        }

        function spokenNumber(word) {
            if (/^\d+$/.test(word)) return parseInt(word, 10);
            if (Object.prototype.hasOwnProperty.call(numberWords, word)) return numberWords[word];
            return NUMBER_WORDS.hasOwnProperty(word) ? NUMBER_WORDS[word] : null;
        }

        return {
            barReply: function (text) {
                return yesOrNo(text, MAX_BAR_REPLY_WORDS, true);
            },

            cardReply: function (text) {
                var answer = yesOrNo(text, MAX_CARD_REPLY_WORDS, false);
                return answer === 'yes' ? 'allow' : answer === 'no' ? 'reject' : null;
            },

            // Stricter than yes and no: only a reply that is exactly a stop word, so "stop the
            // import" stays dictation and a lone "shop" does not end the session.
            isStop: function (text) {
                var reply = normalize(text);
                return !!reply && wordsFor('stop').indexOf(reply) !== -1;
            },

            firstWord: function (command) {
                return wordsFor(command)[0] || command;
            },

            // Index of the label a reply picks, or -1. A number counts in a short reply that ends
            // on it ("2", "option 2", "de tweede"), so "show 2 orders" or "toon een order" (Dutch
            // "een" is also "a") still goes out as a message. Text counts when it is the whole
            // label, or a part of at least two words that only one label contains.
            pick: function (text, labels) {
                var reply = normalize(text);
                if (!labels.length || !reply) return -1;
                var replyWords = reply.split(' ');
                if (replyWords.length <= MAX_NUMBER_REPLY_WORDS) {
                    var numbers = replyWords.map(spokenNumber).filter(function (n) { return n !== null; });
                    var last = spokenNumber(replyWords[replyWords.length - 1]);
                    if (numbers.length === 1 && last !== null && last >= 1 && last <= labels.length) return last - 1;
                }
                var exact = [];
                var partial = [];
                labels.forEach(function (label, i) {
                    var normalized = normalize(label);
                    if (normalized === reply) exact.push(i);
                    if (replyWords.length >= 2 && (' ' + normalized + ' ').indexOf(' ' + reply + ' ') !== -1) partial.push(i);
                });
                if (exact.length === 1) return exact[0];
                return partial.length === 1 ? partial[0] : -1;
            }
        };
    };
});
