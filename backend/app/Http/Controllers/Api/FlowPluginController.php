<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFlowPluginRequest;
use App\Http\Resources\FlowPluginResource;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Bot;
use App\Models\Flow;
use App\Models\FlowPlugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FlowPluginController extends Controller
{
    use ApiResponseTrait;

    public function index(Bot $bot, Flow $flow): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);

        $plugins = $flow->plugins()->latest()->get();

        return FlowPluginResource::collection($plugins);
    }

    public function store(StoreFlowPluginRequest $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);

        $plugin = $flow->plugins()->create($request->validated());

        return $this->created(new FlowPluginResource($plugin), 'Plugin created successfully');
    }

    public function update(StoreFlowPluginRequest $request, Bot $bot, Flow $flow, FlowPlugin $plugin): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);
        $this->ensurePluginBelongsToFlow($plugin, $flow);

        $plugin->update($request->validated());

        return $this->success(new FlowPluginResource($plugin), 'Plugin updated successfully');
    }

    public function destroy(Bot $bot, Flow $flow, FlowPlugin $plugin): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);
        $this->ensurePluginBelongsToFlow($plugin, $flow);

        $plugin->delete();

        return $this->success(null, 'Plugin deleted successfully');
    }

    protected function ensureFlowBelongsToBot(Flow $flow, Bot $bot): void
    {
        if ($flow->bot_id !== $bot->id) {
            abort(404, 'Flow not found');
        }
    }

    protected function ensurePluginBelongsToFlow(FlowPlugin $plugin, Flow $flow): void
    {
        if ($plugin->flow_id !== $flow->id) {
            abort(404, 'Plugin not found');
        }
    }
}
