/*
 * Copyright © Mago Assistant
 */

/**
 * Microphone button for the chat input, backed by the browser's own speech recognition (Web
 * Speech API). Recognised words land in the textarea while the administrator speaks and stay
 * editable. Three modes decide what happens once they stop talking:
 *
 * - dictate: nothing; they review the text and press Send.
 * - auto_send: a short visible countdown, then the message is sent and the microphone turns off.
 *   Typing or pressing the microphone during the countdown cancels it.
 * - conversation: a Send / Discard / Continue bar appears, answered by clicking it or by saying
 *   "send", "discard" or "continue"; continue goes back to dictating more of the same message.
 *   Anything else said while the bar is up is ignored. After the send the microphone is paused
 *   until the panel reports the turn over, then it listens for the next message. While an Allow /
 *   Reject card waits, speech only answers that card.
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
    // Conversation: quiet after a final result before the Send bar appears, so a breath in the
    // middle of a sentence does not flash it.
    var PROMPT_DELAY_MS = 700;
    var MAX_RESTARTS = 5;
    var MODES = ['dictate', 'auto_send', 'conversation'];

    function recognitionClass() {
        return window.SpeechRecognition || window.webkitSpeechRecognition || null;
    }

    /**
     * @param {Object} options
     * @param {HTMLTextAreaElement} options.input
     * @param {HTMLButtonElement} options.button
     * @param {HTMLElement} [options.status] status line under the input
     * @param {HTMLElement} [options.notice] banner shown while a session is active
     * @param {HTMLElement} [options.confirmBar] bar for conversation mode, holding buttons marked
     *        data-voice="send", "discard" and "continue"
     * @param {string} options.lang BCP-47 tag for the recogniser
     * @param {string} options.mode dictate, auto_send or conversation
     * @param {number} [options.sendDelay] auto_send: milliseconds after the last final result before sending
     * @param {Function} [options.matchCommand] conversation: maps a short utterance to "send",
     *        "discard", "continue" or null
     * @param {Function} [options.cardPending] conversation: true while an Allow / Reject card
     *        waits; speech then answers the card instead of filling the input
     * @param {Function} [options.answerCard] conversation: answers the card from an utterance and
     *        returns "answered", "locked" (acknowledgement box not ticked yet) or null
     * @param {Function} options.t translator
     * @param {Function} [options.onSend] called when a spoken message should be sent
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
        var bar = options.confirmBar || null;
        var t = options.t;
        var mode = MODES.indexOf(options.mode) !== -1 ? options.mode : 'dictate';
        var Recognition = recognitionClass();

        if (!Recognition || !input || !button) {
            return {isSupported: false, stop: function () {}, sending: function () {}, turnEnded: function () {}};
        }

        var recognition = null;
        var listening = false;
        var sendDelay = parseInt(options.sendDelay, 10);
        if (!(sendDelay >= 0)) sendDelay = DEFAULT_SEND_DELAY_MS;
        // Conversation: set when the administrator starts a session and cleared by anything they do
        // to end it. waitingForTurn bridges the gap between a send and the reply.
        var inConversation = false;
        var waitingForTurn = false;
        var countdownTimer = null;
        var promptTimer = null;
        var statusTimer = null;
        var restarts = 0;
        // Text already in the box when listening started; recognised speech is appended after it
        // so an interrupted sentence can be resumed without losing what was typed before.
        var prefix = '';
        // Result indexes within the live recogniser's session: results before baseIndex were
        // discarded, results from pendingFrom on arrived while the Send bar was up and are either
        // a command or more of the message. pendingFrom is null while the bar is down.
        var resultCount = 0;
        var baseIndex = 0;
        var pendingFrom = null;
        var idleTitle = t('Speak your message');
        var activeTitle = t('Stop listening');

        button.hidden = false;

        function setText(value) {
            input.value = value;
            input.dispatchEvent(new Event('input', {bubbles: true}));
        }

        function joinWithPrefix(spoken) {
            if (!prefix) return spoken;
            if (!spoken) return prefix;
            return /\s$/.test(prefix) ? prefix + spoken : prefix + ' ' + spoken;
        }

        function collect(results, from, to) {
            var text = '';
            for (var i = from; i < to; i++) text += results[i][0].transcript;
            return {text: text.trim(), isFinal: to > from && results[to - 1].isFinal};
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

        function showBar(visible) {
            if (bar) bar.hidden = !visible;
        }

        function isPrompting() {
            return pendingFrom !== null;
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

        function cardPending() {
            return inConversation && !!options.cardPending && options.cardPending();
        }

        function recordingText() {
            if (cardPending()) return t('Say “allow” or “reject” to answer the card');
            return inConversation ? t('Your turn — speak now') : t('Recording — speak now');
        }

        function clearTimers() {
            clearInterval(countdownTimer);
            countdownTimer = null;
            clearTimeout(promptTimer);
            promptTimer = null;
        }

        function countdownText(remainingMs) {
            return remainingMs >= 1000 ? t('Sending in %1…', Math.ceil(remainingMs / 1000)) : t('Sending…');
        }

        // auto_send, called on every final result: the recogniser only finalises after it hears a
        // pause, so a fresh final result followed by silence is the "stopped talking" signal.
        function scheduleAutoSend() {
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
                stop(true);
                if (options.onSend) options.onSend();
            }, Math.min(250, Math.max(50, sendDelay || 50)));
        }

        // Conversation: after a pause, ask before sending.
        function schedulePrompt() {
            clearTimers();
            if (!input.value.trim()) return;
            promptTimer = setTimeout(function () {
                promptTimer = null;
                pendingFrom = resultCount;
                showBar(true);
                showStatus(t('Say “send”, “discard” or “continue”'), 'is-recording');
            }, PROMPT_DELAY_MS);
        }

        // Conversation: the microphone is off while the assistant answers, so the reply being
        // read aloud or the administrator thinking out loud cannot end up in the next message.
        function park() {
            clearTimers();
            showBar(false);
            pendingFrom = null;
            waitingForTurn = true;
            stopRecogniser();
            setListening(false);
            showStatus(t('Assistant is answering — microphone paused'));
        }

        function commitSend() {
            if (!input.value.trim()) return;
            park();
            if (options.onSend) options.onSend();
        }

        function discard() {
            clearTimers();
            showBar(false);
            pendingFrom = null;
            baseIndex = resultCount;
            prefix = '';
            setText('');
            if (listening) showStatus(recordingText(), 'is-recording');
        }

        // Back to dictating: what is in the box stays and new speech is added after it.
        function resume() {
            clearTimers();
            showBar(false);
            pendingFrom = null;
            baseIndex = resultCount;
            prefix = input.value.trim();
            if (listening) showStatus(t('Keep talking — adding to your message'), 'is-recording');
        }

        // Speech that arrives while the bar is up never reaches the textarea: it is a command, or
        // it is skipped with a nudge, so a misheard "send" cannot quietly change the message.
        function handleWhilePrompting(results) {
            var tail = collect(results, pendingFrom, results.length);
            if (!tail.text || !tail.isFinal) return;
            var command = options.matchCommand ? options.matchCommand(tail.text) : null;
            if (command === 'send') {
                commitSend();
            } else if (command === 'discard') {
                discard();
            } else if (command === 'continue') {
                resume();
            } else {
                pendingFrom = results.length;
                showStatus(t('Not understood: “%1”. Say “send”, “discard” or “continue”', tail.text), 'is-recording');
            }
        }

        // Speech said while a card waits is spent on the card, never written to the input. An
        // answered card starts (allow) or ends (reject) a turn, and either ends in turnEnded().
        function handleCardAnswer(results) {
            var said = collect(results, baseIndex, results.length);
            if (!said.text || !said.isFinal) return;
            baseIndex = results.length;
            var outcome = options.answerCard ? options.answerCard(said.text) : null;
            if (outcome === 'answered') {
                park();
            } else if (outcome === 'locked') {
                showStatus(t('Tick the box on the card first, then say “allow”'), 'is-recording');
            } else {
                showStatus(t('Not understood: “%1”. Say “allow” or “reject”', said.text), 'is-recording');
            }
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

            // A fresh recogniser numbers its results from zero again; what was said to the old one
            // is already in the textarea and becomes the prefix.
            prefix = input.value.trim();
            resultCount = 0;
            baseIndex = 0;
            if (isPrompting()) pendingFrom = 0;

            recognition.onresult = function (event) {
                if (rec !== recognition) return;
                resultCount = event.results.length;
                restarts = 0;
                if (isPrompting()) {
                    handleWhilePrompting(event.results);
                    return;
                }
                if (cardPending()) {
                    handleCardAnswer(event.results);
                    return;
                }
                var spoken = collect(event.results, baseIndex, resultCount);
                setText(joinWithPrefix(spoken.text));
                if (!spoken.isFinal) {
                    clearTimers();
                    showStatus(recordingText(), 'is-recording');
                } else if (mode === 'auto_send') {
                    scheduleAutoSend();
                } else if (mode === 'conversation') {
                    schedulePrompt();
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
                if (inConversation && listening) {
                    recognition = null;
                    restart();
                    return;
                }
                stop();
            };

            // Chrome ends a continuous session on its own after a stretch of silence; in a
            // conversation that is not the administrator's decision, so listen again. A running
            // auto_send countdown is left alone so the message still goes out.
            recognition.onend = function () {
                if (rec !== recognition) return;
                recognition = null;
                if (inConversation && listening) {
                    restart();
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

        // Conversation restarts are capped so a browser that keeps failing to open the recogniser
        // (no network, no device) cannot loop; a result resets the count.
        function restart() {
            if (++restarts > MAX_RESTARTS) {
                stop();
                return;
            }
            setTimeout(function () {
                if (inConversation && listening && !recognition) start();
            }, 250);
        }

        function stopRecogniser() {
            if (recognition) {
                try { recognition.stop(); } catch (e) { /* already stopped */ }
                recognition = null;
            }
        }

        function stop(silent) {
            var wasActive = listening || waitingForTurn || !!countdownTimer || isPrompting();
            clearTimers();
            inConversation = false;
            waitingForTurn = false;
            pendingFrom = null;
            restarts = 0;
            stopRecogniser();
            setListening(false);
            showBar(false);
            showNotice(false);
            if (silent || !wasActive) {
                hideStatus();
            } else {
                showStatus(t('Recording stopped'));
                hideStatus(1500);
            }
        }

        // The panel calls this on every send. In a conversation any send (the bar, Enter or the
        // Send button) pauses the microphone until the reply; otherwise it ends the session.
        function sending() {
            if (waitingForTurn) return;
            if (inConversation && listening) {
                park();
                return;
            }
            stop(true);
        }

        function turnEnded() {
            if (!inConversation || !waitingForTurn) return;
            waitingForTurn = false;
            start();
        }

        function beginSession() {
            inConversation = mode === 'conversation';
            start();
        }

        button.addEventListener('click', function () {
            if (listening || waitingForTurn || countdownTimer) {
                stop();
            } else if (options.requireConsent) {
                options.requireConsent(beginSession);
            } else {
                beginSession();
            }
        });

        if (bar) {
            bar.addEventListener('click', function (e) {
                var action = e.target.closest('[data-voice]');
                if (!action) return;
                if (action.dataset.voice === 'send') commitSend();
                else if (action.dataset.voice === 'discard') discard();
                else if (action.dataset.voice === 'continue') resume();
            });
        }

        // Typing means the administrator is correcting the text: drop the countdown and stop the
        // recogniser so it cannot overwrite the edit. Enter hands the text to the normal send
        // path, which in a conversation keeps the session going.
        input.addEventListener('keydown', function (e) {
            if (!listening && !waitingForTurn && !countdownTimer) return;
            if (e.keyCode === 13 && !e.shiftKey) {
                if (!inConversation) stop(true);
                return;
            }
            if (e.key && e.key.length === 1 || e.keyCode === 8 || e.keyCode === 46) stop(true);
        });

        return {isSupported: true, stop: stop, sending: sending, turnEnded: turnEnded};
    };
});
