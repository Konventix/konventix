<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Illuminate\Validation\Rule;
use Konventix\Platform\Enums\DateFormat;
use Konventix\Platform\Enums\TimeFormat;
use Konventix\Platform\Locales\Locales;
use Konventix\Platform\Models\Operator;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The operator settings form (ADR-0009): one definition for validation, the
 * Inertia props and the TypeScript type.
 *
 * Distinct from `OperatorSellerData`, which is the *backend* contract handed
 * to Commerce and carries no `name` or `contact_email` because an invoice does
 * not need them. This is the editable surface; that is the read model. Merging
 * them would push console-only fields across a module boundary.
 *
 * `admin_host` is deliberately absent. Changing the host an operator answers
 * on from a page served on that host is a way to lock yourself out of the
 * console, and it belongs with provisioning rather than with settings.
 */
#[TypeScript]
final class OperatorSettingsData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $legalName,
        public readonly ?string $contactEmail,
        public readonly ?string $addressLine,
        public readonly ?string $postalCode,
        public readonly ?string $city,
        public readonly string $countryCode,
        public readonly ?string $vatId,
        public readonly ?string $taxNumber,
        public readonly bool $smallBusinessScheme,
        public readonly bool $signupsOpen,
        public readonly DateFormat $dateFormat,
        public readonly TimeFormat $timeFormat,
        public readonly string $locale,
    ) {}

    public static function ofModel(Operator $operator): self
    {
        return new self(
            name: $operator->name,
            legalName: $operator->legal_name,
            contactEmail: $operator->contact_email,
            addressLine: $operator->address_line,
            postalCode: $operator->postal_code,
            city: $operator->city,
            countryCode: $operator->country_code,
            vatId: $operator->vat_id,
            taxNumber: $operator->tax_number,
            smallBusinessScheme: $operator->small_business_scheme,
            signupsOpen: $operator->signups_open,
            dateFormat: DateFormat::tryFrom($operator->date_format) ?? DateFormat::DayMonthYear,
            timeFormat: TimeFormat::tryFrom($operator->time_format) ?? TimeFormat::TwentyFourHour,
            locale: $operator->locale,
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Required, and the reason is not tidiness: OperatorSettings
            // refuses to issue an invoice for a brand whose operator has no
            // legal identity, so an empty value here breaks settlement.
            'legalName' => ['required', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'addressLine' => ['nullable', 'string', 'max:255'],
            'postalCode' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:255'],
            // ISO 3166-1 alpha-2. ZUGFeRD expects exactly this, and a
            // free-text country is what makes an invoice fail validation at
            // the recipient rather than here.
            'countryCode' => ['required', 'string', 'size:2', 'alpha'],
            'vatId' => ['nullable', 'string', 'max:32'],
            'taxNumber' => ['nullable', 'string', 'max:32'],
            'smallBusinessScheme' => ['boolean'],
            // Whether strangers may create accounts (hotfix 2026-09-13).
            // Operator level: an account is the person's, and a signup on
            // one brand host is an account on every brand of the operator.
            'signupsOpen' => ['boolean'],
            // How this instance writes a date and a time (#242), operator
            // level because the invoice, the mail and the console table
            // behind one legal sender may not disagree about it.
            'dateFormat' => ['required', Rule::enum(DateFormat::class)],
            'timeFormat' => ['required', Rule::enum(TimeFormat::class)],
            // The bottom of the locale chain (ADR-0068 §4): every brand
            // without its own choice and every guest on it lands here, so
            // it may not be empty, and only a locale the instance ships.
            'locale' => ['required', Rule::in(app(Locales::class)->supported())],
        ];
    }
}
