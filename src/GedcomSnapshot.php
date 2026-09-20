<?php

declare(strict_types=1);

namespace WebtreesShare;

use Fisharebest\Webtrees\Individual;

use function array_flip;
use function array_keys;
use function implode;
use function in_array;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function sprintf;
use function str_contains;
use function strrpos;
use function substr;
use function trim;

/**
 * Pure helpers around the fixed "birth/death date & place" field set this module works with.
 * No webtrees state beyond the Individual passed in, so these stay easy to read and test.
 */
final class GedcomSnapshot
{
    // Structural/administrative tags, plus the ones already covered by WebtreesShareModule::FIELDS -
    // the rest are shown as read-only context on the request page ("here's what we already know").
    private const array CONTEXT_SKIP_TAGS = [
        'NAME', 'SEX', 'BIRT', 'DEAT', 'FAMS', 'FAMC', 'HUSB', 'WIFE', 'CHIL', 'OBJE', 'CHAN', '_UID', 'RESN',
    ];

    private const array GEDCOM_MONTHS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    /**
     * "12 MAR 1930" -> "1930-03-12" for an HTML date-picker's value attribute. Only handles a
     * plain day-month-year date, on purpose: GEDCOM qualifiers ("ABT 1930", ranges, ...) are an
     * editor-level nuance, not something to expect from a guest with no genealogy background -
     * a date the picker can't represent is simply left for them to (re-)enter fresh.
     */
    public static function gedcomDateToIso(string $gedcom): string
    {
        $months = self::GEDCOM_MONTHS;

        if (preg_match('/^(\d{1,2}) (' . implode('|', array_keys($months)) . ') (\d{3,4})$/', trim($gedcom), $match) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $match[3], $months[$match[2]], (int) $match[1]);
        }

        return '';
    }

    /**
     * The reverse of gedcomDateToIso() - what a browser's <input type="date"> submits back.
     */
    public static function isoDateToGedcom(string $iso): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($iso), $match) === 1) {
            $months = array_flip(self::GEDCOM_MONTHS);
            $month  = $months[(int) $match[2]] ?? null;

            if ($month !== null) {
                return sprintf('%d %s %d', (int) $match[3], $month, (int) $match[1]);
            }
        }

        return '';
    }

    /**
     * The current value of the fixed field set - used both to pre-fill the guest form
     * and, later, as the "before" side of the review comparison.
     *
     * @return array<string,string>
     */
    public static function fields(Individual $individual): array
    {
        $fields = [];

        foreach (WebtreesShareModule::FIELDS as $key => $definition) {
            $fields[$key] = self::factPart($individual, $definition['tag'], $definition['part']);
        }

        return $fields;
    }

    /**
     * A short read-only list of the person's other facts, for context on the request page.
     *
     * @return list<array{tag: string, label: string, value: string}>
     */
    public static function context(Individual $individual): array
    {
        $context = [];

        foreach ($individual->facts([], true) as $fact) {
            $tag = $fact->tag();
            $tag = str_contains($tag, ':') ? substr($tag, strrpos($tag, ':') + 1) : $tag;

            if (in_array($tag, self::CONTEXT_SKIP_TAGS, true)) {
                continue;
            }

            $value = $fact->value();

            if ($value === '') {
                $place = self::linePart($fact->gedcom(), 'PLAC');
                $date  = self::linePart($fact->gedcom(), 'DATE');
                $value = trim($date . ' ' . $place);
            }

            if ($value === '') {
                continue;
            }

            $context[] = [
                'tag'   => $tag,
                'label' => $fact->label(),
                'value' => $value,
            ];
        }

        return $context;
    }

    /**
     * The raw GEDCOM value of a fact's sub-line, e.g. factPart($indi, 'BIRT', 'DATE').
     * Returns '' if the fact or the sub-line doesn't exist.
     */
    public static function factPart(Individual $individual, string $tag, string $part): string
    {
        $fact = $individual->facts([$tag], false, null, true)->first();

        if ($fact === null) {
            return '';
        }

        return self::linePart($fact->gedcom(), $part);
    }

    private static function linePart(string $gedcom, string $part): string
    {
        if (preg_match('/\n2 ' . preg_quote($part, '/') . ' ?(.*)/', $gedcom, $match) === 1) {
            return trim($match[1]);
        }

        return '';
    }

    /**
     * Create or update a fact's DATE/PLAC sub-line with a guest-submitted value.
     * Deliberately narrow: this module only ever touches BIRT/DEAT DATE/PLAC (see
     * WebtreesShareModule::FIELDS), never arbitrary facts.
     */
    public static function applyField(Individual $individual, string $tag, string $part, string $value): void
    {
        $value = self::line($value);
        $fact  = $individual->facts([$tag], false, null, true)->first();

        if ($fact === null) {
            if ($value === '') {
                return;
            }

            $individual->createFact("1 {$tag}\n2 {$part} {$value}", true);

            return;
        }

        $gedcom  = $fact->gedcom();
        $pattern = '/\n2 ' . preg_quote($part, '/') . '.*(\n[3-9] .*)*/';

        if (preg_match($pattern, $gedcom) === 1) {
            $gedcom = (string) preg_replace($pattern, $value === '' ? '' : "\n2 {$part} {$value}", $gedcom, 1);
        } elseif ($value !== '') {
            $gedcom .= "\n2 {$part} {$value}";
        }

        $individual->updateFact($fact->id(), $gedcom, true);
    }

    /**
     * Single-line GEDCOM value: no line breaks, so a submitted value can't inject extra lines.
     */
    public static function line(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
