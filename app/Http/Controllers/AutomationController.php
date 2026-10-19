<?php

namespace App\Http\Controllers;

use App\Models\Automation;
use App\Models\AutomationStep;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Automations\BusinessHours;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Automations screen: three kinds of rule on one page.
 *
 *   GET    /w/{workspace}/automations                     rules + business hours
 *   POST   /w/{workspace}/automations                     create
 *   PATCH  /w/{workspace}/automations/{automation}        edit
 *   POST   /w/{workspace}/automations/{automation}/toggle pause / resume
 *   DELETE /w/{workspace}/automations/{automation}        delete
 *   POST   /w/{workspace}/automations/business-hours      shared opening hours
 *
 * Automations are tenant-scoped by the BelongsToWorkspace global scope. Steps,
 * templates and agent pools are always validated against the current workspace, so
 * a tampered form cannot point a rule at another tenant's template or user.
 */
class AutomationController extends Controller
{
    /** @var array<int, string> */
    private const CHANNELS = ['whatsapp', 'instagram', 'messenger'];

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Automations', [
            'automations' => Automation::query()
                ->with(['steps.template:id,name', 'creator:id,name'])
                ->withCount('runs')
                ->orderBy('priority')
                ->orderBy('id')
                ->get()
                ->map(fn (Automation $automation) => $this->payload($automation))
                ->all(),
            'businessHours' => app(BusinessHours::class)->for($workspace),
            'options' => $this->options($workspace),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $data = $this->validated($request, $workspace);

