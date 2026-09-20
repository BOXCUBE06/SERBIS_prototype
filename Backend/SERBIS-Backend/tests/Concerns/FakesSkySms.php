<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A faked SkySMS host whose answer a test can flip, and a way to read back the
 * codes it was asked to text.
 *
 * SkySMS has no sandbox, so a request that escaped the fake would be a billed
 * text to a real phone. A second Http::fake() for the same URL does not replace
 * the first — the earlier stub still matches — which is why the behaviour is
 * switched by properties read inside one stub instead of by re-faking.
 */
trait FakesSkySms
{
    /** Never answers, as a timeout would. */
    protected bool $smsTimesOut = false;

    /** Answers 402, as an empty credit balance does. */
    protected bool $smsOutOfCredits = false;

    /** Answers 200 with success:false. */
    protected bool $smsRejects = false;

    /** How many of the next sends answer 429 with Retry-After: 0 before one is accepted. */
    protected int $smsShortRateLimits = 0;

    /** How many of the next sends answer 429 with a Retry-After too long to wait for. */
    protected int $smsLongRateLimits = 0;

    protected function fakeSkySms(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'skysms.skyio.site/*' => function () {
                if ($this->smsTimesOut) {
                    throw new ConnectionException('cURL error 28: timed out');
                }

                if ($this->smsShortRateLimits > 0) {
                    $this->smsShortRateLimits--;

                    return Http::response(['message' => 'slow down'], 429, ['Retry-After' => '0']);
                }

                if ($this->smsLongRateLimits > 0) {
                    $this->smsLongRateLimits--;

                    return Http::response(['message' => 'slow down'], 429, ['Retry-After' => '30']);
                }

                if ($this->smsOutOfCredits) {
                    return Http::response(['message' => 'Insufficient credits'], 402);
                }

                return Http::response(['success' => ! $this->smsRejects], 200);
            },
        ]);
    }

    /**
     * Every six-digit code SkySMS was asked to text, oldest first. Read off the
     * outgoing request bodies rather than storage, because what is stored is a
     * hash — the plain code exists only in the message.
     *
     * @return array<int, string>
     */
    protected function codesTexted(): array
    {
        return Http::recorded()
            ->map(function ($pair) {
                preg_match('/[0-9]{6}/', $pair[0]['message'] ?? '', $match);

                return $match[0] ?? null;
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function lastCodeTexted(): string
    {
        $codes = $this->codesTexted();

        return (string) end($codes);
    }

    /** The numbers SkySMS was asked to text, in order, as sent (E.164). */
    protected function numbersTexted(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]['phone_number'] ?? null)->filter()->values()->all();
    }
}
