<?php

namespace App\Services;

use RuntimeException;

class CvPageOverflow extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ce CV dépasse une page A4. Raccourcis le brouillon ou réoptimise-le avant d’exporter le PDF.');
    }
}
