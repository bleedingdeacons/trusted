<?php

declare(strict_types=1);

namespace Trusted\Template;

use Trusted\Support\ResponderDirectory;

/**
 * Validates the member names on a shift template when it is saved.
 *
 * Hooked on `acf/validate_save_post`: every template line may name a member to
 * pre-assign (see TemplateParser). A name is only valid if it matches a Unity
 * member who is a telephone responder. Anything else blocks the save with an
 * inline error on the offending day field, so a template can never carry a name
 * that won't resolve when it is later applied.
 */
final class TemplateValidator
{
    public function __construct(
        private ResponderDirectory $directory,
        private TemplateParser $parser,
    ) {
    }

    public function validate(): void
    {
        if (! function_exists('acf_add_validation_error')) {
            return;
        }

        $values = $this->submittedValues();

        if ($values === []) {
            return; // Not one of our template forms.
        }

        foreach (TemplateFields::DAY_FIELDS as $name => $weekday) {
            $key = TemplateFields::fieldKey($name);

            if (! array_key_exists($key, $values)) {
                continue;
            }

            $this->validateDay($key, (string) $values[$key]);
        }
    }

    private function validateDay(string $fieldKey, string $raw): void
    {
        $seen          = [];
        $nameReported  = false;

        foreach ($this->parser->parse($raw) as $shift) {
            // Every shift must be named. Report a missing name once per day so
            // the save is blocked without repeating the message for each line.
            if (! $nameReported && $shift->label() === '') {
                acf_add_validation_error(
                    'acf[' . $fieldKey . ']',
                    __('Every shift needs a name: HH:MM-HH:MM | Name.', 'trusted')
                );
                $nameReported = true;
            }

            $member = $shift->member();

            if ($member === '' || isset($seen[strtolower($member)])) {
                continue;
            }

            $seen[strtolower($member)] = true;

            if ($this->directory->findResponder($member) !== null) {
                continue;
            }

            $message = $this->directory->memberExists($member)
                ? sprintf(
                    /* translators: %s: member name typed in the template. */
                    __('"%s" is not a telephone responder, so they can\'t be assigned.', 'trusted'),
                    $member
                )
                : sprintf(
                    /* translators: %s: member name typed in the template. */
                    __('No member is named "%s". Check the spelling of the anonymous name.', 'trusted'),
                    $member
                );

            // Tie the error to the day field so it shows next to the offending
            // textarea; this also blocks the save.
            acf_add_validation_error('acf[' . $fieldKey . ']', $message);
        }
    }

    /**
     * The submitted ACF field values, keyed by field key.
     *
     * Reads the raw $_POST payload ACF assembles before saving. We only inspect
     * it to validate member names; ACF itself performs the sanitised write.
     *
     * @return array<string, mixed>
     */
    private function submittedValues(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- ACF verifies its own nonce before this hook fires.
        $acf = $_POST['acf'] ?? null;

        return is_array($acf) ? $acf : [];
    }
}
