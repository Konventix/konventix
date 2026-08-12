<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Platform\Database\Factories\OperatorFactory;

/**
 * The legal entity running this instance (ADR-0001). Exactly one row today —
 * initially Sund-Xplosion e.V. — but a row rather than config, so a future
 * multi-operator mode needs no schema archaeology.
 *
 * Identity, contact, and the operator's *legal* invoicing identity: postal
 * address, tax identifiers, and the §19 UStG small-business election, which
 * is operator-level by law (ADR-0030). Where money *flows* still belongs to
 * Commerce's settlement account model (ADR-0007), not here — this row only
 * says who an invoice is issued by, never where funds land.
 *
 * @property string $id
 * @property string $name
 * @property string $legal_name
 * @property string|null $contact_email
 * @property string|null $admin_host
 * @property string|null $address_line
 * @property string|null $postal_code
 * @property string|null $city
 * @property string $country_code
 * @property string|null $vat_id
 * @property string|null $tax_number
 * @property bool $small_business_scheme
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Operator extends Model
{
    /** @use HasFactory<OperatorFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'legal_name',
        'contact_email',
        'address_line',
        'postal_code',
        'city',
        'country_code',
        'vat_id',
        'tax_number',
        'small_business_scheme',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'small_business_scheme' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Brand, $this>
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    protected static function newFactory(): OperatorFactory
    {
        return OperatorFactory::new();
    }
}
