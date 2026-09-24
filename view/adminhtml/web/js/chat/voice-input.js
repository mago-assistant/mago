/*
 * Copyright © Mago Assistant
 */

/**
 * Microphone button for the chat input, backed by the browser's own speech recognition (Web
 * Speech API). Recognised words land in the textarea while the administrator speaks and stay
 * editable. With auto-send on, a pause in speech starts a short visible countdown and then sends;
 * typing or pressing the microphone during the countdown cancels it, so a misheard sentence can
 * always be corrected first. Hands-free keeps the session alive across turns: after an auto-send
 * it waits for the panel to report the turn over, then listens again.
 *
 * The audio never passes through Mago or the AI provider: the browser vendor handles it. The
 * resulting text is indistinguishable from typed text to the rest of the panel, which is why this
 * module only writes to the textarea and fires its input event.
 */
define([], function () {
    'use strict';

    // Fallback for the configured wait between the last final result and the send. The recogniser
    // already needs a pause before it finalises, so this stays short.
    var DEFAULT_SEND_DELAY_MS = 1400;

    function recognitionClass() {
        return window.SpeechRecognition || window.webkitSpeechRecognition || null;
    }

    /**
     * @param {Object} options
     * @param {HTMLTextAreaElement} options.input
     * @param {HTMLButtonElement} options.button
     * @param {HTMLElement} [options.status] status line under the input
     * @param {HTMLElement} [options.notice] banner shown while a session is active
     * @param {string} options.lang BCP-47 tag for the recogniser
     * @param {boolean} options.autoSend
     * @param {number} [options.sendDelay] milliseconds after the last final result before sending
     * @param {boolean} [options.handsFree] keep listening after an auto-send once the turn ended
     * @param {Function} options.t translator
     * @param {Function} [options.onSend] called when the countdown finishes
     * @param {Function} [options.requireConsent] called with a continuation on the first use;
     *        the session only starts when the continuation is invoked
     * @param {Function} [options.onError] receives a sentence to show the administrator
     * @returns {{isSupported: boolean, stop: Function, sending: Function, turnEnded: Function}}
     */
    return function createVoiceInput(options) {
        var input = options.input;
        var button = options.button;
        var status = options.status || null;
        var statusText = status ? status.querySelector('.mago-voice-text') : null;
        var notice = options.notice || null;
        var t = options.t;
        var Recognition = recognitionClass();

        if (!Recognition || !input || !button) {
            return {isSupported: false, stop: function () {}, sending: function () {}, turnEnded: function () {}};
        }

        var recognition = null;
        var listening = false;
        var sendDelay = parseInt(options.sendDelay, 10);
        if (!(sendDelay >= 0)) sendDelay = DEFAULT_SEND_DELAY_MS;
        // Hands-free: set when the administrator starts a session and cleared by anything they do
        // to end it. waitingForTurn bridges the gap between an auto-send and the reply.
        var handsFree = false;
        var waitingForTurn = false;
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

        function showNotice(visible) {
            if (notice) notice.hidden = !visible;
        }

        function setListening(active) {
            listening = active;
            if (active) showNotice(true);
            button.classList.toggle('is-listening', active);
            input.classList.toggle('is-listening', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.title = active ? activeTitle : idleTitle;
            if (active) {
                input.dataset.placeholder = input.placeholder;
                input.placeholder = t('Listening…');
                showStatus(recordingText(), 'is-recording');
            } else if (typeof input.dataset.placeholder !== 'undefined') {
                input.placeholder = input.dataset.placeholder;
                delete input.dataset.placeholder;
            }
        }

        function recordingText() {
            return handsFree ? t('Hands-free — speak, a pause sends') : t('Recording — speak now');
        }

        function clearTimers() {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }

        function countdownText(remainingMs) {
            return remainingMs >= 1000 ? t('Sending in %1…', Math.ceil(remainingMs / 1000)) : t('Sending…');
        }

        // Called on every final result: the recogniser only finalises after it hears a pause, so
        // a fresh final result followed by silence is the "stopped talking" signal.
        function scheduleAutoSend() {
            if (!options.autoSend) return;
            clearTimers();
            if (!input.value.trim()) return;
            var due = Date.now() + sendDelay;
            showStatus(countdownText(sendDelay), 'is-sending');
            // One interval does both the visible countdown and the send, so they cannot drift apart.
            countdownTimer = setInterval(function () {
                var remaining = due - Date.now();
                if (remaining > 0) {
                    showStatus(countdownText(remaining), 'is-sending');
                    return;
                }
                clearTimers();
                if (handsFree) {
                    // Keep the session flagged so the panel's turn-end resumes it.
                    waitingForTurn = true;
                    stopRecogniser();
                    setListening(false);
                    showStatus(t('Waiting for the reply…'));
                } else {
                    stop(true);
                }
                if (options.onSend) options.onSend();
            }, Math.min(250, Math.max(50, sendDelay || 50)));
        }

        // Anything the administrator does with the text themselves takes over from the recogniser.
        function cancelAutoSend() {
            if (!countdownTimer) return;
            clearTimers();
            if (listening) showStatus(recordingText(), 'is-recording');
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
                    showStatus(recordingText(), 'is-recording');
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
                    stop();
                    return;
                }
                // Anything else (network hiccup, no speech for a while) is recoverable: hands-free
                // starts a fresh session, a single session just ends.
                if (handsFree && listening && !countdownTimer) {
                    recognition = null;
                    start();
                    return;
                }
                stop();
            };

            // The browser ends a continuous session on its own after a stretch of silence. The
            // countdown, if one is running, keeps going so the message still goes out.
            recognition.onend = function () {
                if (rec !== recognition) return;
                recognition = null;
                // Chrome ends a continuous session on its own after a stretch of silence; in
                // hands-free that is not the administrator's decision, so listen again.
                if (handsFree && listening && !countdownTimer) {
                    start();
                    return;
                }
                if (listening) {
                    setListening(false);
                    if (!countdownTimer) {
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

        function stopRecogniser() {
            if (recognition) {
                try { recognition.stop(); } catch (e) { /* already stopped */ }
                recognition = null;
            }
        }

        function stop(silent) {
            var wasActive = listening || waitingForTurn || !!countdownTimer;
            clearTimers();
            handsFree = false;
            waitingForTurn = false;
            stopRecogniser();
            setListening(false);
            showNotice(false);
            if (silent || !wasActive) {
                hideStatus();
            } else {
                showStatus(t('Recording stopped'));
                hideStatus(1500);
            }
        }

        // The panel calls this when a turn ends; only a hands-free session that sent something
        // and is waiting for the reply takes it as the cue to listen again.
        // The panel's send path calls this: a manual Send ends the session, an auto-send in
        // hands-free has already parked the recogniser and must keep its state.
        function sending() {
            if (waitingForTurn) return;
            stop(true);
        }

        function turnEnded() {
            if (!handsFree || !waitingForTurn) return;
            waitingForTurn = false;
            start();
        }

        function beginSession() {
            handsFree = !!options.handsFree && !!options.autoSend;
            start();
        }

        button.addEventListener('click', function () {
            if (listening || waitingForTurn || countdownTimer) {
                stop();
            } else if (options.requireConsent) {
                // The audio leaves the browser for its vendor's speech service, which Mago does
                // not control; the administrator confirms they know that before the first session.
                options.requireConsent(beginSession);
            } else {
                beginSession();
            }
        });

        // Typing means the administrator is correcting the text: drop the countdown and stop the
        // recogniser so it cannot overwrite the edit. Enter hands the text to the normal send path.
        input.addEventListener('keydown', function (e) {
            if (!listening && !waitingForTurn && !countdownTimer) return;
            if (e.keyCode === 13 && !e.shiftKey) { stop(true); return; }
            if (e.key && e.key.length === 1 || e.keyCode === 8 || e.keyCode === 46) stop(true);
        });

        return {isSupported: true, stop: stop, sending: sending, turnEnded: turnEnded};
    };
});
