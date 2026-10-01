<?php

/** Delegates GET / to the home controller without implementing application logic. */

declare(strict_types=1);

use Ordinal\Controller\HomeController;

return (new HomeController())->showHome();
