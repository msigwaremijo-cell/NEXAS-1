<?php

namespace App\Domain\Banking\Services;

/**
 * The fingerprint that makes one statement movement recognisable again.
 *
 * A statement file is downloaded from a bank and uploaded by a person, and
 * uploading a file that overlaps one already imported is the normal case rather
 * than an attack: banks hand out statements by date range, and ranges overlap.
 * The fingerprint is what lets an import recognise a movement it has already
 * seen without comparing five columns by hand.
 *
 * The fields hashed are the ones the requirement names: the date, the amount
 * (both sides, so a debit cannot collide with a credit of the same size), the
 * reference and the description. They are joined with a byte that cannot occur
 * in CSV text, so a reference ending in the description's first word cannot
 * produce the same string as the two fields the other way round.
 *
 * The consequence is deliberate and worth stating: two genuinely identical
 * movements on one day, with the same reference and the same wording, hash
 * alike. Content hashing cannot tell them apart, so the second one is refused
 * rather than imported - which is the safe direction, because the alternative
 * is double-counting an overlapping upload.
 */
final class StatementFingerprint
{
    /**
     * ASCII unit separator: not valid UTF-8 text, so it cannot appear in a
     * value read out of a CSV file.
     */
    private const SEPARATOR = "\x1f";

    public static function of(
        string $valueDate,
        string $debit,
        string $credit,
        ?string $reference,
        ?string $description,
    ): string {
        return hash('sha256', implode(self::SEPARATOR, [
            $valueDate,
            $debit,
            $credit,
            self::text($reference),
            self::text($description),
        ]));
    }

    /**
     * Runs of whitespace are collapsed before hashing, so a line re-exported
     * with a double space in its narration still matches the one already held.
     */
    private static function text(?string $value): string
    {
        $collapsed = preg_replace('/\s+/', ' ', (string) $value);

        return trim($collapsed ?? (string) $value);
    }
}
