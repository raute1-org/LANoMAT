<?php

use App\Modules\Registration\Support\PaymentLink;
use App\Modules\Registration\Support\PaymentReference;

it('formats odd cent amounts', function (int $cents, string $expected) {
    expect(PaymentLink::formatAmount($cents))->toBe($expected);
})->with([[2500, '25'], [2550, '25.5'], [2505, '25.05'], [1, '0.01'], [900, '9']]);

it('appends the amount to paypal.me links', function (string $link, int $cents, ?string $expected) {
    expect(PaymentLink::for($link, $cents))->toBe($expected);
})->with([
    'plain' => ['https://paypal.me/example-orga', 2500, 'https://paypal.me/example-orga/25EUR'],
    'trailing slash and www' => ['https://www.paypal.me/example-orga/', 2500, 'https://www.paypal.me/example-orga/25EUR'],
    'amount and query replaced' => ['https://paypal.me/example-orga/10?locale.x=de_DE', 2500, 'https://paypal.me/example-orga/25EUR'],
    'existing EUR amount' => ['https://paypal.me/example-orga/10EUR', 900, 'https://paypal.me/example-orga/9EUR'],
    'odd cents' => ['https://paypal.me/example-orga', 2505, 'https://paypal.me/example-orga/25.05EUR'],
    'pool unchanged' => ['https://www.paypal.com/pool/example', 2500, 'https://www.paypal.com/pool/example'],
    'http rejected' => ['http://paypal.me/x', 2500, null],
    'zero amount' => ['https://paypal.me/example-orga', 0, null],
]);

it('returns null for empty links and validates https and length', function () {
    expect(PaymentLink::for(null, 2500))->toBeNull()
        ->and(PaymentLink::for('', 2500))->toBeNull()
        ->and(PaymentLink::isValid('http://paypal.me/x'))->toBeFalse()
        ->and(PaymentLink::isValid('https://paypal.me/example-orga'))->toBeTrue()
        ->and(PaymentLink::isValid('https://example.org/'.str_repeat('a', 250)))->toBeFalse()
        ->and(PaymentLink::isValid(null))->toBeFalse();
});

it('builds the reference and truncates the event name first', function () {
    expect(PaymentReference::for('LAN 26', 'Alex', 'Standard'))->toBe('LAN 26 · Alex · Standard');

    $long = PaymentReference::for(str_repeat('E', 200), 'Alex', 'Standard');

    expect(mb_strlen($long))->toBeLessThanOrEqual(140)
        ->and($long)->toEndWith(' · Alex · Standard');
});
