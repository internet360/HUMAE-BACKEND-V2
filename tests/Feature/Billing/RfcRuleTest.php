<?php

declare(strict_types=1);

use App\Rules\Rfc;
use Illuminate\Support\Facades\Validator;

function rfcPasses(string $value): bool
{
    return Validator::make(['rfc' => $value], ['rfc' => [new Rfc]])->passes();
}

it('accepts persona fisica (13) and persona moral (12) RFCs', function (string $rfc): void {
    expect(rfcPasses($rfc))->toBeTrue();
})->with([
    'fisica' => 'XXXX010101AAA',
    'fisica with ñ and digits in homoclave' => 'ÑAAA850315A1A',
    'moral' => 'XXX010101AA1',
    'moral with ampersand' => 'A&B010101AB9',
    'leap day' => 'XXXX000229AAA',
]);

it('rejects malformed RFCs', function (string $rfc): void {
    expect(rfcPasses($rfc))->toBeFalse();
})->with([
    'too short' => 'XXX0101AAA',
    'too long' => 'XXXX010101AAAA',
    'lowercase' => 'xxxx010101aaa',
    'month 13' => 'XXXX011301AAA',
    'day 32' => 'XXXX010132AAA',
    'april 31' => 'XXXX040431AAA',
    'letters in date' => 'XXXXAB0101AAA',
    'symbol' => 'XXXX010101AA*',
]);

it('rejects the generic public RFCs because a request carries real fiscal data', function (string $rfc): void {
    expect(rfcPasses($rfc))->toBeFalse();
})->with(['national' => 'XAXX010101000', 'foreign' => 'XEXX010101000']);

it('rejects non-string values', function (): void {
    expect(Validator::make(['rfc' => ['XXXX010101AAA']], ['rfc' => [new Rfc]])->passes())->toBeFalse();
});
