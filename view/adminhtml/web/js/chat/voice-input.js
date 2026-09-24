/*
 * Copyright © Mago Assistant
 */

/**
 * Microphone button for the chat input, backed by the browser's own speech recognition (Web
 * Speech API). Recognised words land in the textarea while the administrator speaks and stay
 * editable. With auto-send on, a pause in speech starts a short visible countdown and then sends;
 * typing or pressing the microphone during the countdown cancels it, so a misheard sentence can
 * always be corrected first.
 *
 * The audio never passes through Mago or the AI provider: the browser vendor handles it. The
 * resulting text is indistinguishable from typed text to the rest of the panel, which is why this
 * module only writes to the textarea and fires its input event.
 */
define([], function () {
    'use strict';

    // Silence after the last final result before the countdown starts, and the countdown itself.
    var PAUSE_MS = 1200;
    var COUNTDOWN_SECONDS = 2;

    function recognitionClass() {
        return window.SpeechRecognition || window.webkitSpeechRecognition || null;
    }

    /**
     * @param {Object} options
     * @param {HTMLTextAreaElement} options.input
     * @param {HTMLButtonElement} options.button
     * @param {HTMLElement} [options.status] status line under the input
     * @param {string} options.lang BCP-47 tag for the recogniser
     * @param {boolean} options.autoSend
     * @param {Function} options.t translator
     * @param {Function} [options.onSend] called when the countdown finishes
     * @param {Function} [options.onError] receives a sentence to show the administrator
     * @returns {{isSupported: boolean, stop: Function}}
     */
    return function createVoiceInput(options) {
        var input = options.input;
        var button = options.button;
        var status = options.status || null;
        var statusText = status ? status.querySelector('.mago-voice-text') : null;
        var t = options.t;
        var Recognition = recognitionClass();

        if (!Recognition || !input || !button) {
            return {isSupported: false, stop: function () {}};
        }

        var recognition = null;
        var listening = false;
        var pauseTimer = null;
        var countdownTimer = null;
        var statusTimer = null;
        // Text already in the box when listening started; recognised speech is appended after it
        // so an interrupted sentence can be resumed without losing what was typed before.
        var prefix = '';
        var idleTitle = t('Speak your message');
        var activeTitle = t('Stop listening');

        button.hidden = false;

        function setText(value) {
            input.value = value;
            input.dispatchEvent(new Event('input', {bubbles: true}));
        }

        function joinWithPrefix(spoken) {
            if (!prefix) return spoken;
            return /\s$/.test(prefix) ? prefix + spoken : prefix + ' ' + spoken;
        }

        function showStatus(sentence, modifier) {
            if (!status) return;
            clearTimeout(statusTimer);
            status.hidden = false;
            status.className = 'mago-voice-status' + (modifier ? ' ' + modifier : '');
            if (statusText) statusText.textContent = sentence;
        }

        function hideStatus(afterMs) {
            if (!status) return;
            clearTimeout(statusTimer);
            if (afterMs) {
                statusTimer = setTimeout(function () { status.hidden = true; }, afterMs);
            } else {
                status.hidden = true;
            }
        }

        function setListening(active) {
            listening = active;
            button.classList.toggle('is-listening', active);
            input.classList.toggle('is-listening', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.title = active ? activeTitle : idleTitle;
            if (active) {
                input.dataset.placeholder = input.placeholder;
                input.placeholder = t('Listening…');
                showStatus(t('Recording — speak now'), 'is-recording');
            } else if (typeof input.dataset.placeholder !== 'undefined') {
                input.placeholder = input.dataset.placeholder;
                delete input.dataset.placeholder;
            }
        }

        function clearTimers() {
            clearTimeout(pauseTimer);
            clearInterval(countdownTimer);
            pauseTimer = null;
            countdownTimer = null;
        }

        // Called on every final result: the recogniser only finalises after it hears a pause, so
        // a fresh final result followed by silence is the "stopped talking" signal.
        function scheduleAutoSend() {
            if (!options.autoSend) return;
            clearTimers();
            pauseTimer = setTimeout(startCountdown, PAUSE_MS);
        }

        function startCountdown() {
            var remaining = COUNTDOWN_SECONDS;
            if (!input.value.trim()) return;
            showStatus(t('Sending in %1…', remaining), 'is-sending');
            countdownTimer = setInterval(function () {
                remaining -= 1;
                if (remaining > 0) {
                    showStatus(t('Sending in %1…', remaining), 'is-sending');
                    return;
                }
                clearTimers();
                stop(true);
                if (options.onSend) options.onSend();
            }, 1000);
        }

        // Anything the administrator does with the text themselves takes over from the recogniser.
        function cancelAutoSend() {
            if (!pauseTimer && !countdownTimer) return;
            clearTimers();
            if (listening) showStatus(t('Recording — speak now'), 'is-recording');
        }

        function start() {
            if (input.disabled) return;

            // recognition.stop() resolves asynchronously, so a session stopped and restarted in
            // quick succession has the old instance's events landing after the new one began.
            // Each handler checks it still belongs to the live instance before touching state.
            var rec = recognition = new Recognition();
            recognition.lang = options.lang || navigator.language || 'en-US';
            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.maxAlternatives = 1;

            prefix = input.value.trim();

            recognition.onresult = function (event) {
                if (rec !== recognition) return;
                var finalText = '';
                var interimText = '';
                var gotFinal = false;
                for (var i = 0; i < event.results.length; i++) {
                    var transcript = event.results[i][0].transcript;
                    if (event.results[i].isFinal) finalText += transcript;
                    else interimText += transcript;
                }
                gotFinal = event.results.length > 0 && event.results[event.results.length - 1].isFinal;
                setText(joinWithPrefix((finalText + interimText).trim()));
                if (gotFinal) {
                    scheduleAutoSend();
                } else {
                    // Still talking: hold the countdown back.
                    clearTimers();
                    showStatus(t('Recording — speak now'), 'is-recording');
                }
            };

            recognition.onerror = function (event) {
                if (rec !== recognition) return;
                // "aborted" and "no-speech" are what stopping early or staying silent report;
                // neither is worth a message.
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                    if (options.onError) {
                        options.onError(t('Microphone access was denied. Allow it in the browser to use voice input.'));
                    }
                }
                stop();
            };

            // The browser ends a continuous session on its own after a stretch of silence. The
            // countdown, if one is running, keeps going so the message still goes out.
            recognition.onend = function () {
                if (rec !== recognition) return;
                recognition = null;
                if (listening) {
                    setListening(false);
                    if (!countdownTimer && !pauseTimer) {
                        showStatus(t('Recording stopped'));
                        hideStatus(1500);
                    }
                }
                input.focus();
            };

            try {
                recognition.start();
                setListening(true);
            } catch (e) {
                recognition = null;
                setListening(false);
                hideStatus();
            }
        }

        function stop(silent) {
            var wasActive = listening || !!pauseTimer || !!countdownTimer;
            clearTimers();
            if (recognition) {
                try { recognition.stop(); } catch (e) { /* already stopped */ }
                recognition = null;
            }
            setListening(false);
            if (silent || !wasActive) {
                hideStatus();
            } else {
                showStatus(t('Recording stopped'));
                hideStatus(1500);
            }
        }

        button.addEventListener('click', function () {
            if (listening || pauseTimer || countdownTimer) stop(); else start();
        });

        // Typing means the administrator is correcting the text: drop the countdown and stop the
        // recogniser so it cannot overwrite the edit. Enter hands the text to the normal send path.
        input.addEventListener('keydown', function (e) {
            if (!listening && !pauseTimer && !countdownTimer) return;
            if (e.keyCode === 13 && !e.shiftKey) { stop(true); return; }
            if (e.key && e.key.length === 1 || e.keyCode === 8 || e.keyCode === 46) stop(true);
        });

        return {isSupported: true, stop: stop};
    };
});
