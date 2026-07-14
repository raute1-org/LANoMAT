<?php

namespace App\Modules\Registration\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrCode
{
    public function svg(string $token): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(256),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($token);
    }
}
