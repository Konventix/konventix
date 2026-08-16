<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Database\Factories\BookkeepingSettingsFactory;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Enums\TaxTreatment;

/**
 * Per-operator DATEV export settings (ADR-0040). Deliberately NOT
 * brand-owned: the operator is the taxpayer (D-COM-05), and one legal
 * entity gets one account mapping across all its brands. Defaults are the
 * SKR03 Automatikkonten; `forOperator()` hands back an unsaved defaulted
 * instance when nothing was configured, so exporting works before anyone
 * opens the settings form.
 *
 * @property string $id
 * @property string $operator_id
 * @property int|null $consultant_number
 * @property int|null $client_number
 * @property int $gl_account_length
 * @property string $debtor_account
 * @property string $revenue_standard
 * @property string $revenue_reduced
 * @property string $revenue_reverse_charge
 * @property string $revenue_small_business
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BookkeepingSettings extends Model
{
    /** @use HasFactory<BookkeepingSettingsFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'operator_id',
        'consultant_number',
        'client_number',
        'gl_account_length',
        'debtor_account',
        'revenue_standard',
        'revenue_reduced',
        'revenue_reverse_charge',
        'revenue_small_business',
    ];

    protected $attributes = [
        'gl_account_length' => 4,
        'debtor_account' => '10000',
        'revenue_standard' => '8400',
        'revenue_reduced' => '8300',
        'revenue_reverse_charge' => '8336',
        'revenue_small_business' => '8195',
    ];

    public static function forOperator(string $operatorId): self
    {
        return self::query()->where('operator_id', $operatorId)->first()
            ?? new self(['operator_id' => $operatorId]);
    }

    /**
     * The revenue account a document bucket posts against (ADR-0040):
     * treatment first — reverse charge and §19 override any declared
     * class — then the line's tax class.
     */
    public function revenueAccountFor(TaxTreatment $treatment, TaxClass $class): string
    {
        return match ($treatment) {
            TaxTreatment::ReverseCharge => $this->revenue_reverse_charge,
            TaxTreatment::SmallBusiness => $this->revenue_small_business,
            TaxTreatment::Standard => match ($class) {
                TaxClass::Standard => $this->revenue_standard,
                TaxClass::Reduced => $this->revenue_reduced,
            },
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consultant_number' => 'integer',
            'client_number' => 'integer',
            'gl_account_length' => 'integer',
        ];
    }

    protected static function newFactory(): BookkeepingSettingsFactory
    {
        return BookkeepingSettingsFactory::new();
    }
}
