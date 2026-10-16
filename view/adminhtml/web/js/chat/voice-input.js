/*
 * Copyright © Mago Assistant
 */

/**
 * Microphone button for the chat input (#141), backed by the browser's own speech recognition (Web
 * Speech API). Press it, speak, and the words are added to whatever is already in the input while
 * the administrator talks. Recording ends at the first pause or on a second press, and the focus
 * goes back to the input: the text is reviewed and sent like typed text. Nothing is ever sent here.
 *
 * The audio never passes through Mago or the AI provider: the browser vendor handles it.
 */
define([], function () {
    'use strict';

    var QUIET_ERRORS = ['no-speech', 'aborted'];
    var DENIED_ERRORS = ['not-allowed', 'service-not-allowed'];

    function recognitionClass() {
        return window.SpeechRecognition || window.webkitSpeechRecognition || null;
    }

    function appendSpoken(before, spoken) {
        var words = spoken.trim();
        if (words === '') return before;
        if (before === '' || /\s$/.test(before)) return before + words;
        return before + ' ' + words;
    }

    function transcriptOf(results) {
        return Array.prototype.map.call(results, function (result) {
            return result[0].transcript;
        }).join('');
    }

    /**
     * @param {Object} options
     * @param {HTMLTextAreaElement} options.input
     * @param {HTMLButtonElement} options.button hidden until a recogniser is found
     * @param {string} options.lang BCP-47 tag the browser listens in
     * @param {Function} options.t translator
     * @param {Function} options.requireConsent called with the function that starts recording
     * @param {Function} options.onError called with a sentence to show the administrator
     */
    return function createVoiceInput(options) {
        var Recognition = recognitionClass();
        var input = options.input;
        var button = options.button;
        var t = options.t;
        var recognition = null;
        var textBefore = '';

        if (!Recognition || !button) {
            return {isSupported: false, cancel: function () {}};
        }

        function setListening(isListening) {
            var label = isListening ? t('Stop listening') : t('Speak your message');
            button.classList.toggle('is-listening', isListening);
            button.setAttribute('aria-pressed', isListening ? 'true' : 'false');
            button.title = label;
            button.setAttribute('aria-label', label);
        }

        function showSpoken(spoken) {
            input.value = appendSpoken(textBefore, spoken);
            input.dispatchEvent(new Event('input'));
        }

        function focusInputEnd() {
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        }

        function reportError(error) {
            if (QUIET_ERRORS.indexOf(error) !== -1) return;
            if (DENIED_ERRORS.indexOf(error) !== -1) {
                options.onError(t('Microphone access was denied. Allow it in the browser to use voice input.'));
                return;
            }
            options.onError(t('Speech recognition stopped (%1). Press the microphone to try again.', error));
        }

        function start() {
            textBefore = input.value;
            recognition = new Recognition();
            recognition.lang = options.lang || '';
            recognition.interimResults = true;
            recognition.continuous = false;
            recognition.onresult = function (event) {
                showSpoken(transcriptOf(event.results));
            };
            recognition.onerror = function (event) {
                reportError(event.error);
            };
            recognition.onend = function () {
                recognition = null;
                setListening(false);
                focusInputEnd();
            };
            try {
                recognition.start();
                setListening(true);
            } catch (error) {
                recognition = null;
                setListening(false);
                options.onError(t('Could not start the microphone. Please try again.'));
            }
        }

        function stop() {
            if (recognition) recognition.stop();
        }

        function cancel() {
            if (recognition) recognition.abort();
        }

        button.hidden = false;
        button.addEventListener('click', function () {
            if (recognition) {
                stop();
                return;
            }
            options.requireConsent(start);
        });
        input.addEventListener('keydown', cancel);

        return {isSupported: true, cancel: cancel};
    };
});
