<?php

namespace App\Domain\Files\Exceptions;

/**
 * An upload the shared validator would not accept.
 *
 * Every one of these names what was wrong and, where it can, what would have
 * been accepted instead, because the caller is holding the file and can fix
 * it. None of them says anything about the store or about other companies'
 * documents.
 */
class UploadRefusedException extends FilesException
{
    public static function notAFile(string $purpose): self
    {
        return new self(sprintf(
            'The %s could not be read as an uploaded file. Send it again as a file part of the request.',
            $purpose
        ));
    }

    public static function empty(string $purpose): self
    {
        return new self(sprintf('The %s is empty.', $purpose));
    }

    public static function tooLarge(string $purpose, string $name, int $maxKilobytes): self
    {
        return new self(sprintf(
            'The %s "%s" is larger than the %s KB this company accepts for one upload.',
            $purpose,
            $name,
            number_format($maxKilobytes)
        ));
    }

    /**
     * The extension is not one this deployment accepts at all.
     *
     * @param  list<string>  $allowed
     */
    public static function typeNotAllowed(string $purpose, string $name, string $extension, array $allowed): self
    {
        return new self(sprintf(
            'The %s "%s" is a .%s file. This company accepts: %s.',
            $purpose,
            $name,
            $extension === '' ? '(none)' : $extension,
            $allowed === [] ? '(nothing)' : implode(', ', $allowed)
        ));
    }

    /**
     * The extension is accepted but the contents are not what that extension
     * promises — a file renamed to look like something it is not.
     *
     * @param  list<string>  $expected
     */
    public static function contentsDoNotMatch(string $name, string $extension, string $sniffed, array $expected): self
    {
        return new self(sprintf(
            'The file "%s" is named as a .%s file but its contents are %s. A file is accepted by what is inside it and not by what it is called, so change the contents or the name and send it again.',
            $name,
            $extension,
            $sniffed === '' ? 'unreadable' : $sniffed
        ));
    }

    public static function unsafe(string $name, string $reason): self
    {
        return new self(sprintf(
            'The file "%s" was refused by the virus scanner: %s',
            $name,
            $reason
        ));
    }
}
