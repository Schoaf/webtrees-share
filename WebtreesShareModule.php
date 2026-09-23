<?php

declare(strict_types=1);

namespace WebtreesShare;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\RequestHandlers\ModuleAction;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\MigrationService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function is_array;
use function is_string;
use function json_decode;
use function response;
use function route;

/**
 * Entry point of the module: metadata, menu, schema migration and small shared request helpers.
 *
 * All endpoints run through webtrees' built-in module route
 * /module/webtrees-share/<Action>[/<tree>] (index.php?route=... without URL rewriting).
 * There are deliberately no custom routes - the routing API changes between webtrees
 * versions, the module route stays.
 *
 * Split by concern:
 *   src/RequestPages.php     every action method (create, guest form, review, notifications, email)
 *   src/GedcomSnapshot.php   pure helpers: snapshot a person's key facts, apply accepted fields back
 *   src/Migrations/          database schema (one table)
 *
 * Independent of "webtreesand-api" - it does not read its classes or tables, so either module
 * can be enabled/disabled/updated without affecting the other. An app that wants the "ask a
 * relative" feature should check whether this module resolves (see getInfoAction) before
 * showing it, since it is a separate, optional module.
 */
class WebtreesShareModule extends AbstractModule implements ModuleCustomInterface, ModuleMenuInterface, ModuleGlobalInterface
{
    use ModuleCustomTrait;
    use ModuleMenuTrait;
    use RequestPages;

    public const string MODULE_NAME = 'webtrees-share';

    private const string SCHEMA_SETTING = 'webtreesshare_schema_version';
    private const int    SCHEMA_VERSION = 1;

    // How long a request link stays valid for.
    public const int REQUEST_LIFETIME_SECONDS = 2 * 24 * 60 * 60;

    // A generous cap on the guest-uploaded photo - PHP's own upload_max_filesize/post_max_size
    // apply before this code ever runs; this is just a second, explicit guard.
    public const int MAX_PHOTO_BYTES = 10 * 1024 * 1024;

    // Where an uploaded-but-not-yet-reviewed photo is held (webtrees' own "data" filesystem,
    // never the tree's real media folder - nothing is added to the tree until the requester accepts it).
    public const string PENDING_PHOTO_PATH = 'webtreesshare/pending-photos/';

    // Where a snapshot of the person's *existing* photo is held, so a signed-out guest can see
    // it without needing access to the tree's protected media folder. Same idea as
    // PENDING_PHOTO_PATH, kept separate so the two never collide or get confused for each other.
    public const string EXISTING_PHOTO_PATH = 'webtreesshare/existing-photos/';

    // The fixed set of facts a guest can see/complete. Keep this small and explicit - this is a
    // simple "fill in the basics" form, not a general fact editor. Three shapes, per 'kind':
    //   'subline'   - value sits on a level-2 line under a level-1 fact (tag+part), e.g. "2 DATE ..." under "1 BIRT".
    //   'value'     - value sits directly on the level-1 tag line itself, e.g. "1 TITL Dr.".
    //   'name_part' - a NAME sub-line (GIVN/SURN); reads like 'subline', but writing it also
    //                 rebuilds the primary "1 NAME ..." line so the display name stays consistent.
    public const array FIELDS = [
        'GIVN'      => ['kind' => 'name_part', 'tag' => 'NAME', 'part' => 'GIVN'],
        'SURN'      => ['kind' => 'name_part', 'tag' => 'NAME', 'part' => 'SURN'],
        'TITL'      => ['kind' => 'value', 'tag' => 'TITL', 'part' => null],
        'BIRT_DATE' => ['kind' => 'subline', 'tag' => 'BIRT', 'part' => 'DATE'],
        'BIRT_PLAC' => ['kind' => 'subline', 'tag' => 'BIRT', 'part' => 'PLAC'],
        'DEAT_DATE' => ['kind' => 'subline', 'tag' => 'DEAT', 'part' => 'DATE'],
        'DEAT_PLAC' => ['kind' => 'subline', 'tag' => 'DEAT', 'part' => 'PLAC'],
    ];

    public function __construct()
    {
        $this->setName(self::MODULE_NAME);
    }

    public function title(): string
    {
        return I18N::translate('Nach Informationen fragen');
    }

    public function description(): string
    {
        return I18N::translate('Bittet eine Person ohne Konto, Angaben zu einer einzelnen Person im Stammbaum zu ergänzen.');
    }

    public function customModuleAuthorName(): string
    {
        return 'Andreas Scharf';
    }

