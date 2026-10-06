/*
 * Copyright © Mago Assistant
 */

/**
 * Every sentence the panel puts on a confirmation card, and the lookups behind them.
 *
 * These read the live form rather than the tool input on purpose: showing what is on the form
 * right now is what makes the card a review rather than a replay of a value the model may have
 * seen several turns ago. A value comes from the store or the model, so the card is built as DOM
 * with every value in a text node: whatever markdown or HTML a value holds is shown as it is.
 *
 * The bridge is passed as a getter, not a value: the panel loads form-bridge asynchronously, so a
 * reference captured at construction time would still be null when these run.
 */
define([], function () {
    'use strict';

    return function createConfirmText(getFormBridge, translator, text) {
        var t = translator.t;
        var entityLabel = translator.entityLabel;
        var describeEntity = translator.describeEntity;
        var fieldCountText = translator.fieldCountText;

    // children: strings become text nodes, nodes are appended as they are.
    function element(tagName, children) {
        const node = document.createElement(tagName);
        children.forEach(function (child) {
            node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        });
        return node;
    }

    function valueCode(value) {
        return element('code', [value === null || typeof value === 'undefined' ? '' : String(value)]);
    }

    // The old value is deliberately not part of the tool input (task 006): looking it up here,
    // through form-bridge, shows what is on the form right now rather than replaying a value the
    // model may have seen several turns ago. A path form-bridge cannot find is shown as-is, which
    // is itself informative: it means the target has moved since the model proposed the write.
    // live: a snapshot the caller already took, so a multi-line card looks up once instead of
    // per line. Omitted, it takes its own.
    function findLiveField(path, live) {
        var snapshot = live || liveForm();
        if (!snapshot) return null;
        var match = null;
        (snapshot.fields || []).forEach(function(field) {
            if (field.path === path) match = field;
        });
        return match;
    }

    function liveForm() {
        var bridge = getFormBridge();
        var snapshot = bridge ? bridge.snapshot() : null;
        return snapshot && snapshot.hasForm ? snapshot : null;
    }

    function isNavigatingWrite(input, live) {
        if (!input.entity_type) return false;
        if (!live) return true;
        return input.entity_type !== live.entityType || (input.entity_id || '') !== live.entityId;
    }

    function describeLiveForm(live) {
        var text = describeEntity(live.entityType, live.entityId);
        if (live.storeId) text += ' (' + t('store view %1', live.storeId) + ')';
        return text;
    }

    function formatFieldChangeLine(change, live) {
        var field = findLiveField(change.path, live);
        if (!field) return element('li', [String(change.path), ': ', valueCode(change.value)]);
        var previous = field.redacted ? t('(hidden)') : valueCode(field.value);
        return element('li', [String(field.label), ': ', previous, ' → ', valueCode(change.value)]);
    }

    // The same "Label: old → new" as data, for a card that sets every value with textContent.
    // Labels and values are raw here: escaping is the builder's job.
    function describeFieldChange(change, live) {
        var field = findLiveField(change.path, live);
        if (!field) return {label: String(change.path), isFromUnknown: true, to: change.value};
        return {label: String(field.label), from: field.value, isFromHidden: !!field.redacted, to: change.value};
    }

    function describeWriteFieldChanges(tool) {
        var live = liveForm();
        return ((tool.input || {}).changes || []).map(function(change) { return describeFieldChange(change, live); });
    }

    // The heading says where the values go: the form on screen, another entity, or a New form,
    // in which case the administrator is also told the browser will leave this page, and that
    // unsaved edits here will be lost when the open form has any. Returns the heading's paragraphs.
    function formatWriteFieldsHeading(input, changes, live) {
        if (typeof live === 'undefined') live = liveForm();
        var count = fieldCountText(changes.length);
        var notes;

        if (!isNavigatingWrite(input, live)) {
            return [element('p', [t('Stage %1 on %2:', count, live ? describeLiveForm(live) : t('the form on screen'))])];
        }

        notes = [t('You will leave this page.')];
        var bridge = getFormBridge();
        if (live && bridge && typeof bridge.hasUnsavedChanges === 'function' && bridge.hasUnsavedChanges()) {
            notes.push(t('Unsaved edits on %1 will be lost.', describeLiveForm(live)));
        }

        return [
            element('p', [t('Open %1 and stage %2 there:', describeEntity(input.entity_type, input.entity_id), count)]),
            element('p', [element('strong', [notes.join(' ')])])
        ];
    }

    // Every field is listed: a write the admin approves must not hide any of what it writes, so a
    // long card is the price of a complete one (the panel folds long values, never whole lines).
    function formatWriteFieldsConfirmMessage(tool) {
        var input = tool.input || {};
        var changes = input.changes || [];
        // One snapshot for the whole card; the heading and every line share it.
        var live = liveForm();
        var lines = changes.map(function(change) { return formatFieldChangeLine(change, live); });

        return element('div', formatWriteFieldsHeading(input, changes, live).concat([
            element('ul', lines),
            element('p', [formatWriteFieldsFooter()])
        ]));
    }

    // The sentences above the field list on the panel's form-write card.
    function formatWriteFieldsIntro(tool) {
        var input = tool.input || {};
        return element('div', [element('p', [t('I want to perform the following action:')])]
            .concat(formatWriteFieldsHeading(input, input.changes || [])));
    }

    function formatWriteFieldsFooter() {
        return t('Nothing is saved until you click Save on the page.');
    }

    function formatToolParameters(input) {
        return Object.keys(input).reduce(function(children, k, index) {
            var value = input[k];
            if (value !== null && typeof value === 'object') { value = JSON.stringify(value); }
            return children.concat([index === 0 ? ': ' : ', ', k + ': ', valueCode(value)]);
        }, []);
    }

    function formatToolConfirmMessage(tool) {
        if (tool.name === 'page_form' && tool.input && tool.input.action === 'write_fields') {
            return formatWriteFieldsConfirmMessage(tool);
        }
        return element('p', [element('strong', [String(tool.name)])].concat(formatToolParameters(tool.input || {})));
    }

    function formatConfirmMessage(tools) {
        if (!tools || !tools.length) return element('p', [t('I want to perform an action. Allow this?')]);
        return element('div', [element('p', [t('I want to perform the following action:')])]
            .concat(tools.map(formatToolConfirmMessage))
            .concat([element('p', [t('Allow this?')])]));
    }

    // The model's own reply is generated before the browser has applied anything (Confirm.php
    // streams the follow-up turn as soon as the tool result exists, not after form_apply runs), so
    // it can never know which fields actually took the value. This is the panel's own report,
    // appended as a separate message once the bridge has finished, never a second server round trip.
    // A directive whose target no longer matches the form now open (task 008: the administrator
    // navigated to a different entity, store view or form between proposal and confirmation) is
    // named by what it was meant for, so the administrator understands why nothing happened rather
    // than assuming the assistant silently did nothing.
    function formatTargetDescription(target) {
        var entityType = target && target.entity_type;
        if (!target || !target.entity_id) return t('a new, unsaved %1', entityLabel(entityType));
        return describeEntity(entityType, target.entity_id);
    }

    function formatRefusalMessage(target) {
        return t('That change was meant for %1, but a different form is open now. Nothing was changed. Go back to that page and ask me again.', formatTargetDescription(target));
    }

    function failedLabels(result) {
        return result.failed.map(function (f) { return String(f.label); }).join(', ');
    }

    function formatApplyOutcomeMessage(result) {
        if (result.refused) return formatRefusalMessage(result.target);

        var total = result.applied.length + result.failed.length;
        var text;

        if (!total) return null;
        if (!result.applied.length) return t('Could not stage %1. Nothing was changed.', failedLabels(result));

        text = t('Staged %1 of %2 %3. Not saved yet: click Save on the page to keep %4.',
            result.applied.length, total, fieldCountText(total).replace(/^\d+ /, ''),
            total === 1 ? t('this change') : t('these changes'));

        if (result.failed.length) {
            text += ' ' + t('Could not set: %1.', failedLabels(result));
        }

        return text;
    }

        return {
            findLiveField: findLiveField,
            liveForm: liveForm,
            isNavigatingWrite: isNavigatingWrite,
            describeLiveForm: describeLiveForm,
            formatFieldChangeLine: formatFieldChangeLine,
            describeWriteFieldChanges: describeWriteFieldChanges,
            formatWriteFieldsIntro: formatWriteFieldsIntro,
            formatWriteFieldsFooter: formatWriteFieldsFooter,
            formatWriteFieldsHeading: formatWriteFieldsHeading,
            formatWriteFieldsConfirmMessage: formatWriteFieldsConfirmMessage,
            formatToolConfirmMessage: formatToolConfirmMessage,
            formatConfirmMessage: formatConfirmMessage,
            formatTargetDescription: formatTargetDescription,
            formatRefusalMessage: formatRefusalMessage,
            failedLabels: failedLabels,
            formatApplyOutcomeMessage: formatApplyOutcomeMessage
        };
    };
});
