<?php

declare(strict_types=1);

namespace App\Support;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class QrCodeDataUriGenerator
{
    public function generateSvgDataUri(string $value, int $scale = 6): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (! class_exists(QRCode::class) || ! class_exists(QROptions::class)) {
            return null;
        }

        $scale = max(4, min(12, $scale));

        try {
            $options = new QROptions([
                'outputType' => QRCode::OUTPUT_MARKUP_SVG,
                'eccLevel' => QRCode::ECC_M,
                'scale' => $scale,
            ]);

            $dataUri = (new QRCode($options))->render($value);
            if (! is_string($dataUri) || ! str_starts_with($dataUri, 'data:image/svg+xml')) {
                return null;
            }

            return $dataUri;
        } catch (\Throwable) {
            return null;
        }
    }
}

