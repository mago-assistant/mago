<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Form;

/**
 * The normalized, trusted description of the admin page the administrator was looking at when a
 * chat message was sent. Everything here has already passed through PageContextNormalizer, so
 * unlike the raw client payload it carries no un-typed, unbounded or non-scalar data.
 */
class PageContext
{
    /**
     * @param array<int,array<string,mixed>> $fields Normalized field snapshots (path/label/type/value/...)
     */
    public function __construct(
        public readonly string $route,
        public readonly string $namespace,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly bool $isNewEntity,
        public readonly ?string $storeId,
        public readonly array $fields,
        public readonly int $fieldCount,
        public readonly bool $isFieldListTruncated = false
    ) {
    }

    /**
     * Marker the guidance section carries, so a caller-supplied system message that already holds
     * it is not given a second copy.
     */
    public const GUIDANCE_MARKER = '[Page context guide]';

    /**
     * The standing instructions for working with the page the administrator has open.
     *
     * Deliberately constant: nothing here depends on the page, the entity, the store or the number
     * of fields. It sits in the system prompt, ahead of the whole conversation, so a provider that
     * caches a prompt prefix can reuse it on every request. Anything that does vary with the page
     * (which page, which entity) travels as a note on the user message that was sent from it, see
     * {@see \MagoAssistant\Mago\Service\Conversation\NavigationNoteInjector}, and the field
     * count and truncation come from the page_form tool rather than from the prompt.
     *
     * Which tool to reach for while a form is open matters. Several skills can answer "update the
     * description": one drafts copy, another puts a value on the page. Without this the model picks
     * by name and hands the administrator prose about a form it is already looking at, having
     * changed nothing. Only page_form stages a value, so while a form is open it is the one that
     * finishes the job - drafting first with another tool is fine, staging the result through
     * page_form is what makes it land.
     *
     * A truncated list gets its own sentence. Without it a field missing only because the list was
     * cut is indistinguishable from one the form does not have, which invites telling the
     * administrator a field does not exist when it is simply not in view.
     */
    public static function promptGuidance(): string
    {
        return self::GUIDANCE_MARKER . "\n"
            . 'Some user messages start with a note in square brackets, such as "[Page context: ...]" or'
            . ' "[Context update: ...]", saying which admin page the administrator is on. A note appears'
            . ' only when the page changes, so the latest note describes the page that is open now.'
            . ' While a form is open, prefer page_form for anything that is one of its fields: it is the'
            . ' only tool that puts a value on the page. A tool that just returns text leaves the form'
            . ' untouched, so draft with it if you like, then stage the result with page_form rather than'
            . ' replying with the text alone. Use page_form to see the current fields before describing or'
            . ' changing the form. If it reports that the field list was truncated, the form has fields you'
            . ' cannot see: do not tell the administrator a field is missing, say you cannot see all of them'
            . ' and ask which one they mean.';
    }

    public function toLocation(): PageLocation
    {
        return new PageLocation(
            route: $this->route,
            namespace: $this->namespace,
            entityType: $this->entityType,
            entityId: $this->entityId,
            isNewEntity: $this->isNewEntity,
            storeId: $this->storeId
        );
    }
}
