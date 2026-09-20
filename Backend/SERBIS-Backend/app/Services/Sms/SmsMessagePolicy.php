<?php

namespace App\Services\Sms;

/**
 * What a message may contain before it is worth sending.
 *
 * The vendor penalises URLs, domains and IP addresses (10 to 50 credits per
 * recipient) and reports such a message as "sent" while not delivering it, so
 * one of these is money spent for nothing. The check runs before any request
 * leaves, on every path.
 */
class SmsMessagePolicy
{
    /**
     * Schemes, "www.", bare IPv4 addresses, well-known shorteners, and a name
     * followed by a common top-level domain. The TLD list is deliberately
     * short and aimed at what a person might type into a notice: a general
     * "word dot word" rule would refuse every sentence typed without a space
     * after its full stop.
     */
    private const LINK_PATTERN = '~(?:https?://|www\.|\b(?:bit\.ly|tinyurl\.com|t\.co|goo\.gl|ow\.ly|is\.gd)\b|\b\d{1,3}(?:\.\d{1,3}){3}\b|\b[a-z0-9][a-z0-9-]*\.(?:com|net|org|ph|io|info|biz|edu|gov|co|me|ly|gl|tk|xyz|site|online|app|dev|link|click|shop|store|live|fun|top|vip|club)\b)~i';

    /** The basic GSM 03.38 septet set — the panel's smsSegments.ts carries the same table. */
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** Two septets each, but still GSM-7. */
    private const GSM_EXTENDED = "^{}\\[~]|€\f";

    /** The characters word processors substitute for their plain ASCII twins. */
    private const ASCII_MAP = [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{2032}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{2033}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
        "\u{2026}" => '...',
        "\u{00A0}" => ' ', "\u{200B}" => '',
    ];

    public static function containsLink(string $message): bool
    {
        return preg_match(self::LINK_PATTERN, $message) === 1;
    }

    /**
     * The distinct characters outside GSM-7, which move the whole message to
     * UCS-2 and cut a segment from 160 characters to 70.
     *
     * @return array<int, string>
     */
    public static function nonGsmCharacters(string $message): array
    {
        $found = [];

        foreach (mb_str_split($message) as $char) {
            if (mb_strpos(self::GSM_BASIC, $char) === false && mb_strpos(self::GSM_EXTENDED, $char) === false) {
                $found[$char] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Swaps the look-alike punctuation for plain ASCII: curly quotes, dashes,
     * the ellipsis character, non-breaking and zero-width spaces. Anything
     * else outside GSM-7 (an emoji, an accented letter GSM lacks) is left for
     * the caller to see, not silently dropped.
     */
    public static function toGsmSafe(string $text): string
    {
        return strtr($text, self::ASCII_MAP);
    }

    /**
     * A staff-typed name (an equipment item) made safe to put inside a text.
     *
     * The name is data, not the message: refusing the whole reminder because
     * staff called an item "Tent.com Set" would leave a resident never told
     * their loan is due. So when the name itself looks like a link its dots
     * become spaces ("Tent com Set"), and a name that only trips because of a
     * scheme ("https://…") loses its colons and slashes too. A name that does
     * not look like a link is only tidied to plain ASCII. Anything still
     * tripping after that is replaced by "item".
     *
     * Only for names substituted into a fixed template. Text a person writes
     * as the message itself — the blast — keeps the hard block.
     */
    public static function sanitizeName(string $name): string
    {
        $name = self::toGsmSafe($name);

        if (self::containsLink($name)) {
            $name = str_replace('.', ' ', $name);
        }

        if (self::containsLink($name)) {
            $name = preg_replace('~[:/]+~', ' ', $name) ?? $name;
        }

        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        // Nothing readable left (empty, or only punctuation) says nothing about
        // what is due back, so it falls back to the generic word.
        $readable = preg_match('/[\p{L}\p{N}]/u', $name) === 1;

        return ! $readable || self::containsLink($name) ? 'item' : $name;
    }
}
