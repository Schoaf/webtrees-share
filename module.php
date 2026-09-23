<?php

/**
 * webtrees-contribution-request - ask a relative to fill in missing data, without an account.
 *
 * Installation: copy this folder to modules_v4/webtrees-contribution-request. The webtrees core stays untouched.
 * The module class sits next to this file, its parts live under src/ - see the top of
 * WebtreesShareModule.php.
 */

declare(strict_types=1);

namespace WebtreesShare;

use function is_file;
use function spl_autoload_register;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';

    if (str_starts_with($class, $prefix)) {
        // Sub-namespaces (e.g. WebtreesShare\Migrations\Migration0) map to subdirectories -
        // the remaining "\" separators need converting to "/" for the filesystem path.
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file     = __DIR__ . '/src/' . $relative . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

require_once __DIR__ . '/WebtreesShareModule.php';

return new WebtreesShareModule();
