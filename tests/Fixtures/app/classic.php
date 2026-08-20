<?php

declare(strict_types=1);
// Executed once per request, front-controller style. Any path answers 200.
\header('content-type: text/plain');
echo 'classic: ', $_SERVER['REQUEST_METHOD'], ' ', $_SERVER['REQUEST_URI'], "\n";