        $automation = Automation::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'status' => $data['status'] ?? 'active',
            'priority' => $data['priority'] ?? 0,
            'conditions' => $this->conditions($data),
            'actions' => $this->actions($data, $workspace),
            'created_by' => $request->user()->id,
        ]);

        $this->saveSteps($automation, $data);

        return back()->with('success', "Automation “{$automation->name}” created.");
    }

    public function update(Request $request, string $workspace, Automation $automation): RedirectResponse
    {
        $workspaceModel = $request->attributes->get('workspace');
        $data = $this->validated($request, $workspaceModel);

        $automation->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'status' => $data['status'] ?? $automation->status,
            'priority' => $data['priority'] ?? 0,
            'conditions' => $this->conditions($data),
            'actions' => $this->actions($data, $workspaceModel),
        ]);

        $this->saveSteps($automation, $data);

        return back()->with('success', "Automation “{$automation->name}” updated.");
    }

    public function toggle(Request $request, string $workspace, Automation $automation): RedirectResponse
    {
        $automation->update(['status' => $automation->status === 'active' ? 'paused' : 'active']);

        return back()->with('success', $automation->status === 'active'
            ? "Automation “{$automation->name}” is live."
            : "Automation “{$automation->name}” paused.");
    }

    public function destroy(Request $request, string $workspace, Automation $automation): RedirectResponse
    {
        $name = $automation->name;
        $automation->delete();

        return back()->with('success', "Automation “{$name}” deleted.");
    }

    public function businessHours(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');

        $data = $request->validate([
            'timezone' => ['required', 'string', 'max:64'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'min:0', 'max:6'],
            'start' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'end' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
        ]);

        $settings = $workspace->settings ?? [];
        $settings[BusinessHours::SETTINGS_KEY] = [
            'timezone' => $data['timezone'],
            'days' => array_values(array_unique(array_map('intval', $data['days']))),
            'start' => $data['start'],
            'end' => $data['end'],
        ];

        $workspace->forceFill(['settings' => $settings])->save();

        return back()->with('success', 'Business hours updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Workspace $workspace): array
    {
        $type = (string) $request->input('type');

        $rules = [
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(Automation::TYPES)],
            'status' => ['nullable', Rule::in(['active', 'paused'])],
            'priority' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'max:32'],
        ];

        if ($type === Automation::TYPE_AUTO_REPLY) {
            $rules += [
                'keywords' => ['nullable', 'string', 'max:500'],
                'onlyFirstMessage' => ['nullable', 'boolean'],
                'outsideBusinessHours' => ['nullable', 'boolean'],
                'reply' => ['required', 'string', 'max:4000'],
                'assignUserId' => ['nullable', 'integer'],
            ];
        }

        if ($type === Automation::TYPE_ROUTING) {
            $rules += [
                'outsideBusinessHours' => ['nullable', 'boolean'],
                'strategy' => ['nullable', Rule::in(['round_robin', 'least_busy', 'specific_user'])],
                'userId' => ['nullable', 'integer'],
                'userIds' => ['nullable', 'array'],
                'userIds.*' => ['integer'],
            ];
        }

        if ($type === Automation::TYPE_SEQUENCE) {
            $rules += [
                'noReplyAfterHours' => ['required', 'integer', 'min:1', 'max:720'],
                'steps' => ['required', 'array', 'min:1', 'max:10'],
                'steps.*.delayMinutes' => ['required', 'integer', 'min:0', 'max:43200'],
                'steps.*.templateId' => [
                    'required', 'integer',
                    Rule::exists('message_templates', 'id')
                        ->where('workspace_id', $workspace->id)
                        ->where('status', 'approved'),
                ],
                'steps.*.variables' => ['nullable', 'array'],
                'steps.*.variables.*' => ['nullable', 'string', 'max:200'],
            ];
        }

        $data = $request->validate($rules);

        // Anything the caller sent that we do not recognise is dropped, not trusted.
        $data['channels'] = array_values(array_intersect(
            array_map('strval', (array) ($request->input('channels') ?? [])),
            self::CHANNELS,
        ));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function conditions(array $data): array
    {
        $channels = $data['channels'];

        return match ($data['type']) {
            Automation::TYPE_AUTO_REPLY => [
                'keywords' => $this->keywords($data['keywords'] ?? ''),
                'only_first_message' => (bool) ($data['onlyFirstMessage'] ?? false),
                'outside_business_hours' => (bool) ($data['outsideBusinessHours'] ?? false),
                'channels' => $channels,
            ],
            Automation::TYPE_ROUTING => [
                'outside_business_hours' => (bool) ($data['outsideBusinessHours'] ?? false),
                'channels' => $channels,
            ],
            Automation::TYPE_SEQUENCE => [
                'no_reply_after_hours' => (int) $data['noReplyAfterHours'],
                'channels' => $channels,
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function actions(array $data, Workspace $workspace): ?array
    {
        return match ($data['type']) {
            Automation::TYPE_AUTO_REPLY => [
                'reply' => trim((string) $data['reply']),
                'assign_user_id' => $this->memberId($workspace, $data['assignUserId'] ?? null),
            ],
            Automation::TYPE_ROUTING => [
                'strategy' => $data['strategy'] ?? 'round_robin',
                'user_id' => $this->memberId($workspace, $data['userId'] ?? null),
                'user_ids' => $this->memberIds($workspace, $data['userIds'] ?? []),
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveSteps(Automation $automation, array $data): void
    {
        if ($automation->type !== Automation::TYPE_SEQUENCE) {
            $automation->steps()->delete();

            return;
        }

        DB::transaction(function () use ($automation, $data) {
            $automation->steps()->delete();

            foreach (array_values($data['steps']) as $position => $step) {
                AutomationStep::create([
                    'automation_id' => $automation->id,
                    'position' => $position,
                    'delay_minutes' => (int) $step['delayMinutes'],
                    'message_template_id' => (int) $step['templateId'],
                    'template_variables' => array_values(array_map(
                        fn ($value) => (string) $value,
                        (array) ($step['variables'] ?? []),
                    )),
                ]);
            }
        });
    }

    /** Split a comma/newline list into a clean, de-duplicated keyword array. */
    private function keywords(string $raw): array
    {
        return collect(preg_split('/[\r\n,]+/', $raw) ?: [])
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter(fn (string $keyword) => $keyword !== '')
            ->unique(fn (string $keyword) => mb_strtolower($keyword))
            ->take(50)
            ->values()
            ->all();
    }

    /** A user id only survives if that person is actually in this workspace. */
    private function memberId(Workspace $workspace, mixed $id): ?int
    {
        $id = (int) $id;

        return $id > 0 && $workspace->users()->whereKey($id)->exists() ? $id : null;
    }

    /** @return array<int, int> */
    private function memberIds(Workspace $workspace, mixed $ids): array
    {
        return $workspace->users()
            ->whereIn('users.id', array_filter(array_map('intval', (array) $ids)))
            ->pluck('users.id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Automation $automation): array
    {
        return [
            'id' => $automation->id,
            'name' => $automation->name,
            'type' => $automation->type,
            'status' => $automation->status,
            'priority' => (int) $automation->priority,
            'conditions' => $automation->conditions ?? [],
            'actions' => $automation->actions ?? [],
            'creator' => $automation->creator?->name,
            'lastRunAt' => $automation->last_run_at?->toIso8601String(),
            'runCount' => (int) $automation->run_count,
            'contactCount' => (int) ($automation->runs_count ?? 0),
            'steps' => $automation->steps->map(fn (AutomationStep $step) => [
                'id' => $step->id,
                'delayMinutes' => (int) $step->delay_minutes,
                'templateId' => $step->message_template_id,
                'templateName' => $step->template?->name,
                'variables' => array_values((array) ($step->template_variables ?? [])),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(Workspace $workspace): array
    {
        return [
            'users' => $workspace->users()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->pivot->role,
                    'available' => (bool) $user->pivot->is_available,
                ])
                ->all(),
            'templates' => MessageTemplate::query()
                ->where('status', 'approved')
                ->orderBy('name')
                ->get(['id', 'name', 'category'])
                ->map(fn (MessageTemplate $template) => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'category' => $template->category,
                    'placeholders' => $template->placeholderCount(),
                ])
                ->all(),
            'channels' => [
                ['value' => 'whatsapp', 'label' => 'WhatsApp'],
                ['value' => 'instagram', 'label' => 'Instagram'],
                ['value' => 'messenger', 'label' => 'Messenger'],
            ],
            'timezones' => [
                'Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Europe/London', 'America/New_York', 'UTC',
            ],
        ];
    }
}