    public function customModuleVersion(): string
    {
        return '0.1.0';
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        Registry::container()->get(MigrationService::class)
            ->updateSchema('WebtreesShare\Migrations', self::SCHEMA_SETTING, self::SCHEMA_VERSION);
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    public function defaultMenuOrder(): int
    {
        return 100;
    }

    public function bodyContent(): string
    {
        return '';
    }

    /**
     * The "webtrees" theme draws every top-level menu icon via a `content: url(...)` rule keyed
     * to the menu's own CSS class (see e.g. .menu-tree, .menu-chart in webtrees.min.css) - a
     * custom module's menu class isn't in that stylesheet, so without this it would render with
     * no icon at all. A plain heart outline matches the app's own "ask for help" icon.
     */
    public function headContent(): string
    {
        // The "hand offering a heart" icon, matching the app's own icon for this same feature
        // (Icons.volunteer_activism_outlined) - path/viewBox is Google's Material Symbols
        // "volunteer_activism", 50x50 to match the other top-level menu icons' size (the
        // webtrees theme's own icons - see e.g. .menu-tree - render around that size; this
        // module's class isn't in that stylesheet at all, so without this it gets no icon).
        $icon = <<<'SVG'
data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='50' height='50' viewBox='0 -960 960 960' fill='%235d6779'%3E%3Cpath d='M640-440 474-602q-31-30-52.5-66.5T400-748q0-55 38.5-93.5T532-880q32 0 60 13.5t48 36.5q20-23 48-36.5t60-13.5q55 0 93.5 38.5T880-748q0 43-21 79.5T807-602L640-440Zm0-112 109-107q19-19 35-40.5t16-48.5q0-22-15-37t-37-15q-14 0-26.5 5.5T700-778l-60 72-60-72q-9-11-21.5-16.5T532-800q-22 0-37 15t-15 37q0 27 16 48.5t35 40.5l109 107ZM280-220l278 76 238-74q-5-9-14.5-15.5T760-240H558q-27 0-43-2t-33-8l-93-31 22-78 81 27q17 5 40 8t68 4q0-11-6.5-21T578-354l-234-86h-64v220ZM40-80v-440h304q7 0 14 1.5t13 3.5l235 87q33 12 53.5 42t20.5 66h80q50 0 85 33t35 87v40L560-60l-280-78v58H40Zm80-80h80v-280h-80v280Zm520-546Z'/%3E%3C/svg%3E
SVG;

        return '<style>.menu-webtreesshare .nav-link:before{content:url("' . trim($icon) . '")}</style>';
    }

    /**
     * Menu entry "Anfragen (N)" - only for signed-in users, and only once they have at
     * least one unread response, so the menu stays quiet otherwise.
     */
    public function getMenu(Tree $tree): Menu|null
    {
        if (!Auth::check()) {
            return null;
        }

        $unread = $this->unreadCount((int) Auth::id());

        if ($unread === 0) {
            return null;
        }

        $label = I18N::translate('Anfragen') . ' (' . $unread . ')';

        return new Menu($label, $this->actionUrl('RequestReview', $tree->name()), 'menu-webtreesshare', ['rel' => 'nofollow']);
    }

    /**
     * Build a URL to one of this module's own actions - same convention as webtreesand-api.
     *
     * @param array<string,scalar|null> $params
     */
    private function actionUrl(string $action, string|null $tree, array $params = []): string
    {
        $all = ['module' => $this->name(), 'action' => $action, 'tree' => $tree] + $params;

        try {
            return route('module', $all);
        } catch (Throwable) {
            return route(ModuleAction::class, $all);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        $json = (string) $request->getBody();

        if ($json !== '') {
            $decoded = json_decode($json, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * A JSON body's numeric fields (e.g. {"id": 42}) decode to a PHP int, not a string - the
     * Flutter app's JSON client sends `id` as a raw number, so a strict is_string() check here
     * silently fell through to $default (usually '0') and broke every id-based POST action
     * (RequestDelete, RequestApply, ...) coming from the app, not the web form (which always
     * posts strings). Numbers are accepted and stringified; anything else (array, bool, null)
     * still falls back to $default.
     *
     * @param array<string,mixed> $body
     */
    private function str(array $body, string $key, string $default = ''): string
    {
        $value = $body[$key] ?? $default;

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    private function error(int $status, string $code): ResponseInterface
    {
        return response(['ok' => false, 'error' => $code, 'status' => $status])->withStatus($status);
    }
}
