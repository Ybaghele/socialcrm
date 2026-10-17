# OmniFlow: SaaS core + inbound pipeline

Copy the folders into your Laravel app (merge `User.php`, don't overwrite yours blindly).

## 1. Install

```bash
php artisan migrate                 # runs 000001..000012
php artisan db:seed --class=PlanSeeder
```

`.env`:
```
META_APP_SECRET=...                 # Meta app → Settings → Basic → App secret
META_WEBHOOK_VERIFY_TOKEN=any-long-random-string
OMNIFLOW_TRIAL_DAYS=14
QUEUE_CONNECTION=database           # or redis (recommended with Horizon)
BROADCAST_CONNECTION=reverb
```

## 2. Register the provider (`bootstrap/providers.php`)
```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\TenancyServiceProvider::class,
];
```

## 3. Wire middleware + routes (`bootstrap/app.php`)
```php
use App\Http\Middleware\{EnsureActiveSubscription, EnsureSuperAdmin, ResolveWorkspace};
use Illuminate\Routing\Middleware\SubstituteBindings;

->withRouting(
    web: __DIR__.'/../routes/web.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
    then: function () {
        // No "web" group => no session/CSRF. Auth is the HMAC signature.
        \Illuminate\Support\Facades\Route::middleware([])->group(base_path('routes/webhooks.php'));
    },
)
->withBroadcasting(__DIR__.'/../routes/channels.php')
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'workspace'   => ResolveWorkspace::class,
        'subscribed'  => EnsureActiveSubscription::class,
        'super_admin' => EnsureSuperAdmin::class,
    ]);
    // CRITICAL: set the tenant BEFORE route-model binding runs, or {contact} lookups
    // happen without a tenant (they'd throw) instead of 404-ing across tenants.
    $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveWorkspace::class);
})
```

**Check the webhook URL after wiring routes:**
```bash
php artisan route:list --path=webhooks
```
You must see exactly `GET|POST  webhooks/meta`. If you see `webhooks/webhooks/meta` (or `api/webhooks/meta`), remove the extra `->prefix(...)` from the `then:` block in `bootstrap/app.php`. Your Meta callback URL is whatever this command shows.

## 4. Tenant routes (`routes/web.php`)
```php
Route::middleware(['auth', 'verified', 'workspace'])->prefix('w/{workspace}')->group(function () {
    Route::get('/billing', ...);                          // reachable even if unpaid
    Route::middleware('subscribed')->group(function () {
        Route::get('/inbox', ...);  Route::get('/contacts', ...);  Route::get('/campaigns', ...);
    });
});
```
Do **not** type-hint `Workspace $workspace` in controllers; read it from
`$request->attributes->get('workspace')` or `Tenancy::get()`.

## 5. Try it without Meta
```bash
php artisan tinker   # create a user, then:
#   $ws = app(\App\Actions\CreateWorkspace::class)->handle(User::first(), 'Acme');
php artisan omniflow:simulate-inbound acme --sync --channel=whatsapp --text="Price for 50 units?"
php artisan omniflow:simulate-inbound acme --sync --channel=instagram --from=17841400000
php artisan test --filter='TenantIsolationTest|MetaWebhookTest'
```

## 6. Multi-tenant rules for everything you build next

1. Every tenant table gets `workspace_id` + `use BelongsToWorkspace`. No exceptions.
2. No tenant context ⇒ exception, by design. Console/seeders must wrap work in `Tenancy::run($ws, ...)`.
3. **Queued jobs:** `use TenantAwareJob`, pass **IDs only** (never scoped models, since unserialization happens before the tenant is set).
4. `Tenancy::runUnscoped()` is for webhook account lookup and super-admin screens only. Never in a user-facing controller.
5. Cache keys, Reverb channels, storage paths and rate-limit keys must include the workspace id.
6. Meta webhooks are global: one platform Meta app, tenants connect their own assets (Embedded Signup / OAuth). The tenant is found from `phone_number_id` / page id → `channel_accounts`.

## 7. Outbound sending (replies, templates, wallet)

`.env`:
```
META_GRAPH_VERSION=v22.0        # check Meta's current version; old ones get retired
# META_INSTAGRAM_HOST=https://graph.instagram.com   # only if you onboard IG via "Instagram Login"
```
Set real template prices in `config/omniflow.php` → `pricing.whatsapp_template_paise` (current values are placeholders).

Route (inside the tenant group from step 4, behind `subscribed`):
```php
Route::post('/conversations/{conversation}/messages', [ConversationMessageController::class, 'store']);
```
Request bodies: `{"body":"Hi"}` · `{"template_id":7,"variables":["Ananya","#1042"]}` · `{"body":"...","note":true}`.
Errors are `422 {reason}` (`template_required`, `window_closed`, `opted_out`, ...) or `402 insufficient_balance`.

Run a worker: `php artisan queue:work` (needed for `DeliverOutboundMessage` and for real-time broadcasts).

## 8. Real-time (Reverb)

Reverb is the self-hosted WebSocket server (Pusher protocol). It's installed and pre-wired:

- `.env`: `BROADCAST_CONNECTION=reverb` + `REVERB_APP_*` + `VITE_REVERB_*`.
- `routes/channels.php`: private channel `workspace.{workspaceId}.inbox` (only members can subscribe).
- `resources/js/echo.js`: Echo booted with Reverb (auto-imported via `app.js`).
- `Inbox.vue`: subscribes to the private channel and reloads when `message.received` / `message.updated` fire.

### Local dev

**Easiest:** `composer dev` runs `serve`, `queue:listen`, `vite`, and `reverb:start` together (concurrently). Stop any existing `php artisan serve` first to avoid port conflicts.

**Or run manually (4 terminals):**
- `php artisan serve`
- `php artisan queue:listen`
- `php artisan reverb:start`
- `npm run dev`

> Note: `MessageReceived` and `MessageUpdated` implement `ShouldBroadcast` (not `ShouldBroadcastNow`), so broadcasts go through the queue. If no worker runs, the inbox will NOT update in real-time.

Verify private channel auth: posting to `/broadcasting/auth` with valid `socket_id` and `channel_name` returns HTTP 200 with `auth` token. Non-members get 403.

```bash
php artisan test --filter='BroadcastingAuthTest'
```

## 9. Try it without Meta

```bash

Rules enforced before anything is queued or charged: 24h window, template approved + belongs to the number,
marketing needs opt-in, variable count matches, wallet has funds. Template charges are debited in the same DB
transaction as the message row and refunded exactly once if sending fails, or if Meta later reports `failed`.

Known limits: only body variables in templates; media/interactive sends not built; Instagram/Messenger outside
the 24h window needs Meta's `HUMAN_AGENT` tag (requires approval); a network timeout after Meta accepted a message
can cause a duplicate on retry (WhatsApp has no idempotency key).
