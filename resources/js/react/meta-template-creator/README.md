# Meta WhatsApp Template Creator (React + TypeScript)

A self-contained dashboard for authoring WhatsApp message templates inside SocialDesk.
It validates Meta's formatting rules, renders a live WhatsApp preview, maps variables to
CRM fields, and logs the `POST /{WABA_ID}/message_templates` payload before submitting.

> **Repository note:** the live SocialDesk UI is Vue 3 + Inertia. This module is intentionally
> isolated under `resources/js/react/` and does **not** touch the Vue build. See
> [Hosting it](#hosting-it) for the two integration paths.

## Files

```
meta-template-creator/
├── MetaTemplateCreator.tsx        page shell, state, validation + submit orchestration
├── components/
│   ├── TemplateForm.tsx           identity, header, body, footer, buttons
│   ├── WhatsAppPreview.tsx        phone frame, live bubble, light/dark themes
│   ├── VariableMapping.tsx        CRM mapping drawer
│   └── ui.tsx                     primitives, inline icons, toast
├── lib/
│   ├── constants.ts               categories, pricing, languages, limits
│   └── template.ts                sanitizer, variable engine, validation, payload builder
├── types.ts                       shared domain + Graph payload types
└── index.ts                       public exports
```

## Hosting it

The component only needs React 18+ and Tailwind. It does not use a router, icon package,
or any React UI library.

### 1. Standalone React page (fastest)

```bash
npm install react react-dom
npm install -D @types/react @types/react-dom typescript @vitejs/plugin-react
```

Add the demo entry to `vite.config.js`:

```js
import react from '@vitejs/plugin-react';

plugins: [
    laravel({
        input: ['resources/js/app.js', 'resources/js/react/meta-template-creator-demo.tsx'],
        refresh: true,
    }),
    vue({ /* existing options */ }),
    react(),
],
```

Then mount it from a Blade view:

```blade
<div id="meta-template-creator-root"></div>
@vite('resources/js/react/meta-template-creator-demo.tsx')
```

Add `./resources/js/**/*.tsx` to the `content` array in `tailwind.config.js`. The `brand`
palette already exists in this repo, so the indigo accents work as-is.

### 2. Inside the existing Vue app

Either (a) port the three components to `.vue` single-file components while keeping the
same validation/payload helpers in TypeScript, or (b) expose the React island on a
non-Inertia route, as above. Option (a) gives the most consistent product experience.

## Backend contract

`MetaTemplateCreator.tsx` logs the exact payload and then simulates a successful response.
Replace the `console.log` + timeout with:

```php
Route::post('/templates', [TemplateController::class, 'store']);
```

```php
$payload = $request->validate([...]);

Http::withToken($account->access_token)
    ->post("{$graphBase}/{$version}/{$account->business_account_id}/message_templates", [
        'name' => $payload['name'],
        'category' => $payload['category'],
        'language' => $payload['language'],
        'allow_category_change' => true,
        'components' => $payload['components'],
    ])
    ->throw();
```

Persist the returned `id` + `PENDING` status locally, then update it from the
`message_template_status_update` webhook.

### Media headers

Meta requires a `header_handle` from the Resumable Upload API, not a public URL. The UI
collects a media URL for the preview; the server must upload the asset first and replace
`components[HEADER].example.header_handle[0]` with the returned handle before calling the
Graph API.

## Rules enforced in the UI

- Name: lowercase `a-z`, `0-9`, `_`; spaces become underscores automatically.
- Body: required, max 1024 characters.
- Variables: sequential from `{{1}}` with no gaps; duplicates of a number are allowed.
- Header text: max 60 characters, one `{{1}}` variable at most.
- Footer: max 60 characters, no variables.
- Quick replies: up to 3, 25 characters each.
- CTA: at least one URL or phone button; URL must be http(s); phone must include a country code.
- Every variable must be mapped to a CRM field (or a custom example) before submit.

Category pricing in the UI mirrors `config/omniflow.php`: marketing 88 paise, utility and
authentication 30 paise per conversation (India placeholder rates).

## Accessibility

Every input has a label, errors use `role="alert"` and `aria-invalid`, the category picker is
a real radio group, toggles use `role="switch"` with an accessible name, the preview has an
`aria-label`, and the toast is announced with `aria-live="polite"`.
