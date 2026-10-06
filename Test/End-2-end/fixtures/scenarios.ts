/*
 * Copyright © Mago Assistant
 */

import {type ChatScenario, textDeltas} from 'Actions/backend/ChatMock';

const CONVERSATION_ID = 4242;
const MESSAGE_ID = 100501;

export const createCmsPage: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('Sure, I will create that CMS page for you. '),
        {
            event: 'tool_call',
            data: {
                id: 'toolu_cms_1',
                name: 'cms_data',
                input: {
                    action: 'create_page',
                    identifier: 'summer-sale',
                    title: 'Summer Sale',
                    content: '<p>Everything must go.</p>',
                    is_active: true,
                },
            },
        },
        {
            event: 'confirm',
            data: {
                tools: [
                    {
                        name: 'cms_data',
                        description: 'Manage CMS pages and blocks',
                        input: { action: 'create_page', identifier: 'summer-sale', title: 'Summer Sale' },
                    },
                ],
            },
        },
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true },
        },
    ],
    status: { message_id: MESSAGE_ID },
    confirm: [
        {
            event: 'tool_call',
            data: { id: 'toolu_cms_1', name: 'cms_data', input: { action: 'create_page' } },
        },
        ...textDeltas('Done. The page "Summer Sale" is live at /summer-sale.'),
        { event: 'done', data: { conversation_id: CONVERSATION_ID } },
    ],
}

export const createCoupon: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('I can set up a cart price rule with that coupon code. '),
        {
            event: 'tool_call',
            data: {
                id: 'toolu_coupon_1',
                name: 'coupon_manager',
                input: {
                    action: 'create_rule',
                    name: 'Summer 20',
                    discount_type: 'percent',
                    discount_amount: 20,
                    coupon_code: 'SUMMER20',
                },
            },
        },
        {
            event: 'confirm',
            data: {
                tools: [
                    {
                        name: 'coupon_manager',
                        description: 'Manage cart price rules and coupon codes',
                        input: {
                            action: 'create_rule',
                            name: 'Summer 20',
                            discount_type: 'percent',
                            discount_amount: 20,
                            coupon_code: 'SUMMER20',
                        },
                    },
                ],
            },
        },
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true },
        },
    ],
    status: { message_id: MESSAGE_ID },
    confirm: [
        ...textDeltas('Created cart price rule "Summer 20" with coupon code SUMMER20.'),
        { event: 'done', data: { conversation_id: CONVERSATION_ID } },
    ],
    reject: { success: true },
}

export const declineProductCreation: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas(
            'I cannot create products. My catalog tools are read-only, so you will have to add the '
            + 'Anti-musquito candle through Catalog > Products yourself.'
        ),
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
}

export const lookupProduct: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        {
            event: 'tool_call',
            data: { id: 'toolu_product_1', name: 'product_data', input: { action: 'search', query: 'candle' } },
        },
        ...textDeltas('I found 2 products matching "candle".'),
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
}

export const streamFailure: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        { event: 'error', data: { error: 'API error (HTTP 429) rate limit exceeded' } },
        { event: 'done', data: { conversation_id: CONVERSATION_ID } },
    ],
}

// Matches the form_apply directive contract from Task 005: the whole SSE `data` payload is the
// directive itself, not wrapped under another key.
export const formWriteDirective: Record<string, unknown> = {
    type: 'form_write',
    target: {
        namespace: 'product_form',
        entity_type: 'product',
        entity_id: '42',
        store_id: '',
    },
    changes: [
        {
            path: 'data.product.name',
            label: 'Product Name',
            previous_value: 'Chair',
            value: 'Sedia',
            clear_use_default: true,
        },
    ],
}

export const formApplyOnRead: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('Updating the product name. '),
        { event: 'form_apply', data: formWriteDirective },
        ...textDeltas('Done.'),
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
}

export const formApplyOnConfirm: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('I will update the product name for you. '),
        {
            event: 'tool_call',
            data: {
                id: 'toolu_form_write_1',
                name: 'write_fields',
                input: { action: 'apply', changes: [{ path: 'data.product.name', value: 'Sedia' }] },
            },
        },
        {
            event: 'confirm',
            data: {
                tools: [
                    {
                        name: 'write_fields',
                        description: 'Write staged field changes to the open form',
                        input: { action: 'apply' },
                    },
                ],
            },
        },
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true },
        },
    ],
    status: { message_id: MESSAGE_ID },
    confirm: [
        { event: 'form_apply', data: formWriteDirective },
        ...textDeltas('Updated the product name.'),
        { event: 'done', data: { conversation_id: CONVERSATION_ID } },
    ],
}

// A page_form.write_fields proposal: what a real turn looks like once task 006's action exists.
// Distinct from formApplyOnConfirm above, which predates the real tool and stands in only for
// routing the form_apply event, not for the shape of a genuine write_fields tool call.
export const stageFieldChange: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('I will update the product name for you. '),
        {
            event: 'tool_call',
            data: {
                id: 'toolu_write_fields_1',
                name: 'page_form',
                input: {
                    action: 'write_fields',
                    form_namespace: 'product_form',
                    entity_id: '42',
                    store_id: '',
                    changes: [{ path: 'data.product.name', value: 'Sedia' }],
                },
            },
        },
        {
            event: 'confirm',
            data: {
                tools: [
                    {
                        name: 'page_form',
                        description: 'Read and write the admin form currently open in the browser',
                        input: {
                            action: 'write_fields',
                            form_namespace: 'product_form',
                            entity_id: '42',
                            store_id: '',
                            changes: [{ path: 'data.product.name', value: 'Sedia' }],
                        },
                    },
                ],
            },
        },
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true },
        },
    ],
    status: { message_id: MESSAGE_ID },
    confirm: [
        { event: 'form_apply', data: formWriteDirective },
        ...textDeltas('Updated the product name.'),
        { event: 'done', data: { conversation_id: CONVERSATION_ID } },
    ],
    reject: { success: true },
}

