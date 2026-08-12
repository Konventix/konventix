<?php

declare(strict_types=1);

use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Tax\TaxCalculator;

function calculator(): TaxCalculator
{
    return new TaxCalculator;
}

/**
 * @return list<array{label: string, quantity: int, unit_amount_net: string, tax_class: string}>
 */
function standardLine(string $unitNet = '30.00', int $quantity = 1, string $taxClass = 'standard'): array
{
    return [[
        'label' => 'Visitor ticket',
        'quantity' => $quantity,
        'unit_amount_net' => $unitNet,
        'tax_class' => $taxClass,
    ]];
}

it('applies the 19% standard rate for a domestic sale', function (): void {
    $result = calculator()->calculate(standardLine(), false, null, 'DE');

    expect($result->treatment)->toBe(TaxTreatment::Standard)
        ->and($result->lines[0]->taxRate)->toBe('19.00')
        ->and($result->lines[0]->lineNet)->toBe('30.00')
        ->and($result->lines[0]->lineTax)->toBe('5.70')
        ->and($result->lines[0]->lineGross)->toBe('35.70')
        ->and($result->totalNet)->toBe('30.00')
        ->and($result->totalTax)->toBe('5.70')
        ->and($result->totalGross)->toBe('35.70');
});

it('applies the 7% reduced rate for a reduced-class line', function (): void {
    $result = calculator()->calculate(standardLine('10.00', 1, 'reduced'), false, null, 'DE');

    expect($result->lines[0]->taxRate)->toBe('7.00')
        ->and($result->totalTax)->toBe('0.70')
        ->and($result->totalGross)->toBe('10.70');
});

it('rounds per line, half up, so totals equal the sum of printed lines', function (): void {
    // 3 × 9.99 = 29.97 net; 19% = 5.6943 → 5.69
    $result = calculator()->calculate(standardLine('9.99', 3), false, null, 'DE');

    expect($result->lines[0]->lineNet)->toBe('29.97')
        ->and($result->lines[0]->lineTax)->toBe('5.69')
        ->and($result->lines[0]->lineGross)->toBe('35.66')
        ->and($result->totalGross)->toBe('35.66');
});

it('applies reverse charge for a B2B billing party with a VAT ID in another EU member state', function (): void {
    $result = calculator()->calculate(standardLine('250.00'), false, 'ATU12345678', 'AT');

    expect($result->treatment)->toBe(TaxTreatment::ReverseCharge)
        ->and($result->lines[0]->taxRate)->toBe('0.00')
        ->and($result->totalTax)->toBe('0.00')
        ->and($result->totalGross)->toBe('250.00');
});

it('keeps domestic B2B sales at standard treatment even with a VAT ID', function (): void {
    $result = calculator()->calculate(standardLine('250.00'), false, 'DE123456789', 'DE');

    expect($result->treatment)->toBe(TaxTreatment::Standard)
        ->and($result->totalTax)->toBe('47.50');
});

it('keeps non-EU billing parties at standard treatment', function (): void {
    $result = calculator()->calculate(standardLine('250.00'), false, 'GB123456789', 'GB');

    expect($result->treatment)->toBe(TaxTreatment::Standard);
});

it('does not reverse-charge on a VAT ID without a billing country', function (): void {
    $result = calculator()->calculate(standardLine('250.00'), false, 'ATU12345678', null);

    expect($result->treatment)->toBe(TaxTreatment::Standard);
});

it('suppresses VAT entirely under the operator §19 UStG election, overriding reverse charge', function (): void {
    $result = calculator()->calculate(standardLine('250.00'), true, 'ATU12345678', 'AT');

    expect($result->treatment)->toBe(TaxTreatment::SmallBusiness)
        ->and($result->totalTax)->toBe('0.00')
        ->and($result->totalGross)->toBe('250.00');
});

it('sums mixed-class multi-line orders', function (): void {
    $result = calculator()->calculate([
        ['label' => 'Booth', 'quantity' => 1, 'unit_amount_net' => '250.00', 'tax_class' => 'standard'],
        ['label' => 'Catering', 'quantity' => 2, 'unit_amount_net' => '25.00', 'tax_class' => 'reduced'],
    ], false, null, 'DE');

    // 250.00 @19% = 47.50; 50.00 @7% = 3.50
    expect($result->totalNet)->toBe('300.00')
        ->and($result->totalTax)->toBe('51.00')
        ->and($result->totalGross)->toBe('351.00');
});
