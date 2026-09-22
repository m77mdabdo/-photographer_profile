<?php

declare(strict_types=1);

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

return [
    // Swap to 'imagick' in production if the extension is available.
    'driver' => env('IMAGE_DRIVER', 'gd') === 'imagick' ? ImagickDriver::class : GdDriver::class,
];
