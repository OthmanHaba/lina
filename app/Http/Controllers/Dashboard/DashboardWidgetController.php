<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\WidgetSize;
use App\Enums\WidgetType;
use App\Http\Controllers\Controller;
use App\Models\DashboardWidget;
use App\Services\WidgetDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardWidgetController extends Controller
{
    public function store(Request $request, string $current_team): RedirectResponse
    {
        $teamId = $request->user()->current_team_id;

        $validated = $request->validate([
            'type' => ['required', Rule::enum(WidgetType::class)],
            'title' => ['required', 'string', 'max:100'],
            'size' => ['required', Rule::enum(WidgetSize::class)],
        ]);

        $type = WidgetType::from($validated['type']);

        DashboardWidget::create([
            'team_id' => $teamId,
            'type' => $type,
            'title' => $validated['title'],
            'size' => $validated['size'],
            'position' => (int) DashboardWidget::where('team_id', $teamId)->max('position') + 1,
            'config' => $this->validatedConfig($request, $type, $teamId),
        ]);

        return back();
    }

    public function update(Request $request, string $current_team, DashboardWidget $widget): RedirectResponse
    {
        $teamId = $request->user()->current_team_id;
        abort_unless($widget->team_id === $teamId, 404);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'size' => ['required', Rule::enum(WidgetSize::class)],
        ]);

        $widget->update([
            'title' => $validated['title'],
            'size' => $validated['size'],
            'config' => $this->validatedConfig($request, $widget->type, $teamId),
        ]);

        return back();
    }

    public function destroy(Request $request, string $current_team, DashboardWidget $widget): RedirectResponse
    {
        abort_unless($widget->team_id === $request->user()->current_team_id, 404);

        $widget->delete();

        return back();
    }

    public function reorder(Request $request, string $current_team): RedirectResponse
    {
        $teamId = $request->user()->current_team_id;

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $teamWidgetIds = DashboardWidget::where('team_id', $teamId)->pluck('id');

        if ($teamWidgetIds->count() !== count($validated['ids']) || $teamWidgetIds->diff($validated['ids'])->isNotEmpty()) {
            throw ValidationException::withMessages(['ids' => 'The order must include every widget exactly once.']);
        }

        DB::transaction(function () use ($validated) {
            foreach (array_values($validated['ids']) as $position => $id) {
                DashboardWidget::whereKey($id)->update(['position' => $position]);
            }
        });

        return back();
    }

    public function data(Request $request, string $current_team, DashboardWidget $widget, WidgetDataService $service): JsonResponse
    {
        abort_unless($widget->team_id === $request->user()->current_team_id, 404);

        $validated = $request->validate([
            'range' => ['sometimes', Rule::in(array_keys(WidgetDataService::RANGES))],
        ]);

        return response()->json($service->fetch($widget, $validated['range'] ?? '1h'));
    }

    /**
     * Validate and strip the config payload to the keys the widget type knows.
     *
     * @return array<string, mixed>
     */
    protected function validatedConfig(Request $request, WidgetType $type, int $teamId): array
    {
        $rules = [
            'config' => ['present', 'array'],
            'config.server_ids' => ['sometimes', 'array'],
            'config.server_ids.*' => ['integer', Rule::exists('servers', 'id')->where('team_id', $teamId)],
            'config.sources' => ['sometimes', 'array'],
            'config.sources.*' => ['string', 'max:100'],
            'config.search' => ['sometimes', 'nullable', 'string', 'max:500'],
            'config.logql' => ['prohibited'],
        ];

        $keys = ['server_ids', 'sources', 'search'];

        [$rules, $keys] = match ($type) {
            WidgetType::Timeseries => [array_merge($rules, [
                'config.chart' => ['sometimes', Rule::in(['line', 'bar', 'area'])],
                'config.group_by' => ['sometimes', 'nullable', Rule::in(['server', 'source'])],
                'config.logql' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ]), [...$keys, 'chart', 'group_by', 'logql']],
            WidgetType::Stat => [array_merge($rules, [
                'config.window_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
                'config.logql' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ]), [...$keys, 'window_minutes', 'logql']],
            WidgetType::LogStream => [array_merge($rules, [
                'config.limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]), [...$keys, 'limit']],
            default => [$rules, $keys],
        };

        $validated = $request->validate($rules);

        return Arr::only($validated['config'] ?? [], $keys);
    }
}
