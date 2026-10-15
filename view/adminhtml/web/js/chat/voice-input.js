/*
 * Copyright © Mago Assistant
 */

/**
 * Microphone button for the chat input, backed by the browser's own speech recognition (Web
 * Speech API). Recognised words land in the textarea while the administrator speaks and stay
 * editable. Three modes decide what happens once they stop talking:
 *
 * - dictate: nothing; they review the text and press Send. One recording per press.
 * - auto_send: a bar counts down to sending the message; silence lets it send.
 * - confirm: a bar asks "Send this message?" and waits.
 *
 * In both bar modes a "yes" word (or Send) sends at once, a "no" word (or Discard) clears the
 * message, and talking on adds to it. The recogniser starts afresh when the bar appears, so the
 * command is heard on its own instead of as the tail of the sentence before it. auto_send and
 * confirm are hands-free: after a send the microphone is paused until the panel reports the turn
 * over, then it listens for the next message. While an Allow / Reject card waits, speech only
 * answers that card, and suggestions in the latest answer can be picked by number or text.
 *
 * A stop word said on its own turns the microphone off in either mode.
 *
 * The audio never passes through Mago or the AI provider: the browser vendor handles it. The
 * resulting text is indistinguishable from typed text to the rest of the panel, which is why this
 * module only writes to the textarea and fires its input event.
 */
