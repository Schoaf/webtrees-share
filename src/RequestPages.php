<?php

declare(strict_types=1);

namespace WebtreesShare;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Enums\Restriction;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\GuestUser;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\EmailService;
use Fisharebest\Webtrees\Services\MediaFileService;
use Fisharebest\Webtrees\Services\PendingChangesService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\SiteUser;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\TreeUser;
use Fisharebest\Webtrees\Validator;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

use function date;
use function e;
use function getimagesizefromstring;
use function http_build_query;
use function image_type_to_extension;
use function json_decode;
use function json_encode;
use function nl2br;
use function pathinfo;
use function redirect;
use function response;
use function sha1;
use function strip_tags;
use function strtotime;
use function time;
use function usort;

use const JSON_THROW_ON_ERROR;
use const PATHINFO_EXTENSION;
use const UPLOAD_ERR_OK;

/**
 * Every action method of this module (get.../post...), plus the private helpers they share.
 * See WebtreesShareModule.php for the overall shape (one table, no dependency on webtreesand-api).
 */
trait RequestPages
{
    /**
     * Lets an app (or anything else) check whether this module is installed and enabled
     * before showing the "ask a relative" feature, since it's a separate, optional module.
     */
    public function getInfoAction(ServerRequestInterface $request): ResponseInterface
    {
        return response([
            'active'  => true,
            'version' => $this->customModuleVersion(),
        ]);
    }

    /**
     * Snapshot a person's key facts and create a new request for them. Authenticated: only
     * an editor who can already edit this exact record may start a request about it.
     */
    public function postCreateRequestAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree       = Validator::attributes($request)->tree();
        $body       = $this->body($request);
        $xref       = $this->str($body, 'xref');
        $individual = $xref === '' ? null : Registry::individualFactory()->make($xref, $tree);

        $denied = $this->denyRequestCreate($individual);

        if ($denied !== null) {
            return $denied;
        }

        $row = $this->createRequestRow($tree, $individual, Auth::user());

