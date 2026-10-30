<?php

use App\Modules\Registration\Support\TicketLabel;

it('maps known ticket types to German labels', function () {
    expect(TicketLabel::for('standard'))->toBe('Standard');
});

it('prettifies unknown ticket types', function () {
    expect(TicketLabel::for('early-bird'))->toBe('Early Bird')
        ->and(TicketLabel::for('vip_gold'))->toBe('Vip Gold');
});