export const formApplyUnknownType: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('Updating the product name. '),
        { event: 'form_apply', data: { ...formWriteDirective, type: 'something_else' } },
        ...textDeltas('Done.'),
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
}

export const formApplyNotAnObject: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('Updating the product name. '),
        { event: 'form_apply', data: ['not', 'an', 'object'] as unknown as Record<string, unknown> },
        ...textDeltas('Done.'),
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
}

/* The panel escapes the answer's HTML before a ```mago block is parsed, but JSON's own \u003c escapes
   survive that and decode to markup inside the spec: this is how a model smuggles HTML to a builder. */
export const widgetAnswerWithUnicodeEscapedMarkup = (widgets: unknown): ChatScenario =>
    widgetAnswerJson(JSON.stringify(widgets).replace(/</g, '\\u003c').replace(/>/g, '\\u003e'));

export const widgetAnswer = (widgets: unknown): ChatScenario => widgetAnswerJson(JSON.stringify(widgets));

const widgetAnswerJson = (json: string): ChatScenario => ({
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        { event: 'text', data: { text: 'Here you go.\n\n```mago\n' + json + '\n```\n' } },
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
})

/* One answer of plain markdown, the way a prompt-injected model would write it. */
export const markdownAnswer = (markdown: string): ChatScenario => ({
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        { event: 'text', data: { text: markdown } },
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
})

/* An answer whose privacy tokens arrive the way the server sends them: intact in the text, with
   the value behind each one beside it, for the panel to put in as text. A token split across two
   deltas never happens (the server holds a partial one back), so each delta carries whole ones. */
export const vaultAnswer = (deltas: string[], tokens: Record<string, string>): ChatScenario => ({
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...deltas.map((text) => ({ event: 'text', data: { text, tokens } })),
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: false },
        },
    ],
})

/* stageFieldChange, with a field value of the caller's choosing. */
export const stageFieldValue = (value: string): ChatScenario => {
    const input = {
        action: 'write_fields',
        form_namespace: 'product_form',
        entity_id: '42',
        store_id: '',
        changes: [{ path: 'data.product.name', value }],
    };

    return {
        ...stageFieldChange,
        stream: [
            { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
            ...textDeltas('I will update the product name for you. '),
            { event: 'tool_call', data: { id: 'toolu_write_fields_1', name: 'page_form', input } },
            {
                event: 'confirm',
                data: {
                    tools: [{
                        name: 'page_form',
                        description: 'Read and write the admin form currently open in the browser',
                        input,
                    }],
                },
            },
            {
                event: 'done',
                data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true },
            },
        ],
    };
}

/* Two writes in one turn: a config value the summary line used to leave out entirely, and CMS
   content whose markup only starts after the first forty characters the summary showed. */
export const BATCH_CONFIG_VALUE = 'https://batch-e2e.example.com/';
export const BATCH_CMS_CONTENT = '<p>Spring collection is here, come and see it.</p><script>document.title="owned"</script>';

const batchConfigCall = {
    id: 'toolu_config_1',
    name: 'config_writer',
    input: { action: 'set', path: 'web/unsecure/base_link_url', scope: 'default', scope_id: 0, value: BATCH_CONFIG_VALUE },
};
const batchCmsCall = {
    id: 'toolu_cms_2',
    name: 'cms_data',
    input: { action: 'update_page', identifier: 'home', content: BATCH_CMS_CONTENT },
};

export const batchWrites: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('I will update the base link and the home page. '),
        { event: 'tool_call', data: batchConfigCall },
        { event: 'tool_call', data: batchCmsCall },
        {
            event: 'confirm',
            data: {
                tools: [
                    { ...batchConfigCall, description: 'Write store configuration' },
                    { ...batchCmsCall, description: 'Manage CMS pages and blocks' },
                ],
            },
        },
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true },
        },
    ],
    status: { message_id: MESSAGE_ID },
    confirm: [
        ...textDeltas('Updated the home page.'),
        { event: 'done', data: { conversation_id: CONVERSATION_ID } },
    ],
    reject: { success: true },
}

/* A form write with more fields than the card used to list, one of them longer than its fold. */
export const LONG_FIELD_VALUE = 'A long description that keeps going well past the point where the card folds it, '
    + 'and ends with <b>markup</b> the admin must still be able to read in full.';

const manyFieldChanges = [
    ...Array.from({ length: 11 }, (_, i) => ({ path: 'data.product.custom_field_' + (i + 1), value: 'Value ' + (i + 1) })),
    { path: 'data.product.description', value: LONG_FIELD_VALUE },
];

const stageManyFieldsInput = {
    action: 'write_fields',
    form_namespace: 'product_form',
    entity_id: '42',
    store_id: '',
    changes: manyFieldChanges,
};

export const stageManyFieldChanges: ChatScenario = {
    stream: [
        { event: 'conversation', data: { conversation_id: CONVERSATION_ID, admin_user: 'Tester' } },
        ...textDeltas('I will fill in these fields. '),
        { event: 'tool_call', data: { id: 'toolu_write_fields_many', name: 'page_form', input: stageManyFieldsInput } },
        {
            event: 'confirm',
            data: {
                tools: [
                    {
                        name: 'page_form',
                        description: 'Read and write the admin form currently open in the browser',
                        input: stageManyFieldsInput,
                    },
                ],
            },
        },
        {
            event: 'done',
            data: { message_id: MESSAGE_ID, conversation_id: CONVERSATION_ID, pending_confirmation: true },
        },
    ],
    status: { message_id: MESSAGE_ID },
    reject: { success: true },
}