        return response([
            'ok'      => true,
            'url'     => $this->requestUrl($tree, $row['token']),
            'expires' => $row['expires_at'],
        ]);
    }

    /**
     * The canonical subject/body for the "please help" email, with the personal-note portion
     * left as a marker the caller fills in - either for a live send (postSendRequestEmailAction)
     * or to preview it before sending.
     */
    public function getRequestEmailTemplateAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree  = Validator::attributes($request)->tree();
        $token = Validator::queryParams($request)->string('token', '');
        $row   = $this->findByToken($token);

        if ($row === null || (int) $row->gedcom_id !== $tree->id() || (int) $row->creator_user_id !== (int) Auth::id()) {
            return $this->error(404, 'not-found');
        }

        $data = json_decode($row->request_data, true);

        return response([
            'subject' => $this->requestEmailSubject($data['name'] ?? ''),
            'body'    => $this->requestEmailBody(
                Auth::user()->realName(),
                $data['name'] ?? '',
                $this->individualCount($tree),
                $this->requestUrl($tree, $token),
                '{{PERSONAL_MESSAGE}}',
                '',
            ),
        ]);
    }

    /**
     * Actually send the request by email. The client supplies the recipient and an optional
     * personal note only - subject/body are always re-rendered here, never trusted from the client.
     */
    public function postSendRequestEmailAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree  = Validator::attributes($request)->tree();
        $body  = $this->body($request);
        $token = $this->str($body, 'token');
        $row   = $this->findByToken($token);

        if ($row === null || (int) $row->gedcom_id !== $tree->id() || (int) $row->creator_user_id !== (int) Auth::id()) {
            return $this->error(404, 'not-found');
        }

        $recipient_email = $this->str($body, 'recipient_email');
        $recipient_name  = $this->str($body, 'recipient_name');
        $personal        = GedcomSnapshot::line($this->str($body, 'personal_message'));

        if ($recipient_email === '') {
            return $this->error(422, 'missing-recipient');
        }

        $data       = json_decode($row->request_data, true);
        $email_body = $this->requestEmailBody(
            Auth::user()->realName(),
            $data['name'] ?? '',
            $this->individualCount($tree),
            $this->requestUrl($tree, $token),
            $personal,
            $recipient_name,
        );

        // From: webtrees' own configured sender, never the requester's personal address - an
        // email whose From: doesn't match the domain it's actually relayed through fails
        // SPF/DKIM and gets blocked by the recipient's mail provider. Reply-To stays the
        // requester, so a reply still reaches them directly.
        $sent = Registry::container()->get(EmailService::class)->send(
            new SiteUser(),
            new GuestUser($recipient_email, $recipient_name !== '' ? $recipient_name : $recipient_email),
            Auth::user(),
            $this->requestEmailSubject($data['name'] ?? ''),
            $email_body,
            nl2br(e($email_body)),
        );

        if (!$sent) {
            return $this->error(500, 'send-failed');
        }

        return response(['ok' => true]);
    }

    /**
     * The guest-facing form: built entirely from the frozen request_data snapshot, never a
     * live lookup of the record - this is what keeps the token check below so simple.
     */
    public function getRequestAction(ServerRequestInterface $request): ResponseInterface
    {
        // No webtrees site menu/header for a guest with no account - see layouts/guest.phtml.
        $this->layout = $this->name() . '::layouts/guest';
        $tree  = Validator::attributes($request)->tree();
        $token = Validator::queryParams($request)->string('token', '');
        $row   = $this->findByToken($token);

        if ($row === null || (int) $row->gedcom_id !== $tree->id() || $this->isExpired($row)) {
            return $this->viewResponse($this->name() . '::request-invalid', [
                'title' => I18N::translate('Link nicht mehr gültig'),
                'tree'  => $tree,
            ]);
        }

        if ($row->status !== 'pending') {
            return $this->viewResponse($this->name() . '::request-answered', [
                'title'        => I18N::translate('Bereits beantwortet'),
                'tree'         => $tree,
                'register_url' => Site::getPreference('USE_REGISTRATION_MODULE') === '1'
                    ? $this->coreUrl($request, '/register/' . $tree->name())
                    : '',
            ]);
        }

        $data       = json_decode($row->request_data, true);
        $photo      = $data['photo'] ?? '';

        return $this->viewResponse($this->name() . '::request', [
            'title'           => I18N::translate('Angaben ergänzen'),
            'tree'            => $tree,
            'tree_title'      => $tree->title(),
            'tree_url'        => $this->treeUrl($request, $tree),
            'requester_name'  => $data['requester_name'] ?? '',
            'token'           => $token,
            'name'            => $data['name'] ?? '',
            'fields'          => $data['fields'] ?? [],
            'context'         => $data['context'] ?? [],
            'photo_url'       => $photo !== '' ? $this->actionUrl('RequestExistingPhoto', $tree->name(), ['token' => $token]) : '',
            'app_url'         => $this->deepLinkUrl($request, $tree, $token),
            'action'          => $this->actionUrl('RequestSubmit', $tree->name()),
        ]);
    }

    /**
     * The guest's submission. Written into the same row as response_data - nothing here ever
     * touches the tree's GEDCOM; that only happens later, when the creator reviews and saves.
     */
    public function postRequestSubmitAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = $this->name() . '::layouts/guest';
        $tree  = Validator::attributes($request)->tree();
        $body  = $this->body($request);
        $token = $this->str($body, 'token');
        $row   = $this->findByToken($token);

        if ($row === null || (int) $row->gedcom_id !== $tree->id() || $this->isExpired($row)) {
            return $this->viewResponse($this->name() . '::request-invalid', [
                'title' => I18N::translate('Link nicht mehr gültig'),
                'tree'  => $tree,
            ]);
        }

        $fields = [];

        foreach (WebtreesShareModule::FIELDS as $key => $definition) {
            $raw = $this->str($body, $key);

            // A conforming <input type="date"> submits "YYYY-MM-DD"; fall back to treating it
            // as already-GEDCOM-ish free text (e.g. a browser without date-picker support, or a
            // value round-tripped from a date the picker couldn't represent in the first place).
            $fields[$key] = ($definition['part'] ?? null) === 'DATE'
                ? (GedcomSnapshot::isoDateToGedcom($raw) ?: GedcomSnapshot::line($raw))
                : GedcomSnapshot::line($raw);
        }

        $response_data = [
            'fields'          => $fields,
            'note'            => GedcomSnapshot::line($this->str($body, 'note')),
            'photo'           => $this->storePendingPhoto($request->getUploadedFiles()['photo'] ?? null),
            'responder_name'  => GedcomSnapshot::line($this->str($body, 'responder_name')),
            'responder_email' => GedcomSnapshot::line($this->str($body, 'responder_email')),
        ];

        DB::table('webtreesshare_request')
            ->where('id', '=', $row->id)
            ->update([
                'response_data' => json_encode($response_data, JSON_THROW_ON_ERROR),
                'status'        => 'answered',
                'responded_at'  => date('Y-m-d H:i:s'),
            ]);

        $this->notifyCreatorOfResponse($tree, $row, $response_data);

        // Frozen at CreateRequest time by the authenticated creator, same as every other field
        // of the snapshot - deliberately NOT computed here via a live Individual/family-tree
        // lookup, which would mean an anonymous guest's own submission triggers unauthenticated
        // tree traversal (and could surface relatives' names that were never part of what the
        // creator actually chose to share via this link).
        $request_data = json_decode($row->request_data, true);
        $suggestions  = $request_data['suggestions'] ?? [];

        return $this->viewResponse($this->name() . '::request-thanks', [
            'title'           => I18N::translate('Danke!'),
            'tree'            => $tree,
            'tree_url'        => $this->treeUrl($request, $tree),
            'requester_name'  => $request_data['requester_name'] ?? '',
            'suggestions'     => $suggestions,
            'token'           => $token,
            'continue_action' => $this->actionUrl('RequestContinue', $tree->name()),
        ]);
    }

    /**
     * A guest picks a suggested relative from the thank-you page: create a fresh request for
     * them under the same creator, and send the guest straight into a new getRequestAction -
     * no extra app-side step needed to keep going.
     *
     * This whole action runs with no session at all (the guest is anonymous), but the new
     * request gets attributed to the *original* creator - so every permission check here has to
     * be evaluated for that creator specifically, never for "whoever is currently logged in"
     * (nobody is). Two layers, deliberately redundant:
     *   1. $xref must be one the creator's own session already vetted as a suggestion when the
     *      *original* request was created (relativeSuggestions(), run under their session at the
     *      time) - a guest can't submit an arbitrary xref they typed into the request body.
     *   2. Even so, re-check canShow()/canEdit() for the creator now, in case tree permissions
     *      or the record itself changed since that snapshot was taken.
     */
    public function postRequestContinueAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $body = $this->body($request);
        $row  = $this->findByToken($this->str($body, 'token'));

        if ($row === null || (int) $row->gedcom_id !== $tree->id()) {
            return $this->error(404, 'not-found');
        }

        $creator = Registry::container()->get(UserService::class)->find((int) $row->creator_user_id);

        if ($creator === null) {
            return $this->error(404, 'not-found');
        }

        $xref = $this->str($body, 'xref');

        $request_data  = json_decode($row->request_data, true);
        $allowed_xrefs = array_column($request_data['suggestions'] ?? [], 'xref');

        if ($xref === '' || !in_array($xref, $allowed_xrefs, true)) {
            return $this->error(404, 'not-found');
        }

        $individual = Registry::individualFactory()->make($xref, $tree);

        if (!$individual instanceof Individual || !$this->creatorCanRequestFor($individual, $creator)) {
            return $this->error(404, 'not-found');
        }

        $new_row = $this->createRequestRow($tree, $individual, $creator);

        return redirect($this->requestUrl($tree, $new_row['token']));
    }

    /**
     * With no ?id=, lists the creator's own answered/applied requests. With ?id=, shows one
     * request's old-vs-submitted comparison for review.
     */
    public function getRequestReviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $id   = Validator::queryParams($request)->integer('id', 0);

        if ($id === 0) {
            $rows = DB::table('webtreesshare_request')
                ->where('gedcom_id', '=', $tree->id())
                ->where('creator_user_id', '=', (int) Auth::id())
                ->whereIn('status', ['answered', 'applied'])
                ->orderByDesc('responded_at')
                ->get();

            $urls      = [];
            $responders = [];

            foreach ($rows as $row) {
                $urls[$row->id]       = $this->actionUrl('RequestReview', $tree->name(), ['id' => $row->id]);
                $responders[$row->id] = $this->responderLabel(json_decode($row->response_data ?? '{}', true) ?: []);
            }

            return $this->viewResponse($this->name() . '::request-review-list', [
                'title'      => I18N::translate('Anfragen'),
                'tree'       => $tree,
                'rows'       => $rows,
                'names'      => $this->namesFor($tree, $rows),
                'urls'       => $urls,
                'responders' => $responders,
            ]);
        }

        $row = DB::table('webtreesshare_request')->where('id', '=', $id)->first();

        if ($row === null || (int) $row->gedcom_id !== $tree->id()) {
            return $this->error(404, 'not-found');
        }

        if ((int) $row->creator_user_id !== (int) Auth::id()) {
            // Most likely cause: this is the "you got a response" email's link, opened in a
            // browser/device with no active webtrees session - send them to log in and straight
            // back here, rather than a bare 404 for what's actually just "please sign in".
            if (Auth::id() === null) {
                $return_url = $this->actionUrl('RequestReview', $tree->name(), ['id' => $id]);

                return redirect($this->loginUrl($request, $tree, $return_url));
            }

            return $this->error(404, 'not-found');
        }

        $individual    = Registry::individualFactory()->make($row->xref, $tree);
        $request_data  = json_decode($row->request_data, true);
        $response_data = json_decode($row->response_data ?? '{}', true) ?: [];

        $compare = [];

        foreach (WebtreesShareModule::FIELDS as $key => $definition) {
            $before = $request_data['fields'][$key] ?? '';
            $after  = $response_data['fields'][$key] ?? '';

            if ($after !== '' && !$this->fieldsAreEquivalent($definition, $before, $after)) {
                $compare[$key] = ['before' => $before, 'after' => $after];
            }
        }

        $photo = $response_data['photo'] ?? '';

        return $this->viewResponse($this->name() . '::request-review', [
            'title'     => I18N::translate('Antwort prüfen'),
            'tree'      => $tree,
            'row'       => $row,
            'name'      => $individual instanceof Individual ? strip_tags($individual->fullName()) : ($request_data['name'] ?? $row->xref),
            'responder' => $this->responderLabel($response_data),
            'compare'   => $compare,
            'note'      => $response_data['note'] ?? '',
            'applied'   => $row->status === 'applied',
            'action'    => $this->actionUrl('RequestReview', $tree->name()),
            'photo_url' => $photo !== '' ? $this->actionUrl('RequestPhoto', $tree->name(), ['id' => $row->id]) : '',
        ]);
    }

    /**
     * Streams a not-yet-reviewed photo to the requester only - it never becomes web-accessible
     * more broadly than that, since it isn't part of the tree's media library yet.
     */
    public function getRequestPhotoAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $id   = Validator::queryParams($request)->integer('id', 0);
        $row  = DB::table('webtreesshare_request')->where('id', '=', $id)->first();

        if ($row === null || (int) $row->creator_user_id !== (int) Auth::id() || (int) $row->gedcom_id !== $tree->id()) {
            return $this->error(404, 'not-found');
        }

        $response_data = json_decode($row->response_data ?? '{}', true) ?: [];
        $photo         = $response_data['photo'] ?? '';
        $filesystem    = $this->pendingPhotoFilesystem();

        if ($photo === '' || !$filesystem->fileExists($photo)) {
            return $this->error(404, 'not-found');
        }

        $extension  = pathinfo($photo, PATHINFO_EXTENSION);
        $mime_types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];

        return response($filesystem->read($photo), 200, [
            'content-type' => $mime_types[$extension] ?? 'application/octet-stream',
        ]);
    }

    /**
     * Streams the person's *existing* photo snapshot to whoever holds the request token - the
     * guest has no session and can't otherwise reach the tree's protected media folder, so this
     * serves the copy taken at CreateRequest time instead of the live file.
     */
    public function getRequestExistingPhotoAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree  = Validator::attributes($request)->tree();
        $token = Validator::queryParams($request)->string('token', '');
        $row   = $this->findByToken($token);

        if ($row === null || (int) $row->gedcom_id !== $tree->id() || $this->isExpired($row)) {
            return $this->error(404, 'not-found');
        }

        $data       = json_decode($row->request_data, true);
        $photo      = $data['photo'] ?? '';
        $filesystem = $this->existingPhotoFilesystem();

        if ($photo === '' || !$filesystem->fileExists($photo)) {
            return $this->error(404, 'not-found');
        }

        $extension  = pathinfo($photo, PATHINFO_EXTENSION);
        $mime_types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];

        return response($filesystem->read($photo), 200, [
            'content-type' => $mime_types[$extension] ?? 'application/octet-stream',
        ]);
    }

    /**
     * Apply the accepted fields as a normal edit, under the creator's own session - exactly
     * as if they had received the information by phone and typed it in themselves.
     */
    public function postRequestReviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $body = $this->body($request);
        $id   = (int) $this->str($body, 'id', '0');

        $row = DB::table('webtreesshare_request')->where('id', '=', $id)->first();

        if ($row === null || (int) $row->creator_user_id !== (int) Auth::id() || (int) $row->gedcom_id !== $tree->id()) {
            return $this->error(404, 'not-found');
        }

        $individual = Registry::individualFactory()->make($row->xref, $tree);

        if (!$individual instanceof Individual || !Auth::isEditor($tree) || !$individual->canEdit()) {
            return $this->error(403, 'not-editable');
        }

        $response_data = json_decode($row->response_data ?? '{}', true) ?: [];
        $accept        = $body['accept'] ?? [];

        $this->applyAcceptedResponse($tree, $individual, $response_data, $accept);

        DB::table('webtreesshare_request')
            ->where('id', '=', $id)
            ->update(['status' => 'applied', 'applied_at' => date('Y-m-d H:i:s')]);

        // Andreas: after accepting one response, jump straight to the next one waiting for
        // review instead of showing the just-applied page again - the list is where
        // "nothing left" naturally shows.
        $next_id = $this->nextPendingRequestId($tree, (int) Auth::id(), $id);
        $params  = $next_id !== null ? ['id' => $next_id] : [];

        return redirect($this->actionUrl('RequestReview', $tree->name(), $params));
    }

    /**
     * JSON list of the creator's own answered/applied requests, for the app's native "Antworten"
     * screen - same rows as getRequestReviewAction's id=0 (HTML) branch.
     */
    public function getRequestListAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        $rows = DB::table('webtreesshare_request')
            ->where('gedcom_id', '=', $tree->id())
            ->where('creator_user_id', '=', (int) Auth::id())
            ->whereIn('status', ['answered', 'applied'])
            ->orderByDesc('responded_at')
            ->get();

        $names = $this->namesFor($tree, $rows);

        $requests = [];

        foreach ($rows as $row) {
            $requests[] = [
                'id'          => (int) $row->id,
                'xref'        => $row->xref,
                'name'        => $names[$row->id] ?? $row->xref,
                'status'      => $row->status,
                'respondedAt' => $row->responded_at,
                'responder'   => $this->responderLabel(json_decode($row->response_data ?? '{}', true) ?: []),
            ];
        }

        return response(['requests' => $requests]);
    }

    /**
     * JSON detail for one request - the app's equivalent of getRequestReviewAction's id!=0
     * (HTML) branch, same data, same access rules.
     */
    public function getRequestDetailAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $id   = Validator::queryParams($request)->integer('id', 0);

        $row = DB::table('webtreesshare_request')->where('id', '=', $id)->first();

        if ($row === null || (int) $row->creator_user_id !== (int) Auth::id() || (int) $row->gedcom_id !== $tree->id()) {
            return $this->error(404, 'not-found');
        }

        $individual    = Registry::individualFactory()->make($row->xref, $tree);
        $request_data  = json_decode($row->request_data, true);
        $response_data = json_decode($row->response_data ?? '{}', true) ?: [];

        $compare = [];

        foreach (WebtreesShareModule::FIELDS as $key => $definition) {
            $before = $request_data['fields'][$key] ?? '';
            $after  = $response_data['fields'][$key] ?? '';

            if ($after !== '' && !$this->fieldsAreEquivalent($definition, $before, $after)) {
                $compare[$key] = ['before' => $before, 'after' => $after];
            }
        }

        $photo = $response_data['photo'] ?? '';

        return response([
            'id'        => (int) $row->id,
            'name'      => $individual instanceof Individual ? strip_tags($individual->fullName()) : ($request_data['name'] ?? $row->xref),
            'responder' => $this->responderLabel($response_data),
            'applied'   => $row->status === 'applied',
            // PHP serializes an empty array as JSON "[]", never "{}", even though this is
            // conceptually a map - a request with no field changes (only a note, say) would
            // otherwise hand the app a JSON array where it expects an object.
            'compare'   => $compare === [] ? (object) [] : $compare,
            'note'      => $response_data['note'] ?? '',
            'photoUrl'  => $photo !== '' ? $this->actionUrl('RequestPhoto', $tree->name(), ['id' => $row->id]) : '',
        ]);
    }

    /**
     * JSON equivalent of postRequestReviewAction, for the app's native "Antworten" screen -
     * same access rules and apply logic, returns {ok, nextId} instead of a redirect so the app
     * can jump straight to the next pending request itself.
     */
    public function postRequestApplyAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $body = $this->body($request);
        $id   = (int) $this->str($body, 'id', '0');

        $row = DB::table('webtreesshare_request')->where('id', '=', $id)->first();

        if ($row === null || (int) $row->creator_user_id !== (int) Auth::id() || (int) $row->gedcom_id !== $tree->id()) {
            return $this->error(404, 'not-found');
        }

        $individual = Registry::individualFactory()->make($row->xref, $tree);

        if (!$individual instanceof Individual || !Auth::isEditor($tree) || !$individual->canEdit()) {
            return $this->error(403, 'not-editable');
        }

        $response_data = json_decode($row->response_data ?? '{}', true) ?: [];
        $accept        = is_array($body['accept'] ?? null) ? $body['accept'] : [];

        $this->applyAcceptedResponse($tree, $individual, $response_data, $accept);

        DB::table('webtreesshare_request')
            ->where('id', '=', $id)
            ->update(['status' => 'applied', 'applied_at' => date('Y-m-d H:i:s')]);

        return response([
            'ok'     => true,
            'nextId' => $this->nextPendingRequestId($tree, (int) Auth::id(), $id),
        ]);
    }

    /**
     * Who answered, if they said so - a guest is never required to give their name/email, so
     * this is frequently blank. Same fallback notifyCreatorOfResponse() already uses.
     *
     * @param array<string,mixed> $response_data
     */
    private function responderLabel(array $response_data): string
    {
        $name = (string) ($response_data['responder_name'] ?? '');

        return $name !== '' ? $name : (string) ($response_data['responder_email'] ?? '');
    }

    /**
     * Shared by postRequestReviewAction (HTML) and postRequestApplyAction (JSON) - applies
     * exactly the fields/note/photo the creator ticked, under their own session, exactly as if
     * they had received the information by phone and typed it in themselves.
     *
     * @param array<string,mixed> $response_data
     * @param array<string,mixed> $accept
     */
    private function applyAcceptedResponse(Tree $tree, Individual $individual, array $response_data, array $accept): void
    {
        foreach (WebtreesShareModule::FIELDS as $key => $definition) {
            $value = $response_data['fields'][$key] ?? '';

            if ($value !== '' && !empty($accept[$key])) {
                GedcomSnapshot::applyField($individual, $definition, $value);
            }
        }

        $note = GedcomSnapshot::line((string) ($response_data['note'] ?? ''));

        if ($note !== '' && !empty($accept['note'])) {
            $individual->createFact('1 NOTE ' . $note, true);
        }

        $photo = (string) ($response_data['photo'] ?? '');

        if ($photo !== '' && !empty($accept['photo'])) {
            $this->applyPendingPhoto($tree, $individual, $photo);
        }
    }

    /**
     * The next request still waiting for review, oldest first - same order as the list.
     */
    private function nextPendingRequestId(Tree $tree, int $creator_id, int $exclude_id): int|null
    {
        $row = DB::table('webtreesshare_request')
            ->where('gedcom_id', '=', $tree->id())
            ->where('creator_user_id', '=', $creator_id)
            ->where('status', '=', 'answered')
            ->where('id', '!=', $exclude_id)
            ->orderBy('responded_at')
            ->first();

        return $row === null ? null : (int) $row->id;
    }

    /**
     * For the app to poll for its own notification badge.
     */
    public function getRequestNotificationsAction(ServerRequestInterface $request): ResponseInterface
    {
        return response(['unread' => $this->unreadCount((int) Auth::id())]);
    }

    // -----------------------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------------------

    private function denyRequestCreate(Individual|null $individual): ResponseInterface|null
    {
        if ($individual === null) {
            return $this->error(404, 'not-found');
        }

        if (!$individual->canShow()) {
            return $this->error(403, 'private');
        }

        if (!$this->creatorCanRequestFor($individual, Auth::user())) {
            return $this->error(403, 'not-editable');
        }

        return null;
    }

    /**
     * Whether $user could legitimately start (or continue) a share request for $individual -
     * canShow() at their own access level, plus the same editor/lock bar Individual::canEdit()
     * enforces. Individual::canEdit() itself always checks the *current session* with no way to
     * name a different user, which is exactly wrong for postRequestContinueAction: that action
     * runs as the anonymous guest, but the request it creates is attributed to (and must respect
     * the rights of) the *original* creator, never the guest's own session.
     */
    private function creatorCanRequestFor(Individual $individual, UserInterface $user): bool
    {
        $tree = $individual->tree();

        if (!$individual->canShow(Auth::accessLevel($tree, $user))) {
            return false;
        }

        if ($individual->isPendingDeletion()) {
            return false;
        }

        if (Auth::isManager($tree, $user)) {
            return true;
        }

        $fact   = $individual->facts(['RESN'])->first();
        $locked = $fact instanceof Fact && Restriction::fromString($fact->value())->isLocked();

        return Auth::isEditor($tree, $user) && !$locked;
    }

    /**
     * @return array{token: string, expires_at: string}
     */
    private function createRequestRow(Tree $tree, Individual $individual, UserInterface $creator): array
    {
        $data = [
            // Plain text: this feeds both HTML views (which then e() it) and plain-text emails
            // (requestEmailBody()) - fullName() itself returns pre-formatted HTML (<span class="NAME">...).
            'name'            => strip_tags($individual->fullName()),
            'requester_name'  => $creator->realName(),
            'fields'          => GedcomSnapshot::fields($individual),
            'context'         => GedcomSnapshot::context($individual),
            'photo'           => $this->snapshotExistingPhoto($tree, $individual),
            // Computed here, under the creator's own rights (explicitly, not via the current
            // session - see creatorCanRequestFor()), for the same reason every other field above
            // is a frozen snapshot: neither the guest's initial view nor their eventual
            // "continue with a relative" pick should ever need to walk live family-tree
            // relationships, or trust an xref the guest could have tampered with, themselves.
            'suggestions'     => $this->relativeSuggestions($individual, $creator),
        ];

        $token      = Str::random(32);
        $now        = time();
        $expires_at = date('Y-m-d H:i:s', $now + WebtreesShareModule::REQUEST_LIFETIME_SECONDS);

        DB::table('webtreesshare_request')->insert([
            'gedcom_id'       => $tree->id(),
            'xref'            => $individual->xref(),
            'creator_user_id' => $creator->id(),
            'token'           => $token,
            'request_data'    => json_encode($data, JSON_THROW_ON_ERROR),
            'status'          => 'pending',
            'created_at'      => date('Y-m-d H:i:s', $now),
            'expires_at'      => $expires_at,
        ]);

        return ['token' => $token, 'expires_at' => $expires_at];
    }

    private function findByToken(string $token): object|null
    {
        if ($token === '') {
            return null;
        }

        return DB::table('webtreesshare_request')->where('token', '=', $token)->first();
    }

    private function isExpired(object $row): bool
    {
        return strtotime($row->expires_at) < time();
    }

    private function requestUrl(Tree $tree, string $token): string
    {
        return $this->actionUrl('Request', $tree->name(), ['token' => $token]);
    }

    /**
     * Whether a field's "before" and "after" values represent the same thing, for deciding what
     * the review page even needs to show - a plain string compare isn't enough for a DATE
     * sub-line: isoDateToGedcom() never zero-pads the day ("5 JAN 1983"), so a guest who left a
     * date picker showing an unchanged "05 JAN 1983" round-trips to a technically-different
     * string despite not having changed anything. Only DATE fields need this; every other kind
     * already compares correctly as plain strings.
     */
    private function fieldsAreEquivalent(array $definition, string $before, string $after): bool
    {
        if ($before === $after) {
            return true;
        }

        if (($definition['part'] ?? null) !== 'DATE') {
            return false;
        }

        $before_iso = GedcomSnapshot::gedcomDateToIso($before);
        $after_iso  = GedcomSnapshot::gedcomDateToIso($after);

        return $before_iso !== '' && $before_iso === $after_iso;
    }

    /**
     * Built by hand rather than via route(TreePage::class, ...) - see loginUrl()'s docblock,
     * same reasoning: route(<FQCN>, ...) proved unreliable on production (2.2.6), so every
     * core-page URL this module builds is a plain, verified-working "/tree/<name>" path instead.
     */
    private function treeUrl(ServerRequestInterface $request, Tree $tree): string
    {
        return $this->coreUrl($request, '/tree/' . $tree->name());
    }

    /**
     * Built by hand rather than via route(Login::class, ...) - that threw "route not found" on
     * production (2.2.6): Aura's route map apparently doesn't have Login registered under its
     * FQCN there, only under some other name webtrees' own core templates use internally. Format
     * confirmed by inspecting a real webtrees-generated login link on a live page:
     * "/login/<tree>?url=<return-url>".
     */
    /**
     * $return_url must be a URL this module itself built (e.g. via actionUrl()), never
     * $request->getUri() - webtrees' own BaseUrl middleware rewrites the request's URI path to
     * strip "index.php" off it whenever base_url isn't set in config.ini.php (true on this
     * install), so a round-tripped $request->getUri() silently loses the front-controller
     * filename and no longer resolves to anything. Confirmed by reading BaseUrl::process() and by
     * reproducing the exact "too many redirects" this caused live on production before the fix.
     */
    private function loginUrl(ServerRequestInterface $request, Tree $tree, string $return_url): string
    {
        return $this->coreUrl($request, '/login/' . $tree->name()) . '&url=' . urlencode($return_url);
    }

    private function coreUrl(ServerRequestInterface $request, string $path): string
    {
        $base_url = rtrim(Validator::attributes($request)->string('base_url'), '/');

        return $base_url . '/index.php?route=' . urlencode($path);
    }

    /**
     * Best-effort deep link into the app, mirroring webtreesand-api's pairing link
     * (scheme "webtreesand", see modules_v4/webtreesand-api/src/AppPages.php:181).
     * The app needs its own intent-filter/handler for the "request" host to catch this -
     * that's app-side work; the web form below works regardless of whether it does.
     */
    private function deepLinkUrl(ServerRequestInterface $request, Tree $tree, string $token): string
    {
        $base_url = Validator::attributes($request)->string('base_url');

        return 'webtreesand://request?' . http_build_query([
            'url'   => $base_url,
            'tree'  => $tree->name(),
            'token' => $token,
        ]);
    }

    private function individualCount(Tree $tree): int
    {
        return DB::table('individuals')->where('i_file', '=', $tree->id())->count();
    }

    private function requestEmailSubject(string $person_name): string
    {
        if ($person_name === '') {
            return I18N::translate('Hilfst Du mit, unseren Stammbaum zu vervollständigen?');
        }

        return I18N::translate('Hilfst Du mit, unseren Stammbaum zu vervollständigen? (%s)', $person_name);
    }

    private function requestEmailBody(
        string $requester_name,
        string $person_name,
        int $tree_person_count,
        string $url,
        string $personal_message,
        string $recipient_name,
    ): string {
        $greeting = $recipient_name === ''
            ? I18N::translate('Hallo,')
            : I18N::translate('Hallo %s,', $recipient_name);

        $lines = [
            $greeting,
            '',
            I18N::translate('kannst Du mir helfen, unseren Stammbaum zu vervollständigen? Du weißt bestimmt mehr zu %s.', $person_name),
        ];

        if ($personal_message !== '') {
            $lines[] = $personal_message;
        }

        $lines[] = '';
        $lines[] = I18N::translate('Die Daten gehen direkt an mich zurück und ich trage sie dann in den Stammbaum ein. Wenn Du den Stammbaum ansehen möchtest, kannst Du Dich auch registrieren. Es sind schon %s Personen in unserem Stammbaum.', I18N::number($tree_person_count));
        $lines[] = '';
        $lines[] = $url;
        $lines[] = '';
        $lines[] = I18N::translate('Danke und alles Liebe,');
        $lines[] = $requester_name;

        return implode("\n", $lines);
    }

    /**
     * @param array{responder_name?: string, responder_email?: string} $response_data
     */
    private function notifyCreatorOfResponse(Tree $tree, object $row, array $response_data): void
    {
        $creator = Registry::container()->get(UserService::class)->find((int) $row->creator_user_id);

        if ($creator === null) {
            return;
        }

        $data       = json_decode($row->request_data, true);
        $name       = $data['name'] ?? $row->xref;
        $review_url = $this->actionUrl('RequestReview', $tree->name(), ['id' => $row->id]);

        $responder = $this->responderLabel($response_data);

        $text = $responder !== ''
            ? I18N::translate('Du hast eine Antwort von %1$s auf Deine Anfrage zu %2$s bekommen.', $responder, $name)
            : I18N::translate('Du hast eine Antwort auf Deine Anfrage zu %s bekommen.', $name);
        $text .= "\n\n" . $review_url;

        Registry::container()->get(EmailService::class)->send(
            new SiteUser(),
            $creator,
            new TreeUser($tree),
            I18N::translate('Antwort auf Deine Anfrage zu %s', $name),
            $text,
            nl2br(e($text)),
        );
    }

    /**
     * Parents and children of $individual who are missing at least one of the fixed fields,
     * most-missing first - offered as "help with them too?" on the thank-you page. Filtered to
     * $creator's own rights (creatorCanRequestFor(), same bar postCreateRequestAction enforces),
     * not the current session's - this list becomes the *only* xrefs postRequestContinueAction
     * will ever act on later, entirely from the anonymous guest's own request, so it has to be
     * exactly as restrictive as if the creator had started each of those requests themselves.
     *
     * @return list<array{xref: string, name: string, missing: int}>
     */
    private function relativeSuggestions(Individual $individual, UserInterface $creator): array
    {
        $relatives = [];

        foreach ($individual->childFamilies() as $family) {
            foreach ([$family->husband(), $family->wife()] as $parent) {
                if ($parent instanceof Individual) {
                    $relatives[$parent->xref()] = $parent;
                }
            }
        }

        foreach ($individual->spouseFamilies() as $family) {
            foreach ($family->children() as $child) {
                $relatives[$child->xref()] = $child;
            }
        }

        unset($relatives[$individual->xref()]);

        $suggestions = [];

        foreach ($relatives as $relative) {
            if (!$this->creatorCanRequestFor($relative, $creator)) {
                continue;
            }

            $missing = 0;

            foreach (WebtreesShareModule::FIELDS as $definition) {
                if (GedcomSnapshot::fieldValue($relative, $definition) === '') {
                    $missing++;
                }
            }

            if ($missing > 0) {
                $suggestions[] = [
                    'xref'    => $relative->xref(),
                    'name'    => strip_tags($relative->fullName()),
                    'missing' => $missing,
                ];
            }
        }

        usort($suggestions, static fn (array $a, array $b): int => $b['missing'] <=> $a['missing']);

        return $suggestions;
    }

    /**
     * @param iterable<object> $rows
     *
     * @return array<int,string>
     */
    private function namesFor(Tree $tree, iterable $rows): array
    {
        $names = [];

        foreach ($rows as $row) {
            $individual       = Registry::individualFactory()->make($row->xref, $tree);
            $names[$row->id]  = $individual instanceof Individual ? strip_tags($individual->fullName()) : $row->xref;
        }

        return $names;
    }

    private function unreadCount(int $user_id): int
    {
        if ($user_id === 0) {
            return 0;
        }

        return DB::table('webtreesshare_request')
            ->where('creator_user_id', '=', $user_id)
            ->where('status', '=', 'answered')
            ->count();
    }

    /**
     * Holding area for a guest-uploaded photo that hasn't been reviewed yet - webtrees' own
     * "data" filesystem, not the tree's media folder, so nothing is added to the tree's media
     * library until the requester explicitly accepts it.
     */
    private function pendingPhotoFilesystem(): FilesystemOperator
    {
        return Registry::filesystem()->data(WebtreesShareModule::PENDING_PHOTO_PATH);
    }

    /**
     * Holding area for a snapshot of a person's *existing* photo - separate from
     * pendingPhotoFilesystem() (that one holds guest submissions) so the two can never collide.
     */
    private function existingPhotoFilesystem(): FilesystemOperator
    {
        return Registry::filesystem()->data(WebtreesShareModule::EXISTING_PHOTO_PATH);
    }

    /**
     * Copy the person's current highlighted photo (if any) into the existing-photo holding area,
     * so a signed-out guest can see it without needing access to the tree's protected media
     * folder. Returns the stored filename, or '' if there is no photo to snapshot.
     */
    private function snapshotExistingPhoto(Tree $tree, Individual $individual): string
    {
        $media_file = $individual->findHighlightedMediaFile();

        if ($media_file === null || !$media_file->isImage() || !$media_file->fileExists()) {
            return '';
        }

        try {
            $content = $tree->mediaFilesystem()->read($media_file->filename());
        } catch (FilesystemException) {
            return '';
        }

        $extension = pathinfo($media_file->filename(), PATHINFO_EXTENSION);
        $filename  = Str::random(24) . ($extension === '' ? '' : '.' . $extension);

        $this->existingPhotoFilesystem()->write($filename, $content);

        return $filename;
    }

    /**
     * Save a guest's uploaded photo to the pending holding area, after checking it's really an
     * image (never trust the client-supplied filename/extension) and within the size cap.
     * Returns the stored filename, or '' if there was no valid upload to save.
     */
    private function storePendingPhoto(UploadedFileInterface|null $uploaded): string
    {
        if ($uploaded === null || $uploaded->getError() !== UPLOAD_ERR_OK) {
            return '';
        }

        if ($uploaded->getSize() === null || $uploaded->getSize() === 0 || $uploaded->getSize() > WebtreesShareModule::MAX_PHOTO_BYTES) {
            return '';
        }

        $content    = (string) $uploaded->getStream();
        $image_info = @getimagesizefromstring($content);

        if ($image_info === false) {
            return '';
        }

        $extension = image_type_to_extension($image_info[2], false);

        if ($extension === false) {
            return '';
        }

        $filename = Str::random(24) . '.' . $extension;

        $this->pendingPhotoFilesystem()->write($filename, $content);

        return $filename;
    }

    /**
     * Turn an accepted pending photo into a real webtrees media object, exactly the way
     * webtreesand-api's own photo upload does it (content-hash filename, accept the media
     * object immediately, link it to the record as a normal - possibly pending - edit).
     */
    private function applyPendingPhoto(Tree $tree, Individual $individual, string $photo): void
    {
        $pending_fs = $this->pendingPhotoFilesystem();

        if (!$pending_fs->fileExists($photo)) {
            return;
        }

        $content   = $pending_fs->read($photo);
        $extension = pathinfo($photo, PATHINFO_EXTENSION);
        $filename  = sha1($content) . '.' . $extension;

        $tree->mediaFilesystem()->write($filename, $content);

        $media_file_service = Registry::container()->get(MediaFileService::class);
        $gedcom             = "0 @@ OBJE\n" . $media_file_service->createMediaFileGedcom($filename, 'photo', '', '');
        $media              = $tree->createMediaObject($gedcom);

        Registry::container()->get(PendingChangesService::class)->acceptRecord($media);

        $individual->createFact('1 OBJE @' . $media->xref() . '@', true);

        $pending_fs->delete($photo);
    }
}
