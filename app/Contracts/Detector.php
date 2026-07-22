<?php

namespace App\Contracts;

use App\Data\DetectorResult;
use App\Data\PageDocument;

interface Detector
{
    public function key(): string;

    /** @return list<DetectorResult> */
    public function detect(PageDocument $page): array;
}

