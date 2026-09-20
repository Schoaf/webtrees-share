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
            $fields[$key] = self::fieldValue($individual, $definition);
        }

        return $fields;
    }

    /**
     * Read one WebtreesShareModule::FIELDS entry, dispatching on its 'kind' (see the constant's
     * docblock for the three shapes).
     *
     * @param array{kind: string, tag: string, part: string|null} $definition
     */
    public static function fieldValue(Individual $individual, array $definition): string
    {
        if ($definition['kind'] === 'value') {
            return self::factValue($individual, $definition['tag']);
        }

        return self::factPart($individual, $definition['tag'], $definition['part'] ?? '');
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
     * The level-1 value of a fact whose value sits on the tag line itself, e.g.
     * factValue($indi, 'TITL') for "1 TITL Dr.". Returns '' if the fact doesn't exist.
     */
    private static function factValue(Individual $individual, string $tag): string
    {
        $fact = $individual->facts([$tag], false, null, true)->first();

        return $fact === null ? '' : trim($fact->value());
    }

    /**
     * Apply one WebtreesShareModule::FIELDS entry with a guest-submitted value, dispatching on
     * 'kind'. Deliberately narrow: this module only ever touches the fixed field set, never
     * arbitrary facts.
     *
     * @param array{kind: string, tag: string, part: string|null} $definition
     */
    public static function applyField(Individual $individual, array $definition, string $value): void
    {
        match ($definition['kind']) {
            'value'     => self::applyFactValue($individual, $definition['tag'], $value),
            'name_part' => self::applyNamePart($individual, $definition['part'], $value),
            default     => self::applySubline($individual, $definition['tag'], $definition['part'], $value),
        };
    }

    /**
     * Create or update a fact's DATE/PLAC-style sub-line with a guest-submitted value.
     */
    private static function applySubline(Individual $individual, string $tag, string $part, string $value): void
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
     * Create or update a fact whose value sits directly on the level-1 tag line, e.g. "1 TITL Dr.".
     * A blank submitted value is left alone rather than clearing an existing title - a guest
     * leaving this field empty means "I don't know", not "please remove it".
     */
    private static function applyFactValue(Individual $individual, string $tag, string $value): void
    {
        $value = self::line($value);

        if ($value === '') {
            return;
        }

        $fact = $individual->facts([$tag], false, null, true)->first();

        if ($fact === null) {
            $individual->createFact("1 {$tag} {$value}", true);

            return;
        }

        $gedcom = (string) preg_replace('/^1 ' . preg_quote($tag, '/') . '[^\n]*/', '1 ' . $tag . ' ' . $value, $fact->gedcom(), 1);

        $individual->updateFact($fact->id(), $gedcom, true);
    }

    /**
     * Update a NAME sub-line (GIVN/SURN) and rebuild the primary "1 NAME ..." line from the
     * result, so the display name (built from that primary value) stays consistent with
     * whichever part the guest corrected. Does nothing if the individual has no NAME fact at
     * all - not realistic for a real record, and there is no sensible fact to attach a
     * given-name/surname to otherwise.
     */
    private static function applyNamePart(Individual $individual, string $part, string $value): void
    {
        $value = self::line($value);
        $fact  = $individual->facts(['NAME'], false, null, true)->first();

        if ($fact === null) {
            return;
        }

        $gedcom  = $fact->gedcom();
        $pattern = '/\n2 ' . preg_quote($part, '/') . '.*(\n[3-9] .*)*/';

        if (preg_match($pattern, $gedcom) === 1) {
            $gedcom = (string) preg_replace($pattern, $value === '' ? '' : "\n2 {$part} {$value}", $gedcom, 1);
        } elseif ($value !== '') {
            $gedcom .= "\n2 {$part} {$value}";
        }

        $givn    = $part === 'GIVN' ? $value : self::linePart($gedcom, 'GIVN');
        $surn    = $part === 'SURN' ? $value : self::linePart($gedcom, 'SURN');
        $primary = trim($givn . ' /' . $surn . '/');
        $gedcom  = (string) preg_replace('/^1 NAME[^\n]*/', '1 NAME ' . $primary, $gedcom, 1);

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