define([], function () {
    'use strict';

    // Fallback for the configured countdown between the end of speech and the send.
    var DEFAULT_SEND_DELAY_MS = 1400;
    var MAX_RESTARTS = 5;
    // A recogniser that listened at least this long without an error ended normally (Chrome closes
    // a session after a stretch of silence), so its restart does not count as a failure.
    var HEALTHY_SESSION_MS = 3000;
    var MODES = ['dictate', 'auto_send', 'confirm'];
    // Earlier names of the two hands-free modes, still found in stored settings.
    var LEGACY_MODES = {conversation: 'confirm', hands_free: 'auto_send'};

    function recognitionClass() {
        return window.SpeechRecognition || window.webkitSpeechRecognition || null;
    }

    function normalizeMode(value) {
        if (LEGACY_MODES.hasOwnProperty(value)) return LEGACY_MODES[value];
        return MODES.indexOf(value) !== -1 ? value : null;
    }

    /**
     * @param {Object} options
     * @param {HTMLTextAreaElement} options.input
     * @param {HTMLButtonElement} options.button
     * @param {HTMLElement} [options.status] status line under the input
     * @param {HTMLElement} [options.notice] banner shown while a session is active
     * @param {HTMLElement} [options.confirmBar] bar shown after a pause, holding a
     *        .mago-voice-confirm-title, a .mago-voice-confirm-progress and buttons marked
     *        data-voice="send" and data-voice="discard"
     * @param {string} options.lang BCP-47 tag for the recogniser
     * @param {string} options.mode dictate, auto_send or confirm
     * @param {number} [options.sendDelay] auto_send: milliseconds the countdown runs before a send
     * @param {Function} [options.matchCommand] maps a short utterance to "yes", "no" or null
     * @param {Function} [options.isStop] true when an utterance is only a stop word
     * @param {Object} [options.hintWords] first configured word per command, for the status hints
     * @param {Function} [options.pickSuggestion] picks a suggestion from an utterance (its number
     *        or text) and returns true when it did
     * @param {Function} [options.onListening] called with true / false as the hands-free
     *        microphone opens and closes, so the panel can show which suggestions can be picked
     * @param {Function} [options.cardPending] true while an Allow / Reject card waits
     * @param {Function} [options.answerCard] answers the card from an utterance and returns
     *        "answered", "locked" (acknowledgement box not ticked yet) or null
     * @param {Function} options.t translator
     * @param {Function} [options.onSend] called when a spoken message should be sent
     * @param {Function} [options.requireConsent] called with a continuation on the first use;
     *        the session only starts when the continuation is invoked
     * @param {Function} [options.onError] receives a sentence to show the administrator
     * @returns {{isSupported: boolean, stop: Function, sending: Function, turnEnded: Function,
     *     setMode: Function, getMode: Function}}
     */
    return function createVoiceInput(options) {
        var input = options.input;
        var button = options.button;
        var status = options.status || null;
        var statusText = status ? status.querySelector('.mago-voice-text') : null;
        var notice = options.notice || null;
        var bar = options.confirmBar || null;
        var barTitle = bar ? bar.querySelector('.mago-voice-confirm-title') : null;
        var barProgress = bar ? bar.querySelector('.mago-voice-confirm-progress span') : null;
        var t = options.t;
        var mode = normalizeMode(options.mode) || 'dictate';
        var hint = options.hintWords || {};
        if (!hint.yes) hint.yes = 'yes';
        if (!hint.no) hint.no = 'no';
        var Recognition = recognitionClass();

        if (!Recognition || !input || !button) {
            var noop = function () {};
            return {isSupported: false, stop: noop, sending: noop, turnEnded: noop, setMode: noop, getMode: function () { return null; }};
        }

        var recognition = null;
        var listening = false;
        var sendDelay = parseInt(options.sendDelay, 10);
        if (!(sendDelay >= 0)) sendDelay = DEFAULT_SEND_DELAY_MS;
        // Hands-free: set when the administrator starts a session and cleared by anything they do
        // to end it. waitingForTurn bridges a send and the reply.
        var handsFree = false;
        var waitingForTurn = false;
        var countdownTimer = null;
        var statusTimer = null;
        var restarts = 0;
        // Text already in the box when listening started; recognised speech is appended after it
        // so an interrupted sentence can be resumed without losing what was typed before.
        var prefix = '';
        // Result indexes within the live recogniser's session: results before baseIndex were
        // discarded, results from pendingFrom on arrived while the bar was up and are either a
        // command or more of the message. pendingFrom is null while the bar is down.
        var resultCount = 0;
        var baseIndex = 0;
        var pendingFrom = null;
        // Per recogniser: whether it produced any speech, and the last error Chrome reported, so
        // an early end can still send what was heard or say why it stopped.
        var spokeSinceStart = false;
        var lastError = '';
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
            // Chrome starts each later result with a space, but not every recogniser does.
            for (var i = from; i < to; i++) {
                var part = results[i][0].transcript;
                text += text && !/\s$/.test(text) && !/^\s/.test(part) ? ' ' + part : part;
            }
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

        // The bar's progress line fills over the countdown, restarted from empty each time; with
        // no countdown (confirm) it stays empty.
        function runProgress(durationMs) {
            if (!barProgress) return;
            barProgress.style.transition = 'none';
            barProgress.style.width = '0';
            if (!durationMs) return;
            void barProgress.offsetWidth;
            barProgress.style.transition = 'width ' + durationMs + 'ms linear';
            barProgress.style.width = '100%';
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
            if (options.onListening) options.onListening(active && handsFree);
            if (active) {
                if (typeof input.dataset.placeholder === 'undefined') input.dataset.placeholder = input.placeholder;
                input.placeholder = t('Listening…');
                showStatus(recordingText(), 'is-recording');
            } else if (typeof input.dataset.placeholder !== 'undefined') {
                input.placeholder = input.dataset.placeholder;
                delete input.dataset.placeholder;
            }
        }

        function cardPending() {
            return handsFree && !!options.cardPending && options.cardPending();
        }

        function recordingText() {
            if (cardPending()) return t('Say “%1” or “%2” to answer the card', hint.yes, hint.no);
            return handsFree ? t('Your turn — speak now') : t('Recording — speak now');
        }

        function clearTimers() {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }

        function countdownText(remainingMs) {
            return remainingMs >= 1000 ? t('Sending in %1…', Math.ceil(remainingMs / 1000)) : t('Sending…');
        }

        // Hands-free, on every final result: the recogniser only finalises after it hears a pause,
        // so a fresh final result is the "stopped talking" signal that brings up the bar.
        function showPrompt() {
            clearTimers();
            if (!input.value.trim()) return;
            if (mode === 'auto_send' && sendDelay === 0) {
                commitSend();
                return;
            }
            pendingFrom = 0;
            freshRecogniser();
            showBar(true);
            if (mode === 'confirm') {
                if (barTitle) barTitle.textContent = t('Send this message?');
                runProgress(0);
                showStatus(t('Say “%1” to send, “%2” to clear, or keep talking to add more', hint.yes, hint.no), 'is-sending');
                return;
            }
            var due = Date.now() + sendDelay;
            runProgress(sendDelay);
            showStatus(t('Say “%1” to send now, “%2” to clear, or keep talking to add more', hint.yes, hint.no), 'is-sending');
            // One interval does both the visible countdown and the send, so they cannot drift apart.
            var tick = function () {
                var remaining = due - Date.now();
                if (remaining <= 0) {
                    commitSend();
                    return;
                }
                if (barTitle) barTitle.textContent = countdownText(remaining);
            };
            tick();
            countdownTimer = setInterval(tick, 200);
        }

        // What the recogniser heard so far is already in the textarea; a new one hears the next
        // utterance (a command, or more of the message) without the earlier sentence around it.
        function freshRecogniser() {
            if (!listening) return;
            stopRecogniser();
            start();
        }

        // Hands-free: the microphone is off while the assistant answers, so the reply being
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
            clearTimers();
            if (!input.value.trim()) {
                showBar(false);
                pendingFrom = null;
                return;
            }
            park();
            if (options.onSend) options.onSend();
        }

        // Everything said so far is dropped, the recogniser's own memory of it included.
        function discard() {
            clearTimers();
            showBar(false);
            pendingFrom = null;
            setText('');
            freshRecogniser();
        }

        // Speech while the bar is up stays out of the textarea until it is final, so a spoken
        // "yes" or "no" is never typed into the message it answers. Talking on holds an auto_send
        // countdown; once final, anything that is not a command joins the message and the bar
        // comes back for it.
        function handleWhilePrompting(results) {
            var tail = collect(results, pendingFrom, results.length);
            if (!tail.text) return;
            if (!tail.isFinal) {
                if (!bar || !bar.hidden) {
                    clearTimers();
                    showBar(false);
                    showStatus(t('Keep talking — adding to your message'), 'is-recording');
                }
                return;
            }
            var command = options.matchCommand ? options.matchCommand(tail.text) : null;
            if (command === 'yes') {
                commitSend();
            } else if (command === 'no') {
                discard();
            } else {
                pendingFrom = null;
                showBar(false);
                setText(joinWithPrefix(collect(results, baseIndex, results.length).text));
                showPrompt();
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
                showStatus(t('Tick the box on the card first, then say “%1”', hint.yes), 'is-recording');
            } else {
                showStatus(t('Not understood: “%1”. Say “%2” or “%3”', said.text, hint.yes, hint.no), 'is-recording');
            }
        }

        function start() {
            if (input.disabled) return;

            // recognition.stop() resolves asynchronously, so a session stopped and restarted in
            // quick succession has the old instance's events landing after the new one began.
            // Each handler checks it still belongs to the live instance before touching state.
            var rec = recognition = new Recognition();
            var startedAt = Date.now();
            recognition.lang = options.lang || navigator.language || 'en-US';
            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.maxAlternatives = 1;

            // A fresh recogniser numbers its results from zero again; what was said to the old one
            // is already in the textarea and becomes the prefix.
            prefix = input.value.trim();
            resultCount = 0;
            baseIndex = 0;
            spokeSinceStart = false;
            lastError = '';
            if (isPrompting()) pendingFrom = 0;

            recognition.onresult = function (event) {
                if (rec !== recognition) return;
                resultCount = event.results.length;
                restarts = 0;
                // A stop word said on its own ends the session from any state, and is dropped
                // from the message rather than typed into it.
                var last = event.results[resultCount - 1];
                if (last && last.isFinal && resultCount > baseIndex && options.isStop && options.isStop(last[0].transcript)) {
                    if (!isPrompting() && !cardPending()) {
                        setText(joinWithPrefix(collect(event.results, baseIndex, resultCount - 1).text));
                    }
                    stop();
                    return;
                }
                if (isPrompting()) {
                    handleWhilePrompting(event.results);
                    return;
                }
                if (cardPending()) {
                    handleCardAnswer(event.results);
                    return;
                }
                var spoken = collect(event.results, baseIndex, resultCount);
                if (spoken.text) spokeSinceStart = true;
                // A picked suggestion is sent by the panel, whose send parks the microphone.
                if (spoken.isFinal && handsFree && options.pickSuggestion && options.pickSuggestion(spoken.text)) {
                    baseIndex = resultCount;
                    return;
                }
                setText(joinWithPrefix(spoken.text));
                if (!spoken.isFinal) {
                    showStatus(recordingText(), 'is-recording');
                } else if (handsFree) {
                    showPrompt();
                }
            };

            recognition.onerror = function (event) {
                if (rec !== recognition) return;
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                    if (options.onError) {
                        options.onError(t('Microphone access was denied. Allow it in the browser to use voice input.'));
                    }
                    stop();
                    return;
                }
                // "aborted" and "no-speech" are what stopping early or staying silent report;
                // neither is worth a message. Anything else ("network", "audio-capture") is shown
                // once the session has ended.
                if (event.error !== 'aborted' && event.error !== 'no-speech') lastError = event.error;
                if (handsFree && listening) {
                    recognition = null;
                    restart(startedAt);
                }
                // In dictate mode the end event that always follows an error wraps up.
            };

            // Chrome ends a continuous session on its own after a stretch of silence, or early on
            // a network error; hands-free, that is not the administrator's decision, so listen
            // again. Speech the recogniser never finalised still gets its countdown, and a running
            // countdown is left alone so the message still goes out.
            recognition.onend = function () {
                if (rec !== recognition) return;
                recognition = null;
                if (handsFree && listening) {
                    if (!isPrompting() && !cardPending() && spokeSinceStart && input.value.trim()) showPrompt();
                    if (!recognition) restart(startedAt);
                    return;
                }
                if (listening) {
                    setListening(false);
                    showNotice(false);
                    showStopped();
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

        // Hands-free restarts are capped so a browser that keeps failing to open the recogniser
        // (no network, no device) cannot loop. Only failures count: a result, or a session that
        // listened normally until Chrome closed it for silence, resets the count, so a microphone
        // left open for a long time keeps going.
        function restart(startedAt) {
            if (!lastError && startedAt && Date.now() - startedAt >= HEALTHY_SESSION_MS) restarts = 0;
            if (++restarts > MAX_RESTARTS) {
                stop();
                return;
            }
            setTimeout(function () {
                if (handsFree && listening && !recognition) start();
            }, 250);
        }

        function showStopped() {
            if (lastError) {
                showStatus(t('Speech recognition stopped (%1). Press the microphone to try again.', lastError), 'is-error');
                hideStatus(6000);
            } else {
                showStatus(t('Recording stopped'));
                hideStatus(1500);
            }
        }

        function stopRecogniser() {
            if (recognition) {
                try { recognition.stop(); } catch (e) { /* already stopped */ }
                recognition = null;
            }
        }

        function stop(silent) {
            var wasActive = listening || waitingForTurn || isPrompting();
            clearTimers();
            handsFree = false;
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
                showStopped();
            }
        }

        // The panel calls this on every send. Hands-free, any send (the bar, Enter or the Send
        // button) pauses the microphone until the reply; otherwise it ends the session.
        function sending() {
            if (waitingForTurn) return;
            if (handsFree && listening) {
                park();
                return;
            }
            stop(true);
        }

        function turnEnded() {
            if (!handsFree || !waitingForTurn) return;
            waitingForTurn = false;
            start();
        }

        // Switching mode ends a running session: its timers, bar and parked state belong to the old one.
        function setMode(next) {
            next = normalizeMode(next);
            if (!next || next === mode) return;
            stop(true);
            mode = next;
        }

        function beginSession() {
            handsFree = mode !== 'dictate';
            start();
        }

        button.addEventListener('click', function () {
            if (listening || waitingForTurn || isPrompting()) {
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
            });
        }

        // Typing means the administrator is correcting the text: drop the countdown and stop the
        // recogniser so it cannot overwrite the edit. Enter hands the text to the normal send
        // path, which hands-free keeps the session going.
        input.addEventListener('keydown', function (e) {
            if (!listening && !waitingForTurn && !isPrompting()) return;
            if (e.keyCode === 13 && !e.shiftKey) {
                if (!handsFree) stop(true);
                return;
            }
            if (e.key && e.key.length === 1 || e.keyCode === 8 || e.keyCode === 46) stop(true);
        });

        return {
            isSupported: true,
            stop: stop,
            sending: sending,
            turnEnded: turnEnded,
            setMode: setMode,
            getMode: function () { return mode; }
        };
    };
});
