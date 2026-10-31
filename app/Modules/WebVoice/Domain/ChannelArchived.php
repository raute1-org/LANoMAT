<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Domain;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** The channel is archived; not even an admin may join or write there. Renders as HTTP 410. */
class ChannelArchived extends HttpException
{
    public function __construct()
    {
        parent::__construct(410, __('web_voice.errors.archived'));
    }
}
