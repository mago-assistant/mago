<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Privacy;

use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PrivacyServiceTest extends TestCase
{
    private function service(ConversationVault $vault): PrivacyService
    {
        return new PrivacyService(new PrivacyFilter($vault, new PiiHeuristic()), $vault, new PiiHeuristic());
    }

    #[Test]
    public function itScrubsPiiTypedIntoTheUserMessageBeforeItReachesTheLlm(): void
    {
        $messages = $this->service(new ConversationVault())->scrubMessages([
            ['role' => 'system', 'content' => 'You are an assistant.'],
            ['role' => 'user', 'content' => 'Email jan@example.com about order 000000549'],
        ]);

        self::assertSame('Email mago://email_1 about order 000000549', $messages[1]['content']);
        self::assertStringNotContainsString('jan@example.com', (string)json_encode($messages));
    }

    #[Test]
    public function theSameTypedValueKeepsItsTokenAcrossMessages(): void
    {
        $service = $this->service(new ConversationVault());

        $first = $service->scrubMessages([['role' => 'user', 'content' => 'mail jan@example.com']]);
        $second = $service->scrubMessages([['role' => 'user', 'content' => 'again jan@example.com']]);

        self::assertSame('mail mago://email_1', $first[0]['content']);
        self::assertSame('again mago://email_1', $second[0]['content']);
    }

    #[Test]
    public function displayShowsTheRealValueForAResolvableTokenAndANeutralLabelOtherwise(): void
    {
        $vault = new ConversationVault();
        $service = $this->service($vault);
        $service->scrubText('mail jan@example.com');

        $display = $service->displayText('Sent to mago://email_1, earlier case was mago://customer_9.');

        self::assertSame('Sent to jan@example.com, earlier case was [earlier record].', $display);
    }

    #[Test]
    public function onlyAdminUrlTokensAreRefusedForWrites(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertTrue($service->containsSensitiveToken(['nested' => ['link' => 'See mago://url_2']]));
        self::assertFalse($service->containsSensitiveToken(['content' => 'Mail mago://email_1 now']));
        self::assertFalse($service->containsSensitiveToken(['comment' => 'About mago://order_1 and mago://customer_3']));
    }

    #[Test]
    public function everyMaskedValueExceptAnAdminUrlIsFlaggedForTheConfirmationWarning(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertTrue($service->containsPersonalToken(['content' => 'Mail mago://email_1 now']));
        self::assertTrue($service->containsPersonalToken(['nested' => ['phone' => 'mago://phone_3']]));
        self::assertTrue($service->containsPersonalToken(['legacy' => 'Mail [email_1] now']));
        self::assertTrue($service->containsPersonalToken(['comment' => 'About mago://order_1']));
        self::assertTrue($service->containsPersonalToken(['place' => 'Lives in mago://city_2']));
        self::assertFalse($service->containsPersonalToken(['link' => 'See mago://url_2']));
        self::assertFalse($service->containsPersonalToken(['legacy_link' => 'See [url_2]']));
        self::assertFalse($service->containsPersonalToken(['content' => 'Plain [text] and mago://']));
    }

    #[Test]
    public function itMasksValuesIrreversiblyForVaultlessSinks(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertSame(
            'Bel [phone] over [email]',
            $service->maskText('Bel 06 12345678 over jan@example.com')
        );
    }

    #[Test]
    public function aTitleNeverEndsInHalfAToken(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertSame('Stuur een mail naar ', $service->safeTitle('Stuur een mail naar [ema', 24));
        self::assertSame('Order mago://order_1', $service->safeTitle('Order mago://order_1', 50));
    }

    #[Test]
    public function itScrubsASingleTextForStorage(): void
    {
        $service = $this->service(new ConversationVault());

        $stored = $service->scrubText('Bel 06 12345678 over jan@example.com');

        self::assertSame('Bel mago://phone_1 over mago://email_1', $stored);
        self::assertSame($stored, $service->scrubText($stored));
    }

    #[Test]
    public function itRehydratesTokensInToolCallArgumentsBeforeExecution(): void
    {
        $vault = new ConversationVault();
        $service = $this->service($vault);
        $service->scrubMessages([['role' => 'user', 'content' => 'find jan@example.com']]);

        $input = $service->rehydrateArguments(['action' => 'lookup_customer', 'search' => 'mago://email_1']);

        self::assertSame('jan@example.com', $input['search']);
        self::assertSame('lookup_customer', $input['action']);
    }

    #[Test]
    public function itHoldsBackATokenThatSplitsAcrossStreamedChunks(): void
    {
        $service = $this->service(new ConversationVault());

        [$emit1, $carry1] = $service->splitStreamDelta('', 'Mailing mago://email');
        [$emit2, $carry2] = $service->splitStreamDelta($carry1, '_1 now');

        self::assertSame('Mailing ', $emit1);
        self::assertSame('mago://email_1 now', $emit2);
        self::assertSame('', $carry2);
    }

    /**
     * A chunk can end on the bare "m" a token starts with; that "m" has to be held back as well,
     * or the rest of the token goes out on its own and the panel never recognises it.
     */
    #[Test]
    public function itHoldsBackATokenCutRightAfterItsFirstLetter(): void
    {
        $service = $this->service(new ConversationVault());

        [$emit1, $carry1] = $service->splitStreamDelta('', 'Mail m');
        [$emit2, $carry2] = $service->splitStreamDelta($carry1, 'ago://email_1 now');

        self::assertSame('Mail ', $emit1);
        self::assertSame('mago://email_1 now', $emit2);
        self::assertSame('', $carry2);
    }

    #[Test]
    public function itNeverRehydratesTheStreamedText(): void
    {
        $vault = new ConversationVault();
        $service = $this->service($vault);
        $token = $vault->tokenise('**bold** <img src=x onerror=alert(1)>', 'reviewtext');

        [$emit, $carry] = $service->splitStreamDelta('', 'The review says ' . $token . '.');

        self::assertSame('The review says ' . $token . '.', $emit);
        self::assertSame('', $carry);
    }

    /**
     * A widget block carries several tokens; whatever chunk size the provider picks, every token
     * reaches the panel whole, with its value in the same delta.
     */
    #[Test]
    public function everyTokenInAStreamedWidgetArrivesWholeWithItsValueWhateverTheChunkSize(): void
    {
        $vault = new ConversationVault();
        $service = $this->service($vault);
        $name = $vault->tokenise('Luuk van der Berg', 'name');
        $url = $vault->tokenise('https://shop.test/admin/customer/index/edit/id/2/key/abc/', 'url');
        $answer = "Top:\n\n```mago\n" . '{"type":"entityList","items":[{"title":"' . $name . '","href":"' . $url
            . '"}]}' . "\n```";

        foreach (range(1, 12) as $size) {
            $emitted = '';
            $values = [];
            $carry = '';
            foreach (str_split($answer, $size) as $delta) {
                [$text, $carry] = $service->splitStreamDelta($carry, $delta);
                $emitted .= $text;
                $values += $service->tokenValues($text);
            }
            $emitted .= $carry;
            $values += $service->tokenValues($carry);

            self::assertSame($answer, $emitted, "Chunk size {$size}");
            self::assertEqualsCanonicalizing(
                [$name => 'Luuk van der Berg', $url => 'https://shop.test/admin/customer/index/edit/id/2/key/abc/'],
                $values,
                "Chunk size {$size}"
            );
        }
    }

    #[Test]
    public function itGivesTheValueOfEachResolvableTokenAndLeavesUnknownTokensOut(): void
    {
        $vault = new ConversationVault();
        $service = $this->service($vault);
        $email = $vault->tokenise('jan@example.com', 'email');
        $review = $vault->tokenise('Great [click](https://evil.example)', 'reviewtext');

        $values = $service->tokenValues("Mail {$email} about {$review}, not mago://customer_9 or [order_4].");

        self::assertSame([$email => 'jan@example.com', $review => 'Great [click](https://evil.example)'], $values);
    }

    #[Test]
    public function itGivesNoValuesForATextWithoutTokens(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertSame([], $service->tokenValues('Nothing masked here.'));
    }

    #[Test]
    public function customerWrittenTokensAreRefusedForWrites(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertTrue($service->containsCustomerWrittenToken(['content' => 'Quote: mago://reviewtext_1']));
        self::assertTrue($service->containsCustomerWrittenToken(['nested' => ['title' => 'mago://reviewtitle_2']]));
        self::assertTrue($service->containsCustomerWrittenToken(['author' => 'By [nickname_3]']));
        self::assertFalse($service->containsCustomerWrittenToken(['content' => 'Mail mago://email_1 now']));
        self::assertFalse($service->containsCustomerWrittenToken(['review_id' => 'mago://review_1']));
    }

    #[Test]
    public function itDetectsATokenInWriteArgumentsSoAWriteCanBeRefused(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertTrue($service->containsToken(['comment' => 'Call mago://customer_1 back']));
        self::assertTrue($service->containsToken(['nested' => ['ref' => 'mago://order_2']]));
        self::assertFalse($service->containsToken(['status' => 'processing', 'qty' => 3]));
    }

    #[Test]
    public function itRehydratesTokensForTheAdmin(): void
    {
        $vault = new ConversationVault();
        $service = $this->service($vault);
        $service->scrubMessages([['role' => 'user', 'content' => 'call 0612345678']]);

        self::assertSame('I will call 0612345678', $service->rehydrate('I will call mago://phone_1'));
    }

    /**
     * #160: the model sometimes escapes a token's underscore or brackets as markdown. Markdown then
     * shows the plain token again, so it has to resolve like one.
     */
    #[Test]
    public function itResolvesATokenTheModelEscapedAsMarkdown(): void
    {
        $vault = new ConversationVault();
        $service = $this->service($vault);
        $url = $vault->tokenise('https://shop.test/admin/user/index/key/abc/', 'url');
        $name = $vault->tokenise('Jan Jansen', 'name');

        self::assertSame('mago://url_1', $url);
        self::assertSame(
            ['mago://url_1' => 'https://shop.test/admin/user/index/key/abc/', 'mago://name_1' => 'Jan Jansen'],
            $service->tokenValues('Open mago://url\_1 for mago://name\_1')
        );
        self::assertSame('Hi Jan Jansen', $service->displayText('Hi mago://name\_1'));
        self::assertSame('Hi Jan Jansen', $service->rehydrate('Hi mago://name\_1'));
        self::assertSame('Hi Jan Jansen', $service->rehydrate('Hi mago\:\/\/name_1'));
    }

    #[Test]
    public function anEscapedTokenItCannotResolveShowsTheNeutralLabel(): void
    {
        self::assertSame(
            'Open [earlier record] now',
            $this->service(new ConversationVault())->displayText('Open mago://url\_7 now')
        );
    }

    /**
     * An escaped admin URL token in a write must refuse like the plain one, now that it rehydrates:
     * otherwise the escape would carry the admin secret key into store data.
     */
    #[Test]
    public function anEscapedTokenInAWriteIsRefusedLikeThePlainOne(): void
    {
        $service = $this->service(new ConversationVault());

        self::assertTrue($service->containsSensitiveToken(['content' => 'See mago://url\_2']));
        self::assertTrue($service->containsSensitiveToken(['content' => 'See \[url\_2\]']));
        self::assertTrue($service->containsSensitiveToken(['content' => 'See mago\://url_2']));
        self::assertTrue($service->containsCustomerWrittenToken(['content' => 'Quote mago://reviewtext\_1']));
        self::assertTrue($service->containsPersonalToken(['content' => 'For mago://name\_1']));
        self::assertTrue($service->containsToken(['content' => 'For mago://name\_9']));
    }

    #[Test]
    public function itRehydratesAnEscapedTokenInToolArguments(): void
    {
        $vault = new ConversationVault();
        $vault->tokenise('jan@example.com', 'email');

        self::assertSame(
            ['search' => 'jan@example.com'],
            $this->service($vault)->rehydrateArguments(['search' => 'mago://email\_1'])
        );
    }

    #[Test]
    public function itHoldsBackAnEscapedTokenThatSplitsAcrossStreamedChunks(): void
    {
        $service = $this->service(new ConversationVault());

        [$emit1, $carry1] = $service->splitStreamDelta('', 'Open mago://url\\');
        [$emit2, $carry2] = $service->splitStreamDelta($carry1, '_3 now');

        self::assertSame('Open ', $emit1);
        self::assertSame('mago://url\_3 now', $emit2);
        self::assertSame('', $carry2);
    }

    #[Test]
    public function itHoldsBackATokenCutRightAfterAnEscapeInItsScheme(): void
    {
        $service = $this->service(new ConversationVault());

        [$emit1, $carry1] = $service->splitStreamDelta('', 'Open mago\\');
        [$emit2, $carry2] = $service->splitStreamDelta($carry1, '://url_3 now');

        self::assertSame('Open ', $emit1);
        self::assertSame('mago\\://url_3 now', $emit2);
        self::assertSame('', $carry2);
    }
}
