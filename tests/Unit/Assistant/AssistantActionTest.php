<?php

use App\Modules\Assistant\Enums\AssistantActionStatus;

it('only treats proposed as open', function () {
    expect(collect(AssistantActionStatus::cases())->filter->isOpen()->all())
        ->toBe([AssistantActionStatus::Proposed]);
});
